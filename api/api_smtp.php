<?php
/**
 * APOSENTADO — a configuração de e-mail/SMTP deixou de ser por tenant.
 *
 * Este endpoint não exigia sequer autenticação para ler/alterar as
 * credenciais SMTP usadas por toda a plataforma — qualquer usuário logado
 * de qualquer condomínio conseguia ver/trocar a senha do remetente global.
 * A configuração agora vive exclusivamente no Painel Super-Admin
 * (api/api_superadmin.php, ações email_config_*), restrita a super_admin.
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'sucesso'  => false,
    'mensagem' => 'Configuração de e-mail migrada para o Painel Super-Admin (Integração > E-mail).',
], JSON_UNESCAPED_UNICODE);
