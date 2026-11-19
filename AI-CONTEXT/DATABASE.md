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

Este registro do linter não significa que a camada PDO/mysqli, os helpers de compatibilidade ou o pipeline de carga/reconciliação estejam prontos. O estado real do ambiente PostgreSQL está descrito no §18; os testes do linter não acessaram banco de produção nem leram/carregaram dump ou dados de negócio.

## 18. PostgreSQL 18 de desenvolvimento na Hetzner — P1 parcial (2026-11-15)

Em `2.29.161.106`, o cluster PostgreSQL 18.6 `18/main` foi iniciado após ajuste isolado do perfil de memória; atende somente `localhost`/loopback (`127.0.0.1`, `::1`) na porta 5432. Os parâmetros efetivos são `shared_buffers=512MB`, `effective_cache_size=2GB` e `work_mem=8MB`. O banco vazio `erp_pg_dev` foi criado em UTF-8, dono `erp_owner` (role sem login), timezone `America/Sao_Paulo`, schemas `erp` e `legado`, ambos de `erp_owner`, e collation não determinística ICU `ci` (`und-u-ks-level2`). Verificações em PostgreSQL 18 confirmaram `LIKE` sem distinção de caixa e distinção de acento; os schemas iniciaram sem relações.

A configuração anterior de memória foi preservada em cópia `root:root` modo `0600`; `90-erp.conf` manteve `postgres:postgres` modo `0644`. A amostragem por mais de cinco minutos encontrou `MemAvailable` entre 5.544.357.888 e 5.563.576.320 bytes, PSI `some/full avg10=0.00`, e o cluster permaneceu ativo; a regra de parada por baixa memória não foi acionada.

P1 permanece parcial, mas o clone de referência foi restaurado no MySQL em `erp_pg_source` a partir do backup diário verificado. O preflight confirmou o alvo vazio e collation esperada, hash SHA-256 e gzip íntegros; a importação executou com `nice -n 19`/`ionice -c3`, sem assinaturas de erro MySQL. Resultado: 275 tabelas, zero views, sete triggers, zero procedures/functions/events. `pg_reader@localhost` tem somente `SELECT`/`SHOW VIEW` no schema, sem grants globais; o arquivo de credenciais está `root:root` modo `0600`. Manifesto de checksums de configuração antes/depois passou; smoke local da produção retornou raiz 200, login 200 e API não autenticada 401, e MySQL/Nginx/PHP-FPM permaneceram ativos. O mínimo de memória disponível observado foi 4.644 MiB; a regra de parada do PostgreSQL de desenvolvimento não foi acionada. `erp_pg_source` é uma cópia MySQL de origem para o pipeline, não o banco PostgreSQL de destino. A camada compatível de banco ainda não está integrada; não foram criados FPM/vhost PG (`8443`) nem FPM/vhost MySQL de comparação (`8444`), nem alteradas regras UFW/Nginx. `php8.3-pgsql` está instalado e o FPM atual inclui `pgsql`/`pdo_pgsql` junto de `mysqli`/`pdo_mysql`/`mysqlnd`; há somente um `php8.3-fpm.service`/pool, sem runtime PG isolado. O CLI PHP ainda não carrega os módulos PostgreSQL. A produção `erpcondominios` não foi lida nem alterada.


## 19. P3 — fachada PDO/mysqli incremental (2026-11-15)

O primeiro PR adiciona `api/db/PgsqlMysqliConnection.php`, `PgsqlMysqliStatement.php` e `PgsqlMysqliResult.php`, com 19 testes sintéticos e sem ligar o adapter a `api/config.php`. MySQL continua padrão; não houve mudança de conexão, sessão, login ou RBAC. O adapter não é drop-in: procedimentos `mysqli_*`, `multi_query`/`next_result`, streaming e metadados completos não estão implementados. Resultados são bufferizados e preservam ordem/índices numéricos mesmo com nomes de coluna repetidos. `bind_param` relê referências; `execute($params)` exige lista posicional com cardinalidade exata e não pode ser misturado com `bind_param` no mesmo statement. O contador lexical segue as classes bytewise do scanner PostgreSQL 18 para tags dollar-quote e fronteiras de identificador, inclusive bytes UTF-8 altos, sem confundir `$tag$` dentro de identificadores. Statements fechados ou pertencentes a conexão fechada não executam; falhas limpam estado anterior. `insert_id` exige INSERT direto com `RETURNING ... AS insert_id`; cada execução limpa o valor anterior, sem inferir sequences de triggers.

