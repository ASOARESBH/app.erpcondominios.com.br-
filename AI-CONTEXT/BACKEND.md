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
