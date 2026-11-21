# Changelog

Registro de alterações significativas no ERP.

## [Unreleased]

### Projeto PostgreSQL P2 — carga do rebuild divergente (2026-11-21)
- `rebuild.sh development`: 276/276 tabelas, 213.984 linhas e zero falhas de cópia; duração total 116,145 s.
- Reconciliação `DIVERGED` (exit 78): 14 métricas PASS, duas divergentes (`row_hash_multiset`, `tenant_row_hashes`) em `monitoramento_eventos_hub`; contagens 100/100. Sem SQLSTATE e sem diagnóstico por coluna no relatório. Aceite interrompido; sem nova carga ou reconciliação.
- Produção, rollback, `main`, DNS, `current` do 8443 e origem `erp_pg_source` permaneceram intocados.

### Projeto PostgreSQL P2 — corrige matching de constraints FK no reconciliador v7 (2026-11-20)
- O report privado do load registra 11 FKs `VALIDATED`; o reconciliador v6 reportou 0/11 presentes. Causa confirmada estaticamente: DDL/loader geram nomes PostgreSQL determinísticos e o reconciliador os comparava com os nomes MySQL originais. O contrato v7 agora deriva o mesmo nome gerado. O report anterior continua `DIVERGED` e não houve verificação nova no target.
- O load v12 executou o script `40_business_triggers.sql` do bundle após COPY; não houve reinstalação separada após a reconciliação, harness `--installed` ou neutralização das integrações.
- Hash por linha/tenant de `monitoramento_eventos_hub` segue divergente e sem causa-raiz isolada. Retry de uso único consumida: sem nova consulta, reconciliação, carga ou ações pós-carga sob autorização anterior. Fix publicado em `ea0d4d7600e16edb33a57c4da1ab9681aa044667`, ainda sem release/validação no target.
- Testes focados de reconciliação 56/56 e suíte P2 282/282; `py_compile`, `bash -n` e `git diff --check` PASS.

### Projeto PostgreSQL P2 — retry v12 divergente, sem aceite (2026-11-20)
- A única reconciliação read-only autorizada terminou `DIVERGED`: 276/276 contagens de linhas iguais, 13 métricas PASS e 3 divergentes. Hash por linha/tenant diverge em uma tabela; a métrica FK divergiu, mas o report do load registra 11/11 `VALIDATED`; análise estática aponta provável falso divergente por mismatch de nomes de constraints. O fix local ainda não foi verificado no target. A carga permanece não aceita; a retry de uso único foi consumida.
- Não houve causa-raiz comprovada para o hash divergente. O quick-loader `5572f06` permanece NO-GO; `erp_pg_teste` não foi criado. Triggers pós-carga, neutralização, smoke de arquivos/imagens e ativação do release 8443 não ocorreram; P5 está NO-GO.
- Os subprocessos medidos somam 102,139 s, mas não constituem duração ponta a ponta (restore/preflight e gates pós-carga não completados, resultado divergente). Produção MySQL, rollback, `main`, DNS e banco definitivo permaneceram intocados.

### Projeto PostgreSQL P2 — correção de identidade, retry v6 e bloqueio de rebind em load (2026-11-20)
- A carga v12 existente em `erp_pg_dev` permanece committed (276 tabelas/213.984 linhas, sem falhas de cópia), mas ainda não é aceita: a reconciliação inicial parou antes de comparar tabelas. A investigação read-only confirmou MySQL 1064 na query de identidade antiga por alias reservado; o alias foi trocado para `account_name` e passou em teste read-only contra o clone. Contrato do reconciliador atualizado para v6.
- Fechados localmente dois achados P1: o SHA do load committed agora coincide entre produtor/consumidor de rebind (`e0fbaf1`), e bundles rebound levam marcador `RECONCILIATION_ONLY`, bloqueado em `run_load` antes de conexão/DDL/COPY. A seleção de desenvolvimento também distingue carga normal de retry com evidência.
- Validação integral local: 282 testes P2; codec PHP, quatro `php -l`, linter SQL dos quatro PHPs, 8 cenários do linter, `py_compile`, `bash -n` e `git diff --check` PASS. Commit/push e nova release ainda pendentes; nenhum rebind/retry foi executado.
- Auditoria estática do quick-loader exato `5572f06` encontrou incompatibilidades com TIME v3, Base64 canônico e reconciliação binária por linha. Comparação equivalente está bloqueada; não criar/preencher `erp_pg_teste` nem declarar paridade. Triggers/neutralização, troca de `current` 8443 e smoke de anexos/imagem não ocorreram.

### Projeto PostgreSQL P2 — provenance de retry, gate exato de catálogo e rebind semântico (2026-11-19)
- A carga versionada v12 foi committed em `erp_pg_dev`: 276 tabelas, 213.984 linhas e zero falhas de cópia. O loader levou 28,171 s; a reconciliação inicial falhou cedo com `DATABASE_QUERY_FAILED`, sem SQLSTATE e sem tabela comparada. Cleanup foi confirmado; carga não repetida e P2 não aceita.
- Revisão adversarial impediu publicação do retry inicial, que poderia reexecutar status terminal/divergente. A implementação local separa `--retry-read-only`, valida apenas report committed completo/cleanup confirmado e bloqueia qualquer repetição pelo marker persistido antes das conexões. O marker vincula o hash do report à release e bundle original, incluindo hash do report DDL e provenance de gerador, scripts, collation e role. O report legado sem `legacy_schema` só é aceito com a proveniência histórica específica, sem alteração do arquivo; novos reports passam a registrar esse campo.
- O novo código preserva somente SQLSTATE validado e lado/tabela técnica da consulta com falha, sem mensagem bruta. `catalog-rebind` valida report e bundle anteriores, hash integral do report, snapshot privado e equivalência semântica de report/corpos SQL; não conecta a bancos, não lê credenciais nem altera estado global. Corrigida a aridade do subcomando no runner. O preflight exige o conjunto exato das 276 tabelas BASE TABLE, com regressão para lista divergente de igual tamanho; a captura não altera temporariamente `BUNDLE_ROOT`. Testes locais: 277 P2; gates PHP/SQL/sintaxe PASS. Commit/push concluído em `1e11907`; faltam release imutável, rebind, preflight read-only da seleção e uma tentativa de reconciliação.
- Nenhum retry, quick-loader/comparação, neutralização pós-carga, smoke de anexos/imagem ou troca do `current` 8443 executado. Produção, `main`, DNS e P5 intocados.

### Projeto PostgreSQL P2 — config runtime allowlisted no guard de release (2026-11-20)
- Release inativa `c403689` foi instalada fora do `current`. A primeira `catalog-bundle` foi barrada antes da conexão por `RELEASE_FILE_NOT_ROOT_CONTROLLED` (exit 69): o guard não admitia a configuração runtime protegida `erp-pg:erp-pg 0600`.
- Ajuste local concede exceção somente a `api/config.php` com path/owner/group/mode exatos; todos os outros arquivos continuam root-controlled. Nenhum source/target foi alterado; `current` 8443 permaneceu intocado. Suíte P2: 277 testes; gates PHP/SQL/sintaxe PASS. Aguarda commit e release antes de nova captura.

### Projeto PostgreSQL P2 — runner protegido para captura catálogo→bundle v12 (2026-11-19)
- Adicionado `development catalog-bundle` ao `rebuild.sh`: captura somente de `erp_pg_source`, profile cutover recusado, lock compartilhado, release/manifest root-only, `nice`/`ionice` e memória mínima contínua de 1,5 GiB.
- Bundle gerado em staging privado; só é promovido depois de fingerprints, schema/grants, TIME v3, manifest Base64 único (`tenant_arquivos.conteudo` → `TEXT COLLATE "C"`), 276 tabelas, 11 FKs, 7 triggers, zero reviews e `validate_bundle` passarem. Marker privado vincula o bundle à release; preflight/load development exigem esse vínculo.
- Revisão adversarial: booleanos estritos, FK unsupported bloqueada, promoção atômica e tentativa isolada. Validação local: 246 testes P2; codec PHP, 4 `php -l`, linter SQL em 4 PHPs, 8 cenários, `py_compile`, `bash -n` e `git diff --check` PASS.
- Este registro cobre a implementação; não a execução remota. Bundle, preflight, load, quick-loader, triggers pós-carga, neutralização e ativação do 8443 permanecem pendentes até as etapas seguintes autorizadas.

### Projeto PostgreSQL P2 — catálogo v12/Base64 e TIME v3 (2026-11-19)
- Implementada a decisão de André: somente `tenant_arquivos.conteudo` é armazenada como Base64 canônico em PostgreSQL `TEXT COLLATE "C"`; outros binários seguem `BYTEA` byte a byte. Loader, DDL e reconciliador exigem manifest exato, preservam e validam bytes/tamanho/SHA-256 lógicos; adapter PHP mantém bytes no MySQL e decodifica de forma estrita no PostgreSQL. Sem mudança no contrato HTTP.
- Policy TIME v3: `TIME(0)` para valores civis sem fração irrepresentável; `INTERVAL` para negativo/estendido; fração não representável é review bloqueante, nunca truncada. Expressões/defaults não mapeados e NUL em texto continuam fail-closed.
- Revisões adversariais fecharam o bypass de catálogo sem `binary_storage_required=true`: helper e gerador DDL exigem o marker e recusam tabela/coluna ausente, manifest divergente ou `column_type` bruto incompatível, com regressões dedicadas.
- Corrigida a evidência textual do preflight para descrever Base64 canônico na coluna aprovada e `BYTEA` nas demais, sem confundir representação com normalização lógica.
- Validação local final: 232 testes P2; harness codec PHP; sintaxe nos quatro PHPs tocados; linter SQL nos quatro PHPs + 8 testes; `py_compile`, `bash -n` e `git diff --check` aprovados. A auditoria de código encontrou apenas os endpoints canônicos como leitores do payload; `api_documentos.php` delega ao helper de escrita e os outros consumidores de imagem/documento encaminham ao endpoint canônico.
- Código e contexto v12 publicados somente em `pg/triggers-tenant-scoped`; release imutável desse SHA permanece fora de `current`. Nenhum bundle/preflight/carga v12, reconciliação, quick-loader, triggers pós-carga ou neutralização aconteceu. `erp_pg_dev`, source, MySQL ativo/rollback, `main`, DNS, runtime `current` e target definitivo não foram alterados. P2 ainda não aceita; P5 não executada.

### Projeto PostgreSQL P2 — preflight v9 aprovado; carga bloqueada por payload binário (2026-11-19)
- Publicado e instalado fora do runtime `current` o release imutável `dd51295185239c326bebf6c913332461368bf0b1`. Suíte P2: 219/219; `py_compile`, `bash -n` e `git diff --check`: PASS. Catálogo v9 e bundle privado: 276 tabelas, 0 views/routines/events, 0 reviews, `executable_complete=true`; preflight development somente leitura PASS com source/bundle alinhados (213.984 linhas agregadas).
- Pré-scan textual de 1.406 colunas encontrou 0 células com NUL e 0 divergências entre os métodos; TIME: 16 colunas de relógio e 0 de duração estendida. O scan binário detectou NUL real em `tenant_arquivos.conteudo` (`longblob`): 516 de 518 células não nulas, 3.347.652 bytes NUL em 206.367.630 bytes examinados; SQL binário e leitura Python concordaram. Por gate de preservação de payload binário, nenhum `rebuild.sh load` nem quick-loader em `erp_pg_teste` foi executado; a carga, comparação com quick-loader, reinstalação de triggers, neutralização pós-carga e duração ponta a ponta ficam pendentes da decisão e de adaptação byte-exata.
- `erp_pg_dev` não foi alterado; `erpcondominios_pg` segue vazio e as roles cutover NOLOGIN. Source, MySQL ativo/rollback, `main`, DNS e runtime `current` permaneceram intocados. P2 segue bloqueada; P5 permanece planejamento.

