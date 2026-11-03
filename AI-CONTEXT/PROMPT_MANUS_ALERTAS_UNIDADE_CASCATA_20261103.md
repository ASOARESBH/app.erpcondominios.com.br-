# PROMPT MANUS — Alertas da Unidade: ordem das unidades + cascata Unidade → Morador → Dependente

Você é o tech lead do ERP CONDOMÍNIOS (repositório `ASOARESBH/app.erpcondominios.com.br-`). Antes de alterar qualquer coisa, leia `AI-CONTEXT/INDEX.md`, `SKILL.md`, `FRONTEND.md`, `API.md`, `BUSINESS_RULES.md` (§12 Alertas da Unidade) e `CHANGELOG.md` (entrada 2.1.12). Siga a sequência ANALISAR → CAUSA RAIZ → CORRIGIR → TESTAR → DOCUMENTAR.

## Tela
Moradores › aba **Alertas da Unidade** (`layout-base.html?page=moradores`): o filtro "Unidade" da lista e o modal **Novo/Editar/Duplicar alerta da unidade**.

## Problemas
1. **Ordem das unidades errada**: aparece `200, ADMINISTRATIVO, Gleba 1, Gleba 10, Gleba 100, Gleba 101 … Gleba 11, Gleba 110 …`. O esperado é a ordem natural crescente: `200, ADMINISTRATIVO, Gleba 1, Gleba 2, … Gleba 10, Gleba 11, … Gleba 100`.
2. **Selecionar a unidade não filtra o Morador titular nem o Dependente**: o modal mostra todos os moradores e todos os dependentes do condomínio, sem relação com a unidade escolhida. Além disso, com o tipo do titular "Morador", o campo Dependente continua habilitado. Se preenchido, a API recusa o salvamento (`TITULAR_INVALIDO`: "Não informe dependente quando o titular é o morador").

## Causa raiz (confirmada no código)
- `api/api_alertas_unidade.php`, ação `opcoes`: `SELECT DISTINCT unidade FROM moradores ... ORDER BY unidade` ordena como texto. Também não deduplica variações de espaço ou caixa.
- `frontend/js/pages/moradores-alertas.js`:
  - `populateOptions()` preenche `#alertas-form-morador` com **todos** os `state.options.moradores`;
  - não existe evento de `change` para `#alertas-form-unidade` nem para `#alertas-form-titular-tipo`;
  - `updateDependenteOptions()` só filtra pelo morador e inclui itens sem dono (`owner == null`);
  - `resetForm()` força `dependente.disabled = false`;
  - `openEdit()`/`openDuplicate()` aplicam o morador antes de reconstruir as opções da unidade.
- `au_api_validar_titular()` compara a unidade informada com `moradores.unidade` de forma exata. Com unidade "trimada" na lista, `"Gleba 10"` × `"Gleba 10 "` geraria `UNIDADE_DIVERGENTE`.

## Correção esperada

### Backend — `api/api_alertas_unidade.php`
1. Em `acao=opcoes`, ao montar `$unidades`:
   - aplique `trim`;
   - descarte vazios;
   - deduplique por `strtoupper` mantendo a primeira grafia;
   - ordene com `usort(..., function($a,$b){ return strnatcasecmp($a,$b); })`. Use uma closure comum, sem arrow function, por compatibilidade.
2. Em `au_api_validar_titular()`: compare com `strcasecmp($unidadeInformada, trim((string)$morador['unidade'])) !== 0`. Continue gravando a unidade oficial do cadastro do morador (já é assim).
3. Não altere RBAC, tenant (sempre da sessão), contrato de resposta `{sucesso, mensagem, dados}` nem as demais ações.

### Frontend — `frontend/js/pages/moradores-alertas.js` (Vanilla JS, sem frameworks)
1. Crie `const unitCollator = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' })` e `compareUnits(a,b)`. Ordene `state.options.unidades` em `loadOptions()`, como segunda garantia caso venha cache antigo.
2. Crie os helpers:
   - `normalizeUnit(v)`: `trim` + `toUpperCase`;
   - `moradorOwnerId(item)`;
   - `moradoresDaUnidade(unidade)`: compara `normalizeUnit(morador.unidade)` com a unidade selecionada.
