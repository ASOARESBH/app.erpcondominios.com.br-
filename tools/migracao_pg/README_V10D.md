# Fase C.4 — ensaio integral `erp_ensaio_v10d`

## Resultado da execução autorizada — 10/11/2026

**Status: `BLOCKED` — exit code 1.** A única execução integral começou do zero no SHA `323b4c3a226d113d3f25002f8f6e58d842d36bd7`, branch `pg/03-3-ensaio-integral-v10d`, run `20261110T205901Z`. O pipeline parou na fase `catalog`, antes de iniciar o cluster PostgreSQL ou criar o database de destino. O target não foi aceito e não há autorização para retomar, reiniciar ou criar outro target.

### Proveniência e estado de entrada

- Dump-fonte: `source-copy.sql.gz`, 355.697.322 bytes; SHA-256 conferido contra o manifesto privado: `1e79b7172c8d0141e63b90400d91baae084f99ab1ecf47661a8990b4e5dd5fe8`. A atestação de restauração e o container-base corresponderam ao manifesto; `gzip -t` passou.
- A origem de execução foi uma cópia MySQL 5.7.44 restaurada em container v10d próprio, com rede `none`, sem portas publicadas, `bind-address=127.0.0.1`, AppArmor e Event Scheduler desligado. O clone ficou com `read_only=1` e `super_read_only=1` antes da execução. O MySQL original não foi acessado ou alterado.
- Inventário metadata-only do schema restaurado: 270 tabelas base, 0 views, 3.339 colunas, 240 tabelas com PK, 232 colunas `AUTO_INCREMENT`, 11 FKs, 7 triggers e 2 procedures. Nenhuma linha de aplicação foi consultada para este inventário.
- O commit executado continha a correção C.2 e os gates preparados para C.4. Antes do ensaio, a suíte da branch tinha 97 testes aprovados (90 herdados + 7 novos), com AST e `git diff --check` aprovados.

### Diagnóstico do bloqueio

O log sanitizado registra: `pipeline_started`, `mysql_source_isolated`, `routine_fingerprints_verified` (2) e, em seguida, `step_failed` na fase `catalog`, classe `OperationalError`, `error_code=unclassified`, sem SQLSTATE. A conexão da conta técnica indicada pelo arquivo privado não autenticava no MySQL restaurado porque o dump não contém essa conta do sistema. A confirmação posterior foi feita apenas por status/errno e metadados; nenhum segredo ou hash foi exibido.

Após a falha, para ajustar apenas usuários/grants do clone, `read_only` e `super_read_only` foram temporariamente desativados no MySQL v10d; foram então restaurados e confirmados ativos. O ajuste criou primeiro uma identidade uppercase, que não correspondia ao nome lowercase esperado pelo MySQL 5.7 e pelo arquivo protegido; depois foi criada a conta lowercase com privilégios mínimos `SELECT`, `SHOW VIEW` e `TRIGGER`. O helper oficial read-only então passou. Nenhuma tabela de aplicação foi alterada nessa correção. Isso é diagnóstico posterior à tentativa; **não autoriza uma segunda execução**. O run root já existe, o runner é fresh-only e a tentativa permanece preservada.

### Encerramento e preservação

- O runner retornou exit code 1; cleanup sem erro. O cluster v10d permaneceu efetivamente parado, `postmaster.pid` ausente e o tmpfs de credenciais do runner vazio.
- O PostgreSQL v10d foi inicializado em PGDATA próprio, mas não foi iniciado; o database `erp_ensaio_v10d` não foi criado. O run, lock, snapshot de rotinas e logs privados foram preservados para diagnóstico.
- O container MySQL v10d foi parado. Os três arquivos temporários de credenciais copiados para o diretório privado v10d foram removidos. O container/volume v10d e logs permanecem preservados; nenhum valor de credencial foi incluído no Git ou relatório.
- `erp_ensaio_v9`, `erp_ensaio_v10`, `erp_ensaio_v10b` e `erp_ensaio_v10c` não foram acessados nem alterados. Não houve alteração na aplicação, produção, MySQL original, deployment, DNS ou cutover.