Baseline reproduzido imediatamente antes do diff: linter `tools/pg_sql_lint.php` sobre todos os arquivos PHP rastreados recursivamente em `api/` (receita: `find api -type f -name '*.php'`): 337 arquivos, 1.678 ocorrências em 145 arquivos afetados. O prompt de André mencionou 1.677/142; essa contagem não coincidiu com a execução reproduzível nesta revisão e deve ser reconciliada com seu corpus/commit original. Depois do adapter, o mesmo corpus mais os três novos PHP `api/db/` totalizou 340 arquivos, ainda 1.678 ocorrências em 145 arquivos; os novos arquivos não acrescentaram findings. Esta primeira camada não reduz debt SQL; a meta de redução aplica-se ao PR seguinte e posteriores.


## 20. P3.1b — shim PDO drop-in mysqli em branch isolada (2026-11-15)

Na branch `pg/p3-1b-dropin`, a fachada foi ampliada para instalar aliases globais `mysqli`, `mysqli_stmt`, `mysqli_result` e `mysqli_sql_exception` somente quando a extensão nativa `mysqli` estiver ausente, mais constantes e 26 funções procedurais (20 nomes no corpus amplo versionado, incluindo arquivos de configuração, e 6 wrappers adicionais de charset, BLOB e multi-query). No código de API fora dos bootstraps, o inventário tokenizado encontra 19 nomes de funções mysqli. A contagem PHP tokenizada é 265 parâmetros tipados (`mysqli`: 258; `mysqli_stmt`: 7), 26 usos `instanceof` (`mysqli`: 11; `mysqli_result`: 15) e 520 chamadas procedurais/20 nomes no corpus amplo; há 17 construtores `new mysqli` em arquivos da aplicação. A fachada também implementa os usos OO observados de `send_long_data`, `multi_query`, `more_results`, `next_result`, `store_result` e `character_set_name`. As métricas são inventário estático, não prova de equivalência total da API.

O driver padrão continua MySQL. O runtime PostgreSQL é explicitamente opt-in; o auto-prepend exige configuração privada externa, schema PG explícito e `pdo_pgsql`, e retorna falha sanitizada se indisponível. No FPM, falha fechado se o document root não puder ser verificado; o caminho explícito não recai no arquivo legado e configurações com permissões de grupo/outros são recusadas. O fallback MySQL implícito de `api/config.php` usa o mesmo resolvedor de caminho/permissões e saída inesperada do arquivo invalida o bootstrap sem vazar conteúdo. Os argumentos de `new mysqli`/`mysqli_connect` precisam corresponder ao destino configurado; o shim não permite redirecionamento silencioso. Para PostgreSQL, quando a forma é uma única tupla `INSERT ... VALUES`, a lista de colunas é explícita e omite uma PK serial/identity identificável, o adapter acrescenta `RETURNING <PK> AS insert_id` internamente e oculta o resultado auxiliar, obtendo o ID da própria linha. Não usa `currval()`/`lastval()` para inferir qual linha foi inserida. Multi-row, `INSERT ... SELECT`, `ON CONFLICT`, PK fornecida pelo chamador, lista de colunas implícita e demais formatos ambíguos permanecem sem rewrite e retornam `insert_id=0`, ainda que uma operação parcial afete somente uma linha. `bind_param` e `execute(array)` enviam `null` como SQL NULL explícito. A fixture modela ID retornado 89 e currval posterior 90, mas não executa trigger PostgreSQL real. Multi-query é sequencial, bufferizada e MySQL/PDO-only; falhas intermediárias não desfazem statements anteriores e `CALL`/DDL de stored programs falham fechados. `send_long_data` mantém o conteúdo em stream temporário até `execute`, sem alegar streaming de ponta a ponta.

