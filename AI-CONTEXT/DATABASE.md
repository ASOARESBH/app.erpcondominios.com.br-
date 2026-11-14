# Banco de Dados

Banco de dados relacional MySQL hospedado na HostGator.

## 1. Tabelas Principais (Core)
- `moradores`, `dependentes`, `unidades`, `veiculos`, `visitantes`
- `usuarios`, `sessoes_portal`

## 2. Tabelas Financeiras
- `contas_pagar`, `contas_receber`, `movimentacoes_bancarias`
- `conciliacoes`, `historico_importacoes_ofx`

## 3. Tabelas de Operação
- `hidrometros`, `leituras`, `lancamentos_agua`
- `ordens_servico`, `inventario`, `produtos_servicos`

## 4. Tabelas de Integração & Sistema
- `controlid_dispositivos`, `controlid_eventos_acesso`
- `configuracoes`, `logs_sistema`, `email_log`

## Regras Críticas de Banco
- **Soft Delete**: Registros raramente são deletados fisicamente (`DELETE`). Utiliza-se `ativo = 0` ou `status = 'INATIVO'`.
- **Chaves Estrangeiras**: Integridade mantida via código PHP na maioria dos módulos legacy, mas migrando para Constraints nativas do MySQL nas tabelas novas.
- **Timestamps**: Tabelas padronizadas com `criado_em` e `atualizado_em`.


## 5. Snapshots de Inadimplência

As tabelas `inadimplencia_importacoes` e `inadimplencia_lancamentos` preservam snapshots de PDFs BRCondos por `tenant_id`. Todas as leituras devem filtrar `tenant_id`, inclusive quando `importacao_id`, Gleba, CPF ou chave de comparação forem conhecidos. O PDF original é mantido no BLOB de `tenant_arquivos`, por meio de `tenant_arquivo_referencias`, e não deve ser duplicado em diretórios públicos.

`inadimplencia_importacoes.fonte_sistema`, `layout_id` e `layout_versao` identificam o catálogo selecionado no momento do upload. Os defaults são o parser BRCondos homologado, garantindo compatibilidade com snapshots antigos. Layouts planejados não geram snapshot até que um adaptador seja homologado com amostra real.

A migration compatível com MySQL/MariaDB 5.7 é `sql/migration_inadimplencia_mysql57.sql`. As chaves `chave_comparacao` e `chave_alternativa` são evidências para comparar snapshots; não substituem títulos ou baixas do financeiro operacional.

## 6. Rondas de Vigilante

O módulo de rondas é instalado por `sql/migration_rondas_vigilante_mysql57.sql`, compatível com MySQL/MariaDB 5.7. Todas as tabelas abaixo possuem `tenant_id` e devem ser consultadas com o tenant resolvido no backend pela sessão.

| Tabela | Finalidade | Regras/índices relevantes |
|---|---|---|
| `ronda_rotas` | Agenda de uma rota de ronda | Define horas, intervalo, tolerância, repetições e dias da semana. |
| `ronda_pontos` | Pontos físicos da rota | Possui `token_qr` hexadecimal aleatório, ponto ativo e ordem. O token é segredo operacional e não é devolvido pela API móvel. |
| `ronda_vigilantes` | Vínculo N:N entre rota e `rh_colaboradores` | Só `ativo=1` autoriza a leitura de rota. |
| `ronda_registros` | Leituras efetivamente registradas | Armazena ponto, vigilante, SLA, atraso, GPS opcional e auditoria técnica. O índice `uk_ronda_registro_ponto_ciclo (tenant_id, ponto_id, colaborador_id, ciclo_chave)` impede duplicidade. |
| `ronda_auditoria` | Rastreabilidade administrativa | Registra ação, usuário interno, descrição, dados auxiliares e IP. |

A API móvel calcula `ciclo_chave` como `sha256(tenant_id|rota_id|colaborador_id|YYYYMMDD:indice_do_ciclo)`. O índice de `ronda_registros` inclui também o ponto, de modo que a mesma pessoa pode cumprir pontos distintos da rota no mesmo ciclo, mas não repetir o mesmo ponto. Leituras móveis gravadas nessa tabela já são consumidas pelos dashboards e relatórios administrativos existentes.

## 7. Registros de Acesso

