# PROMPT MANUS — O.S.: filtro Unidade sem quantitativos e em ordem crescente

Você é o tech lead do ERP CONDOMÍNIOS (repositório `ASOARESBH/app.erpcondominios.com.br-`). Antes de alterar, leia `AI-CONTEXT/INDEX.md`, `SKILL.md`, `FRONTEND.md`, `API.md` (§10/§10.1, parágrafo do filtro de unidade em O.S.) e `CHANGELOG.md` (entrada 2.1.11). Siga a sequência ANALISAR → CORRIGIR → TESTAR → DOCUMENTAR.

## Tela
Ordens de Serviço › aba **Chamados** (`layout-base.html?page=ordens_servico&aba=chamados`), select **Unidade** dos filtros.

## Problema
O select mostra a quantidade de O.S. junto do nome: `ADMINISTRATIVO (464 O.S.)`, `Gleba 1 (2 O.S.)`, `Gleba 12 (1 O.S.)`. **Isso não é desejado.** O select deve mostrar **somente o nome da unidade**, em **ordem crescente natural** (`Gleba 1, Gleba 2, … Gleba 10, Gleba 11, … Gleba 100`), sem nenhum quantitativo.

## Causa (confirmada no código)
- `frontend/js/pages/ordens_servico.js`, função `carregarUnidadesFiltro()` (≈ linha 1704):
  - faz `Promise.all` com `api_unidades.php?acao=select` e `_get('unidades_com_os')`;
  - monta o `Map contagens`;
  - o rótulo da opção é `` `${nome} (${total} O.S.)` ``.
- A ordem vem do SQL de `api/api_unidades.php` (`acao=select`): `CAST(SUBSTRING_INDEX(nome,' ',-1) AS UNSIGNED)`. Esse SQL funciona para "Gleba N", mas não garante a ordem para nomes fora desse padrão (ex.: "200", "Gleba 1A", nomes sem número).
- `garantirUnidadeFiltroOption()` acrescenta opções ausentes no **final** do select (ex.: unidade vinda da URL ou do ranking), o que quebra a ordem.

## Correção esperada (somente frontend; não alterar `api_unidades.php`, usado por várias telas)
1. Em `carregarUnidadesFiltro()`:
   - remova a chamada `_get('unidades_com_os')` do `Promise.all` e todo o código de `contagens`;
   - o rótulo passa a ser apenas `unidade.nome`;
   - isso também elimina uma requisição a cada abertura da tela.
2. Ordene `state.unidadesFiltro` antes de preencher o select com um comparador natural:
   ```js
   const collatorUnidades = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });
   const compararUnidades = (a, b) => collatorUnidades.compare(String(a?.nome ?? a ?? '').trim(), String(b?.nome ?? b ?? '').trim());
   ```
   Elimine duplicatas por `trim` + maiúsculas, mantendo a primeira ocorrência.
3. Em `garantirUnidadeFiltroOption()`: ao criar uma opção que não existe, **insira na posição correta** (antes da primeira opção cujo nome seja maior pelo mesmo comparador), não no final. Mantenha o placeholder "Todas" sempre como primeira opção.
4. Não mostre contagem em nenhum outro ponto do select (chips, `title`, `aria-label`). O total de O.S. da listagem filtrada (chip/contador da lista, CHANGELOG 2.1.11) **continua**: ele é o resultado da busca, não do select. Se o André também quiser remover esse total, isso é outra tarefa; não remova sem pedido.
5. Backend: a ação `GET api_ordens_servico.php?acao=unidades_com_os` deixa de ser usada pela tela. **Não a apague nesta tarefa**: pode haver consumidores (dashboard/ranking). Procure no repositório (`grep -rn "unidades_com_os"`). Se não houver nenhum outro uso, registre no AI-CONTEXT como "endpoint sem consumidor, candidato a remoção". Ela continua tenant-safe e com escopo RBAC.

## Testes obrigatórios
1. O select Unidade mostra só nomes: `Todas, 200 (se existir), ADMINISTRATIVO, Gleba 1, Gleba 2, … Gleba 10, Gleba 11, … Gleba 100`, sem "(N O.S.)" e sem duplicatas.
2. No DevTools › Network, abrir a aba Chamados **não** dispara mais `acao=unidades_com_os`.
3. Selecionar a unidade continua carregando os moradores da unidade (`api_moradores.php?acao=select_por_unidade`) e filtrando a lista corretamente.
4. Abrir por URL (`&unidade=Gleba 12`) ou clicar no ranking "Top 5 Unidades" do Dashboard seleciona a unidade, e ela fica na posição certa do select.
5. "Limpar" volta para "Todas". A combinação com status, prioridade, departamento, datas e busca continua igual. As permissões (RBAC, departamento, restritivo) continuam iguais.
6. Console sem erros. Dashboard, Relatórios e impressão sem regressão.

## Documentação
- `AI-CONTEXT/API.md` §10.1: ajuste o trecho que diz que o seletor usa as contagens. Registre que o select mostra só o nome, em ordem natural no frontend, e que `unidades_com_os` não é mais consumida pela aba Chamados.
- `AI-CONTEXT/CHANGELOG.md`: nova entrada "Alterado — O.S.: filtro de unidade sem quantitativos e em ordem crescente natural", com os testes e os resultados.
- Preserve o histórico existente.
