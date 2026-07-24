/**
 * sidebar-superadmin.js v2.0
 * ===========================
 * Exibe o item "Super Admin" no menu lateral apenas quando
 * o usuário logado tem permissão = 'super_admin'.
 *
 * MELHORIAS v2.0:
 *   ✅ Verifica localStorage PRIMEIRO (resposta imediata, sem fetch)
 *   ✅ Confirma via API em background (garante dados atualizados)
 *   ✅ Retry automático se o nav-superadmin ainda não existe no DOM
 *   ✅ Funciona mesmo se o evento sidebarLoaded não disparar
 *   ✅ Atualiza nome do condomínio no header da sidebar
 *
 * @version 2.0.0 (Multi-Tenant)
 * @date 2026-07-24
 */
(function () {
    'use strict';
    var log = function() { console.log.apply(console, ['[SidebarSuperAdmin]'].concat(Array.prototype.slice.call(arguments))); };
    var _inicializado = false;

    /**
     * Exibe o item Super Admin no menu
     */
    function exibirSuperAdmin() {
        var navItem = document.getElementById('nav-superadmin');
        if (navItem) {
            navItem.style.display = 'block';
            log('Super Admin visível');
            return true;
        }
        return false;
    }

    /**
     * Verifica via localStorage (rápido, sem fetch)
     * O login salva usuario_permissao no localStorage
     */
    function verificarLocalStorage() {
        try {
            var permissao = localStorage.getItem('usuario_permissao');
            return permissao === 'super_admin';
        } catch(e) { return false; }
    }

    /**
     * Verifica via API e atualiza o menu
     */
    function verificarViaAPI() {
        fetch('/api/verificar_sessao.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(res) {
            if (!res || !res.sucesso || !res.dados) return;

            var usuario = res.dados.usuario;
            var tenant  = res.dados.tenant;

            // Salvar permissão no localStorage para uso futuro
            if (usuario && usuario.permissao) {
                try {
                    localStorage.setItem('usuario_permissao', usuario.permissao);
                    localStorage.setItem('usuario_nome', usuario.nome || '');
                } catch(e) {}
            }

            // Exibir Super Admin apenas para super_admin
            if (usuario && usuario.permissao === 'super_admin') {
                // Tentar exibir agora
                if (!exibirSuperAdmin()) {
                    // Se o nav-superadmin ainda não existe, tentar novamente
                    var tentativas = 0;
                    var retry = setInterval(function () {
                        tentativas++;
                        if (exibirSuperAdmin() || tentativas >= 20) {
                            clearInterval(retry);
                        }
                    }, 300);
                }
                log('Usuário super_admin confirmado via API:', usuario.email);
            }

            // Atualizar nome do condomínio na sidebar
            if (tenant && tenant.nome) {
                var header = document.querySelector('.sidebar-header h1, .sidebar-brand h1');
                if (header) header.textContent = tenant.nome;
                // Salvar dados do tenant no localStorage
                try {
                    localStorage.setItem('tenant_id',    String(tenant.id   || ''));
                    localStorage.setItem('tenant_slug',  tenant.slug  || '');
                    localStorage.setItem('tenant_nome',  tenant.nome  || '');
                    localStorage.setItem('tenant_plano', tenant.plano || '');
                } catch(e) {}
                log('Tenant:', tenant.nome);
            }
        })
        .catch(function(e) { log('Erro ao verificar sessão via API:', e.message); });
    }

    /**
     * Inicialização principal — chamada uma única vez
     */
    function init() {
        if (_inicializado) return;
        _inicializado = true;
        log('Inicializando...');

        // 1. Verificação rápida via localStorage (sem delay)
        if (verificarLocalStorage()) {
            exibirSuperAdmin();
        }

        // 2. Confirmação via API em background
        verificarViaAPI();
    }

    // Aguardar o sidebar ser carregado (evento disparado pelo sidebar-controller.js)
    document.addEventListener('sidebarLoaded', function () {
        log('Sidebar carregada, inicializando...');
        init();
    });

    // Fallback: DOMContentLoaded com delay para garantir que o sidebar foi injetado
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
            init();
        }, 600);
    });

    // Fallback extra: se o DOM já está pronto quando o script carrega
    if (document.readyState !== 'loading') {
        setTimeout(init, 400);
    }

})();
