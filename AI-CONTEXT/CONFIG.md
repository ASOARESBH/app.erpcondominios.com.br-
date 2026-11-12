# Configurações do Sistema

## 1. Arquivo Principal de Configuração
`api/config.php` — Contém as credenciais do banco de dados e configurações globais.

**NUNCA versionar este arquivo com credenciais reais.** Usar `config.example.php` como template.

## 2. Configurações Armazenadas no Banco
A tabela `configuracoes` armazena configurações dinâmicas do sistema:
- Nome e logo da associação.
- Configurações de e-mail (SMTP/Brevo/Resend).
- Configurações do Firebase (FCM) para Push Notifications.
- Período de leitura de hidrômetros.

## 3. Timezone
Definido no `config.php`:
```php
date_default_timezone_set('America/Sao_Paulo');
```

## 4. Hetzner — homologação MySQL (2026-11-12)

A configuração privada da HML deve residir em `/etc/erpcondominios/erp_config.php`, derivada do template versionado, com `ERP_DB_HOST='localhost'`, banco `erpcondominios`, usuário `erp_app` e charset `utf8mb4`. A senha fica em arquivo separado root-only em `/etc/erpcondominios/secrets/`; nunca registrar valores em Git, log ou relatório. O acesso de PHP foi validado com `mysqli` pelo socket local.

A base HML recebeu somente uma tentativa parcial de restauração do dump de ensaio e não está pronta para uso da aplicação. H2 está bloqueada; não usar essa configuração como produção, não habilitar tráfego externo e não repetir/limpar a carga sem resolver o erro MySQL 1419 e aprovar a recuperação do schema parcial.
