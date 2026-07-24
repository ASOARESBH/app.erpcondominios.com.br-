/**
 * sidebar-superadmin.js v3.0
 * ===========================
 * Adiciona o item "Super Admin" no menu lateral APENAS para usuários
 * com permissao = 'super_admin', usando MenuController.addItem().
 *
 * Também exibe um banner de contexto quando o super_admin está
 * navegando em outro tenant (empresa).
 *
 * @version 3.0.0 (Multi-Tenant — com navegação entre empresas)
 */
(function () {
    'use strict';

    var log = function () {
        console.log.apply(console, ['[SidebarSuperAdmin v3]'].concat(Array.prototype.slice.call(arguments)));
    };

    var _inicializado  = false;
    var _itemAdicionado = false;

    // ── Verificar permissão via localStorage (rápido) ─────────────────
    function verificarLocalStorage() {
        try { return localStorage.getItem('usuario_permissao') === 'super_admin'; }
        catch (e) { return false; }
    }

    // ── Adicionar item ao menu via MenuController ─────────────────────
    function adicionarItemMenu() {
        if (_itemAdicionado) return;

        // Método 1: MenuController.addItem() (preferido)
        if (window.MenuController && typeof window.MenuController.addItem === 'function') {
            // Verificar se já existe
            if (window.MenuController.getItem('superadmin')) {
                _itemAdicionado = true;
                log('Item superadmin já existe no MenuController');
                return;
            }

            var adicionado = window.MenuController.addItem({
                id:        'superadmin',
                label:     'Super Admin',
                icon:      'fas fa-crown',
                page:      'superadmin',
                href:      'layout-base.html?page=superadmin',
                order:     99,
                separator: true,
                style:     'color: #f59e0b; font-weight: 700;'
            });

            if (adicionado) {
                _itemAdicionado = true;
                log('Item Super Admin adicionado via MenuController.addItem()');
                return;
            }
        }

        // Método 2: Fallback — exibir elemento #nav-superadmin se existir no sidebar HTML
        var navItem = document.getElementById('nav-superadmin');
        if (navItem) {
            navItem.style.display = 'block';
            _itemAdicionado = true;
            log('Item Super Admin exibido via #nav-superadmin');
            return;
        }

        // Método 3: Fallback — injetar item diretamente no .nav-menu
        var navMenu = document.querySelector('.nav-menu');
        if (navMenu) {
            // Verificar se já foi injetado
            if (navMenu.querySelector('[data-page="superadmin"]')) {
                _itemAdicionado = true;
                return;
            }

            var li = document.createElement('li');
            li.className = 'nav-item nav-item-superadmin';
            li.innerHTML = [
                '<a href="layout-base.html?page=superadmin" data-page="superadmin" class="nav-link" ',
                '   style="color:#f59e0b;font-weight:700;border-top:1px solid rgba(255,255,255,0.1);margin-top:0.5rem;padding-top:0.75rem;">',
                '<i class="fas fa-crown"></i>',
                '<span>Super Admin</span>',
                '</a>'
            ].join('');

            // Inserir antes do botão de sair (último item)
            var lastItem = navMenu.lastElementChild;
            if (lastItem) {
                navMenu.insertBefore(li, lastItem);
            } else {
                navMenu.appendChild(li);
            }

            _itemAdicionado = true;
            log('Item Super Admin injetado diretamente no .nav-menu');
        }
    }

    // ── Verificar via API e atualizar dados ───────────────────────────
    function verificarViaAPI() {
        fetch('/api/verificar_sessao.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (res) {
            if (!res || !res.sucesso || !res.dados) return;

            var usuario = res.dados.usuario;
            var tenant  = res.dados.tenant;

            // Salvar permissão no localStorage
            if (usuario && usuario.permissao) {
                try {
                    localStorage.setItem('usuario_permissao', usuario.permissao);
                    localStorage.setItem('usuario_nome', usuario.nome || '');
                    localStorage.setItem('usuario_id',   String(usuario.id || ''));
                } catch (e) {}
            }

            // Salvar dados do tenant no localStorage
            if (tenant) {
                try {
                    localStorage.setItem('tenant_id',    String(tenant.id   || ''));
                    localStorage.setItem('tenant_slug',  tenant.slug  || '');
                    localStorage.setItem('tenant_nome',  tenant.nome  || '');
                    localStorage.setItem('tenant_plano', tenant.plano || '');
                } catch (e) {}
            }

            // Adicionar item Super Admin se for super_admin
            if (usuario && usuario.permissao === 'super_admin') {
                adicionarItemMenu();
                log('super_admin confirmado via API:', usuario.email);
            }

            // Verificar e exibir banner de contexto
            _verificarBannerContexto(tenant);
        })
        .catch(function (e) { log('Erro API:', e.message); });
    }

    // ── Banner de contexto (quando navegando em outro tenant) ─────────
    function _verificarBannerContexto(tenantAtual) {
        var tenantOriginalId = null;
        try { tenantOriginalId = localStorage.getItem('superadmin_tenant_original'); } catch (e) {}

        if (!tenantOriginalId) return; // Não está navegando em outro tenant

        var tenantAtualId = tenantAtual ? String(tenantAtual.id || '') : '';
        if (tenantOriginalId === tenantAtualId) return; // Mesmo tenant

        // Está navegando em outro tenant — exibir banner
        if (document.getElementById('sa-contexto-banner')) return; // Já existe

        var tenantNome = (tenantAtual && tenantAtual.nome) || localStorage.getItem('tenant_nome') || 'Condomínio';

        var banner = document.createElement('div');
        banner.id = 'sa-contexto-banner';
        banner.style.cssText = [
            'position:fixed',
            'top:0',
            'left:0',
            'right:0',
            'z-index:99999',
            'background:linear-gradient(135deg,#f59e0b,#d97706)',
            'color:white',
            'padding:0.5rem 1.25rem',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'gap:1rem',
            'font-size:0.875rem',
            'font-weight:600',
            'box-shadow:0 2px 8px rgba(0,0,0,0.25)',
            'font-family:inherit'
        ].join(';');

        banner.innerHTML = [
            '<i class="fas fa-eye"></i>',
            '<span>Você está visualizando como: <strong>' + tenantNome + '</strong></span>',
            '<button id="btn-sair-tenant-banner" style="',
            '  background:rgba(255,255,255,0.2);',
            '  border:1px solid rgba(255,255,255,0.5);',
            '  color:white;',
            '  padding:0.25rem 0.75rem;',
            '  border-radius:6px;',
            '  cursor:pointer;',
            '  font-size:0.8rem;',
            '  font-weight:600;',
            '  font-family:inherit;',
            '">',
            '  <i class="fas fa-sign-out-alt"></i> Voltar ao Painel',
            '</button>'
        ].join('');

        document.body.prepend(banner);

        // Ajustar padding do body para não sobrepor conteúdo
        var mainContent = document.querySelector('.main-content, #appContent, .content-area');
        if (mainContent) {
            mainContent.style.paddingTop = (parseInt(mainContent.style.paddingTop || 0) + 40) + 'px';
        }

        // Evento do botão
        var btn = document.getElementById('btn-sair-tenant-banner');
        if (btn) {
            btn.addEventListener('click', function () {
                _sairTenant();
            });
        }

        log('Banner de contexto exibido para tenant:', tenantNome);
    }

    // ── Sair do tenant (retornar ao painel super_admin) ───────────────
    function _sairTenant() {
        fetch('/api/api_superadmin.php?action=sair_tenant', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.sucesso) {
                try { localStorage.removeItem('superadmin_tenant_original'); } catch (e) {}
                window.location.href = '/frontend/layout-base.html?page=superadmin';
            } else {
                alert('Erro ao sair: ' + (res.mensagem || 'Tente novamente'));
            }
        })
        .catch(function () {
            try { localStorage.removeItem('superadmin_tenant_original'); } catch (e) {}
            window.location.href = '/frontend/layout-base.html?page=superadmin';
        });
    }

    // Expor globalmente para uso em onclick
    window._sairTenantSuperAdmin = _sairTenant;

    // ── Inicialização ─────────────────────────────────────────────────
    function init() {
        if (_inicializado) return;
        _inicializado = true;
        log('Inicializando v3.0...');

        // 1. Verificação rápida via localStorage (sem delay)
        if (verificarLocalStorage()) {
            adicionarItemMenu();
        }

        // 2. Confirmação via API em background (atualiza dados e exibe banner)
        verificarViaAPI();
    }

    // Ouvir evento sidebarLoaded
    document.addEventListener('sidebarLoaded', function () {
        log('sidebarLoaded recebido');
        init();
        // Re-tentar adicionar o item após o sidebar ser renderizado
        if (verificarLocalStorage()) {
            setTimeout(adicionarItemMenu, 100);
        }
    });

    // DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(init, 500);
    });

    // Se o DOM já está pronto
    if (document.readyState !== 'loading') {
        setTimeout(init, 300);
    }

})();
