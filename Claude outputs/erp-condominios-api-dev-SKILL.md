---
name: erp-condominios-api-dev
description: "Use sempre que criar, alterar, depurar ou revisar um endpoint da API do ERP Condomínios (api/api_*.php, repositório app.erpcondominios.com.br-). Garante estrutura, autenticação, isolamento multi-tenant e resposta padrão antes de codificar."
---

# Desenvolvimento de API — ERP Condomínios

Skill de engenharia de contexto para a camada de API (`/api/`) do ERP Condomínios (github.com/ASOARESBH/app.erpcondominios.com.br-). Objetivo: nunca escrever ou alterar um endpoint sem antes confirmar estrutura, autenticação, isolamento de tenant e o arquivo canônico certo — evitando os dois erros mais caros neste repositório: furo de segurança multi-tenant e edição do arquivo errado entre várias versões paralelas que coexistem na mesma pasta.

Este skill assume o papel de tech lead + engenheiro de segurança + QA para qualquer tarefa de API neste projeto. Complementa (não substitui) `AI-CONTEXT/SKILL.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DATABASE.md` e `AI-CONTEXT/PROTOCOLO_MANUS.md` do próprio repositório — leia-os quando o repositório estiver disponível; este skill resume o essencial mesmo sem acesso a ele.

## 1. Antes de escrever qualquer linha

1. Identifique o módulo de negócio da tarefa (financeiro, moradores, hidrômetros, controle de acesso, RH, documentos, etc.) e localize o arquivo `api/api_<modulo>.php` correspondente pelo nome — nunca liste o diretório `api/` inteiro para "ver o que tem"; ele tem centenas de arquivos.
2. Se o repositório tiver `AI-CONTEXT/`, leia primeiro `API.md` e a seção relevante de `DATABASE.md`/`BUSINESS_RULES.md` para esse módulo — não leia o framework de contexto inteiro, só o que se aplica à tarefa.
3. Abra o arquivo alvo e identifique qual dos três padrões arquiteturais ele já usa, e siga o mesmo padrão na alteração (nunca migre um para o outro sem pedido explícito):
   - **Legado procedural**: `switch ($_GET['action'])` dentro do próprio arquivo `api_*.php`.
   - **Moderno orientado a objeto**: classe estendendo `ApiBase`.
   - **MVC recente**: `api/controllers/<Nome>Controller.php` + `api/models/<Nome>Model.php` (ex.: `DependenteController`/`DependenteModel`, `SessionController`/`SessionModel`).

## 2. Contrato de resposta (obrigatório em toda API)

Toda resposta é JSON no formato:
```json
{"sucesso": true|false, "mensagem": "texto descritivo", "dados": { ... }}
```
- Nunca dar `echo`/output antes de `header('Content-Type: application/json')`.
- Envolver a lógica com `ob_start()` no início e `ob_end_clean()` antes do JSON final, para warnings do PHP não quebrarem o parse no cliente.
- Erros reais de banco/exceptions nunca vazam para o cliente — logar com `error_log()` e devolver mensagem amigável.

## 3. Autenticação — dois mundos distintos, não misturar

- **Sessão web (painel administrativo)**: incluir `auth_helper.php`, chamar `verificarAutenticacao(true, '<nivel_minimo>')` no topo do endpoint. Hierarquia crescente de permissão: `visualizador` → `operador` → `gerente` → `admin`.
- **Token (app mobile / dispositivo / portal do morador)**: `Authorization: Bearer <token>` validado via `jwt_handler.php` / `dispositivo_token_manager.php` / `qrcode_token_manager.php` conforme o caso. O token já carrega a identidade (morador, colaborador ou dispositivo) — **o cliente nunca deve informar `tenant_id`, `morador_id`, `colaborador_id` etc. brutos no payload**; resolva sempre esses IDs a partir do token/sessão no backend.
- Endpoints acionados por hardware sem sessão (ex.: catracas ControliD em `api/controlid/`) seguem padrão próprio descrito em `AI-CONTEXT/SERVICES.md`/`API.md` — não force login nesses.

## 4. Isolamento multi-tenant (a regra mais fácil de esquecer)

O sistema é multi-tenant com banco único e isolamento lógico por `tenant_id`. Trate como bug crítico de segurança qualquer query que não respeite isto:

- **SELECT**: sempre `WHERE tenant_id = ?` (tenant resolvido da sessão/token, nunca do payload do cliente).
- **INSERT**: sempre gravar `tenant_id` a partir da sessão/token ativa.
- **UPDATE/DELETE**: sempre validar `WHERE id = ? AND tenant_id = ?`, nunca só `WHERE id = ?`.
- Se a tabela envolvida ainda não tiver coluna `tenant_id`, verifique `AI-CONTEXT/MULTITENANT_ARCHITECTURE.md` antes de assumir que não precisa — pode ser uma tabela pendente de migração, não uma exceção legítima.
- Nunca confie em `tenant_id` vindo de querystring, body ou header controlado pelo cliente.

## 5. Segurança geral obrigatória

- SQL Injection: exclusivamente prepared statements (`$stmt->prepare()` / `bind_param()`). Nunca concatenar variável em SQL.
- CORS: `Access-Control-Allow-Origin` deve validar a origem dinamicamente — nunca hardcode de domínio fixo (dívida técnica já identificada em dezenas de arquivos legados; não repita o padrão em código novo).
- Rate limiting em endpoints de login/recuperação de senha via `rate_limiter.php`.
- Uploads e downloads de arquivo (documentos, anexos, fotos) sempre revalidam visibilidade/permissão no backend antes de entregar o arquivo — nunca montar caminho `uploads/...` direto no frontend.
- Credenciais reais (`api/config.php`) nunca são commitadas nem exibidas em log; usar `config.example.php` como referência de formato.

## 6. Arquivos-armadilha em `/api/` — confirme antes de editar

A pasta `api/` mistura endpoints de produção com dezenas de arquivos de teste, debug e versões antigas no mesmo diretório. Nunca trate estes como referência nem como alvo de edição, a menos que a tarefa seja depurar exatamente esse arquivo:
- Prefixos: `teste_`, `debug_`, `diagnostico_`, `exemplo_`.
- Sufixos: `_backup`, `.bak`, `_final`, `_corrigida`/`_corrigido`, datas no nome (`_backup_20260128_...`).
- Exemplo real: `api_protocolos.php` (ativo) convive com `api_protocolos_final.php`, `api_protocolos_corrigida.php` e dois backups datados — edite apenas o primeiro, e só depois de confirmar no JS de página correspondente que é esse mesmo o consumido.
- Para confirmar o arquivo canônico: procure a chamada `fetch()` real no JS de página do frontend (`frontend/js/pages/<modulo>.js` ou `frontend/pages/<modulo>.html`), nunca confie no nome "mais novo" ou "final".

## 7. Ao terminar

1. Validar sintaxe (`php -l arquivo.php`).
2. Conferir que a resposta de erro (`sucesso:false`) é coerente mesmo em HTTP 4xx — clientes deste projeto nem sempre tratam status HTTP como exceção.
3. Se o endpoint for novo ou mudar de contrato, atualizar `AI-CONTEXT/API.md` (e `MODULES.md`/`DATABASE.md` se necessário) — nunca apagar entradas antigas, só complementar.
4. Resumir a mudança (endpoint, ação, parâmetros, regra de negócio) de forma objetiva — sem colar de volta o código já entregue.