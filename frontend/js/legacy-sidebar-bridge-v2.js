(function () {
    'use strict';

    const config = {
        logEnabled: true,
        fallbackEnabled: true,
        useMenuController: true
    };

    const pageToHrefFallback = {
        dashboard: 'dashboard.html',
        moradores: 'moradores.html',
        veiculos: 'layout-base.html?page=veiculos',
        visitantes: 'layout-base.html?page=visitantes',
        registro: 'layout-base.html?page=registro',
        acesso: 'layout-base.html?page=acesso',
        relatorios: 'layout-base.html?page=relatorios',
        financeiro: 'layout-base.html?page=financeiro',
        configuracao: 'configuracao.html',
        manutencao: 'manutencao.html',
        administrativa: 'administrativa.html'
    };

    function log(message, data) {
        if (!config.logEnabled) return;
        if (typeof data !== 'undefined') {
            console.log('[LegacySidebarBridge v2]', message, data);
        } else {
            console.log('[LegacySidebarBridge v2]', message);
        }
    }

    function getPageToHref() {
        if (
            config.useMenuController &&
            window.MenuController &&
            typeof window.MenuController.getPageToHref === 'function'
        ) {
            try {
                return window.MenuController.getPageToHref();
            } catch (error) {
                log('Falha ao ler mapeamento do MenuController', error);
            }
        }

        return { ...pageToHrefFallback };
    }

    function markActiveByHref(links) {
        const currentFile = (window.location.pathname.split('/').pop() || '').toLowerCase();
        const currentPageParam = new URLSearchParams(window.location.search).get('page');
        const legacyFinancialPages = ['contas_pagar.html', 'contas_receber.html', 'planos_contas.html'];

        links.forEach((link) => link.classList.remove('active'));

        if (currentPageParam) {
            const pageLink = Array.from(links).find((link) => link.dataset.page === currentPageParam);
            if (pageLink) {
                pageLink.classList.add('active');
                return;
            }
        }

        if (legacyFinancialPages.includes(currentFile)) {
            const financeLink = Array.from(links).find((link) => link.dataset.page === 'financeiro');
            if (financeLink) {
                financeLink.classList.add('active');
            }
            return;
        }

        const current = Array.from(links).find((link) => {
            const href = (link.getAttribute('href') || '').toLowerCase();
            return href === currentFile || href.startsWith(currentFile + '?');
        });

        if (current) {
            current.classList.add('active');
        }
    }

    function applyFallbackNavigation(sidebar) {
        const pageToHref = getPageToHref();
        const links = sidebar.querySelectorAll('a[data-page]');

        links.forEach((link) => {
            const page = link.dataset.page;
            if (pageToHref[page]) {
                link.setAttribute('href', pageToHref[page]);
            }
        });

        markActiveByHref(sidebar.querySelectorAll('.nav-link'));
    }

    function renderWithMenuController(sidebar) {
        if (
            !config.useMenuController ||
            !window.MenuController ||
            typeof window.MenuController.renderMenu !== 'function'
        ) {
            return false;
        }

        const container = sidebar.querySelector('.nav-menu');
        if (!container) {
            return false;
        }

        const rendered = window.MenuController.renderMenu({ container: container, scope: sidebar });
        if (rendered && typeof window.MenuController.markActive === 'function') {
            window.MenuController.markActive({ container: container, scope: sidebar });
        }

        return !!rendered;
    }

    function emitSidebarLoaded(sidebar) {
        const event = new CustomEvent('sidebarLoaded', {
            detail: { sidebar: sidebar }
        });
        document.dispatchEvent(event);
    }

    async function carregarSidebarComponente() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) {
            log('Elemento #sidebar nao encontrado');
            return;
        }

        try {
            const response = await fetch('components/sidebar.html');
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            sidebar.innerHTML = html;

            const renderedByController = renderWithMenuController(sidebar);
            if (!renderedByController) {
                applyFallbackNavigation(sidebar);
            }

            if (typeof window.refazerInterfaceUI === 'function') {
                window.refazerInterfaceUI();
            }

            emitSidebarLoaded(sidebar);
        } catch (error) {
            log('Erro ao carregar sidebar', error);

            if (config.fallbackEnabled) {
                sidebar.innerHTML = [
                    '<div class="sidebar-header"><h1>Serra da Liberdade</h1></div>',
                    '<ul class="nav-menu">',
                    '<li class="nav-item"><a href="dashboard.html" class="nav-link" data-page="dashboard">Dashboard</a></li>',
                    '</ul>'
                ].join('');
            }
        }
    }

    function initialize() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', carregarSidebarComponente);
        } else {
            carregarSidebarComponente();
        }
    }

    window.LegacySidebarBridge = {
        initialize: initialize,
        reload: carregarSidebarComponente,
        setLogEnabled: function (enabled) {
            config.logEnabled = !!enabled;
        },
        setUseMenuController: function (enabled) {
            config.useMenuController = !!enabled;
        },
        setFallbackEnabled: function (enabled) {
            config.fallbackEnabled = !!enabled;
        }
    };

    initialize();
})();