A tabela `registros_acesso` registra os eventos manuais e automáticos de entrada e saída, sempre isolados por `tenant_id`. No lançamento manual, `modo_registro` é `VEICULO` por padrão ou `PEDESTRE` para acessos sem veículo; `vestimenta` (`VARCHAR(120)`, nula) guarda a descrição opcional da roupa da pessoa. A coluna `usuario_liberou` (`VARCHAR(150)`, nula) preserva o nome do operador autenticado que efetuou uma liberação manual, de forma independente do cadastro atual desse usuário. A coluna opcional `idempotency_key` (`VARCHAR(36)`, nula) recebe a chave do formulário, com índice único `uk_registros_acesso_tenant_idempotency (tenant_id, idempotency_key)`; como a coluna aceita `NULL`, registros antigos e origens que não enviam chave não colidem. Eventos automáticos de hardware não preenchem `usuario_liberou`, e registros históricos anteriores à adoção dos campos permanecem com valores nulos. Registros `PEDESTRE` não exigem placa, modelo ou cor e não geram registros de ocupantes.

## 8. Escopo de Ordens de Serviço

`os_usuarios_config` guarda uma configuração por `(tenant_id, usuario_id)`, incluindo
`restritivo_os`. `os_permissoes_departamento` guarda as ações `pode_visualizar`,
`pode_criar`, `pode_editar` e `pode_excluir` por `(tenant_id, usuario_id, departamento)`.
As duas tabelas são criadas/verificadas de forma idempotente pela API de O.S. e nunca
aceitam `tenant_id` vindo do navegador.

A tabela central `departamentos` deve possuir `tenant_id` e a chave única
`(tenant_id, nome)`. A API corrige a chave histórica global `uk_nome` antes de listar ou
salvar a matriz, permitindo que condomínios diferentes usem departamentos com o mesmo
nome. O escopo aplicado às consultas de `os_chamados` combina autoria própria, atribuição
como atendente e assunção no Portal com os departamentos autorizados; a restritividade
reduz o conjunto a esses vínculos pessoais. A unidade `morador_unidade` é o snapshot da O.S.
e o índice composto `idx_os_tenant_unidade (tenant_id, morador_unidade)` atende à listagem
por unidade. A migration idempotente MySQL/MariaDB 5.7 é
`sql/migration_os_indice_unidade_mysql57.sql`; a API também garante o índice no bootstrap.
O diagnóstico de divergências com a unidade atual do morador fica em
`sql/diagnostico_os_unidade_divergente.sql` e é somente leitura.

## 9. URL pública de Projetos

`tenants.projetos_slug` guarda o identificador opcional usado nas rotas públicas
`/projetos/{slug}` e `/projetos/{slug}/{id}`. A coluna é `VARCHAR(60)` com índice
único e aceita `NULL`, permitindo que tenants sem configuração mantenham a rota
legada `/projetos`. A migration canônica é
`sql/migration_projetos_slug_tenant_mysql57.sql`; o backend nunca aceita
`tenant_id` do navegador e resolve o tenant exclusivamente pelo slug cadastrado.


## 10. Configuração de Moradores e Dependentes

`config_moradores_campos` guarda a obrigatoriedade dos campos dos grupos `moradores` e `dependentes` por `(tenant_id, grupo, campo)`. `config_moradores_opcoes` guarda regras operacionais por `(tenant_id, opcao)`, incluindo `dependente_multiplos_veiculos`. Ambas as tabelas preservam o usuário que alterou a regra e são criadas pela migration `sql/migration_config_cadastro_moradores_mysql57.sql`. O backend resolve o tenant pela sessão; nunca usa `tenant_id` enviado pelo navegador.

## 11. Abastecimento

`abastecimento_lancamentos.observacao` é um campo `TEXT NULL` para informações adicionais do abastecimento. O conteúdo é opcional, preserva registros antigos com `NULL`, pertence ao mesmo `tenant_id` do lançamento e é exibido no histórico, relatório HTML/CSV e PDF. A coluna é criada pela migration idempotente `sql/migration_observacao_abastecimento_mysql57.sql`.

## 11. Integridade da tabela de Usuários

`usuarios.id` é a chave primária técnica e deve ser `INT NOT NULL AUTO_INCREMENT`. A migration `sql/migration_corrigir_ids_usuarios_mysql57.sql` repara registros com `id <= 0` e IDs positivos duplicados somente quando o e-mail é único, recria os vínculos tenant-aware dos usuários reparados e cria backups auxiliares antes da alteração. Para a base da Associação, onde os registros inválidos devem iniciar no ID 15, a migration específica é `sql/migration_corrigir_ids_usuarios_a_partir_15_mysql57.sql`; ela atribui IDs sequenciais por nome/e-mail, sem reutilizar IDs ocupados. Os scripts abortam diante de e-mails vazios/duplicados, destinos ocupados, vínculos positivos ambíguos ou backups já existentes; referências históricas com `usuario_id=0` não são atribuídas automaticamente a uma pessoa, para evitar autoria incorreta.


