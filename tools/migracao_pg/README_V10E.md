# Fase C.4.2 — provisionamento da origem para `erp_ensaio_v10e`

## Escopo e estado

Branch dedicada: `pg/03-4-fix-mysql-source-provisioning`, derivada do C.4 publicado. Esta versão do runner possui um único modo executável: `--mode prepare-source`. Não há opção de migração integral, retomada ou execução de outra fase nesta branch.

A fase C.4.2 provisiona e valida a conta técnica no novo clone MySQL e gera o catálogo/DDL de compatibilidade apenas como artefato. **Não executa `initdb` nem inicia PostgreSQL; não cria o database PostgreSQL; não roda loader, triggers, FKs, `ANALYZE` ou reconciliação de dados.** Após entregar o relatório, aguardar autorização expressa de André para uma branch/execução futura C.4.3. O ensaio v10d continua `BLOCKED`, preservado e em quarentena.

A validação de ambiente real e de catálogo ainda deve ser comprovada na Hetzner. Os testes locais não substituem essa evidência e não permitem classificar a fase como `READY_FOR_V10E_EXECUTION`.

## Causa e correção do provisionamento

O clone MySQL v10d não continha a conta técnica lowercase `pgloader`@`127.0.0.1` exigida pelo helper do catálogo. Uma conta uppercase posterior não é equivalente no MySQL 5.7, que diferencia capitalização do nome da conta. A criação manual pós-falha não comprova o estado de um clone novo.

`mysql_source.py` só funciona com o checkout limpo da branch C.4.2 no repositório oficial, SHA esperado e ancestralidade baseline/C.2. Ele verifica o manifesto, dump, seu tamanho/SHA-256 e integridade gzip; confirma o container e inode do network namespace via `podman inspect`; exige o clone identificado como v10e, imagem MySQL 5.7, versão exata do manifesto, mounts bind novos, `NetworkMode=none`, sem portas publicadas, perfil AppArmor de ensaio, `no-new-privileges`, sem capabilities adicionais, loopback MySQL, Event Scheduler OFF, `local-infile=0`, `secure-file-priv=NULL` e fuso `-03:00`.

O provisionador compara os arquivos reais com o target: `client.cnf` e `root.pass` vivem em `mysql-secrets/`, que é o único bundle de segredo montado no container em read-only; a senha em ambos é comparada em memória. `pgloader.cnf` e o manifesto ficam em `secrets/`, fora do container. `mysql_source.py` rejeita paths de segredo de outros alvos e valida O_NOFOLLOW/fstat, ownership, links e permissões.

A conta de origem é criada/ajustada exclusivamente como `pgloader`@`127.0.0.1`; a busca em `mysql.user` usa comparação binária de usuário e host. Privilégios anteriores são revogados e reaplicados somente como `SELECT`, `SHOW VIEW` e `TRIGGER` no schema `inlaud99_erpcondor`. `expected_version` é obrigatório e conferido contra o manifesto. Antes e depois são verificadas identidade administrativa/local, versão/porta, schema, `read_only=1`, `super_read_only=1` e `event_scheduler=OFF`.

A desativação temporária de flags ocorre só no clone v10e e só para metadados de usuário/grants. O `finally` tenta restaurar os dois flags mesmo em falha/interrupção; restauração não comprovada bloqueia. Em seguida, a conexão passa pelo helper oficial de catálogo, que confirma `CURRENT_USER()=pgloader@127.0.0.1`, flags read-only, Event Scheduler e 270 tabelas. Saída/log contém apenas status e códigos estáticos, sem senha, hash, SQL bruto ou mensagem do MySQL.

## Dados de origem, manifesto e separação de paths

Antes do runner, o administrador/restaurador precisa disponibilizar **um clone MySQL novo** — nunca container/volume v10d — restaurado da cópia privada do dump no schema `inlaud99_erpcondor`. A origem MySQL original permanece intocada. O clone deve estar em `network=none`, sem portas publicadas e com perfil AppArmor existente. A restauração é executada somente nele; após ela, criar/validar as duas identidades técnicas locais e congelar `read_only` e `super_read_only`.

O manifesto root-only `0600` em `/var/lib/erp-pg-rehearsal/v10e/secrets/source-manifest.json` deve conter exatamente os campos versionados: target, branch, SHA de código, schema, SHA/tamanho do dump, versão MySQL, nome/ID do container, nome/ID da imagem, `restore_status=complete` e timestamp UTC. Não inclua senha, dados, linhas de tabela ou conteúdo do dump.

| Recurso | Path exclusivo v10e | Contrato |
|---|---|---|
| Root privado | `/var/lib/erp-pg-rehearsal/v10e` | `postgres:postgres`, `0700` |
| Segredos do host + manifesto | `.../secrets` | `root:root`, `0700`; arquivos `0400/0600` |
| Segredos mínimos visíveis no MySQL | `.../mysql-secrets` | `root:root`, `0700`; `client.cnf` e `root.pass` `0400`; bind read-only |
| Dump restaurado/cópia autenticada | `.../source-copy.sql.gz` | `root:root`, `0400`; hash e bytes iguais ao manifesto |
| Dados/socket do container | `.../mysql-data`, `.../mysql-socket` | paths novos, volumes bind próprios do clone |
| Execução de preflight | `.../preparations/source_<UTC>/` | logs agregados `0600`; lock exclusivo `.source-provision.lock` |
| Runtime Python | `.../venv` | PyMySQL 1.2.3 e Psycopg 3.3.8 fixados |
| PostgreSQL futuro | `.../pg-data`, `.../pg-socket`, porta `55434` | não criar nesta fase; paths/porta devem estar livres |

