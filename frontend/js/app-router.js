/**
 * AppRouter - Gerenciador de Navegação Profissional
 * Responsável por carregar conteúdo HTML e lógica JS separadamente.
 */
const AppRouter = {
    // Armazena a referência ao módulo da página atual para limpeza
    currentPageModule: null,

    // Configurações
    config: {
        pagesPath: 'pages/',
        scriptsPath: './pages/', // Relativo ao local deste arquivo (js/)
        contentContainerId: 'appContent',
        titleElementId: 'pageTitle'
    },

    /**
     * Inicializa o router
     */
    init() {
        console.log('[Router] Inicializando...');

        // Listener para navegação via botões back/forward
        window.addEventListener('popstate', (event) => {
            console.log('[Router] popstate detectado:', event.state);
            if (event.state && event.state.page) {
                // Navegar sem atualizar histórico (updateHistory: false)
                this.loadPage(event.state.page, false);
            }
        });

        console.log('[Router] Pronto (URL management habilitado)');
    },

    /**
     * Obtém a página da URL (?page=X) ou retorna página padrão
     * @param {string} defaultPage - Página padrão se não houver parâmetro
     * @returns {string} Nome da página
     */
    getPageFromURL(defaultPage = 'dashboard') {
        const urlParams = new URLSearchParams(window.location.search);
        const page = urlParams.get('page');
        return page || defaultPage;
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
        link.href = `/assets/css/pages/${pageName}.css`;
        link.id = 'dynamic-page-css';

        // Verificar se arquivo existe antes de adicionar (evita 404 visual)
        fetch(link.href, { method: 'HEAD' })
            .then(response => {
                if (response.ok) {
                    document.head.appendChild(link);
                    console.log(`[Router] ✅ CSS carregado: ${pageName}.css`);
                } else {
                    console.log(`[Router] ℹ️ CSS não encontrado para ${pageName} (isso é normal para páginas sem CSS específico)`);
                }
            })
            .catch(error => {
                console.log(`[Router] ℹ️ Nenhum CSS específico para ${pageName}`);
            });
    },

    /**
     * Carrega uma página dinamicamente
     * @param {string} pageName - Nome do arquivo (sem extensão)
     * @param {boolean} updateHistory - Se true, atualiza URL com pushState (default: true)
     */
    async loadPage(pageName, updateHistory = true) {
        console.log(`[Router] Carregando página: ${pageName} (updateHistory: ${updateHistory})`);

        const container = document.getElementById(this.config.contentContainerId);
        if (!container) return;

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

            // 7. Atualizar Sidebar Ativa
            if (window.SidebarController) {
                // window.SidebarController.setActiveLink(pageName); 
                // Precisaremos ajustar o SidebarController para aceitar "nomes" ou links virtuais
            }

            // 8. Atualizar URL (se solicitado)
            if (updateHistory) {
                const newUrl = `?page=${pageName}`;
                history.pushState({ page: pageName }, '', newUrl);
                console.log(`[Router] URL atualizada: ${newUrl}`);
            }

            // 9. Disparar evento pageLoaded para guard de permissões e outros listeners
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
