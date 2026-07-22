<?php
/**
 * ============================================================
 * API Central PWA — ERP Condomínios
 * Versão: 2.0.0  |  Arquitetura: FCM HTTP v1 + OAuth2
 * ============================================================
 * Endpoints (acao via GET ou POST JSON):
 *   dashboard_status     — dados do painel em tempo real
 *   health_check         — diagnóstico completo do PWA
 *   obter_config         — lê pwa_configuracoes
 *   salvar_config        — grava pwa_configuracoes + registra log
 *   listar_dispositivos  — lista tokens FCM paginados com UA parseado
 *   desativar_dispositivo, ativar_dispositivo, excluir_dispositivo
 *   enviar_teste         — envia push de teste para um token específico
 *   listar_logs          — pwa_logs com filtros
 *   estatisticas         — breakdown plataforma/browser/OS
 *   versao_atual         — versão corrente do PWA
 *   atualizar_versao     — bump semver + invalida cache
 *   upload_service_account — salva service-account.json no servidor
 * ============================================================
 * Segurança: Apenas usuários do ERP autenticados por sessão.
 * Service Account JSON: armazenado em config/firebase/, nunca no banco.
 * ============================================================
 */

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$allowed = ['https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed)) header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();

$conn   = conectar_banco();
$metodo = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($metodo === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) $body = json_decode($raw, true) ?? [];
}

$acao = $_GET['acao'] ?? $_GET['action'] ?? $body['acao'] ?? $body['action'] ?? '';