Linter `tools/pg_sql_lint.php` com receita recursiva e corpus idênticos (`find api -type f -name '*.php'`): SHA base `219ef48f59a3e9f3cc5081f7e3b3f6da1e9097dc`, 340 arquivos, 1.678 ocorrências em 145 arquivos afetados; worktree P3.1b, 347 arquivos (sete PHP de API novos), as mesmas 1.678/145. O shim não acrescentou findings. Os testes desta branch são sintéticos, com PDO falso; 26 testes do núcleo e 37/39 assertions do processo pai no harness sem/com `mysqli` nativa cobrem alias/procedural, SQL-multiple, BLOB, configuração fail-closed, SQL NULL, escape MySQL sincronizado após alteração de `SESSION sql_mode`, RETURNING interno da PK, INSERT multi-row sem ID ambíguo e erro sanitizado; verificações de subprocessos são validadas por exit status/marcadores e não entram no contador. Os testes não substituem os gates HTTP/PHP-FPM nem executam trigger real. Não foram executadas conexões por este shim a engines reais, alteração na Hetzner, deploy ou produção. Portanto, P3.1b é preparação técnica em revisão, não autorização para ativar PostgreSQL nem atestado de portabilidade completa.


Reconciliação da contagem citada na revisão: a busca textual encontra 271 ocorrências de `mysqli $` e 7 de `mysqli_stmt $` fora de testes, mas a tokenização PHP valida 258 parâmetros reais `mysqli` e 7 `mysqli_stmt`; as 13 ocorrências adicionais de texto não são declarações de parâmetro. A cobertura de `instanceof` real é 11 referências à conexão `mysqli` e 15 a `mysqli_result` (26 no total). O inventário amplo do corpus PHP rastreado encontrou 520 chamadas em 20 nomes; no código da API, excluindo os dois arquivos de configuração/bootstraps, são 518 chamadas em 19 nomes. Portanto, 271 é uma contagem textual de matches, não a contagem exata de type hints executáveis. A diferença entre 20 e 26 funções é intencional: seis wrappers extras dão suporte aos métodos OO/compatibilidade, além dos nomes proceduralmente observados.


## 21. P3.2 — portabilidade de login, sessão e RBAC (em revisão)

A branch P3.2 é empilhada sobre `pg/p3-1b-dropin`; o commit base precisa continuar sendo o head publicado da P3.1b até sua revisão/merge. MySQL permanece padrão. PostgreSQL exige seleção explícita por configuração privada válida, schema explícito e `ERP_TRUSTED_TENANT_DOMAINS`. No PostgreSQL, tenant é resolvido pelo servidor a partir de um host válido que corresponda à lista confiável ou de um único vínculo ativo do usuário; porta inválida, host alheio, contexto ausente ou múltiplos vínculos no domínio compartilhado falham fechados. O ramo ambíguo invalida a sessão PHP anterior antes do HTTP 409. Este recorte não aceita `tenant_slug_escolhido`, não retorna um catálogo de seleção ao browser e não adiciona reenvio de senha para escolha de tenant. O seletor ERP/Portal preexistente na `main` permanece inalterado; sua substituição, se aprovada, pertence a PR de produto separada com token temporário emitido e validado pelo servidor. O comportamento MySQL legado não relacionado permanece inalterado.

Nas APIs protegidas que chamam `verificarAutenticacao()` no perfil PostgreSQL, o helper revalida usuário ativo, status do tenant, vínculo autorizado e estado da sessão RBAC; falha de schema/consulta não deve virar sessão válida. `verificar_sessao.php`, por sua vez, não consulta o banco nem revalida usuário/RBAC: verifica a sessão PHP, timeout e coerência host/tenant, não usa `?tenant` como fonte de resolução e rejeita host não confiável ou tenant incompatível, limpando o estado operacional nos casos inválidos. Logins válidos limpam o estado anterior e rotacionam o ID PHP antes de gravar a nova identidade. Somente o SHA-256 do identificador efêmero pode ser persistido em `rbac_sessoes.sessao_hash`; o ID em claro/cookie não deve aparecer em resposta ou logs. Login e renovação Bearer do Portal continuam indisponíveis no driver PostgreSQL (503) até portabilidade e homologação próprias. O MVC legado `SessionController`/`SessionModel` não é o fluxo canônico.

A cobertura P3.2 é contratual/sintética. Os testes de database/adapter executados anteriormente usaram MySQL 8.0.46 e PostgreSQL 16.15, não PostgreSQL 18 nem o schema completo; a matriz HTTP/PHP-FPM contra os clones isolados permanece gate separado. Nenhum resultado sintético autoriza ativar PostgreSQL em produção.

