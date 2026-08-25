<?php
/**
 * Controle de versões e manifesto do agente Windows ERP Monitoring.
 * A administração exige sessão Super-Admin. O manifesto exige bearer do agente.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/monitoramento_helper.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function monitoring_update_json(bool $ok, string $message, $data = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['sucesso' => $ok, 'mensagem' => $message, 'dados' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function monitoring_update_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function monitoring_update_admin(): void {
    verificarAutenticacao(true, 'super_admin');
}
function monitoring_update_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}
function monitoring_update_log(mysqli $db, $releaseId, $agentId, $currentCode, string $action, string $detail = ''): void {
    $stmt = $db->prepare('INSERT INTO monitoring_update_log (release_id, agent_id, current_version_code, action, detail, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) return;
    $releaseId = $releaseId ? (int)$releaseId : null;
    $agentId = $agentId ? (int)$agentId : null;
    $currentCode = $currentCode !== null ? (int)$currentCode : null;
    $stmt->bind_param('iiisss', $releaseId, $agentId, $currentCode, $action, $detail, $GLOBALS['_monitoring_update_ip']);
    $stmt->execute();
    $stmt->close();
}

$db = conectar_banco();
$GLOBALS['_monitoring_update_ip'] = monitoring_update_ip();
$action = $_GET['action'] ?? $_GET['acao'] ?? 'manifest';
$input = monitoring_update_input();

try {
    if ($action === 'manifest') {
        if (function_exists('monitoring_agent_context')) {
            $agent = monitoring_agent_context($db, true);
            $agentId = (int)($agent['agente_id'] ?? 0);
        } else {
            monitoring_update_json(false, 'Autenticação do agente indisponível.', null, 500);
        }
        $channel = (string)($_GET['channel'] ?? 'producao');
        if (!in_array($channel, ['interno', 'teste', 'producao'], true)) $channel = 'producao';
        $currentCode = (int)($_GET['current_version_code'] ?? $input['current_version_code'] ?? 0);
        $stmt = $db->prepare("SELECT id, version_name, version_code, channel, download_url, sha256, size_bytes, mandatory, minimum_version_code, release_notes, published_at FROM monitoring_releases WHERE channel = ? AND status = 'publicado' ORDER BY version_code DESC LIMIT 1");
        $stmt->bind_param('s', $channel);
        $stmt->execute();
        $release = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$release) {
            monitoring_update_log($db, null, $agentId, $currentCode, 'MANIFEST_EMPTY', 'Nenhuma release publicada no canal ' . $channel);
            monitoring_update_json(true, 'Nenhuma atualização disponível.', ['update_available' => false, 'channel' => $channel]);
        }
        $availableCode = (int)$release['version_code'];
        $mandatory = (int)$release['mandatory'] === 1 && $currentCode < (int)$release['minimum_version_code'];
        $available = $availableCode > $currentCode;
        monitoring_update_log($db, (int)$release['id'], $agentId, $currentCode, 'MANIFEST_CHECK', $available ? 'Atualização disponível' : 'Agente atualizado');
        monitoring_update_json(true, 'Manifesto obtido.', [
            'update_available' => $available,
            'mandatory' => $mandatory,
            'current_version_code' => $currentCode,
            'release' => [
                'id' => (int)$release['id'],
                'version_name' => $release['version_name'],
                'version_code' => $availableCode,
                'channel' => $release['channel'],
                'download_url' => $release['download_url'],
                'sha256' => strtolower($release['sha256']),
                'size_bytes' => (int)$release['size_bytes'],
                'minimum_version_code' => (int)$release['minimum_version_code'],
                'release_notes' => $release['release_notes'],
                'published_at' => $release['published_at']
            ]
        ]);
    }

    monitoring_update_admin();
    if ($action === 'list') {
        $result = $db->query('SELECT * FROM monitoring_releases ORDER BY version_code DESC, id DESC');
        monitoring_update_json(true, 'Releases carregadas.', ['releases' => $result ? $result->fetch_all(MYSQLI_ASSOC) : []]);
    }
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') monitoring_update_json(false, 'Método inválido.', null, 405);
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['version_name'] ?? ''));
        $code = (int)($input['version_code'] ?? 0);
        $channel = (string)($input['channel'] ?? 'interno');
        $status = (string)($input['status'] ?? 'rascunho');
        $url = trim((string)($input['download_url'] ?? ''));
        $sha = strtolower(trim((string)($input['sha256'] ?? '')));
        $size = max(0, (int)($input['size_bytes'] ?? 0));
        $mandatory = !empty($input['mandatory']) ? 1 : 0;
        $minimum = max(0, (int)($input['minimum_version_code'] ?? 0));
        $notes = trim((string)($input['release_notes'] ?? ''));
        if ($name === '' || $code < 1 || !in_array($channel, ['interno','teste','producao'], true) || !in_array($status, ['rascunho','publicado','arquivado'], true)) monitoring_update_json(false, 'Versão, código, canal ou status inválido.', null, 422);
        if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0) monitoring_update_json(false, 'A URL da atualização deve usar HTTPS.', null, 422);
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) monitoring_update_json(false, 'SHA-256 inválido.', null, 422);
        $userId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE monitoring_releases SET version_name=?, version_code=?, channel=?, status=?, download_url=?, sha256=?, size_bytes=?, mandatory=?, minimum_version_code=?, release_notes=? WHERE id=?');
            $stmt->bind_param('sissssiiisi', $name, $code, $channel, $status, $url, $sha, $size, $mandatory, $minimum, $notes, $id);
        } else {
            $stmt = $db->prepare('INSERT INTO monitoring_releases (version_name, version_code, channel, status, download_url, sha256, size_bytes, mandatory, minimum_version_code, release_notes, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sissssiiisi', $name, $code, $channel, $status, $url, $sha, $size, $mandatory, $minimum, $notes, $userId);
        }
        if (!$stmt || !$stmt->execute()) monitoring_update_json(false, 'Não foi possível salvar a release.', null, 500);
        $savedId = $id ?: (int)$stmt->insert_id;
        $stmt->close();
        monitoring_update_json(true, 'Release salva.', ['id' => $savedId]);
    }
    if ($action === 'publish' || $action === 'archive') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') monitoring_update_json(false, 'Método inválido.', null, 405);
        $id = (int)($input['id'] ?? 0);
        if ($id < 1) monitoring_update_json(false, 'ID da release obrigatório.', null, 422);
        $status = $action === 'publish' ? 'publicado' : 'arquivado';
        $stmt = $db->prepare("UPDATE monitoring_releases SET status=?, published_at=IF(?='publicado', NOW(), published_at) WHERE id=?");
        $stmt->bind_param('ssi', $status, $status, $id);
        if (!$stmt->execute() || $stmt->affected_rows < 1) monitoring_update_json(false, 'Release não encontrada.', null, 404);
        $stmt->close();
        monitoring_update_log($db, $id, null, null, strtoupper($action), 'Ação executada pelo Super-Admin');
        monitoring_update_json(true, $action === 'publish' ? 'Release publicada.' : 'Release arquivada.');
    }
    monitoring_update_json(false, 'Ação inválida.', null, 400);
} catch (Throwable $e) {
    error_log('[api_monitoring_update] ' . $e->getMessage());
    monitoring_update_json(false, 'Não foi possível processar o versionamento do Monitoring.', null, 500);
} finally {
    fechar_conexao($db);
}