if (!function_exists('_pwa_json')) {
    function _pwa_json($ok, $msg, $dados = null) {
        echo json_encode(['sucesso' => $ok, 'mensagem' => $msg, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// HELPERS
// ============================================================

// Executa query e retorna COUNT seguro — retorna 0 se tabela não existir
function _safe_count($conn, $sql) {
    $r = @$conn->query($sql);
    return ($r && $r !== true) ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
}

// Cria as tabelas PWA se não existirem (idempotente, executado em toda requisição)
function _pwa_garantir_tabelas($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS pwa_versao (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        versao        VARCHAR(20)  NOT NULL DEFAULT '1.0.0',
        cache_version VARCHAR(50)  NOT NULL DEFAULT 'portal-morador-v1',
        changelog     TEXT         NULL,
        tipo          ENUM('major','minor','patch','build') NOT NULL DEFAULT 'patch',
        publicado_por INT          NULL,
        publicado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ativo         TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS pwa_logs (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tipo        VARCHAR(60)  NOT NULL,
        nivel       ENUM('info','aviso','erro') NOT NULL DEFAULT 'info',
        morador_id  INT          NULL,
        token_id    INT UNSIGNED NULL,
        descricao   TEXT         NOT NULL,
        extras      JSON         NULL,
        ip          VARCHAR(45)  NULL,
        user_agent  VARCHAR(255) NULL,
        plataforma  VARCHAR(20)  NULL,
        criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tipo (tipo), KEY idx_nivel (nivel), KEY idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS pwa_oauth_cache (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        access_token TEXT        NOT NULL,
        token_type  VARCHAR(30)  NOT NULL DEFAULT 'Bearer',
        expires_at  DATETIME     NOT NULL,
        criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Adiciona colunas de device info ao pwa_fcm_tokens se não existirem
    $cols = [];
    $r = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pwa_fcm_tokens'");
    if ($r) while ($row = $r->fetch_assoc()) $cols[] = $row['COLUMN_NAME'];
    if (!in_array('device_model',   $cols)) @$conn->query("ALTER TABLE pwa_fcm_tokens ADD COLUMN device_model   VARCHAR(100) NULL");
    if (!in_array('device_os',      $cols)) @$conn->query("ALTER TABLE pwa_fcm_tokens ADD COLUMN device_os      VARCHAR(100) NULL");
    if (!in_array('device_browser', $cols)) @$conn->query("ALTER TABLE pwa_fcm_tokens ADD COLUMN device_browser VARCHAR(100) NULL");

    // Insere chaves default de configuração PWA se não existirem
    $defaults = [
        'pwa_ativo'               => '1',
        'pwa_versao'              => '1.0.0',
        'pwa_cache_version'       => 'portal-morador-v1.0.0',
        'pwa_nome_app'            => 'Portal do Morador',
        'pwa_tema_cor'            => '#2563eb',
        'pwa_install_url'         => '/frontend/portal_morador.html',
        'pwa_modo_manutencao'     => '0',
        'push_visitante_ativo'    => '1',
        'push_inadimplencia_ativo'=> '1',
        'push_comunicado_ativo'   => '1',
        'push_os_ativo'           => '1',
        'push_urgente_ativo'      => '1',
        'fcm_api_key'             => '',
        'fcm_auth_domain'         => '',
        'fcm_project_id'          => '',
        'fcm_storage_bucket'      => '',
        'fcm_messaging_sender_id' => '',
        'fcm_app_id'              => '',
        'fcm_vapid_key'           => '',
        'fcm_service_account_path'  => '',
        'fcm_service_account_email' => '',
    ];
    $stmt_d = @$conn->prepare("INSERT IGNORE INTO pwa_configuracoes (chave, valor) VALUES (?, ?)");
    if ($stmt_d) {
        foreach ($defaults as $k => $v) {
            $stmt_d->bind_param('ss', $k, $v);
            $stmt_d->execute();
        }
    }
}

function _pwa_config($conn) {
    $res = @$conn->query("SELECT chave, valor FROM pwa_configuracoes");
    $cfg = [];
    if ($res) while ($r = $res->fetch_assoc()) $cfg[$r['chave']] = $r['valor'];
    return $cfg;
}

function _sa_path() {
    // Caminho do service-account.json — sempre relativo à raiz do projeto PHP
    // Nunca dentro de uma pasta pública sem .htaccess de bloqueio
    return __DIR__ . '/../config/firebase/service-account.json';
}

function _sa_existe() {
    $path = _sa_path();
    return file_exists($path) && filesize($path) > 100;
}

function _sa_ler() {
    if (!_sa_existe()) return null;
    $json = file_get_contents(_sa_path());
    return json_decode($json, true);
}

/** Gera/reutiliza token OAuth2 para FCM HTTP v1 */
function _oauth2_token($conn) {
    // Verificar cache no banco
    $res = $conn->query("SELECT access_token, expires_at FROM pwa_oauth_cache ORDER BY id DESC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $expira = strtotime($row['expires_at']);
        if ($expira - time() > 120) { // ainda válido com 2min de folga
            return $row['access_token'];
        }
    }

    $sa = _sa_ler();
    if (!$sa) return null;

    // Construir JWT para Google OAuth2
    $now  = time();
    $head = rtrim(strtr(base64_encode(json_encode(['alg'=>'RS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $claims = rtrim(strtr(base64_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ])), '+/', '-_'), '=');

    $input = $head . '.' . $claims;

    $pk = openssl_pkey_get_private($sa['private_key']);
    if (!$pk) return null;

    openssl_sign($input, $sig, $pk, OPENSSL_ALGO_SHA256);
    $jwt = $input . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    // Trocar JWT por access_token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $http_cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_cod !== 200) return null;

    $data  = json_decode($resp, true);
    $token = $data['access_token'] ?? null;
    $exp   = $data['expires_in']   ?? 3600;
    if (!$token) return null;

    // Salvar no cache
    $expires_dt = date('Y-m-d H:i:s', $now + $exp);
    $conn->query("DELETE FROM pwa_oauth_cache");
    $stmt = $conn->prepare("INSERT INTO pwa_oauth_cache (access_token, expires_at) VALUES (?, ?)");
    $stmt->bind_param('ss', $token, $expires_dt);
    $stmt->execute();

    return $token;
}

/** Envia push via FCM HTTP v1 */
function _enviar_push_v1($conn, $fcm_token, $titulo, $corpo, $dados, $project_id) {
    $access_token = _oauth2_token($conn);
    if (!$access_token) {
        return ['sucesso' => false, 'erro' => 'service_account_invalido', 'invalido' => false];
    }

    $dados_str = array_map('strval', array_filter($dados, fn($v) => $v !== null));

    $payload = [
        'message' => [
            'token'        => $fcm_token,
            'notification' => ['title' => $titulo, 'body' => $corpo],
            'data'         => $dados_str,
            'webpush'      => [
                'notification' => [
                    'icon'                => '/ico/icon-192x192.png',
                    'badge'               => '/ico/icon-72x72.png',
                    'requireInteraction'  => in_array($dados['tipo'] ?? '', ['inadimplencia','urgente']),
                    'vibrate'             => [200, 100, 200],
                    'tag'                 => $dados['tag'] ?? ('notif-' . ($dados['tipo'] ?? 'geral') . '-' . time()),
                ],
                'fcm_options' => ['link' => $dados['url'] ?? '/frontend/portal_morador.html'],
            ],
            'android' => ['priority' => 'high'],
            'apns'    => ['headers' => ['apns-priority' => '10']],
        ],
    ];

    $url = "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $http_cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['sucesso' => false, 'erro' => 'curl: ' . $curl_err, 'invalido' => false];

    $r = json_decode($resp, true);
    if ($http_cod === 200 && isset($r['name'])) {
        return ['sucesso' => true, 'message_id' => $r['name']];
    }

    $status   = $r['error']['status']  ?? '';
    $msg      = $r['error']['message'] ?? 'Erro desconhecido';
    $invalido = in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT']);
    return ['sucesso' => false, 'erro' => $msg, 'invalido' => $invalido, 'status' => $status];
}

/** Parse básico de User Agent */
function _parse_ua($ua) {
    if (empty($ua)) return ['os' => 'Desconhecido', 'browser' => 'Desconhecido', 'model' => ''];

    $os = 'Desconhecido';
    $browser = 'Desconhecido';
    $model = '';

    if (preg_match('/Android ([0-9.]+)/', $ua, $m)) {
        $os = 'Android ' . $m[1];
        if (preg_match('/\(Linux; Android [^;]+; ([^)]+)\)/', $ua, $mm)) {
            $model = trim($mm[1]);
        }
    } elseif (preg_match('/iPhone OS ([0-9_]+)/', $ua, $m)) {
        $os = 'iOS ' . str_replace('_', '.', $m[1]);
        $model = 'iPhone';
    } elseif (preg_match('/iPad.*OS ([0-9_]+)/', $ua, $m)) {
        $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
        $model = 'iPad';
    } elseif (preg_match('/Windows NT ([0-9.]+)/', $ua, $m)) {
        $map = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $os = 'Windows ' . ($map[$m[1]] ?? $m[1]);
    } elseif (preg_match('/Mac OS X ([0-9_]+)/', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    if (preg_match('/Edg\/([0-9.]+)/', $ua, $m)) {
        $browser = 'Edge ' . explode('.', $m[1])[0];
    } elseif (preg_match('/OPR\/([0-9.]+)/', $ua, $m)) {
        $browser = 'Opera ' . explode('.', $m[1])[0];
    } elseif (preg_match('/Firefox\/([0-9.]+)/', $ua, $m)) {
        $browser = 'Firefox ' . explode('.', $m[1])[0];
    } elseif (preg_match('/SamsungBrowser\/([0-9.]+)/', $ua, $m)) {
        $browser = 'Samsung Browser ' . explode('.', $m[1])[0];
    } elseif (preg_match('/Chrome\/([0-9.]+)/', $ua, $m)) {
        $browser = 'Chrome ' . explode('.', $m[1])[0];
    } elseif (preg_match('/Safari\/([0-9.]+)/', $ua, $m) && strpos($ua, 'Mobile') !== false) {
        $browser = 'Safari Mobile';
    } elseif (strpos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    }

    return compact('os', 'browser', 'model');
}

/** Registra evento no pwa_logs */
function _pwa_log($conn, $tipo, $nivel, $descricao, $extras = [], $morador_id = null, $token_id = null) {
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $plat      = null;
    $extras_j  = !empty($extras) ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null;
    $stmt = @$conn->prepare(
        "INSERT INTO pwa_logs (tipo, nivel, morador_id, token_id, descricao, extras, ip, user_agent, plataforma)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param('ssiisssss', $tipo, $nivel, $morador_id, $token_id, $descricao, $extras_j, $ip, $ua, $plat);
        $stmt->execute();
    }
}

// ============================================================
// ACTIONS
// ============================================================

// Garante que as tabelas existam antes de qualquer operação
_pwa_garantir_tabelas($conn);

switch ($acao) {

    // ─── DASHBOARD STATUS ─────────────────────────────────────
    case 'dashboard_status':
        $cfg        = _pwa_config($conn);
        $sa_existe  = _sa_existe();
        $firebase_ok = !empty($cfg['fcm_api_key']) && $cfg['fcm_api_key'] !== 'SUBSTITUA_PELO_SEU_API_KEY';
        $pwa_ativo  = ($cfg['pwa_ativo'] ?? '1') === '1';

        // Contadores — usa _safe_count para resistir a tabelas ausentes
        $tokens_ativos = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_fcm_tokens WHERE ativo=1");
        $moradores_pwa = _safe_count($conn, "SELECT COUNT(DISTINCT morador_id) c FROM pwa_fcm_tokens WHERE ativo=1");
        $push_hoje     = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_notificacoes_push WHERE DATE(criado_em)=CURDATE()");
        $push_total    = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_notificacoes_push");
        $logs_erros    = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_logs WHERE nivel='erro' AND DATE(criado_em)=CURDATE()");

        // Versão — safe
        $versao_row = null;
        $r_versao = @$conn->query("SELECT versao, publicado_em FROM pwa_versao WHERE ativo=1 ORDER BY id DESC LIMIT 1");
        if ($r_versao) $versao_row = $r_versao->fetch_assoc();

        // OAuth cache — safe
        $oauth_row    = null;
        $r_oauth = @$conn->query("SELECT expires_at FROM pwa_oauth_cache ORDER BY id DESC LIMIT 1");
        if ($r_oauth) $oauth_row = $r_oauth->fetch_assoc();
        $oauth_valido = $oauth_row && strtotime($oauth_row['expires_at']) > time() + 120;

        _pwa_json(true, 'Status carregado', [
            'pwa_ativo'         => $pwa_ativo,
            'firebase_ok'       => $firebase_ok,
            'service_account'   => $sa_existe,
            'oauth_valido'      => $oauth_valido,
            'oauth_expira'      => $oauth_row['expires_at'] ?? null,
            'tokens_ativos'     => $tokens_ativos,
            'moradores_pwa'     => $moradores_pwa,
            'push_hoje'         => $push_hoje,
            'push_total'        => $push_total,
            'logs_erros_hoje'   => $logs_erros,
            'versao'            => $versao_row['versao']      ?? ($cfg['pwa_versao'] ?? '1.0.0'),
            'versao_data'       => $versao_row['publicado_em'] ?? null,
            'cache_version'     => $cfg['pwa_cache_version']  ?? 'portal-morador-v1.0.0',
            'nome_app'          => $cfg['pwa_nome_app']        ?? 'Portal do Morador',
            'tema_cor'          => $cfg['pwa_tema_cor']        ?? '#2563eb',
            'install_url'       => $cfg['pwa_install_url']     ?? '/frontend/portal_morador.html',
        ]);

    // ─── HEALTH CHECK ─────────────────────────────────────────
    case 'health_check':
        $cfg = _pwa_config($conn);
        $checks = [];

        // 1. Manifest
        $manifest_path = __DIR__ . '/../portal-morador-manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            $checks[] = ['item' => 'Manifest JSON', 'status' => $manifest ? 'ok' : 'erro',
                'detalhe' => $manifest ? 'Válido e parseável' : 'JSON inválido'];
            if ($manifest) {
                $req_icons = ['192x192','512x512'];
                $icon_sizes = array_column($manifest['icons'] ?? [], 'sizes');
                foreach ($req_icons as $sz) {
                    $checks[] = ['item' => "Ícone {$sz}", 'status' => in_array($sz, $icon_sizes) ? 'ok' : 'atencao',
                        'detalhe' => in_array($sz, $icon_sizes) ? 'Declarado no manifest' : 'Ausente no manifest'];
                }
                // Verificar ícones 180/32/16 fisicamente
                $ico_fisicos = ['icon-180x180.png','icon-120x120.png','icon-32x32.png','icon-16x16.png'];
                foreach ($ico_fisicos as $ico) {
                    $exists = file_exists(__DIR__ . '/../ico/' . $ico);
                    $checks[] = ['item' => "Ícone físico /{$ico}", 'status' => $exists ? 'ok' : 'atencao',
                        'detalhe' => $exists ? 'Arquivo presente' : 'Arquivo ausente — iOS/favicon prejudicados'];
                }
            }
        } else {
            $checks[] = ['item' => 'Manifest JSON', 'status' => 'erro', 'detalhe' => 'Arquivo não encontrado'];
        }

        // 2. Service Worker
        $sw_path = __DIR__ . '/../firebase-messaging-sw.js';
        $checks[] = ['item' => 'Service Worker (arquivo)', 'status' => file_exists($sw_path) ? 'ok' : 'erro',
            'detalhe' => file_exists($sw_path) ? 'firebase-messaging-sw.js presente' : 'Arquivo ausente'];

        // 3. Firebase config
        $fcm_campos = ['fcm_api_key','fcm_auth_domain','fcm_project_id','fcm_messaging_sender_id','fcm_app_id'];
        $fcm_ok = true;
        foreach ($fcm_campos as $campo) {
            $val = $cfg[$campo] ?? '';
            $preenchido = !empty($val) && strpos($val, 'SUBSTITUA') === false;
            if (!$preenchido) { $fcm_ok = false; break; }
        }
        $checks[] = ['item' => 'Firebase Config (banco)', 'status' => $fcm_ok ? 'ok' : 'erro',
            'detalhe' => $fcm_ok ? 'Todos os campos preenchidos' : 'Campos vazios ou com placeholder — configure na aba Firebase'];

        // 4. VAPID Key
        $vapid = $cfg['fcm_vapid_key'] ?? '';
        $vapid_ok = !empty($vapid) && strpos($vapid, 'SUBSTITUA') === false && strlen($vapid) > 20;
        $checks[] = ['item' => 'VAPID Key', 'status' => $vapid_ok ? 'ok' : 'erro',
            'detalhe' => $vapid_ok ? 'Configurada (' . strlen($vapid) . ' chars)' : 'Ausente ou inválida'];

        // 5. Service Account
        $sa_existe = _sa_existe();
        $checks[] = ['item' => 'Service Account JSON', 'status' => $sa_existe ? 'ok' : 'erro',
            'detalhe' => $sa_existe ? 'config/firebase/service-account.json presente' : 'Arquivo ausente — push notifications não funcionam'];
        if ($sa_existe) {
            $sa = _sa_ler();
            $sa_valido = !empty($sa['client_email']) && !empty($sa['private_key']) && !empty($sa['project_id']);
            $checks[] = ['item' => 'Service Account (estrutura)', 'status' => $sa_valido ? 'ok' : 'erro',
                'detalhe' => $sa_valido ? 'client_email: ' . ($sa['client_email'] ?? '?') : 'JSON inválido ou campos ausentes'];
        }

        // 6. OAuth2 Token Cache
        $oauth_row = null;
        $r_oc = @$conn->query("SELECT expires_at FROM pwa_oauth_cache ORDER BY id DESC LIMIT 1");
        if ($r_oc) $oauth_row = $r_oc->fetch_assoc();
        if ($oauth_row) {
            $restante = strtotime($oauth_row['expires_at']) - time();
            $checks[] = ['item' => 'OAuth2 Token Cache', 'status' => $restante > 0 ? 'ok' : 'atencao',
                'detalhe' => $restante > 0 ? "Válido por mais {$restante}s" : 'Expirado — será renovado no próximo envio'];
        } else {
            $checks[] = ['item' => 'OAuth2 Token Cache', 'status' => 'atencao',
                'detalhe' => 'Nenhum token em cache — será gerado no primeiro envio'];
        }

        // 7. Banco de dados — tabelas
        $tabelas = ['pwa_fcm_tokens','pwa_notificacoes_push','pwa_notificacoes_recebidas','pwa_configuracoes','pwa_versao','pwa_logs','pwa_oauth_cache'];
        foreach ($tabelas as $tb) {
            $existe = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tb'")->num_rows > 0;
            $checks[] = ['item' => "Tabela {$tb}", 'status' => $existe ? 'ok' : 'erro',
                'detalhe' => $existe ? 'Existe no banco' : 'Ausente — execute migration_pwa_v2.sql'];
        }

        // 8. HTTPS (via host)
        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $checks[] = ['item' => 'HTTPS', 'status' => $https ? 'ok' : 'atencao',
            'detalhe' => $https ? 'Conexão segura ativa' : 'HTTP detectado — Service Workers exigem HTTPS em produção'];

        // 9. PWA feature flags
        $pwa_ativo = ($cfg['pwa_ativo'] ?? '1') === '1';
        $checks[] = ['item' => 'PWA Ativo', 'status' => $pwa_ativo ? 'ok' : 'atencao',
            'detalhe' => $pwa_ativo ? 'Portal ativo' : 'Portal em modo manutenção'];

        // Resumo
        $erros   = count(array_filter($checks, fn($c) => $c['status'] === 'erro'));
        $avisos  = count(array_filter($checks, fn($c) => $c['status'] === 'atencao'));
        $status_geral = $erros > 0 ? 'erro' : ($avisos > 0 ? 'atencao' : 'ok');

        _pwa_log($conn, 'health_check', 'info', "Health check: {$erros} erros, {$avisos} avisos", compact('erros','avisos'));
        _pwa_json(true, 'Diagnóstico concluído', [
            'checks'        => $checks,
            'total'         => count($checks),
            'erros'         => $erros,
            'avisos'        => $avisos,
            'status_geral'  => $status_geral,
            'executado_em'  => date('Y-m-d H:i:s'),
        ]);

    // ─── OBTER CONFIG ─────────────────────────────────────────
    case 'obter_config':
        $cfg = _pwa_config($conn);
        // Ocultar API Key parcialmente por segurança
        if (!empty($cfg['fcm_api_key']) && strlen($cfg['fcm_api_key']) > 8) {
            $cfg['fcm_api_key_preview'] = substr($cfg['fcm_api_key'], 0, 6) . '****' . substr($cfg['fcm_api_key'], -4);
        }
        // Metadados do Service Account
        $cfg['service_account_existe'] = _sa_existe();
        if (_sa_existe()) {
            $sa = _sa_ler();
            $cfg['service_account_email']   = $sa['client_email']  ?? '';
            $cfg['service_account_project'] = $sa['project_id']    ?? '';
        }
        _pwa_json(true, 'Configurações carregadas', $cfg);

    // ─── SALVAR CONFIG ────────────────────────────────────────
    case 'salvar_config':
        verificarPermissao('gerente');
        $campos_permitidos = [
            'fcm_api_key','fcm_auth_domain','fcm_project_id','fcm_storage_bucket',
            'fcm_messaging_sender_id','fcm_app_id','fcm_vapid_key',
            'pwa_nome_app','pwa_tema_cor','pwa_install_url','pwa_ativo',
            'push_visitante_ativo','push_inadimplencia_ativo','push_comunicado_ativo',
            'push_os_ativo','push_urgente_ativo','pwa_modo_manutencao',
        ];
        $salvos = 0;
        $stmt = $conn->prepare("INSERT INTO pwa_configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        foreach ($campos_permitidos as $campo) {
            if (isset($body[$campo])) {
                $val = trim($body[$campo]);
                $stmt->bind_param('ss', $campo, $val);
                $stmt->execute();
                $salvos++;
            }
        }
        // Invalidar OAuth cache quando config Firebase muda
        if (array_intersect(array_keys($body), ['fcm_project_id','fcm_messaging_sender_id','fcm_app_id'])) {
            $conn->query("DELETE FROM pwa_oauth_cache");
        }
        _pwa_log($conn, 'config_salva', 'info', "Configuração PWA atualizada ({$salvos} campos)");
        _pwa_json(true, "{$salvos} configurações salvas com sucesso");

    // ─── LISTAR DISPOSITIVOS ──────────────────────────────────
    case 'listar_dispositivos':
        $limite  = max(1, min(100, (int)($_GET['limite'] ?? 25)));
        $offset  = max(0, (int)($_GET['offset'] ?? 0));
        $status  = $_GET['status'] ?? '';
        $plat    = $_GET['plataforma'] ?? '';
        $busca   = trim($_GET['busca'] ?? '');

        $where = ['1=1'];
        $params = [];
        $types  = '';
        if ($status === 'ativo')    { $where[] = 't.ativo = 1'; }
        elseif ($status === 'inativo') { $where[] = 't.ativo = 0'; }
        if ($plat) { $where[] = 't.plataforma = ?'; $params[] = $plat; $types .= 's'; }
        if ($busca) {
            $where[] = '(m.nome LIKE ? OR m.unidade_numero LIKE ? OR t.device_browser LIKE ? OR t.device_os LIKE ?)';
            $b = "%{$busca}%";
            array_push($params, $b, $b, $b, $b);
            $types .= 'ssss';
        }

        $w = implode(' AND ', $where);
        $total_res = $conn->query("SELECT COUNT(*) c FROM pwa_fcm_tokens t LEFT JOIN moradores m ON t.morador_id = m.id WHERE {$w}");
        $total = (int)($total_res ? $total_res->fetch_assoc()['c'] : 0);

        $sql = "SELECT t.id, t.morador_id, t.plataforma, t.ativo,
                       t.device_info, t.device_model, t.device_os, t.device_browser,
                       t.criado_em, t.atualizado_em, t.ultimo_uso,
                       LEFT(t.fcm_token, 20) AS token_preview,
                       m.nome AS morador_nome, m.unidade_numero
                FROM pwa_fcm_tokens t
                LEFT JOIN moradores m ON t.morador_id = m.id
                WHERE {$w}
                ORDER BY t.ultimo_uso DESC, t.criado_em DESC
                LIMIT ? OFFSET ?";
        $params[] = $limite;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Parse UA e enriquecimento
        foreach ($rows as &$row) {
            if (empty($row['device_os']) && !empty($row['device_info'])) {
                $ua = _parse_ua($row['device_info']);
                $row['device_os']      = $ua['os'];
                $row['device_browser'] = $ua['browser'];
                if (empty($row['device_model'])) $row['device_model'] = $ua['model'];
            }
        }
        unset($row);

        _pwa_json(true, 'Dispositivos carregados', ['items' => $rows, 'total' => $total, 'offset' => $offset, 'limite' => $limite]);

    // ─── ATIVAR / DESATIVAR / EXCLUIR DISPOSITIVO ────────────
    case 'desativar_dispositivo':
    case 'ativar_dispositivo':
    case 'excluir_dispositivo':
        verificarPermissao('gerente');
        $id = (int)($body['id'] ?? 0);
        if (!$id) _pwa_json(false, 'ID inválido');

        if ($acao === 'excluir_dispositivo') {
            $stmt = $conn->prepare("DELETE FROM pwa_fcm_tokens WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            _pwa_log($conn, 'token_removido', 'info', "Token #{$id} excluído pelo admin");
            _pwa_json(true, 'Dispositivo excluído');
        }

        $novo_ativo = ($acao === 'ativar_dispositivo') ? 1 : 0;
        $stmt = $conn->prepare("UPDATE pwa_fcm_tokens SET ativo = ? WHERE id = ?");
        $stmt->bind_param('ii', $novo_ativo, $id);
        $stmt->execute();
        $msg_log = $novo_ativo ? "Token #{$id} reativado" : "Token #{$id} desativado pelo admin";
        _pwa_log($conn, $novo_ativo ? 'token_registrado' : 'token_removido', 'info', $msg_log);
        _pwa_json(true, $novo_ativo ? 'Dispositivo reativado' : 'Dispositivo desativado');

    // ─── ENVIAR TESTE ─────────────────────────────────────────
    case 'enviar_teste':
        verificarPermissao('gerente');
        $token_id = (int)($body['token_id'] ?? 0);
        if (!$token_id) _pwa_json(false, 'token_id obrigatório');

        $r_tok = $conn->query("SELECT t.fcm_token, t.morador_id, m.nome FROM pwa_fcm_tokens t LEFT JOIN moradores m ON t.morador_id=m.id WHERE t.id={$token_id} AND t.ativo=1");
        $row = $r_tok ? $r_tok->fetch_assoc() : null;
        if (!$row) _pwa_json(false, 'Token não encontrado ou inativo');

        $cfg = _pwa_config($conn);
        $project_id = $cfg['fcm_project_id'] ?? '';
        if (empty($project_id)) _pwa_json(false, 'fcm_project_id não configurado');

        $resultado = _enviar_push_v1($conn, $row['fcm_token'],
            '🔔 Teste de Notificação',
            'Esta é uma notificação de teste enviada pela Central PWA do ERP.',
            ['tipo' => 'geral', 'url' => '/frontend/portal_morador.html', 'tag' => 'teste-' . time()],
            $project_id
        );

        _pwa_log($conn, $resultado['sucesso'] ? 'push_enviado' : 'push_erro',
            $resultado['sucesso'] ? 'info' : 'erro',
            "Teste para token #{$token_id} (" . ($row['nome'] ?? 'morador') . "): " . ($resultado['sucesso'] ? 'OK' : $resultado['erro']),
            $resultado, $row['morador_id'], $token_id
        );
        _pwa_json($resultado['sucesso'], $resultado['sucesso'] ? 'Push de teste enviado com sucesso' : 'Falha ao enviar: ' . $resultado['erro'], $resultado);

    // ─── LISTAR LOGS ──────────────────────────────────────────
    case 'listar_logs':
        $limite  = max(1, min(100, (int)($_GET['limite'] ?? 50)));
        $offset  = max(0, (int)($_GET['offset'] ?? 0));
        $tipo    = $_GET['tipo']   ?? '';
        $nivel   = $_GET['nivel']  ?? '';
        $busca   = trim($_GET['busca'] ?? '');
        $data_de = $_GET['data_de'] ?? '';
        $data_ate= $_GET['data_ate'] ?? '';

        $where = ['1=1'];
        $params = [];
        $types  = '';
        if ($tipo)    { $where[] = 'l.tipo = ?';    $params[] = $tipo;  $types .= 's'; }
        if ($nivel)   { $where[] = 'l.nivel = ?';   $params[] = $nivel; $types .= 's'; }
        if ($busca)   { $where[] = 'l.descricao LIKE ?'; $params[] = "%{$busca}%"; $types .= 's'; }
        if ($data_de) { $where[] = 'DATE(l.criado_em) >= ?'; $params[] = $data_de;  $types .= 's'; }
        if ($data_ate){ $where[] = 'DATE(l.criado_em) <= ?'; $params[] = $data_ate; $types .= 's'; }

        $w = implode(' AND ', $where);
        $total = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_logs l WHERE {$w}");

        $sql = "SELECT l.*, m.nome AS morador_nome FROM pwa_logs l LEFT JOIN moradores m ON l.morador_id=m.id WHERE {$w} ORDER BY l.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limite; $params[] = $offset; $types .= 'ii';
        $stmt = $conn->prepare($sql);
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        _pwa_json(true, 'Logs carregados', ['items' => $logs, 'total' => $total, 'offset' => $offset]);

    // ─── ESTATÍSTICAS ─────────────────────────────────────────
    case 'estatisticas':
        $por_plataforma = [];
        $res = $conn->query("SELECT plataforma, COUNT(*) total FROM pwa_fcm_tokens WHERE ativo=1 GROUP BY plataforma");
        if ($res) while ($r = $res->fetch_assoc()) $por_plataforma[$r['plataforma']] = (int)$r['total'];

        // Breakdown browser via device_browser
        $por_browser = [];
        $res = $conn->query("SELECT COALESCE(NULLIF(TRIM(device_browser),''), 'Desconhecido') AS browser, COUNT(*) total FROM pwa_fcm_tokens WHERE ativo=1 GROUP BY browser ORDER BY total DESC LIMIT 10");
        if ($res) while ($r = $res->fetch_assoc()) $por_browser[] = $r;

        // Breakdown OS
        $por_os = [];
        $res = $conn->query("SELECT COALESCE(NULLIF(TRIM(device_os),''), 'Desconhecido') AS os, COUNT(*) total FROM pwa_fcm_tokens WHERE ativo=1 GROUP BY os ORDER BY total DESC LIMIT 10");
        if ($res) while ($r = $res->fetch_assoc()) $por_os[] = $r;

        // Novos dispositivos (últimos 30 dias)
        $novos_30d = _safe_count($conn, "SELECT COUNT(*) c FROM pwa_fcm_tokens WHERE criado_em >= DATE_SUB(NOW(),INTERVAL 30 DAY)");

        // Últimos acessos
        $ultimos = [];
        $res = $conn->query("SELECT t.device_browser, t.device_os, t.plataforma, t.ultimo_uso, m.nome, m.unidade_numero FROM pwa_fcm_tokens t LEFT JOIN moradores m ON t.morador_id=m.id WHERE t.ativo=1 AND t.ultimo_uso IS NOT NULL ORDER BY t.ultimo_uso DESC LIMIT 10");
        if ($res) while ($r = $res->fetch_assoc()) $ultimos[] = $r;

        // Timeline de instalações (últimos 30 dias)
        $timeline = [];
        $res = $conn->query("SELECT DATE(criado_em) AS dia, COUNT(*) total FROM pwa_fcm_tokens WHERE criado_em >= DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY dia ORDER BY dia");
        if ($res) while ($r = $res->fetch_assoc()) $timeline[] = $r;

        _pwa_json(true, 'Estatísticas carregadas', compact('por_plataforma','por_browser','por_os','novos_30d','ultimos','timeline'));

    // ─── VERSÃO ATUAL ─────────────────────────────────────────
    case 'versao_atual':
        $row = null;
        $r_v = @$conn->query("SELECT * FROM pwa_versao WHERE ativo=1 ORDER BY id DESC LIMIT 1");
        if ($r_v) $row = $r_v->fetch_assoc();
        $historico = [];
        $r_h = @$conn->query("SELECT versao, tipo, changelog, cache_version, publicado_em FROM pwa_versao ORDER BY id DESC LIMIT 10");
        if ($r_h) while ($r = $r_h->fetch_assoc()) $historico[] = $r;
        _pwa_json(true, 'Versão carregada', ['atual' => $row, 'historico' => $historico]);

    // ─── ATUALIZAR VERSÃO ─────────────────────────────────────
    case 'atualizar_versao':
        verificarPermissao('gerente');
        $tipo_bump  = in_array($body['tipo'] ?? '', ['major','minor','patch','build']) ? $body['tipo'] : 'patch';
        $changelog  = trim($body['changelog'] ?? '');
        $usuario_id = $_SESSION['usuario_id'] ?? null;

        // Ler versão atual
        $atual = null;
        $r_at = @$conn->query("SELECT versao FROM pwa_versao WHERE ativo=1 ORDER BY id DESC LIMIT 1");
        if ($r_at) $atual = $r_at->fetch_assoc();
        $v_str = $atual['versao'] ?? '1.0.0';
        [$maj, $min, $pat] = array_map('intval', explode('.', $v_str . '.0.0'));

        switch ($tipo_bump) {
            case 'major': $maj++; $min = 0; $pat = 0; break;
            case 'minor': $min++; $pat = 0; break;
            default:      $pat++; break;
        }
        $nova_versao       = "{$maj}.{$min}.{$pat}";
        $nova_cache        = "portal-morador-v{$nova_versao}";

        $stmt = $conn->prepare("INSERT INTO pwa_versao (versao, cache_version, changelog, tipo, publicado_por) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssi', $nova_versao, $nova_cache, $changelog, $tipo_bump, $usuario_id);
        $stmt->execute();

        // Atualizar pwa_configuracoes
        $conn->query("UPDATE pwa_configuracoes SET valor='{$nova_versao}', atualizado_em=NOW() WHERE chave='pwa_versao'");
        $conn->query("UPDATE pwa_configuracoes SET valor='{$nova_cache}', atualizado_em=NOW() WHERE chave='pwa_cache_version'");

        // Invalidar OAuth cache para forçar novo token
        $conn->query("DELETE FROM pwa_oauth_cache");

        _pwa_log($conn, 'cache_atualizado', 'info', "Versão atualizada {$v_str} → {$nova_versao} ({$tipo_bump})");
        _pwa_json(true, "Versão atualizada para {$nova_versao}", [
            'versao_anterior' => $v_str,
            'nova_versao'     => $nova_versao,
            'cache_version'   => $nova_cache,
        ]);

    // ─── UPLOAD SERVICE ACCOUNT ───────────────────────────────
    case 'upload_service_account':
        verificarPermissao('gerente');
        $json_str = $body['service_account_json'] ?? '';
        if (empty($json_str)) _pwa_json(false, 'JSON do Service Account não fornecido');

        $sa = json_decode($json_str, true);
        if (!$sa) _pwa_json(false, 'JSON inválido');

        $campos_req = ['type','project_id','private_key','client_email'];
        foreach ($campos_req as $c) {
            if (empty($sa[$c])) _pwa_json(false, "Campo obrigatório ausente: {$c}");
        }
        if ($sa['type'] !== 'service_account') _pwa_json(false, 'type deve ser "service_account"');

        // Salvar arquivo
        $path = _sa_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        file_put_contents($path, json_encode($sa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($path, 0640);

        // Salvar metadados no banco (jamais a private_key)
        $email   = $sa['client_email'];
        $proj_id = $sa['project_id'];
        $conn->query("UPDATE pwa_configuracoes SET valor='{$conn->real_escape_string($email)}', atualizado_em=NOW() WHERE chave='fcm_service_account_email'");
        $conn->query("UPDATE pwa_configuracoes SET valor='{$conn->real_escape_string($proj_id)}', atualizado_em=NOW() WHERE chave='fcm_project_id'");

        // Invalidar cache de OAuth
        $conn->query("DELETE FROM pwa_oauth_cache");

        _pwa_log($conn, 'config_salva', 'info', "Service Account atualizado — projeto: {$proj_id}, email: {$email}");
        _pwa_json(true, 'Service Account salvo com sucesso', ['project_id' => $proj_id, 'client_email' => $email]);

    default:
        _pwa_json(false, "Ação '{$acao}' não reconhecida");
}

fechar_conexao($conn);