Na reconstrução atual, a suíte conta 27 casos sintéticos do núcleo (os 26 da P3.1b mais um teste `bind_param` com SQL NULL), 56 assertions do processo pai no harness sem `mysqli` nativa e 58 no CLI nativo, 13 cenários sintéticos de bootstrap e 20 testes auth/RBAC. Dois harnesses separados acrescentam cinco cenários do endpoint `verificar_sessao.php` (querystring ignorada com contexto válido, contexto ausente, host não confiável, subdomínio incompatível e subdomínio confiável) e dois da auditoria (IP `NULL` efetivamente ligado e padrão legado com IP). Nos casos inválidos, o harness verifica que `$_SESSION` fica vazia — incluindo identidade, tenant, `_rbac_sessao_id` e `_rbac_cache` — e que o ID de sessão anterior foi aposentado; uma querystring não altera os valores de tenant já vinculados. Os harnesses cobrem precedência do seletor por ambiente, configuração ausente/inválida, aliases divergentes, timezone geográfico inválido, offset literal ou alias `Etc/GMT` fixo, e timezone nulo; o auto-prepend e o bootstrap API testam essa recusa também no perfil MySQL, portas e rótulos DNS inválidos, domínio-base confiável versus Host alheio e bloqueio estático do Host antes do fallback de tenant único tanto no endpoint de login como em `auth_helper`. O novo teste executa o endpoint de sessão em CLI com fixtures isoladas e não constitui homologação HTTP/PHP-FPM. Não foram feitos novos testes com engines reais após a reconstrução. No corpus recursivo idêntico de `api/*.php`, `origin/pg/p3-1b-dropin` tem 347 arquivos e 1.678 findings em 145 arquivos afetados; esta branch tem 350 arquivos e 1.668/142 (redução líquida de 10 findings e 3 arquivos afetados). Entre os 14 PHPs de API alterados, os 7 findings deliberados ficam concentrados em `api/db/DatabaseDialect.php` como variantes MySQL do helper; os outros 13 não introduzem findings. A seleção do driver no helper é exercitada com a facade `mysqli` P3.1b, não apenas com o adapter PostgreSQL interno. `DatabaseTimezone` aceita somente zonas geográficas IANA reconhecidas pelo PHP ou o literal `UTC`, recusando offsets fixos e aliases `Etc/GMT`; o auto-prepend e o bootstrap API falham de modo consistente para ambos os drivers quando o timezone é inválido ou nulo.


## 22. Carga direta de teste MySQL → PostgreSQL (`tools/migracao_pg/carga_rapida_pg.py`, 2026-11-18)

Ferramenta alternativa ao `rebuild.sh` da P2 para colocar dados reais no PostgreSQL de **teste** rapidamente e permitir o teste manual no 8443. Lê o catálogo e os dados de uma cópia MySQL (`erp_pg_source`) dentro de um snapshot `REPEATABLE READ` read-only e recria os schemas `erp`/`legado` no destino. Recusa `erpcondominios` e `erpcondominios_stage_20261114` como origem e só escreve em `erp_pg_dev`, `erp_pg_teste` ou `erp_pg_hml`. Não imprime valores de dados — somente nomes, contagens, tempos e o relatório JSON.

Regras aplicadas (as mesmas aprovadas para a P2): DATETIME→`timestamp(0)` sem conversão; TIMESTAMP lido em UTC e convertido para hora civil `America/Sao_Paulo`; datas zero → NULL (coluna NOT NULL vira nullable e é listada); TIME fora de 00–23h → `interval`; tinyint→smallint; int unsigned→bigint; bigint unsigned→`numeric(20,0)`; ENUM→varchar + CHECK incluindo valores fora da lista já existentes; AUTO_INCREMENT→identity + `setval`; `DEFAULT CURRENT_TIMESTAMP`→`LOCALTIMESTAMP(0)`; `ON UPDATE CURRENT_TIMESTAMP`→trigger genérica `erp.trg_on_update_timestamp(col)` (só atualiza se a linha mudou e a coluna não foi definida no UPDATE, igual ao MySQL); textos com collation `erp.ci`, colunas `_bin` ou com nome de token/hash/senha/caminho com `"C"`; NUL removido de texto (contado); blobs→bytea intactos; coluna gerada STORED traduzida e fora do COPY; índices de prefixo/TEXT→`left(col,n)`; FULLTEXT adiado; UNIQUE que colide sob `ci` vira índice comum e é listado; tabelas de backup (`_backup`, `_bkp_`, `_old`, data `_AAAAMMDD`) → schema `legado`; FKs criadas `NOT VALID` e validadas, órfãos só reportados. Triggers MySQL (7) **não** são criadas — listadas no relatório para tradução manual. Diferença conhecida em relação à P2: o original dos textos com NUL não é guardado em `legado.auditoria_nul`, apenas contado.

