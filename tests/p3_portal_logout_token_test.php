<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$login = file_get_contents($root . '/api/api_verificar_tipo_login.php');
$logout = file_get_contents($root . '/api/logout_morador.php');
if (!is_string($login) || !is_string($logout)) {
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
];

$passed = 0;
foreach ($checks as $name => $ok) {
    printf("%s\t%s\n", $name, $ok ? 'PASS' : 'FAIL');
    if ($ok) $passed++;
}
printf("TOTAL=%d\nPASS=%d\nFAIL=%d\n", count($checks), $passed, count($checks) - $passed);
exit($passed === count($checks) ? 0 : 1);
