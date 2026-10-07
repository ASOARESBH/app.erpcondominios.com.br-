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
    // O roteador impede a abertura direta de uma página sem permissão, mas a
    // visão geral também precisa esconder as abas/cards dos submódulos negados.
    // A autorização continua sendo server-side; aqui apenas refletimos o estado.
    void _filtrarSubmodulosPorPermissao().finally(() => {
        _setupKeyboardNavigation();
        _setupTabNavigation();
        console.log('[Manutencao] Cards disponíveis:', _getCardList());
        console.log('[Manutencao] Módulo pronto.');
    });
}

async function _filtrarSubmodulosPorPermissao() {
    const controles = Array.from(document.querySelectorAll('.page-manutencao [data-page]'));
    if (!controles.length || !window.MenuController || typeof window.MenuController.autorizarPagina !== 'function') return;

    const paginas = [...new Set(controles.map((el) => el.dataset.page).filter(Boolean))];
    const acesso = new Map();
    await Promise.all(paginas.map(async (pagina) => {
        try {
            acesso.set(pagina, await window.MenuController.autorizarPagina(pagina));
        } catch (erro) {
            console.error('[Manutencao] Falha ao consultar permissão de', pagina, erro);
            acesso.set(pagina, false);
        }
    }));

    controles.forEach((el) => {
        const permitido = acesso.get(el.dataset.page) === true;
        el.hidden = !permitido;
        if (!permitido) {
            el.setAttribute('aria-hidden', 'true');
            if (el.classList.contains('interactive')) el.setAttribute('tabindex', '-1');
        }
    });
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
        document.querySelectorAll('.page-manutencao .page-card[data-page]:not([hidden])')
    ).map(c => c.dataset.page);
}
