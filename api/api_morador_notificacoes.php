<?php
// =====================================================
// API PARA NOTIFICAÇÕES DO MORADOR
// =====================================================

session_start();

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

// Não definir headers JSON se for download
if (!isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// Verificar se o morador está logado
if (!isset($_SESSION['morador_logado']) || $_SESSION['morador_logado'] !== true) {
    retornar_json(false, "Sessão inválida. Faça login novamente.");
}

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$morador_id = $_SESSION['morador_id'];

// ========== LISTAR NOTIFICAÇÕES ==========
if ($metodo === 'GET' && !isset($_GET['download'])) {
    $sql = "SELECT 
            n.id,
            n.numero_sequencial,
            n.data_hora,
            DATE_FORMAT(n.data_hora, '%d/%m/%Y %H:%i') as data_hora_formatada,
            n.assunto,
            n.resumo,
            n.anexo_nome,
            CASE WHEN n.anexo_nome IS NOT NULL THEN 1 ELSE 0 END as tem_anexo,
            CASE WHEN v.id IS NOT NULL THEN 1 ELSE 0 END as visualizada,
            CASE WHEN (SELECT COUNT(*) FROM notificacoes_downloads WHERE notificacao_id = n.id AND morador_id = ?) > 0 THEN 1 ELSE 0 END as baixada
            FROM notificacoes n
            LEFT JOIN notificacoes_visualizacoes v ON v.notificacao_id = n.id AND v.morador_id = ?
            WHERE n.ativo = 1
            ORDER BY n.data_hora DESC, n.numero_sequencial DESC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("ii", $morador_id, $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $notificacoes = array();
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $notificacoes[] = $row;
        }
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, "Notificações listadas com sucesso", $notificacoes);
}

// ========== MARCAR COMO VISUALIZADA ==========
if ($metodo === 'POST' && isset($_POST['visualizar'])) {
    $notificacao_id = intval($_POST['visualizar']);
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    // Verificar se já foi visualizada
    $stmt = $conexao->prepare("SELECT id FROM notificacoes_visualizacoes WHERE notificacao_id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $notificacao_id, $morador_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        $stmt->close();
        
        // Inserir visualização
        $data_visualizacao = date('Y-m-d H:i:s');
        $stmt = $conexao->prepare("INSERT INTO notificacoes_visualizacoes (notificacao_id, morador_id, data_visualizacao, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $notificacao_id, $morador_id, $data_visualizacao, $ip_address);
        
        if ($stmt->execute()) {
            registrar_log('INFO', "Morador ID $morador_id visualizou notificação ID $notificacao_id");
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(true, "Notificação marcada como visualizada");
        } else {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "Erro ao marcar visualização");
        }
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Notificação já foi visualizada");
    }
}

// ========== DOWNLOAD DE ANEXO ==========
if ($metodo === 'GET' && isset($_GET['download'])) {
    $notificacao_id = intval($_GET['download']);
    
    // Buscar dados da notificação
    $stmt = $conexao->prepare("SELECT anexo_nome, anexo_caminho, anexo_tipo FROM notificacoes WHERE id = ? AND ativo = 1");
    $stmt->bind_param("i", $notificacao_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        header('Content-Type: application/json');
        echo json_encode(array('sucesso' => false, 'mensagem' => 'Notificação não encontrada'));
        exit;
    }
    
    $notificacao = $resultado->fetch_assoc();
    $stmt->close();
    
    if (!$notificacao['anexo_caminho'] || !file_exists($notificacao['anexo_caminho'])) {
        fechar_conexao($conexao);
        header('Content-Type: application/json');
        echo json_encode(array('sucesso' => false, 'mensagem' => 'Arquivo não encontrado'));
        exit;
    }
    
    // Registrar download
    $data_download = date('Y-m-d H:i:s');
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $stmt = $conexao->prepare("INSERT INTO notificacoes_downloads (notificacao_id, morador_id, data_download, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $notificacao_id, $morador_id, $data_download, $ip_address);
    $stmt->execute();
    $stmt->close();
    
    // Marcar como visualizada também (se ainda não foi)
    $stmt = $conexao->prepare("SELECT id FROM notificacoes_visualizacoes WHERE notificacao_id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $notificacao_id, $morador_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        $stmt->close();
        $stmt = $conexao->prepare("INSERT INTO notificacoes_visualizacoes (notificacao_id, morador_id, data_visualizacao, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $notificacao_id, $morador_id, $data_download, $ip_address);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt->close();
    }
    
    registrar_log('INFO', "Morador ID $morador_id baixou anexo da notificação ID $notificacao_id");
    fechar_conexao($conexao);
    
    // Limpar qualquer saída anterior
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Fazer download do arquivo
    header('Content-Type: ' . $notificacao['anexo_tipo']);
    header('Content-Disposition: attachment; filename="' . $notificacao['anexo_nome'] . '"');
    header('Content-Length: ' . filesize($notificacao['anexo_caminho']));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    
    // Enviar arquivo em chunks para evitar problemas de memória
    $handle = fopen($notificacao['anexo_caminho'], 'rb');
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
    exit;
}

fechar_conexao($conexao);
retornar_json(false, "Método não permitido");

