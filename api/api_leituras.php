<?php
// =====================================================
// API PARA LEITURAS DE HIDRÔMETROS
// =====================================================

// Segurança: garantir que falhas internas retornem JSON (nunca HTML)
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Capturar exceções e erros fatais e retornar JSON padronizado
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('Exception em api_leituras: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no servidor'], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        error_log('Fatal error em api_leituras: ' . print_r($err, true));
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro fatal no servidor'], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Constantes
define('VALOR_METRO_CUBICO', 6.16);
define('VALOR_MINIMO', 61.60);
define('CONSUMO_MINIMO', 10);

// ========== ORDENAÇÃO NATURAL DE UNIDADES ==========
// Administrativo sempre primeiro, depois ordem numérica (Gleba 1, 2, ... 10, 11 ... 118),
// nunca alfabética (o que faria "Gleba 10" vir antes de "Gleba 2"). Precisa rodar aqui,
// em PHP, porque a paginação (LIMIT/OFFSET) acontece no servidor — se a ordenação
// certa só existisse no JS, o SQL já teria fatiado as páginas na ordem errada.
function _compararUnidadesNatural($a, $b) {
    $isAdmin = function ($str) {
        return stripos((string) $str, 'adm') !== false;
    };

    $admA = $isAdmin($a);
    $admB = $isAdmin($b);
    if ($admA && !$admB) return -1;
    if (!$admA && $admB) return 1;

    $numKey = function ($str) {
        return preg_match('/(\d+)/', (string) $str, $m) ? (int) $m[1] : null;
    };
    $nA = $numKey($a);
    $nB = $numKey($b);
    if ($nA !== null && $nB !== null && $nA !== $nB) {
        return $nA <=> $nB;
    }

    return strnatcasecmp((string) $a, (string) $b);
}

// ========== RELATÓRIO DE CONSUMO (Hidrômetros > Relatórios) ==========
// Precisa vir ANTES do "LISTAR LEITURAS" genérico: usa data_de/data_ate
// (nomes diferentes de data_inicial/data_final), então caindo no bloco
// genérico os filtros de data eram silenciosamente ignorados.
if ($metodo === 'GET' && isset($_GET['relatorio'])) {
    $data_de  = isset($_GET['data_de'])  ? sanitizar($conexao, $_GET['data_de'])  : '';
    $data_ate = isset($_GET['data_ate']) ? sanitizar($conexao, $_GET['data_ate']) : '';
    $unidade  = isset($_GET['unidade'])  ? sanitizar($conexao, $_GET['unidade'])  : '';

    $sql = "SELECT l.*, h.numero_hidrometro, h.numero_lacre, m.nome as morador_nome,
            DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') as data_leitura_formatada
            FROM leituras l
            INNER JOIN hidrometros h ON l.hidrometro_id = h.id
            INNER JOIN moradores m ON l.morador_id = m.id
            WHERE 1=1 ";

    if (!empty($data_de))  $sql .= "AND DATE(l.data_leitura) >= '$data_de' ";
    if (!empty($data_ate)) $sql .= "AND DATE(l.data_leitura) <= '$data_ate' ";
    if (!empty($unidade))  $sql .= "AND l.unidade = '$unidade' ";

    $sql .= "ORDER BY l.data_leitura ASC";

    $resultado = $conexao->query($sql);
    $leituras = array();
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $leituras[] = $row;
        }
    }

    // Ordenação natural por unidade (Administrativo primeiro, depois Gleba 1, 2 ... 10, 11),
    // com a data da leitura como desempate explícito (usort não é garantido estável antes do PHP 8).
    usort($leituras, function ($a, $b) {
        $cmp = _compararUnidadesNatural($a['unidade'], $b['unidade']);
        return $cmp !== 0 ? $cmp : strcmp($a['data_leitura'], $b['data_leitura']);
    });

    retornar_json(true, "Relatório gerado com sucesso", $leituras);
}