### Projeto PostgreSQL P2 — catálogo v9, defaults/TIME e target cutover preparado (2026-11-19)
- André autorizou a cópia literal de defaults textuais, inclusive vazios/sensíveis; expressões e funções sem mapeamento continuam fail-closed. Catálogo v9/DDL 2.4.0 armazenam o default integral no snapshot e no SQL gerado; esses artefatos podem conter configuração/credenciais, são criados somente em diretório privado 0700 com arquivos 0600 e nunca podem ser commitados, logados ou tratados como sanitizados.
- MySQL TIME usa `TIME(p)` quando os dados e default estão em faixa civil; dados negativos/estendidos ou default estendido selecionam `INTERVAL`. O catálogo guarda flags agregadas e reporta nomes técnicos, sem valores. O alvo quick-loader `time` legado é uma política distinta e não deve ser usado para declarar equivalência com P2.
- Perfil cutover foi restringido a `erpcondominios_pg`, `erp_p2_cutover_loader` e `erpcondominios_app`; development continua fixo em `erp_pg_dev`/`erp_p2_loader`/`erp_app`. O banco final foi preparado vazio com owner `erp_owner`, UTF8, locale `en_US.UTF-8` e timezone `America/Sao_Paulo`; as duas roles próprias são `NOLOGIN` e não há config de target, approval file ou one-shot marker. Esse target não está pronto para preflight até ativação controlada do login do loader na janela. Nenhum dado foi carregado.
- A decisão contingencial de 20/11 às 22:00 BRT está documentada: quick-loader aprovado (base `84bd3f2`) seguido de reconciliação P2 read-only. A inspeção do código mostrou que requer um runner derivado com allowlist para o target definitivo, suporte de auditoria NUL e adapter de evidência read-only testado; portanto não é executável no estado atual e implica NO-GO se esses gates não forem concluídos.
- Nenhum `rebuild.sh load`, reconciliação live, reinstalação de triggers, cutover, troca de driver/config, DNS, produção MySQL ou `main` foi alterado/executado. A suíte P2 passou 219/219; `py_compile`, `bash -n` e `git diff --check` passaram.

### Projeto PostgreSQL P2/P5 — allowlist clone-only e runner cutover (2026-11-19)
- André aprovou `SELECT`, `SHOW VIEW` e `TRIGGER` somente em `erp_pg_source`. `TRIGGER` concede capacidade DDL, aceita exclusivamente na cópia descartável; leituras de dados continuam em transação `READ ONLY`. Qualquer privilégio global efetivo, outro escopo ou permissão extra segue bloqueado. Sem grants `SHOW ROUTINE`/`EVENT`, o coletor não lê definições dessas classes e exige prova administrativa agregada de zero routines/events antes/depois.
- O catálogo foi elevado a v7 e o gerador a 2.2.0. O `rebuild.sh` distingue `development` (`erp_pg_source` → `erp_pg_dev`) e `cutover` (source sempre clone; target PostgreSQL local somente após provisionamento, aprovação root-only e marcador one-shot). `cutover` não foi executado. A suíte sintética atual passou 203/203; sintaxe Bash/Python e `git diff --check` passaram.
- O fluxo P5 foi documentado como congelamento → dump final verificado de `erpcondominios_stage_20261114` → restauração somente em `erp_pg_source` → `rebuild.sh cutover` → reconciliação 100% → troca de driver/config → smoke/liberação. A implementação do modo de manutenção e o banco definitivo ainda são gates operacionais; nenhum foi alterado/provisionado nesta rodada.
- O backup diário candidato foi validado por SHA-256, `gzip -t` e cabeçalho do schema ativo. O refresh de `erp_pg_source` passou com 276 tabelas, 0 views, 7 triggers e 0 routines/events; os definers dos sete triggers são `pg_reader@localhost`, sem definers de produção. A restauração durou 30 s e observou mínimo de 3.411.900 KiB de memória disponível; esse intervalo mede apenas o restore, não a execução ponta a ponta.
- O bundle v7 gerado tem 74 reviews (72 `TEXT_DEFAULT_REVIEW_REQUIRED`, 2 `MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED`) e `executable_complete=false`. O preflight do `rebuild.sh development` fechou antes de qualquer mutação; nenhum load/reconciliação foi executado. `erp_pg_dev` continua com a carga rápida anterior. Comparação read-only encontrou 213.984 linhas no source vs. 213.515 no target, 13 tabelas com contagens diferentes e 16 diferenças de tipo `interval` vs. `time without time zone` em 3.407 colunas; snapshots e métodos diferem, portanto não é prova de equivalência do rebuild. P2 segue bloqueada e não aceita para produção.

### Projeto PostgreSQL P2 — recarga após fix BIGINT unsigned (2026-11-19)
- Recarregado somente `erp_pg_dev` com o commit aprovado `84bd3f2329238330740b2e2be4bf9abc4b889a39`: 276/276 tabelas, 213.515/213.515 linhas, zero divergências/falhas e 11/11 FKs validadas, sem órfãos. Menor `MemAvailable` observado: 2.862.960 KiB; 33,7 s internos. Não houve escrita em `erp_pg_source`; uma contagem agregada em transação `READ ONLY` confirmou 276 tabelas, 7 triggers, zero routines e 213.515 linhas. A conta contém `TRIGGER` para inspeção de metadata e, por isso, não tem capability estritamente read-only de DDL.
- O mapeamento P2 equivalente foi publicado em `a2e8b7c6cb3b133b5e9b3f0d4f70694e6f33fb5e`; catálogo v4 pre-scan persiste somente booleano de faixa e alinha a FK `rbac_sessoes`/`rbac_auditoria`. 193/193 testes sintéticos passaram.
- Histórico do primeiro preflight v4, anterior à decisão atual: `SOURCE_ACCOUNT_GRANTS_NOT_READ_ONLY` bloqueou a execução porque `pg_reader` tinha `TRIGGER` no escopo do schema. Esse artefato v4 não foi executado; a decisão posterior e o catálogo v7 estão registrados na entrada acima.
- As sete triggers tenant-scoped foram reinstaladas em `erp_pg_dev`; suas definições ficaram enabled. O harness original pós-recarga parou no guard fail-closed de nomes preexistentes. Um harness temporário sem DDL validou os sete comportamentos: 10/10 PASS; fixtures revertidas, triggers persistentes preservadas e 11/11 sequences restauradas. Esse ajuste do harness não foi versionado. Integrações de saída seguem neutralizadas no target.
- Checksums do baseline pré-operação mantiveram-se iguais (45 arquivos); smoke produtivo: `/` 200, login canônico 301, API anônima 401; serviços ativos. Monitoramento sanitizado de 15 minutos no 8443 terminou com zero SQL_NAO_TRADUZIDO, PHP fatal, erro PostgreSQL ou erro Nginx; MemAvailable permaneceu entre 3.405.428 e 3.426.164 KiB. Nenhuma escrita em produção/rollback/source, alteração de `main`, DNS, cutover ou deploy produtivo. P2 permanece BLOQUEADA/NÃO ACEITA; aguarda-se o resultado funcional do teste autenticado manual no 8443.

### Projeto PostgreSQL P2 — ensaio controlado (2026-11-18)
- Atualizado o clone MySQL read-only `erp_pg_source` do backup diário validado de `erpcondominios_stage_20261114`; o ensaio separado carregou `erp_pg_dev` com 276/276 tabelas e 213.515/213.515 linhas. A carga usou `carga_rapida_pg.py`, não o `rebuild.sh` versionado; portanto a equivalência do pipeline P2 ainda não foi demonstrada.
- Resultado não aceito: dez FKs validadas sem órfãos, uma FK não criada por incompatibilidade de tipos (`rbac_auditoria.fk_rbac_auditoria_sessao`, SQLSTATE `42804`). Exceções agregadas incluem 439 datas zero, 214 ENUMs fora da lista e dois relaxamentos NOT NULL; nenhum valor de linha foi registrado no changelog.
- Implementado suporte fail-closed para generated columns e falhas parciais, incluindo `lancamentos_agua.consumo` calculado, além da tradução pós-carga das sete triggers segundo a política tenant-scoped aprovada por André. Harness PostgreSQL: 10/10; aplicação persistente somente em `erp_pg_dev`; as verificações agregadas de SMTP/e-mail, FCM, ControlID, câmeras/monitoramento e fila ControlID ficaram zeradas somente nesse target.
- Preparado e ativado o release imutável `ca4135c0b9cc891dcb80773e9337ca642dc05032` somente no vhost loopback 8443, com TLS, Basic Auth e sem regra UFW; smoke HTTP base passou. Teste autenticado manual de André permanece pendente. Produção, current produtivo, DNS, cutover e `main` não foram alterados.
- Suíte sintética P2: 190 testes aprovados com a venv de dependências do pipeline. P2 continua BLOQUEADA / NÃO ACEITA para produção até resolver a FK, repetir a carga com o bundle/rebuild versionado, reconciliar tudo, revisar as exceções e obter o teste manual no 8443.

### Projeto PostgreSQL P2 — BIGINT unsigned (2026-11-19)
- Portada para o pipeline versionado a regra aprovada em `pg/carga-rapida`: `bigint unsigned` usa PostgreSQL `bigint` com `CHECK (>= 0)` quando os valores cabem no limite assinado e `numeric(20,0)` somente quando a pré-varredura encontra valores maiores; as extremidades unsigned ligadas por FK recebem tipo-alvo alinhado.
- Catálogo elevado a v4 e snapshot contém somente sinal booleano agregado, sem máximos ou linhas. Regressão sintética do par `rbac_sessoes` → `rbac_auditoria`, carga não executada ainda pelo commit nesta anotação. Suíte total 193/193, `compileall` e `git diff --check` passaram.
- Código publicado no commit `a2e8b7c6cb3b133b5e9b3f0d4f70694e6f33fb5e` da branch `pg/triggers-tenant-scoped`, PR #26 contra `pg/integracao`. Nenhuma mudança em `main`, produção, DNS ou cutover.


### Projeto PostgreSQL P3.1b — shim mysqli sobre PDO (branch isolada, 2026-11-15)
- Adicionado bootstrap opt-in via `auto_prepend_file` para aliases mysqli e 26 funções procedurais, sem carregar junto da extensão nativa. MySQL continua default; PostgreSQL exige perfil próprio e schema, enquanto argumentos de conexão divergentes são rejeitados.
- A superfície observada inclui BLOB com `send_long_data`, charset e multi-query sequencial MySQL/PDO; resultados são bufferizados, `CALL`/DDL de stored programs falham fechados, e multi-query não oferece rollback dos statements já executados.
- `insert_id` PostgreSQL explícito vem do `RETURNING` solicitado; para uma tupla `INSERT ... VALUES` com lista explícita que omite uma PK serial/identity identificável, o adapter acrescenta `RETURNING <PK> AS insert_id` internamente e oculta a coluna auxiliar. O ID é, assim, o da própria linha, não o avanço de `currval()`/`lastval()`; o teste sintético modela ID 89 da linha e estado final 90 após consumo adicional da mesma sequence. Multi-row, `INSERT ... SELECT`, `ON CONFLICT`, PK fornecida pelo chamador e lista de colunas implícita ficam sem rewrite e retornam zero. Bindings `null` usam `PDO::PARAM_NULL`. Erros do bootstrap são sanitizados; tanto o caminho externo explícito quanto o fallback MySQL legado passam pelo resolvedor privado, e output inesperado da configuração é descartado e invalidado.
- `real_escape_string` MySQL usa o SQL mode sincronizado com a sessão: no `NO_BACKSLASH_ESCAPES`, duplica aspas simples, preserva barras invertidas e rejeita byte NUL; mudanças via query direta ou prepared statement invalidam o estado e exigem releitura antes do próximo escape; multi-query com referência a `sql_mode` é recusada antes de executar o lote; nos demais modos, mantém `PDO::quote`.
- QA local: 26/26 testes unitários sintéticos P3.1 core; harness drop-in com 37 assertions do processo pai sem mysqli nativa e 39 no CLI normal, incluindo recusa da extensão nativa; checagens dos subprocessos usam exit status e marcadores PASS, não são somadas ao contador. O fake cobre o efeito de trigger consumindo a mesma sequence, mas não executa trigger PostgreSQL real. Sem conexão por este shim a engines reais, sem PHP-FPM/HTTP, sem mudanças na Hetzner ou produção.
- Linter em corpus idêntico `api/*.php`: 340 arquivos/1.678 ocorrências/145 arquivos afetados no SHA base `219ef48f59a3e9f3cc5081f7e3b3f6da1e9097dc`; worktree 347 arquivos e o mesmo total 1.678/145. Os sete PHP novos não aumentaram findings.
- Esta branch prepara o runtime, mas não é prova de compatibilidade integral nem autorização de ativação/deploy. O gate de homologação HTTP/PHP-FPM isolada e o teste MySQL 8444 ainda não foram executados.
### Projeto PostgreSQL P1 — Hetzner (parcial, 2026-11-15)
- Iniciado o cluster PostgreSQL 18.6 apenas em loopback; preparado banco vazio `erp_pg_dev` com schemas `erp`/`legado`, ICU `ci` e perfil de memória 512 MB/2 GB/8 MB.
- Backup root-only da configuração PostgreSQL antes do ajuste; monitoramento inicial >5 min manteve memória disponível acima de 5,5 GB e PSI `avg10=0.00`.
- O backup diário foi validado e restaurado exclusivamente no schema MySQL `erp_pg_source` (275 tabelas, 7 triggers, 0 views/routines/events naquele snapshot); `pg_reader` usou acesso de dados read-only e credencial root-only 0600. Revisão posterior confirmou que `TRIGGER` também permite DDL sobre triggers no clone, razão pela qual o preflight P2 agora falha fechado até revisão do contrato. Checksums de configuração e smoke da produção passaram; produção permaneceu intacta.
- P1 segue parcial: nenhuma carga foi feita no PostgreSQL, e a camada de compatibilidade integrada, FPM isolado e vhosts 8443/8444 ainda não existem. Sem alteração de Nginx/UFW/DNS nesta restauração.

