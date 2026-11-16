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


## 16. Chat-In v2 — configuração e sinais efêmeros (2026-11-14)
`sql/migration_chatin_v2_mysql.sql` adiciona em `chatin_preferencias`: `som_ativo` (TINYINT, 1), `som_tipo` (VARCHAR(20), `classico`), `som_volume` (TINYINT UNSIGNED, 70), `notificacao_navegador` (TINYINT, 0) e `permitir_cutucar` (TINYINT, 1). Em `chatin_presenca`, adiciona `digitando_conversa_id` BIGINT UNSIGNED NULL, `digitando_ate` DATETIME NULL e o índice tenant-first `idx_chatin_presenca_digitando`. `chatin_mensagens.tipo` passa a aceitar `CUTUCAR`, preservando `TEXTO` e `AVISO`; a migration falha fechada se o ENUM tiver outro estado.

`chatin_configuracoes` é uma linha por tenant: disponibilidade, pares de participantes, recursos, `grupos_visiveis_moradores_json`, `filtro_moradores_json`, autoria e horário. Quando `pwa_configuracoes` tem `tenant_id`, o legado `push_chatin_ativo` é copiado tenant a tenant; no esquema antigo sem essa coluna, o valor global é usado como fallback para todos. A reexecução usa `INSERT IGNORE`, portanto preserva configurações já existentes. O catálogo RBAC ganha o módulo `chatin`, ações `visualizar` e `configurar` e grants de `visualizar` em todos os grupos tenant-scoped existentes. Não há equivalente em `sql/postgresql/` nesta branch; PostgreSQL permanece pendente.

`chatin_preferencias` também recebe as CHECK constraints `chk_chatin_som_volume` (0–100) e `chk_chatin_som_tipo` (`classico|campainha|suave`). Antes de qualquer DDL, o preflight verifica definições e valores já existentes; incompatibilidade interrompe a migration sem iniciar as alterações de aplicação. Gravações de preferências têm whitelist de campos, validação de booleanos/volume e são transacionais.

Filtros: o DDL versionado de `moradores` tem `ativo`, mas não campo de tipo titular/proprietário/inquilino. `dependentes` é uma entidade separada com `morador_id`, `parentesco` e `ativo`; parentesco não comprova titularidade ou contrato da unidade. O Chat-In continua listando apenas moradores ativos; filtro de perfil não é presumido.


## 17. Projeto PostgreSQL 18 — portabilidade SQL (2026-11-15)

Código novo deve priorizar SQL comum aos drivers MySQL e PostgreSQL. Diferenças inevitáveis devem ser encapsuladas em helpers de banco com variantes selecionadas por `ERP_DB_DRIVER`; `mysql` continua sendo o padrão. Evitar `DATE_FORMAT`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, crases e outras construções específicas diretamente nos módulos.

`tools/pg_sql_lint.php` recebe uma lista explícita de arquivos PHP/SQL de aplicação e verifica um catálogo inicial de construções não portáveis; não percorre o repositório por conta própria e imprime somente caminho, linha e regra. `NOW()` é aceito como compartilhado; comentários `--` com separador, `#` e `/* ... */` são ignorados. `--comentário` sem separador é marcado como ambíguo entre drivers. Os operadores PostgreSQL `#>`, `#>>` e `#-` permanecem ativos. Não lintar o próprio scanner nem fixtures que contêm dialetos proibidos intencionalmente. `tests/pg_sql_lint_test.php` cobre oito casos sintéticos. O linter é heurístico e não prova equivalência semântica; o resultado deve ser combinado com testes MySQL e PostgreSQL em cada PR.

Este registro do linter não significa que a camada PDO/mysqli, os helpers de compatibilidade ou o pipeline de carga/reconciliação estejam prontos. O estado operacional atual da Hetzner está no §20; o §18 é um snapshot histórico supersedido. Os testes do linter não acessaram banco de produção nem leram/carregaram dump ou dados de negócio.

## 18. PostgreSQL 18 de desenvolvimento na Hetzner — snapshot histórico (2026-11-15; supersedido pelo §20)

