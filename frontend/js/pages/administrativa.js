/**
 * administrativa.js — Hub de Módulos Administrativos v2.1
 *
 * Cards e abas usam handlers locais que encaminham a rota ao AppRouter.
 *
 * Este módulo é responsável apenas por:
 *   1. Acessibilidade: ativar cards com Enter/Espaço via teclado
 *   2. Log de debug para diagnóstico
 *
 * Sub-páginas disponíveis:
 *   - protocolos            → /pages/protocolos.html
 *   - contratos             → /pages/contratos.html
 *   - eventos               → /pages/eventos.html
 *   - checklists            → /pages/checklists.html
 *   - inventario            → /pages/inventario.html
 *   - marketplace           → /pages/marketplace.html
 *   - crm                   → /pages/crm.html
 *   - cadastro_fornecedor_admin → /pages/cadastro_fornecedor_admin.html
 */
'use strict';

let _listeners = [];

export function init() {
    console.log('[Administrativa] Inicializando módulo v2.1...');
    _setupClickNavigation();
    _setupKeyboardNavigation();
    console.log('[Administrativa] Cards disponíveis:', _getCardList());
    console.log('[Administrativa] Módulo pronto.');
}

export function destroy() {
    console.log('[Administrativa] Destruindo módulo...');
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
    console.log('[Administrativa] Módulo destruído.');
}

function _setupClickNavigation() {
    const controls = document.querySelectorAll('.page-administrativa [data-page]');

    controls.forEach(control => {
        const fn = () => {
            const pageName = control.dataset.page;
            if (pageName && window.AppRouter) {
                console.log(`[Administrativa] Navegação por clique: ${pageName}`);
                window.AppRouter.loadPage(pageName);
            }
        };
        control.addEventListener('click', fn);
        _listeners.push({ el: control, ev: 'click', fn });
    });
}

/**
 * Acessibilidade: permite navegar pelos cards usando Enter ou Espaço no teclado.
 */
function _setupKeyboardNavigation() {
    const cards = document.querySelectorAll('.page-administrativa .page-card[data-page]');

    cards.forEach(card => {
        const fn = (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const pageName = card.dataset.page;
                console.log(`[Administrativa] Navegação por teclado: ${pageName}`);
                if (window.AppRouter) {
                    window.AppRouter.loadPage(pageName);
                }
            }
        };
        card.addEventListener('keydown', fn);
        _listeners.push({ el: card, ev: 'keydown', fn });
    });

    console.log(`[Administrativa] Acessibilidade configurada para ${cards.length} card(s).`);
}

function _getCardList() {
    return Array.from(
        document.querySelectorAll('.page-administrativa .page-card[data-page]')
    ).map(c => c.dataset.page);
}