### Projeto PostgreSQL P3 — primeiro PR (2026-11-15)
- Adicionada fachada OO PDO PostgreSQL em `api/db/` com wrappers mysqli parciais e 19 testes unitários sintéticos. Após revisão, os resultsets preservam posições mesmo com rótulos repetidos; estados de resultado/insert_id são limpos; statements fechados ou com conexão encerrada não executam; cardinalidade e modo de binding são validados.
- `insert_id` só usa `RETURNING ... AS insert_id` em INSERT direto; sem alias não há inferência por LASTVAL, que poderia refletir sequence de trigger.
- O scanner de `param_count` segue as classes bytewise do lexer PostgreSQL 18 para tags dollar-quote e fronteiras de identificador, inclusive bytes UTF-8 altos; testes cobrem tags Unicode/emoji, caixa exata e dígitos.
- Linter antes/depois medido sobre todos os arquivos PHP rastreados recursivamente sob `api/` (`find api -type f -name '*.php'`): 1.678/145 em 337 arquivos → 1.678/145 em 340 arquivos (incluídos três wrappers novos, sem findings). O baseline informado 1.677/142 não foi reproduzido e está documentado para reconciliação.

### Projeto PostgreSQL P3 — segundo PR: login, sessão e RBAC (em preparação, 2026-11-15)
- Integrado seletor de driver fail-closed ao bootstrap P3.1b: instalação sem configuração permanece MySQL; PostgreSQL só é usado por opt-in privado explícito com schema definido. Nenhum ambiente de produção foi ativado ou alterado.
- Portado o caminho administrativo ERP para a fachada PDO/PostgreSQL em preparação; tenant é resolvido pelo host do servidor validado contra `ERP_TRUSTED_TENANT_DOMAINS` ou vínculo ativo único. Portas inválidas, domínio fora da lista, contexto ausente, múltiplos vínculos ou falha de validação não criam sessão operacional; o ramo HTTP 409 invalida a sessão anterior e `super_admin` não usa tenant 1. A P3.2 não implementa seleção de tenant nem aceita slug/ID do browser; eventual seleção será PR de produto separada com token temporário server-side. Guards preservam IDs de usuário legados zero em sessão/RBAC. Schema/sessão RBAC incompleto ou erro de metadata não habilita permissões.
- Nas APIs protegidas ERP PostgreSQL que passam por `verificarAutenticacao()`, o helper revalida usuário ativo, status do tenant, vínculo e sessão RBAC; sessão revogada falha fechado. `verificar_sessao.php` apenas valida sessão PHP, timeout e coerência host/tenant, sem consulta ao banco ou revalidação de usuário/RBAC. `verificar_sessao_completa.php` não expõe o PHP `session_id` e suas mensagens de log não carregam token, nome ou texto bruto de exceção.
- O perfil efetivo aplicado à sessão respeita a hierarquia global/local existente e não permite promoção local a `super_admin`. Eventos do endpoint de login agora gravam somente códigos estáticos, sem e-mail, nome, user-agent ou IP.
- Cada login validado regenera o ID PHP e limpa identidade, cache e estado RBAC antigos antes de gravar usuário/tenant, impedindo sessão residual entre usuários ou condomínios.
- Exceção antes do commit limpa/destrói estado parcial, sem permitir que falha de fechamento de conexões interrompa cleanup/resposta. No Portal MySQL, exclusão de tokens anteriores, inserção do novo token e update de último acesso são transacionais em InnoDB; resposta é preparada antes do commit, e rollback preserva tokens antigos. Após commit confirmado, falha tardia retorna a resposta serializada sem revogar o token durável; auditoria/fechamento são best-effort. A P3.2 não adiciona um POST de senha para escolha de tenant. O seletor ERP/Portal preexistente na main permanece inalterado; sua substituição por token temporário server-side pertence a PR de produto separada.
- `bind_param` mantém valores nulos como `PDO::PARAM_NULL`, cobrindo colunas opcionais com o adapter PDO.
- Centralizadas em `DatabaseDialect` as variantes de metadata, igualdade null-safe, idempotência, upserts RBAC e `RETURNING` para sessão. O digest SHA-256 da sessão fica somente em `rbac_sessoes.sessao_hash`, conforme autorização expressa; o ID/cookie em claro não é persistido ou logado.
- Login e ações de sessão Bearer do Portal do Morador permanecem indisponíveis no PostgreSQL (HTTP 503 antes de qualquer escrita); persistência Bearer `sessoes_portal` e o MVC legado `sessoes_usuarios` não foram convertidos nesta fatia.
- QA local final da reconstrução sobre P3.1b: 27 testes sintéticos do núcleo (26 herdados + caso bind NULL), 56/58 assertions do processo pai no harness drop-in sem/com `mysqli` nativa, 13 cenários sintéticos de bootstrap, 20 testes auth/RBAC, 5 cenários comportamentais do endpoint de sessão e 2 da auditoria. O endpoint é executado em subprocessos CLI com sessão/config sintéticas: a querystring não altera um contexto já válido; contexto ausente, host alheio ou subdomínio incompatível limpam sessão/aposentam o ID antigo e falham; subdomínio confiável preserva o contexto válido. O teste de auditoria confirma que o valor persistido é `NULL` quando o IP é omitido e que o padrão legado com IP permanece. `php -n -l` passou nos 20 PHPs alterados, `node --check` nos quatro scripts inline e `git diff --check` respeitando CRLF. Os casos de timezone, driver, allowlist/aliases e SQL adapter seguem sintéticos. A decisão de sessão do login antes do 409 ainda é verificada estaticamente; os testes CLI não equivalem a HTTP/PHP-FPM. O harness integrado MySQL 8.0.46/PostgreSQL 16.15 registrado acima pertence ao estado anterior à reconstrução e não foi repetido; não substitui QA final. Nenhuma conexão real, PHP-FPM/HTTP ou produção foi usada nesta validação.
- Linter recursivo no corpus idêntico `find api -type f -name '*.php'`: base direta `origin/pg/p3-1b-dropin` = 347 arquivos, 1.678 ocorrências/145 arquivos afetados; reconstrução P3.2 = 350 arquivos, 1.668/142 (redução líquida de 10 ocorrências e 3 arquivos afetados). Os 7 findings presentes nos 14 PHPs de API alterados concentram-se em `api/db/DatabaseDialect.php` (1 `MYSQL_DATABASE_FUNCTION`, 3 `MYSQL_INSERT_SET`, 3 `MYSQL_ON_DUPLICATE_KEY`), como variantes MySQL intencionais centralizadas; os outros 13 arquivos de API alterados não acrescentaram findings. A P3.2 segue sem gate HTTP/PHP-FPM.

### Corrigido
- Portal do Morador: layout de abas convertido em sidebar vertical responsiva/drawer, preservando os 14 módulos e seus loaders.
- Logo: corrigido o acesso do Portal à imagem `logo_tenant` com Bearer do morador e filtro simultâneo por tenant, tipo ativo e MIME de imagem; demais anexos continuam inacessíveis por essa exceção.
- Marketplace do Portal: consultas compatibilizadas com o schema ativo (`preco_venda` e colunas de avaliação específicas), tenant derivado da sessão, pedidos/histórico tenant-aware, binds ajustados e erro técnico substituído por JSON genérico.
- PWA: token FCM só é solicitado após `navigator.serviceWorker.ready`, passando a registration ativa explicitamente; removido logging de prefixos de token.
- Chat-In do Portal: caminhos de widget/API absolutos e estabilidade sob paths de navegação.
- Logo do Portal: endpoint de descoberta agora autentica o token Bearer do morador e deriva o tenant da sessão Portal ativa, sem depender de cookie PHP de backoffice; sem Bearer apenas a sessão ERP permanece autorizada.
- Firebase do Portal: `pwa_ativo` ausente/inativo, placeholders, campos fora dos formatos aceitos e instância default divergente deixam o push desativado antes da permissão/token. Aviso sanitizado para 403 Firebase Installations; habilitação da API e restrições da chave continuam sendo responsabilidade da configuração Google Cloud.
- Hubs de Configuração e Administrativo: cartões e abas agora navegam por clique via handlers locais; acesso direto, autorização RBAC do AppRouter e atalhos por teclado permanecem preservados.
- Chat-In v2: preferência validada por whitelist/booleanos/volume e gates tenant no backend; configurações incompletas falham fechadas. GET sync com sessão exige same-origin por atualizar presença, não marca leitura; mensagens e canais/auditoria usam transações/locks e fazem rollback em falha. Replay idempotente só aceita mesma conversa/tipo/corpo; migration valida valores `som_tipo`/`som_volume` e instala CHECKs. Seed de `visualizar` cobre todos os grupos tenant existentes, preservando `NEGAR` explícito.
- Widget Chat-In: prévia de áudio não sofre cooldown de notificações; Cutucar inicia cooldown local de 30 s, mantendo enforcement no servidor; notificação fallback usa o remetente sincronizado sem corpo; navegação não declara tabs ARIA incompletas. Texto da configuração explica efeito sobre conversas existentes, classe CSS de loading e cache-busting v2 foram alinhados.
### Validação
- Testes estáticos locais e `php -l`/`node --check` aprovados; nomes de colunas do Marketplace confirmados por `INFORMATION_SCHEMA` no MySQL ativo sem ler linhas de negócio. Para esta correção, suíte Portal (9 testes), lint PHP/JS e `git diff --check` aprovados; leitura autenticada da logo e FCM consentido continuam pendentes antes de declarar validação runtime.
- Para os hubs, `tests/hub_navigation/hub-navigation.test.mjs`, a regressão `tests/clean_urls/app-router.test.mjs`, `node --check` e `git diff --check` passaram.
- Chat-In v2: testes Node de contrato/áudio **14/14** passaram; harness MariaDB 10.11 descartável passou incluindo ausência de `pwa_configuracoes` antes do DDL, preflights fail-closed, idempotência, constraints de som/volume, seed RBAC e preservação de estado push. MySQL 8.4, browser autenticado, FCM e produção permanecem pendentes.

### PostgreSQL — regra de SQL portátil (2026-11-15)
- Código SQL novo deve priorizar a sintaxe comum entre MySQL e PostgreSQL. Diferenças de dialeto pertencem a helpers com variantes explícitas selecionadas por `ERP_DB_DRIVER`; `mysql` continua sendo o padrão.
- Adicionados `tools/pg_sql_lint.php` (entrada explícita de arquivos; sem varredura implícita do repositório) e `tests/pg_sql_lint_test.php` com oito cenários sintéticos. O linter detecta construções conhecidas não portáveis em PHP/SQL e não imprime SQL bruto.
- A revisão independente levou à remoção de `NOW()` do catálogo MySQL, ao suporte de comentários SQL `#` sem mascarar `#>`, `#>>` e `#-`, e à rejeição controlada de `--` sem separador por sua ambiguidade entre drivers.
- É uma verificação estática inicial, não um parser semântico nem prova de equivalência. A camada PDO/mysqli, o pipeline PostgreSQL e o diferencial continuam ausentes/pendentes; não foram iniciados serviços nem consultados bancos nesta alteração.

### Preparação de operação
- PR #13 mesclado em `main` no commit `2ad4b9ec652435ccdc21fb754bbdbfeca54f0ed4`.
- Corrigido preflight SQL para falhar antes de qualquer DDL do schema da aplicação quando `pwa_configuracoes` estiver ausente; a procedure auxiliar é temporária e removida pelo runner em falha normal. Novo SHA-256 `a5d402a17e3789223a9d5a297b0c12eb78fa26232ebd6eb8dd8c1f575f5aa375`.
- Preparado runner fail-closed para migration Chat-In v2 com alvo MySQL/schema e SHA fixos, comparação de identidade da instância via aplicação/CLI, modo `--check` read-only, confirmação TTY, lock root-only sem symlink, marcador persistente de tentativa, backup/integridade obrigatórios e pós-condições granulares de estrutura/tenant/RBAC.
- Revisão independente motivou hardening de estado parcial, retentativa, lock e logs; esse item registrava o estado anterior à execução real documentada abaixo.
- Riscos P2 documentados: a routine auxiliar é criada antes do preflight PWA; `--check` em estado completo confia no marcador e não revalida a semântica integral dos tipos/CHECKs; proveniência GitHub da release é gate externo; gzip/checksum não substituem restore drill.
- A migration não havia sido executada no fechamento da etapa de preparação descrita acima. Estado posterior registrado abaixo.

