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

## 5. GED — Portal do Morador

O endpoint `api/api_portal_documentos.php` é o consumidor autenticado das tabelas GED. O token Bearer determina o morador; o cliente nunca informa `tenant_id`, `morador_id` ou `unidade_id`.

| Ação | Parâmetros | Uso correto |
|---|---|---|
| `pastas_listar` | Nenhum | Navegação por pastas com contagem de itens visíveis |
| `documentos_listar` | `pasta_id` obrigatório, `tipo` opcional | Lista de uma pasta específica; `pasta_id=0` representa itens sem pasta |
| `buscar` | `q`, `tipo`, `pagina` | Lista plana e paginada entre todas as pastas; é a ação usada pelo aplicativo móvel |
| `visualizar` / `download` | `id` | Revalida a visibilidade do documento antes de transmitir arquivo ou link externo |

> A resposta de erro de uma API (`sucesso: false`) deve ser apresentada explicitamente pelo cliente, inclusive se o cliente HTTP optar por não transformar HTTP 400 em exceção.
