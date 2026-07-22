<?php
// =====================================================
// VISUALIZADOR DE ANEXOS (PDF E IMAGENS)
// =====================================================

session_start();

ob_start();
require_once 'config.php';
ob_end_clean();

// Verificar se o morador está logado
if (!isset($_SESSION['morador_logado']) || $_SESSION['morador_logado'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo "Acesso negado. Faça login.";
    exit;
}

$conexao = conectar_banco();
$morador_id = $_SESSION['morador_id'];
$notificacao_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($notificacao_id === 0) {
    header('HTTP/1.1 400 Bad Request');
    echo "ID de notificação inválido.";
    exit;
}

// Buscar dados da notificação
$stmt = $conexao->prepare("SELECT anexo_nome, anexo_caminho, anexo_tipo FROM notificacoes WHERE id = ? AND ativo = 1");
$stmt->bind_param("i", $notificacao_id);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $stmt->close();
    fechar_conexao($conexao);
    header('HTTP/1.1 404 Not Found');
    echo "Notificação não encontrada.";
    exit;
}

$notificacao = $resultado->fetch_assoc();
$stmt->close();

if (!$notificacao['anexo_caminho'] || !file_exists($notificacao['anexo_caminho'])) {
    fechar_conexao($conexao);
    header('HTTP/1.1 404 Not Found');
    echo "Arquivo não encontrado.";
    exit;
}

// Registrar visualização (se ainda não foi)
$stmt = $conexao->prepare("SELECT id FROM notificacoes_visualizacoes WHERE notificacao_id = ? AND morador_id = ?");
$stmt->bind_param("ii", $notificacao_id, $morador_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    $data_visualizacao = date('Y-m-d H:i:s');
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $stmt = $conexao->prepare("INSERT INTO notificacoes_visualizacoes (notificacao_id, morador_id, data_visualizacao, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $notificacao_id, $morador_id, $data_visualizacao, $ip_address);
    $stmt->execute();
    $stmt->close();
    
    registrar_log('INFO', "Morador ID $morador_id visualizou anexo da notificação ID $notificacao_id");
} else {
    $stmt->close();
}

fechar_conexao($conexao);

// Limpar qualquer saída anterior
if (ob_get_level()) {
    ob_end_clean();
}

// Definir headers para visualização inline
header('Content-Type: ' . $notificacao['anexo_tipo']);
header('Content-Disposition: inline; filename="' . $notificacao['anexo_nome'] . '"');
header('Content-Length: ' . filesize($notificacao['anexo_caminho']));
header('Cache-Control: private, max-age=3600');
header('Pragma: public');

// Enviar arquivo
$handle = fopen($notificacao['anexo_caminho'], 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
exit;

