<?php
require_once __DIR__ . '/../api/helpers/pwa_security.php';

function assertPwaSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$public = pwa_config_publica([
    'fcm_project_id' => 'public-project',
    'fcm_vapid_key' => 'public-key',
    'fcm_server_key' => 'must-not-leak',
    'fcm_service_account_path' => '/private/service-account.json',
    'fcm_service_account_email' => 'private@example.invalid',
    'fcm_api_key_preview' => 'prefix****suffix',
    'service_account_email' => 'private@example.invalid',
    'service_account_project' => 'private-project',
    'private_key' => 'must-not-leak',
    'access_token' => 'must-not-leak',
    'new_unreviewed_private_field' => 'must-not-leak',
]);
assertPwaSecurity(($public['fcm_project_id'] ?? null) === 'public-project', 'preserva campos públicos');
assertPwaSecurity(!isset($public['fcm_server_key'], $public['private_key'], $public['access_token']), 'remove credenciais');
assertPwaSecurity(!isset($public['fcm_service_account_path'], $public['fcm_service_account_email']), 'remove metadados privados do service account');
assertPwaSecurity(!isset($public['fcm_api_key_preview'], $public['service_account_email'], $public['service_account_project']), 'remove prévia e metadados derivados do service account');
assertPwaSecurity(!isset($public['new_unreviewed_private_field']), 'allowlist descarta campos desconhecidos');
$mergedConfig = pwa_mesclar_config_tenant(['fcm_project_id' => 'global', 'push_os_ativo' => '1'], ['push_os_ativo' => '0']);
assertPwaSecurity($mergedConfig === ['fcm_project_id' => 'global', 'push_os_ativo' => '0'], 'config tenant prevalece sobre base institucional');

$pageBounds = pwa_paginacao('1', '-25', 50);
assertPwaSecurity($pageBounds === ['pagina' => 1, 'limite' => 1, 'offset' => 0], 'limite negativo é limitado a um');
$pageBounds = pwa_paginacao('0', '0', 50);
assertPwaSecurity($pageBounds === ['pagina' => 1, 'limite' => 1, 'offset' => 0], 'zero não gera LIMIT/OFFSET negativo');
$pageBounds = pwa_paginacao('999999999', '10000', 50);
assertPwaSecurity($pageBounds === ['pagina' => 1000000, 'limite' => 50, 'offset' => 49999950], 'limite e página extremos são clampados');
$pageBounds = pwa_paginacao('invalid', 'invalid', 50);
assertPwaSecurity($pageBounds === ['pagina' => 1, 'limite' => 20, 'offset' => 0], 'parâmetros inválidos usam defaults seguros');

foreach (['/frontend/portal_morador.html', '/frontend/portal_morador.html?aba=avisos', '/'] as $url) {
    assertPwaSecurity(pwa_url_destino_valida($url), 'aceita rota same-origin');
}
foreach (['https://evil.example/path', '//evil.example/path', 'javascript:alert(1)', 'https:/evil.example/path', " /x", "/x\r\nLocation: https://evil.example", '/\\evil.example/path', '/%5c%5cevil.example/path', '/%255c%255cevil.example/path', '/%0d%0aLocation%3Aevil'] as $url) {
    assertPwaSecurity(!pwa_url_destino_valida($url), 'rejeita destino externo ou malformado');
}
foreach (['https://app.erpcondominios.com.br', 'http://localhost', 'http://127.0.0.1'] as $origin) {
    assertPwaSecurity(pwa_origem_permitida($origin), 'aceita origem PWA explicitamente autorizada');
}
foreach (['https://evil.example', 'http://app.erpcondominios.com.br', 'http://localhost:3000'] as $origin) {
    assertPwaSecurity(!pwa_origem_permitida($origin), 'rejeita origem PWA fora da allowlist exata');
}

assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'todos'])['destinatario'] === 'todos', 'broadcast precisa ser explícito');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'morador', 'morador_id' => '12'])['morador_id'] === 12, 'aceita identificador válido de morador');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'unidade', 'unidade_id' => 7])['unidade_id'] === 7, 'aceita identificador válido de unidade');
assertPwaSecurity(pwa_resolver_destinatario_push(['morador_id' => '12']) === null, 'não infere broadcast quando destinatário falta');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'morador', 'morador_id' => 'abc']) === null, 'rejeita identificador malformado');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'morador']) === null, 'rejeita destinatário sem ID correspondente');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'todos', 'unidade_id' => 7]) === null, 'rejeita IDs em broadcast');
assertPwaSecurity(pwa_resolver_destinatario_push(['destinatario' => 'morador', 'morador_id' => '12', 'unidade_id' => '7']) === null, 'rejeita IDs conflitantes');
assertPwaSecurity(pwa_resolver_destinatario_automatico(['destinatario' => 'todos'])['destinatario'] === 'todos', 'envio automático aceita broadcast somente quando explícito');
assertPwaSecurity(pwa_resolver_destinatario_automatico(['destinatario' => 'morador', 'morador_id' => '12'])['morador_id'] === 12, 'envio automático aceita morador somente com ID válido');
assertPwaSecurity(pwa_resolver_destinatario_automatico(['morador_id' => 'abc']) === null, 'envio automático rejeita ID inválido sem converter para broadcast');
assertPwaSecurity(pwa_resolver_destinatario_automatico(['morador_id' => '12']) === null, 'envio automático exige destinatário explícito');
assertPwaSecurity(pwa_resolver_destinatario_automatico(['destinatario' => 'unidade', 'unidade_id' => '7']) === null, 'envio automático não aceita destino por unidade sem implementação dedicada');
assertPwaSecurity(pwa_schema_sessao_portal_exigido('registrar_token') === ['token', 'morador_id', 'ativo', 'data_expiracao'], 'readiness exige schema de sessão no registro de token');
assertPwaSecurity(pwa_schema_sessao_portal_exigido('listar_minhas') === ['token', 'morador_id', 'ativo', 'data_expiracao'], 'readiness exige schema de sessão na listagem Bearer');
assertPwaSecurity(pwa_schema_sessao_portal_exigido('estatisticas') === null, 'ações administrativas não exigem tabela Bearer');

echo "pwa_security_test: PASS (40 assertions)\n";
