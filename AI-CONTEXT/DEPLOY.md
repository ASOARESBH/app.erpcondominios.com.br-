# Guia de Deploy (HostGator)

## 1. Ambiente
O sistema é hospedado em uma revenda HostGator (cPanel).
- **Pasta raiz**: `/public_html/` ou pasta do domínio `app.erpcondominios.com.br`
- **Banco de Dados**: MySQL via phpMyAdmin.

## 2. Processo de Deploy
1. **Compactação**: Todo código deve ser entregue em um arquivo `.zip`.
2. **Upload**: Feito manualmente pelo usuário via Gerenciador de Arquivos do cPanel.
3. **Extração**: Extrair o `.zip` sobrescrevendo os arquivos existentes.

## 3. Arquivos Sensíveis (NUNCA sobrescrever em produção)
- `api/config.php`: Contém as credenciais reais do banco de dados (`inlaud99_admin`).
- Pasta `uploads/`: Contém arquivos de moradores, contratos e logos.
- Arquivos `.htaccess`: Regras de segurança e redirecionamento.

## 4. Migrações de Banco de Dados
Sempre que houver alteração de banco, deve ser gerado um arquivo `.sql` separado e as instruções de execução (via phpMyAdmin) devem ser fornecidas ao usuário.

## 5. Alertas da Unidade — rotina de expiração
Após backup, deploy dos arquivos e aplicação de `sql/migration_alertas_unidade_mysql57.sql`, cadastrar no cPanel > Cron Jobs a rotina `cron/alertas_unidade_a_cada_10_minutos.php` a cada 10 minutos (`*/10 * * * *`). O comando deve usar o binário PHP CLI disponível na conta e o caminho absoluto do domínio, por exemplo: `*/10 * * * * /usr/local/bin/php /home/USUARIO/app.erpcondominios.com.br/cron/alertas_unidade_a_cada_10_minutos.php`. A rotina só aceita CLI, usa lock para evitar concorrência, expira alertas vencidos e deduplica o lembrete de 24 horas. A migration, deploy e agendamento de produção não são executados por esta alteração de código; confirmar o caminho real e o binário no cPanel antes de ativar.

## 6. Chat-In (2026-11-05)
Após backup: executar no phpMyAdmin `sql/migration_chatin_mysql57.sql` e `sql/migration_assembleia_tenant_mysql57.sql` (conferir `assembleias_sem_tenant = 0`). Enviar os arquivos web. O widget se desativa sozinho enquanto a migration não existir. Para desligar o push do Chat-In em um condomínio: `pwa_configuracoes` chave `push_chatin_ativo` = '0'. App: `flutter analyze` + novo build para o push/telas do Chat-In.


## URL limpa de login — gate de publicação e Nginx

A branch `fix/url-limpa-login` parte da `main` atual e adiciona `<base href="/frontend/">` em `frontend/login.html`, além da documentação. Até o merge do PR e publicação desse release, não alterar os vhosts Nginx de produção. Depois de publicado: fazer backup root-only dos dois vhosts (domínio e IP), servir `/` via `alias` para o `frontend/login.html`, redirecionar `/frontend/login.html` e `/login.html` para `/` preservando query strings, validar `nginx -t` e só então recarregar. Evitar loop: a raiz não deve redirecionar para `/frontend/login.html`. O rollback restaura os vhosts salvos e recarrega após `nginx -t`; o rollback do código aponta `current` ao release anterior.

Critérios pós-publicação: `/` retorna 200 `text/html` com o `<base>`; os dois caminhos legados retornam 301 à raiz com query preservada; JS, catálogos, CSS e assets retornam 200; `/login_morador.html` e `/login_fornecedor.html` continuam acessíveis. QA autenticado de login, logout, sessão expirada, recuperação de senha, idiomas e ausência de 404 deve ser feito no navegador autorizado, sem alterar fluxos JavaScript para corrigir URL.

## Rotas limpas da SPA — gate e rollback

A mudança `feature/urls-limpas` adiciona rotas de primeiro nível `/<slug>` para os fragments `frontend/pages/*.html`. O Nginx serve internamente o shell sem reescrever a URL; a barra final é removida por `history.replaceState`, enquanto paths fora do allowlist mantêm o fallback já existente. Após PR revisado e merge aprovado pelo André, a sequência autorizada é: backup dos vhosts; instalar `deploy/hetzner/erp-pages.conf` como `/etc/nginx/snippets/erp-pages.conf` e os vhosts; `nginx -t` e reload; só então publicar o release imutável do código e fazer o smoke. A janela entre Nginx e release deve ser imediata. Não houve alteração de produção nesta branch. O gerador versionado `deploy/hetzner/generate-clean-page-routes.py` produz `deploy/hetzner/erp-pages.conf`; rode `python3 deploy/hetzner/generate-clean-page-routes.py --check` em CI/revisão e regenere após adicionar/remover uma página. O gerador falha em slug reservado/inválido, colisão e snippet obsoleto.

Após o release: fazer backup root-only dos vhosts atuais do domínio e IP; instalar o snippet em `/etc/nginx/snippets/erp-clean-page-routes.conf`; instalar os dois vhosts versionados; executar `nginx -t`; confirmar com `nginx -T` que o snippet está incluído uma vez por vhost e que a regra não precede os bloqueios explícitos; recarregar Nginx somente depois dos gates. A allowlist fica após bloqueios de caminhos reservados e antes das regex genéricas de estáticos/PHP. Não capturar `/projetos`, `/api`, `/uploads`, `/config`, `/frontend`, arquivos físicos ou logins alternativos. `/layout-base.html` deve preservar a query e redirecionar para `/frontend/layout-base.html`; a raiz `/` continua servindo login e os caminhos antigos `?page=` continuam compatíveis.

Rollback: restaurar os dois vhosts e snippet do backup, validar com `nginx -t` e recarregar; apontar `current` ao release anterior se a regressão estiver no código. Não alterar DNS nem executar o deploy de produção como parte da criação do PR. Após publicação, smoke-testar deep links, refresh/F5, back/forward, assets, logins alternativos, API, uploads/config e `/projetos` em hostname e IP. O QA de navegação autenticada exige conta de teste não produtiva fornecida pelo administrador; não registrar credenciais.
