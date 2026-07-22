<?php
/**
 * API PARA CRUD DE VEÍCULOS - COM SUPORTE A DEPENDENTES
 * 
 * Funcionalidades:
 * 1. Listar veículos de um morador
 * 2. Listar veículos de um dependente
 * 3. Obter veículo específico
 * 4. Criar veículo (com validações de placa, TAG e dependente)
 * 5. Atualizar veículo
 * 6. Deletar veículo
 * 7. Validar placa duplicada
 * 8. Validar TAG duplicada
 * 9. Validar um veículo por dependente
 */

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'error_logger.php';
require_once 'debug_veiculos.php';

// Registrar debug inicial
registrar_debug('INICIO', 'API de veículos iniciada', array(
    'GET' => $_GET,
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD']
));

// Limpar buffer e definir headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array(
            'sucesso' => $sucesso,
            'mensagem' => $mensagem
        );
        if ($dados !== null) {
            $resposta['dados'] = $dados;
        }
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// MÓDULO RELATÓRIOS — filtros compartilhados
// ============================================================
// Usado pelas ações relatorio_dashboard e relatorio_listar (e reaproveitado
// pela exportação CSV, que é a mesma ação sem paginação) para nunca haver
// duas implementações divergentes do mesmo WHERE.
function _veic_montar_filtros($conexao) {
    $where  = ['1=1'];
    $params = [];
    $tipos  = '';

    if (!empty($_GET['data_inicio'])) {
        $where[] = 'DATE(v.data_cadastro) >= ?';
        $params[] = sanitizar($conexao, $_GET['data_inicio']);
        $tipos .= 's';
    }
    if (!empty($_GET['data_fim'])) {
        $where[] = 'DATE(v.data_cadastro) <= ?';
        $params[] = sanitizar($conexao, $_GET['data_fim']);
        $tipos .= 's';
    }
    if (!empty($_GET['unidade'])) {
        $where[] = 'm.unidade = ?';
        $params[] = sanitizar($conexao, $_GET['unidade']);
        $tipos .= 's';
    }
    if (!empty($_GET['morador_id'])) {
        $where[] = 'v.morador_id = ?';
        $params[] = intval($_GET['morador_id']);
        $tipos .= 'i';
    }
    if (!empty($_GET['dependente_id'])) {
        $where[] = 'v.dependente_id = ?';
        $params[] = intval($_GET['dependente_id']);
        $tipos .= 'i';
    }
    if (!empty($_GET['modelo'])) {
        $where[] = 'v.modelo LIKE ?';
        $params[] = '%' . sanitizar($conexao, $_GET['modelo']) . '%';
        $tipos .= 's';
    }
    if (!empty($_GET['cor'])) {
        $where[] = 'v.cor = ?';
        $params[] = sanitizar($conexao, $_GET['cor']);
        $tipos .= 's';
    }
    if (!empty($_GET['tipo'])) {
        $where[] = 'v.tipo = ?';
        $params[] = sanitizar($conexao, $_GET['tipo']);
        $tipos .= 's';
    }
    if (!empty($_GET['placa'])) {
        // Aceita ABC1234 (antiga) ou ABC1D23 (Mercosul) sem diferenciar — a
        // busca é por substring da placa normalizada (sem espaços/hífen).
        $placa_norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['placa']));
        $where[] = "REPLACE(REPLACE(v.placa,'-',''),' ','') LIKE ?";
        $params[] = '%' . sanitizar($conexao, $placa_norm) . '%';
        $tipos .= 's';
    }
    if (!empty($_GET['tag'])) {
        $where[] = 'v.tag LIKE ?';
        $params[] = '%' . sanitizar($conexao, $_GET['tag']) . '%';
        $tipos .= 's';
    }
    if (!empty($_GET['ativo']) && in_array($_GET['ativo'], ['0', '1'], true)) {
        $where[] = 'v.ativo = ?';
        $params[] = intval($_GET['ativo']);
        $tipos .= 'i';
    }
    if (!empty($_GET['dependentes_apenas'])) {
        $where[] = 'v.dependente_id IS NOT NULL';
    }
    if (!empty($_GET['sem_tag'])) {
        $where[] = "(v.tag IS NULL OR v.tag = '')";
    }
    if (!empty($_GET['busca'])) {
        $b = '%' . sanitizar($conexao, $_GET['busca']) . '%';
        $where[] = '(m.nome LIKE ? OR d.nome_completo LIKE ? OR m.unidade LIKE ? OR v.modelo LIKE ? OR v.cor LIKE ? OR v.placa LIKE ? OR v.tag LIKE ?)';
        for ($i = 0; $i < 7; $i++) { $params[] = $b; $tipos .= 's'; }
    }

    return [implode(' AND ', $where), $params, $tipos];
}

