<?php
/**
 * ============================================================
 * API PWA Push Notifications — Portal do Morador
 * Gerencia tokens FCM e envio de notificações push
 * ============================================================
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once __DIR__ . '/helpers/pwa_security.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !pwa_origem_permitida($origin)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Origem não autorizada.', 'dados' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Garantir que a sessão está iniciada (auth_helper pode não ter iniciado ainda)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null, $statusHttp = 200) {
        http_response_code((int)$statusHttp);
        echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$conexao = conectar_banco();
$metodo  = $_SERVER['REQUEST_METHOD'];

// ── Ler body JSON uma única vez (para não perder o stream) ────
$_body_json = [];
if ($metodo === 'POST') {
    $body_raw = file_get_contents('php://input');
    if (!empty($body_raw)) {
        $_body_json = json_decode($body_raw, true) ?? [];
    }
}

// ── Aceita 'action' (padrão da API) ou 'acao' (padrão do ERP) ─
// Prioridade: GET['action'] > GET['acao'] > body['action'] > body['acao']
$action = $_GET['action'] ?? $_GET['acao'] ?? $_body_json['action'] ?? $_body_json['acao'] ?? '';
$acoesPost = ['registrar_token', 'remover_token', 'marcar_lida', 'enviar', 'salvar_config', 'envio_automatico'];
if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    retornar_json(false, 'Método não permitido.', null, 405);
}
if (in_array($action, $acoesPost, true) && $metodo !== 'POST') {
    header('Allow: POST, OPTIONS');
    retornar_json(false, 'Esta ação exige POST.', null, 405);
}
if (in_array($action, ['enviar', 'salvar_config'], true) && ($origin === '' || !pwa_origem_permitida($origin))) {
    retornar_json(false, 'Origem não autorizada.', null, 403);
}

// ── Funções auxiliares ──────────────────────────────────────

/**
 * Autenticar morador via token Bearer (sessoes_portal)
 */
function autenticar_morador_pwa($conexao) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) return null;
    $token = trim($m[1]);
    $stmt = $conexao->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['morador_id'] : null;
}

function pwa_tenant_do_morador($conexao, int $morador_id): int {
    if ($morador_id <= 0) return 0;
    $stmt = $conexao->prepare('SELECT tenant_id FROM moradores WHERE id = ?');
    if (!$stmt || !$stmt->bind_param('i', $morador_id) || !$stmt->execute()) return 0;
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['tenant_id'] ?? 0);
}

/**
 * Autenticar usuário do ERP (sistema interno)
 * Usa verificarAutenticacao() do auth_helper.php
 */
function autenticar_erp($conexao) {
    // auth_helper.php usa camelCase: verificarAutenticacao()
    if (function_exists('verificarAutenticacao')) {
        return verificarAutenticacao(false); // false = não aborta, retorna false se não autenticado
    }
    // Fallback: verificar sessão diretamente
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) return null;
    return [
        'id'   => $_SESSION['usuario_id']   ?? null,
        'nome' => $_SESSION['usuario_nome'] ?? 'Sistema',
    ];
}

/**
 * Obter configuração FCM do banco
 */
function obter_config_fcm($conexao, int $tenant_id) {
    if ($tenant_id <= 0) return [];
    if (!\ERP\Database\DatabaseDialect::columnExists($conexao, 'pwa_configuracoes', 'tenant_id')) {
        $legacy = $conexao->query('SELECT chave, valor FROM pwa_configuracoes');
        if (!$legacy) return [];
        $config = [];
        while ($row = $legacy->fetch_assoc()) $config[$row['chave']] = $row['valor'];
        return $config;
    }
    $configPorTenant = [];
    foreach (array_unique([1, $tenant_id]) as $configTenantId) {
        $stmt = $conexao->prepare("SELECT chave, valor FROM pwa_configuracoes WHERE tenant_id = ? ORDER BY id");
        if (!$stmt || !$stmt->bind_param('i', $configTenantId) || !$stmt->execute()) return [];
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $configPorTenant[$configTenantId][$row['chave']] = $row['valor'];
            }
        }
        $stmt->close();
    }
    return pwa_mesclar_config_tenant($configPorTenant[1] ?? [], $configPorTenant[$tenant_id] ?? []);
}

