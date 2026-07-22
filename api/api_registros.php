<?php
// =====================================================
// API PARA REGISTROS DE ACESSO v3
// Novidades: tipo_acesso (Entrada/Saída), dependente_id
// Mantém compatibilidade com colunas opcionais via
// _tem_coluna() + ALTER TABLE automático
// =====================================================

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// ===== LOG DE DEBUG =====
function log_registro($msg, $dados = null) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($dados !== null) $linha .= ' | ' . json_encode($dados, JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/registro.txt', $linha . PHP_EOL, FILE_APPEND);
}

// ===== RETORNO JSON =====
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ===== VERIFICAR / CRIAR COLUNAS EXTRAS =====
function _tem_coluna($conexao, $tabela, $coluna) {
    $r = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $r && $r->num_rows > 0;
}

function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (!_tem_coluna($conexao, $tabela, $coluna)) {
        $conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
        log_registro("ALTER TABLE: coluna $coluna adicionada a $tabela");
    }
}

// Garantir colunas novas (cria automaticamente se não existirem)
_garantir_coluna($conexao, 'registros_acesso', 'tipo_acesso',    "ENUM('Entrada','Saída') DEFAULT 'Entrada'");
_garantir_coluna($conexao, 'registros_acesso', 'dependente_id',  "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'visitante_id',   "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'documento_visitante', "VARCHAR(30) NULL DEFAULT NULL");

$tem_tipo_acesso     = true; // acabou de garantir
$tem_dependente_id   = true;
$tem_visitante_id    = true;
$tem_documento_visit = true;

// ========== LISTAR REGISTROS ==========
if ($metodo === 'GET') {
    $limite = intval($_GET['limite'] ?? 100);

    $sql = "SELECT r.id,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') AS data_hora_formatada,
            r.data_hora, r.placa, r.modelo, r.cor, r.tag, r.tipo,
            r.nome_visitante, r.unidade_destino, r.dias_permanencia,
            r.status, r.liberado, r.observacao,
            r.tipo_acesso, r.dependente_id,
            m.nome AS morador_nome, m.unidade AS morador_unidade,
            d.nome_completo AS dependente_nome
            FROM registros_acesso r
            LEFT JOIN moradores m ON r.morador_id = m.id
            LEFT JOIN dependentes d ON r.dependente_id = d.id
            ORDER BY r.data_hora DESC
            LIMIT ?";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        log_registro('ERRO prepare GET', ['erro' => $conexao->error]);
        retornar_json(false, 'Erro ao preparar consulta: ' . $conexao->error);
    }
    $stmt->bind_param('i', $limite);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $registros = [];
    while ($row = $resultado->fetch_assoc()) {
        $registros[] = $row;
    }
    $stmt->close();
    retornar_json(true, 'Registros listados com sucesso', $registros);
}

