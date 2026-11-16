# Camada P3: compatibilidade mysqli sobre PDO

A camada é incremental e **não ativa PostgreSQL na aplicação por padrão**. A P3.1 adicionou a fachada OO PostgreSQL; a P3.1b adiciona um shim opt-in para o subconjunto mysqli efetivamente consumido pelo ERP. MySQL continua sendo o driver padrão.

## Componentes

- `PgsqlMysqliConnection.php`, `PgsqlMysqliStatement.php` e `PgsqlMysqliResult.php`: núcleo PDO PostgreSQL com bindings, transações, resultados bufferizados, estado de erro sanitizado e scanner de placeholders.
- `PdoMysqliConnection.php`, `PdoMysqliStatement.php` e `PdoMysqliResult.php`: fachada compartilhada para PDO MySQL/PostgreSQL, com verificação de que os argumentos legados da conexão correspondem ao destino da configuração selecionada.
- `MysqliDropIn.php` e `MysqliDropInFunctions.php`: bootstrap que instala aliases globais `mysqli`, `mysqli_stmt`, `mysqli_result` e `mysqli_sql_exception`, as constantes `MYSQLI_*` presentes no corpus e 26 funções procedurais (20 nomes no corpus amplo versionado, incluindo bootstrap; 19 fora dele; e 6 wrappers de charset, BLOB e multi-query).
- `MysqliDropInAutoPrepend.php`: ativação prévia ao script PHP, com falha sanitizada HTTP 503.

## Seleção e segurança de configuração

- `ERP_DB_DRIVER=mysql` continua sendo o default de compatibilidade; PostgreSQL exige `ERP_DB_DRIVER=pgsql` e as constantes próprias `ERP_PG_*`, incluindo schema explícito. As credenciais do driver não selecionado não são copiadas para os aliases `DB_*`.
- O shim só carrega quando a extensão nativa `mysqli` não está ativa. O pool isolado deve configurar `auto_prepend_file` para `api/db/MysqliDropInAutoPrepend.php` e `ERP_CONFIG_PATH` para um arquivo privado fora do document root, sem permissões de grupo/outros (`0600` recomendado). No FPM, ausência ou impossibilidade de verificar o document root, caminho explícito inválido e driver indisponível falham fechados antes da API, com resposta sanitizada; o bootstrap descarta saída parcial. Sem `ERP_CONFIG_PATH`, o fallback MySQL legado usa o mesmo resolvedor de caminho/permissões, e qualquer saída do arquivo invalida a configuração sem vazar para a resposta.
- Os parâmetros fornecidos por `new mysqli(...)`/`mysqli_connect(...)` não escolhem outro banco: se divergirem do perfil privado selecionado, a conexão é recusada. O shim não deve ser carregado junto com a extensão nativa nem em um pool compartilhado com runtimes distintos.
- `mysqli_report(MYSQLI_REPORT_STRICT)` lança a exceção compatível sanitizada; erros não expõem query, valores ou texto bruto do driver. `connect_errno` preserva o código numérico reportado pelo PDO e usa `2002` como fallback genérico.

## Contrato e limites

- Os aliases permitem type hints e `instanceof` do subconjunto inventariado, mas **não implementam toda a API mysqli**. `multi_query`, `more_results`, `next_result` e `store_result` da conexão são sequenciais, MySQL/PDO-only e bufferizados. O parser entende delimitadores fora de literais/comentários e recusa `DELIMITER`, `CALL` e DDL de stored programs; uma falha intermediária não reverte statements já executados. `send_long_data` aceita apenas parâmetros `b` vinculados e acumula o payload em `php://temp` até `execute` (até 2 MiB em memória antes de poder usar armazenamento temporário); não é streaming de ponta a ponta. Metadados completos de campos/avisos, resultados não bufferizados e flags/names avançados de transação continuam fora do contrato; chamadas não inventariadas não devem ser presumidas suportadas.
- Prepared statements aceitam placeholders posicionais `?`; o scanner considera literais, comentários, dollar quotes e escapes conforme o driver. `bind_param` preserva referências e valida quantidade/tipos. Resultsets são materializados em memória; avalie o tamanho por endpoint.
- PostgreSQL: o `insert_id` de `RETURNING id` ou `RETURNING ... AS insert_id` é lido do resultado; no caminho explícito, os nomes reconhecidos para a coluna retornada são `id` e `insert_id`. Outros aliases/nomes continuam visíveis no resultset, mas não alimentam `insert_id`. Para um `INSERT ... VALUES` de uma única tupla, com lista de colunas explícita que omite a PK serial/identity, o adapter acrescenta internamente `RETURNING <PK> AS insert_id` e oculta essa coluna auxiliar do contrato público; assim o ID vem da própria linha, não de `currval()`/`lastval()` compartilhado. A reescrita usa nome de coluna obtido de metadata e escapado como identificador. Multi-row, `INSERT ... SELECT`, `ON CONFLICT`, lista de colunas implícita, PK informada pelo chamador e outros formatos ambíguos não são reescritos e deixam `insert_id=0`, mesmo se uma única linha tiver sido afetada. Se a PK/sequence não puder ser identificada, a consulta original permanece intacta e o ID fica zero. Valores nulos em `bind_param` e `execute(array)` são vinculados como `PDO::PARAM_NULL`.
- MySQL via PDO usa `lastInsertId()` após INSERT bem-sucedido. Os resultados do adapter e a equivalência de cada consulta da aplicação ainda precisam de homologação diferencial.
- `set_charset('utf8'|'utf8mb4')` e `character_set_name()` representam UTF-8 nos dois drivers. Em MySQL, `real_escape_string()` usa `PDO::quote` no modo padrão; quando o SQL mode detectado inclui `NO_BACKSLASH_ESCAPES`, duplica aspas simples sem adicionar escapes por barra invertida e rejeita byte NUL. Alterações de `SESSION sql_mode` por query direta ou prepared statement invalidam o estado; o valor efetivo é relido antes do próximo escape e antes da divisão de multi-query. Uma multi-query que contenha qualquer referência a `sql_mode` é recusada para evitar mudança de regras no meio do lote; falha ao consultar o modo interrompe a operação antes de executar o lote. As mensagens e erros são sanitizados; não registre senhas, tokens, cookies ou hashes.

## Testes locais

```bash
D=$(php -r 'echo ini_get("extension_dir");')
php -n -d extension=pdo -d extension="$D/mysqlnd.so" -d extension="$D/pdo_mysql.so" tests/p3_mysqli_compat_test.php
php -n -d extension=pdo -d extension="$D/mysqlnd.so" -d extension="$D/pdo_mysql.so" tests/p3_mysqli_dropin_test.php
php tests/p3_mysqli_dropin_test.php
```

A suíte sintética injeta PDO/statement falsos e verifica aliases, APIs procedurais, falha fechada, binding, metadata de PK/sequence, destino, BLOB, multi-query, escape por SQL mode e sanitização (26 testes do núcleo e 37 assertions do processo pai no harness sem mysqli nativa; 39 no CLI normal). Os subprocessos do harness são validados por status e marcadores, sem agregar as assertions internas. O teste do insert_id modela separadamente o ID da linha (89) e o valor posterior da mesma sequence (90), cobrindo o risco de trigger consumir essa sequence; continua sem executar trigger PostgreSQL real. Não abre conexão com MySQL ou PostgreSQL; os testes HTTP/PHP-FPM e a validação em engines reais são gates separados.