function pwa_schema_pronto($conexao, string $action): bool {
    $required = [
        'registrar_token' => [['pwa_fcm_tokens' => ['id', 'tenant_id', 'morador_id', 'fcm_token', 'ativo'], 'moradores' => ['id', 'tenant_id']], ['pwa_fcm_tokens']],
        'remover_token' => [['pwa_fcm_tokens' => ['tenant_id', 'morador_id', 'fcm_token', 'ativo'], 'moradores' => ['id', 'tenant_id']], []],
        'marcar_lida' => [['pwa_notificacoes_recebidas' => ['tenant_id', 'notificacao_id', 'morador_id', 'lida', 'lida_em'], 'moradores' => ['id', 'tenant_id']], []],
        'listar_minhas' => [['pwa_notificacoes_push' => ['tenant_id', 'id', 'criado_em'], 'pwa_notificacoes_recebidas' => ['tenant_id', 'notificacao_id', 'morador_id', 'status_envio', 'lida'], 'moradores' => ['id', 'tenant_id']], []],
        'enviar' => [['pwa_configuracoes' => ['tenant_id', 'chave', 'valor'], 'pwa_oauth_cache' => ['id', 'tenant_id', 'access_token', 'expires_at'], 'pwa_fcm_tokens' => ['id', 'tenant_id', 'morador_id', 'fcm_token', 'ativo'], 'pwa_notificacoes_push' => ['id', 'tenant_id'], 'pwa_notificacoes_recebidas' => ['id', 'tenant_id'], 'moradores' => ['id', 'tenant_id', 'unidade_id'], 'unidades' => ['id', 'tenant_id']], ['pwa_notificacoes_push', 'pwa_notificacoes_recebidas', 'pwa_oauth_cache']],
        'listar_enviadas' => [['pwa_notificacoes_push' => ['tenant_id', 'id', 'criado_em']], []],
        'obter_config' => [['pwa_configuracoes' => ['tenant_id', 'chave', 'valor']], []],
        'salvar_config' => [['pwa_configuracoes' => ['id', 'tenant_id', 'chave', 'valor']], ['pwa_configuracoes']],
        'estatisticas' => [['pwa_fcm_tokens' => ['tenant_id', 'morador_id', 'ativo'], 'pwa_notificacoes_push' => ['tenant_id', 'criado_em']], []],
        'envio_automatico' => [['pwa_configuracoes' => ['tenant_id', 'chave', 'valor'], 'pwa_oauth_cache' => ['id', 'tenant_id', 'access_token', 'expires_at'], 'pwa_fcm_tokens' => ['id', 'tenant_id', 'morador_id', 'fcm_token', 'ativo'], 'pwa_notificacoes_push' => ['id', 'tenant_id'], 'pwa_notificacoes_recebidas' => ['id', 'tenant_id']], ['pwa_notificacoes_push', 'pwa_notificacoes_recebidas', 'pwa_oauth_cache']],
    ];
    if (!isset($required[$action])) return true;
    [$tables, $identities] = $required[$action];
    $portalSessionColumns = pwa_schema_sessao_portal_exigido($action);
    if ($portalSessionColumns !== null) $tables['sessoes_portal'] = $portalSessionColumns;
    if ($action === 'envio_automatico') $tables['moradores'] = ['id', 'tenant_id'];
    foreach ($tables as $table => $columns) {
        if (!\ERP\Database\DatabaseDialect::tableExists($conexao, $table)) return false;
        foreach ($columns as $column) {
            if (!\ERP\Database\DatabaseDialect::columnExists($conexao, $table, $column)) return false;
        }
    }
    foreach ($identities as $table) {
        $stmt = $conexao->prepare("SELECT is_identity, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'id'");
        if (!$stmt || !$stmt->bind_param('s', $table) || !$stmt->execute()) return false;
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta || (($meta['is_identity'] ?? 'NO') !== 'YES' && !preg_match('/^nextval\\(/i', (string)($meta['column_default'] ?? '')))) return false;
    }
    return true;
}

/**
 * Gera/reutiliza token OAuth2 para FCM HTTP v1.
 * Cacheia em pwa_oauth_cache por até 3600s.
 */
