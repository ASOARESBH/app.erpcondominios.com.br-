# PROMPT DE IMPLEMENTAÇÃO — Filtro por Unidade e Morador em Ordens de Serviço

> Gerado em 2026-11-03 após análise de `frontend/pages/ordens_servico.html`, `frontend/js/pages/ordens_servico.js`, `api/api_ordens_servico.php`, `api/api_portal_os.php` e do AI-CONTEXT (`API.md` §10.1, `BUSINESS_RULES.md` §4, `DATABASE.md` §8).
> Tela: `layout-base.html?page=ordens_servico` → aba **Chamados**.
> Copie a partir de "INÍCIO DO PROMPT".

---

## INÍCIO DO PROMPT

Você é o tech lead do **ERP CONDOMÍNIOS**. Implemente na aba **Chamados** de Ordens de Serviço um filtro **Unidade → Morador**. Ao filtrar por uma unidade, a lista mostra **todas as O.S. destinadas àquela unidade que o usuário logado tem permissão de ver**. Opcionalmente, o usuário restringe a um morador específico da unidade.

### 0. Regras obrigatórias

1. Leia antes: `AI-CONTEXT/INDEX.md`, `SKILL.md`, `FRONTEND.md`, `API.md` (§10 e §10.1), `BUSINESS_RULES.md` (§4), `DATABASE.md` (§8), `SECURITY.md` e este arquivo.
2. Sequência: ANALISAR → ENTENDER → PLANEJAR → IMPLEMENTAR → TESTAR → DOCUMENTAR.
3. O `tenant_id` vem sempre da sessão. Use apenas prepared statements e resposta `{sucesso, mensagem, dados}`.
4. **Não altere o modelo de permissões de O.S.** O filtro novo é somado ao escopo existente (`os_escopo_sql()`), nunca o substitui.
5. Use Vanilla JS e siga o padrão atual de `ordens_servico.js` (`state.filtros`, `_coletarFiltros`, `_renderChipsAtivos`, `osRemoverChip`, `osLimparFiltros`).
6. Não edite arquivos `teste_*`, `debug_*`, `*_backup*` ou `*_final*`.

---

### 1. Contexto encontrado no código

| Item | Situação atual | Implicação |
|---|---|---|
| Vínculo O.S. ↔ unidade | `os_chamados` tem `morador_id` (INT, nulo), `morador_nome` e `morador_unidade` (`VARCHAR(50)`, **texto livre gravado como cópia**). Existe índice em `morador_id`, mas **não em `morador_unidade`**. | O filtro precisa de comparação **exata e normalizada** e de um índice novo. |
| Busca atual | `listar` faz `o.morador_unidade LIKE '%texto%'` dentro da busca ampla. Por isso "103" casa com "1030", "Gleba 10" casa com "Gleba 103" etc. | É o problema que o usuário vê hoje. O filtro novo **não** pode usar LIKE. Mesma lição já documentada em Relatórios de Acesso (`unidade_exata=1`, `API.md` §8). |
| Origem de `morador_unidade` | Painel (`criar`/`editar` em `api_ordens_servico.php`, ≈ linhas 1431 e 1619): **aceita o valor enviado pelo navegador** (`$dados['morador_unidade']`). Portal (`api_portal_os.php`, ≈ linha 166): vem do cadastro do morador (`m.unidade`). | Dados do painel podem divergir do cadastro oficial. Ver §4: o backend passa a resolver a unidade pelo `morador_id`. |
| Escopo de permissão | `os_escopo_sql('o')` aplica: gestor sem restrição vê tudo; usuário restritivo vê só as próprias/atribuídas/assumidas; demais veem as próprias + departamentos com `pode_visualizar=1` (`os_permissoes_departamento`). | "Usuários com permissão" = este escopo. O filtro de unidade é um `AND` a mais. **Não abra O.S. fora do escopo por causa da unidade.** |
| Filtros na tela | `#filtro-status`, `#filtro-prioridade`, `#filtro-departamento`, `#filtro-data-ini`, `#filtro-data-fim`, `#filtro-busca`, botões Filtrar/Limpar e chips em `#osChipsAtivos`. | Encaixe os dois selects novos no mesmo grid `.os-filtros` / `.os-filtro-group`. |
| Fontes de dados | `api_unidades.php?acao=select` (já usado em Registro, Hidrômetro, Moradores). `api_moradores.php?unidade=...&ativo=1&por_pagina=0` (já usado em Registro). `ordens_servico.js` já declara `API_MORADORES`. | Reutilize os mesmos endpoints. Declare `API_UNIDADES` no padrão dos outros módulos. |
| Ranking "Top 5 Unidades" (Dashboard) | Já agrupa por `TRIM(morador_unidade)`. | Bônus: clicar numa unidade do ranking abre a aba Chamados já filtrada por ela. |
| Segurança nos chips | `_renderChipsAtivos()` injeta `state.filtros.busca` em `innerHTML` **sem escapar**. | Escape os chips novos e corrija o existente (`_esc`/`textContent`). |