3. Crie `updateMoradorOptions()`:
   - sem unidade: morador desabilitado com o placeholder "Selecione a unidade primeiro";
   - com unidade: lista só os moradores dela, com o placeholder "Selecione o morador titular" ou "Nenhum morador ativo nesta unidade" (desabilitado);
   - se houver só 1 morador, pré-selecione;
   - ao final, chame `updateDependenteOptions()`.
4. Reescreva `updateDependenteOptions()`:
   - **Titular tipo "MORADOR"**: o dependente fica desabilitado, `required=false`, valor vazio e o placeholder é "Não se aplica — titular é o morador".
   - **Titular tipo "DEPENDENTE"**:
     - lista os dependentes do morador selecionado ou, sem morador, os de todos os moradores da unidade;
     - campo `required=true`;
     - desabilitado se a lista estiver vazia, com a mensagem "Nenhum dependente ativo para este morador" ou "… nesta unidade";
     - sem morador selecionado, o rótulo mostra "Dependente — Morador".
5. Crie `handleDependenteChange()`: se o dependente for escolhido antes do morador, preencha o morador dono e preserve o dependente escolhido.
6. Em `handleFormChange()`, ligue `#alertas-form-unidade` → `updateMoradorOptions()`, `#alertas-form-titular-tipo` → `updateDependenteOptions()`, `#alertas-form-morador` → `updateDependenteOptions()` e `#alertas-form-dependente` → `handleDependenteChange()`. Remova o `bind` duplicado de `#alertas-form-morador` em `bindEvents()`.
7. `populateOptions()` chama `updateMoradorOptions()` em vez de preencher todos os moradores. `resetForm()` troca `dependente.disabled = false` por `updateMoradorOptions()`.
8. Em `openEdit()` e `openDuplicate()`: depois de aplicar unidade e titular_tipo, chame `updateMoradorOptions()`. Só então aplique `morador_id`, chame `updateDependenteOptions()` e aplique `dependente_id`.
9. Em `readForm()`: envie `dependente_id` **somente** se `titular_tipo === 'DEPENDENTE'`. Em `validateForm()`: mensagens claras para unidade ausente e para dependente ausente quando o titular for Dependente.

## Testes obrigatórios
1. `php -l api/api_alertas_unidade.php`.
2. Ordem: os selects (filtro e modal) mostram `200, ADMINISTRATIVO, Gleba 1, Gleba 2, … Gleba 10, … Gleba 100`, sem duplicatas.
3. Modal novo: o morador fica bloqueado até escolher a unidade. Ao trocar de unidade, a lista muda e a seleção anterior é limpa. Unidade sem moradores mostra a mensagem. Unidade com um morador já o seleciona.
4. Titular Morador: dependente bloqueado e salvar funciona sem `TITULAR_INVALIDO`.
5. Titular Dependente: só aparecem dependentes da unidade ou do morador. Escolher o dependente primeiro preenche o morador. Salvar funciona. Sem dependente, aparece a mensagem de validação.
6. Unidade cadastrada com espaço no fim ("Gleba 10 ") salva sem `UNIDADE_DIVERGENTE`.
7. Editar e Duplicar abrem com unidade, morador e dependente corretos.
8. Regressão: filtro da lista por unidade, exportar/imprimir ciências, Portal do Morador (`api_portal_alertas.php`), avaliação de alertas no Registro Manual e no Console sem alteração. Console do navegador sem erros. Isolamento por tenant preservado.

## Documentação
Atualize `AI-CONTEXT/CHANGELOG.md` (Corrigido + testes executados com resultado) e `BUSINESS_RULES.md` §12, registrando a regra "seleção encadeada Unidade → Morador titular → Dependente; unidades em ordem natural". Preserve o histórico existente.

> Observação: esta correção já foi aplicada e testada localmente no XAMPP do André (CHANGELOG 2.1.12), mas pode **ainda não estar no GitHub**. Antes de codar, verifique se `moradores-alertas.js` já contém `updateMoradorOptions` e `unitCollator`. Se já contiver, apenas revise, teste e complete o que faltar; não duplique o código.
