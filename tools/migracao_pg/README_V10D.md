# Fase C.4 — preparação do runner `erp_ensaio_v10d`

## Estado

Esta branch prepara o runner para um ensaio integral novo, do zero, com destino exclusivo `erp_ensaio_v10d`. A alteração **não executa** o pipeline nem comprova que os paths, o cluster, o container MySQL ou os segredos já existam na Hetzner. Não use este runbook como evidência de execução aprovada.

Base de código: commit C.2 `c4703e9e153fd2d156f8c9dab398c12d72dd19cf`, cuja correção do preflight foi integrada à prova C.3 em PostgreSQL 18.6/Psycopg 3.3.8. A branch final precisa ser revisada e ter o SHA exato confirmado antes de qualquer execução.

## Invariantes do target

- Único nome aceito pelo runner e pelos subprocessos: `erp_ensaio_v10d`.
- PostgreSQL dedicado: `PGDATA=/var/lib/erp-pg-rehearsal/v10d/pg-data`, socket `/var/lib/erp-pg-rehearsal/v10d/pg-socket`, porta privada `55433`, `listen_addresses=''`.
- Ambiente Python esperado: `/var/lib/erp-pg-rehearsal/v10d/venv` com dependências fixadas em `requirements.txt`.
- Diretório privado esperado: `/var/lib/erp-pg-rehearsal/v10d`, diretório de propriedade do usuário `postgres`, modo `0700`.
- Segredos em `/var/lib/erp-pg-rehearsal/v10d/secrets`, propriedade root, modo `0700`; cada configuração de cliente deve ser arquivo regular root-owned, sem symlink, um único hard link e modo `0400` ou `0600`.
- Container MySQL exclusivo `erp-mysql-source-v10d`, sem rede/portas publicadas, origem read-only, Event Scheduler desligado e montagem read-only dos segredos v10d.
- Run root `/var/lib/erp-pg-rehearsal/v10d/runs` deve ainda não existir. O runner o cria uma vez. Se já existir, ele para antes de iniciar o ensaio: não retoma nem sobrescreve artefatos.
- O lock exclusivo fica em `runs/.runner.lock`. O diretório e os arquivos de execução permanecem privados e pertencem ao PostgreSQL, salvo o lock root-only.

As referências históricas a v9 e os respectivos diretórios/bancos não são inputs nem targets da execução v10d. O código não contém DROP/TRUNCATE do target anterior; a criação do database verifica que o nome exato ainda não existe e bloqueia se já existir.

## Proveniência e bloqueios antes de executar

O runner exige root, branch oficial limpa `pg/03-3-ensaio-integral-v10d`, origin exclusivamente `https://github.com/ASOARESBH/app.erpcondominios.com.br-.git`, SHA40 exato informado pelo operador e ancestralidade do commit C.2 aprovado. Também confere que o clone usado para classificar objetos legacy continua limpo no baseline `main` aprovado.

`--database` aceita exclusivamente `erp_ensaio_v10d`. O runner não expõe modos de resume nem `--source-run-id`; argparse os rejeita antes de qualquer efeito. Loader, catálogo, harness, reconciliador e verificador de conexão impõem o mesmo destino e endpoint próprios.

Antes de uma execução futura, o operador deve validar e registrar separadamente a proveniência do dump/cópia MySQL e seu manifesto de origem (SHA-256, identificação não sensível do artefato e vínculo com o container restaurado). O manifesto e os artefatos da cópia não são versionados. **A documentação desta branch não substitui essa validação**; sem evidência verificável do manifesto, não iniciar a migração integral.

## Gate de aceite codificado

A reconciliação precisa concluir e o runner exige, cumulativamente:

- 270 tabelas, 3.339 colunas e 240 tabelas com primary key;
- 232 colunas identity/AUTO_INCREMENT;
- 202.609 linhas na origem carregadas e reconciliadas, com 270 métricas de tabela únicas;
- 11/11 foreign keys validadas e zero órfãos;
- zero divergências de contagem/hash/PK/sequences/somas numéricas/binários/tenant/timestamps e nenhuma normalização de tipos FK divergente do catálogo aprovado;
- status final exatamente `ACCEPTED`.

Qualquer campo ausente, valor inesperado, artefato já existente ou divergência resulta em bloqueio. A falha preserva o target/artefatos parciais para diagnóstico; não há retomada automática. Credenciais temporárias são removidas por `finally` quando a limpeza é segura.

## Testes desta branch

A suíte usa somente fixtures sintéticas locais. A prova anterior C.3 foi feita com PostgreSQL 18.6 real e Psycopg 3.3.8, antes destas alterações de isolamento v10d. Os 90 testes herdados do SHA C.2 não foram removidos; sete testes novos cobrem database/endpoint exclusivos, rejeição de resume, ancestralidade C.2, criação de target, gate de aceite exato e proteção do verificador de conexão, totalizando 97. As verificações desta branch não são uma execução de migração nem uma prova PostgreSQL integral do v10d.

Comando local de QA:

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s tests/migracao_pg -p 'test_*.py'
```

## Execução futura — somente após revisão/autorização específica

Com a branch publicada e revisada, ambiente v10d provisionado e manifesto da origem validado, usar exclusivamente o SHA40 final revisado:

```bash
sudo /usr/bin/python3 -B /opt/erpcondominios/pg-03-conversion/tools/migracao_pg/run_migration.py \
  --database erp_ensaio_v10d \
  --expected-commit <SHA40_REVISADO> \
  --batch-size 1000
```

Esse comando **não foi executado** nesta etapa. A etapa atual não autoriza a execução integral, criação de banco na Hetzner, adaptação da API, conexão do ERP, deploy, DNS ou cutover. Os alvos `v9`, `v10`, `v10b` e `v10c` e seus logs permanecem preservados e intocados.