Em `2.29.161.106`, o cluster PostgreSQL 18.6 `18/main` foi iniciado após ajuste isolado do perfil de memória; atende somente `localhost`/loopback (`127.0.0.1`, `::1`) na porta 5432. Os parâmetros efetivos são `shared_buffers=512MB`, `effective_cache_size=2GB` e `work_mem=8MB`. O banco vazio `erp_pg_dev` foi criado em UTF-8, dono `erp_owner` (role sem login), timezone `America/Sao_Paulo`, schemas `erp` e `legado`, ambos de `erp_owner`, e collation não determinística ICU `ci` (`und-u-ks-level2`). Verificações em PostgreSQL 18 confirmaram `LIKE` sem distinção de caixa e distinção de acento; os schemas iniciaram sem relações.

A configuração anterior de memória foi preservada em cópia `root:root` modo `0600`; `90-erp.conf` manteve `postgres:postgres` modo `0644`. A amostragem por mais de cinco minutos encontrou `MemAvailable` entre 5.544.357.888 e 5.563.576.320 bytes, PSI `some/full avg10=0.00`, e o cluster permaneceu ativo; a regra de parada por baixa memória não foi acionada.

P1 permanece parcial, mas o clone de referência foi restaurado no MySQL em `erp_pg_source` a partir do backup diário verificado. O preflight confirmou o alvo vazio e collation esperada, hash SHA-256 e gzip íntegros; a importação executou com `nice -n 19`/`ionice -c3`, sem assinaturas de erro MySQL. Resultado: 275 tabelas, zero views, sete triggers, zero procedures/functions/events. `pg_reader@localhost` tem somente `SELECT`/`SHOW VIEW` no schema, sem grants globais; o arquivo de credenciais está `root:root` modo `0600`. Manifesto de checksums de configuração antes/depois passou; smoke local da produção retornou raiz 200, login 200 e API não autenticada 401, e MySQL/Nginx/PHP-FPM permaneceram ativos. O mínimo de memória disponível observado foi 4.644 MiB; a regra de parada do PostgreSQL de desenvolvimento não foi acionada. `erp_pg_source` é uma cópia MySQL de origem para o pipeline, não o banco PostgreSQL de destino. A camada compatível de banco ainda não está integrada; não foram criados FPM/vhost PG (`8443`) nem FPM/vhost MySQL de comparação (`8444`), nem alteradas regras UFW/Nginx. `php8.3-pgsql` está instalado e o FPM atual inclui `pgsql`/`pdo_pgsql` junto de `mysqli`/`pdo_mysql`/`mysqlnd`; há somente um `php8.3-fpm.service`/pool, sem runtime PG isolado. O CLI PHP ainda não carrega os módulos PostgreSQL. A produção `erpcondominios` não foi lida nem alterada.


## 19. P3 — fachada PDO/mysqli incremental (2026-11-15)

O primeiro PR adiciona `api/db/PgsqlMysqliConnection.php`, `PgsqlMysqliStatement.php` e `PgsqlMysqliResult.php`, com 19 testes sintéticos e sem ligar o adapter a `api/config.php`. MySQL continua padrão; não houve mudança de conexão, sessão, login ou RBAC. O adapter não é drop-in: procedimentos `mysqli_*`, `multi_query`/`next_result`, streaming e metadados completos não estão implementados. Resultados são bufferizados e preservam ordem/índices numéricos mesmo com nomes de coluna repetidos. `bind_param` relê referências; `execute($params)` exige lista posicional com cardinalidade exata e não pode ser misturado com `bind_param` no mesmo statement. O contador lexical segue as classes bytewise do scanner PostgreSQL 18 para tags dollar-quote e fronteiras de identificador, inclusive bytes UTF-8 altos, sem confundir `$tag$` dentro de identificadores. Statements fechados ou pertencentes a conexão fechada não executam; falhas limpam estado anterior. `insert_id` exige INSERT direto com `RETURNING ... AS insert_id`; cada execução limpa o valor anterior, sem inferir sequences de triggers.


## 20. Identidade dos bancos e estado P1 na Hetzner (2026-11-16)

O nome `erpcondominios_stage_20261114` é histórico: ele é o schema MySQL ativo de produção desde o cutover de 14/11/2026, promovido pela comutação atômica da configuração privada (conforme André e o runbook “Estado do cutover — 14/11/2026”). A inspeção da pasta de dados mostrou birth time em 2026-11-14 01:44:40 UTC (2026-11-13 22:44:40 BRT); os metadados das tabelas vão de 2026-11-13 22:44 a 2026-11-15 08:54 BRT. Esses horários indicam a criação/alteração de objetos, não necessariamente a promoção. A documentação/registros consultados não identificam o job administrativo que executou o `CREATE DATABASE` original; portanto, a origem operacional exata da criação permanece não confirmada.

