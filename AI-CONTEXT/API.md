# Mapa de APIs

As APIs estão localizadas na pasta `/api/` e respondem primariamente em JSON.

## 1. Estrutura de Chamada
**Endpoint Padrão**: `GET/POST /api/api_nome_modulo.php?action=nome_da_acao`

## 2. Padrão de Resposta
Toda API deve retornar obrigatoriamente a estrutura:
```json
{
    "sucesso": true|false,
    "mensagem": "Texto descritivo",
    "dados": { ... } // Opcional
}
```

## 3. Autenticação nas APIs
O arquivo `auth_helper.php` deve ser incluído no topo.
```php
require_once 'config.php';
require_once 'auth_helper.php';
verificarAutenticacao(true, 'operador'); // true = exige login, 'operador' = nivel minimo
```

## 4. Prevenção de Erros de Output
Muitas APIs usam o padrão `ob_start()` no início e `ob_end_clean()` antes de enviar o JSON, para evitar que warnings do PHP quebrem o parse do JSON no frontend.
