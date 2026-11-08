# Pipeline MySQL → PostgreSQL 18 (ensaio v9)

O pipeline é exclusivo para a cópia de ensaio restaurada do dump autorizado e para um database novo do cluster PostgreSQL 18 socket-only. Não serve para produção/virada. No estado atual, a API/ERP não se conecta ao PostgreSQL.

## Pré-condições no servidor

- Repositório Git oficial em `/opt/erpcondominios/pg-03-conversion`, branch `pg/03-conversao-schema`, commit explicitamente revisado, árvore limpa e origin `https://github.com/ASOARESBH/app.erpcondominios.com.br-.git`.
- Clone oficial de referência em `/opt/erpcondominios/source`, branch `main`, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`, limpo.
- PostgreSQL 18 isolado em `/var/lib/erp-pg-rehearsal/pg-data`, Unix socket `/var/lib/erp-pg-rehearsal/pg-socket`, porta privada 55432, `listen_addresses=''`; o runner recusa reutilizar cluster já ativo.
- Cópia MySQL 5.7 isolada em Podman `network=none`, sem portas publicadas, read-only, Event Scheduler OFF, AppArmor dedicado, no-new-privileges e segredo montado read-only. A conexão da ferramenta ocorre somente no loopback do namespace do container.
- Arquivos de autenticação de MySQL em diretório root-only, `0400` ou `0600`, verificados por `lstat`/`O_NOFOLLOW`; o runner copia credencial somente para tmpfs e limpa ao encerrar.
- Dependências Python fixadas em `requirements.txt` e instaladas no venv privado `/var/lib/erp-pg-rehearsal/v9/venv`.
- Database de destino inexistente. O runner cria apenas `erp_ensaio_v9`; recusa qualquer outro nome, banco preexistente ou preenchido, e não executa DROP/TRUNCATE.

## Execução

Depois de publicar e revisar o commit dedicado, o operador autorizado executa no servidor (o SHA deve ser substituído pelo commit revisado):

```bash
sudo /usr/bin/python3 /opt/erpcondominios/pg-03-conversion/tools/migracao_pg/run_migration.py \
  --database erp_ensaio_v9 \
  --expected-commit <SHA40_REVISADO> \
  --batch-size 1000
```

O runner aplica em sequência catálogo/DDL, schema vazio, carga streaming, PKs/índices, sequences, triggers/routines e grants mínimos. Executa o harness transacional antes de adicionar FKs: mesmo `NOT VALID`, uma FK PostgreSQL impõe integridade em novas linhas, enquanto os fixtures do harness usam referências sintéticas. Depois do harness, cria as FKs `NOT VALID`, executa `ANALYZE` e reconcilia; a reconciliação valida cada FK sem órfãos e deixa somente as que têm órfãos como `NOT VALID`, sem apagar registros. Em sucesso ou falha tratado, para somente o cluster de ensaio com `pg_ctl -w` e confirma que `postmaster.pid` desapareceu; não declara sucesso sem a parada verificada e a limpeza da credencial. Em SIGTERM/SIGINT, tenta encerrar o subprocesso e o cluster de ensaio de forma controlada; uma terminação abrupta como SIGKILL/OOM exige verificação read-only e não autoriza reaproveitar o database parcial. A credencial é removida do tmpfs.

## Artefatos e privacidade

- Logs e resultados da execução: `/var/lib/erp-pg-rehearsal/v9/runs/`, owner `postgres`, diretórios `0700`, arquivos `0600`.
- DDL gerado: fica inicialmente no diretório privado da execução; após revisão e sanitização, somente scripts sem dados/segredos podem ser copiados para `sql/postgresql/` na branch própria.
- Reconciliation CSV/JSON contém apenas nomes estruturais, números, status, métricas e fingerprints; nunca valores de linhas.
- Dump, banco restaurado, credentials, `client.cnf`, `pgloader.cnf`, logs brutos, SQL de dados e conteúdo de linhas não podem ser copiados para o repositório.

## Critérios de bloqueio/aceite

O pipeline deve terminar exit 0 e registrar `ACCEPTED` somente após 270/270 tabelas e 3.339 colunas, contagens e hashes por linha, máximos de PK, 232 identities em `max+1/is_called=false`, sums numeric, comprimento/conteúdo binário, buckets de tenant, 11 FKs com assinatura exata e validação (ou órfãos contados e constraints `NOT VALID`), comparação de até 20 timestamps por tabela, testes sintéticos das sete triggers e duas procedures, métricas de zero-dates, execução `ANALYZE`, parada confirmada do cluster e limpeza do tmpfs.

Qualquer diferença de catálogo, contagem/hash, NUL textual, TIME não representável, precisão perdida ou FK inesperada, falha de tenancy/trigger/role, cleanup incompleto ou estado de cluster ambíguo bloqueia. Valores ENUM observados fora do domínio são copiados literalmente e preservados no CHECK do destino; o relatório registra somente contagens, nunca os valores. Não corrigir linhas, apagar órfãos ou ignorar erros automaticamente; reportar somente números/status e solicitar decisão quando necessária.
