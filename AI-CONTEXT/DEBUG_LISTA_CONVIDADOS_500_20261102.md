# DEBUG — Erro 500 na listagem da Lista de Convidados (2026-11-02)

> Prompt para diagnosticar e corrigir. Copie a partir de "INÍCIO DO PROMPT".

## Sintoma (console do navegador)

```
GET /api/api_convidados.php?acao=listar&data=2026-11-02&pagina=1&por_pagina=50  500 (Internal Server Error)
[RegistroConvidados] Falha ao carregar convidados: Error: Não foi possível concluir a operação de convidados...
```

- O erro se repete para **todas as datas** (29/10 a 02/11). Não depende do dia nem de filtro.
- O corpo vem em JSON com a mensagem genérica. Ou seja, o PHP **carregou**, autenticou e caiu no `catch (Throwable)` do final de `api_convidados.php` (linha ~730), ou no `set_exception_handler`. Não é erro de sintaxe nem fatal de compilação: esses devolveriam corpo vazio ou HTML.
- O restante da tela funciona: `registro.js` carrega e as 189 unidades vêm normalmente. Sessão e autenticação estão OK.
- A mensagem real fica **apenas no log do servidor**, com o prefixo `[Convidados][Fatal] tenant=1 acao=listar erro=...`.

## Achados da análise do código local (repositório em `main`, 02/11/2026)

1. **Já houve um bug de bind nesta mesma listagem.** O commit `e1a874e` ("corrige bind da listagem de convidados", 02/11 01:03 UTC) trocou `$tiposFiltros . 'sssii'` por `$tiposFiltros . 'ii'`. Com a string antiga, o PHP 8 lança `ArgumentCountError` / `ValueError` no `bind_param` em **toda** chamada de `listar`, exatamente o sintoma atual. O deploy é **manual pelo Gerenciador de Arquivos do cPanel** (`DEPLOY.md`). Se o arquivo corrigido não foi enviado, ou o OPcache ainda serve a versão antiga, a produção continua quebrada. **Hipótese nº 1.**
2. No código local, a contagem de placeholders da listagem está correta: base `sssi` (3 datas + tenant) + filtros + `ii` (LIMIT/OFFSET).
3. A listagem depende de objetos que precisam existir **no banco de produção** (`ERP_DB_NAME` do `config.php`):
   - a tabela `convidados` (criada por `sql/migration_lista_convidados_mysql57.sql`);
   - `moradores.tenant_id`;
   - `dependentes.tenant_id` e `dependentes.nome_completo` (o JOIN usa `d.nome_completo`; note que `api/models/DependenteModel.php` usa `nome`, o que indica divergência histórica de schema);
   - `registros_acesso.convidado_id` (opcional: a API checa se existe antes de usar).
   Se a migration não rodou no banco certo, o `prepare` falha com `Table ... doesn't exist` ou `Unknown column`. **Hipótese nº 2.** Existem dumps de mais de um banco em `sql/` (erpserra, erpcondor), o que aumenta o risco de a migration ter rodado no banco errado.
4. A migration usa `DELIMITER $$` + `CREATE PROCEDURE`. No phpMyAdmin da HostGator, isso pode falhar por falta do privilégio `CREATE ROUTINE` ou pelo modo de importação. O `CREATE TABLE convidados` vem **antes** da procedure e deve ter sido criado, mas a coluna `registros_acesso.convidado_id` e a ampliação do ENUM `tipo` podem não ter sido aplicadas. Isso não quebra a listagem, mas quebra a Fase 3 e o vínculo de entrada.

---

## INÍCIO DO PROMPT

Você é o tech lead do **ERP CONDOMÍNIOS**. Antes de qualquer alteração, leia `AI-CONTEXT/INDEX.md`, `DEPLOY.md`, `DATABASE.md`, `PROMPT_LISTA_CONVIDADOS_20261031.md` e este arquivo (`DEBUG_LISTA_CONVIDADOS_500_20261102.md`). Siga a sequência ANALISAR → CAUSA RAIZ → CORRIGIR → TESTAR → DOCUMENTAR.

### Problema

`GET api/api_convidados.php?acao=listar&data=AAAA-MM-DD&pagina=1&por_pagina=50` retorna **HTTP 500** em produção para qualquer data, com a mensagem genérica "Não foi possível concluir a operação de convidados". O frontend (`frontend/js/pages/registro-convidados.js`) e a autenticação funcionam. A exceção é capturada no `catch (Throwable)` do final do endpoint.

**Não corrija às cegas.** Primeiro obtenha a mensagem real.

### Passo 1 — Capturar o erro real (obrigatório)

