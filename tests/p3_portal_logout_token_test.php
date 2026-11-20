<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$login = file_get_contents($root . '/api/api_verificar_tipo_login.php');
$logout = file_get_contents($root . '/api/logout_morador.php');
$session = file_get_contents($root . '/api/verificar_sessao_completa.php');
$cpfLogin = file_get_contents($root . '/api/validar_login_morador.php');
if (!is_string($login) || !is_string($logout) || !is_string($session) || !is_string($cpfLogin)) {
    fwrite(STDERR, "FAIL: fontes canônicas do Portal indisponíveis.\n");
    exit(1);
}

$checks = [
    'Login detecta tenant_id no schema antes de gravar a sessão Portal' => str_contains($login, 'DatabaseDialect::columnExists($conexao, \'sessoes_portal\', \'tenant_id\')'),
    'Login inclui tenant_id quando a coluna existe e preserva INSERT legado' => str_contains($login, 'sessoes_portal (morador_id, tenant_id, token, ip_address, user_agent, data_expiracao)')
        && str_contains($login, 'sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao)'),
    'Login mantém o Bearer somente na sessão PHP server-side' => preg_match('~\$_SESSION\[\'morador_portal_token\'\]\s*=\s*\$token\s*;~', $login) === 1,
    'Logout lê o mesmo marcador de sessão antes de destruir a sessão PHP' => preg_match('~\$tokenPortal\s*=\s*\(string\)\(\$_SESSION\[\'morador_portal_token\'\]\s*\?\?\s*\'\'\)~', $logout) === 1,
    'Revogação restringe o token ao morador e tenant da sessão' => str_contains($logout, 'WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1'),
    'Logout usa metadata de schema portável' => str_contains($logout, 'DatabaseDialect::columnExists($conexao, \'sessoes_portal\', \'tenant_id\')') && !str_contains($logout, 'INFORMATION_SCHEMA.COLUMNS'),
    'Login principal PostgreSQL exige tenant ativo do morador e sem fallback tenant 1' => str_contains($login, "m.id = ? AND m.tenant_id = ? AND m.ativo = 1 AND t.status = 'ativo'") && str_contains($login, 'if (!$tenant_morador && !$isPostgres)') && !str_contains($login, 'O login do Portal do Morador ainda não foi homologado'),
    'Sessão GET valida tenant, morador ativo e expiração e só confirma após update' => str_contains($session, 'sp.tenant_id=m.tenant_id') && str_contains($session, 'sp.token=? AND sp.ativo=1 AND m.ativo=1') && str_contains($session, 'sp.data_expiracao') && str_contains($session, '$update->affected_rows > 0'),
    'PostgreSQL aceita Bearer somente no header; query/body/cookie ficam apenas no legado MySQL' => str_contains($session, "if (defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql') return '';") && str_contains($session, 'return trim($_POST[\'token\'] ?? $_GET[\'token\'] ?? \'\');'),
    'POST renova/logout apenas depois da validação e usa escopo tenant-morador' => str_contains($session, '$sessaoPortal = verificar_sessao_portal($conexao, $token, $config)') && str_contains($session, 'WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1') && str_contains($session, '$stmt->affected_rows > 0'),
    'Rota CPF ativa usa metadata e token transacional tenant-scoped' => str_contains($cpfLogin, 'DatabaseDialect::columnExists($conexao, \'moradores\', \'tenant_id\')') && str_contains($cpfLogin, 'DatabaseDialect::columnExists($conexao, \'sessoes_portal\', \'tenant_id\')') && str_contains($cpfLogin, 'begin_transaction()') && str_contains($cpfLogin, 'DELETE FROM sessoes_portal WHERE morador_id = ? AND tenant_id = ?'),
    'Eventos de login/logout não incluem CPF, nome, hash, token ou IP como payload de log' => str_contains($cpfLogin, "'LOGIN_PORTAL_CONFIRMADO', null, false") && str_contains($logout, "'LOGOUT_PORTAL', null, false") && !str_contains($cpfLogin, 'registrarTentativa('),
];

$passed = 0;
foreach ($checks as $name => $ok) {
    printf("%s\t%s\n", $name, $ok ? 'PASS' : 'FAIL');
    if ($ok) $passed++;
}
printf("TOTAL=%d\nPASS=%d\nFAIL=%d\n", count($checks), $passed, count($checks) - $passed);
exit($passed === count($checks) ? 0 : 1);
