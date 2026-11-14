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

## Estado da configuração ativa após import — 14/11/2026

A configuração privada da aplicação foi atualizada atomicamente para conectar ao schema importado mais recente na Hetzner. A cópia de rollback da configuração anterior é root-only, e o schema anterior segue preservado como rollback do banco. O arquivo de configuração e quaisquer credenciais permanecem fora do repositório e do webroot. PHP-FPM foi recarregado e a conexão ao novo schema foi validada por CLI e Nginx/PHP-FPM sem exibir valores privados.

## 5. Alertas de infraestrutura Hetzner (2026-11-13)

Os alertas de operação usam `msmtp` separado da configuração de e-mail do ERP. `/etc/erpcondominios/secrets/msmtprc`, `smtp-password` (Brevo) e `titan-app-password` ficam em `root:root 0600`, no diretório `root:root 0700`; nenhuma credencial ou parâmetro de conexão é versionado. André definiu TITAN como primário e Brevo como fallback automático, com remetente/destinatário `noreply@erpcondominios.com.br`. Os scripts de provisionamento dual foram instalados a partir do commit `6cdca818b99984042f4bf9f75b711edf7b3b8a59`. A credencial Brevo extraída privadamente do snapshot confirmado não autenticou; `titan-app-password` ainda não existe e aguarda geração/instalação direta via TTY na VM. O `msmtprc` permanece single-account até a conversão protegida ser validada; não substituir por API key. O timer do resumo permanece desabilitado; o healthcheck mantém um marcador sanitizado root-only de falha de entrega.


## Hostname e TLS Hetzner — 2026-11-14

O Nginx serve `app.erpcondominios.com.br` por vhost TLS separado com certificado Let's Encrypt próprio. O vhost por IP permanece como contingência; o certificado IP não foi reutilizado para o domínio. A renovação via Certbot Snap passou `--dry-run`, e `snap.certbot.renew.timer` está ativo. A alteração DNS limitou-se ao A de `app`; um TTL TXT do apex foi normalizado sem alteração do conteúdo e MX/Titan permaneceu intacto.

Alertas SMTP continuam sem credencial app-password TITAN instalada; o resumo diário está pausado. Não guardar credenciais no repositório, nem executar envio de teste até a instalação segura por TTY e nova autorização para retomar essa etapa.