// ========== CRIAR REGISTRO MANUAL ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);

    log_registro('POST recebido', $dados);

    $data_hora        = $dados['data_hora']        ?? date('Y-m-d H:i:s');
    $placa            = strtoupper(trim($dados['placa']    ?? ''));
    $modelo           = trim($dados['modelo']           ?? '');
    $cor              = trim($dados['cor']              ?? '');
    $tipo             = trim($dados['tipo']             ?? '');
    $unidade_destino  = trim($dados['unidade_destino']  ?? '');
    $dias_permanencia = intval($dados['dias_permanencia'] ?? 0);
    $nome_visitante   = trim($dados['nome_visitante']   ?? '');
    $observacao       = trim($dados['observacao']       ?? '');
    $tipo_acesso      = trim($dados['tipo_acesso']      ?? 'Entrada');

    // Validar tipo_acesso
    if (!in_array($tipo_acesso, ['Entrada', 'Saída'])) $tipo_acesso = 'Entrada';

    // Validações
    if (empty($placa) || empty($tipo)) {
        log_registro('ERRO validacao', ['placa' => $placa, 'tipo' => $tipo]);
        retornar_json(false, 'Placa e tipo são obrigatórios');
    }

    if (!in_array($tipo, ['Morador', 'Visitante', 'Prestador'])) {
        log_registro('ERRO tipo invalido', ['tipo' => $tipo]);
        retornar_json(false, 'Tipo inválido: ' . $tipo);
    }

    $morador_id   = isset($dados['morador_id'])   && $dados['morador_id']   ? intval($dados['morador_id'])   : null;
    $visitante_id = isset($dados['visitante_id']) && $dados['visitante_id'] ? intval($dados['visitante_id']) : null;
    $dependente_id = isset($dados['dependente_id']) && $dados['dependente_id'] ? intval($dados['dependente_id']) : null;
    $documento    = trim($dados['documento'] ?? '');
    $tag          = null;
    $liberado     = 0;
    $status       = '';

    // Se for morador, buscar no banco pela placa
    if ($tipo === 'Morador') {
        // Se morador_id já foi enviado pelo frontend (seleção manual), usar diretamente
        if ($morador_id) {
            $stmt2 = $conexao->prepare("SELECT nome, unidade FROM moradores WHERE id = ?");
            if ($stmt2) {
                $stmt2->bind_param('i', $morador_id);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2->num_rows > 0) {
                    $mor = $res2->fetch_assoc();
                    $liberado = 1;
                    $status   = '✅ Acesso liberado - ' . $mor['nome'];
                    if (empty($unidade_destino)) $unidade_destino = $mor['unidade'];
                } else {
                    $status   = '🟨 Registro manual - Morador';
                    $liberado = 1;
                }
                $stmt2->close();
            }
        } else {
            // Tentar detectar pela placa
            $stmt2 = $conexao->prepare(
                "SELECT v.tag, v.morador_id, m.nome, m.unidade
                 FROM veiculos v
                 INNER JOIN moradores m ON v.morador_id = m.id
                 WHERE v.placa = ? AND v.ativo = 1"
            );
            if (!$stmt2) {
                log_registro('ERRO prepare busca veiculo', ['erro' => $conexao->error]);
                retornar_json(false, 'Erro interno ao buscar veículo: ' . $conexao->error);
            }
            $stmt2->bind_param('s', $placa);
            $stmt2->execute();
            $resultado2 = $stmt2->get_result();

            if ($resultado2->num_rows > 0) {
                $veiculo         = $resultado2->fetch_assoc();
                $morador_id      = intval($veiculo['morador_id']);
                $tag             = $veiculo['tag'];
                $liberado        = 1;
                $status          = '✅ Acesso liberado - ' . $veiculo['nome'];
                $unidade_destino = $veiculo['unidade'];
            } else {
                $status   = '❌ Acesso negado - Placa não cadastrada';
                $liberado = 0;
            }
            $stmt2->close();
        }

        // Se for dependente, buscar nome do dependente para o status
        if ($dependente_id) {
            $stmtDep = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE id = ?");
            if ($stmtDep) {
                $stmtDep->bind_param('i', $dependente_id);
                $stmtDep->execute();
                $resDep = $stmtDep->get_result();
                if ($resDep->num_rows > 0) {
                    $dep = $resDep->fetch_assoc();
                    $status .= ' (Dependente: ' . $dep['nome_completo'] . ')';
                }
                $stmtDep->close();
            }
        }

    } else {
        // Visitante ou Prestador
        $status   = '🟨 Registro manual - ' . $tipo;
        $liberado = 1;
    }

    // ── Montar INSERT dinamicamente ──────────────────────────────────────────
    $cols   = 'data_hora, placa, modelo, cor, tag, tipo, morador_id, nome_visitante, unidade_destino, dias_permanencia, status, liberado, observacao, tipo_acesso, dependente_id, visitante_id, documento_visitante';
    $marks  = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
    // s=data_hora(1) s=placa(2) s=modelo(3) s=cor(4) s=tag(5) s=tipo(6)
    // i=morador_id(7) s=nome_visitante(8) s=unidade_destino(9)
    // i=dias_permanencia(10) s=status(11) i=liberado(12) s=observacao(13)
    // s=tipo_acesso(14) i=dependente_id(15) i=visitante_id(16) s=documento_visitante(17)
    $types = 'ssssssissisissiis';

    $params = [
        &$data_hora, &$placa, &$modelo, &$cor, &$tag, &$tipo,
        &$morador_id, &$nome_visitante, &$unidade_destino,
        &$dias_permanencia, &$status, &$liberado, &$observacao,
        &$tipo_acesso, &$dependente_id, &$visitante_id, &$documento
    ];

    $sql  = "INSERT INTO registros_acesso ($cols) VALUES ($marks)";
    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        log_registro('ERRO prepare INSERT', ['sql' => $sql, 'erro' => $conexao->error]);
        retornar_json(false, 'Erro ao preparar inserção: ' . $conexao->error);
    }

    $bind_args = array_merge([&$types], $params);
    call_user_func_array([$stmt, 'bind_param'], $bind_args);

    log_registro('INSERT executando', [
        'placa'        => $placa,
        'tipo'         => $tipo,
        'tipo_acesso'  => $tipo_acesso,
        'morador_id'   => $morador_id,
        'dependente_id'=> $dependente_id,
        'visitante_id' => $visitante_id,
    ]);

    if ($stmt->execute()) {
        $id_inserido = $conexao->insert_id;
        log_registro('INSERT OK', ['id' => $id_inserido, 'status' => $status, 'tipo_acesso' => $tipo_acesso]);
        registrar_log('REGISTRO_CRIADO', "Registro manual criado: $placa ($tipo) - $tipo_acesso");
        retornar_json(true, $status, ['id' => $id_inserido, 'liberado' => $liberado, 'status' => $status, 'tipo_acesso' => $tipo_acesso]);
    } else {
        log_registro('ERRO execute INSERT', ['erro' => $stmt->error, 'errno' => $stmt->errno]);
        retornar_json(false, 'Erro ao criar registro: ' . $stmt->error);
    }

    $stmt->close();
}

// ========== ATUALIZAR REGISTRO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);

    $id         = intval($dados['id'] ?? 0);
    $observacao = trim($dados['observacao'] ?? '');
    $status     = trim($dados['status']     ?? '');

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('UPDATE registros_acesso SET observacao=?, status=? WHERE id=?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar atualização: ' . $conexao->error);
    }
    $stmt->bind_param('ssi', $observacao, $status, $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_ATUALIZADO', "Registro atualizado: ID $id");
        retornar_json(true, 'Registro atualizado com sucesso');
    } else {
        retornar_json(false, 'Erro ao atualizar registro: ' . $stmt->error);
    }
    $stmt->close();
}

// ========== EXCLUIR REGISTRO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('DELETE FROM registros_acesso WHERE id = ?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar exclusão: ' . $conexao->error);
    }
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_EXCLUIDO', "Registro excluído: ID $id");
        retornar_json(true, 'Registro excluído com sucesso');
    } else {
        retornar_json(false, 'Erro ao excluir registro: ' . $stmt->error);
    }
    $stmt->close();
}

fechar_conexao($conexao);