## 12. Lista de Convidados

A tabela `convidados` armazena convites do painel, tenant-aware, com anfitrião `MORADOR`/`DEPENDENTE`, visitante vinculado opcional, documento normalizado, validade, limite e contador de entradas, status, autoria, cancelamento e chave de idempotência. Os índices começam por `tenant_id`; a unicidade da chave é `(tenant_id, idempotency_key)`. `registros_acesso.convidado_id` vincula cada movimento Entrada/Saída ao convite no mesmo tenant e é indexado junto com `tenant_id`. A migration MySQL/MariaDB 5.7 é `sql/migration_lista_convidados_mysql57.sql`.

O schema base `sql/database.sql` e a migration ampliam `registros_acesso.tipo` para manter `Entregador Autorizado` e `Familiar` como categorias próprias, sem convertê-las em Visitante/Prestador. A migration preserva valores existentes em bases legadas. Relatórios e Portal do Morador devem filtrar esses valores sob o tenant da sessão.

`convidados.origem_cadastro` inclui `APP_MORADOR` (pré-cadastro pelo aplicativo) além de `PORTAL_MORADOR`, via `sql/migration_convidados_pre_cadastro_morador_mysql57.sql` (idempotente, preserva os valores do ENUM). Pré-cadastros do morador gravam `cadastrado_por_tipo='MORADOR'`, `cadastrado_por_morador_id` e `cadastrado_por_usuario_id=NULL`; atualizações e cancelamentos feitos pelo morador gravam `atualizado_por_tipo='MORADOR'`. Índice `idx_conv_tenant_autor_morador (tenant_id, cadastrado_por_morador_id, status)` atende o limite de ativos e a listagem do portal.

## 13. Alertas da Unidade

A migration `sql/migration_alertas_unidade_mysql57.sql` cria as tabelas `alertas_unidade`, `alertas_unidade_alvos`, `alertas_unidade_acionamentos`, `alertas_unidade_ciencia_tokens`, `alertas_unidade_ciencias`, `alertas_unidade_historico`, `alertas_unidade_rate_limits`, `alertas_unidade_notificacoes`, `alertas_unidade_lembretes`, `alertas_unidade_preferencias` e `alertas_unidade_cache_ids`. Todas são tenant-aware e têm índices iniciados por `tenant_id`. `alertas_unidade_ciencias` e `alertas_unidade_historico` são append-only pela API; `alertas_unidade_acionamentos` também registra decisões automáticas (`APENAS_AVISAR`, `CIENCIA_PENDENTE`, `BLOQUEADO_AUTOMATICO`) sem atribuí-las a uma ciência humana.

A migration garante `sessoes_portal.tenant_id` quando ausente e, se `notificacoes_morador` existir, acrescenta referências opcionais a `alerta_unidade_id` e `registro_acesso_id`. `alertas_unidade_notificacoes` aplica deduplicação por tenant, alerta, morador e janela de 10 minutos; `alertas_unidade_lembretes` deduplica por tenant, alerta e versão de vigência final. `alertas_unidade_preferencias` guarda `push_ativo` por `(tenant_id,morador_id)`; sem registro, a preferência padrão é habilitada. `alertas_unidade_cache_ids` guarda somente IDs ativos e timestamp por `(tenant_id,unidade)` para cache efêmero de preflight. A rotina cron atualiza `ATIVO → EXPIRADO` e cria inbox/push cerca de 24 horas antes do vencimento, respeitando o controle individual para push sem suprimir o inbox. Não reutilizar tabelas `alertas_acesso_*`, que pertencem ao domínio distinto de alertas administrativos pós-evento.

## 14. RH — Banco de Horas

`rh_banco_horas` é o ledger de créditos/débitos manuais e automáticos. Leituras e mutações sempre incluem `tenant_id`; o colaborador precisa pertencer ao mesmo tenant. O índice composto `idx_rh_bh_tenant_colab_data (tenant_id, colaborador_id, data)` atende extratos/saldos e `idx_rh_bh_lancamento` localiza sincronizações automáticas. `uq_rh_bh_tenant_idempotency (tenant_id, idempotency_key)` impede retries duplicados sem cruzar tenants.

