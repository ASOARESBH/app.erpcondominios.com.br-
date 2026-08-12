# Auditoria estática de branding Multi-Tenant

## APIs que usam logo, empresa ou tenant
api/api_demonstrativo_agua.php
api/api_email_alertas.php
api/api_empresa.php
api/api_relatorio_abastecimento_pdf.php
api/api_relatorio_acessos_pdf.php
api/api_relatorio_acessos_veiculos_pdf.php
api/api_relatorio_hidrometro_pdf.php
api/api_relatorio_inventario_pdf.php
api/api_relatorio_licitacao_pdf.php
api/api_relatorio_moradores_pdf.php
api/api_relatorio_veiculos_pdf.php
api/api_relatorio_visitantes_pdf.php
api/api_rh_relatorio_pdf.php
api/api_superadmin.php
api/api_tenants.php
api/api_verificar_tipo_login.php
api/auth_helper.php
api/get_logo_empresa.php
api/tenant_helper.php
api/verificar_sessao.php

## Referências perigosas sem sessão ou com fallback de primeiro tenant
api/api_abastecimento.php:451:    $result = $conn->query("SELECT valor FROM abastecimento_saldo WHERE id = 1");
api/api_abastecimento.php:459:    $result = $conn->query("SELECT valor_minimo FROM abastecimento_saldo WHERE id = 1");
api/api_abastecimento.php:480:        WHERE id = 1
api/api_validar_token.php:188:    $usuario_id = 1; // Sistema
api/api_verificar_tipo_login.php:250:                // Compatibilidade com instalações antigas — usa tenant_id = 1
api/debug_sessao.php:199:    $morador_id = 185; // ANDRE SOARES E SILVA
api/get_logo_empresa.php:123:        $res = $conexao->query("SELECT logo_url, nome_fantasia, razao_social FROM tenants WHERE status = 'ativo' LIMIT 1");
api/get_logo_empresa.php:132:                        'tenant_sem_sessao',

## Referências de login ao endpoint de logo
login.html:29:                API_LOGO = window.location.origin + '/api/get_logo_empresa.php';
frontend/login.html:29:                API_LOGO = window.location.origin + '/api/get_logo_empresa.php';
