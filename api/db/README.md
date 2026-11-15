# Camada P3: fachada mysqli sobre PDO PostgreSQL

Esta primeira fatia adiciona uma fachada de compatibilidade orientada a objetos, sem conectar automaticamente a aplicação e sem substituir a classe nativa `mysqli`.

## Arquivos

- `PgsqlMysqliConnection.php`: conecta via PDO PostgreSQL quando `pdo_pgsql` estiver habilitado, prepara consultas, executa queries, administra transações e mantém estado de erro resumido.
- `PgsqlMysqliStatement.php`: oferece `bind_param`, `execute`, `get_result`, `bind_result`/`fetch`, `store_result` e `close`.
- `PgsqlMysqliResult.php`: oferece resultados bufferizados por `fetch_assoc`, `fetch_row`, `fetch_array`, `fetch_all`, `data_seek` e `free`.

## Contrato e restrições

- A classe ainda não é carregada por `api/config.php`, não altera `conectar_banco()` e não troca o driver do ERP. A seleção do driver e a integração da sessão ficam para o PR seguinte.
- Não é substituta drop-in de `mysqli`: chamadas procedurais `mysqli_*`, herança/identidade de tipo nativo, `multi_query`, resultados streaming, metadados completos de campo e transaction flags/names não são suportados.
- Consultas preparadas aceitam placeholders posicionais `?`; `param_count` ignora literais/comentários e placeholders PDO escapados `??`. Use `??` para operadores PostgreSQL JSON que contenham `?`. `bind_param` aceita tipos mysqli `i`, `d`, `s` e `b`; `execute($params)` exige lista posicional com cardinalidade exata e não pode ser misturado a bindings `bind_param` no mesmo ciclo. Código novo deve preferir prepared statements e não montar SQL com escaping.
- O scanner léxico de parâmetros respeita delimitadores dollar-quote e identificadores UTF-8 segundo a [documentação lexical oficial](https://www.postgresql.org/docs/current/sql-syntax-lexical.html) e o [scanner do PostgreSQL 18](https://raw.githubusercontent.com/postgres/postgres/REL_18_STABLE/src/backend/parser/scan.l), incluindo o tratamento por byte dos caracteres não ASCII.
- Resultsets são bufferizados em memória. A adequação a consultas grandes precisa ser avaliada por endpoint antes do uso.
- Resultados solicitados como associativos e posicionais são materializados por `PDO::FETCH_BOTH`, preservando colunas homônimas para `fetch_row`, `fetch_array(MYSQLI_NUM)` e `bind_result`; `fetch_assoc` mantém somente o último valor para um rótulo repetido, como ocorre com arrays associativos.
- PostgreSQL não fornece um `insert_id` universal equivalente ao MySQL. O adapter só devolve esse valor para INSERT iniciado diretamente com `RETURNING ... AS insert_id`; `INSERT ... RETURNING id` sem alias e INSERT dentro de CTE não alimentam a propriedade. Cada nova execução limpa o ID anterior. A tradução dos escritores existentes deve usar a forma documentada e ser explícita.
- `close()` na conexão impede novas operações também nos statements já preparados; `close()` no statement é idempotente e invalida suas futuras execuções. Falhas limpam o estado da execução anterior.
- `connect()` falha com `RuntimeException` sanitizada; a fachada não expõe `connect_error`/`connect_errno` compatíveis com o objeto nativo.
- O mapeamento `SQLSTATE` → `errno` cobre códigos comuns apenas para compatibilidade de transição. A mensagem exposta não inclui SQL, valores ou texto bruto do driver.
- `set_charset('utf8'|'utf8mb4')` significa UTF-8 PostgreSQL. Outros charsets são rejeitados.

## Teste local

```bash
php -l api/db/PgsqlMysqliConnection.php
php -l api/db/PgsqlMysqliStatement.php
php -l api/db/PgsqlMysqliResult.php
php tests/p3_mysqli_compat_test.php
```

O harness injeta `PDO`/`PDOStatement` sintéticos; não abre conexão MySQL/PostgreSQL nem depende de fixture com dados do ERP.
