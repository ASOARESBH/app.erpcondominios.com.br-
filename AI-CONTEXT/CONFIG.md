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

A base HML foi restaurada e aceita: 270 tabelas e 202.609 registros reconciliados por tabela, sete triggers, duas procedures, zero divergências e zero erros de importação. As integrações externas foram neutralizadas somente nessa cópia para evitar notificações de teste. A evidência detalhada é privada/root-only e não deve ser copiada para Git.

H3 aplicou apenas DDL estrutural idempotente comprovadamente necessário; os itens de dados/seed continuam sem alteração e aguardam autorização. Esta configuração é de homologação, nunca produção. H4 criou `/etc/erpcondominios/erp_config.php` com `root:www-data 0640`, alimentado pelo segredo MySQL root-only; nenhum valor foi versionado ou impresso. A HML está disponível por HTTPS no IP `2.29.161.106` com certificado de IP de curta validade e renovação automatizada. A senha de DB nunca deve ser colocada em `config.php` versionado. Não habilitar serviços de banco para tráfego externo.

Os dados em `configuracoes` continuam com as integrações externas neutralizadas nesta cópia. Os artefatos ZIP de `uploads/` e `config/firebase/` ainda não foram fornecidos e não foram importados; `shared/uploads` permanece vazio. Manter Firebase/FCM, SMTP/Brevo/Resend e webhooks neutralizados até autorização específica e QA HML autenticado.
