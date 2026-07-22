/**
 * Sidebar Unificada - Gerenciador de Sidebar
 * 
 * Responsabilidades:
 * - Gerenciar estado da sidebar
 * - Injetar logo dinâmica
 * - Gerenciar responsividade
 * - Sincronizar com outros componentes
 */

class SidebarUnificada {
    constructor() {
        this.sidebar = null;
        this.isOpen = true;
        this.isMobile = false;
        this.init();
    }

    /**
     * Inicializar o gerenciador
     */
    init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    /**
     * Configurar a sidebar
     */
    setup() {
        this.sidebar = document.getElementById('sidebar');
        if (!this.sidebar) {
            console.warn('[SidebarUnificada] Sidebar não encontrada');
            return;
        }

        // Injetar logo dinâmica
        this.injectLogo();

        // Configurar responsividade
        this.setupResponsive();

        // Adicionar event listeners
        this.attachEventListeners();
    }

    /**
     * Injetar logo dinâmica
     */
    injectLogo() {
        const sidebarHeader = this.sidebar.querySelector('.sidebar-header');
        if (!sidebarHeader) return;

        // Verificar se já tem logo
        if (sidebarHeader.querySelector('img.sidebar-logo')) return;

        // Criar elemento de logo
        const logoImg = document.createElement('img');
        logoImg.src = '../uploads/logo/logo_1769740112.jpeg';
        logoImg.alt = 'Serra da Liberdade';
        logoImg.className = 'sidebar-logo';
        logoImg.style.cssText = `
            max-width: 180px;
            height: auto;
            margin-bottom: 1rem;
        `;

        // Adicionar ao header
        sidebarHeader.insertBefore(logoImg, sidebarHeader.firstChild);

        // Ocultar h1 se existir
        const h1 = sidebarHeader.querySelector('h1');
        if (h1) {
            h1.style.display = 'none';
        }
    }

    /**
     * Configurar responsividade
     */
    setupResponsive() {
        // Verificar tamanho inicial
        this.checkScreenSize();

        // Adicionar listener para resize
        window.addEventListener('resize', () => this.checkScreenSize());
    }

    /**
     * Verificar tamanho da tela
     */
    checkScreenSize() {
        const wasMobile = this.isMobile;
        this.isMobile = window.innerWidth < 768;

        // Se mudou de estado
        if (wasMobile !== this.isMobile) {
            if (this.isMobile) {
                this.sidebar.classList.add('mobile');
                this.closeSidebar();
            } else {
                this.sidebar.classList.remove('mobile');
                this.openSidebar();
            }
        }
    }

    /**
     * Abrir sidebar
     */
    openSidebar() {
        this.isOpen = true;
        this.sidebar.classList.add('open');
        this.sidebar.classList.remove('closed');
    }

    /**
     * Fechar sidebar
     */
    closeSidebar() {
        this.isOpen = false;
        this.sidebar.classList.remove('open');
        this.sidebar.classList.add('closed');
    }

    /**
     * Toggle sidebar
     */
    toggleSidebar() {
        if (this.isOpen) {
            this.closeSidebar();
        } else {
            this.openSidebar();
        }
    }

    /**
     * Adicionar event listeners
     */
    attachEventListeners() {
        // Menu toggle button
        const toggleBtn = document.querySelector('.menu-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggleSidebar());
        }

        // Fechar sidebar ao clicar em um link (mobile)
        const navLinks = this.sidebar.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (this.isMobile) {
                    this.closeSidebar();
                }
            });
        });

        // Fechar sidebar ao clicar fora (mobile)
        document.addEventListener('click', (e) => {
            if (this.isMobile && this.isOpen) {
                if (!this.sidebar.contains(e.target) && !document.querySelector('.menu-toggle').contains(e.target)) {
                    this.closeSidebar();
                }
            }
        });
    }

    /**
     * Obter estado da sidebar
     */
    getState() {
        return {
            isOpen: this.isOpen,
            isMobile: this.isMobile
        };
    }

    /**
     * Definir item ativo
     */
    setActiveItem(href) {
        // Remover classe ativa de todos os links
        const links = this.sidebar.querySelectorAll('.nav-link');
        links.forEach(link => {
            link.classList.remove('active');
        });

        // Adicionar classe ativa ao link correspondente
        const activeLink = this.sidebar.querySelector(`a[href="${href}"]`);
        if (activeLink) {
            activeLink.classList.add('active');
        }
    }

    /**
     * Destruir recursos
     */
    destroy() {
        // Remover event listeners
        window.removeEventListener('resize', () => this.checkScreenSize());
    }
}

// Instanciar globalmente
const sidebarUnificada = new SidebarUnificada();

// Expor para uso global
window.sidebarUnificada = sidebarUnificada;

// Função auxiliar para toggle (compatibilidade)
function toggleMenu() {
    if (window.sidebarUnificada) {
        window.sidebarUnificada.toggleSidebar();
    }
}

// Expor função globalmente
window.toggleMenu = toggleMenu;
