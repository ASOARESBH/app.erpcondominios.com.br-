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