| Banco/schema | Papel e estado | Tabelas/objetos observados | Conta/escopo observado |
| --- | --- | --- | --- |
| MySQL `erpcondominios_stage_20261114` | Produção ativa após cutover; “stage” é nome histórico. Dados/metadados de aplicação medem ~455 MiB. | 276 tabelas-base; metadados de criação das tabelas entre 13 e 15/11. | `erp_app@localhost` tem grants de schema; sem privilégios globais, de tabela ou de coluna. A configuração ativa foi testada com o release em `current`: `get_class($conexao)=mysqli`, `SELECT DATABASE()=erpcondominios_stage_20261114`. |
| MySQL `erpcondominios` | Schema antigo de rollback, não produção ativa e não origem de migração. Preservado sem consulta de conteúdo nem escrita nesta etapa. | Não inventariado nesta verificação. | `erp_app@localhost` conserva grants de schema; sem privilégios globais, de tabela ou de coluna. Não usar no pipeline. |
| MySQL `erp_pg_source` | Cópia read-only do backup verificado de produção, origem exclusiva da futura P2. Dump SHA-256 conferido; cabeçalho identifica `erpcondominios_stage_20261114`. | 275 tabelas-base, 0 views, 7 triggers, 0 procedures/functions/events. | `pg_reader@localhost`: `SELECT`/`SHOW VIEW` somente neste schema; sem privilégios globais. As 7 triggers têm `DEFINER=pg_reader@localhost`; não há objeto com `DEFINER=erp_app`. |
| MySQL `erp_hml_mysql` | Cópia independente do mesmo dump para homologação HTTP, sem conexão com produção. | 275 tabelas-base, 0 views, 7 triggers, 0 procedures/functions/events. | `erp_hml_app@localhost`: grants de schema somente neste clone; sem privilégios globais. As 7 triggers têm `DEFINER=erp_hml_app@localhost`; não há objeto com `DEFINER=erp_app`. |
| PostgreSQL `erp_pg_dev` | Banco-alvo isolado de desenvolvimento; ainda sem carga P2. | Schemas `erp` e `legado` presentes; 0 tabelas de aplicação nesses schemas. | `erp_owner` é owner não-login do banco/schemas; o estado de grants da aplicação é separado da conta de produção. |

O dump verificado usado nas duas cópias tem SHA-256 `680f6fbe126c2c9da1764e9c256e7be4b7b568bfe0c29b4c3ab16abbca5ef685` e gzip íntegro. A cópia contém 275 tabelas-base, contra 276 no MySQL ativo; é um snapshot, não réplica em tempo real. O catálogo traz 7 triggers e nenhuma rotina, embora o inventário anterior esperasse duas procedures: localizar o artefato ausente antes de declarar conversão P2 completa. O pipeline/rebuild deve ler exclusivamente `erp_pg_source`, nunca conectar ao schema ativo ou ao rollback e jamais depender de `DEFINER`.

P1 foi preparada em paths/release/pools isolados: o master FPM MySQL de homologação espelha a configuração PHP da produção, mantém `mysqli` nativa e não carrega auto-prepend; o master FPM PostgreSQL não tem `mysqli` e carrega o drop-in via auto-prepend. Nginx `8443` (PostgreSQL) e `8444` (MySQL) têm TLS/Basic Auth, bind exclusivo em loopback e nenhuma regra UFW para as portas; acesso é por túnel SSH. O pool de produção, Nginx de produção, firewall geral, DNS e ambos schemas `erpcondominios_stage_20261114`/`erpcondominios` não foram modificados.

O release ativo verificado é `7c834ff90905857c58431855d02d432cef91963f`; `REVISION` não existe como arquivo, então o identificador veio do nome do diretório imutável. O smoke e os checksums de produção passaram antes/depois: 8 arquivos de configuração sem mudança; raiz/IP 200, login legado 301 para `/`, API sem sessão 401. MemAvailable mínimo observado durante o restore da cópia foi 1.572.864 KiB (limite de parada: abaixo de 1,5 GiB por mais de 5 minutos); amostragem posterior: 4.055.712 KiB. `erp_p3_runtime` foi removido. A matriz HTTP funcional completa, incluindo credenciais sintéticas/rotação/RBAC, ainda não foi executada; portanto P1 está preparada, mas não aceita, e não há autorização para ligar produção ao PostgreSQL, deploy, DNS/cutover ou merge automático.
