<?php
/**
 * ERP CONDOMÍNIOS — Sistema > Controle de Acesso: canais de notificação por evento.
 *
 * GET → catálogo de canais (EMAIL, APP, CHATIN; WHATSAPP/SMS como "em breve") e
 *       canais ativos de cada evento (ex.: acesso.visitante_chegou) do tenant.
 * PUT {evento, canais: {EMAIL: bool, APP: bool, CHATIN: bool, ...}} → grava (Sistema > Configurar).
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/notificacao_canais_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function notif_canais_responder($sucesso, $mensagem, $dados = null, $status = 200) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code($status);
    echo json_encode(['sucesso' => (bool)$sucesso, 'mensagem' => $mensagem, 'dados' => $dados === null ? [] : $dados], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
set_exception_handler(function (Throwable $e) {
    error_log('[NotifCanais] ' . get_class($e) . ' codigo=' . (int)$e->getCode());
    notif_canais_responder(false, 'Não foi possível concluir a operação.', null, 500);
});

$auth = verificarAutenticacao(true);
$tenant_id = (int)exigirTenantId();
$conexao = conectar_banco();
$conexao->set_charset('utf8mb4');
$metodo = $_SERVER['REQUEST_METHOD'];

$rbacAtivo = function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conexao);
if ($metodo === 'GET') {
    if ($rbacAtivo) rbacExigir($conexao, 'visitantes', 'visualizar'); // mesmo padrão de api_config_visitantes.php
    else verificarPermissao('admin');
    $dados = notif_canais_config_tela($conexao, $tenant_id);
    $dados['pode_configurar'] = $rbacAtivo ? rbacPode($conexao, 'sistema', 'configurar') : true;
    notif_canais_responder(true, 'Configuração carregada.', $dados);
}

if ($metodo !== 'PUT') notif_canais_responder(false, 'Método não permitido.', null, 405);
if ($rbacAtivo) rbacExigir($conexao, 'sistema', 'configurar');
else verificarPermissao('admin');
if (!notif_canais_tabela_existe($conexao)) {
    notif_canais_responder(false, 'A estrutura ainda não foi instalada. Execute sql/migration_chatin_mysql57.sql.', null, 409);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$evento = trim((string)($entrada['evento'] ?? ''));
$canais = is_array($entrada['canais'] ?? null) ? $entrada['canais'] : null;
if ($evento === '' || $canais === null) notif_canais_responder(false, 'Informe o evento e os canais.', null, 400);
$catalogo = notif_canais_catalogo();
if (!isset($catalogo['eventos'][$evento])) notif_canais_responder(false, 'Evento desconhecido.', null, 422);

$antes = notif_canais_do_evento($conexao, $tenant_id, $evento);
$depois = notif_canais_salvar($conexao, $tenant_id, $evento, $canais, (int)($auth['id'] ?? 0));
if ($rbacAtivo && function_exists('rbacAuditar')) {
    rbacAuditar($conexao, [
        'tenant_id' => $tenant_id, 'usuario_id' => (int)($auth['id'] ?? 0), 'modulo_chave' => 'sistema',
        'acao' => 'CONFIGURAR', 'registro_tipo' => 'notificacao_canais', 'registro_id' => 0,
        'dados_antes' => [$evento => $antes], 'dados_depois' => [$evento => $depois],
        'resultado' => 'SUCESSO', 'status_http' => 200,
    ]);
}
notif_canais_responder(true, 'Canais de notificação salvos.', ['evento' => $evento, 'canais_ativos' => $depois]);
