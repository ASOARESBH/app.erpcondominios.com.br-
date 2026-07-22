<?php
/**
 * API de Logs de Erro
 * Registra erros de JavaScript, PHP e outros para debug
 */

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

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';

// Verificar se a tabela logs_erro existe
$table_check = $conexao->query("SHOW TABLES LIKE 'logs_erro'");
if ($table_check->num_rows === 0) {
    retornar_json([
        'sucesso' => false,
        'mensagem' => 'Tabela logs_erro não existe. Execute o script create_logs_erro.sql no banco de dados.',
        'instrucoes' => 'Acesse phpMyAdmin → SQL → Cole o conteúdo de create_logs_erro.sql → Executar'
    ]);
}

// Função para registrar log de erro
function registrar_log_erro($conexao, $dados) {
    try {
        $stmt = $conexao->prepare("
            INSERT INTO logs_erro 
            (tipo, nivel, arquivo, funcao, linha, mensagem, stack_trace, contexto, url, user_agent, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt->bind_param(
            "ssssissssss",
            $dados['tipo'],
            $dados['nivel'],
            $dados['arquivo'],
            $dados['funcao'],
            $dados['linha'],
            $dados['mensagem'],
            $dados['stack_trace'],
            $dados['contexto'],
            $dados['url'],
            $dados['user_agent'],
            $ip
        );
        
        if ($stmt->execute()) {
            $log_id = $stmt->insert_id;
            $stmt->close();
            return ['sucesso' => true, 'log_id' => $log_id];
        } else {
            $stmt->close();
            return ['sucesso' => false, 'mensagem' => 'Erro ao inserir log: ' . $conexao->error];
        }
    } catch (Exception $e) {
        return ['sucesso' => false, 'mensagem' => 'Exceção ao registrar log: ' . $e->getMessage()];
    }
}

// Função para listar logs de erro
function listar_logs_erro($conexao, $filtros = []) {
    try {
        $where = [];
        $params = [];
        $types = '';
        
        if (!empty($filtros['tipo'])) {
            $where[] = "tipo = ?";
            $params[] = $filtros['tipo'];
            $types .= 's';
        }
        
        if (!empty($filtros['nivel'])) {
            $where[] = "nivel = ?";
            $params[] = $filtros['nivel'];
            $types .= 's';
        }
        
        if (!empty($filtros['arquivo'])) {
            $where[] = "arquivo LIKE ?";
            $params[] = '%' . $filtros['arquivo'] . '%';
            $types .= 's';
        }
        
        if (!empty($filtros['data_inicial'])) {
            $where[] = "DATE(data_hora) >= ?";
            $params[] = $filtros['data_inicial'];
            $types .= 's';
        }
        
        if (!empty($filtros['data_final'])) {
            $where[] = "DATE(data_hora) <= ?";
            $params[] = $filtros['data_final'];
            $types .= 's';
        }
        
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $limit = isset($filtros['limit']) ? intval($filtros['limit']) : 100;
        $offset = isset($filtros['offset']) ? intval($filtros['offset']) : 0;
        
        $sql = "
            SELECT 
                id, tipo, nivel, arquivo, funcao, linha, mensagem, 
                stack_trace, contexto, url, user_agent, ip_address, data_hora
            FROM logs_erro
            $whereSQL
            ORDER BY data_hora DESC
            LIMIT $limit OFFSET $offset
        ";
        
        if (!empty($params)) {
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conexao->query($sql);
        }
        
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            // Decodificar contexto JSON
            if ($row['contexto']) {
                $row['contexto'] = json_decode($row['contexto'], true);
            }
            $logs[] = $row;
        }
        
        // Contar total
        $sqlCount = "SELECT COUNT(*) as total FROM logs_erro $whereSQL";
        if (!empty($params)) {
            $stmtCount = $conexao->prepare($sqlCount);
            $stmtCount->bind_param($types, ...$params);
            $stmtCount->execute();
            $resultCount = $stmtCount->get_result();
        } else {
            $resultCount = $conexao->query($sqlCount);
        }
        $total = $resultCount->fetch_assoc()['total'];
        
        return [
            'sucesso' => true,
            'dados' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    } catch (Exception $e) {
        return ['sucesso' => false, 'mensagem' => 'Erro ao listar logs: ' . $e->getMessage()];
    }
}

// Função para obter estatísticas de erros
function obter_estatisticas_erro($conexao) {
    try {
        $stats = [];
        
        // Total de erros
        $result = $conexao->query("SELECT COUNT(*) as total FROM logs_erro");
        $stats['total'] = $result->fetch_assoc()['total'];
        
        // Erros por tipo
        $result = $conexao->query("
            SELECT tipo, COUNT(*) as total 
            FROM logs_erro 
            GROUP BY tipo 
            ORDER BY total DESC
        ");
        $stats['por_tipo'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['por_tipo'][$row['tipo']] = $row['total'];
        }
        
        // Erros por nível
        $result = $conexao->query("
            SELECT nivel, COUNT(*) as total 
            FROM logs_erro 
            GROUP BY nivel 
            ORDER BY 
                CASE nivel
                    WHEN 'critical' THEN 1
                    WHEN 'error' THEN 2
                    WHEN 'warning' THEN 3
                    WHEN 'info' THEN 4
                    WHEN 'debug' THEN 5
                END
        ");
        $stats['por_nivel'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['por_nivel'][$row['nivel']] = $row['total'];
        }
        
        // Erros nas últimas 24h
        $result = $conexao->query("
            SELECT COUNT(*) as total 
            FROM logs_erro 
            WHERE data_hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stats['ultimas_24h'] = $result->fetch_assoc()['total'];
        
        // Arquivos com mais erros
        $result = $conexao->query("
            SELECT arquivo, COUNT(*) as total 
            FROM logs_erro 
            WHERE arquivo IS NOT NULL
            GROUP BY arquivo 
            ORDER BY total DESC 
            LIMIT 10
        ");
        $stats['arquivos_mais_erros'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['arquivos_mais_erros'][] = $row;
        }
        
        return ['sucesso' => true, 'dados' => $stats];
    } catch (Exception $e) {
        return ['sucesso' => false, 'mensagem' => 'Erro ao obter estatísticas: ' . $e->getMessage()];
    }
}

// Processar requisição
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    // Registrar novo log de erro
    $input = file_get_contents('php://input');
    $dados = json_decode($input, true);
    
    if (!$dados) {
        retornar_json(['sucesso' => false, 'mensagem' => 'Dados inválidos']);
    }
    
    // Validar campos obrigatórios
    if (empty($dados['tipo']) || empty($dados['nivel']) || empty($dados['mensagem'])) {
        retornar_json(['sucesso' => false, 'mensagem' => 'Campos obrigatórios: tipo, nivel, mensagem']);
    }
    
    // Preparar dados
    $dadosLog = [
        'tipo' => $dados['tipo'],
        'nivel' => $dados['nivel'],
        'arquivo' => $dados['arquivo'] ?? null,
        'funcao' => $dados['funcao'] ?? null,
        'linha' => $dados['linha'] ?? null,
        'mensagem' => $dados['mensagem'],
        'stack_trace' => $dados['stack_trace'] ?? null,
        'contexto' => $dados['contexto'] ?? null,
        'url' => $dados['url'] ?? null,
        'user_agent' => $dados['user_agent'] ?? null
    ];
    
    $resultado = registrar_log_erro($conexao, $dadosLog);
    retornar_json($resultado);
    
} elseif ($metodo === 'GET') {
    $action = $_GET['action'] ?? 'listar';
    
    if ($action === 'listar') {
        // Listar logs com filtros
        $filtros = [
            'tipo' => $_GET['tipo'] ?? null,
            'nivel' => $_GET['nivel'] ?? null,
            'arquivo' => $_GET['arquivo'] ?? null,
            'data_inicial' => $_GET['data_inicial'] ?? null,
            'data_final' => $_GET['data_final'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $resultado = listar_logs_erro($conexao, $filtros);
        retornar_json($resultado);
        
    } elseif ($action === 'estatisticas') {
        // Obter estatísticas
        $resultado = obter_estatisticas_erro($conexao);
        retornar_json($resultado);
        
    } else {
        retornar_json(['sucesso' => false, 'mensagem' => 'Ação inválida']);
    }
    
} else {
    retornar_json(['sucesso' => false, 'mensagem' => 'Método não permitido']);
}

$conexao->close();
?>
