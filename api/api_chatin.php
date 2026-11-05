<?php
/**
 * ERP CONDOMÍNIOS — API CHAT-IN
 *
 * Autenticação:
 *   - Portal do Morador / aplicativo: Authorization: Bearer <token sessoes_portal>  → MORADOR
 *   - Painel (layout-base): sessão PHP do operador                                 → USUARIO
 * A identidade e o tenant vêm SEMPRE da sessão; o cliente nunca informa quem é.
 *
 * GET  ?acao=sync&plataforma=painel|portal|app&versao=&conversa_id=&apos_id=&marcar_lida=1
 *        → presença + total de não lidas; lista de conversas quando a versão mudou;
 *          mensagens novas da conversa aberta (polling ~5 s)
 * GET  ?acao=conversas[&arquivadas=1]
 * GET  ?acao=contatos&q=
 * GET  ?acao=mensagens&conversa_id=&antes_id=&limite=
 * GET  ?acao=preferencias
 * POST ?acao=abrir        {tipo: USUARIO|MORADOR, id}
 * POST ?acao=enviar       {conversa_id, corpo, idempotency_key}
 * POST ?acao=lida         {conversa_id, ate_id}
 * POST ?acao=arquivar     {conversa_id, arquivar: true|false}
 * POST ?acao=preferencias {visivel_busca, mostrar_online, notificar_push}
 * POST ?acao=bloquear     {tipo, id, bloquear: true|false}
 */

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/helpers/chatin_helper.php';
ob_end_clean();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$origemHttp = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?erpcondominios\.com\.br$/i', $origemHttp) || preg_match('/^https?:\/\/localhost(:\d+)?$/i', $origemHttp)) {
    header('Access-Control-Allow-Origin: ' . $origemHttp);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function chatin_responder($sucesso, $mensagem, $dados = null, $status = 200, $codigo = null) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    $r = ['sucesso' => (bool)$sucesso, 'mensagem' => (string)$mensagem, 'dados' => $dados === null ? [] : $dados];
    if ($codigo !== null) $r['codigo'] = $codigo;
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[ChatIn][Uncaught] ' . get_class($e) . ' codigo=' . (int)$e->getCode());
    chatin_responder(false, 'Não foi possível concluir a operação do Chat-In. Tente novamente.', null, 500, 'INTERNAL_ERROR');
});

function chatin_token_bearer() {
    $cab = '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $cab = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if ($cab === '') $cab = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{20,256})$/i', trim($cab), $m)) return null;
    return $m[1];
}

/** Resolve quem está usando o chat: morador (Bearer) ou usuário do painel (sessão). */
function chatin_autenticar(mysqli $c) {
    $token = chatin_token_bearer();
    if ($token !== null) {
        $sql = chatin_coluna_existe($c, 'sessoes_portal', 'tenant_id')
            ? 'SELECT sp.morador_id, m.tenant_id FROM sessoes_portal sp INNER JOIN moradores m ON m.id = sp.morador_id AND m.ativo = 1 AND (sp.tenant_id IS NULL OR m.tenant_id = sp.tenant_id)
               WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() LIMIT 1'
            : 'SELECT sp.morador_id, m.tenant_id FROM sessoes_portal sp INNER JOIN moradores m ON m.id = sp.morador_id AND m.ativo = 1
               WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() LIMIT 1';
        $s = chatin_linha($c, $sql, 's', [$token]);
        if (!$s) chatin_responder(false, 'Sessão inválida ou expirada. Faça login novamente.', null, 401, 'AUTH_INVALID');
        $tenantId = (int)$s['tenant_id'];
        $pessoa = chatin_pessoa($c, $tenantId, 'MORADOR', (int)$s['morador_id']);
        if (!$pessoa) chatin_responder(false, 'Morador inativo.', null, 401, 'AUTH_INVALID');
        return [$tenantId, $pessoa];
    }
    $auth = verificarAutenticacao(false);
    if (!$auth || empty($auth['id'])) chatin_responder(false, 'Autenticação necessária. Faça login novamente.', null, 401, 'AUTH_REQUIRED');
    $tenantId = (int)($auth['tenant_id'] ?? 0);
    if ($tenantId <= 0) chatin_responder(false, 'Contexto de condomínio não identificado.', null, 403, 'TENANT_REQUIRED');
    $pessoa = chatin_pessoa($c, $tenantId, 'USUARIO', (int)$auth['id']);
    if (!$pessoa) chatin_responder(false, 'Usuário sem acesso a este condomínio.', null, 403, 'USUARIO_SEM_ACESSO');
    return [$tenantId, $pessoa];
}

