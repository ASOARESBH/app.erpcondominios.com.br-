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

H1 foi validada para o runtime MySQL. H2 **não foi aceita**: o dump autorizado (SHA-256 `1e79b7172c8d0141e63b90400d91baae084f99ab1ecf47661a8990b4e5dd5fe8`, 355.697.322 bytes, gzip íntegro) começou a ser importado em `erpcondominios`, mas a criação de rotina/trigger falhou com MySQL `1419/HY000` porque `log_bin=1` e `log_bin_trust_function_creators=0`. O schema de HML está parcial: 41 tabelas base, zero views, triggers, routines ou events; a contagem de linhas não foi validada. O log bruto foi mantido privado em `/var/log/erpcondominios/h2-restore-failure-20261112/`, root-only.

Como H2 falhou, interromper H3/H4/H5/H6. Não reaplicar o dump, remover objetos, executar migrations ou avançar deploy/DNS sem resolver o gate e obter autorização para qualquer reset/overwrite do schema parcial. Nenhuma migration foi aplicada. A triagem estática das 54 migrations de `origin/main` está em `docs/infraestrutura/hetzner/MATRIZ_MIGRATIONS_H3.md`: 20 estruturais idempotentes, 29 mistas, 4 estruturais não idempotentes e 1 bloqueada/requer revisão. Essas categorias não determinam necessidade; isso continua pendente da comparação com uma restauração H2 aceita. Os quatro arquivos explicitamente protegidos em H3 foram conferidos: os dois de correção de IDs não existem em `origin/main`; `migration_assembleia_tenant_mysql57.sql` e `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` existem e permanecem não aplicados.

Estado: `H1_OK / H2_BLOCKED / H3-H6_NOT_STARTED`. PostgreSQL e ensaios históricos permanecem pausados/preservados; nenhuma virada, deploy ou alteração DNS foi feita.