Reconciliação por tabela: contagem, soma e máximo de colunas numéricas, soma de bytes de blobs e contagem por `tenant_id`. Saída 0 = tudo bate; 2 = há diferença/falha.

Teste local (sandbox, MySQL 8.0.46 → PostgreSQL 16, fixture sintética `tests/fixtures_carga_rapida_mysql.sql` sem dados reais): 8/8 tabelas reconciliadas, data zero→NULL, TIMESTAMP UTC→SP, `838:00:00`→interval, ENUM '' preservado com CHECK, NUL removido, bytea idêntico, bigint unsigned máximo preservado, coluna gerada recalculando em INSERT, identity continuando após o maior id, busca case-insensitive com acento, collation C mantendo `abc`/`ABC` distintos, FK com órfão ficando NOT VALID, trigger de ON UPDATE com as três semânticas do MySQL. Ainda não executado na Hetzner com o banco real.

### §22.1 Correção — FK `rbac_auditoria.fk_rbac_auditoria_sessao` (SQLSTATE 42804), 2026-11-18

Causa raiz: o script mapeava `bigint unsigned` com AUTO_INCREMENT para `bigint` (identity) e `bigint unsigned` sem AUTO_INCREMENT para `numeric(20,0)`. Em `rbac_auditoria.sessao_id` (`numeric(20,0)`) → `rbac_sessoes.id` (`bigint`) o PostgreSQL recusa a FK por tipos incompatíveis (numeric e bigint não estão na mesma família de operadores btree). Correção: todo `bigint unsigned` vira `bigint` + `CHECK (>= 0)`; a pré-varredura só usa `numeric(20,0)` quando existe valor acima de 9.223.372.036.854.775.807 (listado em `bigint_acima_do_limite`). FKs não criadas agora registram no relatório os dois tipos envolvidos. Fixture ampliada com o par `rbac_sessoes`/`rbac_auditoria`: 11/11 tabelas, FK validada.

**Atenção para o pipeline versionado P2:** `tools/migracao_pg/type_mapping.py` (branch `pg/integracao`) mapeia `bigint unsigned` → `NUMERIC(20,0)` incondicionalmente e reproduzirá o mesmo 42804 no `rebuild.sh`. Deve adotar a mesma regra (bigint + CHECK, numeric só com evidência de valor acima do limite, e mesmo tipo dos dois lados de toda FK).

### §22.8 Carga rápida alinhada ao contrato v12 (Base64), 2026-11-19

`tools/migracao_pg/carga_rapida_pg.py` passou a gravar `tenant_arquivos.conteudo` como Base64 canônico (`base64.b64encode`, sem quebras de linha) em `TEXT COLLATE "C"`, igual ao contrato v12 do pipeline P2. Os demais binários continuam `bytea`. Um valor `longblob` diferente nessa coluna aborta a carga. A reconciliação dessa coluna compara a soma de bytes após `decode(...,'base64')` e um hash SHA-256 encadeado (SHA-256 por linha, em ordem de PK) calculado no MySQL e no PostgreSQL. Fixture: NUL, vazio, NULL e 600 KB com bytes 0x00/0xFF reconciliados; uma linha adulterada é detectada (`hash_base64` + `bytes`). Assim, o caminho alternativo da virada P5 continua compatível com o código PHP v12 (adapter Base64). O banco `erp_pg_dev` carregado em 19/11 pela versão anterior tem essa coluna em `bytea`: não instalar o release v12 no 8443 sem recarregar.
