# Frontend Guide

## 1. Regra de Ouro
**Proibido uso de Frameworks.** Todo desenvolvimento deve ser feito em HTML5, CSS3 e Vanilla JS.

## 2. Roteamento (AppRouter)
O arquivo `frontend/js/app-router.js` gerencia a navegação.
- As páginas ficam em `frontend/pages/*.html` (apenas o miolo, sem `<html>` ou `<body>`).
- Ao navegar para `?page=clientes`, o Router:
  1. Carrega `frontend/pages/clientes.html` no `#appContent`.
  2. Carrega `assets/css/pages/clientes.css` dinamicamente.
  3. Executa `import { init } from './pages/clientes.js'` e chama `init()`.

## 3. Padrão de Arquivos JS de Página
Todo arquivo JS de página DEVE ser um ES Module:
```javascript
export function init() {
    // Inicialização de eventos, chamadas de API iniciais
}

export function destroy() {
    // Limpeza de timers, listeners globais ao sair da página
}
```

## 4. Branding por Tenant
- As telas de login usam somente `assets/img/logos/logo_padrao.png` e não recebem logo do tenant.
- Em áreas autenticadas, previews e cabeçalhos devem preferir URLs BLOB autenticadas devolvidas pela API, nunca montar caminhos `uploads/...` manualmente.
- O Portal do Morador consulta `get_logo_empresa.php` com a sessão PHP do morador e deve restaurar a marca institucional ou ícone padrão quando a logo não existir ou falhar.

### Registro — Lista de Convidados
- `frontend/js/pages/registro-convidados.js` é um módulo ES separado, montado dentro de `.page-registro`; `destroy()` cancela a lista em andamento, invalida cascatas e remove listeners antes de outra rota ser carregada.
- Respostas de unidades, moradores, dependentes, busca documental, detalhe e gravações devem confirmar que ainda pertencem à mesma raiz SPA conectada antes de modificar o DOM.
- A aba ativa persiste em `?page=registro&aba=manual|convidados`; `frontend/js/app-router.js` preserva `aba` durante a primeira carga/atualização da rota Registro.

## 5. UI Components
- **Modais**: Usar as classes padronizadas `.modal-overlay`, `.modal-content`.
- **Botões**: `.btn-primary-modern`, `.btn-secondary-modern`.
- **Tabelas**: `.table-responsive`, `.data-table`.

### Registro Manual — stepper web
- `frontend/pages/registro.html`, `frontend/js/pages/registro.js` e `assets/css/pages/registro.css` implementam Acesso → Destino → Identificação → Veículo/Pedestre, com identificação omitida para Morador. Etapas concluídas permanecem no DOM como resumos recolhidos com ação Editar; reabrir etapa mantém valores posteriores e invalida/reexecuta a pré-validação correspondente. Ao voltar de Veículo, o módulo pula Identificação se o tipo atual for Morador.
- Pré-validações usam `POST api/api_alertas_unidade.php?acao=verificar` com `parcial=true` e a etapa explícita; respostas parciais não são ciência nem token. Popups decisórios e chip informativo ficam próximos à etapa ativa; a decisão final formal permanece no fluxo `409 ALERTA_PENDENTE` do submit.
- `init()`/`destroy()` removem timers, requisições e listeners do stepper/modal. Limpar reinicia para Acesso e troca a chave de idempotência; convite vinculado abre o formulário preenchido na etapa final. A confirmação de destino divergente pela placa não substitui a seleção do operador.


## 6. Alertas da Unidade (web)

- A rota `moradores` inclui a aba `Alertas`, carregada pelo módulo `frontend/js/pages/moradores-alertas.js`; o ciclo `init()`/`destroy()` limpa listeners e respostas obsoletas. O deep-link `?page=moradores&aba=alertas` é preservado pelo roteador. Filtros são persistidos por usuário em `localStorage` com falha não bloqueante; exportação CSV e impressão/PDF do log de ciências respeitam a permissão `moradores.exportar`.
- KPIs ficam limitados ao tenant autenticado. O detalhe de alerta é dividido em Visão geral, Acionamentos/Ciências e Histórico, com tablist acessível por teclado. A timeline mostra operador, decisão, justificativa, pessoa e link para a linha de `registros_acesso`; o GET de Registro a filtra por ID sob RBAC/tenant e a destaca após abertura. Alertas podem ser duplicados somente por usuário com permissão de criar e editar.
- Registro Manual e Controle de Acesso exibem alertas antes de confirmar Entrada e retomam a gravação somente após ciência/token válido. Leitura automática via QR devolve eventos/decisão ao console; `BLOQUEAR` retorna negação explícita.
- O Portal do Morador inclui Meus Alertas e consulta `api_portal_alertas.php` com Bearer; o formulário de QR verifica conflito antes de gerar e exige confirmação do usuário. A busca de alvos só lista visitantes vinculados ao morador e mascara documentos; o formulário oferece modelos rápidos e resumo natural. O detalhe exibe timeline de acionamentos do mesmo tenant/unidade. Uma opção individual controla push de acionamentos/lembretes; desativá-la preserva a notificação interna na caixa do Portal. Ao criar convite pela Lista de Convidados, um preflight usa a data prevista às 12h locais, pede confirmação se houver alerta e esclarece que a portaria reavalia no horário efetivo.
- A implementação é somente web/backend. Telas e providers Flutter não foram alterados.

## 7. Lista principal de Moradores

- A barra da Lista de Moradores combina busca textual, unidade e ordenação sem botão de envio: texto usa debounce de 350 ms, unidade/ordenação são imediatas e o botão Limpar restaura nome crescente e lista completa. As opções de unidade são deduplicadas e ordenadas naturalmente no frontend.
- A lista paginada envia `unidade_exata=1` apenas junto ao filtro de unidade. Requisições anteriores são abortadas e a sequência garante que apenas a última resposta atualize o DOM. Busca, unidade, ordenação e página ficam na querystring para restauração no refresh; após mutações, recarrega a mesma posição, recuando se a página removida ficar fora do total.

## 8. Convidados (Portal do Morador e painel)
- Portal: aba `convidados` em `frontend/portal_morador.html` (botão em `#mainNav`, item `#mmi-convidados`, `ABAS_INFO`, loader `convidadosInicializar`). Painel `#panel-convidados` com formulário (visitante da unidade opcional, máscara/validação de CPF, prévia da validade), lista Ativos/Histórico com paginação "Carregar mais", modais `#modalConvidadoCancelar` e `#modalConvidadoDetalhe`. Estado em `ConvidadosPortal`; CSS com prefixo `.conv-`.
- Painel: `frontend/js/pages/registro-convidados.js` — `ORIGENS_MORADOR`, `originCell()` (selo "Pré-cadastro"), `setOriginLocked()` (origem travada ao editar pré-cadastro), filtro `#filtroConvidadosOrigem` em `frontend/pages/registro.html`.