---

### 2. Regras de negócio

1. **Campo Unidade** (select com busca, se o projeto tiver componente; senão, select simples ordenado): opção padrão "Todas". Fonte: unidades ativas do tenant.
2. **Campo Morador**: fica desabilitado até escolher a unidade. Carrega os moradores ativos **daquela unidade**. A primeira opção é **"Todos os moradores da unidade"** (padrão).
3. **Filtrar só por Unidade** retorna todas as O.S. destinadas à unidade, de qualquer morador ou sem morador vinculado, dentro do escopo de permissão do usuário.
4. **Filtrar por Unidade + Morador** retorna só as O.S. daquele morador **naquela unidade**.
5. **Critério de "destinada à unidade"** (não use LIKE):
   - Principal: `UPPER(TRIM(o.morador_unidade)) = UPPER(TRIM(?))`. É a unidade registrada na O.S. no momento da abertura, o que preserva o histórico se o morador mudar de unidade.
   - Recuperação de legado: também incluir `(o.morador_unidade IS NULL OR TRIM(o.morador_unidade) = '') AND o.morador_id IN (SELECT m.id FROM moradores m WHERE m.tenant_id = ? AND UPPER(TRIM(m.unidade)) = UPPER(TRIM(?)))`, para O.S. antigas sem unidade gravada.
   - Com morador: `o.morador_id = ?` **e** o critério de unidade acima. O backend valida que o morador pertence ao tenant. Se o morador não for da unidade informada, retorne lista vazia; não lance erro de "não encontrado".
6. **Permissão**: o resultado é `filtros AND (os_escopo_sql('o'))`. O usuário restritivo continua vendo só as O.S. dele, mesmo filtrando pela unidade. O gestor sem restrição vê todas da unidade. Não crie bypass.
   - ⚠️ **Confirmar com o André:** se a intenção for que certos usuários vejam **todas** as O.S. da unidade, mesmo de departamentos sem permissão, isso exige uma **permissão nova e explícita** (ex.: RBAC `ordens_servico.visualizar_por_unidade`), auditada. Não implemente isso sem confirmação. Por padrão, respeite o escopo atual.
7. Os filtros novos combinam com status, prioridade, departamento, período e busca (todos `AND`).
8. **Contadores**: mostre no topo da lista, quando a unidade estiver filtrada, "X O.S. da unidade {unidade}" (o total retornado). Opcional: mini-resumo por status (abertas / em andamento / finalizadas), calculado no backend com o mesmo `WHERE`.

---

### 3. Backend — `api/api_ordens_servico.php`, ação `listar`

- Novos parâmetros GET: `unidade` (string, até 50 caracteres) e `morador_id` (int > 0). Ignore valores vazios. `morador_id` sem `unidade` → recuse com mensagem "Selecione a unidade antes do morador".
- Monte as condições de §2.5 em `$where`/`$params`/`$types`, no mesmo padrão já usado. **Confira a contagem**: `strlen($types) === count($params)` antes do `bind_param` (houve bug idêntico na Lista de Convidados, ver `DEBUG_LISTA_CONVIDADOS_500_20261102.md`).
- Aplique os mesmos filtros nas ações que dependem da listagem e que a tela usa com os filtros ativos (exportação ou impressão da lista, se houver). Confira no JS quais chamadas reutilizam `state.filtros`.
- **Índice**: crie uma migration idempotente MySQL 5.7, `sql/migration_os_indice_unidade_mysql57.sql`, com `INDEX idx_os_tenant_unidade (tenant_id, morador_unidade)` verificado via `INFORMATION_SCHEMA.STATISTICS`. Também adicione ao `_os_garantir_tabelas()` com o helper existente, se esse for o padrão do arquivo. Sem `DELIMITER`/procedure, se der (o phpMyAdmin da HostGator pode recusar).
- Nova ação de apoio (opcional, recomendada): `GET ?acao=unidades_com_os` → lista das unidades distintas (`TRIM(morador_unidade)`) que têm O.S. **dentro do escopo do usuário**, com a contagem. Use-a para marcar no select quais unidades têm chamados. Não substitui a lista oficial de `api_unidades.php`.
- Log de erro com `os_log()`. Nunca exponha a mensagem do banco ao cliente.

### 4. Qualidade do dado (mesma tarefa, impacto controlado)

- Em `criar` e `editar` do painel: quando `morador_id` vier preenchido, **resolva `morador_nome` e `morador_unidade` a partir de `moradores` no tenant** (`WHERE id = ? AND tenant_id = ?`) e ignore os valores do navegador. É o mesmo princípio do POST manual de acesso (`API.md` §8). Sem `morador_id`, mantenha o comportamento atual (O.S. sem morador ou cliente avulso), com `TRIM`.
- **Não reescreva O.S. antigas automaticamente.** Gere um script **somente de diagnóstico**, `sql/diagnostico_os_unidade_divergente.sql`, que lista as O.S. cujo `morador_unidade` difere da unidade atual do `morador_id`, para o André decidir.
- Confirme que `api_portal_os.php` continua gravando a unidade a partir do cadastro (não mexer, só validar).