### Execução em produção — 2026-11-15
- PR #14 mesclado em `main` no commit `7c834ff90905857c58431855d02d432cef91963f`; release ativa no mesmo SHA e anterior preservada para rollback.
- Runner executado uma vez após `--check` aprovado e confirmação TTY: migration aplicada no MySQL 8.4.13/schema ativo; pós-checagem `COMPLETA`.
- Backup novo e integridade passaram; `gzip -t`, SHA-256 e permissões `0600` conferidos. Restore drill e cópia off-host continuam pendentes.
- Nginx/PHP-FPM/MySQL ativos; smoke não autenticado passou para login, portais, assets Chat-In e API sem sessão (401 esperado). QA autenticado, FCM e Flutter permanecem pendentes.
- Corrigida rota limpa `/config_chatin`: o snippet Nginx instalado estava desatualizado; sem o allowlist, a rota caía no fallback `/login.html`, que redireciona para `/`. Instalado o snippet gerado no SHA da release com backup root-only. `nginx -t`, reload e smoke local/público passaram (`/config_chatin` e `/` = 200). Sem alteração de código da aplicação.

## [2.1.26] - 2026-11-05
### Adicionado
- **Chat-In** (menu abaixo de Recursos Humanos): comunicador interno entre usuários do painel e moradores (Portal e app). Botão flutuante + painel lateral fixável/minimizável, página do painel, status online, busca por nome/unidade, histórico permanente, bloqueio, ocultar-se da busca, emojis, push FCM no app com deep link. Detalhes em `AI-CONTEXT/CHATIN.md`. Migration `sql/migration_chatin_mysql57.sql`.
- **Avisos do condomínio** (canal somente leitura do Chat-In) alimentado por Documentos, Assembleia, Eventos e chegada de visitante.
- **Eventos** (Administrativo): a página stub virou módulo completo (`api/api_eventos.php`) com público por unidades, aviso/cancelamento pelo Chat-In.
- **Sistema › Controle de Acesso**: liga/desliga por condomínio o aviso de chegada de visitante por E-mail, Aplicativo e Chat-In; WhatsApp/SMS preparados ("Em breve") com registro de provedor.
- Opção "Notificar pelo Chat-In" em Documentos e Assembleia.
### Corrigido
- **Assembleia**: criar sempre falhava (`responder_criar` sem `global $body` e sem `tenant_id`); `_exigir_admin()` lia `role/perfil` em vez de `permissao` e bloqueava toda escrita; listagem mostrava assembleias de **todos os condomínios** e obter/atualizar/excluir/status filtravam só por id. Agora tudo filtra por tenant. Migration `sql/migration_assembleia_tenant_mysql57.sql` (sem `INFORMATION_SCHEMA`, com backfill); sem a coluna a API responde 409 orientando a migration.
- **Documentos (`api_documentos.php`)**: 12 funções usavam `$tenant_id` fora de escopo (SQL `tenant_id = AND`), quebrando p.ex. salvar documento com departamento ("Departamento inativo"/500), dashboard, unidades do seletor, grupos, pastas, tipos e compartilhamento. Adicionado `global $tenant_id`.
### Pendências conhecidas
- Assembleia: pautas, votos e participantes ainda não têm `tenant_id` próprio (dependem do id da assembleia; leitura/escrita por id da pauta não confere tenant). Corrigir em etapa própria.
- App Flutter não foi compilado neste ambiente (sem SDK): rodar `flutter analyze` antes do build.

## [2.1.25] - 2026-11-05
### Alterado
- **Ordens de Serviço — seleção de unidade e morador**: o campo livre Morador/Cliente foi substituído por selects encadeados. Unidades ativas são ordenadas numericamente; moradores ativos são carregados somente após escolher a unidade. A seleção apenas da unidade permanece válida para chamados de área comum.
- O frontend reutiliza APIs tenant-scoped existentes e cancela consultas de moradores obsoletas ao trocar unidade, fechar a modal ou desmontar a página. O backend continua resolvendo nome e snapshot da unidade a partir do `morador_id` quando um morador é selecionado.

## [2.1.24] - 2026-11-04
### Corrigido
- **Convidados (Portal/App) não encontrava visitante cadastrado pela portaria.** Caso real: GABRIEL ELIAS RODRIGUES SILVA (CPF 130.565.176-64), cadastrado no painel por usuário (`cadastrado_por_tipo=FUNCIONARIO`, sem unidade), aparecia como "Visitante não cadastrado". Causa: `buscar_documento` só considerava visitantes vinculados à unidade do morador (`pvu_condicao_visitante_unidade`). Agora a busca usa a **base de Visitantes do condomínio** (tenant), preferindo o cadastro da unidade; resposta traz `da_unidade`. Ao salvar, o convite é vinculado ao cadastro existente (nunca duplica); o estado `EXISTENTE_PORTARIA` deixou de existir.
- Vínculo seguro: `visitante_id` fora da unidade só é aceito quando o documento digitado é idêntico ao do cadastro (`portal_convidados_visitante_por_documento_informado`); caso contrário 403 `VISITANTE_FORA_DO_ESCOPO`. Portal e app enviam `visitante_id` + `tipo_documento` + `documento`. A lista "Visitante já cadastrado" do formulário continua restrita à unidade.
### Adicionado
- **CPF de morador ativo não pode ser cadastrado como visitante** — helper `api/helpers/visitantes_documento_helper.php` (`visitante_cpf_de_morador`, `visitante_mensagem_cpf_morador`) aplicado em: `api/api_visitantes.php` POST e PUT (mensagem com nome/unidade do morador, `dados.cpf_de_morador`), `GET api_visitantes.php?documento=` (avisa `cpf_de_morador` quando não é visitante), `api/api_portal_morador.php?action=visitantes` POST (Portal/App; mensagem sem expor o morador; também valida CPF e grava formatado) e cadastro automático de visitante do pré-cadastro de convidado. Página Visitantes (`visitantes.js`) mostra o aviso ao sair do campo documento.
### Observação
- `api/api_portal.php?action=cadastrar_visitante` é legado (colunas `nome/cpf/tipo` inexistentes, sem tenant) e não é chamado por portal/app; não recebeu a regra. Candidato a remoção.

## [2.1.23] - 2026-11-04
### Adicionado
- **Convidados (Portal do Morador + app): busca automática do documento em Visitantes.** Com CPF completo e válido (ou ao sair do campo, no RG) o formulário consulta `GET api/api_portal_convidados.php?acao=buscar_documento`. Visitante da unidade encontrado → nome preenchido e travado, convite vinculado ao cadastro. Não encontrado → aviso "Visitante não cadastrado"; o morador informa o nome e, ao salvar, o convidado é **cadastrado em Visitantes da unidade** (autoria `MORADOR`, CPF formatado) e vinculado ao convite. `criar` devolve `dados.visitante`: `VINCULADO` | `CADASTRADO` | `EXISTENTE_PORTARIA` | `NAO_CADASTRADO` | `FALHOU`; `cadastrar_visitante:false` desativa o cadastro.
- App Flutter: `GuestDocumentLookup` + `GuestsRemoteDataSource.buscarDocumento()`; `GuestEntity.visitanteId`; formulário com status (encontrado/não cadastrado/CPF inválido), nome travado quando vinculado, vínculo preservado na edição.
- Painel (Registro > Convidados): busca automática ao completar o CPF.
### Corrigido
- **Painel — mensagens do modal "Novo Convidado" apareciam na página atrás do modal** ("CPF inválido", "Visitante não encontrado", erros ao salvar/cancelar). `showFeedback()` passa a escrever no `[data-modal-feedback]` do modal aberto (`#convidadoModalFeedback`, `#convidadoCancelFeedback`); mensagens da busca ficam abaixo do documento (`#convidadoDocumentoFeedback`, estado `.error` novo em `assets/css/pages/registro.css`). Ao abrir um modal, o aviso dele é limpo.
- Painel: com o Nome somente leitura (antes da busca) o envio passava pela validação nativa e só falhava no servidor; agora o modal pede a busca/nome.
- Portal: editar convite vinculado a visitante não derruba mais o vínculo.
### Segurança / privacidade
- `buscar_documento` só revela nome de visitante **da unidade do morador**; documento de outra unidade responde "não cadastrado" e, ao salvar, não cria duplicado nem vincula (`EXISTENTE_PORTARIA`) — a portaria localiza pelo documento na chegada. CPF de morador ativo → 400 `CPF_DE_MORADOR`; CPF inválido → 400 `CPF_INVALIDO`. Falha ao cadastrar o visitante não desfaz o convite salvo (log `[PortalConvidados] falha ao cadastrar visitante`).

## [2.1.22] - 2026-11-04
### Corrigido e adicionado
- **RH — Banco de Horas**: removido o descarte silencioso de extras acima de 120 minutos; `bh_apurar_dia()` aplica `CREDITAR_TUDO` ou separa excedente conforme a política da escala.
- Adicionados mapeamento diário, hash da prévia/entrada, botão Gerar, confirmação, aplicação auditada em lotes de 20, histórico, bloqueio por colaborador, tratamento de períodos fechados e relatório individual/departamental com CSV/PDF.
- Reprocessamento altera somente linhas automáticas do tenant autenticado, preserva abatimentos/pagamentos manuais e não modifica as horas apuradas por mero limite diário. Escritas de Ponto, Abono e DSR compartilham lock/transação com o ledger; falta abonada preserva o DSR. RBAC foi aplicado a leituras RH/PDF, Abono, escala e lançamentos manuais.
- Abatimento acima do saldo exige confirmação explícita e justificativa, pode gerar saldo negativo auditado e respeita limite devedor opcional; pagamento continua limitado ao saldo positivo. Foram centralizados sinal, cálculo e formatação e corrigidos os filtros/gravações multi-tenant, idempotência, sincronização, relatórios e PDF. Exclusão manual requer permissão, motivo e auditoria; lançamentos automáticos não podem ser excluídos manualmente.
- A migration MySQL/MariaDB 5.7 corrige tenant histórico pela relação com o colaborador, adiciona campos/índices e apenas diagnostica `tipo=''`; não corrige tipos vazios. Após o phpMyAdmin retornar #1044, foi removida a dependência de `INFORMATION_SCHEMA`; a idempotência ignora somente coluna/índice duplicados.
- Aviso departamental indica cobertura parcial dos lotes; renderers escapam conteúdo dinâmico e fotos ficam restritas à mesma origem.
### Validado
- Migrations aplicadas duas vezes em MariaDB QA descartável, inclusive reaplicação após existir `excedente`; fixture sintética validou extras de 03:17 e totais de 07:09, além de política limitada de 02:00 + 01:17 excedente.
- Fixtures confirmaram isolamento tenant, preservação de lançamentos manuais, idempotência/replay, lock, lotes 20+2, DSR/falta abonada, períodos fechados, invalidação por alteração pós-prévia e consultas individuais/departamentais.
- `php -l`, `node --check` e `git diff --check` passaram. Não houve endpoint autenticado, renderização visual de PDF, consulta/correção/migration em produção ou deploy.

## [2.1.21] - 2026-11-04
### Adicionado
- **Convidados — pré-cadastro do morador (Portal do Morador + aplicativo)**: nova aba/tela **CONVIDADOS** no `frontend/portal_morador.html` e no app Flutter (`lib/presentation/screens/guests/guests_screen.dart`, rota `/home/guests`, item no menu lateral e card no início). O morador cadastra o convidado com os mesmos campos da Lista de Convidados (anfitrião morador/dependente, visitante já cadastrado da unidade ou CPF/RG + nome, tipo Visitante/Prestador/Entregador autorizado/Familiar, data prevista, dias de permanência, entradas únicas/múltiplas/livres, observação). O convite cai na tabela `convidados` e aparece imediatamente em **Registro > Convidados** para a portaria.
- Novo endpoint `api/api_portal_convidados.php` (Bearer `sessoes_portal`): `opcoes`, `listar` (`filtro=ativos|historico`), `obter`, `verificar_alertas`, `criar`, `editar`, `cancelar`. Morador, unidade, tenant e **origem** são definidos pelo servidor: `PORTAL_MORADOR` (portal) ou `APP_MORADOR` (`canal=app`); autoria `cadastrado_por_tipo=MORADOR`.
- Novo helper `api/helpers/convidados_core_helper.php`: validação, normalização, duplicidade, idempotência e persistência (`convidados_dados_entrada`, `convidados_persistir_novo`, `convidados_atualizar_existente`, `convidados_cancelar_existente`) compartilhados entre painel e portal/app — regra única, sem duplicação.
- Migration `sql/migration_convidados_pre_cadastro_morador_mysql57.sql` (idempotente): acrescenta `APP_MORADOR` ao ENUM `convidados.origem_cadastro` e o índice `idx_conv_tenant_autor_morador`. Sem ela, o app grava `PORTAL_MORADOR` (detecção do ENUM em tempo de execução) — nada quebra.
- Painel (Registro > Convidados): selo **Pré-cadastro** na origem, filtro **Origem** (inclui "Pré-cadastro do morador (Portal/App)", `GET listar&origem=MORADOR`), contador `resumo.pre_cadastros_morador`; na edição de um pré-cadastro a origem fica travada e é preservada pelo servidor.
- Testes de integração versionados em `tests/convidados_pre_cadastro/` (65 casos).
### Corrigido
- **Lista de Convidados do painel retornava HTTP 500 ao listar**: `convidados_sql_base()` declarava `array &$tipos`, mas recebia a string de tipos do `bind_param` → `TypeError` em toda chamada de `GET api_convidados.php?acao=listar`. Assinatura corrigida para `&$tipos`. Sem a correção, a portaria não veria nenhum convite (nem os pré-cadastros do morador).
- Edição de convite criado pelo morador no painel falharia: o select de origem do painel não possuía `PORTAL_MORADOR` e a API recusava a origem. Agora a origem do morador é preservada.
### Segurança
- Morador só lista/obtém/edita/cancela convites com `morador_id` = sessão; edita apenas os que ele mesmo cadastrou (convites da portaria são somente cancelamento); visitante vinculado precisa pertencer à unidade (`pvu_condicao_visitante_unidade`); documento completo só retorna ao próprio autor; `verificar_alertas` do portal devolve somente alertas da própria unidade (alertas de outra unidade que casam pelo documento não são expostos); limite de 100 pré-cadastros PREVISTO por morador (HTTP 429).