## Invariantes do target

- Nome único aceito pelo runner e subprocessos: `erp_ensaio_v10d`.
- PostgreSQL dedicado: `PGDATA=/var/lib/erp-pg-rehearsal/v10d/pg-data`, socket `/var/lib/erp-pg-rehearsal/v10d/pg-socket`, porta privada `55433`, `listen_addresses=''`.
- Ambiente Python: `/var/lib/erp-pg-rehearsal/v10d/venv`, com dependências fixadas em `requirements.txt`.
- Diretório privado `/var/lib/erp-pg-rehearsal/v10d`, propriedade `postgres`, modo `0700`.
- Segredos temporários: diretório `secrets`, root-owned, modo `0700`; arquivos regulares root-owned, sem symlink, um hard link e modo `0400` ou `0600`.
- Container MySQL exclusivo `erp-mysql-source-v10d`, sem rede ou portas publicadas, somente-leitura, Event Scheduler desligado e montagem read-only dos segredos v10d.
- O run root `/var/lib/erp-pg-rehearsal/v10d/runs` precisa inexistir antes de uma execução. O runner o cria uma vez; se já existir, não retoma nem sobrescreve artefatos.
- O lock exclusivo é `runs/.runner.lock`. Diretórios e arquivos de execução são privados.

Os alvos históricos não são inputs do v10d. A criação do database exige nome exato e inexistência prévia. Esta branch não implementa retomada.

## Proveniência e gates de código

O runner exige root, branch limpa `pg/03-3-ensaio-integral-v10d`, origin exclusivamente `https://github.com/ASOARESBH/app.erpcondominios.com.br-.git`, SHA40 explícito e ancestralidade do commit C.2. O clone de classificação legacy também precisa corresponder ao `main` aprovado `f60739713e6de3f2473b79ecd14a70fd700e5462`.

`--database` aceita exclusivamente `erp_ensaio_v10d`. O runner não expõe modos de resume. Catálogo, loader, harness, reconciliador e verificador impõem o mesmo destino e endpoint exclusivos.

## Gate de aceite codificado

Para eventual execução autorizada futura, o runner exige cumulativamente: 270 tabelas, 3.339 colunas, 240 PKs, 232 identities, 202.609 linhas carregadas e reconciliadas, 11/11 FKs válidas, zero órfãos, zero divergências nas categorias codificadas e status final exatamente `ACCEPTED`. Qualquer valor ausente ou inesperado bloqueia. A falha preserva artefatos parciais e não permite retomada.

## Testes desta branch

A suíte usa fixtures sintéticas locais. A prova C.3 anterior executou o harness em PostgreSQL 18.6 com Psycopg 3.3.8. Os 90 testes herdados do SHA C.2 foram preservados; sete testes novos cobrem database/endpoint exclusivos, rejeição de resume, ancestralidade C.2, criação de target, gate de aceite exato e proteção do verificador de conexão, totalizando 97. Esses testes não substituem a migração integral; o ensaio C.4 descrito acima foi bloqueado.

Comando local de QA:

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s tests/migracao_pg -p 'test_*.py'
```

## Comando executado — histórico, não repetir

```bash
sudo /usr/bin/python3 -B /opt/erpcondominios/pg-03-3-ensaio-integral-v10d/tools/migracao_pg/run_migration.py \
  --database erp_ensaio_v10d \
  --expected-commit 323b4c3a226d113d3f25002f8f6e58d842d36bd7 \
  --batch-size 1000
```

O comando retornou `1` na única execução. Não rodar novamente com este run root nem retomar o pipeline. Qualquer novo ensaio exigirá uma solicitação e autorização expressas, novo target exclusivo e SHA revisto. A Fase D, adaptação PDO, integração da aplicação, deploy, DNS e cutover continuam fora do escopo.
