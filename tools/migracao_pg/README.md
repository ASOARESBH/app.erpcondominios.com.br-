# Pipeline MySQL → PostgreSQL 18 (ensaios v9 e v10)

O pipeline é exclusivo para a cópia de ensaio restaurada do dump autorizado e para um database novo do cluster PostgreSQL 18 socket-only. Não serve para produção/virada. No estado atual, a API/ERP não se conecta ao PostgreSQL.

## Pré-condições no servidor

- Repositório Git oficial em `/opt/erpcondominios/pg-03-conversion`, branch `pg/03-conversao-schema`, commit explicitamente revisado, árvore limpa e origin `https://github.com/ASOARESBH/app.erpcondominios.com.br-.git`.
- Clone oficial de referência em `/opt/erpcondominios/source`, branch `main`, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`, limpo.
- PostgreSQL 18 isolado em `/var/lib/erp-pg-rehearsal/pg-data`, Unix socket `/var/lib/erp-pg-rehearsal/pg-socket`, porta privada 55432, `listen_addresses=''`; o runner recusa reutilizar cluster já ativo.
- Cópia MySQL 5.7 isolada em Podman `network=none`, sem portas publicadas, read-only, Event Scheduler OFF, AppArmor dedicado, no-new-privileges e segredo montado read-only. A conexão da ferramenta ocorre somente no loopback do namespace do container.
- Arquivos de autenticação de MySQL em diretório root-only, `0400` ou `0600`, verificados por `lstat`/`O_NOFOLLOW`; o runner copia credencial somente para tmpfs e limpa ao encerrar.
- Dependências Python fixadas em `requirements.txt` e instaladas no venv privado `/var/lib/erp-pg-rehearsal/v9/venv`.
- Em execução nova, o database `erp_ensaio_v9` precisa não existir; o runner recusa qualquer outro nome, database pré-existente ou preenchido, e não executa DROP/TRUNCATE. `--resume-partial-target` é restrito ao run privado `20261108T181755Z` e ao commit que criou o alvo parcial; exige commit descendente, valida artefatos e contagens, e nunca recarrega nem apaga o target.
- A retomada `--resume-post-fk-reconciliation` é um modo separado e exclusivo para o run privado `20261108T203651Z`; exige `--source-run-id 20261108T203651Z`, commit descendente de `d4447f9…` e manifesto root-only `/root/erp_pg_v9_post_fk_20261108_manifest.json`. Confere hashes dos dois runs e dos DDLs, história do harness/FKs/ANALYZE, estado do target (270 tabelas, 11 FKs `NOT VALID`, tipos/contagens e checks) e catálogo regenerado. Ela não chama loader, harness, criação do database, `apply_sql` nem `ANALYZE`; executa somente a reconciliação. Qualquer FK ausente, extra ou já validada bloqueia antes da reconciliação.
- O modo integral v10 usa branch `pg/03-1-ensaio-integral-v10`, paths privados próprios `/var/lib/erp-pg-rehearsal/v10/` e `/dev/shm/erp-pg-v10/`, database exclusivo `erp_ensaio_v10` e lock separado. O database alvo não pode existir e qualquer opção de resume é recusada; as raízes de execução podem existir somente com owner/mode verificados, para preservar logs/runs anteriores sem sobrescrevê-los e permitir outra tentativa de setup. O tmpfs é preparado/limpo somente por verificações restritas a artefatos conhecidos; entradas inesperadas bloqueiam e exigem revisão manual, sem remoção automática. Não limpa nem sobrescreve `erp_ensaio_v9` ou outros ensaios.
- Antes de iniciar cluster ou criar database v10, valida o arquivo original e `source-copy.sql.gz` por `lstat`, proprietário/permissões, SHA-256, tamanho, `cmp` byte a byte e `gzip -t`, contra o manifesto root-only `/root/erp_pg_v10_source_manifest.json`. O manifesto não é versionado. Se catálogo/dump não baterem com os contratos revisados, o target ainda não foi criado.
- No fluxo v10, o gate de catálogo confirma 270 tabelas/3.339 colunas, 240 PKs, 11 FKs, 7 triggers, 2 procedures, zero functions/views/events, 232 identities, cobertura 228/42 de `tenant_id`, 111 colunas `ON UPDATE`, 83 colunas `COLLATE "C"`, cinco grupos zero-date, seis grupos ENUM e as oito tabelas `legado`. O summary privado inclui os 270 nomes de tabela para que a reconciliação exija correspondência exata de nome e schema (`erp`/`legado`), além das métricas.
- O fluxo aplica schema, PKs/índices antes da carga, carrega em streaming, instala timestamps/routines/triggers/grants mínimos, executa o harness transacional, aplica FKs `NOT VALID`, prepara identities/sequences e faz `ANALYZE`. A reconciliação valida as FKs e o runner exige zero órfãos, zero divergências e cada uma das 270 tabelas exatas do catálogo aprovada; qualquer mismatch resulta em exit diferente de zero e evidência privada preservada. O gate terminal também valida no JSONL privado a ordem exata das 14 fases, os 10 testes funcionais e o marcador de privilégios mínimos `erp_app_minimum_privileges` (11 eventos distintos), o resumo de carga/reconciliação, a criação do target pela execução atual e a parada do cluster antes de emitir `ACCEPTED`.
- A normalização de zero-date e a preservação literal de ENUM vazio têm ressalvas funcionais registradas em `V10_PRE_EXECUTION_REVIEW.md`. Esta fase não altera APIs nem escolhe novos valores de negócio: adaptações de entrada/leitura para `NULL` e validações futuras dos domínios ficam para fase posterior, sem reescrever o histórico.

## Execução

Para o ensaio v9 histórico, depois de publicar e revisar o commit dedicado, o operador autorizado executa no servidor (o SHA deve ser substituído pelo commit revisado):

```bash
sudo /usr/bin/python3 -B /opt/erpcondominios/pg-03-conversion/tools/migracao_pg/run_migration.py \
  --database erp_ensaio_v9 \
  --expected-commit <SHA40_REVISADO> \
  --batch-size 1000
