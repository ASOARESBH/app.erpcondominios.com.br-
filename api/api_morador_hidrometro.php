<?php
// =====================================================
// API PARA DADOS DE HIDRÔMETRO DO MORADOR LOGADO
// =====================================================

ob_start();
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

ob_end_clean();

// ── Função utilitária ──────────────────────────────
if (!function_exists('hidro_json')) {
    function hidro_json($sucesso, $mensagem, $dados = null) {
        $resp = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resp['dados'] = $dados;
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
// Alias para compatibilidade com código legado
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        hidro_json($sucesso, $mensagem, $dados);
    }
}

// ── Obter token Bearer ─────────────────────────────
function hidro_obter_token() {
    $auth = '';
    if (function_exists('getallheaders')) {
        $h    = getallheaders();
        $auth = $h['Authorization'] ?? ($h['authorization'] ?? '');
    }
    if (empty($auth)) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($auth)) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    return null;
}

// ── Resolver morador_id ────────────────────────────
$conexao    = conectar_banco();
$morador_id = null;

// 1) Token Bearer (portal do morador)
$_hidro_token = hidro_obter_token();
if ($_hidro_token) {
    $stmt = $conexao->prepare(
        "SELECT morador_id FROM sessoes_portal
         WHERE token = ? AND ativo = 1 AND data_expiracao > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $_hidro_token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $morador_id = (int) $res->fetch_assoc()['morador_id'];
    }
    $stmt->close();
}

// 2) Sessão PHP legada
if (!$morador_id && isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
    $morador_id = (int) ($_SESSION['morador_id'] ?? 0);
}

if (!$morador_id) {
    http_response_code(401);
    hidro_json(false, 'Sessão inválida. Faça login novamente.');
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ── Somente GET permitido ──────────────────────────
if ($metodo !== 'GET') {
    http_response_code(405);
    hidro_json(false, 'Método não permitido.');
}

// ── 1. Buscar todos os hidrômetros do morador ─────
$sql_hidro = "SELECT
        h.id,
        h.numero_hidrometro,
        h.numero_lacre,
        h.unidade,
        h.ativo,
        DATE_FORMAT(h.data_instalacao, '%d/%m/%Y') AS data_instalacao,
        DATE_FORMAT(h.data_instalacao, '%Y-%m-%d')  AS data_instalacao_raw
    FROM hidrometros h
    WHERE h.morador_id = ?
    ORDER BY h.ativo DESC, h.data_instalacao DESC";

$stmt_h = $conexao->prepare($sql_hidro);
$stmt_h->bind_param('i', $morador_id);
$stmt_h->execute();
$res_h = $stmt_h->get_result();

$hidrometros      = [];
$hidrometro_ativo = null;

while ($row = $res_h->fetch_assoc()) {
    $row['ativo'] = (int) $row['ativo'];
    $hidrometros[] = $row;
    if ($row['ativo'] === 1 && $hidrometro_ativo === null) {
        $hidrometro_ativo = $row;
    }
}
$stmt_h->close();

// ── 2. Buscar histórico de leituras ───────────────
$leituras = [];

if (!empty($hidrometros)) {
    $sql_leit = "SELECT
            l.id,
            l.hidrometro_id,
            DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') AS data_leitura,
            DATE_FORMAT(l.data_leitura, '%Y-%m') AS mes_ref,
            l.leitura_anterior,
            l.leitura_atual,
            l.consumo,
            l.valor_total,
            l.observacao,
            h.numero_hidrometro,
            h.numero_lacre,
            h.ativo   AS hidrometro_ativo,
            DATE_FORMAT(h.data_instalacao, '%d/%m/%Y') AS data_instalacao_hidrometro,
            (SELECT f.id FROM leituras_fotos f WHERE f.leitura_id = l.id ORDER BY f.data_upload ASC LIMIT 1) AS foto_id
        FROM leituras l
        INNER JOIN hidrometros h ON l.hidrometro_id = h.id
        WHERE l.morador_id = ?
        ORDER BY l.data_leitura DESC
        LIMIT 24";

    $stmt_l = $conexao->prepare($sql_leit);
    $stmt_l->bind_param('i', $morador_id);
    $stmt_l->execute();
    $res_l = $stmt_l->get_result();

    while ($row = $res_l->fetch_assoc()) {
        $row['leitura_anterior'] = (float) $row['leitura_anterior'];
        $row['leitura_atual']    = (float) $row['leitura_atual'];
        $row['consumo']          = (float) $row['consumo'];
        $row['valor_total']      = (float) $row['valor_total'];
        $row['hidrometro_ativo'] = (int)   $row['hidrometro_ativo'];
        $leituras[] = $row;
    }
    $stmt_l->close();
}

fechar_conexao($conexao);

// ── Retornar resposta ──────────────────────────────
// 'hidrometros'       => chave consumida pelo portal_morador.html
// 'todos_hidrometros' => compatibilidade com código legado
// 'hidrometro_ativo'  => compatibilidade com código legado
hidro_json(true, 'Dados obtidos com sucesso', [
    'hidrometros'       => $hidrometros,
    'todos_hidrometros' => $hidrometros,
    'hidrometro_ativo'  => $hidrometro_ativo,
    'leituras'          => $leituras,
]);
?>
