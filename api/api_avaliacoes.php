<?php
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

function resposta($sucesso, $mensagem, $dados = null) {
    echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
    exit;
}

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

switch ($acao) {
    case 'listar_fornecedor':
        $fornecedor_id = intval($_GET['fornecedor_id']);
        $sql = "SELECT a.*, m.nome as morador_nome FROM avaliacoes a 
                LEFT JOIN moradores m ON a.avaliador_id=m.id AND a.avaliador_tipo='morador'
                WHERE a.avaliado_id=$fornecedor_id AND a.avaliado_tipo='fornecedor' 
                ORDER BY a.data_avaliacao DESC LIMIT 50";
        $result = mysqli_query($conn, $sql);
        $avaliacoes = [];
        while ($row = mysqli_fetch_assoc($result)) $avaliacoes[] = $row;
        resposta(true, 'Avaliações carregadas', $avaliacoes);
        break;
    
    case 'salvar':
        $pedido_id = intval($_POST['pedido_id']);
        $avaliador_tipo = mysqli_real_escape_string($conn, $_POST['avaliador_tipo']);
        $avaliador_id = intval($_POST['avaliador_id']);
        $avaliado_tipo = mysqli_real_escape_string($conn, $_POST['avaliado_tipo']);
        $avaliado_id = intval($_POST['avaliado_id']);
        $nota = intval($_POST['nota']);
        $comentario = mysqli_real_escape_string($conn, $_POST['comentario'] ?? '');
        
        $sql = "INSERT INTO avaliacoes (pedido_id, avaliador_tipo, avaliador_id, avaliado_tipo, avaliado_id, nota, comentario) 
                VALUES ($pedido_id, '$avaliador_tipo', $avaliador_id, '$avaliado_tipo', $avaliado_id, $nota, '$comentario')";
        
        mysqli_query($conn, $sql) ? resposta(true, 'Avaliação enviada!') : resposta(false, 'Erro');
        break;
    
    default:
        resposta(false, 'Ação inválida');
}
?>