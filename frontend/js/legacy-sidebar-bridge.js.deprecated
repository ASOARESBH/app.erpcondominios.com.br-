/**
 * Legacy Sidebar Bridge
 * Usa o mesmo componente sidebar.html nas paginas legadas e converte data-page em links reais.
 */
(function () {
    'use strict';

    const pageToHref = {
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

    function marcarAtivo(links) {
        const arquivoAtual = (window.location.pathname.split('/').pop() || '').toLowerCase();
        const paginasFinanceirasLegadas = ['contas_pagar.html', 'contas_receber.html', 'planos_contas.html'];

        links.forEach((link) => {
            link.classList.remove('active');
        });

        if (paginasFinanceirasLegadas.includes(arquivoAtual)) {
            const linkFinanceiro = Array.from(links).find((l) => l.dataset.page === 'financeiro');
            if (linkFinanceiro) linkFinanceiro.classList.add('active');
            return;
        }

        const linkAtual = Array.from(links).find((l) => {
            const href = (l.getAttribute('href') || '').toLowerCase();
            return href === arquivoAtual;
        });

        if (linkAtual) linkAtual.classList.add('active');
    }

    async function carregarSidebarComponente() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        try {
            const response = await fetch('components/sidebar.html');
            if (!response.ok) return;

            const html = await response.text();
            sidebar.innerHTML = html;

            const linksComDataPage = sidebar.querySelectorAll('a[data-page]');
            linksComDataPage.forEach((link) => {
                const page = link.dataset.page;
                if (pageToHref[page]) {
                    link.setAttribute('href', pageToHref[page]);
                }
            });

            marcarAtivo(sidebar.querySelectorAll('.nav-link'));

            if (typeof window.refazerInterfaceUI === 'function') {
                window.refazerInterfaceUI();
            }
        } catch (error) {
            console.warn('[LegacySidebarBridge] Falha ao carregar sidebar compartilhada:', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', carregarSidebarComponente);
    } else {
        carregarSidebarComponente();
    }
})();