O ledger mantém `tipo` na whitelist `credito`, `debito`, `abatimento`, `pagamento`; minutos são positivos e o sinal é definido pelo helper `api/helpers/rh_banco_horas_helper.php`. Auditoria manual usa `saldo_anterior_min`, `saldo_posterior_min`, `saldo_negativo_confirmado`, `justificativa_saldo_negativo`, `usuario_id` e `ip`; `lancamento_id IS NULL` identifica operação manual e valor não nulo identifica vínculo automático ao ponto. `tipo=''` legado é apenas diagnosticado pela migration, não corrigido automaticamente.

`rh_escala.limite_saldo_negativo_min` é inteiro opcional em minutos; `NULL` significa sem limite. A migration idempotente MySQL/MariaDB 5.7 `sql/migration_rh_banco_horas_saldo_negativo_mysql57.sql` backfill o tenant do ledger pela relação com `rh_colaboradores`, aborta se houver linha órfã e acrescenta campos/índices de auditoria/idempotência. A migration não consulta `INFORMATION_SCHEMA`, para funcionar com usuários de hospedagem sem privilégio de leitura global; idempotência de DDL é controlada por handlers para códigos específicos de coluna/índice duplicados. Fazer backup e validar o relatório de diagnóstico antes de qualquer aplicação em produção.

`rh_escala.politica_extra_excedente` aceita `CREDITAR_TUDO` (padrão) ou `LIMITAR_E_SEPARAR`; `limite_extra_diario_min` tem padrão 120 e serve de alerta diário/teto de crédito apenas na segunda política. `rh_banco_horas` recebe `minutos_apurados`, `limite_aplicado_min`, `regra_aplicada`, `versao_calculo` e `reprocessamento_id`; `tipo='excedente'` não compõe saldo. `rh_banco_horas_reprocessamentos` guarda tenant/usuário/nome, escopo e filtros, hashes, regra/versão, motivo, totais antes/depois, dias divergentes, ignorados, status, IP, horário e se períodos fechados foram explicitamente incluídos.

A migration `sql/migration_rh_banco_horas_mapeamento_mysql57.sql` pressupõe a migration tenant do ledger já aplicada, usa DDL idempotente sem `INFORMATION_SCHEMA` e não reprocessa nem altera automaticamente lançamentos. O ENUM base também preserva `excedente` ao ser reaplicado depois dela. Excedentes são linhas automáticas vinculadas ao lançamento do ponto e não entram no saldo; abatimentos/pagamentos manuais permanecem fora do escopo do reprocessamento.

## 15. Chat-In, Canais e Eventos (2026-11-05)
`sql/migration_chatin_mysql57.sql` cria: `chatin_conversas`, `chatin_participantes`, `chatin_mensagens`, `chatin_presenca`, `chatin_preferencias`, `chatin_bloqueios`, `notificacao_canais_config`, `eventos_condominio`. Todas com `tenant_id` e índices iniciando por ele; só `CREATE TABLE IF NOT EXISTS` (compatível com phpMyAdmin sem acesso a `INFORMATION_SCHEMA`). Detalhes em `CHATIN.md` §3.
`sql/migration_assembleia_tenant_mysql57.sql` adiciona `assembleias.tenant_id` + índice `idx_assembleias_tenant_data` com backfill (criador → `usuarios.tenant_id`; vínculo único em `usuario_tenant`; instalação com um único `tenants`). Idempotente por handlers 1060/1061, sem `INFORMATION_SCHEMA`. Linhas sem tenant ficam invisíveis na API.
Chave opcional `pwa_configuracoes.push_chatin_ativo` = '0' desliga o push do Chat-In no tenant.

## 16. Hetzner HML — estado da restauração (2026-11-12)

A HML usa MySQL 8.4.13 local-only e foi criada para validar o dump de origem sem alterar HostGator/produção. Integridade gzip e checksum do dump foram verificados; digest e cópias permanecem em armazenamento privado, não versionados.

O banco foi restaurado após a correção autorizada de `log_bin_trust_function_creators=ON` e aceito em 12/11: 270 tabelas, 202.609 linhas reconciliadas em 270/270 tabelas com o catálogo do loader, sete triggers, duas procedures, zero definers incorretos, zero divergências e zero erros de importação. As integrações externas foram neutralizadas somente no HML. A evidência e os logs são root-only em `/var/log/erpcondominios/h2-restore-accepted-20261112/`.

