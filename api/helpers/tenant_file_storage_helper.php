<?php
/**
 * Serviço compartilhado para arquivos de negócio Multi-Tenant.
 * Não grava arquivos em public_html/uploads; persiste o binário em tenant_arquivos.
 */

if (!function_exists('tenant_file_normalizar_caminho')) {
    function tenant_file_normalizar_caminho($path) {
        $path = ltrim(str_replace('\\', '/', (string)$path), '/');
        if (strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('Caminho lógico de arquivo inválido.');
        }
        return $path;
    }
}

if (!function_exists('tenant_file_gravar_upload')) {
    function tenant_file_gravar_upload(mysqli $db, int $tenantId, array $file, string $tipo, string $caminhoLegado, bool $publico = false, ?int $usuarioId = null) {
        if ($tenantId <= 0) throw new InvalidArgumentException('Tenant inválido para armazenamento de arquivo.');
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload temporário inválido.');
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no upload: código ' . (int)$file['error']);
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 25 * 1024 * 1024) throw new RuntimeException('Arquivo deve ter entre 1 byte e 25 MB.');
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        return tenant_file_gravar_conteudo($db, $tenantId, $content, (string)($file['name'] ?? 'arquivo'), $tipo, $caminhoLegado, $publico, $usuarioId);
    }
}

if (!function_exists('tenant_file_gravar_conteudo')) {
    function tenant_file_gravar_conteudo(mysqli $db, int $tenantId, string $content, string $nomeOriginal, string $tipo, string $caminhoLegado, bool $publico = false, ?int $usuarioId = null) {
        $caminhoLegado = tenant_file_normalizar_caminho($caminhoLegado);
        $nomeOriginal = preg_replace('/[\x00-\x1F\\\\\/:*?"<>|]+/', '_', basename($nomeOriginal));
        $nomeOriginal = trim($nomeOriginal, '. ') ?: 'arquivo';
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content) ?: 'application/octet-stream';
        $tamanho = strlen($content);
        $sha = hash('sha256', $content);
        $token = $publico ? bin2hex(random_bytes(24)) : null;
        $usuarioId = (int)($usuarioId ?? ($_SESSION['usuario_id'] ?? 0));

        $existe = $db->prepare('SELECT id FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? LIMIT 1');
        if (!$existe) throw new RuntimeException('Falha ao verificar arquivo existente: ' . $db->error);
        $existe->bind_param('is', $tenantId, $caminhoLegado);
        $existe->execute();
        $anterior = $existe->get_result()->fetch_assoc();
        $existe->close();

        if ($anterior) {
            $id = (int)$anterior['id'];
            $stmt = $db->prepare('UPDATE tenant_arquivos SET tipo=?, nome_original=?, extensao=?, mime_type=?, tamanho_bytes=?, sha256=?, conteudo=?, publico=?, token_publico=?, criado_por_usuario_id=?, ativo=1, atualizado_em=NOW() WHERE id=? AND tenant_id=?');
            if (!$stmt) throw new RuntimeException('Falha ao atualizar arquivo: ' . $db->error);
            $blob = null;
            $stmt->bind_param('ssssisbisiii', $tipo, $nomeOriginal, $extensao, $mime, $tamanho, $sha, $blob, $publico, $token, $usuarioId, $id, $tenantId);
            $stmt->send_long_data(6, $content);
            if (!$stmt->execute()) throw new RuntimeException('Falha ao atualizar arquivo: ' . $stmt->error);
            $stmt->close();
            error_log("[TenantArquivo][ATUALIZADO] tenant={$tenantId}; arquivo={$id}; tipo={$tipo}; caminho={$caminhoLegado}; bytes={$tamanho}");
            return ['id' => $id, 'caminho_legado' => $caminhoLegado, 'url' => '/api/api_arquivos_tenant.php?acao=conteudo&id=' . $id, 'token_publico' => $token];
        }

        $stmt = $db->prepare('INSERT INTO tenant_arquivos (tenant_id, tipo, nome_original, extensao, mime_type, tamanho_bytes, sha256, conteudo, publico, token_publico, caminho_legado, criado_por_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Falha ao preparar arquivo: ' . $db->error);
        $blob = null;
        $stmt->bind_param('issssisbissi', $tenantId, $tipo, $nomeOriginal, $extensao, $mime, $tamanho, $sha, $blob, $publico, $token, $caminhoLegado, $usuarioId);
        $stmt->send_long_data(7, $content);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao gravar arquivo: ' . $stmt->error);
        $id = (int)$db->insert_id;
        $stmt->close();
        error_log("[TenantArquivo][GRAVADO] tenant={$tenantId}; arquivo={$id}; tipo={$tipo}; caminho={$caminhoLegado}; bytes={$tamanho}");
        return ['id' => $id, 'caminho_legado' => $caminhoLegado, 'url' => '/api/api_arquivos_tenant.php?acao=conteudo&id=' . $id, 'token_publico' => $token];
    }
}

if (!function_exists('tenant_file_desativar_caminho')) {
    function tenant_file_desativar_caminho(mysqli $db, int $tenantId, string $caminhoLegado) {
        $caminhoLegado = tenant_file_normalizar_caminho($caminhoLegado);
        $stmt = $db->prepare('UPDATE tenant_arquivos SET ativo=0, atualizado_em=NOW() WHERE tenant_id=? AND caminho_legado=?');
        if (!$stmt) throw new RuntimeException('Falha ao desativar arquivo: ' . $db->error);
        $stmt->bind_param('is', $tenantId, $caminhoLegado);
        $stmt->execute();
        return $stmt->affected_rows;
    }
}
