/**
 * eventos.js — Módulo Eventos (stub)
 */
'use strict';

let _listeners = [];

export function init() {
    console.log('[Eventos] Inicializando módulo stub...');
    _setupBtnBack();
}

export function destroy() {
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
}

function _setupBtnBack() {
    document.querySelectorAll('[data-page]').forEach(el => {
        const fn = () => {
            const page = el.dataset.page;
            if (window.AppRouter) window.AppRouter.loadPage(page);
        };
        el.addEventListener('click', fn);
        _listeners.push({ el, ev: 'click', fn });
    });
}