Antes de H3 foi criado o backup lógico root-only `/var/backups/erpcondominios/h3-pre-ddl-20261112T200850Z/`; manifesto e hash não são públicos. Após H3, a reconciliação exata por `COUNT(*)` preservou as 270 tabelas de origem e os 202.609 registros; foram acrescentadas cinco tabelas vazias de alertas de acesso, totalizando 275 tabelas base. O delta aplicado contém somente DDL estrutural aprovado, versionado em `sql/deploy/hetzner_cutover_migrations.sql`: cinco tabelas `alertas_acesso_*`; `abastecimento_lancamentos.observacao`; `client_uuid` e índices únicos nas tabelas `leituras` e `leituras_fotos`; e os índices `os_chamados.idx_origem_portal` e `idx_morador_id`. O script é compatível com MySQL 8.4 por guards `INFORMATION_SCHEMA` e SQL preparado; não contém DML ou DROP.

H3 permanece parcial quanto às demais migrations. `assembleias.tenant_id` e `idx_assembleias_tenant_data` já existem, e a contagem de registros sem tenant é zero; nenhum backfill foi executado. A mudança de nulabilidade/índice de `notificacoes_morador` também não foi executada (`protocolo_id` permanece `NOT NULL`; 643 linhas e zero duplicidades na chave candidata). Para migrations mistas, não idempotentes ou com dados/seed pendentes, consultar a matriz H3 e obter autorização antes de qualquer alteração. A migration desativada `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` nunca deve ser executada.

## 17. H4/H6 — aplicação HML por IP e backup (2026-11-12)

O código servido é exatamente `origin/main` em `f60739713e6de3f2473b79ecd14a70fd700e5462`. A aplicação conecta somente ao schema HML `erpcondominios`; MySQL escuta apenas localmente/socket e não foi aberto para rede. O caminho `/api/api_usuarios.php` sem sessão retorna 401 JSON; nenhum cadastro/login funcional ou DML foi executado nesta validação.

A contagem agregada confirmou 275/275 tabelas-base InnoDB. O backup lógico diário usa `mysqldump --single-transaction --routines --triggers --events --hex-blob`, gzip, verificação de checksum e arquivos `root:root 0600`. O timer de backup é 06:15 UTC; recusa iniciar com menos de 10 GiB livres e não expurga cópias. A unit separada de integridade diária valida gzip, SHA-256 e permissões; healthcheck passivo de cinco minutos verifica o marcador de integridade/frescor e não inicia serviços. Evitar DDL concorrente durante o backup.

Os backups estão apenas no mesmo host, sem retenção automática ou cópia off-host; restore drill isolado, armazenamento externo criptografado, política de retenção e alertas externos permanecem pendentes. Testar restauração apenas em alvo temporário novo, nunca sobre o HML.

O delta H3 exato está em `sql/deploy/hetzner_cutover_migrations.sql`. A matriz/resultado por 54 migrations está em `docs/infraestrutura/hetzner/MATRIZ_MIGRATIONS_H3.md` e `docs/infraestrutura/hetzner/H3_RECONCILIACAO_LIVE_2026-11-12.md`. Evidências root-only não devem ser transferidas para Git.


## 18. Banco ativo após import — 2026-11-14

O dump mais recente foi importado no schema isolado `erpcondominios_stage_20261114`, com 275 tabelas. A comparação agregada encontrou 204.965 linhas no novo schema e 202.630 no schema anterior; 27 tabelas têm contagens diferentes. Nenhuma linha de negócio foi exibida. A configuração privada ativa foi comutada atomicamente para o schema novo; sua versão anterior permanece em rollback root-only e o schema `erpcondominios` anterior permanece intacto. PHP CLI como `www-data` e probe temporário Nginx/PHP-FPM confirmaram acesso às 275 tabelas; o probe foi removido.

MySQL 8.4 continua sendo o banco ativo, local-only. PostgreSQL 18 permanece parado e branches/dados `pg/*` seguem preservados. Backup pós-switch e verificação de integridade executados em 14/11 terminaram com sucesso. O dump original e os backups anteriores foram preservados. O hostname ainda depende de DNS e certificado próprio.


## Estado MySQL ativo na Hetzner — 2026-11-14

A aplicação continua conectada pelo arquivo privado fora do webroot ao schema importado mais recente; o MySQL 8.4 é o único banco ativo, local-only. A contagem agregada validada é 275 tabelas. O schema anterior e a configuração anterior permanecem como rollback; PostgreSQL 18, dumps e branches `pg/*` continuam preservados e pausados. Nenhum DML foi executado como parte do DNS/TLS.

Após o switch, foi produzido um backup root-only e validado por `gzip -t`, manifesto SHA-256 e modos/permissões, seguido da atualização do marcador de integridade. O artefato e o hash permanecem privados e não são citados neste documento. Restore drill isolado e cópia off-host ainda são pendências operacionais.
