<?php
/** API de idioma do ERP: preferência do usuário, padrão do tenant e fallback. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
$usuario = verificarAutenticacao();
$tenantId = exigirTenantId();
$usuarioId = (int)($usuario['id'] ?? $_SESSION['usuario_id'] ?? 0);
$db = conectar_banco();
$permitidos = ['pt-BR','en-US','es-ES'];
$acao = $_GET['acao'] ?? 'preferencia';
function i18n_json(bool $ok, string $msg, $dados=null, int $status=200): void { http_response_code($status); echo json_encode(['sucesso'=>$ok,'mensagem'=>$msg,'dados'=>$dados], JSON_UNESCAPED_UNICODE); exit; }
function coluna_existe(mysqli $db, string $tabela, string $coluna): bool { $s=$db->prepare('SELECT COUNT(*) total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $s->bind_param('ss',$tabela,$coluna); $s->execute(); $ok=(int)($s->get_result()->fetch_assoc()['total']??0)>0; $s->close(); return $ok; }
if (!coluna_existe($db,'tenants','locale') || !coluna_existe($db,'usuarios','locale')) i18n_json(false,'Execute sql/migration_i18n_pt_en_es_mysql57.sql antes de usar preferências de idioma.',null,503);
try {
    if ($acao === 'preferencia' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $s=$db->prepare('SELECT t.locale AS tenant_locale, u.locale AS usuario_locale FROM tenants t LEFT JOIN usuarios u ON u.id=? WHERE t.id=? LIMIT 1'); $s->bind_param('ii',$usuarioId,$tenantId); $s->execute(); $row=$s->get_result()->fetch_assoc()?:[]; $s->close();
        $usuarioLocale=in_array($row['usuario_locale']??'', $permitidos, true)?$row['usuario_locale']:null; $tenantLocale=in_array($row['tenant_locale']??'', $permitidos, true)?$row['tenant_locale']:'pt-BR'; i18n_json(true,'Preferência carregada.',['locale'=>$usuarioLocale?:$tenantLocale,'usuario_locale'=>$usuarioLocale,'tenant_locale'=>$tenantLocale,'permitidos'=>$permitidos]);
    }
    if ($acao === 'preferencia' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body=json_decode(file_get_contents('php://input')?:'{}',true); $locale=(string)($body['locale']??''); if(!in_array($locale,$permitidos,true))i18n_json(false,'Idioma não suportado.',null,422);
        $s=$db->prepare('UPDATE usuarios SET locale=? WHERE id=? AND tenant_id=?'); $s->bind_param('sii',$locale,$usuarioId,$tenantId); if(!$s->execute()) throw new RuntimeException($s->error); $s->close(); $_SESSION['locale']=$locale; i18n_json(true,'Preferência de idioma salva.',['locale'=>$locale]);
    }
    if ($acao === 'tenant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nivel=strtolower((string)($usuario['permissao']??$usuario['nivel_permissao']??$_SESSION['usuario_permissao']??'')); if(!in_array($nivel,['admin','super_admin'],true))i18n_json(false,'Permissão administrativa necessária.',null,403);
        $body=json_decode(file_get_contents('php://input')?:'{}',true); $locale=(string)($body['locale']??''); if(!in_array($locale,$permitidos,true))i18n_json(false,'Idioma não suportado.',null,422);
        $s=$db->prepare('UPDATE tenants SET locale=? WHERE id=?'); $s->bind_param('si',$locale,$tenantId); if(!$s->execute()) throw new RuntimeException($s->error); $s->close(); i18n_json(true,'Idioma padrão do condomínio salvo.',['locale'=>$locale]);
    }
    i18n_json(false,'Ação não reconhecida.',null,404);
} catch(Throwable $e) { error_log('[I18N] '.$e->getMessage()); i18n_json(false,'Não foi possível salvar a preferência de idioma.',null,500); }
