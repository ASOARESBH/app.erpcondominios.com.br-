/**
 * AppRouter - Gerenciador de Navegação Profissional
 * Responsável por carregar conteúdo HTML e lógica JS separadamente.
 */
const AppRouter = {
    // Armazena a referência ao módulo da página atual para limpeza
    currentPageModule: null,
    initialized: false,

    // Configurações
    config: {
        pagesPath: '/frontend/pages/',
        scriptsPath: '/frontend/js/pages/',
        contentContainerId: 'appContent',
        titleElementId: 'pageTitle'
    },

    /**
     * Inicializa o router
     */
    init() {
        if (this.initialized) return;
        this.initialized = true;
        console.log('[Router] Inicializando...');

        // Listener para navegação via botões back/forward
        window.addEventListener('popstate', () => {
            const page = this.getPageFromURL('dashboard');
            console.log('[Router] popstate detectado:', page);
            this.canonicalizeCurrentURL(page);
            this.loadPage(page, false);
        });

        document.addEventListener('click', (event) => this.handleDocumentClick(event));
        this.canonicalizeCurrentURL(this.getPageFromURL('dashboard'));

        console.log('[Router] Pronto (URL management habilitado)');
    },

    /**
     * Obtém a página do caminho limpo ou da URL legada (?page=X).
     * @param {string} defaultPage - Página padrão se não houver parâmetro
     * @returns {string} Nome da página
     */
    getPageFromURL(defaultPage = 'dashboard') {
        const pathname = window.location.pathname;
        let routePath = pathname;
        try { routePath = decodeURIComponent(pathname); } catch (_) { /* nome inválido será rejeitado */ }
        const urlParams = new URLSearchParams(window.location.search);
        const cleanRoute = routePath.match(/^\/([a-z0-9_-]+)\/?$/);
        const legacyLayout = /^\/(?:frontend\/)?layout-base\.html\/?$/i.test(routePath);
        const invalidSingleSegment = !cleanRoute && !legacyLayout
            ? routePath.match(/^\/([^/]+)\/?$/)
            : null;
        const page = cleanRoute
            ? cleanRoute[1]
            : (legacyLayout ? urlParams.get('page') : (invalidSingleSegment ? invalidSingleSegment[1] : null));
        const permissoes = String(localStorage.getItem('usuario_permissao') || '').toLowerCase();
        const emContextoDeTenant = !!localStorage.getItem('superadmin_tenant_original');

        // Depois do login, e também para URLs antigas apontando ao dashboard,
        // o Super-Admin deve iniciar no painel global. Ao entrar em um tenant,
        // a flag superadmin_tenant_original preserva a navegação operacional.
        if (permissoes === 'super_admin' && !emContextoDeTenant && (!page || page === 'dashboard')) {
            return 'superadmin';
        }
        return page || defaultPage;
    },

    isValidPageName(pageName) {
        return typeof pageName === 'string' && /^[a-z0-9_-]+$/.test(pageName);
    },

    normalizePageQuery(pageName, sourceParams) {
        const params = new URLSearchParams(sourceParams || '');
        params.delete('page');

        if (pageName === 'registro' || pageName === 'moradores') {
            const allowedTabs = pageName === 'registro' ? ['manual', 'convidados'] : ['alertas'];
            if (params.has('aba') && !allowedTabs.includes(params.get('aba'))) params.delete('aba');
        }

        if (pageName === 'registro') {
            const registroId = params.get('registro_id') || '';
            const numericId = Number(registroId);
            if (!/^\d+$/.test(registroId) || !Number.isSafeInteger(numericId) || numericId <= 0) params.delete('registro_id');
        } else {
            params.delete('registro_id');
        }

        return params;
    },

    cleanURLForPage(pageName, sourceParams, hash = '') {
        const params = this.normalizePageQuery(pageName, sourceParams);
        const query = params.toString();
        return `/${pageName}${query ? `?${query}` : ''}${hash || ''}`;
    },

    canonicalizeCurrentURL(pageName) {
        if (!this.isValidPageName(pageName)) return false;

        const current = new URL(window.location.href);
        const legacyLayout = /^\/(?:frontend\/)?layout-base\.html\/?$/i.test(current.pathname);
        const route = current.pathname.match(/^\/([a-z0-9_-]+)\/?$/);
        const shouldCanonicalize = legacyLayout || !route || current.pathname !== `/${pageName}` || current.searchParams.has('page');
        if (!shouldCanonicalize) return false;

        const params = this.normalizePageQuery(pageName, current.searchParams);
        const cleanURL = this.cleanURLForPage(pageName, params, current.hash);
        const state = { ...(window.history.state || {}), page: pageName, aba: params.get('aba') || null };
        window.history.replaceState(state, '', cleanURL);
        return true;
    },

    handleDocumentClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const target = event.target;
        const link = target && typeof target.closest === 'function' ? target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download')) return;
        const targetName = (link.getAttribute('target') || '').toLowerCase();
        if (targetName && targetName !== '_self') return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (_) {
            return;
        }
        if (url.origin !== window.location.origin) return;

        const legacyLayout = /^\/(?:frontend\/)?layout-base\.html\/?$/i.test(url.pathname);
        const pageFromLegacyLink = legacyLayout ? url.searchParams.get('page') : null;
        const pageFromData = link.dataset && link.dataset.page;
        if (!pageFromData && !pageFromLegacyLink) return;

        const pageName = pageFromData || pageFromLegacyLink;
        if (!this.isValidPageName(pageName)) return;
        event.preventDefault();
        this.loadPage(pageName, true, { searchParams: url.searchParams, hash: url.hash });
    },

    getNavigationParams(pageName, navigation = {}) {
        if (navigation.searchParams && [...navigation.searchParams.keys()].some((key) => key !== 'page')) {
            return this.normalizePageQuery(pageName, navigation.searchParams);
        }

        const currentParams = new URLSearchParams(window.location.search);
        const currentPage = this.getPageFromURL('');
        if (currentPage === pageName) return this.normalizePageQuery(pageName, currentParams);

        return new URLSearchParams();
    },

    /**
     * Carrega CSS específico da página dinamicamente
     * Remove CSS da página anterior e carrega o novo
     * @param {string} pageName - Nome da página (sem extensão)
     */
    loadPageCSS(pageName) {
        console.log(`[Router] Carregando CSS para: ${pageName}`);

        // Remove CSS da página anterior
        const existingCSS = document.getElementById('dynamic-page-css');
        if (existingCSS) {
            console.log('[Router] Removendo CSS anterior');
            existingCSS.remove();
        }

        // Criar novo elemento link
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        // Anexa imediatamente para evitar a corrida assíncrona do HEAD: em produção
        // alguns servidores bloqueiam HEAD ou respondem após a página já renderizar.
        // O versionamento também impede que o CSS antigo deixe o Dashboard sem os cartões.
        const versaoCSS = '20260917-layout-1';
        link.href = `/assets/css/pages/${pageName}.css?v=${versaoCSS}`;
        link.id = 'dynamic-page-css';
        link.onload = () => console.log(`[Router] ✅ CSS carregado: ${pageName}.css`);
        link.onerror = () => console.log(`[Router] ℹ️ CSS específico não encontrado para ${pageName}`);
        document.head.appendChild(link);
    },

    /**
     * Carrega uma página dinamicamente
     * @param {string} pageName - Nome do arquivo (sem extensão)
     * @param {boolean} updateHistory - Se true, atualiza URL com pushState (default: true)
     */
    async loadPage(pageName, updateHistory = true, navigation = {}) {
        console.log(`[Router] Carregando página: ${pageName} (updateHistory: ${updateHistory})`);

        const container = document.getElementById(this.config.contentContainerId);
        if (!container) return;

        if (!this.isValidPageName(pageName)) {
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Erro ao carregar página</h3>
                    <p>Nome de página inválido.</p>
                </div>
            `;
            console.warn('[Router] Nome de página inválido rejeitado');
            return;
        }

        // Guard visual: o backend continua sendo a fonte de verdade, mas o router
        // evita carregar uma página que o usuário não pode sequer visualizar.
        if (window.MenuController && typeof window.MenuController.autorizarPagina === 'function') {
            const autorizado = await window.MenuController.autorizarPagina(pageName);
            if (!autorizado) {
                container.innerHTML = `<div class="error-state" style="text-align:center;padding:3rem;"><i class="fas fa-lock" style="font-size:2.5rem;color:#dc2626;"></i><h3>Acesso negado</h3><p>Você não possui permissão para acessar este módulo.</p><button class="btn-retry" onclick="AppRouter.loadPage('dashboard')">Voltar ao painel</button></div>`;
                document.dispatchEvent(new CustomEvent('accessDenied', { detail:{ page:pageName }, bubbles:false }));
                return;
            }
        }

        // 1. Mostrar Loading
        container.innerHTML = `
            <div class="loading" style="display:block; text-align:center; padding: 3rem;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color:var(--color-primary-600)"></i>
                <p style="margin-top:1rem; color:var(--color-text-tertiary)">Carregando conteúdo...</p>
            </div>
        `;

        try {
            // 2. Limpeza da página anterior (Lifecycle: destroy)
            if (this.currentPageModule && typeof this.currentPageModule.destroy === 'function') {
                console.log('[Router] Executando cleanup da página anterior...');
                this.currentPageModule.destroy();
            }
            this.currentPageModule = null;

            // 3. Carregar CSS específico da página (em paralelo com HTML)
            this.loadPageCSS(pageName);

            // 4. Carregar HTML
            const htmlResponse = await fetch(`${this.config.pagesPath}${pageName}.html`);
            if (!htmlResponse.ok) throw new Error(`Erro ${htmlResponse.status} ao carregar HTML`);
            const htmlContent = await htmlResponse.text();

            // 5. Injetar HTML
            // Importante: Scripts inline NÃO executam aqui por segurança e por design
            container.innerHTML = htmlContent;

            // 6. Atualizar Título (se houver metadados)
            this.updatePageMeta();

            // Atualizar a URL antes de init() para que os módulos leiam seus filtros/abas.
            if (updateHistory) {
                const params = this.getNavigationParams(pageName, navigation);
                const newUrl = this.cleanURLForPage(pageName, params, navigation.hash || '');
                history.pushState({ page: pageName, aba: params.get('aba') || null }, '', newUrl);
                console.log(`[Router] URL atualizada: ${newUrl}`);
            }

            // 6. Carregar Script da Página (Lifecycle: init)
            // Usa import dinâmico do ES6
            try {
                const scriptPath = `${this.config.scriptsPath}${pageName}.js?t=${new Date().getTime()}`; // Cache busting dev
                const module = await import(scriptPath);

                if (module && typeof module.init === 'function') {
                    console.log(`[Router] Inicializando módulo: ${pageName}`);
                    module.init();
                    this.currentPageModule = module;
                } else {
                    console.warn(`[Router] Módulo ${pageName}.js carregado mas não possui método init() exportado.`);
                }
            } catch (scriptError) {
                // É normal algumas páginas não terem script específico (apenas estáticas)
                if (scriptError.message.includes('Failed to fetch') || scriptError.code === 'ERR_MODULE_NOT_FOUND') {
                    console.log(`[Router] Página ${pageName} operando sem script específico.`);
                } else {
                    console.error('[Router] Erro ao carregar script da página:', scriptError);
                }
            }

            // 7. Disparar evento pageLoaded para guard de permissões e outros listeners
            document.dispatchEvent(new CustomEvent('pageLoaded', {
                detail: { page: pageName },
                bubbles: false
            }));
            console.log(`[Router] Evento pageLoaded disparado para: ${pageName}`);

        } catch (error) {
            console.error('[Router] Erro fatal:', error);
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Erro ao carregar página</h3>
                    <p>${error.message}</p>
                    <button onclick="AppRouter.loadPage('${pageName}')" class="btn-retry">Tentar Novamente</button>
                </div>
            `;
        }
    },

    /**
     * Busca metadados no HTML injetado para atualizar o Header
     */
    updatePageMeta() {
        // As páginas podem ter um <div id="page-metadata" data-title="X" data-icon="Y"></div>
        const metadata = document.getElementById('page-metadata');
        const titleEl = document.getElementById(this.config.titleElementId);

        if (metadata && titleEl) {
            const title = metadata.dataset.title || 'Sistema';
            const icon = metadata.dataset.icon || 'fa-circle';
            titleEl.innerHTML = `<i class="fas ${icon}"></i> ${title}`;
        }
    },

    /**
     * Alias para loadPage para compatibilidade com scripts que usam navigate()
     * @param {string} pageName - Nome da página
     */
    navigate(pageName) {
        return this.loadPage(pageName);
    }
};

// Tornar global e exportável
window.AppRouter = AppRouter;

// Export ES6
export { AppRouter };
