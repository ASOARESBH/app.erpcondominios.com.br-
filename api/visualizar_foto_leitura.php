<?php
// =====================================================
// VISUALIZADOR DE FOTOS DE LEITURA (evidência fotográfica)
// =====================================================
// GET ?id=N  → transmite a imagem inline (para uso em <img src="...">)
//
// Autorização:
//   - Usuário/operador do ERP Administrativo (sessão admin): vê qualquer foto.
//   - Morador do Portal: só pode ver fotos de hidrômetros da SUA unidade
//     (morador_id resolvido via token Bearer — chamadas fetch — ou sessão
//     PHP legada — navegação direta / <img>). Caso contrário: 403 Forbidden.
//
// Reaproveita o mesmo mecanismo de resolução de morador_id já usado em
// api/api_morador_hidrometro.php (token em sessoes_portal + fallback de sessão).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

$conexao = conectar_banco();

$foto_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($foto_id <= 0) {
    http_response_code(400);
    echo 'ID da foto inválido.';
    exit;
}

$stmt = $conexao->prepare(
    "SELECT f.caminho, f.tipo_mime, f.nome_original, h.morador_id
     FROM leituras_fotos f
     INNER JOIN hidrometros h ON h.id = f.hidrometro_id
     WHERE f.id = ?"
);
$stmt->bind_param('i', $foto_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo 'Foto não encontrada.';
    exit;
}
$foto = $res->fetch_assoc();
$stmt->close();

// ── Resolver morador_id a partir do token Bearer ou da sessão PHP ──────────
function _lf_resolver_morador_id($conexao) {
    $auth = '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $auth = $h['Authorization'] ?? ($h['authorization'] ?? '');
    }
    if (empty($auth)) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($auth)) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (preg_match('/Bearer\s+(.+)$/i', $auth, $m)) {
        $token = trim($m[1]);
        $stmt = $conexao->prepare(
            "SELECT morador_id FROM sessoes_portal
             WHERE token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $morador_id = (int) $res->fetch_assoc()['morador_id'];
            $stmt->close();
            return $morador_id;
        }
        $stmt->close();
    }

    if (isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
        $id = (int) ($_SESSION['morador_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    return null;
}

// ── Autorização ─────────────────────────────────────────────────────────────
$admin_autenticado = false;
try {
    $usuario_admin = verificarAutenticacao(false);
$tenant_id = exigirTenantId();
    $admin_autenticado = ($usuario_admin !== false);
} catch (Exception $e) {
    $admin_autenticado = false;
}

if (!$admin_autenticado) {
    $morador_id_sessao = _lf_resolver_morador_id($conexao);
    if (!$morador_id_sessao || (int) $foto['morador_id'] !== $morador_id_sessao) {
        fechar_conexao($conexao);
        http_response_code(403);
        echo 'Acesso não autorizado a esta foto.';
        exit;
    }
}

$caminho_abs = dirname(__DIR__) . '/' . $foto['caminho'];
if (!file_exists($caminho_abs)) {
    fechar_conexao($conexao);
    http_response_code(404);
    echo 'Arquivo não encontrado no servidor.';
    exit;
}

fechar_conexao($conexao);

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $foto['tipo_mime']);
header('Content-Disposition: inline; filename="' . addslashes($foto['nome_original']) . '"');
header('Content-Length: ' . filesize($caminho_abs));
header('Cache-Control: private, max-age=3600');

$handle = fopen($caminho_abs, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
exit;