function chatin_pessoa_param(array $dados) {
    $tipo = strtoupper(trim((string)($dados['tipo'] ?? '')));
    $id = (int)($dados['id'] ?? 0);
    if (!in_array($tipo, CHATIN_TIPOS_PESSOA, true) || $id <= 0) throw new ChatInErro('Contato inválido.', 422, 'CONTATO_INVALIDO');
    return ['tipo' => $tipo, 'id' => $id];
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$dados = [];
if ($metodo === 'POST') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) chatin_responder(false, 'Envie um JSON válido.', null, 400, 'JSON_INVALIDO');
    $dados = $json;
}
$acao = strtolower(trim((string)($_GET['acao'] ?? $dados['acao'] ?? 'sync')));

try {
    $c = conectar_banco();
    if (!$c) chatin_responder(false, 'Serviço indisponível.', null, 503, 'DB_INDISPONIVEL');
    $c->set_charset('utf8mb4');
    if (!chatin_instalado($c)) chatin_responder(false, 'Chat-In ainda não foi instalado neste servidor (migration pendente).', ['instalado' => false], 503, 'NAO_INSTALADO');
    list($tenantId, $eu) = chatin_autenticar($c);
    $euPublico = ['tipo' => $eu['tipo'], 'id' => $eu['id'], 'nome' => $eu['nome'], 'subtitulo' => $eu['subtitulo']];

    if ($metodo === 'GET' && $acao === 'sync') {
        $plataforma = (string)($_GET['plataforma'] ?? ($eu['tipo'] === 'USUARIO' ? 'painel' : 'portal'));
        $conversaId = (int)($_GET['conversa_id'] ?? 0);
        $aposId = (int)($_GET['apos_id'] ?? 0);
        if ($conversaId > 0 && !chatin_participacao($c, $tenantId, $conversaId, $eu)) $conversaId = 0;
        chatin_tocar_presenca($c, $tenantId, $eu, $plataforma, $conversaId ?: null);
        $saida = ['eu' => $euPublico];
        if ($conversaId > 0) {
            $saida['mensagens'] = chatin_mensagens($c, $tenantId, $eu, $conversaId, $aposId > 0 ? $aposId : 0, 0, $aposId > 0 ? 100 : 40);
            if (!empty($_GET['marcar_lida'])) chatin_marcar_lida($c, $tenantId, $eu, $conversaId);
            $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
            $mapa = chatin_online_mapa($c, $tenantId, array_values(array_filter($outros, function ($o) { return $o['tipo'] !== 'SISTEMA'; })));
            $saida['conversa_id'] = $conversaId;
            $saida['contato_online'] = $outros && $outros[0]['tipo'] !== 'SISTEMA' ? ($mapa[chatin_sigla($outros[0]['tipo']) . (int)$outros[0]['id']] ?? false) : false;
        }
        $versao = chatin_versao($c, $tenantId, $eu);
        $saida['versao'] = $versao;
        $saida['total_nao_lidas'] = array_sum(chatin_nao_lidas_mapa($c, $tenantId, $eu));
        if ((string)($_GET['versao'] ?? '') !== $versao) $saida['conversas'] = chatin_listar_conversas($c, $tenantId, $eu);
        chatin_responder(true, 'OK', $saida);
    }
    if ($metodo === 'GET' && $acao === 'conversas') {
        chatin_responder(true, 'OK', ['eu' => $euPublico, 'conversas' => chatin_listar_conversas($c, $tenantId, $eu, !empty($_GET['arquivadas'])), 'versao' => chatin_versao($c, $tenantId, $eu)]);
    }
    if ($metodo === 'GET' && $acao === 'contatos') {
        chatin_responder(true, 'OK', ['contatos' => chatin_buscar_contatos($c, $tenantId, $eu, (string)($_GET['q'] ?? ''))]);
    }
    if ($metodo === 'GET' && $acao === 'mensagens') {
        $conversaId = (int)($_GET['conversa_id'] ?? 0);
        $msgs = chatin_mensagens($c, $tenantId, $eu, $conversaId, 0, (int)($_GET['antes_id'] ?? 0), (int)($_GET['limite'] ?? 40));
        chatin_responder(true, 'OK', ['mensagens' => $msgs, 'tem_mais' => count($msgs) >= max(1, min(100, (int)($_GET['limite'] ?? 40)))]);
    }
    if ($metodo === 'GET' && $acao === 'preferencias') {
        chatin_responder(true, 'OK', chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id']));
    }
    if ($metodo === 'POST' && $acao === 'abrir') {
        $outro = chatin_pessoa_param($dados);
        $conversaId = chatin_abrir_direta($c, $tenantId, $eu, $outro);
        $pessoa = chatin_pessoa($c, $tenantId, $outro['tipo'], $outro['id']);
        $online = chatin_online_mapa($c, $tenantId, [$outro]);
        chatin_responder(true, 'Conversa aberta.', [
            'conversa' => [
                'id' => $conversaId, 'tipo' => 'DIRETA', 'titulo' => $pessoa['nome'], 'subtitulo' => $pessoa['subtitulo'],
                'contato' => $pessoa + ['online' => $online[chatin_sigla($outro['tipo']) . $outro['id']] ?? false, 'ativo' => true, 'bloqueado_por_mim' => chatin_eu_bloqueei($c, $tenantId, $eu, $outro)],
                'somente_leitura' => false,
            ],
            'mensagens' => chatin_mensagens($c, $tenantId, $eu, $conversaId),
        ]);
    }
    if ($metodo === 'POST' && $acao === 'enviar') {
        $msg = chatin_enviar($c, $tenantId, $eu, (int)($dados['conversa_id'] ?? 0), (string)($dados['corpo'] ?? ''), $dados['idempotency_key'] ?? null);
        chatin_responder(true, 'Mensagem enviada.', ['mensagem' => $msg], empty($msg['idempotente']) ? 201 : 200);
    }
    if ($metodo === 'POST' && $acao === 'lida') {
        chatin_marcar_lida($c, $tenantId, $eu, (int)($dados['conversa_id'] ?? 0), (int)($dados['ate_id'] ?? 0));
        chatin_responder(true, 'OK', ['total_nao_lidas' => array_sum(chatin_nao_lidas_mapa($c, $tenantId, $eu))]);
    }
    if ($metodo === 'POST' && $acao === 'arquivar') {
        chatin_arquivar($c, $tenantId, $eu, (int)($dados['conversa_id'] ?? 0), !empty($dados['arquivar']));
        chatin_responder(true, !empty($dados['arquivar']) ? 'Conversa arquivada.' : 'Conversa restaurada.');
    }
    if ($metodo === 'POST' && $acao === 'preferencias') {
        chatin_responder(true, 'Preferências salvas.', chatin_salvar_preferencias($c, $tenantId, $eu, $dados));
    }
    if ($metodo === 'POST' && $acao === 'bloquear') {
        $outro = chatin_pessoa_param($dados);
        $bloquear = !array_key_exists('bloquear', $dados) || !empty($dados['bloquear']);
        chatin_definir_bloqueio($c, $tenantId, $eu, $outro, $bloquear);
        chatin_responder(true, $bloquear ? 'Contato bloqueado.' : 'Contato desbloqueado.', ['bloqueado' => $bloquear]);
    }
    chatin_responder(false, 'Ação não permitida.', null, 405, 'ACAO_NAO_PERMITIDA');
} catch (ChatInErro $e) {
    chatin_responder(false, $e->getMessage(), null, $e->status, $e->codigo);
} catch (Throwable $e) {
    error_log('[ChatIn][Fatal] acao=' . $acao . ' ' . get_class($e) . ' codigo=' . (int)$e->getCode());
    chatin_responder(false, 'Não foi possível concluir a operação do Chat-In. Tente novamente.', null, 500, 'INTERNAL_ERROR');
}