## [2.1.20] - 2026-11-04
### Corrigido
- **App/Portal do Morador — Visitantes por Gleba**: `GET api/api_portal_morador.php?action=visitantes` filtrava `morador_id = morador da sessão`, ocultando visitantes cadastrados por outros moradores da mesma unidade e visitantes da base geral autorizados para a Gleba. A lista agora usa o escopo da unidade do morador autenticado (moradores ativos da mesma unidade, mesmo tenant), por `morador_id`, `cadastrado_por_morador_id` ou autorização em `acessos_visitantes`. Novo helper `api/helpers/portal_visitantes_unidade_helper.php`.
- A resposta passou a trazer `cadastrado_por_nome`, `proprio` e `pode_excluir`; `ativo`/`id` são inteiros. A exclusão continua restrita ao autor (`morador_id` da sessão).
- `POST api/api_acessos_visitantes.php` (geração de QR pelo portal/app) usa o mesmo escopo de unidade; antes recusava visitantes da Gleba cadastrados por outro morador.
- App Flutter (`aplicativoerpcondominios`): `visitors_screen.dart` exibe "Visitantes da Unidade", contador, autor do cadastro e só mostra excluir quando `pode_excluir`; `access_screen.dart` lista apenas visitantes ativos no seletor do QR. `frontend/portal_morador.html` recebeu o mesmo tratamento de autor/exclusão.
- **App Flutter — build Android após atualização do Flutter SDK (3.47.1)**: `flutter run` falhava com "Gradle 8.12.0 is lower than Flutter's minimum supported version of 8.14.0". Atualizados wrapper Gradle 8.12 → 8.14, AGP 8.7.3 → 8.13.1, Kotlin 2.1.0 → 2.3.20 e Java 11 → 17, mantendo AGP 8.x.
### Validado
- `php -l` nos três PHP tocados; MariaDB descartável executando o endpoint real com fixture de duas Glebas, ex-morador inativo, Gleba 13/1330, unidade sem prefixo e outro tenant. Detalhes em `DEBUG_VISITANTES_APP_GLEBA_20261104.md`.

## [2.1.19] - 2026-11-04
### Corrigido
- **Registro Manual — revisão de integridade**: validar no backend que `dependente_id` pertence ao `morador_id` informado e ao tenant da sessão; a Entrada exige ambos ativos e a Saída histórica ainda pode ser encerrada, mas nunca com dependente de outro morador.
- Tipo e documento enviados para visitante precisam corresponder ao `visitante_id` do cadastro tenant-local. Permanência dos tipos de visitante aceita somente inteiro de 1 a 365 dias, com omissão igual a 1.
- O replay idempotente agora compara também o conjunto de ocupantes e devolve HTTP 409 `IDEMPOTENCY_CONFLICT` em colisão; o rollback é explícito antes da resposta.
- Pré-validação de Alertas com dependente sem morador informado deriva unidade do morador-pai consultado no tenant. O botão Voltar da etapa Veículo retorna a Destino quando Identificação foi omitida para Morador.
### Validado
- `php -l` nos endpoints/helpers tocados, `node --check` no módulo Registro e `git diff --check`.
- MariaDB descartável executou helpers reais de dependente, documento, permanência, unidade canônica e idempotência: vínculo correto/divergente, tenants cruzados, Entrada/Saída, 1/365 dias, ocupantes iguais/removidos/adicionados/divergentes e resposta 409 estruturada passaram.
- Smoke test Playwright com endpoints mockados incluiu retorno de Veículo para Destino no caso Morador; sem erros de página e sem overflow horizontal em 1366×768 e 375×812. Não houve sessão autenticada, deploy nem teste na base de produção.

## [2.1.18] - 2026-11-04
### Corrigido e alterado
- **Registro Manual — fluxo por etapas**: reorganizado em Acesso, Destino, Identificação e Veículo/Pedestre. Etapas concluídas agora ficam recolhidas com resumo e ação Editar; a etapa de identificação é omitida para Morador. Reabrir Destino preserva os campos posteriores e revalida alertas.
- O foco automático agora escolhe o primeiro controle realmente visível da etapa, ignorando ações Editar ocultas e campos dentro de seções escondidas.
- A API de Alertas passou a aceitar pré-validação parcial por etapa, sem token de ciência e sem revelar documentos de alvos futuros. A gravação mantém a reavaliação formal e o fluxo de `409 ALERTA_PENDENTE`.
- Corrigido o fallback `409` do Registro, que referenciava nome inexistente ao abrir o modal formal; as rotas normal e de fallback agora usam o modal existente. Limpar gera uma nova chave de idempotência. A identificação de Dependente foi alinhada ao tipo avaliado na gravação e a consulta de placa não troca automaticamente o destino escolhido.
### Validado
- `php -l` nos endpoints/helpers PHP e `node --check` no módulo Registro, além de `git diff --check`.
- Fixture MariaDB descartável confirmou os alertas gerais por etapa, contagem de específicos sem expor alvos, reclassificação conforme visitante/placa identificados e isolamento tenant A/B.
- Smoke test Playwright local com APIs interceptadas, sem sessão ou produção, passou em 1366×768 e 375×812: avanço Enter/foco visível, entrada, modal/popups, saída, bloqueio/override, documento obrigatório, Dependente/Pedestre, convite, resumo/edição de etapas, revalidação, `409`→ciência→token→retry com a mesma chave, atualização dos Registros Recentes, limpeza/idempotência e reentrada SPA sem handlers duplicados. Sem erros de página ou overflow horizontal.
- Em MariaDB descartável, o helper real rejeitou token ao mudar o contexto (`CIENCIA_TOKEN_DIVERGENTE`) e em outro tenant (`CIENCIA_TOKEN_INVALIDO`). Não houve teste de endpoint autenticado, deploy, nem alteração no banco de produção.

## [2.1.17] - 2026-11-03
### Corrigido e alterado
- **RBAC — permissões explícitas**: removida a negação implícita entre grupos; a ausência de grant é sem acesso, e somente registros `NEGAR` explícitos negam uma ação. Precedência definida como admin/super_admin, negar individual, permitir individual, negar de grupo e permitir de grupo.
- A Central de Usuários agora edita estados `HERDAR`/`PERMITIR`/`NEGAR` por ação, apresenta origem/prévia efetiva e valida grants em perfil, grupo, vínculo, exceção individual e reset. O backend compara efeitos antes/depois e rejeita autoelevação/concessões indiretas acima da permissão do configurador; grupos de compatibilidade não são atribuíveis manualmente.
- O perfil admin/super_admin é lido do registro atual no tenant, não de uma sessão/localStorage potencialmente desatualizados; a resposta efetiva e `is_admin` usam o mesmo papel do banco. Revisão do tenant revalida caches, MenuController e a sessão ativa sem novo login. Em falha da API, o menu operacional é preservado, escrita é bloqueada e aparece aviso.
- Os guards `PermissoesModulos` e `MenuController` alinham aliases de rota às chaves canônicas RBAC (incluindo `relatorios_acesso`, `administrativo` e rotas auxiliares); os casos legados conhecidos têm alias explícito e ações sem mapeamento negam por padrão.
- **Auditoria estática REPO_ONLY**: analisados 59 módulos ativos; encontrados gaps de enforcement, catálogo, controles e chaves/rotas em múltiplos módulos. Nenhuma base de produção foi consultada.
### Validado
- PHP lint nos cinco endpoints/helpers RBAC tocados, `node --check` em `usuarios.js`, `permissoes-modulos.js` e `menu-controller.js`, além de `git diff --check`.
- Fixture MariaDB descartável confirmou 59 módulos ativos e 199 ações seedadas e testou U1–U9: somente visualização, grants por grupo/usuário, interseção de grupos, NEGAR individual/de grupo, precedência, isolamento tenant, papel admin de banco versus sessão stale, recusa de grant/reset não autorizado, grant por admin e criação/incremento da revisão. Todos passaram; a auditoria adicional inspecionou 357 operações do código, REPO_ONLY.
- Não houve teste autenticado em browser, chamada direta com cookie real, deploy ou consulta/alteração da base de produção.

## [2.1.16] - 2026-11-03
### Corrigido
- **Moradores — permissões de edição**: a aplicação das permissões agora marca quais controles foram desabilitados pelo RBAC e remove `disabled` somente desses controles quando a resposta efetiva permite a ação. Estados `disabled` próprios de fluxos, como a cascata de Unidade → Morador, são preservados.
- Em falha ao carregar permissões, Moradores mantém as ações de escrita bloqueadas e exibe aviso ao usuário, sem conceder acesso por fallback.
### Validado
- `node --check frontend/js/pages/moradores.js`, `php -l api/api_moradores.php` e `git diff --check` passaram. Harness local confirmou reversão do bloqueio provisório para administrador, permanência do `disabled` preexistente, bloqueio de usuário somente leitura e aviso em falha de carregamento.
- Não houve teste autenticado no navegador nem validação em produção; a Parte 2 da auditoria RBAC permanece separada.

## [2.1.15] - 2026-11-03
### Corrigido
- **Alertas da Unidade — cadastro**: o `INSERT` de `alertas_unidade` possuía 24 parâmetros e placeholders, mas a assinatura de tipos mysqli tinha 23 caracteres. O mismatch lançava `ArgumentCountError`, revertia a transação e devolvia HTTP 500. Corrigida a assinatura para cobrir os 24 campos.
- O handler global de exceções agora registra classe, código e mensagem sanitizada no log PHP, mantendo ao cliente a resposta JSON genérica sem detalhes internos.
### Validado
- `php -l api/api_alertas_unidade.php` e `git diff --check` passaram. Com o helper `au_query` real e schema descartável, o bind antigo foi reproduzido como `ArgumentCountError`; a assinatura corrigida inseriu e recuperou os 24 parâmetros, incluindo campos `NULL` e chave de idempotência.
- A assinatura de UPDATE continua com 20 tipos para 20 parâmetros. Não houve teste autenticado no navegador, consulta ao log de produção, migration ou deploy.
- O HTTP 422 também mostrado na captura não foi atribuído a um campo específico porque o corpo JSON da resposta não estava disponível; ele pode ser uma tentativa distinta rejeitada por validação de negócio.

## [2.1.14] - 2026-11-03
### Alterado
- **Moradores — Lista de Moradores**: adicionado filtro de Unidade à barra, opções sem quantitativos em ordem natural e busca automática por texto/unidade/ordenação. O botão Buscar foi substituído por Limpar, que restaura a lista e ordenação padrão.
- A unidade pode ser combinada com texto como condição AND e usa `unidade_exata=1` com igualdade case/space-insensitive; chamadas legadas sem essa flag preservam o filtro antigo. Ordenações Unidade ascendente/descendente agora usam número natural no backend.
- Buscas concorrentes cancelam a requisição anterior e somente a resposta mais recente renderiza. Busca, unidade, ordenação e página são restauradas pela URL. Cadastro, edição, exclusão e operações de anexos recarregam a mesma consulta/página; se uma exclusão esvaziar a última página, a listagem recua.
### Validado
- `php -l api/api_moradores.php`, `node --check frontend/js/pages/moradores.js` e `git diff --check` passaram.
- Fixture MariaDB descartável confirmou igualdade normalizada sem incluir Gleba 10/100 ao filtrar Gleba 1, combinação AND, ordenação natural até Gleba 100 em páginas distintas, compatibilidade da consulta legada e busca de CPF mascarado.
- Harness local da tela confirmou `unidade_exata=1`, filtros combinados, cancelamento/latest-only, recuo de página, restauração de querystring, Limpar, mensagem de zero resultados, ordenação/deduplicação natural e inserção ordenada de unidade de deep-link. Revisão estática confirmou as chamadas de recarga da mesma página nos handlers de cadastro, edição, exclusão e anexos.
### Pendente
- Não houve teste autenticado no navegador/produção; as regressões visuais mobile e as chamadas de outros módulos à API permanecem sem validação ponta a ponta. Nenhuma migration foi criada/aplicada.

