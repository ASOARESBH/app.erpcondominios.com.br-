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

## 7. Hetzner — HML MySQL lift-and-shift (2026-11-12)

A decisão operacional é hospedar a aplicação atual sobre MySQL 8.4.13; o projeto PostgreSQL fica pausado. A VM possui MySQL local-only em `127.0.0.1:3306`/socket, `NO_ENGINE_SUBSTITUTION`, timezone `-03:00`, servidor `utf8mb4`/`utf8mb4_unicode_ci`, binlog com retenção de 7 dias, slow log ativo e Event Scheduler desligado. PHP `mysqli` autenticou por socket com `erp_app`; a porta 3306 não tem regra UFW.

H2 foi aceita em 12/11: a evidência root-only registra 270 tabelas, 202.609 linhas reconciliadas em 270/270 tabelas, 7 triggers, 2 procedures, zero definers incorretos, zero divergências e zero erros de importação. A base `erpcondominios` continua sendo HML; integrações externas nela permanecem neutralizadas. Preservar os logs privados da tentativa anterior, o dump-fonte e os artefatos históricos PostgreSQL.

H3 comparou as 54 migrations de `origin/main` com catálogo `INFORMATION_SCHEMA` do HML. Foram aplicadas somente as lacunas estruturais idempotentes comprovadas: cinco tabelas `alertas_acesso_*`, a coluna `abastecimento_lancamentos.observacao`, as colunas/índices únicos `client_uuid` de `leituras` e `leituras_fotos`, e os índices `os_chamados.idx_origem_portal`/`idx_morador_id`. O script reproduzível MySQL 8.4 é `sql/deploy/hetzner_cutover_migrations.sql`; usa guards por `INFORMATION_SCHEMA` e SQL preparado, sem DML, DROP, TRUNCATE ou valores de integração. Pré-backup lógico e reconciliação das contagens foram registrados root-only na VM.

H3 permanece **PARCIAL**: as migrations mistas/não idempotentes ainda não foram executadas; qualquer DML aguarda autorização. `assembleias.tenant_id` e `idx_assembleias_tenant_data` já existem, com zero assembleias nulas/zero, então não houve execução da migration de backfill. `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` segue desativada e não executada. A migration v2 de notificações também não foi aplicada: `protocolo_id` continua `NOT NULL` e há 643 linhas existentes, apesar de zero duplicidades na chave candidata.

H4 agora deve expor somente a HML no IP `2.29.161.106`, sem alterar nenhum registro DNS ou HostGator. A release deve vir de `origin/main` (`f60739713e6de3f2473b79ecd14a70fd700e5462`), não desta branch de documentação. Ainda faltam Nginx/release/configuração do site e os ZIPs autorizados de `uploads/` e `config/firebase/`; não tentar Certbot sem nome DNS apontado. Não abrir banco para a Internet, nem alterar SSH, fail2ban, UFW ou AppArmor.

Estado: `H1_OK / H2_ACCEPTED / H3_PARTIAL / H4_IP_NOT_STARTED`. PostgreSQL e ensaios históricos permanecem pausados/preservados. Sem deploy, DNS, alteração no HostGator ou merge em `main` até nova verificação e gates aplicáveis.
