<?php
// =====================================================
// API PARA LISTAR PROTOCOLOS DO MORADOR LOGADO
// =====================================================

session_start();

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

// Limpar buffer e definir headers
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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Resolver morador_id (suporta token Bearer e sessão PHP) ───────────
$morador_id = null;

// 1. Token Bearer
$auth_header = '';
if (function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $auth_header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (empty($auth_header)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}
if (preg_match('/Bearer\s+(.+)$/i', $auth_header, $m)) {
    $bearer_token = trim($m[1]);
    $cx = conectar_banco();
    $st = $cx->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1");
    $st->bind_param('s', $bearer_token);
    $st->execute();
    $rr = $st->get_result();
    if ($rr->num_rows > 0) {
        $morador_id = (int)$rr->fetch_assoc()['morador_id'];
    }
    $st->close();
    fechar_conexao($cx);
}

// 2. Fallback: sessão PHP
if (!$morador_id && isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
    $morador_id = (int)($_SESSION['morador_id'] ?? 0);
}

if (!$morador_id) {
    http_response_code(401);
    retornar_json(false, 'Sessão inválida. Faça login novamente.');
}

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== LISTAR PROTOCOLOS DO MORADOR ==========
if ($metodo === 'GET') {
    $filtro_status = isset($_GET['status']) ? trim($_GET['status']) : '';

    $sql = "SELECT
                p.id,
                COALESCE(p.descricao_mercadoria, 'Encomenda') AS tipo_encomenda,
                p.descricao_mercadoria,
                p.codigo_nf,
                p.observacao AS remetente,
                DATE_FORMAT(p.data_hora_recebimento, '%d/%m/%Y %H:%i') AS data_hora_recebimento,
                p.recebedor_portaria,
                p.status,
                p.nome_recebedor_morador,
                DATE_FORMAT(p.data_hora_entrega, '%d/%m/%Y %H:%i') AS data_hora_entrega
            FROM protocolos p
            WHERE p.morador_id = ?";
    if ($filtro_status && in_array($filtro_status, ['pendente', 'entregue'])) {
        $sql .= " AND p.status = '" . $conexao->real_escape_string($filtro_status) . "'";
    }
    $sql .= " ORDER BY p.data_hora_recebimento DESC";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param('i', $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $protocolos = [];
    while ($row = $resultado->fetch_assoc()) {
        $protocolos[] = $row;
    }

    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Protocolos listados com sucesso', $protocolos);
}

fechar_conexao($conexao);
retornar_json(false, 'Método não permitido');