## [2.1.13] - 2026-11-03
### Alterado
- **Ordens de Serviço — Chamados**: o select Unidade agora exibe somente o nome e ordena as opções naturalmente no frontend, eliminando duplicatas por trim/caixa. A aba não consulta mais `unidades_com_os`, removendo uma requisição; deep-links e o ranking inserem unidades ausentes em sua posição natural. O contador da lista filtrada e os filtros cumulativos permanecem inalterados.
- `unidades_com_os` foi mantida no backend e documentada como ação tenant-safe/RBAC sem consumidor ativo identificado, candidata a remoção futura.
### Validado
- `node --check frontend/js/pages/ordens_servico.js` e `git diff --check` passaram.
- Harness local confirmou nomes sem quantitativos, ordem natural, deduplicação, placeholder `Todas`, inserção ordenada por URL/ranking e apenas uma chamada à API de unidades, sem request `unidades_com_os`. A ligação já existente Unidade → moradores e atualização dos filtros/lista foi revisada sem alteração.
### Pendente
- Não foi executado browser autenticado/DevTools; fluxos reais de deep-link, ranking, limpeza e regressão visual de Dashboard/Relatórios/impressão ainda precisam de confirmação no navegador.

## [2.1.12] - 2026-11-03
### Corrigido
- **Alertas da Unidade (Moradores › Alertas da Unidade)**:
  - **Ordem das unidades**: `api/api_alertas_unidade.php` (`acao=opcoes`) ordenava com `ORDER BY unidade` textual, o que colocava "Gleba 10" antes de "Gleba 2". Agora a lista é deduplicada por `TRIM`/caixa e ordenada em ordem natural (`strnatcasecmp`). O frontend reaplica `Intl.Collator('pt-BR', {numeric:true})`. Resultado: `200, ADMINISTRATIVO, Gleba 1, Gleba 2, … Gleba 10 … Gleba 100`. Vale para o filtro da lista e para o modal.
  - **Cascata Unidade → Morador titular → Dependente** (`frontend/js/pages/moradores-alertas.js`): o select de unidade não tinha evento, e o modal listava todos os moradores e dependentes do tenant. Agora:
    - o morador fica desabilitado até escolher a unidade e mostra só os moradores ativos dela (comparação `TRIM`/caixa); com um único morador, ele é pré-selecionado;
    - o dependente só fica habilitado quando o tipo do titular é "Dependente" e lista só os dependentes da unidade ou do morador escolhido; com tipo "Morador", fica desabilitado e não é enviado (a API recusa `dependente_id` nesse caso — `TITULAR_INVALIDO`);
    - escolher o dependente antes do titular preenche o morador responsável;
    - editar e duplicar reconstroem a cascata antes de aplicar os valores;
    - mensagens de validação para unidade e dependente ausentes.
  - A comparação de unidade divergente em `au_api_validar_titular()` passou a ignorar espaços e caixa, porque a lista agora usa a unidade com `TRIM`. O backend continua revalidando morador/dependente no tenant da sessão.
- **Testes**: `php -l` OK. Simulação em navegador (Playwright) da ordenação e da cascata (troca de unidade, unidade sem moradores, morador único, titular Morador × Dependente, dependente antes do morador, payload final) OK. Pendente: validação manual no ambiente com sessão real.

## [2.1.11] - 2026-11-03
### Implementado — web/backend
- **Ordens de Serviço**: adicionados filtros exatos de Unidade e Morador encadeados, chip de unidade/contagem, querystring restaurável ao atualizar/compartilhar e atalho clicável/acessível pelo ranking. A listagem continua cumulativa com tenant, RBAC, departamentos e Restritividade O.S.; sem permissão `moradores.visualizar`, apenas o seletor de morador fica indisponível.
- A API usa prepared statements para filtrar pela unidade snapshot da O.S. e só consulta `moradores.unidade` no fallback de registros legados sem unidade persistida. Criar/editar resolve nome/unidade pelo `morador_id` e tenant autenticado; ao editar o mesmo morador preserva o snapshot antigo, e só grava outra unidade em troca explícita do vínculo. O fluxo do Portal do Morador não foi modificado.
- **Moradores**: a listagem GET e sua contagem aplicam obrigatoriamente `tenant_id` da sessão antes dos filtros opcionais, fechando uma consulta que podia retornar registros de outros tenants.
- O filtro de O.S. usa `GET api/api_moradores.php?acao=select_por_unidade`, que retorna apenas ID/nome de moradores ativos e mantém o RBAC `moradores.visualizar` já exigido pelo endpoint.
- Adicionada migration MySQL/MariaDB 5.7 idempotente para `idx_os_tenant_unidade` e script somente leitura `sql/diagnostico_os_unidade_divergente.sql`; a migration não foi aplicada em produção.

## [2.1.10] - 2026-11-03
### Corrigido
- **Ordens de Serviço**: o formulário operacional passa a usar a identidade do usuário autenticado como atendente, sem depender da API global de usuários. Gestores recebem uma lista mínima de atendentes ativos do próprio tenant; o backend valida o tenant e impede atribuições novas a usuários inativos ou de outra empresa.
- O usuário comum não consegue reatribuir O.S. ao editar; o responsável anterior é preservado. A Restritividade O.S. inclui ordens criadas, atribuídas ou assumidas pelo usuário, respeitando o tenant e o esquema antigo sem a coluna opcional `assumido_por_id`. O vínculo pessoal não concede escrita sem as permissões aplicáveis.

## [2.1.9] - 2026-11-03
### Corrigido
- **Moradores e Alertas da Unidade**: a leitura das regras de campos agora exige `moradores.visualizar`, enquanto alterações continuam exigindo `sistema.configurar`. O painel Alertas usa as permissões específicas retornadas pelo backend, oculta ações de escrita para visualizadores e não mantém o detalhe aberto quando a API não retorna um alerta válido. A avaliação operacional dos alertas continua no lançamento manual de entrada.
- **RBAC de Moradores**: grupo personalizado ou `compat-visualizador` que concede somente `moradores.visualizar` agora nega explicitamente permissões herdadas de escrita, inclusive de `compat-operador`; a revisão do tenant é criada/incrementada ao alterar políticas, invalidando caches de sessões ativas.

### Validado
- Teste em MariaDB descartável confirmou que grupo customizado somente leitura e `compat-visualizador` mantêm `moradores.visualizar` e negam criar/editar/excluir/exportar apesar do `compat-operador`; o operador compatível sem grupo somente leitura preserva edição. Invalidação de cache e criação da revisão RBAC ausente também passaram. `php -l`, `node --check` e `git diff --check` passaram.

## [2.1.9] - 2026-11-04
### Planejado
- **RH — Banco de Horas: botão "Gerar" (reprocessar) + mapeamento espelho × banco**: causa confirmada em `rh_ponto_core.php` `_bh_sincronizar()` L401–407 — `min($extraLiquido, 120)` descarta silenciosamente horas extras acima de 2h/dia (caso Wanderson 17/10/2026: espelho 03:17, banco 02:00; outubro: espelho 07:09 × banco 04:47). Banco só é ressincronizado ao salvar/recalcular o espelho, ficando congelado em regras antigas. Especificado: função única `bh_apurar_dia()`, política configurável `CREDITAR_TUDO` (padrão) / `LIMITAR_E_SEPARAR` (excedente fora do saldo, nunca descartado), endpoint `mapear`, botão "Gerar" ao lado do filtro Colaborador com prévia (simular) → aplicar com hash, lotes por departamento, tabela `rh_banco_horas_reprocessamentos`. Nenhuma alteração de código — prompt em `PROMPT_BANCO_HORAS_REPROCESSAR_20261104.md`.

## [2.1.8] - 2026-11-03
### Corrigido
- **Protocolos de Mercadorias**: removida a dependência da listagem global de usuários, que retornava 403 para funcionários sem `usuarios.visualizar`. O campo Recebedor (Portaria) passa a exibir o operador autenticado; a API grava esse nome a partir da sessão e preserva a autoria na edição. Escritas passam a exigir permissões RBAC do módulo Protocolos.

## [2.1.8] - 2026-11-04
### Planejado
- **RH — Banco de Horas: abatimento maior que o saldo**: analisados `api_rh_banco_horas.php`, `rh_ponto_core.php` (`_bh_sincronizar`), `api_rh_ponto.php` (DSR), `api_rh_relatorios.php`, `recursos_humanos.js/.html`. Causa do bloqueio "Saldo insuficiente": `registrar` rejeita `minutos > saldo` para abatimento e pagamento (L187). Especificado: abatimento pode negativar o saldo com confirmação (HTTP 409 `CONFIRMAR_SALDO_NEGATIVO` + justificativa + auditoria); pagamento segue limitado ao saldo. Achados críticos: INSERTs em `rh_banco_horas` sem `tenant_id` (coluna `DEFAULT 1` → lançamentos de qualquer tenant caem no tenant 1); extrato/relatório sem filtro de tenant; saldo calculado em 4 lugares tratando qualquer tipo não-crédito como débito; linha de 15/09/2026 com `tipo` vazio no extrato; sem transação/lock no registrar. Nenhuma alteração de código — prompt em `PROMPT_BANCO_HORAS_ABATIMENTO_20261104.md`.

## [2.1.7] - 2026-11-02
### Implementado — web/backend
- **Alertas da Unidade**: criada aba Alertas em Moradores, APIs administrativas e Portal web, tabelas tenant-aware, alvos específicos, histórico, eventos automáticos e notificação pós-ciência humana. O domínio novo permanece separado do motor `alertas_acesso` de detecção pós-evento.
- Registro Manual e Controle de Acesso reavaliam alertas no servidor; ciência contextual tem token de uso único e gravação transacional. Leituras automáticas de QR/RFID bloqueiam `BLOQUEAR` e registram eventos não atribuídos a operador.
- Portal e painel administrativo podem usar modelos rápidos e prévias/resumos operacionais, detalhes com histórico em abas, duplicação e exportação CSV/impressão filtrada a 90 dias. KPIs incluem ativos/críticos/pendentes, expiração em 7 dias, acionamentos do dia e overrides no mês. `verificar` usa rate limit e cache de IDs de 30s invalidado em mutações; a gravação final reavalia regras sob lock. Limite server-side de 30 regras simultaneamente vigentes por unidade é aplicado na criação e retomada. O Portal cria/edita/pausa/cancela regras próprias, vê acionamentos e busca visitantes vinculados à sessão com documento mascarado; verifica conflitos antes de emitir QR e controla preferência individual de push, sem suprimir o inbox interno. Convites verificam alertas na data prevista (12h local) antes da confirmação de cadastro; a portaria reavalia no horário real. O QR permanece restrito ao mesmo tenant/morador. Override de supervisor é reenviado ao motor `alertas_acesso` após o commit. Aplicativo Flutter ficou explicitamente fora do escopo.
- Criado cron CLI idempotente de 10 minutos para expirar alertas e gerar inbox/lembrete aproximadamente 24h antes do fim da vigência. O código não configura o agendamento no cPanel.
### Validado
- `php -l` e `node --check` dos arquivos alterados, `git diff --check` e migration executada duas vezes em MariaDB descartável.
- Teste sintético local confirmou migration gate, isolamento de tenant, avaliação de regra, bloqueio automático, máscara de documento e atualização de contador.
- Teste MariaDB descartável do cron confirmou expiração no tenant A, preservação do alerta futuro no tenant B e reexecução sem duplicar lembrete, inbox ou histórico.
- Testes descartáveis também cobriram cache de 30s com invalidação, preferência push por morador/tenant, match de Familiar em `CONVIDADOS` e contagem de 30 alertas vigentes por unidade.
- Corrigidos os checks de metadata da migration e o gate HTTP 503 para não comparar strings com collations implícitas incompatíveis. Reaplicação local em MariaDB descartável com collations mistas passou duas vezes, incluindo backfill da sessão Portal; produção não foi alterada.
### Pendente
- Não foram executados teste de browser autenticado, hardware físico, deploy ou migration de produção. A migration `sql/migration_alertas_unidade_mysql57.sql` precisa de backup e aplicação autorizada antes do uso em produção.