function _pwa_oauth2_token($conexao, int $tenant_id) {
    $stmt = $conexao->prepare('SELECT access_token, expires_at FROM pwa_oauth_cache WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        if (strtotime($row['expires_at']) - time() > 120) return $row['access_token'];
    }

    $sa_path = __DIR__ . '/../config/firebase/service-account.json';
    if (!file_exists($sa_path)) return null;
    $sa = json_decode(file_get_contents($sa_path), true);
    if (empty($sa['private_key']) || empty($sa['client_email'])) return null;

    $now  = time();
    $b64u = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $head   = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64u(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $input = $head . '.' . $claims;
    $pk = openssl_pkey_get_private($sa['private_key']);
    if (!$pk) return null;
    openssl_sign($input, $sig, $pk, OPENSSL_ALGO_SHA256);
    $jwt = $input . '.' . $b64u($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $token = $data['access_token'] ?? null;
    if (!$token) return null;

    $exp = date('Y-m-d H:i:s', $now + ($data['expires_in'] ?? 3600));
    $del = $conexao->prepare('DELETE FROM pwa_oauth_cache WHERE tenant_id = ?');
    $del->bind_param('i', $tenant_id); $del->execute(); $del->close();
    $s = $conexao->prepare('INSERT INTO pwa_oauth_cache (tenant_id, access_token, expires_at) VALUES (?, ?, ?)');
    $s->bind_param('iss', $tenant_id, $token, $exp); $s->execute();
    return $token;
}

/**
 * Envia push via FCM HTTP v1 (substitui Legacy API encerrada em jun/2024).
 * Requer: config/firebase/service-account.json + fcm_project_id no banco.
 */
function enviar_push_fcm($fcm_token, $titulo, $corpo, $dados, $project_id, $conexao_ou_key = null, ?int $tenant_id = null) {
    // Aceita conexão (novo padrão) ou ignora server_key (legado - não funciona mais)
    $conexao_ref = is_object($conexao_ou_key) ? $conexao_ou_key : $GLOBALS['conexao'] ?? null;
    if (!$conexao_ref) return ['sucesso' => false, 'erro' => 'conexao_ausente', 'invalido' => false];

    if (!$tenant_id) return ['sucesso' => false, 'erro' => 'tenant_ausente', 'invalido' => false];
    $access_token = _pwa_oauth2_token($conexao_ref, $tenant_id);
    if (!$access_token) return ['sucesso' => false, 'erro' => 'service_account_nao_configurado', 'invalido' => false];

    // project_id pode ser passado como $project_id ou lido do banco se vier como server_key (legado)
    if (empty($project_id) || strlen($project_id) < 5) {
        $stmt = $conexao_ref->prepare("SELECT valor FROM pwa_configuracoes WHERE tenant_id = ? AND chave = 'fcm_project_id' LIMIT 1");
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $project_id = $stmt->get_result()->fetch_assoc()['valor'] ?? '';
        $stmt->close();
    }
    if (empty($project_id)) return ['sucesso' => false, 'erro' => 'fcm_project_id_nao_configurado', 'invalido' => false];

    $dados_str = array_map('strval', array_filter($dados, fn($v) => $v !== null && $v !== ''));
    $payload = [
        'message' => [
            'token' => $fcm_token,
            'notification' => ['title' => $titulo, 'body' => $corpo],
            'data' => $dados_str,
            'webpush' => [
                'notification' => [
                    'icon'  => '/ico/icon-192x192.png',
                    'badge' => '/ico/icon-72x72.png',
                    'requireInteraction' => in_array($dados['tipo'] ?? '', ['inadimplencia','urgente']),
                    'vibrate' => [200, 100, 200],
                    'tag' => $dados['tag'] ?? ('notif-' . ($dados['tipo'] ?? 'geral') . '-' . time()),
                ],
                'fcm_options' => ['link' => $dados['url'] ?? '/frontend/portal_morador.html'],
            ],
            'android' => ['priority' => 'high'],
            'apns' => ['headers' => ['apns-priority' => '10']],
        ],
    ];

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['sucesso' => false, 'erro' => 'transport_failure', 'invalido' => false];
    $r = json_decode($resp, true);
    if ($code === 200 && isset($r['name'])) return ['sucesso' => true, 'message_id' => $r['name']];
    $status   = $r['error']['status'] ?? '';
    $invalido = in_array($status, ['UNREGISTERED','INVALID_ARGUMENT']);
    return [
        'sucesso' => false,
        'erro' => $invalido ? 'invalid_registration_token' : 'provider_rejected',
        'invalido' => $invalido,
    ];
}

// ============================================================
// ACTIONS DO PORTAL DO MORADOR (autenticação via token)
// ============================================================

if (\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
    try {
        $pwaReady = pwa_schema_pronto($conexao, (string)$action);
    } catch (Throwable $e) {
        $pwaReady = false;
    }
    if (!$pwaReady) {
        http_response_code(503);
        retornar_json(false, 'Schema PWA não provisionado integralmente; a operação está temporariamente indisponível.');
    }
}

// ── Registrar token FCM do dispositivo ──────────────────────
if ($action === 'registrar_token' && $metodo === 'POST') {
    $morador_id = autenticar_morador_pwa($conexao);
    if (!$morador_id) retornar_json(false, 'Não autorizado');
    $tenant_id = pwa_tenant_do_morador($conexao, $morador_id);
    if ($tenant_id <= 0) { http_response_code(503); retornar_json(false, 'Vínculo de condomínio não disponível.'); }

    $dados       = $_body_json;
    $fcm_token   = trim($dados['fcm_token']   ?? '');
    $device_info = substr(trim($dados['device_info'] ?? ''), 0, 512);
    $plataforma  = in_array($dados['plataforma'] ?? 'web', ['web','android','ios']) ? $dados['plataforma'] : 'web';

    if (empty($fcm_token)) retornar_json(false, 'Token FCM é obrigatório');

    $stmt = $conexao->prepare("SELECT id FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND fcm_token = ?");
    $stmt->bind_param("iis", $tenant_id, $morador_id, $fcm_token);
    $stmt->execute();
    $existente = $stmt->get_result()->fetch_assoc();

    if ($existente) {
        $stmt2 = $conexao->prepare("UPDATE pwa_fcm_tokens SET ativo = 1, device_info = ?, plataforma = ?, ultimo_uso = NOW() WHERE tenant_id = ? AND morador_id = ? AND id = ?");
        $stmt2->bind_param("ssiii", $device_info, $plataforma, $tenant_id, $morador_id, $existente['id']);
        $stmt2->execute();
        retornar_json(true, 'Token atualizado com sucesso', ['token_id' => $existente['id']]);
    } else {
        $stmt2 = $conexao->prepare("INSERT INTO pwa_fcm_tokens (tenant_id, morador_id, fcm_token, device_info, plataforma, ultimo_uso) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt2->bind_param("iisss", $tenant_id, $morador_id, $fcm_token, $device_info, $plataforma);
        $stmt2->execute();
        $token_id = $conexao->insert_id;
        retornar_json(true, 'Token registrado com sucesso', ['token_id' => $token_id]);
    }
}

// ── Remover token FCM (logout ou revogação) ─────────────────
if ($action === 'remover_token' && $metodo === 'POST') {
    $morador_id = autenticar_morador_pwa($conexao);
    if (!$morador_id) retornar_json(false, 'Não autorizado');
    $tenant_id = pwa_tenant_do_morador($conexao, $morador_id);
    if ($tenant_id <= 0) { http_response_code(503); retornar_json(false, 'Vínculo de condomínio não disponível.'); }

    $dados     = $_body_json;
    $fcm_token = trim($dados['fcm_token'] ?? '');

    if (empty($fcm_token)) {
        $stmt = $conexao->prepare("UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND morador_id = ?");
        $stmt->bind_param("ii", $tenant_id, $morador_id);
    } else {
        $stmt = $conexao->prepare("UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND morador_id = ? AND fcm_token = ?");
        $stmt->bind_param("iis", $tenant_id, $morador_id, $fcm_token);
    }
    $stmt->execute();
    retornar_json(true, 'Token removido com sucesso');
}

// ── Marcar notificação como lida ─────────────────────────────
if ($action === 'marcar_lida' && $metodo === 'POST') {
    $morador_id = autenticar_morador_pwa($conexao);
    if (!$morador_id) retornar_json(false, 'Não autorizado');
    $tenant_id = pwa_tenant_do_morador($conexao, $morador_id);
    if ($tenant_id <= 0) { http_response_code(503); retornar_json(false, 'Vínculo de condomínio não disponível.'); }

    $dados    = $_body_json;
    $notif_id = (int)($dados['notificacao_id'] ?? 0);
    if (!$notif_id) retornar_json(false, 'ID da notificação inválido');

    $stmt = $conexao->prepare("UPDATE pwa_notificacoes_recebidas SET lida = 1, lida_em = NOW() WHERE tenant_id = ? AND notificacao_id = ? AND morador_id = ?");
    $stmt->bind_param("iii", $tenant_id, $notif_id, $morador_id);
    $stmt->execute();
    retornar_json(true, 'Notificação marcada como lida');
}

// ── Listar notificações do morador ───────────────────────────
if ($action === 'listar_minhas' && $metodo === 'GET') {
    $morador_id = autenticar_morador_pwa($conexao);
    if (!$morador_id) retornar_json(false, 'Não autorizado');
    $tenant_id = pwa_tenant_do_morador($conexao, $morador_id);
    if ($tenant_id <= 0) { http_response_code(503); retornar_json(false, 'Vínculo de condomínio não disponível.'); }

    $paginacao = pwa_paginacao($_GET['pagina'] ?? 1, $_GET['limite'] ?? 20, 50);
    $limite = $paginacao['limite'];
    $offset = $paginacao['offset'];

    $stmt = $conexao->prepare("
        SELECT
            n.id, n.titulo, n.corpo, n.tipo, n.url_destino, n.criado_em,
            r.lida, r.lida_em
        FROM pwa_notificacoes_push n
        INNER JOIN pwa_notificacoes_recebidas r ON r.tenant_id = n.tenant_id AND r.notificacao_id = n.id AND r.morador_id = ?
        WHERE n.tenant_id = ? AND r.tenant_id = ? AND r.status_envio = 'sucesso'
        ORDER BY n.criado_em DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii", $morador_id, $tenant_id, $tenant_id, $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt2 = $conexao->prepare("SELECT COUNT(*) as total FROM pwa_notificacoes_recebidas WHERE tenant_id = ? AND morador_id = ? AND lida = 0 AND status_envio = 'sucesso'");
    $stmt2->bind_param("ii", $tenant_id, $morador_id);
    $stmt2->execute();
    $nao_lidas = (int)$stmt2->get_result()->fetch_assoc()['total'];

    retornar_json(true, 'OK', ['notificacoes' => $rows, 'nao_lidas' => $nao_lidas, 'pagina' => $paginacao['pagina']]);
}

// ============================================================
// ACTIONS DO ERP (autenticação via sessão do sistema interno)
// ============================================================

// ── Enviar notificação push ──────────────────────────────────
if ($action === 'enviar' && $metodo === 'POST') {
    $usuario = autenticar_erp($conexao);
    if (!$usuario) retornar_json(false, 'Não autorizado — faça login no ERP');
    verificarPermissao('gerente');
    $tenant_id = exigirTenantId();

    $dados = $_body_json;

    $titulo       = trim($dados['titulo']       ?? '');
    $corpo        = trim($dados['corpo']        ?? '');
    $tipo         = $dados['tipo']              ?? 'geral';
    $url_destino  = $dados['url_destino'] ?? '/frontend/portal_morador.html';
    $destinoPush = pwa_resolver_destinatario_push($dados);
    $dados_extras = $dados['dados_extras']      ?? [];

    if (empty($titulo) || empty($corpo)) retornar_json(false, 'Título e corpo são obrigatórios');

    if ($destinoPush === null) {
        http_response_code(400);
        retornar_json(false, 'Destinatário ou identificador inválido.', null, 400);
    }
    $destinatario = $destinoPush['destinatario'];
    $morador_id = $destinoPush['morador_id'];
    $unidade_id = $destinoPush['unidade_id'];
    if (!is_string($url_destino) || !pwa_url_destino_valida($url_destino)) {
        http_response_code(400);
        retornar_json(false, 'URL de destino inválida.', null, 400);
    }
    $dados_extras = is_array($dados_extras) ? array_diff_key($dados_extras, array_flip(['tipo', 'url', 'notificacao_id'])) : [];

    if ($morador_id) {
        $stMorador = $conexao->prepare('SELECT 1 FROM moradores WHERE id = ? AND tenant_id = ?');
        $stMorador->bind_param('ii', $morador_id, $tenant_id);
        $stMorador->execute();
        $moradorValido = $stMorador->get_result()->fetch_assoc() !== null;
        $stMorador->close();
        if (!$moradorValido) retornar_json(false, 'Morador não encontrado neste condomínio.', null, 404);
    }
    if ($unidade_id) {
        $stUnidade = $conexao->prepare('SELECT 1 FROM unidades WHERE id = ? AND tenant_id = ?');
        $stUnidade->bind_param('ii', $unidade_id, $tenant_id);
        $stUnidade->execute();
        $unidadeValida = $stUnidade->get_result()->fetch_assoc() !== null;
        $stUnidade->close();
        if (!$unidadeValida) retornar_json(false, 'Unidade não encontrada neste condomínio.', null, 404);
    }

    $config     = obter_config_fcm($conexao, $tenant_id);
    $project_id = $config['fcm_project_id'] ?? '';
    $sa_path    = __DIR__ . '/../config/firebase/service-account.json';
    if (empty($project_id) || !file_exists($sa_path)) {
        retornar_json(false, 'Firebase não configurado. Acesse Central PWA → Configuração → envie o Service Account.');
    }
    $GLOBALS['conexao'] = $conexao;

    // Buscar tokens dos destinatários
    if ($destinatario === 'morador' && $morador_id) {
        $sql_tokens = "SELECT t.id, t.morador_id, t.fcm_token FROM pwa_fcm_tokens t WHERE t.tenant_id = ? AND t.morador_id = ? AND t.ativo = 1";
        $stmt_tok = $conexao->prepare($sql_tokens);
        $stmt_tok->bind_param("ii", $tenant_id, $morador_id);
    } elseif ($destinatario === 'unidade' && $unidade_id) {
        $sql_tokens = "SELECT t.id, t.morador_id, t.fcm_token FROM pwa_fcm_tokens t INNER JOIN moradores m ON m.id = t.morador_id AND m.tenant_id = t.tenant_id WHERE t.tenant_id = ? AND m.tenant_id = ? AND m.unidade_id = ? AND t.ativo = 1";
        $stmt_tok = $conexao->prepare($sql_tokens);
        $stmt_tok->bind_param("iii", $tenant_id, $tenant_id, $unidade_id);
    } else {
        $sql_tokens = "SELECT t.id, t.morador_id, t.fcm_token FROM pwa_fcm_tokens t WHERE t.tenant_id = ? AND t.ativo = 1";
        $stmt_tok = $conexao->prepare($sql_tokens);
        $stmt_tok->bind_param('i', $tenant_id);
    }
    $stmt_tok->execute();
    $tokens = $stmt_tok->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($tokens)) retornar_json(false, 'Nenhum dispositivo registrado para os destinatários selecionados');

    // Registrar notificação
    $usuario_id   = $usuario['id']   ?? null;
    $usuario_nome = $usuario['nome'] ?? 'Sistema';
    $dados_json   = $dados_extras ? json_encode($dados_extras) : null;
    $total_tokens = count($tokens);

    $stmt_ins = $conexao->prepare("INSERT INTO pwa_notificacoes_push (tenant_id, titulo, corpo, tipo, url_destino, destinatario, morador_id, unidade_id, enviado_por, enviado_por_nome, total_tokens, status, dados_extras) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'enviando', ?)");
    $stmt_ins->bind_param("isssssiiisis", $tenant_id, $titulo, $corpo, $tipo, $url_destino, $destinatario, $morador_id, $unidade_id, $usuario_id, $usuario_nome, $total_tokens, $dados_json);
    $stmt_ins->execute();
    $notif_id = $conexao->insert_id;

    // Enviar para cada token
    $sucesso = 0; $falha = 0;
    $payload_dados = [
        'tipo'           => $tipo,
        'url'            => $url_destino,
        'notificacao_id' => (string)$notif_id,
        'timestamp'      => (string)time()
    ];
    if ($dados_extras) $payload_dados = array_merge($payload_dados, (array)$dados_extras);

    $stmt_rec = $conexao->prepare("INSERT INTO pwa_notificacoes_recebidas (tenant_id, notificacao_id, morador_id, fcm_token_id, status_envio, erro_fcm) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($tokens as $tok) {
        $resultado = enviar_push_fcm($tok['fcm_token'], $titulo, $corpo, $payload_dados, $project_id, $conexao, $tenant_id);

        if ($resultado['sucesso']) {
            $status_env = 'sucesso'; $erro_fcm = null; $sucesso++;
        } else {
            $status_env = $resultado['invalido'] ? 'invalido' : 'falha';
            $erro_fcm   = $resultado['erro'] ?? 'Erro desconhecido';
            $falha++;
            if ($resultado['invalido'] ?? false) {
                $stmt_desat = $conexao->prepare("UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?");
                $stmt_desat->bind_param("ii", $tenant_id, $tok['id']);
                $stmt_desat->execute();
            }
        }

        $stmt_rec->bind_param("iiiiss", $tenant_id, $notif_id, $tok['morador_id'], $tok['id'], $status_env, $erro_fcm);
        $stmt_rec->execute();
    }

    // Atualizar status da notificação
    $stmt_upd = $conexao->prepare("UPDATE pwa_notificacoes_push SET status = 'concluido', total_sucesso = ?, total_falha = ?, enviado_em = NOW() WHERE tenant_id = ? AND id = ?");
    $stmt_upd->bind_param("iiii", $sucesso, $falha, $tenant_id, $notif_id);
    $stmt_upd->execute();

    retornar_json(true, "Notificação enviada: {$sucesso} sucesso(s), {$falha} falha(s)", [
        'notificacao_id' => $notif_id,
        'total_tokens'   => $total_tokens,
        'enviadas'       => $sucesso,
        'falha'          => $falha
    ]);
}

// ── Listar notificações enviadas (ERP) ───────────────────────
if ($action === 'listar_enviadas' && $metodo === 'GET') {
    $usuario = autenticar_erp($conexao);
    if (!$usuario) retornar_json(false, 'Não autorizado');
    verificarPermissao('admin');
    $tenant_id = exigirTenantId();

    $paginacao = pwa_paginacao($_GET['pagina'] ?? 1, $_GET['limite'] ?? 20, 100);
    $limite = $paginacao['limite'];
    $offset = $paginacao['offset'];

    $stmt = $conexao->prepare('SELECT id, titulo, corpo, tipo, destinatario, total_tokens, total_sucesso, total_falha, status, enviado_por_nome, criado_em, enviado_em FROM pwa_notificacoes_push WHERE tenant_id = ? ORDER BY criado_em DESC LIMIT ? OFFSET ?');
    $stmt->bind_param('iii', $tenant_id, $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    retornar_json(true, 'OK', $rows);
}

// ── Obter configurações FCM (ERP) ────────────────────────────
if ($action === 'obter_config' && $metodo === 'GET') {
    $usuario = autenticar_erp($conexao);
    if (!$usuario) retornar_json(false, 'Não autorizado');
    verificarPermissao('admin');
    $tenant_id = exigirTenantId();
    retornar_json(true, 'OK', pwa_config_publica(obter_config_fcm($conexao, $tenant_id)));
}

// ── Salvar configurações FCM (ERP) ───────────────────────────
if ($action === 'salvar_config' && $metodo === 'POST') {
    $usuario = autenticar_erp($conexao);
    if (!$usuario) retornar_json(false, 'Não autorizado');
    verificarPermissao('admin');
    $tenant_id = exigirTenantId();

    $dados = $_body_json;
    $chaves_permitidas = [
        'fcm_project_id', 'fcm_api_key', 'fcm_auth_domain',
        'fcm_messaging_sender_id', 'fcm_app_id', 'fcm_vapid_key',
        'pwa_ativo', 'push_visitante_ativo', 'push_inadimplencia_ativo',
        'push_comunicado_ativo', 'push_os_ativo'
    ];

    $postgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
    if ($postgres) {
        if (!\ERP\Database\DatabaseDialect::tableExists($conexao, 'pwa_configuracoes')) {
            http_response_code(503);
            retornar_json(false, 'Schema PWA não provisionado; a configuração está temporariamente indisponível.');
        }
        if (!\ERP\Database\DatabaseDialect::columnExists($conexao, 'pwa_configuracoes', 'tenant_id')) {
            http_response_code(503);
            retornar_json(false, 'Schema PWA sem isolamento de condomínio; a configuração está temporariamente indisponível.');
        }
        $idMeta = $conexao->query("SELECT is_identity, column_default FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='pwa_configuracoes' AND column_name='id'");
        $idColumn = $idMeta ? $idMeta->fetch_assoc() : null;
        $idHasGenerator = $idColumn
            && (($idColumn['is_identity'] ?? 'NO') === 'YES'
                || preg_match('/^nextval\(/i', (string)($idColumn['column_default'] ?? '')) === 1);
        if (!$idHasGenerator) {
            http_response_code(503);
            retornar_json(false, 'Schema PWA não possui gerador de ID; a configuração está temporariamente indisponível.');
        }
    } else {
        // O modo MySQL mantém a criação legada da tabela quando necessário.
        $tabela_existe = $conexao->query("SHOW TABLES LIKE 'pwa_configuracoes'")->num_rows > 0;
        if (!$tabela_existe) {
            $conexao->query("CREATE TABLE IF NOT EXISTS pwa_configuracoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                chave VARCHAR(100) NOT NULL,
                valor TEXT,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pwa_config_tenant_chave (tenant_id, chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!\ERP\Database\DatabaseDialect::columnExists($conexao, 'pwa_configuracoes', 'tenant_id')) {
            http_response_code(503);
            retornar_json(false, 'Schema PWA sem isolamento de condomínio; a configuração está temporariamente indisponível.');
        }
    }

    if ($postgres && !$conexao->begin_transaction()) {
        http_response_code(503);
        retornar_json(false, 'Não foi possível iniciar a gravação da configuração PWA.');
    }
    $sql = $postgres
        ? 'INSERT INTO pwa_configuracoes (tenant_id, chave, valor) VALUES (?, ?, ?) ON CONFLICT (tenant_id, chave) DO UPDATE SET valor = EXCLUDED.valor, atualizado_em = NOW()'
        : 'INSERT INTO pwa_configuracoes (tenant_id, chave, valor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()';
    $stmt = $conexao->prepare($sql);
    if (!$stmt && $postgres) {
        if ($postgres) $conexao->rollback();
        http_response_code(503);
        retornar_json(false, 'Não foi possível preparar a gravação da configuração PWA.');
    }
    $salvos = 0;
    foreach ($dados as $chave => $valor) {
        if (in_array($chave, $chaves_permitidas)) {
            $valor_str = (string)$valor;
            $stmt->bind_param("iss", $tenant_id, $chave, $valor_str);
            if (!$stmt->execute() && $postgres) {
                $stmt->close();
                $conexao->rollback();
                http_response_code(503);
                retornar_json(false, 'Não foi possível persistir a configuração PWA.');
            }
            $salvos++;
        }
    }
    if ($stmt) $stmt->close();
    if ($postgres && !$conexao->commit()) {
        $conexao->rollback();
        http_response_code(503);
        retornar_json(false, 'Não foi possível confirmar a gravação da configuração PWA.');
    }
    retornar_json(true, "Configurações salvas com sucesso ({$salvos} chaves)");
}

// ── Estatísticas de tokens ───────────────────────────────────
if ($action === 'estatisticas' && $metodo === 'GET') {
    $usuario = autenticar_erp($conexao);
    if (!$usuario) retornar_json(false, 'Não autorizado');
    verificarPermissao('admin');
    $tenant_id = exigirTenantId();

    $tem_tokens = \ERP\Database\DatabaseDialect::tableExists($conexao, 'pwa_fcm_tokens');
    $tem_push   = \ERP\Database\DatabaseDialect::tableExists($conexao, 'pwa_notificacoes_push');

    $stTokens = $tem_tokens ? $conexao->prepare('SELECT COUNT(*) c, COUNT(DISTINCT morador_id) moradores FROM pwa_fcm_tokens WHERE tenant_id = ? AND ativo = 1') : null;
    if ($stTokens) { $stTokens->bind_param('i', $tenant_id); $stTokens->execute(); $tokenStats = $stTokens->get_result()->fetch_assoc() ?: []; $stTokens->close(); } else $tokenStats = [];
    $stPush = $tem_push ? $conexao->prepare('SELECT COUNT(*) total, SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE THEN 1 ELSE 0 END) hoje FROM pwa_notificacoes_push WHERE tenant_id = ?') : null;
    if ($stPush) { $stPush->bind_param('i', $tenant_id); $stPush->execute(); $pushStats = $stPush->get_result()->fetch_assoc() ?: []; $stPush->close(); } else $pushStats = [];
    $stats = [
        'tokens_ativos' => (int)($tokenStats['c'] ?? 0),
        'moradores_pwa' => (int)($tokenStats['moradores'] ?? 0),
        'notificacoes_enviadas' => (int)($pushStats['total'] ?? 0),
        'notificacoes_hoje' => (int)($pushStats['hoje'] ?? 0),
    ];
    retornar_json(true, 'OK', $stats);
}

// ── Envio automático por evento do sistema ──────────────────
if ($action === 'envio_automatico' && $metodo === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $chave_interna = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    $chave_esperada = defined('INTERNAL_API_KEY') ? (string)INTERNAL_API_KEY : (string)(getenv('INTERNAL_API_KEY') ?: '');
    $tenant_allowlist = preg_split('/\s*,\s*/', (string)(getenv('INTERNAL_API_TENANT_IDS') ?: ''), -1, PREG_SPLIT_NO_EMPTY);
    $tenant_allowlist = array_values(array_unique(array_filter(array_map(
        static fn($value) => ctype_digit((string)$value) ? (int)$value : 0,
        $tenant_allowlist
    ), static fn($value) => $value > 0)));

    if (strlen($chave_esperada) < 32 || !$tenant_allowlist) {
        http_response_code(503);
        retornar_json(false, 'Envio interno não está provisionado.');
    }
    if (!in_array($ip, ['127.0.0.1', '::1'], true) || $chave_interna === '' || !hash_equals($chave_esperada, $chave_interna)) {
        http_response_code(403);
        retornar_json(false, 'Acesso não autorizado');
    }

    $dados      = $_body_json;
    $tenant_id  = (int)($dados['tenant_id'] ?? 0);
    if ($tenant_id <= 0 || !in_array($tenant_id, $tenant_allowlist, true)) {
        http_response_code(403);
        retornar_json(false, 'Contexto de condomínio não autorizado.', null, 403);
    }
    $config     = obter_config_fcm($conexao, $tenant_id);
    $project_id = $config['fcm_project_id'] ?? '';
    $sa_path    = __DIR__ . '/../config/firebase/service-account.json';
    if (empty($project_id) || !file_exists($sa_path)) {
        retornar_json(false, 'Firebase não configurado — acesse Central PWA');
    }
    $GLOBALS['conexao'] = $conexao;

    $tipo         = $dados['tipo']             ?? 'geral';
    $titulo       = $dados['titulo']           ?? 'Portal do Morador';
    $corpo        = $dados['corpo']            ?? 'Você tem uma nova notificação.';
    $destinoPush  = pwa_resolver_destinatario_automatico($dados);
    if ($destinoPush === null) {
        http_response_code(400);
        retornar_json(false, 'Destinatário ausente ou inválido.', null, 400);
    }
    $morador_id   = $destinoPush['morador_id'];
    $url_destino  = $dados['url']              ?? '/frontend/portal_morador.html';
    $dados_extras = $dados['dados']            ?? [];
    if (!pwa_url_destino_valida((string)$url_destino)) {
        http_response_code(400);
        retornar_json(false, 'URL de destino inválida.', null, 400);
    }
    $dados_extras = is_array($dados_extras) ? array_diff_key($dados_extras, array_flip(['tipo', 'url', 'notificacao_id'])) : [];

    if ($morador_id) {
        $stMorador = $conexao->prepare('SELECT 1 FROM moradores WHERE id = ? AND tenant_id = ?');
        $stMorador->bind_param('ii', $morador_id, $tenant_id);
        $stMorador->execute();
        $moradorValido = $stMorador->get_result()->fetch_assoc() !== null;
        $stMorador->close();
        if (!$moradorValido) retornar_json(false, 'Morador não encontrado neste condomínio.', null, 404);
    }

    $config_key = "push_{$tipo}_ativo";
    if (isset($config[$config_key]) && $config[$config_key] === '0') {
        retornar_json(false, "Push do tipo '{$tipo}' está desativado nas configurações");
    }

    if ($morador_id) {
        $stmt_tok = $conexao->prepare("SELECT id, morador_id, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1");
        $stmt_tok->bind_param("ii", $tenant_id, $morador_id);
    } else {
        $stmt_tok = $conexao->prepare("SELECT id, morador_id, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND ativo = 1");
        $stmt_tok->bind_param('i', $tenant_id);
    }
    $stmt_tok->execute();
    $tokens = $stmt_tok->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($tokens)) retornar_json(false, 'Sem tokens para enviar');

    $total_tokens = count($tokens);
    $dest         = $morador_id ? 'morador' : 'todos';
    $dados_json   = !empty($dados_extras) ? json_encode($dados_extras) : null;

    $stmt_ins = $conexao->prepare("INSERT INTO pwa_notificacoes_push (tenant_id, titulo, corpo, tipo, url_destino, destinatario, morador_id, enviado_por_nome, total_tokens, status, dados_extras) VALUES (?, ?, ?, ?, ?, ?, ?, 'Sistema Automático', ?, 'enviando', ?)");
    $stmt_ins->bind_param("isssssiis", $tenant_id, $titulo, $corpo, $tipo, $url_destino, $dest, $morador_id, $total_tokens, $dados_json);
    $stmt_ins->execute();
    $notif_id = $conexao->insert_id;

    $sucesso = 0; $falha = 0;
    $payload_dados = array_merge(['tipo' => $tipo, 'url' => $url_destino, 'notificacao_id' => (string)$notif_id], $dados_extras);

    $stmt_rec = $conexao->prepare("INSERT INTO pwa_notificacoes_recebidas (tenant_id, notificacao_id, morador_id, fcm_token_id, status_envio, erro_fcm) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($tokens as $tok) {
        $res        = enviar_push_fcm($tok['fcm_token'], $titulo, $corpo, $payload_dados, $project_id, $conexao, $tenant_id);
        $status_env = $res['sucesso'] ? 'sucesso' : ($res['invalido'] ? 'invalido' : 'falha');
        $erro_fcm   = $res['sucesso'] ? null : ($res['erro'] ?? 'Erro');
        if ($res['sucesso']) $sucesso++; else $falha++;
        if ($res['invalido'] ?? false) {
            $stmt_desat = $conexao->prepare('UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?');
            $stmt_desat->bind_param('ii', $tenant_id, $tok['id']);
            $stmt_desat->execute();
            $stmt_desat->close();
        }
        $stmt_rec->bind_param("iiiiss", $tenant_id, $notif_id, $tok['morador_id'], $tok['id'], $status_env, $erro_fcm);
        $stmt_rec->execute();
    }

    $stmt_upd = $conexao->prepare("UPDATE pwa_notificacoes_push SET status = 'concluido', total_sucesso = ?, total_falha = ?, enviado_em = NOW() WHERE tenant_id = ? AND id = ?");
    $stmt_upd->bind_param("iiii", $sucesso, $falha, $tenant_id, $notif_id);
    $stmt_upd->execute();

    retornar_json(true, "Enviado: {$sucesso}/{$total_tokens}", ['notificacao_id' => $notif_id, 'sucesso' => $sucesso, 'falha' => $falha]);
}

retornar_json(false, 'Ação não encontrada: ' . $action);