A CLI usa apenas a forma abaixo, sempre com o SHA integral publicado desta branch:

```bash
sudo -n /var/lib/erp-pg-rehearsal/v10e/venv/bin/python3 -B \
  /opt/erpcondominios/pg-03-4-fix-mysql-source-provisioning/tools/migracao_pg/run_migration.py \
  --mode prepare-source --database erp_ensaio_v10e --expected-commit <SHA-C4.2>
```

O runner rejeita v9, v10, v10b, v10c, v10d, nomes arbitrários, resume, modo `full-migration`, paths preexistentes de cluster/run/socket, root v10e inseguro e porta ocupada. Ele não chama qualquer função de criação/inicialização do target PostgreSQL. Se algum path reservado já existir, parar e preservar; não limpar nem sobrescrever.

## Testes automatizados

Uma comparação AST entre o commit-base C.4 e esta branch confirmou 97/97 métodos de teste preservados. Cinco nomes mudaram para expressar v10e; nenhum teste herdado foi removido. Foram adicionados 47 testes específicos para CLI `prepare-source`, impossibilidade estrutural da migração integral, isolamento/manifesto/dump, namespaces, identidade uppercase/host, bundle exato de segredos, permissões, versão, grants e hash do segredo, restauração read-only, eventos de auditoria do freeze/restore, sinais, timeouts de processos/descendentes, JSON privado/versionado, rejeição de symlink em DDLs, helper e logs sanitizados. A suíte atual contém 144 testes definidos/aprovados localmente.

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s tests/migracao_pg -p 'test_*.py' -v
```

Também executar AST nos arquivos Python alterados e `git diff --check`. A contagem nominal não equivale a aceite operacional.

Cada subprocesso de fase do runner tem timeout padrão de 900 segundos, ajustável apenas via `--step-timeout` entre 1 e 3600. Uma leitura seletora não bloqueante aplica deadline absoluto; ao expirar, o process group recebe SIGTERM, espera no máximo 2 segundos e recebe SIGKILL se houver membros restantes, com reap do líder limitado e erro estático `step_timeout`. Se o líder encerra com sucesso mas deixa descendentes no grupo, o runner termina o grupo e falha com `step_child_process`. Os subprocessos de preflight (Git, Podman, toolchain e portas) também têm deadlines limitados. O bundle montado em `/run/rehearsal-secrets` precisa conter exatamente `client.cnf` e `root.pass`, ambos arquivos regulares root-owned mode 0400, sem symlink ou terceiros; os membros são abertos relativamente ao directory fd com `O_NOFOLLOW` e verificados por `fstat`, e o provisionador compara os bytes lidos ao mesmo tempo em que valida o par senha/config. A cadeia de diretórios de manifestos, dumps, segredos, JSON privado/versionado e DDLs é aberta sem symlinks com `openat`/`O_NOFOLLOW`; os owners/modes dos parents privados são verificados por `fstat`. O dump é hasheado e validado com `gzip -t` pelo mesmo descritor aberto, sem reabrir pathname. Os DDLs do catálogo são hasheados em streaming pelo mesmo descritor usado na leitura. O parser `SHOW GRANTS` compara user/host/schema com sensibilidade de caixa e vincula o hash MySQL 5.7 de 41 caracteres do `USAGE` ao resultado `PASSWORD(%s)` da senha que autenticou a conexão, apenas em memória.

## Gate C.4.2 e critério terminal

Classificar como `READY_FOR_V10E_EXECUTION` somente quando houver evidência real, sanitizada, de todos os itens: código commitado/publicado em branch própria com checkout remoto limpo no SHA; 144/144 testes e 97/97 preservados; AST e diff-check; toolchain PostgreSQL 18.6, Psycopg 3.3.8/PyMySQL 1.2.3; armazenamento e paths exclusivos; clone MySQL novo da cópia de dump validada; nenhum MySQL original ou alvo v9–v10d tocado; manifesto/dump autenticados; container isolado sem portas; provisionador lowercase executado; grants mínimos e helper oficial aprovados; catálogo read-only com 270 tabelas/3339 colunas/240 PKs/11 FKs/7 triggers/2 procedures e 8 DDLs esperados; `read_only=1`, `super_read_only=1`, Event Scheduler OFF; zero database PostgreSQL de destino criado e nenhum cluster PostgreSQL iniciado; logs sem segredos; branch e commit SHA documentados.

Esta aceitação **não** autoriza a migração integral. Não executar `run_migration.py` fora do modo `prepare-source`, não criar `erp_ensaio_v10e`, carregar dados, adaptar PDO/aplicação, fazer deploy, alterar DNS/cutover ou iniciar Fase D. Após apresentar relatório, aguardar nova autorização expressa.