## [2.1.6] - 2026-10-31
### Planejado
- **Lista de Convidados no Registro de Acesso**: analisados `registro.html`/`registro.js`, `api_registros.php`, `api_visitantes.php`, `api_acessos_visitantes.php`, `api_portal_morador.php`, RBAC e app Flutter para especificar uma nova aba "Lista de Convidados" (cadastro pela portaria e pelo morador, herança de visitante por CPF/RG, tipos Entregador Autorizado e Familiar, data prevista, dias de permanência, controle de entradas único/múltiplos de 2/indeterminado, origem do cadastro, autoria Usuário/Morador/Dependente e navegação por data). Decisões registradas: não reutilizar `acessos_visitantes` (regra de 1 acesso ativo por visitante é incompatível); `registros_acesso.tipo` é ENUM e precisa de migration + atualização de todas as whitelists; dependente não possui login hoje, então a autoria `DEPENDENTE` fica preparada mas não é gravada. Nenhuma alteração de código feita nesta tarefa — apenas análise e prompt em `PROMPT_LISTA_CONVIDADOS_20261031.md`.
### Adicionado
- **Registro Manual — Lista de Convidados (painel web)**: incluída tabela tenant-aware de convites com anfitrião morador/dependente, documento, período de validade, modalidade e limite de entradas, autoria, histórico, cancelamento e chave de idempotência. A tela inclui navegação por data, busca, filtros, formulário acessível e deep-link `?page=registro&aba=convidados`.
- A API de convidados aplica autenticação, isolamento pelo tenant da sessão e RBAC por ação. A movimentação Entrada/Saída revalida convite, documento, destino, validade e limite sob transação; os novos tipos **Entregador Autorizado** e **Familiar** são categorias próprias.
### Alterado
- **Relatórios**: os tipos novos foram incluídos em filtros, KPIs, análises, CSV, PDFs e histórico do Portal do Morador. As consultas de Relatórios passaram a identificar a ação própria para exigir `relatorios_acesso.visualizar`, sem confundir essa permissão com a listagem operacional de Registros Recentes.
- **Registro SPA**: respostas assíncronas de Convidados verificam a raiz ativa antes de atualizar a interface; a migration é `sql/migration_lista_convidados_mysql57.sql`.
### Validado
- `php -l` nos endpoints PHP alterados; `node --check` nos módulos JS alterados; `git diff --check`.
- Migration executada duas vezes em banco MariaDB descartável; `convidado_id` e os dois valores do ENUM foram confirmados. Consulta sintética validou estados por data e isolamento tenant-aware.
- A aplicação da migration na base de produção, o teste end-to-end autenticado e as fases Portal/App Mobile não fazem parte deste escopo web e ainda não foram executados.

## [2.1.5] - 2026-08-13
### Investigado
- **Módulo Vigilante/Rondas — mapeamento para o Portal do Colaborador (mobile)**: analisado por completo o módulo administrativo já existente (`api/api_rondas_vigilante.php`, `frontend/pages/rondas_vigilante.html`), incluindo tabelas (`ronda_rotas`, `ronda_pontos`, `ronda_vigilantes`, `ronda_registros`, `ronda_auditoria` — nenhuma documentada antes em `DATABASE.md`), regra de ciclo/SLA e deduplicação por `ciclo_chave`. Confirmado que os endpoints de leitura de QR (`qr_detalhe`, `registrar_leitura`) já existem e funcionam, mas **nenhum cliente os utiliza hoje** — não há tela de "bate-ponto" nem no painel web nem no app. Identificado um gap crítico de identidade: o Portal do Colaborador mobile autentica contra `usuarios`, enquanto `ronda_vigilantes` referencia `rh_colaboradores` — tabelas sem vínculo direto, só ponte possível via e-mail. Nenhuma alteração de código feita nesta tarefa — apenas análise e prompt para o Manus implementar o novo menu "Vigilante" no app. Detalhe completo em `ANALISE_MODULO_VIGILANTE_RONDAS_MOBILE_20260813.md`.

## [2.1.4] - 2026-08-13
### Investigado
- **Notificação de novo documento (GED/Documentos) para moradores**: diagnosticado que hoje não existe nenhum caminho que avise o morador quando um documento liberado para ele é cadastrado. A função `_notificar_novo_documento()` em `api/api_documentos.php` só envia e-mail para usuários internos do sistema (por `grupo_id` administrativo) e nem roda no caso mais comum (documento sem grupo específico); ela não tem relação com o campo `visibilidade`/`unidades_acesso`, que é o que realmente controla se um morador pode ver o documento (já implementado em `pd_where_visivel()`, `api/api_portal_documentos.php`). Nenhuma alteração de código feita nesta tarefa — apenas diagnóstico e prompt para o Manus implementar, seguindo o padrão já validado de `protocol_notification_helper.php`. Detalhe completo em `DEBUG_NOTIFICACAO_DOCUMENTOS_20260813.md`.

## [2.1.3] - 2026-08-13
### Investigado
- **Notificação de veículo entrando na unidade (Controle de Acesso)**: diagnosticado por que o morador não recebe aviso de "veículo entrou para sua unidade" apesar do histórico de acessos aparecer normalmente no painel web e no app. Causa raiz principal: o fluxo automático da catraca (`api/controlid/notifications/dao.php` e `api/controlid/new_user_identified.php`, via `push_registrar_acesso_erp()` em `api/controlid/_helper.php`) grava em `registros_acesso` mas nunca chama o helper de notificação — só o lançamento manual pelo painel (`api/api_registros.php`) está de fato ligado a `controle_acesso_criar_notificacao_registro()`. Também não há confirmação de que as migrações que adicionam `veiculo_id`/`registro_acesso_id` em `notificacoes_morador` já rodaram em produção. Nenhuma alteração de código feita nesta tarefa — apenas diagnóstico e prompt para o Manus corrigir. Detalhe completo em `DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md`.

## [2.1.2] - 2026-08-13
### Documentado
- **AI-CONTEXT do App Mobile (Flutter)**: criados `APP_MOBILE_ARCHITECTURE.md` e `APP_MOBILE_BUSINESS_RULES.md`, mapeando toda a arquitetura (auth do morador vs. colaborador, roteamento, offline/sync do leiturista, OCR, notificações) e as regras de negócio tela a tela do repositório `aplicativoerpcondominios`, com tabela de endpoints por módulo. Objetivo: acelerar leitura futura do app por IA, sem necessidade de reler todo o código-fonte a cada tarefa. Indexado em `INDEX.md`.
- Durante o mapeamento, dois problemas reais foram identificados no app (não corrigidos nesta tarefa, apenas documentados):
  1. `documents_screen.dart` chama a ação errada da API de documentos (`documentos_listar` sem `pasta_id` em vez de `buscar`), deixando a tela de Documentos sempre vazia — detalhe completo em `DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md`.
  2. `tickets_screen.dart` chama `dioClient.initBaseUrl()`, método removido do `DioClient` na migração para URL fixa multi-tenant — quebra a criação de chamados e a visualização do histórico de interações no Portal do Morador (`NoSuchMethodError` capturado silenciosamente pela tela). Detalhe em `APP_MOBILE_ARCHITECTURE.md`, seção 6.

## [2.1.1] - 2026-08-13
### Corrigido
- **Portal do Morador PWA**: Removida a barra visual fixa de “Nova versão disponível!”. A atualização passou a seguir o ciclo nativo conservador do Service Worker: a nova versão permanece em espera e assume em um ciclo seguro, sem recarregar formulários em uso.
- Mantidos o registro em `/firebase-messaging-sw.js`, o listener `controllerchange`, Firebase Cloud Messaging, cache offline e o banner de instalação do PWA.


## [2.1.0] - 2026-02-xx
### Adicionado
- **Manual do Sistema**: Novo módulo com artigos interativos, busca, favoritos e relatórios.
- **Módulo GED (Documentos)**: Gerenciamento Eletrônico de Documentos integrado à Manutenção, com versionamento e visibilidade por unidade.
- **AI Context Framework**: Criação da base de conhecimento permanente (`AI-CONTEXT/`) para inteligências artificiais.

## [2.0.0] - 2026-01-25
### Adicionado
- **Sistema de Dependentes**: Cadastro completo vinculado a moradores.
- **Integração ControliD**: Rota de webhook para catracas.
- **App PWA**: Portal do Morador com Push Notifications (Firebase).

### Corrigido
- Loop de redirecionamento no `session-manager-core.js`.
- Correção de layout no CSS do `input-wrapper` na tela de login.
## [2.1.1] - 2026-08-13
### Corrigido
- **Portal do Morador — Documentos**: a tela móvel passou a usar a ação GED `buscar` com parâmetros `q`, `tipo` e `pagina`, eliminando a chamada incompatível a `documentos_listar` sem `pasta_id`.
- **Portal do Morador — Documentos**: respostas `sucesso: false`, erros de rede e formatos inválidos agora são apresentados como erro visível, em vez de lista vazia silenciosa.
- **Portal do Morador — Documentos**: adicionada paginação incremental de 30 documentos por página, mantendo as regras de visibilidade e download revalidado no backend.

### Documentado
- Registrado o diagnóstico confirmado de unidade, visibilidade e contrato da API em `AI-CONTEXT/DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md` e `AI-CONTEXT/API.md`.


## [2.1.3] - 2026-08-13
### Corrigido
- **Controle de Acesso / ControlID**: todos os modos automáticos passaram a criar um evento persistente `acesso_entrada` para o morador vinculado ao veículo após gravar o acesso no ERP.
- **Controle de Acesso / ControlID**: a resolução de `tenant_id` e unidade passou a ser obtida do morador associado ao veículo, sem aceitar contexto de tenant enviado pela catraca.
- **Controle de Acesso / ControlID**: a tentativa de persistência e push FCM foi isolada em tratamento não bloqueante; uma falha de notificação não interfere na liberação da catraca.

### Documentado
- Adicionada auditoria de produção e roteiro pós-deploy em `DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md` e `sql/validacao_controlid_notificacao_pos_deploy.sql`.


## [2.1.4] - 2026-08-13
### Corrigido
- **Controle de Acesso — Veículos**: a migração de notificações passou a tornar `notificacoes_morador.protocolo_id` anulável, permitindo eventos `veiculo_cadastrado` e `acesso_entrada` sem protocolo associado.
- **Controle de Acesso — Veículos**: o helper valida previamente a compatibilidade do esquema e devolve `migracao_pendente` de forma não bloqueante quando a coluna ainda não aceitar `NULL`.

### Documentado
- Registrada a causa raiz e a validação esperada em `DEBUG_NOTIFICACAO_VEICULO_20260813.md`.


## [2.1.5] - 2026-08-13
### Adicionado
- **Financeiro — Inadimplência**: criado módulo Multi-Tenant para importação do Relatório de Inadimplência Detalhado BRCondos, preservação do PDF em BLOB, snapshots históricos, conciliação de totais, ranking por Gleba, comparação, CSV e geração de PDF pelo fluxo de impressão.
- Registrada a permissão `inadimplencia` com nível mínimo `gerente`, o submenu e card no Financeiro e o contrato da API em `AI-CONTEXT/INADIMPLENCIA.md`.
- **Portal do Colaborador — Vigilante/Rondas Mobile**: adicionadas as ações autenticadas `vigilante_qr_detalhe`, `vigilante_registrar_leitura` e `vigilante_historico_hoje`. O aplicativo consulta QR, confirma ponto/rota/instruções, registra leitura com resultado de SLA e mostra histórico do dia.
- **Identidade operacional segura**: a sessão Bearer resolve o tenant e associa `usuarios.email` a `rh_colaboradores.email` no mesmo condomínio. Situações de divergência usam somente opção opaca, assinada e temporária para vigilantes vinculados à rota; o cliente nunca fornece `tenant_id` ou `colaborador_id` livre.
- **Regra compartilhada de rondas**: criado `api/helpers/ronda_helper.php`, reutilizado pela API administrativa e pela móvel para dias ativos, ciclos e SLA sem duplicar cálculo.

### Validado
- O PDF BRCondos de referência foi processado com 41 unidades, 1.153 lançamentos, R$ 405.265,52 lançado e R$ 574.363,84 projetado, com totais conciliados e cobertura para Gleba com múltiplos lançamentos, proprietário `E OUTROS` e descrição extra longa.
- O parser possui fallback para ambientes PHP sem `mbstring`; consultas usam `tenant_id` exclusivamente da sessão e ordenações utilizam lista branca.
- QR de tenant diferente é recusado com HTTP 403, e a API revalida vínculo do vigilante, agenda, janela de ciclo e índice de duplicidade antes da gravação.
- Validadas sintaxe PHP, regras de ciclo/SLA, análise direcionada dos arquivos Flutter e testes Flutter. O detalhe técnico e roteiro pós-deploy estão em `ANALISE_MODULO_VIGILANTE_RONDAS_MOBILE_20260813.md`.


## [Unreleased] Login na raiz com URL limpa

Adicionado `<base href="/frontend/">` como primeiro elemento do `<head>` em `frontend/login.html`, para permitir servir o login na raiz sem quebrar assets, configuração, i18n ou referências relativas à API. O PR #8 foi mesclado e seu release foi publicado; o login continua separado da SPA e as rotas de login legado preservam a query ao redirecionar à raiz. O QA autenticado de login/logout, sessão expirada e recuperação permanece dependente de conta segura de teste.