1. No cPanel, abra o `error_log` da pasta `public_html/api/` (ou o caminho definido em `ini_get('error_log')`) e procure as últimas linhas com `[Convidados][Fatal]` ou `[Convidados][Uncaught]`. Copie a mensagem completa: ela diz exatamente se o problema é bind, tabela ou coluna.
2. Se o log não estiver acessível, adicione **temporariamente**, só para administrador, um identificador de correlação na resposta de erro. Gere um `ref` (ex.: `bin2hex(random_bytes(4))`), grave no `error_log` como `[Convidados][Fatal][ref=xxxx]` e devolva só o `ref` no JSON (`dados.ref`), **nunca** a mensagem do banco. Deixe esse padrão permanente: ele não vaza dados e facilita o suporte.

### Passo 2 — Testar as hipóteses na ordem e parar na que confirmar

**H1 — Produção com versão antiga do endpoint (bind `'sssii'`).**
- Compare o `api/api_convidados.php` do servidor com o do repositório (`git show HEAD:api/api_convidados.php`). Procure por `'sssii'`.
- Mensagem típica no log: `The number of elements in the type definition string must match the number of bind variables` ou `ArgumentCountError`.
- **Correção**: subir o arquivo atual do `main` e limpar o OPcache (cPanel → Select PHP Version/MultiPHP, ou um `opcache_reset()` temporário protegido). Confirme que `registro-convidados.js` e `registro.js` em produção também são os do `main`.

**H2 — Objetos de banco ausentes no banco de produção.**
Rode no phpMyAdmin, **no mesmo banco de `ERP_DB_NAME`**:
```sql
SELECT DATABASE();
SHOW TABLES LIKE 'convidados';
SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME='moradores'   AND COLUMN_NAME IN ('tenant_id','nome'))
     OR (TABLE_NAME='dependentes' AND COLUMN_NAME IN ('tenant_id','nome','nome_completo'))
     OR (TABLE_NAME='registros_acesso' AND COLUMN_NAME IN ('convidado_id','tipo')));
SHOW COLUMNS FROM registros_acesso LIKE 'tipo';
```
- Se `convidados` não existir, rode a migration no banco correto.
- Se a procedure falhar por `DELIMITER` ou `CREATE ROUTINE`, crie a variante `sql/migration_lista_convidados_mysql57_sem_procedure.sql`, com comandos simples, executáveis um a um no phpMyAdmin e acompanhados de SELECTs de verificação prévia (sem procedure, sem DELIMITER).
- Se `dependentes` tiver `nome` e **não** `nome_completo`, troque o JOIN por um nome resolvido em tempo de execução (`convidados_coluna_existe`, já disponível) ou por `COALESCE`, e registre a divergência em `DATABASE.md`. Mensagem típica: `Unknown column 'd.nome_completo'`.

**H3 — Outra exceção dentro de `convidados_listar` / `convidados_formatar_linha`** (se o log mostrar algo diferente de H1/H2).
- Reproduza localmente no XAMPP com o mesmo schema de produção (exporte a estrutura) e corrija a causa exata.
- Verifique `get_result()` (requer mysqlnd; outros endpoints já usam) e conversões de tipo em PHP 8.

### Passo 3 — Endurecer para não voltar a acontecer

- Mantenha o `ref` de correlação no 500 (Passo 1.2).
- Na listagem, centralize a montagem de `tipos` + `parametros` num único array de pares e derive a string de tipos dele, para nunca mais divergir a contagem. Adicione `assert`/validação: `strlen($tipos) === count($parametros)` e `substr_count($sql, '?') === count($parametros)` antes do `bind_param`. Se divergir, lance uma exceção com mensagem clara no log.
- No frontend, quando a API devolver 500, mostre o estado de erro com o botão "Tentar novamente" e o `ref` (se houver). Não dispare uma nova requisição a cada clique de data enquanto a anterior está pendente (confirme o `AbortController`/debounce de `scheduleList`).
- Não altere nenhum outro fluxo do Registro Manual.

### Passo 4 — Testes

1. `php -l api/api_convidados.php`.
2. `listar` sem filtros para hoje, ontem, amanhã e uma data sem convidados retorna 200 com `convidados: []` e `resumo` zerado quando não houver dados.
3. `listar` com cada filtro isolado e combinado (`unidade`, `tipo`, `status` incluindo `EXPIRADO`, `busca`) e com paginação (`pagina=2`) retorna 200.
4. Criar um convidado → aparece na data prevista e nos dias de permanência → não aparece fora deles.
5. Tenant isolado: usuário de outro tenant não vê o registro.
6. Console sem erros. Registro Manual (Morador, Visitante, Prestador, Pedestre, Ocupantes) sem regressão.

### Passo 5 — Documentar

- Atualize este arquivo com a **causa raiz confirmada** (mensagem real do log), a correção aplicada e os testes.
- Atualize `CHANGELOG.md` (seção Corrigido) e, se o schema divergir, `DATABASE.md`.
- Se a causa for deploy, acrescente em `DEPLOY.md` um checklist: "após subir arquivos PHP, conferir versão no servidor + limpar OPcache + rodar migrations pendentes no banco de `ERP_DB_NAME`".

## FIM DO PROMPT
