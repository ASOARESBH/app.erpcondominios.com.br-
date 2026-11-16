# Backend Guide

## 1. Regra de Ouro
O backend é exclusivo em PHP 8.x. Foco em performance e segurança.

## 2. Conexão com Banco
Centralizada em `api/config.php`.
```php
$conexao = conectar_banco();
```

## 3. Prevenção de SQL Injection
**Obrigatório** o uso de Prepared Statements (`$stmt->prepare`, `$stmt->bind_param`).
NUNCA concatenar variáveis diretamente na string SQL.

## 4. Tratamento de Erros
Usar blocos `try/catch`. Erros críticos devem ser logados com `error_log()` e retornar uma mensagem amigável no JSON, nunca expor o erro real do banco de dados para o cliente.

## 5. Sessão e Output
Nunca dar `echo` antes do `header('Content-Type: application/json')`. O PHP falhará ao definir headers se houver output prévio (incluindo espaços em branco antes do `<?php`).

## 6. P3 — fachada incremental PDO PostgreSQL (primeiro PR)

`api/db/PgsqlMysqliConnection.php`, `PgsqlMysqliStatement.php` e `PgsqlMysqliResult.php` oferecem uma fachada OO limitada sobre PDO para preparação/execução, resultados bufferizados, `bind_param`/`bind_result`, transações e estado de erro sanitizado. A camada não está ligada a `api/config.php`, não muda `conectar_banco()` e não seleciona driver automaticamente; MySQL continua sendo o padrão.

Não é substituto drop-in da extensão mysqli: funções procedurais `mysqli_*`, identidade/herança do tipo nativo, `multi_query`/`next_result`, streaming e metadados completos não são cobertos. Resultados são bufferizados; deve-se avaliar memória por endpoint. `execute($params)` exige lista posicional de cardinalidade exata, não combina com `bind_param` no mesmo statement, e referências de `bind_param` são relidas a cada execute. `close()` invalida statements preparados; erros/falhas limpam estados da execução anterior. `insert_id` é preenchido somente para INSERT direto com `RETURNING ... AS insert_id`; toda nova execução limpa o valor anterior. Escritores existentes não devem ser migrados até o segundo PR e testes diferenciais.


## 7. P3.1b — compatibilidade procedural mysqli via PDO (branch isolada)

`api/db/MysqliDropInAutoPrepend.php` carrega o shim antes da API somente quando configurado no pool isolado; sem mysqli nativo e com `ERP_CONFIG_PATH` apontando a arquivo privado. No FPM, o bootstrap exige document root verificável, config fora dele e sem permissões de grupo/outros; saída parcial é descartada em falha e a resposta é 503 genérica. `api/config.php` também resolve o fallback MySQL legado pelo mesmo validador e rejeita arquivo que produza saída inesperada. PostgreSQL exige seleção explícita, variáveis próprias e schema. `mysql` permanece default. A conexão PDO valida os argumentos legados do construtor contra a configuração ativa e recusa destinos divergentes; erros são sanitizados e preservam apenas SQLSTATE/código numérico seguro.

O shim implementa aliases, 26 wrappers procedurais e os métodos OO inventariados, não a API mysqli inteira. Resultsets são bufferizados. `multi_query` e `next_result` são sequenciais, MySQL/PDO-only e não oferecem rollback dos statements já executados; `CALL`, `DELIMITER` e DDL de stored programs falham fechados. `send_long_data` acumula bytes em stream temporário e não é streaming de ponta a ponta. Metadados avançados continuam fora do contrato. No PostgreSQL, o `insert_id` explícito vem do `RETURNING` solicitado; para um INSERT simples com lista explícita que omite PK serial/identity identificável, o adapter acrescenta `RETURNING <PK> AS insert_id` internamente e oculta esse campo auxiliar. Isso evita inferir ID por `currval()`/`lastval()` se um trigger avançar a mesma sequence. Multi-row, `INSERT ... SELECT`, `ON CONFLICT`, PK fornecida pelo chamador e lista de colunas implícita permanecem sem rewrite e retornam zero. Bindings nulos são enviados como SQL NULL. Não ativar no FPM compartilhado: a instalação requer pool sem extensão mysqli e configuração privada; a conexão ainda precisa passar por teste real HTTP/PHP-FPM nos vhosts isolados antes de homologação.