### Rotas limpas da SPA (em revisão)
- `feature/urls-limpas` adiciona `/<slug>` para as páginas em `frontend/pages/*.html`, com compatibilidade para `/frontend/layout-base.html?page=<slug>`, histórico/back-forward, query de estado e paths absolutos do shell.
- O snippet `deploy/hetzner/erp-pages.conf` é gerado por `deploy/hetzner/generate-clean-page-routes.py` a partir dos **63** fragments atuais, com validação fail-closed e excludes para rotas reservadas; ambos os vhosts incluem `/etc/nginx/snippets/erp-pages.conf`. O roteamento especial `/projetos` e as regras existentes de proteção permanecem fora da allowlist.
- Testes locais do router passaram; gerador **10/10**, `--check` e `nginx -t` isolado com os dois vhosts/snippet passaram, incluindo slugs com barra final, os submenus SPA e a exclusão de caminhos não allowlisted sem alterar o fallback existente. A validação da configuração instalada e o deploy/reload de produção permanecem pendentes da revisão e do merge; nenhuma alteração Nginx de produção foi realizada nesta etapa.


## [Unreleased] Chat-In v2 — configuração por tenant, sons, Cutucar e digitando (2026-11-14)

Implementação na branch `feature/chatin-v2`, pendente de revisão/merge do André. Acrescenta preferências Web Audio locais (sem áudio de terceiros), opt-in de Notification API sem prévias, cursor de eventos paginado, polling 2,5 s na conversa focada, sinal visual de digitação com TTL, Cutucar com rate limit/Retry-After e push existente do morador. Adiciona configuração tenant-scoped do Chat-In, ações RBAC `visualizar/configurar`, seed de `visualizar` em grupos existentes, canal CHATIN indisponível quando o recurso é desligado e auditoria da tela de configuração. Migração de `push_chatin_ativo` para `recurso_push_app` ocorre ao inserir configuração nova, sem sobrescrever linhas tenant já existentes.

A análise do schema encontrou `moradores.ativo`; não encontrou campo confiável de titular/proprietário/inquilino. `dependentes.parentesco` modela relação familiar, não titularidade, por isso nenhum filtro de perfil foi implementado. A migration foi exercitada duas vezes em MariaDB 10.11 isolado e ainda exige validação MySQL 8.4 real após revisão/backup; equivalente PostgreSQL, testes com sessões reais/20 usuários e produção permanecem pendentes. Nenhum banco, backup, serviço ou release de produção foi alterado.


### P3.2 — correção Portal Bearer/tenant (2026-11-16; reteste HML pendente)

A matriz HTTP Portal inicial no 8444 encontrou 401 em Convidados e Chat-In e token ainda válido depois do logout (16/21 casos passaram). Causa: o login omitira `sessoes_portal.tenant_id` em schemas que possuem a coluna e não guardava o Bearer no estado PHP server-side usado pelo logout. `DatabaseDialect::columnExists()` agora verifica metadata de coluna preparada e limitada ao schema corrente. O login inclui tenant derivado do cadastro quando `tenant_id` existe e conserva o INSERT legado quando não existe; o logout pode então revogar por token, morador e tenant da sessão, sem aceitar token do cliente/querystring. O Portal PostgreSQL continua bloqueado. Os testes sintéticos de metadata/auth e o novo teste contratual Portal passaram; linter e matriz HTTP serão recontados/repetidos após publicar uma release corretiva isolada. Sem merge ou deploy de produção.


### P3.2 — aceite da matriz HTTP Portal Bearer HML 8444 (2026-11-16)

Reteste da release corretiva `0578cf3ba43b79376e86ccf746d4d86965912af8`: **22/22 PASS** com PHP-FPM HML e MySQL `erp_hml_mysql`, usando apenas morador/condomínio sintéticos. Passaram login, Bearer em perfil, convidados, visitantes, GED e Chat-In, criação/cancelamento sintéticos, tokens inválido/expirado/revogado, rotação, falha forçada com rollback preservando o token anterior e logout. A sessão/token sintético foi revogado, a presença Chat-In restaurada, a trigger temporária removida e Basic Auth restaurado; sem mudança em produção. A comparação de checksums de configuração e o smoke read-only da produção passaram; a conexão efetiva de `www-data` confirmou `mysqli`/`erpcondominios_stage_20261114`. Isso habilita revisão/merge do PR #20 por André, mas não autoriza deploy/cutover.


## [Unreleased] PostgreSQL P2 — catálogo v8 e tradução limitada de CHECK (2026-11-19)

- O catálogo versionado na branch WIP passou a v8; gerador DDL 2.3.0 armazena CHECK somente como tradução estruturada validada ou review agregado, sem manter a expressão MySQL original no snapshot. A gramática aceita apenas CHECK textual estático `IN` e numérico `BETWEEN`; duas constraints enforced do clone passaram com testes sintéticos. CHECK não enforced e qualquer sintaxe fora da gramática continuam bloqueados.
- Foi adicionada allowlist textual mínima por valor/semântica: conjunto finito de status, forma `#RRGGBB`, versão numérica, locales suportados e uma única coluna `chatin_preferencias.som_tipo` cuja lista é provada por CHECK enforced da mesma coluna. Campos sensíveis, de integração e os demais defaults de texto permanecem fail-closed. A denylist foi movida antes da aceitação de string vazia, trazendo de volta ao review três defaults vazios de chave/credencial/IP; o bundle v8d final tem 64 reviews (11 liberações antigas, 3 novos bloqueios sensíveis, redução líquida de 8 em relação aos 72), permanece `executable_complete=false` e nenhum load versionado foi executado.
- A política P2 MySQL `TIME` → PostgreSQL `INTERVAL` foi fixada e testada para sinais negativos e durações >24 h. As diferenças do target criado pelo loader rápido não comprovam falha nem equivalência do rebuild; uma carga P2 bem-sucedida é necessária antes de comparar tipos.
- Resultado local: 215 testes P2 aprovados, `py_compile`, `bash -n` e `git diff --check` aprovados. Sem alteração em `erp_pg_dev`, source MySQL, produção, main, DNS, runtime 8443 ou cutover. Estado detalhado: `AI-CONTEXT/DATABASE.md` §22.4 e `AI-CONTEXT/POSTGRESQL_P2.md`.


### Projeto PostgreSQL P2 — captura inicial v12 interrompida antes do load (2026-11-19)
- Release `aff56b4` instalado e validado fora de `current`; a primeira captura parou no gate de fingerprint por import ausente de `hashlib`. O erro foi detectado no log privado sem divulgar seu conteúdo.
- Nenhum bundle foi promovido/selecionado; nenhum preflight, load, escrita em `erp_pg_dev`, quick-loader, trigger, neutralização ou troca do 8443 ocorreu. Source foi lido apenas em modo READ ONLY. Staging e logs foram preservados.
- Fix local importa `hashlib` e adiciona teste direto da proveniência: 15/15 testes focados, `py_compile`, `bash -n` e `git diff --check` PASS. A nova tentativa exige commit/push e release versionada.


### Projeto PostgreSQL P2 — preflight v12 e gate BIGINT UNSIGNED (2026-11-19)
- Nova captura do catálogo e bundle v12 passou com 276 tabelas, 11 FKs, 7 triggers e zero reviews; preflight read-only alinhou o snapshot, detectou zero NUL em texto e confirmou que NUL binário só ocorre no objeto Base64 allowlisted, com concordância dos dois métodos.
- O primeiro `load` foi recusado por argumento incorreto; a chamada válida bloqueou antes da conexão de carga em `UNSIGNED_BIGINT_RANGE_SCAN_REQUIRED`. Nenhum DDL/COPY ou escrita no `erp_pg_dev` ocorreu.
- Correção local: loader consome metadados de range scan e promoção FK do DDL com validação estrita; `loader.py` agora integra o fingerprint do gerador. Testes completos: 251 PASS, incluindo o par sintético de RBAC. Commit e nova release ainda pendentes.


### Projeto PostgreSQL P2 — rollback de load por colisão de PK (2026-11-19)
- Captura v12 e preflight da release `0481548` passaram; load abortou com `POSTGRES_PRIMARY_KEY_CREATE_FAILED` / `42P07` na criação post-load das PKs.
- O report privado confirmou `FAILED_ROLLED_BACK` e limpeza source/target/spool; carga não aceita, sem quick-loader/comparação ou validação pós-carga.
- Causa: nome `PRIMARY` repetido para índices de várias tabelas. DDL corrigido para gerar nome único por tabela; 251 testes P2 e gates PHP/SQL/sintaxe passaram. Fix ainda aguarda commit/push e nova release/captura/preflight/load.


### PostgreSQL P2/P3/P5 — preparação local e gates — 2026-11-20

- Canonicalização de JSON no reconciliador: `json.loads` usa `Decimal` para inteiros e decimais, com chaves ordenadas; regressão sintética confirma equivalência numérica. O diagnóstico agregado por coluna permanece limitado a `monitoramento_eventos_hub`.
- Login web/CPF legado e sessão Portal Bearer ganharam caminho PostgreSQL tenant-scoped; 20 testes auth/RBAC e 12 Portal/Bearer passaram. Quatro `php -l` e linter SQL explícito passaram; homologação HTTP/PHP-FPM live ainda pendente.
- Runners P5 locais: suíte sintética 18 testes + 4 subtestes. Captura de catálogo segue restrita a `erp_pg_source`; manutenção cobre os dois vhosts 443 com exceção IPv4 `/32` root-only e sem reload; rollback não opera bancos. Backup final permanece fail-closed até definição de credencial dedicada.
- Gates adicionais: 288 testes P2 + 117 subtestes, `py_compile`, `bash -n` e `git diff --check` PASS. Nenhuma reconciliação live, carga, alteração na VM/Nginx/produção, troca do 8443 ou cutover foi executada. A divergência já registrada em `monitoramento_eventos_hub` e a retry single-use consumida mantêm P2 e P5 em NO-GO.


### Hardening local P2/P3/P5 — 2026-11-20
- P2: canonicalização JSON do reconciliador com `Decimal`, forma científica exata, rejeição sanitizada de Unicode inválido e teste sintético do streaming MySQL bytes × PostgreSQL text. Nenhuma reconciliação live repetida.
- P5: backups de manutenção com nomes exclusivos; `activate`/`deactivate` restauram o marcador após falha de `nginx -t`, com erros específicos se a restauração também falhar. Nenhum runner executado na VM nem reload Nginx.
- P3: rotação de sessão no login CPF, rejeição genérica de CPF/e-mail ambíguos, erros operacionais Bearer como HTTP 503 e logout confirmado antes de limpar sessão/token no cliente. Fallback de senha em texto claro e persistência atual do token continuam pendências explícitas, sem mudança de schema.
- Verificação local: 289 testes P2, 22 P5 e suítes sintéticas P3 passaram; PHP lint e linter SQL nos quatro endpoints, `py_compile`, `bash -n` e `git diff --check` passaram.
- Sem banco/VM/live HTTP, carga, mudança no 8443, produção, `main`, DNS ou cutover. P2 continua bloqueada pela divergência registrada de `monitoramento_eventos_hub` e pela retry single-use consumida; P5 segue NO-GO e o backup final permanece bloqueado até política da credencial dedicada.


### Complemento da revisão local P3/P5 — 2026-11-20
- Login CPF: falha tardia após persistência/commit do Bearer agora tenta revogação compensatória por token, morador e tenant antes de limpar a sessão; falhas operacionais retornam 503. Logout aceita Bearer válido após expiração da sessão PHP, mantendo escopo derivado do token; a rota GET preserva `/frontend/login_morador.html`. Regressões sintéticas cobrem os caminhos.
- Runner P5: `prepare` relê e compara configuração/allowlist/vhosts/estado sob lock, antes de backup e mutação; `_write` limpa seu temporário próprio quando há falha parcial. Regressões sintéticas cobrem os dois caminhos.
- Logout Bearer: sem sessão PHP, a consulta exige exatamente uma sessão ativa/não expirada; o destino GET é `/frontend/login_morador.html`. Falhas de conexão respondem 503 genérico.
- Revisões estáticas independentes: nenhuma falha encontrada no reconciliador P2; observações de testes fake permanecem riscos de validação live. Nenhum acesso a bancos/VM, runner real, HTTP live ou alteração de produção.


Gates finais: P2 289/289, P5 26/26, auth/RBAC P3 20/20, Portal/logout 18/18 e bootstrap 13/13; quatro `php -l`, `pg_sql_lint` dos quatro endpoints, `py_compile`, `bash -n` e `git diff --check` passaram. As suítes locais continuam sintéticas; não houve conexão com banco nem HTTP/PHP-FPM live.
