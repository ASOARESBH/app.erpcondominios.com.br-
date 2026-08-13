# Incidente: tela "Documentos" do app do morador sempre vazia (0 documentos)

## Evidências fornecidas

- Prints da tela "Documentos" do aplicativo (Flutter, `aplicativoerpcondominios`) mostram sempre **"Nenhum documento encontrado."**, mesmo com a unidade logada (Casa 133) devendo enxergar documentos do GED.
- Não há erro visível ao usuário — a tela simplesmente renderiza o estado vazio (`EmptyState`), como se a consulta tivesse retornado uma lista sem itens.

## Diagnóstico (leitura de código, sem alteração)

### Fluxo envolvido
- Frontend: `lib/presentation/screens/documents/documents_screen.dart` (método `_loadData`).
- Endpoint: `AppConstants.endpointDocumentos` = `/api/api_portal_documentos.php` (`lib/core/constants/app_constants.dart`).
- Backend: `api/api_portal_documentos.php`, ação `documentos_listar` (linhas 240-261).
- HTTP client: `lib/core/network/dio_client.dart` — `validateStatus: (status) => status != null && status < 500`.

### Causa raiz
1. `_loadData()` sempre chama a API com `acao=documentos_listar` e **nunca envia `pasta_id`** (só envia `busca` e `tipo` quando preenchidos).
2. No backend, a ação `documentos_listar` exige `pasta_id` (0 = "sem pasta", ou o ID de uma pasta específica). Sem o parâmetro, `$_GET['pasta_id']` cai no default `-1` e a API responde `{"sucesso": false, "mensagem": "pasta_id inválido."}` com HTTP 400 (`api/api_portal_documentos.php`, linha ~242).
3. Como `dio_client.dart` trata qualquer status `< 500` como resposta "normal" (não lança exceção), o `try/catch` de `_loadData()` não é acionado. O código apenas verifica `if (data['sucesso'] == true)` — como vem `false`, o bloco é pulado e `_documents` permanece `[]`, sem nenhum log de erro visível ao usuário.
4. Ou seja: a tela **nunca chega a listar nada de fato**, para nenhum morador, em nenhum tenant — não é um problema de permissão/tenant específico, é um descompasso de contrato entre app e API.

### Causa raiz secundária (busca não funciona mesmo corrigindo o item acima)
- O campo de busca da tela envia o parâmetro `busca`, mas a ação `documentos_listar` só lê `tipo` — o texto digitado é ignorado. Quem lê o parâmetro de busca por texto (`q`) é a ação **`buscar`** (linhas 267-297), que a tela nunca chama.
- A ação `buscar` (sem pasta) é, na prática, a que corresponde à UX já implementada na tela (lista única, sem navegação por pastas): ela aceita `q`, `tipo` e `pagina`, **não exige `pasta_id`**, e já retorna `pasta_nome` (usado no `subtitle` do `ListTile`, linha 151 de `documents_screen.dart`). A ação `documentos_listar` não retorna `pasta_nome`.

### Conclusão
O endpoint correto para essa tela (lista simples com busca + filtro de tipo, sem navegação por pastas) é `acao=buscar`, não `acao=documentos_listar`. A tela foi implementada para consumir uma listagem plana, mas foi ligada à ação da API que espera navegação por pastas (`pastas_listar` → `documentos_listar&pasta_id=`).

## Correção recomendada (não aplicada nesta análise)

1. Em `documents_screen.dart`, trocar `acao=documentos_listar` por `acao=buscar`, renomear o parâmetro `busca` para `q` (nome esperado pelo backend) e passar `pagina` (com paginação/scroll infinito se necessário, já que `buscar` limita a 30 registros por página).
2. Alternativa mais alinhada ao desenho original da API: implementar navegação por pastas na tela, chamando `pastas_listar` e depois `documentos_listar&pasta_id=`. Mais trabalho de UI, mas usa a ação como foi originalmente projetada (ver comentário no topo de `api_portal_documentos.php`).
3. Independente da opção escolhida, tratar `sucesso: false` mostrando mensagem de erro ao usuário (hoje é engolido silenciosamente) para que falhas futuras de contrato API↔app não fiquem indistinguíveis de "sem documentos".
4. Validar também a resolução de `unidade_id` em `api_portal_documentos.php` (`JOIN unidades u ON u.nome = m.unidade`, linha ~179): se o nome da unidade do morador não bater exatamente com `unidades.nome`, documentos com `visibilidade = 'unidades_especificas'` não aparecerão mesmo após corrigir a ação. Conferir com dados reais antes de fechar o chamado.

## Fontes locais
- Frontend: `lib/presentation/screens/documents/documents_screen.dart`
- Constantes: `lib/core/constants/app_constants.dart`
- HTTP client: `lib/core/network/dio_client.dart`
- Backend: `api/api_portal_documentos.php`
- Contexto de armazenamento de arquivos: [TENANT_FILE_STORAGE.md](TENANT_FILE_STORAGE.md)
