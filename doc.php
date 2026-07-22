<?php
/**
 * Página pública de acesso a documentos compartilhados
 * URL: /doc.php?t={token}
 *
 * Não requer autenticação — valida apenas o token.
 */
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';

$token = trim($_GET['t'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{48}$/', $token)) {
    http_response_code(404);
    _renderErro('Link inválido', 'O link de compartilhamento não é válido ou está malformado.');
}

$db = conectar_banco();
$tEsc = $db->real_escape_string($token);

$res = $db->query("SELECT c.*, d.nome AS doc_nome, d.arquivo, d.arquivo_tipo,
                          d.arquivo_nome_original, d.link_externo, d.descricao AS doc_desc,
                          d.status AS doc_status,
                          dep.nome AS departamento
                   FROM documentos_compartilhamentos c
                   JOIN documentos d ON d.id = c.documento_id
                   LEFT JOIN documentos_departamentos dep ON dep.id = d.departamento_id
                   WHERE c.token='$tEsc' AND c.ativo=1 LIMIT 1");

if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    _renderErro('Link não encontrado', 'Este link de compartilhamento não existe ou foi desativado.');
}

$comp = $res->fetch_assoc();

// Validações
if ($comp['doc_status'] !== 'ativo') {
    _renderErro('Documento indisponível', 'Este documento não está mais disponível.');
}
if ($comp['expira_em'] && strtotime($comp['expira_em']) < time()) {
    $db->query("UPDATE documentos_compartilhamentos SET ativo=0 WHERE token='$tEsc'");
    _renderErro('Link expirado', 'Este link de compartilhamento expirou em ' . date('d/m/Y H:i', strtotime($comp['expira_em'])) . '.');
}
if ($comp['limite_acessos'] !== null && $comp['total_acessos'] >= $comp['limite_acessos']) {
    _renderErro('Limite atingido', 'Este link atingiu o número máximo de acessos permitidos.');
}

// Registrar acesso externo
$ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
$ipE = $db->real_escape_string($ip);
$uaE = $db->real_escape_string($ua);
$docId = (int)$comp['documento_id'];

$db->query("INSERT INTO documentos_acessos
            (documento_id, tipo, origem, token_compartilhamento, ip, user_agent)
            VALUES ($docId,'visualizacao','externo','$tEsc','$ipE','$uaE')");

$db->query("UPDATE documentos_compartilhamentos SET total_acessos=total_acessos+1 WHERE token='$tEsc'");
$db->query("UPDATE documentos SET total_visualizacoes=total_visualizacoes+1 WHERE id=$docId");

// Download direto se solicitado
if (isset($_GET['dl']) && !empty($comp['arquivo'])) {
    $path = __DIR__ . '/uploads/documentos/' . basename($comp['arquivo']);
    if (file_exists($path)) {
        $db->query("UPDATE documentos_acessos SET tipo='download' ORDER BY id DESC LIMIT 1");
        $db->query("UPDATE documentos SET total_downloads=total_downloads+1 WHERE id=$docId");
        header('Content-Type: ' . ($comp['arquivo_tipo'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($comp['arquivo_nome_original'] ?: basename($comp['arquivo'])) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

// Renderizar página pública
$nomeDoc = htmlspecialchars($comp['doc_nome']);
$depto   = htmlspecialchars($comp['departamento'] ?? '');
$descDoc = htmlspecialchars($comp['doc_desc'] ?? '');
$temArq  = !empty($comp['arquivo']);
$temLink = !empty($comp['link_externo']);
$urlDl   = '?t=' . urlencode($token) . '&dl=1';
$linkExt = htmlspecialchars($comp['link_externo'] ?? '');

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $nomeDoc ?> — Documento Compartilhado</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;background:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.12);max-width:560px;width:100%;overflow:hidden}
  .card-header{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:28px;text-align:center}
  .card-header h1{font-size:1.4rem;font-weight:700;margin-bottom:8px}
  .card-header p{font-size:.9rem;opacity:.85}
  .card-body{padding:28px}
  .doc-name{font-size:1.25rem;font-weight:700;color:#0f172a;margin-bottom:8px}
  .doc-desc{color:#64748b;font-size:.9rem;margin-bottom:20px;line-height:1.5}
  .meta-row{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#64748b;margin-bottom:8px}
  .meta-row i{width:18px;color:#2563eb}
  .btn-dl{display:block;width:100%;padding:14px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;text-decoration:none;text-align:center;margin-top:20px;transition:background .2s}
  .btn-dl:hover{background:#1d4ed8}
  .btn-ext{display:block;width:100%;padding:14px;background:#fff;color:#2563eb;border:2px solid #2563eb;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;text-decoration:none;text-align:center;margin-top:12px;transition:all .2s}
  .btn-ext:hover{background:#eff6ff}
  .footer{text-align:center;padding:16px;font-size:.75rem;color:#94a3b8;border-top:1px solid #f1f5f9}
  .badge{display:inline-block;background:#dbeafe;color:#1e40af;padding:3px 12px;border-radius:20px;font-size:.78rem;font-weight:600;margin-bottom:16px}
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <div style="font-size:3rem;margin-bottom:12px">📄</div>
    <h1>Documento Compartilhado</h1>
    <p>Você recebeu acesso a um documento do Sistema ERP Serra da Liberdade</p>
  </div>
  <div class="card-body">
    <?php if ($depto): ?>
    <span class="badge"><?= $depto ?></span>
    <?php endif; ?>
    <div class="doc-name"><?= $nomeDoc ?></div>
    <?php if ($descDoc): ?>
    <div class="doc-desc"><?= $descDoc ?></div>
    <?php endif; ?>

    <?php if ($comp['expira_em']): ?>
    <div class="meta-row"><i class="fas fa-clock"></i> Válido até: <?= date('d/m/Y H:i', strtotime($comp['expira_em'])) ?></div>
    <?php endif; ?>
    <?php if ($comp['limite_acessos']): ?>
    <div class="meta-row"><i class="fas fa-eye"></i> Acessos: <?= $comp['total_acessos'] ?>/<?= $comp['limite_acessos'] ?></div>
    <?php endif; ?>

    <?php if ($temArq): ?>
    <a href="<?= $urlDl ?>" class="btn-dl">⬇️ Baixar Documento</a>
    <?php endif; ?>
    <?php if ($temLink): ?>
    <a href="<?= $linkExt ?>" target="_blank" rel="noopener noreferrer" class="btn-ext">🔗 Abrir Link Externo</a>
    <?php endif; ?>
  </div>
  <div class="footer">Serra da Liberdade — ERP Condomínio · Este link pode ser desativado a qualquer momento.</div>
</div>
</body>
</html>
<?php

function _renderErro(string $titulo, string $msg): never {
    http_response_code(403);
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>$titulo</title>
    <style>body{font-family:Arial;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh}
    .box{background:#fff;border-radius:12px;padding:40px;max-width:440px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)}
    h2{color:#dc2626;margin-bottom:12px} p{color:#64748b;line-height:1.5}</style></head>
    <body><div class='box'><div style='font-size:3rem;margin-bottom:16px'>🔒</div>
    <h2>$titulo</h2><p>$msg</p></div></body></html>";
    exit;
}
