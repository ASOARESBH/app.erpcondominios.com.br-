<?php
/**
 * API central de arquivos Multi-Tenant.
 * Arquivos de negócio são armazenados no banco tenant_arquivos, nunca em public_html/uploads.
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
ob_end_clean();

$_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_origin) || preg_match('/^https?:\/\/localhost(:\d+)?$/', $_origin)) {
    header('Access-Control-Allow-Origin: ' . $_origin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function tf_json($ok, $message, $data = null, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $out = ['sucesso' => (bool)$ok, 'mensagem' => $message];
    if ($data !== null) $out['dados'] = $data;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function tf_safe_filename($name) {
    $name = preg_replace('/[\x00-\x1F\\\\\/:*?"<>|]+/', '_', (string)$name);
    $name = trim($name, '. ');
    return $name !== '' ? substr($name, 0, 220) : 'arquivo';
}

function tf_type_from_path($path) {
    $path = strtolower($path);
    if (strpos($path, 'uploads/contratos/') === 0) return ['contrato_anexo', 0];
    if (strpos($path, 'uploads/crm_anexos/') === 0) return ['crm_anexo', 0];
    if (strpos($path, 'uploads/documentos/') === 0) return ['documento', 0];
    if (strpos($path, 'uploads/logo/') === 0) return ['logo_tenant', 0];
    if (strpos($path, 'uploads/notificacoes/') === 0) return ['notificacao_anexo', 0];
    if (strpos($path, 'uploads/moradores_anexos/') === 0) return ['morador_anexo', 0];
    if (strpos($path, 'uploads/leituras_fotos/') === 0) return ['leitura_foto', 0];
    if (strpos($path, 'uploads/visitantes/') === 0) return ['visitante_anexo', 0];
    if (strpos($path, 'uploads/rh_fotos/') === 0) return ['rh_foto', 0];
    if (strpos($path, 'uploads/assembleias/') === 0) return ['assembleia_anexo', 0];
    if (strpos($path, 'uploads/projetos_capas/') === 0 || strpos($path, 'uploads/projetos_fotos/') === 0) return ['projeto_imagem', 1];
    return ['anexo_geral', 0];
}

function tf_insert_file($db, $tenantId, $type, $originalName, $mime, $content, $public, $legacyPath = null) {
    $originalName = tf_safe_filename($originalName);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $bytes = strlen($content);
    $sha = hash('sha256', $content);
    $token = $public ? bin2hex(random_bytes(24)) : null;
    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    $stmt = $db->prepare('INSERT INTO tenant_arquivos (tenant_id, tipo, nome_original, extensao, mime_type, tamanho_bytes, sha256, conteudo, publico, token_publico, caminho_legado, criado_por_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Falha ao preparar arquivo: ' . $db->error);
    $blob = null;
    $stmt->bind_param('issssisbissi', $tenantId, $type, $originalName, $extension, $mime, $bytes, $sha, $blob, $public, $token, $legacyPath, $userId);
    $stmt->send_long_data(7, $content);
    if (!$stmt->execute()) throw new Exception('Falha ao gravar arquivo: ' . $stmt->error);
    $id = (int)$db->insert_id;
    $stmt->close();
    return ['id' => $id, 'token' => $token, 'sha256' => $sha, 'bytes' => $bytes];
}

function tf_stream($row, $download) {
    $name = tf_safe_filename($row['nome_original']);
    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (int)$row['tamanho_bytes']);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . addcslashes($name, '"\\') . '"');
    echo $row['conteudo'];
    exit;
}

function tf_is_superadmin() {
    $permission = $_SESSION['usuario_permissao'] ?? $_SESSION['permissao'] ?? '';
    return $permission === 'super_admin';
}

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$db = conectar_banco();

try {
    // Conteúdo público só pode ser acessado por token aleatório e flag público = 1.
    if ($acao === 'publico') {
        $token = preg_replace('/[^a-f0-9]/i', '', $_GET['token'] ?? '');
        if (strlen($token) !== 48) tf_json(false, 'Token público inválido', null, 400);
        $stmt = $db->prepare('SELECT nome_original, mime_type, tamanho_bytes, conteudo FROM tenant_arquivos WHERE token_publico = ? AND publico = 1 AND ativo = 1 LIMIT 1');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) tf_json(false, 'Arquivo não encontrado', null, 404);
        tf_stream($row, false);
    }

    // Compatibilidade temporária: URLs históricas /uploads/... são atendidas pelo BLOB.
    // Com sessão, a busca é sempre restrita ao tenant da sessão; sem sessão, somente arquivo marcado como público.
    if ($acao === 'legado') {
        $legacy = ltrim(str_replace('\\', '/', $_GET['caminho'] ?? ''), '/');
        if (strpos($legacy, 'uploads/') !== 0 || strpos($legacy, '..') !== false || strpos($legacy, "\0") !== false) tf_json(false, 'Caminho legado inválido', null, 400);
        // obterTenantId() inicia a sessão PHP quando necessário. Não leia
        // $_SESSION diretamente nesta rota: ela é executada antes do bloco
        // autenticado abaixo e, sem session_start(), toda logo privada cai
        // incorretamente no fluxo de arquivo público e retorna 404.
        $sessionTenant = (int)(obterTenantId() ?? 0);
        if ($sessionTenant > 0) {
            $stmt = $db->prepare('SELECT nome_original, mime_type, tamanho_bytes, conteudo FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? AND ativo = 1 LIMIT 1');
            $stmt->bind_param('is', $sessionTenant, $legacy);
        } else {
            $stmt = $db->prepare('SELECT nome_original, mime_type, tamanho_bytes, conteudo FROM tenant_arquivos WHERE caminho_legado = ? AND publico = 1 AND ativo = 1 LIMIT 1');
            $stmt->bind_param('s', $legacy);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) tf_json(false, 'Arquivo legado não encontrado ou sem permissão', null, 404);
        tf_stream($row, false);
    }

    verificarAutenticacao(true, 'operador');
    $tenantId = (int)exigirTenantId();

    if ($acao === 'conteudo') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) tf_json(false, 'Identificador de arquivo inválido', null, 400);
        $stmt = $db->prepare('SELECT nome_original, mime_type, tamanho_bytes, conteudo FROM tenant_arquivos WHERE id = ? AND tenant_id = ? AND ativo = 1 LIMIT 1');
        $stmt->bind_param('ii', $id, $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) tf_json(false, 'Arquivo não encontrado ou sem permissão', null, 404);
        tf_stream($row, isset($_GET['download']) && $_GET['download'] === '1');
    }

    if ($acao === 'listar') {
        $type = trim($_GET['tipo'] ?? '');
        $sql = 'SELECT id, tipo, nome_original, extensao, mime_type, tamanho_bytes, publico, token_publico, caminho_legado, criado_em FROM tenant_arquivos WHERE tenant_id = ? AND ativo = 1';
        if ($type !== '') $sql .= ' AND tipo = ?';
        $sql .= ' ORDER BY criado_em DESC';
        $stmt = $db->prepare($sql);
        if ($type !== '') $stmt->bind_param('is', $tenantId, $type); else $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $row['url'] = '/api/api_arquivos_tenant.php?acao=conteudo&id=' . (int)$row['id'];
            $row['url_publica'] = $row['publico'] ? '/api/api_arquivos_tenant.php?acao=publico&token=' . $row['token_publico'] : null;
        }
        tf_json(true, 'Arquivos listados', $rows);
    }

    if ($acao === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') tf_json(false, 'Método inválido', null, 405);
        if (!isset($_FILES['arquivo']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) tf_json(false, 'Arquivo obrigatório', null, 400);
        $file = $_FILES['arquivo'];
        if ((int)$file['error'] !== UPLOAD_ERR_OK) tf_json(false, 'Falha no upload: código ' . (int)$file['error'], null, 400);
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 25 * 1024 * 1024) tf_json(false, 'Arquivo deve ter entre 1 byte e 25 MB', null, 400);
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) throw new Exception('Não foi possível ler o upload temporário');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content) ?: 'application/octet-stream';
        $pathHint = trim($_POST['caminho_legado'] ?? '');
        list($inferredType, $inferredPublic) = tf_type_from_path($pathHint);
        $type = preg_replace('/[^a-z0-9_\-]/i', '', $_POST['tipo'] ?? $inferredType);
        if ($type === '') $type = $inferredType;
        $public = !empty($_POST['publico']) && tf_is_superadmin() ? 1 : $inferredPublic;
        $stored = tf_insert_file($db, $tenantId, $type, $file['name'], $mime, $content, $public, $pathHint ?: null);
        error_log("[ArquivosTenant][UPLOAD] tenant={$tenantId}; arquivo={$stored['id']}; tipo={$type}; bytes={$stored['bytes']}");
        tf_json(true, 'Arquivo armazenado no banco', [
            'id' => $stored['id'],
            'url' => '/api/api_arquivos_tenant.php?acao=conteudo&id=' . $stored['id'],
            'url_publica' => $stored['token'] ? '/api/api_arquivos_tenant.php?acao=publico&token=' . $stored['token'] : null,
        ]);
    }

    if ($acao === 'importar_zip_legado') {
        if (!tf_is_superadmin()) tf_json(false, 'Apenas Super Admin pode importar o pacote legado', null, 403);
        if (!isset($_FILES['arquivo_zip']) || !is_uploaded_file($_FILES['arquivo_zip']['tmp_name'])) tf_json(false, 'Arquivo ZIP obrigatório', null, 400);
        $zip = new ZipArchive();
        if ($zip->open($_FILES['arquivo_zip']['tmp_name']) !== true) tf_json(false, 'ZIP inválido', null, 400);
        if ($zip->numFiles > 10000) tf_json(false, 'ZIP excede o limite de 10.000 arquivos', null, 400);
        $summary = ['importados' => 0, 'ignorados' => 0, 'erros' => 0];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $path = str_replace('\\', '/', $entry['name']);
            if (substr($path, -1) === '/' || strpos($path, '..') !== false || strpos($path, "\0") !== false) continue;
            $path = ltrim($path, '/');
            if (strpos($path, 'uploads/') !== 0 || basename($path) === '.htaccess') { $summary['ignorados']++; continue; }
            if ((int)$entry['size'] <= 0 || (int)$entry['size'] > 25 * 1024 * 1024) { $summary['erros']++; continue; }
            $check = $db->prepare('SELECT id FROM tenant_arquivos_migracao_log WHERE tenant_id = ? AND caminho_legado = ? LIMIT 1');
            $check->bind_param('is', $tenantId, $path);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) { $summary['ignorados']++; continue; }
            $content = $zip->getFromIndex($i);
            if ($content === false) { $summary['erros']++; continue; }
            try {
                list($type, $public) = tf_type_from_path($path);
                $stored = tf_insert_file($db, $tenantId, $type, basename($path), $finfo->buffer($content) ?: 'application/octet-stream', $content, $public, $path);
                $log = $db->prepare("INSERT INTO tenant_arquivos_migracao_log (tenant_id, caminho_legado, arquivo_id, status, mensagem) VALUES (?, ?, ?, 'importado', 'Importado do ZIP legado')");
                $log->bind_param('isi', $tenantId, $path, $stored['id']);
                $log->execute();
                $summary['importados']++;
            } catch (Throwable $e) {
                $message = substr($e->getMessage(), 0, 900);
                $log = $db->prepare("INSERT INTO tenant_arquivos_migracao_log (tenant_id, caminho_legado, status, mensagem) VALUES (?, ?, 'erro', ?)");
                $log->bind_param('iss', $tenantId, $path, $message);
                $log->execute();
                $summary['erros']++;
            }
        }
        $zip->close();
        error_log("[ArquivosTenant][IMPORT_ZIP] tenant={$tenantId}; importados={$summary['importados']}; ignorados={$summary['ignorados']}; erros={$summary['erros']}");
        tf_json(true, 'Importação concluída', $summary);
    }

    tf_json(false, 'Ação inválida', null, 400);
} catch (Throwable $e) {
    error_log('[ArquivosTenant][ERRO] ' . $e->getMessage());
    tf_json(false, 'Falha no armazenamento de arquivos. Consulte o log técnico.', null, 500);
}
