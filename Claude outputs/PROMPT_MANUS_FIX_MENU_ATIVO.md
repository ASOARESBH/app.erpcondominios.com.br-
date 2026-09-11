# Prompt para o Manus — Corrigir item ativo do menu lateral

Copie e cole o bloco abaixo inteiro para o Manus.

---

Você vai corrigir um bug real já diagnosticado no repositório `app.erpcondominios.com.br-`. Não precisa reinvestigar do zero — a causa raiz já foi identificada abaixo; sua tarefa é aplicar a correção, testar e commitar.

## Bug observado

Ao navegar pela SPA (ex.: clicar em "Acesso" no menu lateral, URL vai para `layout-base.html?page=acesso`), o conteúdo da página muda corretamente, mas o item destacado (classe `active`, fundo azul) no menu lateral **não acompanha o clique** — continua marcado no item anterior (ex.: "Registro Manual"), mesmo a página atual sendo outra. O mesmo problema afeta a navegação por botões voltar/avançar do navegador.

## Causa raiz (já confirmada nos arquivos)

- `frontend/js/menu-controller.js` calcula qual link deve ficar `active` na função `markActive()` (por volta da linha 287), usando `detectCurrentPage()` para ler o `?page=` da URL atual. Essa função funciona corretamente — o problema não é o cálculo, é **quando ele é chamado**.
- `markActive()` só é executado: na inicialização (`initialize()`, uma vez, no carregamento completo da página), no evento `sidebarLoaded`, no evento `erp:locale-changed`, e nas operações internas de CRUD do menu (`addItem`/`removeItem`/`updateItem`/`setMode`). **Nunca é chamado quando o usuário navega dentro da SPA.**
- `frontend/js/app-router.js`, na função `loadPage()`, é quem faz a navegação real: troca o conteúdo, atualiza a URL com `history.pushState` (linha ~171) e, ao final, dispara `document.dispatchEvent(new CustomEvent('pageLoaded', { detail:{ page: pageName } }))` (linha ~176). **Esse evento existe exatamente para avisar outras partes do sistema que a página mudou, mas nada no `menu-controller.js` está ouvindo `pageLoaded`.**
- Há inclusive um trecho morto em `app-router.js` (linhas ~162–166) com um comentário e uma linha comentada (`// window.SidebarController.setActiveLink(pageName);`) que mostra que essa integração foi planejada e nunca terminada:
  ```js
  // 7. Atualizar Sidebar Ativa
  if (window.SidebarController) {
      // window.SidebarController.setActiveLink(pageName);
      // Precisaremos ajustar o SidebarController para aceitar "nomes" ou links virtuais
  }
  ```
- O menu já expõe `markActive` publicamente em `window.MenuController.markActive` (ver o objeto `api` retornado no fim do arquivo, linha ~400-422) — não é preciso criar nada novo, só conectar o evento que já existe (`pageLoaded`) à função que já existe (`markActive`).
- Confirmado também que o handler de `popstate` em `app-router.js` (linha ~27-33) chama o mesmo `loadPage()`, então a mesma correção resolve tanto clique no menu quanto voltar/avançar do navegador.
- Não há conflito de nomes de página: `registro` e `acesso` são chaves distintas e corretas em `menu-controller.js` (linhas 9-10 e no mapa `LEGACY_GROUP_BY_PAGE`), então não é um problema de mapeamento — é puramente falta do listener.

## Correção a aplicar

**1. `frontend/js/menu-controller.js`** — dentro de `initialize()`, ao lado do listener já existente para `sidebarLoaded` (por volta da linha 392-397), adicione:

```js
document.addEventListener('pageLoaded', function (event) {
    markActive();
});
```

Não é necessário chamar `renderMenu()` aqui — o menu em si não muda de conteúdo na troca de página, só qual item está marcado como ativo, que é exatamente o que `markActive()` faz sozinho.

**2. `frontend/js/app-router.js`** — remova o bloco morto/comentado do passo 7 (linhas ~162-166, `// 7. Atualizar Sidebar Ativa` com a referência a `window.SidebarController`) ou substitua por um comentário correto indicando que a atualização do item ativo agora é feita por `menu-controller.js` ao ouvir o evento `pageLoaded` disparado logo abaixo (passo 9). Não altere o disparo do evento `pageLoaded` em si (linha ~176-180) — ele já está correto e é a peça que faltava conectar.

## Como testar antes de commitar

1. Recarregar a aplicação, entrar em qualquer módulo (ex.: Dashboard).
2. Clicar em pelo menos 4-5 itens diferentes do menu lateral em sequência (ex.: Moradores → Veículos → Acesso → Registro Manual → Financeiro) e confirmar que o item destacado em azul muda junto, sempre correspondendo à página exibida.
3. Testar o botão "Voltar" do navegador após navegar por 2-3 páginas e confirmar que o destaque também acompanha corretamente (caminho do `popstate`).
4. Confirmar no DevTools que existe sempre exatamente um `<a class="nav-link active">` correspondente ao `data-page` da página atual, nunca zero nem mais de um.
5. Verificar que nada mais quebrou: colapsar/expandir o menu, troca de idioma (evento `erp:locale-changed`) e o carregamento inicial (`initialize()`) continuam funcionando como antes.

## Ao terminar: commit e push

1. Adicionar apenas os dois arquivos alterados (`frontend/js/menu-controller.js` e `frontend/js/app-router.js`) — não usar `git add -A`.
2. Commit objetivo em português, por exemplo:
   `fix: sincroniza item ativo do menu lateral com a navegacao SPA (evento pageLoaded)`
3. Fazer `push` para o repositório remoto, na branch atual (verifique com `git status`/`git branch` antes; não force push).
4. Se este projeto tiver o hábito de registrar mudanças em `AI-CONTEXT/CHANGELOG.md`, adicione uma linha breve descrevendo a correção, sem apagar o histórico existente.
