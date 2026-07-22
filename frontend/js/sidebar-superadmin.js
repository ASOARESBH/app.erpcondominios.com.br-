/**
 * sidebar-superadmin.js
 * =====================
 * Exibe o item "Super Admin" no menu lateral apenas quando
 * o usuário logado tem permissão = 'super_admin'.
 *
 * Também atualiza o nome do condomínio no header da sidebar
 * com o nome do tenant ativo da sessão.
 *
 * Carregado automaticamente pelo layout-base.html após o sidebar.
 *
 * @version 1.0.0 (Multi-Tenant)
 * @date 2026-07-22
 */
(function () {
    'use strict';

    const log = (...args) => console.log('[SidebarSuperAdmin]', ...args);

    /**
     * Verifica a sessão e exibe/oculta o item Super-Admin
     */
    function init() {
        // Verificar sessão via API
        fetch('/api/verificar_sessao.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(res => {
            if (!res.sucesso || !res.dados) return;

            const usuario = res.dados.usuario;
            const tenant  = res.dados.tenant;

            // Exibir Super Admin apenas para super_admin
            if (usuario && usuario.permissao === 'super_admin') {
                const navItem = document.getElementById('nav-superadmin');
                if (navItem) {
                    navItem.style.display = 'block';
                    log('Super Admin visível para:', usuario.email);
                }
            }

            // Atualizar nome do condomínio na sidebar
            if (tenant && tenant.nome) {
                const header = document.querySelector('.sidebar-header h1');
                if (header) {
                    header.textContent = tenant.nome;
                    log('Tenant no header:', tenant.nome);
                }
            }

            // Salvar dados do tenant no localStorage para uso pelo frontend
            if (tenant) {
                localStorage.setItem('tenant_id',   tenant.id   || '');
                localStorage.setItem('tenant_slug', tenant.slug || '');
                localStorage.setItem('tenant_nome', tenant.nome || '');
                localStorage.setItem('tenant_plano',tenant.plano|| '');
            }
        })
        .catch(e => log('Erro ao verificar sessão:', e));
    }

    // Aguardar o sidebar ser carregado antes de inicializar
    document.addEventListener('sidebarLoaded', function () {
        log('Sidebar carregada, inicializando...');
        init();
    });

    // Fallback: inicializar após DOMContentLoaded se o evento não disparar
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
            const navItem = document.getElementById('nav-superadmin');
            if (navItem && navItem.style.display === 'none') {
                init();
            }
        }, 800);
    });

})();