// ========== LISTAR LEITURAS ==========
if ($metodo === 'GET' && !isset($_GET['ultima_leitura']) && !isset($_GET['hidrometros_ativos']) && !isset($_GET['relatorio'])) {
    $data_inicial = isset($_GET['data_inicial']) ? sanitizar($conexao, $_GET['data_inicial']) : '';
    $data_final = isset($_GET['data_final']) ? sanitizar($conexao, $_GET['data_final']) : '';
    $unidade = isset($_GET['unidade']) ? sanitizar($conexao, $_GET['unidade']) : '';
    $morador_id = isset($_GET['morador_id']) ? intval($_GET['morador_id']) : 0;
    
    $sql = "SELECT l.*, h.numero_hidrometro, h.numero_lacre, m.nome as morador_nome,
            DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') as data_leitura_formatada,
            CASE
                WHEN l.lancado_por_tipo = 'usuario' THEN CONCAT('👤 ', l.lancado_por_nome, ' (Operador)')
                WHEN l.lancado_por_tipo = 'morador' THEN CONCAT('🏠 ', l.lancado_por_nome, ' (Morador)')
                ELSE 'Sistema'
            END as lancado_por_descricao,
            (SELECT COUNT(*) FROM leituras_fotos lf WHERE lf.leitura_id = l.id) as total_fotos
            FROM leituras l
            INNER JOIN hidrometros h ON l.hidrometro_id = h.id
            INNER JOIN moradores m ON l.morador_id = m.id
            WHERE 1=1 ";
    
    if (!empty($data_inicial)) {
        $sql .= "AND DATE(l.data_leitura) >= '$data_inicial' ";
    }
    
    if (!empty($data_final)) {
        $sql .= "AND DATE(l.data_leitura) <= '$data_final' ";
    }
    
    if (!empty($unidade)) {
        $sql .= "AND l.unidade = '$unidade' ";
    }
    
    if ($morador_id > 0) {
        $sql .= "AND l.morador_id = $morador_id ";
    }
    
    $sql .= "ORDER BY l.data_leitura ASC, l.unidade ASC";
    
    $resultado = $conexao->query($sql);
    $leituras = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $leituras[] = $row;
        }
    }
    
    retornar_json(true, "Leituras listadas com sucesso", $leituras);
}

// ========== BUSCAR ÚLTIMA LEITURA DE UM HIDRÔMETRO ==========
if ($metodo === 'GET' && isset($_GET['ultima_leitura'])) {
    $hidrometro_id = intval($_GET['ultima_leitura']);
    
    $sql = "SELECT leitura_atual, DATE_FORMAT(data_leitura, '%d/%m/%Y %H:%i') as data_leitura_formatada
            FROM leituras 
            WHERE hidrometro_id = $hidrometro_id 
            ORDER BY data_leitura DESC 
            LIMIT 1";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado && $resultado->num_rows > 0) {
        $leitura = $resultado->fetch_assoc();
        retornar_json(true, "Última leitura encontrada", $leitura);
    } else {
        retornar_json(true, "Nenhuma leitura anterior", array('leitura_atual' => 0));
    }
}

// ========== LISTAR HIDRÔMETROS ATIVOS PARA LEITURA COLETIVA ==========
if ($metodo === 'GET' && isset($_GET['hidrometros_ativos'])) {
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    if ($pagina < 1) $pagina = 1;
    $por_pagina = 20;

    // Segurança do lançamento: um hidrômetro que já tem leitura registrada
    // no mês/ano corrente não pode ser lançado de novo, então nem aparece
    // mais na lista de pendentes (evita relançamento ao voltar para a página).
    $condicao_sem_leitura_mes = "NOT EXISTS (
        SELECT 1 FROM leituras lm
        WHERE lm.hidrometro_id = h.id
        AND MONTH(lm.data_leitura) = MONTH(CURDATE())
        AND YEAR(lm.data_leitura) = YEAR(CURDATE())
    )";

    // Busca TODOS os pendentes (sem LIMIT) para poder aplicar a ordenação natural
    // em PHP antes de paginar — se a ordenação certa só existisse depois do corte
    // de página, as páginas ficariam cortadas na ordem alfabética errada.
    $sql = "SELECT h.id, h.numero_hidrometro, h.numero_lacre, h.unidade,
            m.id as morador_id, m.nome as morador_nome,
            (SELECT leitura_atual FROM leituras WHERE hidrometro_id = h.id ORDER BY data_leitura DESC LIMIT 1) as leitura_anterior
            FROM hidrometros h
            INNER JOIN moradores m ON h.morador_id = m.id
            WHERE h.ativo = 1 AND $condicao_sem_leitura_mes";

    $resultado = $conexao->query($sql);
    $todos = array();

    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            if ($row['leitura_anterior'] === null) {
                $row['leitura_anterior'] = 0;
            }
            $todos[] = $row;
        }
    }

    usort($todos, function ($a, $b) {
        $cmp = _compararUnidadesNatural($a['unidade'], $b['unidade']);
        return $cmp !== 0 ? $cmp : strnatcasecmp($a['morador_nome'], $b['morador_nome']);
    });

    $total = count($todos);
    $total_paginas = max(1, (int) ceil($total / $por_pagina));
    if ($pagina > $total_paginas) $pagina = $total_paginas;
    $offset = ($pagina - 1) * $por_pagina;
    $hidrometros = array_slice($todos, $offset, $por_pagina);

    retornar_json(true, "Hidrômetros carregados", array(
        'hidrometros' => array_values($hidrometros),
        'pagina_atual' => $pagina,
        'total_paginas' => $total_paginas,
        'total_registros' => $total
    ));
}

