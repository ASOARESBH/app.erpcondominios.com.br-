/**
 * UI REFACTOR - Global Sidebar & Top Header Controller
 * Adere ao novo design (Janeiro/2026) com login superior e sidebar limpa.
 *
 * v1.1 — 2026-06-23
 * - Sino de notificações posicionado DENTRO do grupo de sessão (ao lado do avatar)
 * - Botão de recolher sidebar (desktop) adicionado ao sidebar-header
 * - Lógica de toggle sidebar desktop implementada
 */
(function () {
    'use strict';

    function initUI() {
        const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
        if (!sidebar) return;

        // 1. Garantir que o CSS do Refactor está carregado
        if (!document.getElementById('cssSidebarRefactor')) {
            const link = document.createElement('link');
            link.id = 'cssSidebarRefactor';
            link.rel = 'stylesheet';
            link.href = '../assets/css/sidebar-refactor.css';
            document.head.appendChild(link);
        }

        // 1.1 Injetar Reset Global para anular CSS legado (Head-to-Head Cleanup)
        if (!document.getElementById('styleUIGlobalReset')) {
            const style = document.createElement('style');
            style.id = 'styleUIGlobalReset';
            style.innerHTML = `
                /* Reset de Headers e Layouts Legados */
                .header, header.header { 
                    background: white !important; 
                    background-image: none !important;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
                    border: none !important;
                    color: #1e293b !important;
                }
                .main-content { 
                    margin-left: 260px !important; 
                    background-color: var(--color-background-tertiary) !important;
                    min-height: 100vh !important;
                    transition: margin-left 0.3s ease !important;
                }
                .sidebar {
                    background: #111827 !important;
                    background-image: none !important;
                    transition: width 0.3s ease, transform 0.3s ease !important;
                }
                /* Sidebar recolhida (desktop) */
                .sidebar.collapsed {
                    width: 68px !important;
                }
                .sidebar.collapsed .nav-link span,
                .sidebar.collapsed .nav-link > *:not(i):not(.submenu-arrow),
                .sidebar.collapsed .sidebar-logo-container,
                .sidebar.collapsed .btn-abrir-chamado span,
                .sidebar.collapsed .submenu-content,
                .sidebar.collapsed .submenu-arrow {
                    display: none !important;
                }
                .sidebar.collapsed .nav-link {
                    justify-content: center !important;
                    padding: 12px 8px !important;
                }
                .sidebar.collapsed .nav-link i {
                    margin-right: 0 !important;
                    font-size: 1.2rem !important;
                }
                .sidebar.collapsed .btn-abrir-chamado {
                    justify-content: center !important;
                    padding: 0.65rem 0.5rem !important;
                }
                .sidebar.collapsed .btn-abrir-chamado i:first-child {
                    font-size: 1.1rem !important;
                }
                .sidebar.collapsed .sidebar-header {
                    padding: 16px 8px !important;
                }
                .main-content.sidebar-collapsed {
                    margin-left: 68px !important;
                }
                @media (max-width: 768px) {
                    .main-content { margin-left: 0 !important; }
                    .main-content.sidebar-collapsed { margin-left: 0 !important; }
                    .sidebar.collapsed { width: 260px !important; }
                }
                /* Esconder elementos legados que podem reaparecer */
                .user-mini-profile:not(#userMiniProfile) { display: none !important; }
                /* Grupo de sessão no header: sino + info + avatar juntos */
                #headerSessionGroup {
                    display: flex !important;
                    align-items: center !important;
                    gap: 4px !important;
                }
                /* Tooltip para sidebar recolhida */
                .sidebar.collapsed .nav-link {
                    position: relative;
                }
                .sidebar.collapsed .nav-link:hover::after {
                    content: attr(data-label);
                    position: absolute;
                    left: calc(100% + 10px);
                    top: 50%;
                    transform: translateY(-50%);
                    background: #1e293b;
                    color: #f1f5f9;
                    padding: 5px 10px;
                    border-radius: 6px;
                    font-size: 0.82rem;
                    white-space: nowrap;
                    z-index: 9999;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                    pointer-events: none;
                }
            `;
            document.head.appendChild(style);
        }

        // 2. Refatorar Sidebar Header (Logo + Botão Recolher)
        const sidebarHeader = sidebar.querySelector('.sidebar-header');
        if (sidebarHeader) {
            sidebarHeader.innerHTML = `
                <div class="sidebar-logo-container">
                    <img src="../assets/img/logos/logo_padrao.png"
                         alt="ERP Condomínio"
                         class="sidebar-logo"
                         id="dynamicSidebarLogo"
                         onerror="this.onerror=null;this.style.display='none';">
                </div>
                <button id="sidebarCollapseBtn" title="Recolher menu" style="
                    background: none;
                    border: none;
                    color: #94a3b8;
                    cursor: pointer;
                    width: 32px;
                    height: 32px;
                    border-radius: 6px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 1rem;
                    transition: background 0.2s, color 0.2s;
                    margin: 8px auto 0;
                " onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#f1f5f9';" onmouseout="this.style.background='none';this.style.color='#94a3b8';">
                    <i class="fas fa-bars" id="sidebarCollapseBtnIcon"></i>
                </button>
            `;
        }

        // 3. Remover seções legadas do sidebar (perfil e sessão)
        const legacySections = sidebar.querySelectorAll('.user-profile-section, .profile-section, .user-session, .sidebar-footer');
        legacySections.forEach(el => el.remove());

        // 4. Garantir Top Header (Login Superior)
        ensureTopHeader();

        // 5. Ajustar Botão de Logout para o novo estilo
        const btnLogout = document.getElementById('btn-logout');
        if (btnLogout) {
            btnLogout.classList.add('nav-link-logout');
        }

        // 6. Marcar item ativo baseado na URL
        const currentPath = window.location.pathname.split('/').pop();
        const links = sidebar.querySelectorAll('.nav-link, .sidebar-nav a');
        links.forEach(link => {
            const href = link.getAttribute('href');
            if (href && href.includes(currentPath) && currentPath !== '') {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });

        // 7. Inicializar botão de recolher sidebar (desktop)
        _initSidebarCollapse(sidebar);
    }

    function ensureTopHeader() {
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) return;

        let header = document.querySelector('.header');

        if (!header || !header.querySelector('#userMiniProfile')) {
            if (!header) {
                header = document.createElement('header');
                header.className = 'header';
                mainContent.prepend(header);
            }
        }

        // Título da Página
        const pageTitle = document.title.split('-')[0].trim() || 'Sistema';
        const iconClass = getPageIcon(pageTitle);

        // O sino (#notif-bell-wrap) será inserido DENTRO do #headerSessionGroup
        // pelo notif-bell.js, que agora busca #headerSessionGroup como ponto de inserção
        header.innerHTML = `
            <h1><i class="${iconClass}"></i> ${pageTitle}</h1>
            <div id="headerSessionGroup" class="header-session-group">
                <div class="user-mini-profile" id="userMiniProfile">
                    <div class="user-info-mini">
                        <span class="header-user-name" id="topUserName">Carregando...</span>
                    </div>
                    <div class="top-user-avatar" id="topUserAvatar">U</div>
                </div>
            </div>
        `;
    }

    function _initSidebarCollapse(sidebar) {
        // Aguardar o botão ser renderizado
        setTimeout(function () {
            const btn = document.getElementById('sidebarCollapseBtn');
            const mainContent = document.querySelector('.main-content');
            if (!btn) return;

            // Restaurar estado salvo
            const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (collapsed) {
                sidebar.classList.add('collapsed');
                if (mainContent) mainContent.classList.add('sidebar-collapsed');
                _updateCollapseIcon(true);
            }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const isCollapsed = sidebar.classList.toggle('collapsed');
                if (mainContent) mainContent.classList.toggle('sidebar-collapsed', isCollapsed);
                localStorage.setItem('sidebarCollapsed', isCollapsed);
                _updateCollapseIcon(isCollapsed);
            });
        }, 100);
    }

    function _updateCollapseIcon(collapsed) {
        const icon = document.getElementById('sidebarCollapseBtnIcon');
        if (!icon) return;
        if (collapsed) {
            icon.className = 'fas fa-bars';
        } else {
            icon.className = 'fas fa-bars';
        }
    }

    function getPageIcon(title) {
        const lower = title.toLowerCase();
        if (lower.includes('dashboard')) return 'fas fa-chart-line';
        if (lower.includes('morador')) return 'fas fa-users';
        if (lower.includes('veículo')) return 'fas fa-car';
        if (lower.includes('visitante')) return 'fas fa-user-clock';
        if (lower.includes('registro')) return 'fas fa-clipboard-list';
        if (lower.includes('controle')) return 'fas fa-door-open';
        if (lower.includes('relatório')) return 'fas fa-file-alt';
        if (lower.includes('finan')) return 'fas fa-money-bill-wave';
        if (lower.includes('config')) return 'fas fa-cog';
        if (lower.includes('manuten')) return 'fas fa-tools';
        if (lower.includes('admin')) return 'fas fa-briefcase';
        return 'fas fa-desktop';
    }

    // Inicialização
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initUI();
        });
    } else {
        initUI();
    }

    window.refazerInterfaceUI = initUI;

})();
