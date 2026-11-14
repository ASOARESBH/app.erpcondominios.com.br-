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
- As telas de login usam somente a marca institucional, nunca a logo do tenant. `assets/img/logos/logo_padrao.png` permanece como original; `assets/img/logos/logo_padrao_login.png` é a derivada aparada sem o tagline embutido e é usada no formulário, que exibe seu subtítulo HTML traduzível. A página informativa usa `assets/img/logos/logo_padrao_trim.png` com tagline.
- `frontend/login.html` mantém o login público fora da SPA: o layout split-screen usa `assets/css/pages/login.css` e exibe o banner `assets/img/login/banner-gestao-condominio.png` inteiro (`object-fit: contain`) para não cortar módulos na lateral, inclusive em tablet; em telas pequenas a área promocional é ocultada para priorizar o formulário. A marca usa a derivada aparada `assets/img/logos/logo_padrao_trim.png`; o `logo_padrao.png` original permanece preservado. Textos e descrições acessíveis do login, recuperação de senha, escolha ERP/Portal e estados dos botões são traduzidos pelos catálogos pt-BR/en-US/es-ES. O link de direitos no rodapé abre `frontend/direitos-e-privacidade.html`, página pública e localizada com informação geral sobre direitos autorais/LGPD, referências oficiais e orientação para procurar a organização controladora; ela não substitui avisos de privacidade por tenant nem recebe requisições de titulares. Nomes retornados pela API no seletor ERP/Portal entram somente via `textContent`. Campos, endpoint, payload de autenticação, recuperação, seleção de perfil e checagem de sessão preservam o fluxo existente.
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
- Busca de documento (2026-11-04) — Portal: `#convDocFeedback` (`.conv-doc-feedback found|notfound|error|loading`), `convidadosDocumentoAlterado()`, `convidadosBuscarDocumento()`, `ConvidadosPortal.docVisitanteId`. Painel: `openGuestModal()`, `showFeedback()` roteado para `[data-modal-feedback]` do modal aberto, `setDocumentFeedback()`, `scheduleAutoDocumentSearch()`.

## 9. RH — Banco de Horas

- `frontend/pages/recursos_humanos.html` e `frontend/js/pages/recursos_humanos.js` aceitam duração em minutos ou `HH:MM`; a prévia usa o saldo global do ledger e marca saldo projetado negativo em vermelho. Pagamento acima do saldo fica bloqueado e aponta Abatimento.
- HTTP 409 `CONFIRMAR_SALDO_NEGATIVO` abre modal próprio, nunca `window.confirm`, com saldo atual/projetado, dívida formatada, justificativa obrigatória e checkbox. O retry preserva `idempotency_key`; durante envio há bloqueio de duplo clique e, após sucesso, o relatório é recarregado.
- Valores dinâmicos do ledger e tooltips de auditoria são inseridos com `_esc()`. Tipos desconhecidos aparecem como “Não classificado” em cinza e saldo devedor como “Saldo devedor” em vermelho. `destroy()` remove o listener global do Escape e fecha/limpa o modal.
- A escala oferece `limite_saldo_negativo_min` em minutos; vazio representa `NULL`, sem limite.
- Ao lado do filtro Colaborador/Departamento, o botão **Gerar** aparece conforme RBAC `recursos_humanos.editar`, respeita mês/ano ou intervalo personalizado e só habilita com período válido e colaborador/departamento. Sem colaborador, permite processar ativos com escala e Banco de Horas ligado em lotes de 20.
- Gerar abre modal próprio com totais e mapeamento diário (batidas, carga, trabalhado, extra, atraso, abonos, valores atuais/esperados, diferença e status). “Mostrar somente divergências”, aviso de teto, fechados e escala ausente, opção administrativa de incluir fechados, revisão expressa e motivo são apresentados antes de aplicar.
- O cliente envia hash/filtro da própria prévia, trava duplo envio, atualiza progresso por lote, pede nova revisão em 409 e mantém o histórico (autor, horário, motivo e totais). Após aplicar, recarrega o relatório de Banco de Horas individual ou agregado; CSV/PDF refletem o filtro de departamento. O selo amarelo de desatualizado aponta divergência e o atalho Gerar.
- O mapa consultivo por departamento é paginado; quando existem lotes não verificados, o aviso informa que a verificação é parcial mesmo se o primeiro lote não tiver divergências. Renderizadores de colaboradores, espelho, escalas, Abono e relatórios escapam conteúdo dinâmico com `_esc()` e normalizam IDs/minutos usados em atributos; caminhos de foto aceitam apenas HTTP(S) da mesma origem e caem para o avatar padrão em caso contrário.

## 10. Ordens de Serviço — vínculo Unidade/Morador

- No modal `Nova Ordem de Serviço`/edição (`frontend/pages/ordens_servico.html`), a seleção segue Unidade → Morador da unidade. Unidades ativas vêm de `api_unidades.php?acao=select`, ficam em ordem numérica crescente e o segundo select permanece desabilitado até haver unidade.
- Moradores são carregados sob demanda de `api_moradores.php?acao=select_por_unidade&unidade=...`, com `credentials: 'include'`; uma troca rápida de unidade aborta a consulta anterior. Selecionar somente a unidade continua permitido para chamados sem morador específico.

## 11. Chat-In (widget global) e novas páginas
- `frontend/js/chatin-widget.js` + `assets/css/chatin.css` são carregados no `layout-base.html` (`ChatIn.iniciar({plataforma:'painel'})`) e no `portal_morador.html` (após validar a sessão, com `token: () => Portal.token`; `ChatIn.encerrar()` no logout). API global: `ChatIn.iniciar/abrir/minimizar/abrirComContato(tipo,id)/montarEmbutido(el)/desmontarEmbutido(el)/encerrar`.
- O widget usa atributos `data-ci` (nunca `data-page`, que o layout intercepta para navegação).
- Novas rotas SPA: `chatin` (sempre visível), `config_controle_acesso` (módulo `sistema`), `eventos` (implementada). Assembleia e Documentos ganharam checkbox "Notificar pelo Chat-In".
- `config_controle_acesso.css` faz `@import` de `config_visitantes.css` (fonte única dos cartões/switches).
- `frontend/login.html` mantém o login público fora da SPA: o layout split-screen usa `assets/css/pages/login.css` e exibe o banner `assets/img/login/banner-gestao-condominio.png` inteiro (`object-fit: contain`) para não cortar módulos na lateral, inclusive em tablet; em telas pequenas a área promocional é ocultada para priorizar o formulário. A marca usa `assets/img/logos/logo_padrao_login.png` sem margens/tagline embutido, e o subtítulo HTML permanece único e traduzível; `logo_padrao.png` original permanece preservado. Textos e descrições acessíveis do login, recuperação de senha, escolha ERP/Portal e estados dos botões são traduzidos pelos catálogos pt-BR/en-US/es-ES. O link de direitos no rodapé abre `frontend/direitos-e-privacidade.html`, página pública e localizada com informação geral sobre direitos autorais/LGPD, referências oficiais e orientação para procurar a organização controladora; ela não substitui avisos de privacidade por tenant nem recebe requisições de titulares. Nomes retornados pela API no seletor ERP/Portal entram somente via `textContent`. Campos, endpoint, payload de autenticação, recuperação, seleção de perfil e checagem de sessão preservam o fluxo existente.
