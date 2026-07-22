/**
 * Manutencao Page Module
 *
 * Gerencia a página de visão geral de Manutenção:
 *  - Navegação pelos cards interativos para subpáginas
 *  - Acessibilidade via teclado (Enter/Space)
 *
 * @module manutencao
 * @version 3.0.0
 */
'use strict';

let _listeners = [];

// ============================================================
// LIFECYCLE
// ============================================================
export function init() {
    console.log('[Manutencao] Inicializando módulo v3.0...');
    _setupKeyboardNavigation();
    _setupTabNavigation();
    console.log('[Manutencao] Cards disponíveis:', _getCardList());
    console.log('[Manutencao] Módulo pronto.');
}

export function destroy() {
    console.log('[Manutencao] Destruindo módulo...');
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
    console.log('[Manutencao] Módulo destruído.');
}

// ============================================================
// NAVEGAÇÃO POR TECLADO (acessibilidade)
// ============================================================
function _setupKeyboardNavigation() {
    const cards = document.querySelectorAll('.page-manutencao .page-card[data-page]');
    cards.forEach(card => {
        const fn = (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const pageName = card.dataset.page;
                console.log(`[Manutencao] Navegação por teclado: ${pageName}`);
                _navegarPara(pageName);
            }
        };
        card.addEventListener('keydown', fn);
        _listeners.push({ el: card, ev: 'keydown', fn });
    });
    console.log(`[Manutencao] Acessibilidade configurada para ${cards.length} card(s).`);
}

// ============================================================
// NAVEGAÇÃO POR TABS (submenu)
// ============================================================
function _setupTabNavigation() {
    const tabs = document.querySelectorAll('.page-manutencao .tabs .tab-button[data-page]');
    tabs.forEach(btn => {
        const fn = (e) => {
            e.preventDefault();
            const pageName = btn.dataset.page;
            if (pageName) {
                console.log(`[Manutencao] Tab clicada: ${pageName}`);
                _navegarPara(pageName);
            }
        };
        btn.addEventListener('click', fn);
        _listeners.push({ el: btn, ev: 'click', fn });
    });
}

// ============================================================
// HELPERS
// ============================================================
function _navegarPara(pageName) {
    if (window.AppRouter && typeof window.AppRouter.loadPage === 'function') {
        window.AppRouter.loadPage(pageName);
    } else {
        console.warn('[Manutencao] AppRouter não disponível. Usando fallback de URL.');
        window.location.href = window.location.origin + `/frontend/layout-base.html?page=${pageName}`;
    }
}

function _getCardList() {
    return Array.from(
        document.querySelectorAll('.page-manutencao .page-card[data-page]')
    ).map(c => c.dataset.page);
}