// ========== CALCULAR VALOR ==========
function calcularValor($consumo) {
    if ($consumo <= CONSUMO_MINIMO) {
        return VALOR_MINIMO;
    } else {
        return $consumo * VALOR_METRO_CUBICO;
    }
}

// ========== CRIAR LEITURA (INDIVIDUAL OU COLETIVA) ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    // Verificar se é leitura coletiva (lançamento em lote, contínuo entre páginas)
    if (isset($dados['leituras']) && is_array($dados['leituras'])) {
        // LEITURA COLETIVA — payload: { dataLeitura, leituras: [{hidrometro_id, leitura, selecionado}] }
        // Tudo ou nada: valida cada item antes de gravar; se qualquer um falhar,
        // nada é gravado (rollback completo da transação).
        $data_leitura_lote = sanitizar($conexao, $dados['dataLeitura'] ?? $dados['data_leitura'] ?? '');
        if (empty($data_leitura_lote)) {
            retornar_json(false, "Data da leitura é obrigatória");
        }

        $itens = array_values(array_filter($dados['leituras'], function ($item) {
            return !empty($item['selecionado']);
        }));

        if (empty($itens)) {
            retornar_json(false, "Nenhum hidrômetro selecionado para lançamento");
        }

        $mes = date('m', strtotime($data_leitura_lote));
        $ano = date('Y', strtotime($data_leitura_lote));
        $usuario_id = intval($_SESSION['usuario_id'] ?? 0);
        $usuario_nome = sanitizar($conexao, $_SESSION['usuario_nome'] ?? 'Sistema');

        $conexao->begin_transaction();

        $erros = array();
        $preparados = array();
        $vistos = array();

        foreach ($itens as $item) {
            $hidrometro_id = intval($item['hidrometro_id'] ?? 0);
            $leitura_atual = isset($item['leitura']) && is_numeric($item['leitura']) ? floatval($item['leitura']) : null;

            if ($hidrometro_id <= 0 || $leitura_atual === null || $leitura_atual < 0) {
                $erros[] = "Hidrômetro ID $hidrometro_id: leitura inválida";
                continue;
            }

            if (isset($vistos[$hidrometro_id])) {
                $erros[] = "Hidrômetro ID $hidrometro_id enviado duplicado no mesmo lançamento";
                continue;
            }
            $vistos[$hidrometro_id] = true;

            // Buscar e travar a linha do hidrômetro dentro da transação
            $stmt = $conexao->prepare("SELECT morador_id, unidade, numero_hidrometro, ativo FROM hidrometros WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $hidrometro_id);
            $stmt->execute();
            $hidrometro = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$hidrometro) {
                $erros[] = "Hidrômetro ID $hidrometro_id não encontrado";
                continue;
            }
            if (intval($hidrometro['ativo']) !== 1) {
                $erros[] = "Unidade {$hidrometro['unidade']}: hidrômetro inativo";
                continue;
            }

            // Segurança do lançamento: 1 leitura por mês por hidrômetro
            $stmt_check = $conexao->prepare("SELECT id FROM leituras WHERE hidrometro_id = ? AND MONTH(data_leitura) = ? AND YEAR(data_leitura) = ? LIMIT 1");
            $stmt_check->bind_param("iii", $hidrometro_id, $mes, $ano);
            $stmt_check->execute();
            $ja_lancada = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($ja_lancada) {
                $erros[] = "Unidade {$hidrometro['unidade']} (hidrômetro {$hidrometro['numero_hidrometro']}) já possui leitura lançada neste mês";
                continue;
            }

            // Última leitura para calcular consumo
            $stmt = $conexao->prepare("SELECT leitura_atual FROM leituras WHERE hidrometro_id = ? ORDER BY data_leitura DESC LIMIT 1");
            $stmt->bind_param("i", $hidrometro_id);
            $stmt->execute();
            $ultima = $stmt->get_result()->fetch_assoc();
            $leitura_anterior = $ultima ? floatval($ultima['leitura_atual']) : 0;
            $stmt->close();

            if ($leitura_atual < $leitura_anterior) {
                $erros[] = "Unidade {$hidrometro['unidade']}: leitura ({$leitura_atual} m³) não pode ser menor que a anterior ({$leitura_anterior} m³)";
                continue;
            }

            $consumo = $leitura_atual - $leitura_anterior;
            // Evidência fotográfica (opcional/complementar): a foto já foi enviada
            // separadamente via api_leituras_fotos.php e está com leitura_id NULL;
            // aqui só guardamos a referência para vincular depois do INSERT.
            $foto_id_item = isset($item['foto_id']) && intval($item['foto_id']) > 0 ? intval($item['foto_id']) : null;

            $preparados[] = array(
                'hidrometro_id'     => $hidrometro_id,
                'morador_id'        => $hidrometro['morador_id'],
                'unidade'           => $hidrometro['unidade'],
                'leitura_anterior'  => $leitura_anterior,
                'leitura_atual'     => $leitura_atual,
                'consumo'           => $consumo,
                'valor_total'       => calcularValor($consumo),
                'foto_id'           => $foto_id_item,
            );
        }

        // Qualquer item inválido cancela o lote inteiro — nenhuma leitura é salva parcialmente
        if (!empty($erros)) {
            $conexao->rollback();
            retornar_json(false, "Lançamento cancelado: " . implode('; ', $erros), array('erros' => $erros));
        }

        $valor_mc = VALOR_METRO_CUBICO;
        $valor_min = VALOR_MINIMO;

        foreach ($preparados as $p) {
            $stmt = $conexao->prepare(
                "INSERT INTO leituras
                    (hidrometro_id, morador_id, unidade, leitura_anterior, leitura_atual, consumo,
                     valor_metro_cubico, valor_minimo, valor_total, data_leitura,
                     lancado_por_tipo, lancado_por_id, lancado_por_nome)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'usuario', ?, ?)"
            );
            $stmt->bind_param(
                "iisddddddsis",
                $p['hidrometro_id'], $p['morador_id'], $p['unidade'],
                $p['leitura_anterior'], $p['leitura_atual'], $p['consumo'],
                $valor_mc, $valor_min, $p['valor_total'], $data_leitura_lote,
                $usuario_id, $usuario_nome
            );

            if (!$stmt->execute()) {
                $erro_sql = $stmt->error;
                $stmt->close();
                $conexao->rollback();
                retornar_json(false, "Lançamento cancelado ao gravar a unidade {$p['unidade']}: " . $erro_sql);
            }
            $nova_leitura_id = $conexao->insert_id;
            $stmt->close();

            // Evidência fotográfica é complementar: se o vínculo falhar por qualquer
            // motivo (foto já vinculada, removida, ou de outro hidrômetro), a leitura
            // já gravada NÃO é afetada — apenas a foto fica sem evidência.
            if (!empty($p['foto_id'])) {
                $stmt_foto = $conexao->prepare(
                    "UPDATE leituras_fotos SET leitura_id = ? WHERE id = ? AND hidrometro_id = ? AND leitura_id IS NULL"
                );
                $stmt_foto->bind_param("iii", $nova_leitura_id, $p['foto_id'], $p['hidrometro_id']);
                $stmt_foto->execute();
                $stmt_foto->close();
            }
        }

        $conexao->commit();

        $total_gravado = count($preparados);
        registrar_log($conexao, 'INFO', "Leitura coletiva: $total_gravado leituras registradas em lote por $usuario_nome");
        retornar_json(true, "$total_gravado leitura(s) registrada(s) com sucesso", array('total' => $total_gravado));

    } else {
        // LEITURA INDIVIDUAL
        $hidrometro_id = intval($dados['hidrometro_id'] ?? 0);
        $leitura_atual = floatval($dados['leitura_atual'] ?? 0);
        $data_leitura = sanitizar($conexao, $dados['data_leitura'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        $lancado_por_tipo = sanitizar($conexao, $dados['lancado_por_tipo'] ?? 'usuario'); // 'usuario' ou 'morador'
        $lancado_por_id = intval($dados['lancado_por_id'] ?? 0);
        $lancado_por_nome = sanitizar($conexao, $dados['lancado_por_nome'] ?? '');
        // Evidência fotográfica (opcional/complementar) — enviada antes via
        // api_leituras_fotos.php, ainda com leitura_id NULL; vinculada abaixo
        // somente depois que a leitura for gravada com sucesso.
        $foto_id = isset($dados['foto_id']) && intval($dados['foto_id']) > 0 ? intval($dados['foto_id']) : null;

        if ($hidrometro_id <= 0) {
            retornar_json(false, "Hidrômetro é obrigatório");
        }
        
        if ($leitura_atual < 0) {
            retornar_json(false, "Leitura atual não pode ser negativa");
        }
        
        if (empty($data_leitura)) {
            retornar_json(false, "Data da leitura é obrigatória");
        }
        
        // VALIDAR: 1 leitura por mês (usuário OU morador)
        $mes = date('m', strtotime($data_leitura));
        $ano = date('Y', strtotime($data_leitura));
        
        $stmt_check = $conexao->prepare("
            SELECT id, lancado_por_tipo, lancado_por_nome, DATE_FORMAT(data_leitura, '%d/%m/%Y %H:%i') as data_formatada
            FROM leituras 
            WHERE hidrometro_id = ? 
            AND MONTH(data_leitura) = ? 
            AND YEAR(data_leitura) = ?
            LIMIT 1
        ");
        $stmt_check->bind_param("iii", $hidrometro_id, $mes, $ano);
        $stmt_check->execute();
        $leitura_existente = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();
        
        if ($leitura_existente) {
            $tipo_descricao = $leitura_existente['lancado_por_tipo'] === 'morador' ? 'morador' : 'operador';
            retornar_json(false, "Já existe leitura para este mês lançada por {$leitura_existente['lancado_por_nome']} ({$tipo_descricao}) em {$leitura_existente['data_formatada']}");
        }
        
        // Buscar dados do hidrômetro
        $stmt = $conexao->prepare("SELECT morador_id, unidade, numero_hidrometro FROM hidrometros WHERE id = ?");
        $stmt->bind_param("i", $hidrometro_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $hidrometro = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$hidrometro) {
            retornar_json(false, "Hidrômetro não encontrado");
        }
        
        // Buscar última leitura
        $stmt = $conexao->prepare("SELECT leitura_atual FROM leituras WHERE hidrometro_id = ? ORDER BY data_leitura DESC LIMIT 1");
        $stmt->bind_param("i", $hidrometro_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $ultima = $resultado->fetch_assoc();
        $leitura_anterior = $ultima ? floatval($ultima['leitura_atual']) : 0;
        $stmt->close();
        
        // Validar leitura atual: igual ou maior que anterior (aceita igual para quando nao houve consumo no periodo)
        if ($leitura_atual < $leitura_anterior) {
            retornar_json(false, "Leitura atual ({$leitura_atual} m³) não pode ser menor que a leitura anterior ({$leitura_anterior} m³). Informe um valor igual ou superior.");
        }
        
        // Calcular consumo e valor
        $consumo = $leitura_atual - $leitura_anterior;
        $valor_total = calcularValor($consumo);
        
        // Inserir leitura com log de quem lançou
        $stmt = $conexao->prepare("INSERT INTO leituras (hidrometro_id, morador_id, unidade, leitura_anterior, leitura_atual, consumo, valor_metro_cubico, valor_minimo, valor_total, data_leitura, observacao, lancado_por_tipo, lancado_por_id, lancado_por_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $valor_mc = VALOR_METRO_CUBICO;
        $valor_min = VALOR_MINIMO;
        $stmt->bind_param("iisddddddsssis", $hidrometro_id, $hidrometro['morador_id'], $hidrometro['unidade'], $leitura_anterior, $leitura_atual, $consumo, $valor_mc, $valor_min, $valor_total, $data_leitura, $observacao, $lancado_por_tipo, $lancado_por_id, $lancado_por_nome);
        
        if ($stmt->execute()) {
            $id = $conexao->insert_id;
            $stmt->close();

            // Evidência fotográfica é complementar: se o vínculo falhar por qualquer
            // motivo, a leitura já gravada acima NÃO é desfeita — só a foto fica
            // sem evidência vinculada.
            $foto_vinculada = false;
            if ($foto_id !== null) {
                $stmt_foto = $conexao->prepare(
                    "UPDATE leituras_fotos SET leitura_id = ? WHERE id = ? AND hidrometro_id = ? AND leitura_id IS NULL"
                );
                $stmt_foto->bind_param("iii", $id, $foto_id, $hidrometro_id);
                $stmt_foto->execute();
                $foto_vinculada = $stmt_foto->affected_rows > 0;
                $stmt_foto->close();
            }

            registrar_log('LEITURA_REGISTRADA', "Leitura registrada: Hidrômetro {$hidrometro['numero_hidrometro']} - Consumo: {$consumo}m³ - Valor: R$ {$valor_total}" . ($foto_vinculada ? ' - com evidência fotográfica' : ''), $lancado_por_nome);
            retornar_json(true, "Leitura registrada com sucesso", array(
                'id' => $id,
                'consumo' => $consumo,
                'valor_total' => $valor_total,
                'foto_vinculada' => $foto_vinculada,
            ));
        } else {
            retornar_json(false, "Erro ao registrar leitura: " . $stmt->error);
            $stmt->close();
        }
    }
}

fechar_conexao($conexao);
