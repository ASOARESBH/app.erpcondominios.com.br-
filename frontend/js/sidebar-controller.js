(function () {
    'use strict';

    const SIDEBAR_CONTAINER_ID = 'sidebar';

    function emitSidebarLoaded(sidebar) {
        const event = new CustomEvent('sidebarLoaded', {
            detail: { sidebar: sidebar }
        });
        document.dispatchEvent(event);
    }

    function renderWithMenuController(sidebar) {
        if (!window.MenuController || typeof window.MenuController.renderMenu !== 'function') {
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

    function setActiveLink() {
        const currentPath = window.location.pathname.split('/').pop();
        const links = document.querySelectorAll('.nav-link, .sidebar-nav a');

        links.forEach((link) => {
            const href = link.getAttribute('href');
            if (href && (href === currentPath || (currentPath === '' && href === 'index.html'))) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }

    async function initSidebar() {
        const sidebar = document.getElementById(SIDEBAR_CONTAINER_ID);
        if (!sidebar) return;

        try {
            const response = await fetch('components/sidebar.html');
            if (!response.ok) {
                throw new Error('Falha ao carregar sidebar');
            }

            const html = await response.text();
            sidebar.innerHTML = html;

            const renderedByController = renderWithMenuController(sidebar);
            if (!renderedByController) {
                setActiveLink();
            }

            if (typeof window.refazerInterfaceUI === 'function') {
                window.refazerInterfaceUI();
            }

            // Restaurar estado collapsed do localStorage após recarregar o sidebar
            const mainContent = document.querySelector('.main-content');
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                if (mainContent) mainContent.classList.add('sidebar-collapsed');
            } else {
                sidebar.classList.remove('collapsed');
                if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            }

            emitSidebarLoaded(sidebar);

            // Fechar sidebar mobile ao clicar fora
            document.addEventListener('click', function (e) {
                const toggle = document.querySelector('.menu-toggle');
                if (
                    window.innerWidth <= 768 &&
                    sidebar.classList.contains('active') &&
                    !sidebar.contains(e.target) &&
                    (!toggle || !toggle.contains(e.target))
                ) {
                    sidebar.classList.remove('active');
                }
            });
        } catch (error) {
            console.error('Erro ao carregar sidebar:', error);
            sidebar.innerHTML = '<div class="p-4 text-red-500">Erro ao carregar menu.</div>';
        }
    }

    function initSubmenus() {
        document.addEventListener('click', function (e) {
            const toggle = e.target.closest('.submenu-toggle') || e.target.closest('[data-toggle="submenu"]');

            if (toggle) {
                e.preventDefault();
                const targetId = toggle.getAttribute('data-target') || toggle.getAttribute('onclick')?.match(/'([^']+)'/)?.[1];

                if (!targetId) return;

                const menu = document.getElementById(targetId);
                if (!menu) return;

                document.querySelectorAll('.submenu-content.show').forEach((current) => {
                    if (current.id !== targetId) current.classList.remove('show');
                });

                document.querySelectorAll('.submenu-toggle.active').forEach((button) => {
                    if (button !== toggle) button.classList.remove('active');
                });

                toggle.classList.toggle('active');
                menu.classList.toggle('show');
                return;
            }

            if (!e.target.closest('.submenu-container')) {
                document.querySelectorAll('.submenu-toggle').forEach((button) => button.classList.remove('active'));
                document.querySelectorAll('.submenu-content').forEach((menu) => menu.classList.remove('show'));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initSidebar();
            initSubmenus();
        });
    } else {
        initSidebar();
        initSubmenus();
    }
})();

// Global logout delegation for dynamically loaded sidebar.
document.addEventListener('click', function (e) {
    if (e.target.closest('#btn-logout')) {
        e.preventDefault();
        e.stopPropagation();

        const modal = document.getElementById('modalLogout');
        if (modal) {
            modal.classList.add('show');
        }
    }

    if (e.target.closest('#cancelLogout')) {
        e.preventDefault();

        const modal = document.getElementById('modalLogout');
        if (modal) {
            modal.classList.remove('show');
        }
    }

    if (e.target.closest('#confirmLogout')) {
        e.preventDefault();

        const confirmBtn = document.getElementById('confirmLogout');
        if (confirmBtn) {
            confirmBtn.innerText = 'Encerrando...';
            confirmBtn.disabled = true;
        }

        if (window.SessionManagerCore && typeof window.SessionManagerCore.getInstance === 'function') {
            const sessionManager = window.SessionManagerCore.getInstance();
            sessionManager.logout();
        } else {
            window.location.href = window.location.origin + '/frontend/login.html';
        }
    }

    if (e.target.id === 'modalLogout') {
        const modal = document.getElementById('modalLogout');
        if (modal) {
            modal.classList.remove('show');
        }
    }
});