try {
    // Verificar autenticação
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    $conexao = conectar_banco();
    
    if (!$conexao) {
        throw new Exception("Erro ao conectar ao banco de dados");
    }

    // Para operações de escrita, verificar permissão de admin
    if ($metodo !== 'GET') {
        verificarPermissao('admin');
    }

    // Coluna aditiva "tipo" — o formulário de cadastro já coleta este campo
    // (Carro/Moto/Caminhonete/Utilitario) mas a tabela nunca teve a coluna,
    // então o valor sempre era descartado silenciosamente. Correção aditiva
    // (não altera nenhuma regra de negócio existente, apenas passa a
    // persistir um campo que o formulário já envia).
    $rCol = $conexao->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='veiculos' AND COLUMN_NAME='tipo'");
    if ($rCol && (int)$rCol->fetch_assoc()['c'] === 0) {
        $conexao->query("ALTER TABLE veiculos ADD COLUMN tipo VARCHAR(50) DEFAULT NULL AFTER cor");
    }
    
    // ========== LISTAR TODOS OS VEÍCULOS (COM SUPORTE A FILTROS) ==========
    // A checagem de "acao" abaixo é aditiva: nenhuma chamada existente envia
    // esse parâmetro, então o comportamento atual continua idêntico; só as
    // novas rotas da aba Relatórios (?acao=relatorio_*) são desviadas dela.
    if ($metodo === 'GET' && !isset($_GET['morador_id']) && !isset($_GET['id']) && !isset($_GET['acao'])) {
        registrar_debug('LISTAR_TODOS', 'Listando todos os veículos');
        
        try {
            // Construir query com filtros opcionais
            $query = "
                SELECT v.id, v.placa, v.modelo, v.cor, v.tipo, v.tag, v.morador_id, v.dependente_id,
                       v.ativo, DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro,
                       m.nome as morador_nome,
                       d.nome_completo as dependente_nome
                FROM veiculos v
                INNER JOIN moradores m ON v.morador_id = m.id
                LEFT JOIN dependentes d ON v.dependente_id = d.id
                WHERE 1=1
            ";
            
            $params = array();
            $tipos = '';
            
            // Filtro por placa
            if (!empty($_GET['placa'])) {
                $placa_filtro = '%' . sanitizar($conexao, $_GET['placa']) . '%';
                $query .= " AND v.placa LIKE ?";
                $params[] = $placa_filtro;
                $tipos .= 's';
            }
            
            // Filtro por nome do morador
            if (!empty($_GET['morador_nome'])) {
                $morador_nome_filtro = '%' . sanitizar($conexao, $_GET['morador_nome']) . '%';
                $query .= " AND m.nome LIKE ?";
                $params[] = $morador_nome_filtro;
                $tipos .= 's';
            }
            
            // Filtro por dependente
            if (!empty($_GET['dependente'])) {
                $dependente_filtro = '%' . sanitizar($conexao, $_GET['dependente']) . '%';
                $query .= " AND d.nome_completo LIKE ?";
                $params[] = $dependente_filtro;
                $tipos .= 's';
            }
            
            $query .= " ORDER BY v.data_cadastro DESC";
            
            $stmt = $conexao->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            
            // Bind dos parâmetros de filtro
            if (!empty($params)) {
                $stmt->bind_param($tipos, ...$params);
            }
            
            $stmt->execute();
            $resultado = $stmt->get_result();
            $veiculos = array();
            
            if ($resultado && $resultado->num_rows > 0) {
                while ($row = $resultado->fetch_assoc()) {
                    $veiculos[] = $row;
                }
            }
            
            $stmt->close();
            
            registrar_debug('LISTAR_TODOS_SUCESSO', 'Veículos listados', array(
                'total' => count($veiculos)
            ));
            
            retornar_json(true, "Veículos listados com sucesso", $veiculos);
        } catch (Exception $e) {
            registrar_debug('LISTAR_TODOS_ERRO', $e->getMessage());
            $errorLogger->registrarErroAPI('listar_todos', $e->getMessage());
            throw $e;
        }
    }
    
    // ========== LISTAR VEÍCULOS DE UM MORADOR ==========
    if ($metodo === 'GET' && isset($_GET['morador_id'])) {
        $morador_id = intval($_GET['morador_id']);
        
        if ($morador_id <= 0) {
            retornar_json(false, "ID do morador inválido");
        }
        
        try {
            $stmt = $conexao->prepare("
                SELECT v.id, v.placa, v.modelo, v.cor, v.tipo, v.tag, v.morador_id, v.dependente_id,
                       v.ativo, DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro,
                       d.nome_completo as dependente_nome
                FROM veiculos v
                LEFT JOIN dependentes d ON v.dependente_id = d.id
                WHERE v.morador_id = ?
                ORDER BY v.data_cadastro DESC
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            
            $stmt->bind_param("i", $morador_id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $veiculos = array();
            
            if ($resultado && $resultado->num_rows > 0) {
                while ($row = $resultado->fetch_assoc()) {
                    $veiculos[] = $row;
                }
            }
            
            $stmt->close();
            
            retornar_json(true, "Veículos listados com sucesso", $veiculos);
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('listar', $e->getMessage(), array('morador_id' => $morador_id));
            throw $e;
        }
    }
    
    // ========== OBTER VEÍCULO ESPECÍFICO ==========
    if ($metodo === 'GET' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        try {
            $stmt = $conexao->prepare("
                SELECT v.id, v.placa, v.modelo, v.cor, v.tipo, v.tag, v.morador_id, v.dependente_id,
                       v.ativo, DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro,
                       d.nome_completo as dependente_nome
                FROM veiculos v
                LEFT JOIN dependentes d ON v.dependente_id = d.id
                WHERE v.id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($resultado && $resultado->num_rows > 0) {
                $veiculo = $resultado->fetch_assoc();
                $stmt->close();
                retornar_json(true, "Veículo obtido com sucesso", $veiculo);
            } else {
                $stmt->close();
                retornar_json(false, "Veículo não encontrado");
            }
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('obter', $e->getMessage(), array('id' => $id));
            throw $e;
        }
    }

    // ============================================================
    // MÓDULO RELATÓRIOS — aba "Relatórios" de Veículos
    // ============================================================
    // Todas as ações abaixo são somente leitura (GET) e reaproveitam a
    // mesma tabela/joins já usados pelas ações de listagem acima — nenhuma
    // consulta nova é criada, apenas filtros/paginação/agregação adicionais.

    // ── Unidades para o select do filtro ───────────────────────
    // Mesma convenção já usada em outros módulos (ex.: api_documentos.php
    // _unidades_select): ordena numericamente pelo sufixo do nome, com
    // ADMIN sempre por último.
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_unidades') {
        $res = $conexao->query("
            SELECT id, nome, bloco FROM unidades WHERE tenant_id = $tenant_id AND ativo = 1
            ORDER BY CASE WHEN bloco = 'ADMIN' THEN 9999 ELSE CAST(SUBSTRING_INDEX(nome, ' ', -1) AS UNSIGNED) END ASC,
                     nome ASC
        ");
        $unidades = [];
        if ($res) while ($row = $res->fetch_assoc()) $unidades[] = $row;
        retornar_json(true, "Unidades listadas com sucesso", $unidades);
    }

    // ── Autocomplete de dependente (apenas os que já têm veículo) ──
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_buscar_dependente') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) retornar_json(true, "OK", []);
        $qLike = '%' . sanitizar($conexao, $q) . '%';
        $stmt = $conexao->prepare("
            SELECT DISTINCT d.id, d.nome_completo, d.cpf, m.unidade
            FROM veiculos v
            INNER JOIN dependentes d ON v.dependente_id = d.id
            INNER JOIN moradores m ON v.morador_id = m.id
            WHERE d.nome_completo LIKE ? OR d.cpf LIKE ?
            ORDER BY d.nome_completo ASC LIMIT 10
        ");
        $stmt->bind_param('ss', $qLike, $qLike);
        $stmt->execute();
        $res = $stmt->get_result();
        $lista = [];
        while ($row = $res->fetch_assoc()) $lista[] = $row;
        $stmt->close();
        retornar_json(true, "OK", $lista);
    }

    // ── Dashboard (KPIs respeitando os mesmos filtros da busca) ──
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_dashboard') {
        [$whereSql, $params, $tipos] = _veic_montar_filtros($conexao);

        $sql = "
            SELECT COUNT(*) AS total,
                   SUM(v.ativo = 1) AS ativos,
                   SUM(v.dependente_id IS NOT NULL) AS dependentes,
                   SUM(v.tag IS NOT NULL AND v.tag != '') AS com_tag,
                   COUNT(DISTINCT v.tipo) AS tipos_distintos
            FROM veiculos v
            INNER JOIN moradores m ON v.morador_id = m.id
            LEFT JOIN dependentes d ON v.dependente_id = d.id
            WHERE $whereSql
        ";
        $stmt = $conexao->prepare($sql);
        if (!empty($params)) $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $kpis = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $resTipos = $conexao->query("SELECT DISTINCT tipo FROM veiculos WHERE tenant_id = $tenant_id AND tipo IS NOT NULL AND tipo != '' ORDER BY tipo ASC");
        $tiposLista = [];
        if ($resTipos) while ($r = $resTipos->fetch_assoc()) $tiposLista[] = $r['tipo'];

        retornar_json(true, "OK", [
            'total'        => (int)($kpis['total'] ?? 0),
            'ativos'       => (int)($kpis['ativos'] ?? 0),
            'dependentes'  => (int)($kpis['dependentes'] ?? 0),
            'com_tag'      => (int)($kpis['com_tag'] ?? 0),
            'tipos'        => $tiposLista,
        ]);
    }

    // ── Listagem filtrada e paginada (também usada pela exportação CSV,
    //    bastando enviar por_pagina=0 para trazer todos os registros
    //    filtrados de uma vez, sem paginação) ──
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_listar') {
        [$whereSql, $params, $tipos] = _veic_montar_filtros($conexao);

        $pagina     = max(1, intval($_GET['pagina'] ?? 1));
        $porPagina  = intval($_GET['por_pagina'] ?? 20);
        $semLimite  = $porPagina <= 0;
        $porPagina  = $semLimite ? 0 : min(100, max(1, $porPagina));
        $offset     = $semLimite ? 0 : ($pagina - 1) * $porPagina;

        $sqlCount = "
            SELECT COUNT(*) AS total FROM veiculos v
            INNER JOIN moradores m ON v.morador_id = m.id
            LEFT JOIN dependentes d ON v.dependente_id = d.id
            WHERE $whereSql
        ";
        $stmtCount = $conexao->prepare($sqlCount);
        if (!empty($params)) $stmtCount->bind_param($tipos, ...$params);
        $stmtCount->execute();
        $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        $sql = "
            SELECT v.id, v.placa, v.modelo, v.cor, v.tipo, v.tag, v.ativo,
                   DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') AS data_cadastro,
                   m.nome AS morador_nome, m.unidade AS morador_unidade,
                   d.nome_completo AS dependente_nome
            FROM veiculos v
            INNER JOIN moradores m ON v.morador_id = m.id
            LEFT JOIN dependentes d ON v.dependente_id = d.id
            WHERE $whereSql
            ORDER BY m.unidade ASC, v.modelo ASC, v.placa ASC
        ";
        $paramsLista = $params;
        $tiposLista  = $tipos;
        if (!$semLimite) {
            $sql .= " LIMIT ? OFFSET ?";
            $paramsLista[] = $porPagina;
            $paramsLista[] = $offset;
            $tiposLista .= 'ii';
        }

        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new Exception("Erro ao preparar query: " . $conexao->error);
        if (!empty($paramsLista)) $stmt->bind_param($tiposLista, ...$paramsLista);
        $stmt->execute();
        $res = $stmt->get_result();
        $itens = [];
        while ($row = $res->fetch_assoc()) $itens[] = $row;
        $stmt->close();

        retornar_json(true, "OK", [
            'itens'         => $itens,
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $semLimite ? $total : $porPagina,
            'total_paginas' => $semLimite ? 1 : (int)ceil($total / max(1, $porPagina)),
        ]);
    }

    // ── Agregados: presets de "Relatórios Prontos" e dados dos gráficos ──
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'relatorio_agregado') {
        $tipoAgregado = $_GET['tipo_agregado'] ?? '';

        if ($tipoAgregado === 'por_unidade') {
            $res = $conexao->query("
                SELECT m.unidade AS chave, COUNT(*) AS total
                FROM veiculos v INNER JOIN moradores m ON v.morador_id = m.id
                GROUP BY m.unidade ORDER BY total DESC, m.unidade ASC
            ");
        } elseif ($tipoAgregado === 'por_tipo') {
            $res = $conexao->query("
                SELECT COALESCE(NULLIF(v.tipo,''),'Não informado') AS chave, COUNT(*) AS total
                FROM veiculos v WHERE tenant_id = $tenant_id GROUP BY chave ORDER BY total DESC
            ");
        } elseif ($tipoAgregado === 'por_cor') {
            $res = $conexao->query("
                SELECT COALESCE(NULLIF(v.cor,''),'Não informada') AS chave, COUNT(*) AS total
                FROM veiculos v WHERE tenant_id = $tenant_id GROUP BY chave ORDER BY total DESC
            ");
        } elseif ($tipoAgregado === 'por_mes') {
            $res = $conexao->query("
                SELECT DATE_FORMAT(v.data_cadastro, '%Y-%m') AS chave, COUNT(*) AS total
                FROM veiculos v WHERE tenant_id = $tenant_id AND v.data_cadastro >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY chave ORDER BY chave ASC
            ");
        } elseif ($tipoAgregado === 'tags_duplicadas') {
            $res = $conexao->query("
                SELECT v.tag AS chave, COUNT(*) AS total, GROUP_CONCAT(v.placa SEPARATOR ', ') AS placas
                FROM veiculos v WHERE tenant_id = $tenant_id AND v.tag IS NOT NULL AND v.tag != ''
                GROUP BY v.tag HAVING COUNT(*) > 1 ORDER BY total DESC
            ");
        } elseif ($tipoAgregado === 'placas_duplicadas') {
            $res = $conexao->query("
                SELECT v.placa AS chave, COUNT(*) AS total, GROUP_CONCAT(v.tag SEPARATOR ', ') AS tags
                FROM veiculos v WHERE tenant_id = $tenant_id GROUP BY v.placa HAVING COUNT(*) > 1 ORDER BY total DESC
            ");
        } else {
            retornar_json(false, "Tipo de agregação inválido");
        }

        $linhas = [];
        if ($res) while ($row = $res->fetch_assoc()) $linhas[] = $row;
        retornar_json(true, "OK", $linhas);
    }

    // ========== CRIAR VEÍCULO ==========
    if ($metodo === 'POST') {
        $errorLogger->registrarInfo('Iniciando criacao de veiculo', array('metodo' => 'POST'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $placa = sanitizar($conexao, strtoupper($dados['placa'] ?? ''));
        $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
        $cor = sanitizar($conexao, $dados['cor'] ?? '');
        $tipo = sanitizar($conexao, $dados['tipo'] ?? '');
        $tag = sanitizar($conexao, $dados['tag'] ?? '');
        $morador_id = intval($dados['morador_id'] ?? 0);
        $dependente_id = isset($dados['dependente_id']) && !empty($dados['dependente_id']) ? intval($dados['dependente_id']) : null;
        
        // Validações
        if (empty($placa) || empty($modelo) || empty($tag) || $morador_id <= 0) {
            retornar_json(false, "Placa, modelo, TAG e morador são obrigatórios");
        }
        
        try {
            // Verificar se morador existe
            $stmt = $conexao->prepare("SELECT id FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("i", $morador_id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows == 0) {
                $stmt->close();
                retornar_json(false, "Morador não encontrado");
            }
            $stmt->close();
            
            // Se dependente_id foi informado, verificar se existe e pertence ao morador
            if ($dependente_id !== null) {
                $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE tenant_id = $tenant_id AND id = ? AND morador_id = ?");
                if (!$stmt) {
                    throw new Exception("Erro ao preparar query: " . $conexao->error);
                }
                $stmt->bind_param("ii", $dependente_id, $morador_id);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows == 0) {
                    $stmt->close();
                    retornar_json(false, "Dependente não encontrado ou não pertence ao morador");
                }
                $stmt->close();
                
                // Verificar se dependente já tem um veículo
                $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tenant_id = $tenant_id AND dependente_id = ?");
                if (!$stmt) {
                    throw new Exception("Erro ao preparar query: " . $conexao->error);
                }
                $stmt->bind_param("i", $dependente_id);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $stmt->close();
                    retornar_json(false, "Este dependente já possui um veículo cadastrado. Máximo de 1 veículo por dependente");
                }
                $stmt->close();
            }
            
            // Verificar se placa já existe
            $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tenant_id = $tenant_id AND placa = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("s", $placa);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->close();
                retornar_json(false, "Placa já cadastrada no sistema");
            }
            $stmt->close();
            
            // Verificar se TAG já existe
            $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tenant_id = $tenant_id AND tag = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("s", $tag);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->close();
                retornar_json(false, "TAG já cadastrada no sistema");
            }
            $stmt->close();
            
            // Inserir veículo
            if ($dependente_id !== null) {
                $stmt = $conexao->prepare("INSERT INTO veiculos (placa, modelo, cor, tipo, tag, morador_id, dependente_id, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                if (!$stmt) {
                    throw new Exception("Erro ao preparar insert: " . $conexao->error);
                }
                $stmt->bind_param("sssssii", $placa, $modelo, $cor, $tipo, $tag, $morador_id, $dependente_id);
            } else {
                $stmt = $conexao->prepare("INSERT INTO veiculos (placa, modelo, cor, tipo, tag, morador_id, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)");
                if (!$stmt) {
                    throw new Exception("Erro ao preparar insert: " . $conexao->error);
                }
                $stmt->bind_param("sssssi", $placa, $modelo, $cor, $tipo, $tag, $morador_id);
            }
            
            if ($stmt->execute()) {
                $id_inserido = $conexao->insert_id;
                registrar_log('VEICULO_CRIADO', "Veículo criado: $placa (ID: $id_inserido)", $placa);
                $errorLogger->registrarInfo('Veículo criado com sucesso', array('id' => $id_inserido, 'placa' => $placa, 'tag' => $tag, 'morador_id' => $morador_id, 'dependente_id' => $dependente_id));
                retornar_json(true, "Veículo cadastrado com sucesso", array('id' => $id_inserido));
            } else {
                $errorLogger->registrarErroAPI('criar', "Erro ao cadastrar veículo: " . $stmt->error, array('placa' => $placa, 'tag' => $tag, 'morador_id' => $morador_id, 'dependente_id' => $dependente_id));
                throw new Exception("Erro ao cadastrar veículo: " . $stmt->error);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('criar', $e->getMessage(), array('placa' => $placa, 'tag' => $tag, 'morador_id' => $morador_id, 'dependente_id' => $dependente_id));
            throw $e;
        }
    }
    
    // ========== ATUALIZAR VEÍCULO ==========
    if ($metodo === 'PUT') {
        $errorLogger->registrarInfo('Iniciando atualizacao de veiculo', array('metodo' => 'PUT'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        $placa = sanitizar($conexao, strtoupper($dados['placa'] ?? ''));
        $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
        $cor = sanitizar($conexao, $dados['cor'] ?? '');
        $tipo = sanitizar($conexao, $dados['tipo'] ?? '');
        $tag = sanitizar($conexao, $dados['tag'] ?? '');

        // Validações
        if ($id <= 0 || empty($placa) || empty($modelo) || empty($tag)) {
            retornar_json(false, "Dados inválidos para atualização");
        }
        
        try {
            // Verificar se placa já existe em outro veículo
            $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tenant_id = $tenant_id AND placa = ? AND id != ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("si", $placa, $id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->close();
                retornar_json(false, "Placa já cadastrada para outro veículo");
            }
            $stmt->close();
            
            // Verificar se TAG já existe em outro veículo
            $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tenant_id = $tenant_id AND tag = ? AND id != ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("si", $tag, $id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->close();
                retornar_json(false, "TAG já cadastrada para outro veículo");
            }
            $stmt->close();
            
            // Atualizar veículo
            $stmt = $conexao->prepare("UPDATE veiculos SET placa=?, modelo=?, cor=?, tipo=?, tag=? WHERE tenant_id = $tenant_id AND id=?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("sssssi", $placa, $modelo, $cor, $tipo, $tag, $id);
            
            if ($stmt->execute()) {
                registrar_log('VEICULO_ATUALIZADO', "Veículo atualizado: $placa (ID: $id)", $placa);
                $errorLogger->registrarInfo('Veículo atualizado com sucesso', array('id' => $id, 'placa' => $placa, 'tag' => $tag));
                retornar_json(true, "Veículo atualizado com sucesso");
            } else {
                $errorLogger->registrarErroAPI('atualizar', "Erro ao atualizar veículo: " . $stmt->error, array('id' => $id, 'placa' => $placa, 'tag' => $tag));
                throw new Exception("Erro ao atualizar veículo: " . $stmt->error);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('atualizar', $e->getMessage(), array('id' => $id, 'placa' => $placa, 'tag' => $tag));
            throw $e;
        }
    }
    
    // ========== DELETAR VEÍCULO ==========
    if ($metodo === 'DELETE') {
        $errorLogger->registrarInfo('Iniciando delecao de veiculo', array('metodo' => 'DELETE'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        try {
            // Buscar placa do veículo antes de excluir
            $stmt = $conexao->prepare("SELECT placa FROM veiculos WHERE tenant_id = $tenant_id AND id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $veiculo = $resultado->fetch_assoc();
            $placa_veiculo = $veiculo['placa'] ?? 'Desconhecida';
            $stmt->close();
            
            // Excluir veículo
            $stmt = $conexao->prepare("DELETE FROM veiculos WHERE tenant_id = $tenant_id AND id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar delete: " . $conexao->error);
            }
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                registrar_log('VEICULO_DELETADO', "Veículo deletado: $placa_veiculo (ID: $id)", $placa_veiculo);
                $errorLogger->registrarInfo('Veículo deletado com sucesso', array('id' => $id, 'placa' => $placa_veiculo));
                retornar_json(true, "Veículo deletado com sucesso");
            } else {
                $errorLogger->registrarErroAPI('deletar', "Erro ao deletar veículo: " . $stmt->error, array('id' => $id));
                throw new Exception("Erro ao deletar veículo: " . $stmt->error);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('deletar', $e->getMessage(), array('id' => $id));
            throw $e;
        }
    }
    
    // ========== ALTERAR STATUS DO VEÍCULO ==========
    if ($metodo === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'alternar_status') {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        try {
            // Obter status atual
            $stmt = $conexao->prepare("SELECT ativo FROM veiculos WHERE tenant_id = $tenant_id AND id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $veiculo = $resultado->fetch_assoc();
            $stmt->close();
            
            if (!$veiculo) {
                retornar_json(false, "Veículo não encontrado");
            }
            
            $novo_status = $veiculo['ativo'] == 1 ? 0 : 1;
            
            // Atualizar status
            $stmt = $conexao->prepare("UPDATE veiculos SET ativo = ? WHERE tenant_id = $tenant_id AND id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("ii", $novo_status, $id);
            
            if ($stmt->execute()) {
                $status_texto = $novo_status == 1 ? 'Ativado' : 'Desativado';
                registrar_log('VEICULO_STATUS', "Veículo $status_texto (ID: $id)", "ID: $id");
                $errorLogger->registrarInfo("Veículo $status_texto com sucesso", array('id' => $id, 'novo_status' => $novo_status));
                retornar_json(true, "Veículo $status_texto com sucesso");
            } else {
                $errorLogger->registrarErroAPI('alterar_status', "Erro ao alterar status: " . $stmt->error, array('id' => $id));
                throw new Exception("Erro ao alterar status: " . $stmt->error);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('alterar_status', $e->getMessage(), array('id' => $id));
            throw $e;
        }
    }
    
    // Se nenhuma ação foi executada
    retornar_json(false, "Ação não reconhecida");
    
} catch (Exception $e) {
    $errorLogger->registrarErroAPI('geral', $e->getMessage(), array());
    retornar_json(false, "Erro: " . $e->getMessage());
}
?>