```

Para o ciclo integral v10 desde zero, após revisão do commit da branch dedicada e criação/verificação do manifesto root-only no servidor:

```bash
sudo -n /usr/bin/python3 -B /opt/erpcondominios/pg-03-1-ensaio-integral-v10/tools/migracao_pg/run_migration.py \
  --database erp_ensaio_v10 \
  --expected-commit <SHA40_REVISADO> \
  --batch-size 1000
```

Não executar novamente sobre `erp_ensaio_v10` existente; após falha, preservar log, diretório do run, target e estado do cluster para diagnóstico. Uma falha de setup anterior à criação do database registra `runner_setup_failed` e `target_created=false`, tenta remover somente tmpfs vazio/artefatos reconhecidos e não elimina runs nem logs. O v10 não aceita retomadas.

Para a retomada pós-FK aprovada, acrescente `--resume-post-fk-reconciliation --source-run-id 20261108T203651Z`; use somente o SHA revisado descendente do commit-base documentado acima. Não use `--resume-partial-target` junto com essa opção.

No v9 histórico, o runner aplica em sequência catálogo/DDL, schema vazio, carga streaming, PKs/índices, sequences, triggers/routines e grants mínimos. No v10, por sua vez, PKs/índices são aplicados antes da carga e sequences só depois do harness e das FKs. Em ambos os modos, o harness transacional precede a criação das FKs: mesmo `NOT VALID`, uma FK PostgreSQL impõe integridade em novas linhas, enquanto os fixtures do harness usam referências sintéticas. Depois do harness, cria as FKs `NOT VALID`, executa `ANALYZE` e reconcilia; o v9 admite órfãos contados e deixa somente essas constraints como `NOT VALID`, enquanto v10 exige zero órfãos e valida as 11 FKs. Em sucesso ou falha tratada, para somente o cluster de ensaio com `pg_ctl -w` e confirma que `postmaster.pid` desapareceu; não declara sucesso sem a parada verificada e a limpeza da credencial. Em SIGTERM/SIGINT, tenta encerrar o subprocesso e o cluster de ensaio de forma controlada; uma terminação abrupta como SIGKILL/OOM exige verificação read-only e não autoriza reaproveitar o database parcial. A credencial é removida do tmpfs próprio da versão.

O modo pós-FK repete somente a reconciliação sobre o target existente. A comparação de tabelas, FKs, tipos, checks e tenancy é somente leitura; a validação das constraints elegíveis ocorre em uma transação única depois de todos os demais gates passarem, para não deixar um subconjunto acidentalmente validado.

As 11 assinaturas de FK são verificadas contra allowlist exata. Neste snapshot, somente três pares têm tipos incompatíveis: `aplicativos_versoes.aplicativo_id` e `moradores_anexos.morador_id` passam de `integer` para `bigint` com `CHECK` preservando o domínio MySQL signed INT; `rbac_auditoria.sessao_id` passa de `numeric(20,0)` para `bigint` somente após confirmar novamente 791 linhas e zero nulos, frações, negativos ou valores fora da faixa signed. A regeneração do catálogo e comparação de DDL permitem somente essas diferenças; os três `ALTER TYPE`, os checks e as 11 FKs são aplicados por `psql -1` como bundle atômico. Isso não define a regra da migração final nem aceita valores unsigned fora da faixa signed.

A reconciliação canonicaliza somente os aliases equivalentes de `format_type` (`character varying(n)` → `varchar(n)` e `character(n)` → `char(n)`), preservando rigorosamente o typmod; diferenças de comprimento ou de tipo continuam bloqueando.

## Estado do ensaio atual

O run pós-FK `20261108T214035Z` terminou exit 0/`ACCEPTED`: 270/270 tabelas (202.609 linhas em cada lado), zero divergência de hash, PK/identity/sequence, numeric, binário, buckets tenant e timestamps; as 11 FKs foram validadas sem órfãos. O CSV contém 270 linhas. Esse resultado aceita a equivalência do target preservado, mas não demonstra um único ciclo contínuo de criação/carga/testes/reconciliação desde o zero; houve retomadas fail-closed de estágios após correções publicadas. Não limpar o target nem os ensaios antigos até a decisão de aceite global por André. O cluster v9 está parado.

## Artefatos e privacidade

- Logs e resultados da execução: `/var/lib/erp-pg-rehearsal/v9/runs/`, owner `postgres`, diretórios `0700`, arquivos `0600`.
- DDL gerado: fica inicialmente no diretório privado da execução; após revisão e sanitização, somente scripts sem dados/segredos podem ser copiados para `sql/postgresql/` na branch própria.
- Reconciliation CSV/JSON contém apenas nomes estruturais, números, status, métricas e fingerprints; nunca valores de linhas.
- Dump, banco restaurado, credentials, `client.cnf`, `pgloader.cnf`, logs brutos, SQL de dados e conteúdo de linhas não podem ser copiados para o repositório.

## Critérios de bloqueio/aceite

O pipeline deve terminar exit 0 e registrar `ACCEPTED` somente após 270/270 tabelas e 3.339 colunas, contagens e hashes por linha, máximos de PK, 232 identities em `max+1/is_called=false`, sums numeric, comprimento/conteúdo binário, buckets de tenant, 11 FKs com assinatura exata, comparação de até 20 timestamps por tabela, testes sintéticos das sete triggers e duas procedures, métricas de zero-dates, execução `ANALYZE`, parada confirmada do cluster e limpeza do tmpfs. O target v10 exige adicionalmente que a reconciliação por tabela tenha 270 chaves únicas, 202.609 linhas totais em cada lado, todas as 232 identities/sequence prontas, 11 FKs validadas e nenhum órfão ou mismatch de hash, PK, numeric, binário, tenant ou timestamp.

Qualquer diferença de catálogo, contagem/hash, NUL textual, TIME não representável, precisão perdida ou FK inesperada, falha de tenancy/trigger/role, cleanup incompleto ou estado de cluster ambíguo bloqueia. Valores ENUM observados fora do domínio são copiados literalmente e preservados no CHECK do destino; o relatório registra somente contagens, nunca os valores. Não corrigir linhas, apagar órfãos ou ignorar erros automaticamente; reportar somente números/status e solicitar decisão quando necessária.
