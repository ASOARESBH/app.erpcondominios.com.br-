/**
 * configuracao.js — Hub de Configurações do Sistema v2.1
 *
 * Esta página atua como "hub" de navegação para os módulos de configuração.
 * Cards e abas usam handlers locais que encaminham a rota ao AppRouter.
 *
 * Sub-páginas disponíveis (cada uma com seu próprio HTML + JS + CSS):
 *   - empresa       → /pages/empresa.html + /js/pages/empresa.js
 *   - usuarios      → /pages/usuarios.html + /js/pages/usuarios.js
 *   - meu_perfil    → /pages/meu_perfil.html + /js/pages/meu_perfil.js
 *   - seguranca     → /pages/seguranca.html (stub)
 *   - notificacoes  → /pages/notificacoes.html (stub)
 *   - sistema       → /pages/sistema.html (stub)
 */
'use strict';

let _listeners = [];

export function init() {
    console.log('[Configuracao Hub] Inicializando módulo v2.1...');

    _setupCardNavigation();
    _setupTabNavigation();

    // Acessibilidade: ativar cards com Enter/Espaço via teclado
    _setupKeyboardNavigation();

    console.log('[Configuracao Hub] Módulo pronto. Cards disponíveis:', _getCardList());
}

export function destroy() {
    console.log('[Configuracao Hub] Destruindo módulo...');
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
    console.log('[Configuracao Hub] Módulo destruído.');
}

function _setupCardNavigation() {
    const cards = document.querySelectorAll('.page-configuracao .page-card.interactive[data-page]');

    cards.forEach(card => {
        const fn = () => {
            const pageName = card.dataset.page;
            if (pageName && window.AppRouter) {
                console.log(`[Configuracao Hub] Navegação por clique: ${pageName}`);
                window.AppRouter.loadPage(pageName);
            }
        };
        card.addEventListener('click', fn);
        _listeners.push({ el: card, ev: 'click', fn });
    });
}

// ============================================================
// ACESSIBILIDADE: navegação por teclado nos cards
// ============================================================

function _setupKeyboardNavigation() {
    const cards = document.querySelectorAll('.page-configuracao .page-card.interactive[data-page]');

    cards.forEach(card => {
        const fn = (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const pageName = card.dataset.page;
                console.log(`[Configuracao Hub] Navegação por teclado: ${pageName}`);
                if (window.AppRouter) {
                    window.AppRouter.loadPage(pageName);
                }
            }
        };
        card.addEventListener('keydown', fn);
        _listeners.push({ el: card, ev: 'keydown', fn });
    });

    console.log(`[Configuracao Hub] Acessibilidade configurada para ${cards.length} card(s).`);
}

function _getCardList() {
    return Array.from(
        document.querySelectorAll('.page-configuracao .page-card.interactive[data-page]')
    ).map(c => c.dataset.page);
}

// ============================================================
// NAVEGAÇÃO POR TABS (submenu)
// ============================================================
function _setupTabNavigation() {
    const tabs = document.querySelectorAll('.page-configuracao .tabs .tab-button[data-page]');
    tabs.forEach(btn => {
        const fn = (e) => {
            e.preventDefault();
            const pageName = btn.dataset.page;
            if (pageName) {
                console.log(`[Configuracao Hub] Tab clicada: ${pageName}`);
                if (window.AppRouter) window.AppRouter.loadPage(pageName);
            }
        };
        btn.addEventListener('click', fn);
        _listeners.push({ el: btn, ev: 'click', fn });
    });
    console.log(`[Configuracao Hub] Tabs configuradas: ${tabs.length}`);
}
