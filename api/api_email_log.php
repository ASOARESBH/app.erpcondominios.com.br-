<?php
/**
 * API para Gerenciamento de Logs de E-mail
 * 
 * Ações disponíveis:
 * - listar: Lista logs com filtros
 * - estatisticas: Retorna estatísticas de envio
 * - detalhes: Detalhes de um log específico
 * - limpar: Limpa logs antigos
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
require_once 'config.php';
require_once 'auth_helper.php';

// Função para resposta JSON
function resposta($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar conexão
if (!$conn) {
    resposta(false, 'Erro de conexão com o banco de dados');
}

// Pegar ação
$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_POST['acao']) ? $_POST['acao'] : '');

// Processar ação
switch ($acao) {
    
    case 'listar':
        listarLogs($conn);
        break;
    
    case 'estatisticas':
        obterEstatisticas($conn);
        break;
    
    case 'detalhes':
        obterDetalhes($conn);
        break;
    
    case 'limpar':
        limparLogsAntigos($conn);
        break;
    
    default:
        resposta(false, 'Ação inválida');
}

/**
 * Listar logs com filtros
 */
function listarLogs($conn) {
    // Filtros
    $status = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
    $tipo = isset($_GET['tipo']) ? mysqli_real_escape_string($conn, $_GET['tipo']) : '';
    $dataInicio = isset($_GET['data_inicio']) ? mysqli_real_escape_string($conn, $_GET['data_inicio']) : '';
    $dataFim = isset($_GET['data_fim']) ? mysqli_real_escape_string($conn, $_GET['data_fim']) : '';
    
    // Construir query
    $sql = "SELECT 
                id,
                morador_id,
                destinatario,
                assunto,
                tipo,
                status,
                mensagem_erro,
                data_envio
            FROM email_log
            WHERE 1=1";
    
    // Aplicar filtros
    if (!empty($status)) {
        $sql .= " AND status = '$status'";
    }
    
    if (!empty($tipo)) {
        $sql .= " AND tipo = '$tipo'";
    }
    
    if (!empty($dataInicio)) {
        $sql .= " AND DATE(data_envio) >= '$dataInicio'";
    }
    
    if (!empty($dataFim)) {
        $sql .= " AND DATE(data_envio) <= '$dataFim'";
    }
    
    $sql .= " ORDER BY data_envio DESC LIMIT 1000";
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        resposta(false, 'Erro ao buscar logs: ' . mysqli_error($conn));
    }
    
    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    
    resposta(true, 'Logs carregados com sucesso', $logs);
}

/**
 * Obter estatísticas de envio
 */
function obterEstatisticas($conn) {
    // Estatísticas gerais
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) as enviados,
                SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) as erros,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM email_log";
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        resposta(false, 'Erro ao buscar estatísticas: ' . mysqli_error($conn));
    }
    
    $stats = mysqli_fetch_assoc($result);
    
    // Calcular taxa de sucesso
    $total = intval($stats['total']);
    $enviados = intval($stats['enviados']);
    
    if ($total > 0) {
        $taxa = round(($enviados / $total) * 100, 1);
    } else {
        $taxa = 0;
    }
    
    $dados = [
        'total' => $total,
        'enviados' => $enviados,
        'erros' => intval($stats['erros']),
        'pendentes' => intval($stats['pendentes']),
        'taxa_sucesso' => $taxa . '%'
    ];
    
    resposta(true, 'Estatísticas carregadas', $dados);
}

/**
 * Obter detalhes de um log específico
 */
function obterDetalhes($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    $sql = "SELECT 
                l.*,
                m.nome as nome_morador,
                m.cpf as cpf_morador
            FROM email_log l
            LEFT JOIN moradores m ON l.morador_id = m.id
            WHERE l.id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        resposta(true, 'Detalhes encontrados', $row);
    } else {
        resposta(false, 'Log não encontrado');
    }
}

/**
 * Limpar logs antigos (mais de 90 dias)
 */
function limparLogsAntigos($conn) {
    // Verificar se é admin (adicione sua lógica de autenticação aqui)
    
    $sql = "DELETE FROM email_log 
            WHERE data_envio < DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND status = 'enviado'";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        $deletados = mysqli_affected_rows($conn);
        resposta(true, "Limpeza concluída: {$deletados} registros removidos", ['deletados' => $deletados]);
    } else {
        resposta(false, 'Erro ao limpar logs: ' . mysqli_error($conn));
    }
}

// Fechar conexão
mysqli_close($conn);
?>