### 5. Frontend

**`frontend/pages/ordens_servico.html`** (aba Chamados, bloco `.os-filtros`): adicione dois `.os-filtro-group` antes de "Buscar":

```html
<div class="os-filtro-group">
  <label>Unidade</label>
  <select id="filtro-unidade"><option value="">Todas</option></select>
</div>
<div class="os-filtro-group">
  <label>Morador</label>
  <select id="filtro-morador" disabled><option value="">Selecione a unidade</option></select>
</div>
```

Ajuste o grid/CSS do bloco para não quebrar em telas ≤ 1366px e em mobile (≤ 768px, uma coluna). Hoje a linha já tem 6 campos + ações: avalie quebrar em duas linhas.

**`frontend/js/pages/ordens_servico.js`**:
- `API_UNIDADES = window.location.origin + '/api/api_unidades.php'`. Carregue as unidades uma vez no `init` (cache em memória).
- Ao mudar a unidade: limpe e recarregue o select de morador (`api_moradores.php?unidade=...&ativo=1&por_pagina=0`) com a primeira opção "Todos os moradores da unidade". Use `AbortController` para cancelar a carga anterior. Ao limpar a unidade, desabilite e resete o morador.
- Inclua `unidade` e `morador_id` em `state.filtros`, `_coletarFiltros()`, no envio de `carregarChamados()` (`_get('listar', params)`), em `osRemoverChip()` (remover a unidade remove também o morador) e em `osLimparFiltros()`.
- Chips: "Unidade: Gleba 103" e "Morador: Nome". **Escape o texto** e corrija o chip de busca existente, que hoje injeta HTML sem escapar.
- Mantenha os filtros na URL (`&unidade=...&morador_id=...`), para que o link compartilhado ou o F5 reabra filtrado. Ao carregar a página com esses parâmetros, preencha os selects antes da primeira listagem.
- Bônus: no Dashboard, clicar numa unidade do "Top 5 Unidades" troca para a aba Chamados com `filtro-unidade` aplicado.
- Atualize o placeholder ou a dica da busca, deixando claro que, para a unidade exata, o caminho é o filtro "Unidade".
- Estados: carregando moradores, unidade sem moradores ativos ("Nenhum morador ativo — mostrando todas as O.S. da unidade") e lista vazia com mensagem clara.

---

### 6. Testes obrigatórios

1. `php -l api/api_ordens_servico.php`. Rodar a migration de índice duas vezes (idempotente).
2. **Exatidão**: com O.S. nas unidades "103", "1030" e "Gleba 10"/"Gleba 103", filtrar "103" traz **só** a 103. Diferença de caixa ou espaços (" gleba 103 ") é tratada.
3. **Unidade + Morador**: só as O.S. daquele morador. Morador de outra unidade com a unidade X selecionada traz lista vazia.
4. **Legado**: O.S. sem `morador_unidade` mas com `morador_id` de morador da unidade aparece no filtro.
5. **Permissões**:
   - gestor sem restrição vê todas da unidade;
   - usuário com permissão só no departamento "Elétrica" vê apenas as O.S. de Elétrica da unidade (+ as próprias);
   - usuário restritivo vê só as dele;
   - outro tenant não vê nada.
   Teste também alterando `unidade`/`morador_id` direto na URL da API.
6. **Combinação** com status, prioridade, departamento, período e busca. Paginação correta (total e páginas).
7. **Criar/editar** com `morador_id` grava a unidade do cadastro mesmo que o navegador envie outra (teste via DevTools).
8. **UI**: chips aparecem e removem corretamente, "Limpar" zera tudo, a URL mantém os filtros, o layout funciona em 1366px e no mobile, o console não mostra erros e não há XSS ao buscar `<img src=x onerror=alert(1)>`.
9. **Regressão**: Dashboard, Relatórios, impressão, detalhes, Portal do Morador (O.S. do morador) e app inalterados.

### 7. Documentação

- `API.md` §10/§10.1: parâmetros `unidade`/`morador_id` do `listar`, ação `unidades_com_os` (se criada) e regra de unidade resolvida pelo `morador_id` no criar/editar.
- `BUSINESS_RULES.md` §4: filtro por unidade = correspondência exata + recuperação por `morador_id`, sempre dentro do escopo de permissão.
- `DATABASE.md` §8: índice `idx_os_tenant_unidade`.
- `CHANGELOG.md`: Adicionado / Alterado / Corrigido (XSS do chip de busca) / Testes.
- Registre a decisão sobre a permissão ampliada por unidade (§2.6), conforme a resposta do André.

## FIM DO PROMPT
