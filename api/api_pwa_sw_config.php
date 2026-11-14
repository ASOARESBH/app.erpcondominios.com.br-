<?php
/**
 * ============================================================
 * API PWA SW Config — Configuração dinâmica para o Service Worker
 * ============================================================
 * Retorna um arquivo JavaScript com a configuração do Firebase
 * lida do banco de dados. Eliminando completamente credenciais
 * hardcoded nos arquivos JS/SW.
 *
 * Chamado por:
 *   - firebase-messaging-sw.js via importScripts('/api/api_pwa_sw_config.php')
 *   - pwa-portal.js via fetch('/api/api_pwa_sw_config.php?format=json')
 *   - portal_morador.html via <script> tag (define window.PWA_CONFIG)
 * ============================================================
 */

// Sem autenticação — expõe apenas config pública do Firebase (client-side safe)
// A chave privada do Service Account NUNCA é exposta aqui.

ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

// ── Cabeçalhos ────────────────────────────────────────────────
$format = $_GET['format'] ?? 'js';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Access-Control-Allow-Origin: *');

// ── Ler config do banco ───────────────────────────────────────
function _ler_pwa_config() {
    try {
        $conn = conectar_banco();
        $result = $conn->query("SELECT chave, valor FROM pwa_configuracoes WHERE chave IN (
            'fcm_api_key','fcm_auth_domain','fcm_project_id','fcm_storage_bucket',
            'fcm_messaging_sender_id','fcm_app_id','fcm_vapid_key',
            'pwa_versao','pwa_cache_version','pwa_nome_app','pwa_tema_cor',
            'pwa_install_url','pwa_ativo'
        )");
        $config = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $config[$row['chave']] = $row['valor'];
            }
        }
        fechar_conexao($conn);
        return $config;
    } catch (Exception $e) {
        return [];
    }
}

$cfg = _ler_pwa_config();

$firebase_config = [
    'apiKey'            => $cfg['fcm_api_key']            ?? '',
    'authDomain'        => $cfg['fcm_auth_domain']         ?? '',
    'projectId'         => $cfg['fcm_project_id']          ?? '',
    'storageBucket'     => $cfg['fcm_storage_bucket']      ?? '',
    'messagingSenderId' => $cfg['fcm_messaging_sender_id'] ?? '',
    'appId'             => $cfg['fcm_app_id']              ?? '',
];

$pwa_versao        = $cfg['pwa_versao']        ?? '1.0.0';
$pwa_cache_version = $cfg['pwa_cache_version'] ?? 'portal-morador-v1.0.0';
$pwa_vapid_key     = $cfg['fcm_vapid_key']     ?? '';
$pwa_nome_app      = $cfg['pwa_nome_app']      ?? 'Portal do Morador';
$pwa_tema_cor      = $cfg['pwa_tema_cor']      ?? '#2563eb';
$pwa_ativo         = isset($cfg['pwa_ativo']) && $cfg['pwa_ativo'] === '1';

function _pwa_config_value_ready($value) {
    if (!is_string($value) || trim($value) === '') return false;
    return !preg_match('/(?:^|[^A-Z0-9])(?:SUBSTITUA|PLACEHOLDER|YOUR|CHANGE[_ -]?ME|EXEMPLO|SEU|INFORME)(?:$|[^A-Z0-9])/i', $value);
}

$api_key_valida = (bool)preg_match('/^AIza[0-9A-Za-z_-]{35}$/D', (string)$firebase_config['apiKey']);
$auth_domain_valido = (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/iD', (string)$firebase_config['authDomain']);
$project_id_valido = (bool)preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/D', (string)$firebase_config['projectId']);
$sender_id_valido = (bool)preg_match('/^[0-9]{6,20}$/D', (string)$firebase_config['messagingSenderId']);
$app_id_valido = (bool)preg_match('/^1:[0-9]{6,20}:web:[A-Za-z0-9_-]+$/D', (string)$firebase_config['appId']);
$vapid_key_valida = (bool)preg_match('/^[A-Za-z0-9_-]{40,128}={0,2}$/D', (string)$pwa_vapid_key);
$firebase_configurado = $pwa_ativo
    && _pwa_config_value_ready($firebase_config['apiKey'])
    && _pwa_config_value_ready($firebase_config['authDomain'])
    && _pwa_config_value_ready($firebase_config['projectId'])
    && _pwa_config_value_ready($firebase_config['messagingSenderId'])
    && _pwa_config_value_ready($firebase_config['appId'])
    && _pwa_config_value_ready($pwa_vapid_key)
    && $api_key_valida
    && $auth_domain_valido
    && $project_id_valido
    && $sender_id_valido
    && $app_id_valido
    && $vapid_key_valida;

// ── Formatos de saída ────────────────────────────────────────

if ($format === 'json') {
    // Usado pelo pwa-portal.js via fetch()
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sucesso'               => true,
        'firebase'              => $firebase_config,
        'vapidKey'              => $pwa_vapid_key,
        'versao'                => $pwa_versao,
        'cacheVersion'          => $pwa_cache_version,
        'nomeApp'               => $pwa_nome_app,
        'temaCor'               => $pwa_tema_cor,
        'firebaseConfigurado'   => $firebase_configurado,
        'pwaAtivo'              => $pwa_ativo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Formato padrão: JavaScript para importScripts() no Service Worker
// ou <script src="..."> no portal_morador.html
header('Content-Type: application/javascript; charset=utf-8');

echo '/* PWA Config — gerado dinamicamente em ' . date('Y-m-d H:i:s') . ' */' . "\n";
echo '/* Versão: ' . htmlspecialchars($pwa_versao) . ' | Cache: ' . htmlspecialchars($pwa_cache_version) . ' */' . "\n\n";

// Detecta contexto: Service Worker (self) ou página (window)
echo "(function(ctx) {\n";
echo "    ctx.PWA_CONFIG = " . json_encode([
    'firebase'            => $firebase_config,
    'vapidKey'            => $pwa_vapid_key,
    'versao'              => $pwa_versao,
    'cacheVersion'        => $pwa_cache_version,
    'nomeApp'             => $pwa_nome_app,
    'temaCor'             => $pwa_tema_cor,
    'firebaseConfigurado' => $firebase_configurado,
    'pwaAtivo'            => $pwa_ativo,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ";\n";
echo "})(typeof self !== 'undefined' ? self : window);\n";
