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
