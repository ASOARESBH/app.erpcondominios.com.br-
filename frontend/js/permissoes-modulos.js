/**
 * permissoes-modulos.js  v2.0
 * ─────────────────────────────────────────────────────────────────────
 * Controle de acesso por módulo:
 *   • Filtra o menu lateral conforme permissões do usuário logado
 *   • Bloqueia navegação para páginas sem permissão
 *   • Expõe API pública para verificação granular (pode_criar, pode_editar, etc.)
 *
 * Carregado após menu-controller.js no layout-base.html
 */
(function (global) {
    'use strict';

    // ─────────────────────────────────────────────────────────────────
    // MAPEAMENTO: page (data-page no sidebar) → chave do módulo no banco
    // Deve estar 100% alinhado com as chaves em modulos_sistema.chave
    // ─────────────────────────────────────────────────────────────────
    const PAGE_TO_MODULO = {
        // Core
        dashboard:              'dashboard',

        // Condomínios
        moradores:              'moradores',
        veiculos:               'veiculos',
        visitantes:             'visitantes',

        // Acesso
        registro:               'registro',
        acesso:                 'acesso',
        relatorios:             'relatorios',

        // Financeiro
        financeiro:             'financeiro',
        contas_bancarias:       'financeiro',
        conciliacao:            'conciliacao',
        relatorios_bancarios:   'relatorios_bancarios',
        contas_pagar:           'contas_pagar',
        contas_receber:         'contas_receber',
        planos_contas:          'planos_contas',
        importacao_financeira:  'importacao_financeira',
        logs_financeiro:        'logs_financeiro',

        // Manutenção
        manutencao:             'manutencao',
        hidrometro:             'hidrometro',
        leitura:                'leitura',
        relatorios_hidrometro:  'relatorios_hidrometro',
        abastecimento:          'abastecimento',
        estoque:                'estoque',
        inventario:             'inventario',
        relatorios_inventario:  'relatorios_inventario',

        // Administrativo
        administrativa:         'administrativa',
        assembleia:             'assembleia',
        contratos:              'contratos',
        protocolos:             'protocolos',
        notificacoes:           'notificacoes',
        eventos:                'eventos',
        fornecedores:           'fornecedores',
        cadastro_fornecedor_admin: 'fornecedores',
        documentos:             'documentos',

        // Manutenção (inclui checklists movido de Administrativo)
        checklists:             'checklists',

        // RH
        recursos_humanos:       'recursos_humanos',

        // CRM
        crm:                    'crm',

        // Marketplace
        marketplace:            'marketplace',
        marketplace_admin:      'marketplace_admin',

        // Sistema
        configuracao:           'configuracao',
        pwa_central:            'pwa_central',
        dispositivos:           'dispositivos',
        seguranca:              'seguranca',
        sistema:                'sistema',
        usuarios:               'usuarios',
        empresa:                'empresa',
        meu_perfil:             'meu_perfil'
    };

    // Páginas sempre visíveis (independente de permissão)
    const SEMPRE_VISIVEIS = ['dashboard', 'meu_perfil'];

    // Cache de permissões (carregado uma vez por sessão)
    let _cache = null;
    let _carregando = false;
    let _callbacks = [];

    // ─────────────────────────────────────────────────────────────────
    // CARREGAR PERMISSÕES
    // ─────────────────────────────────────────────────────────────────
    function carregarPermissoes(callback) {
        if (_cache !== null) { callback(_cache); return; }

        try {
            const cached = sessionStorage.getItem('_perm_v2');
            if (cached) {
                _cache = JSON.parse(cached);
                callback(_cache);
                return;
            }
        } catch (e) { /* ignorar */ }

        _callbacks.push(callback);
        if (_carregando) return;
        _carregando = true;

        var BASE = (global.APP_BASE_PATH || '') + '/api/';

        fetch(BASE + 'api_permissoes_modulos.php?acao=minhas_permissoes')
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var normalizado;
                if (data.sucesso && data.dados) {
                    normalizado = {
                        is_admin:   !!data.dados.is_admin,
                        permissoes: data.dados.modulos || data.dados.permissoes || {}
                    };
                } else {
                    // Erro na API → liberar acesso para não bloquear o sistema
                    normalizado = { is_admin: true, permissoes: {} };
                }
                _cache = normalizado;
                try { sessionStorage.setItem('_perm_v2', JSON.stringify(normalizado)); } catch (e) { /* ignorar */ }
                _carregando = false;
                _callbacks.forEach(function (cb) { cb(_cache); });
                _callbacks = [];
            })
            .catch(function (err) {
                console.warn('[PermissoesModulos v2] Erro ao carregar permissões:', err);
                _cache = { is_admin: true, permissoes: {} };
                _carregando = false;
                _callbacks.forEach(function (cb) { cb(_cache); });
                _callbacks = [];
            });
    }

    // ─────────────────────────────────────────────────────────────────
    // VERIFICAÇÃO DE ACESSO
    // ─────────────────────────────────────────────────────────────────

    /**
     * Verifica se o usuário pode acessar uma página.
     * @param {string} page  - chave data-page do sidebar
     * @param {object} perms - objeto retornado pela API (opcional; usa cache se omitido)
     */
    function temAcesso(page, perms) {
        var p = perms || _cache;
        if (!p) return true;                         // sem dados = liberar
        if (p.is_admin) return true;                 // admin tem tudo
        if (SEMPRE_VISIVEIS.indexOf(page) !== -1) return true;

        var chave = PAGE_TO_MODULO[page];
        if (!chave) return true;                     // página não mapeada = liberar

        var mod = p.permissoes && p.permissoes[chave];
        if (!mod) return false;
        return !!mod.pode_acessar;
    }

    /**
     * Verifica permissão granular para uma página.
     * @param {string} page       - chave data-page
     * @param {string} permissao  - 'pode_criar' | 'pode_editar' | 'pode_excluir' | 'pode_exportar'
     */
    function temPermissao(page, permissao) {
        if (!_cache) return true;
        if (_cache.is_admin) return true;

        var chave = PAGE_TO_MODULO[page];
        if (!chave) return true;

        var mod = _cache.permissoes && _cache.permissoes[chave];
        if (!mod) return false;
        return !!mod[permissao];
    }

    // ─────────────────────────────────────────────────────────────────
    // FILTRAR MENU LATERAL
    // ─────────────────────────────────────────────────────────────────
    function filtrarMenu(perms) {
        if (!perms || perms.is_admin) return; // admin: não filtrar nada

        // Ocultar todos os nav-link cujo data-page não tem acesso
        var links = document.querySelectorAll('[data-page]');
        links.forEach(function (link) {
            var page = link.getAttribute('data-page');
            if (!page) return;
            if (!temAcesso(page, perms)) {
                var container = link.closest('li.nav-item') || link.parentElement;
                if (container) {
                    container.style.display = 'none';
                    console.log('[PermissoesModulos v2] Menu oculto:', page);
                }
            }
        });

        // Ocultar submenus que ficaram completamente vazios
        document.querySelectorAll('.submenu-container').forEach(function (container) {
            var visiveis = container.querySelectorAll('.submenu-content li:not([style*="display: none"])');
            if (visiveis.length === 0) {
                container.style.display = 'none';
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // VERIFICAR PÁGINA ATUAL (guard de rota)
    // ─────────────────────────────────────────────────────────────────
    function verificarPaginaAtual(perms) {
        if (!perms || perms.is_admin) return;

        var params = new URLSearchParams(window.location.search);
        var pageParam = params.get('page');
        if (!pageParam) return;

        if (!temAcesso(pageParam, perms)) {
            console.warn('[PermissoesModulos v2] Acesso negado à página:', pageParam);
            var base = window.location.pathname;
            var url = base + '?page=dashboard&acesso_negado=' + encodeURIComponent(pageParam);
            window.location.replace(url);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // BANNER DE ACESSO NEGADO (exibido no dashboard após redirecionamento)
    // ─────────────────────────────────────────────────────────────────
    function verificarRedirecionamento() {
        var params = new URLSearchParams(window.location.search);
        var negado = params.get('acesso_negado');
        if (!negado) return;

        setTimeout(function () {
            var container = document.querySelector('.page-content, #page-content, main, .main-content');
            if (!container) return;
            var banner = document.createElement('div');
            banner.style.cssText = [
                'background:#fee2e2', 'color:#b91c1c', 'border:1px solid #f87171',
                'border-radius:8px', 'padding:1rem 1.25rem', 'margin-bottom:1.5rem',
                'display:flex', 'align-items:center', 'gap:0.75rem', 'font-weight:500'
            ].join(';');
            banner.innerHTML = '<i class="fas fa-lock" style="font-size:1.1rem;"></i>' +
                ' Você não tem permissão para acessar o módulo <strong>' +
                _esc(negado) + '</strong>. Solicite acesso ao administrador do sistema.';
            container.insertBefore(banner, container.firstChild);
            setTimeout(function () { banner.remove(); }, 8000);
        }, 800);
    }

    function _esc(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ─────────────────────────────────────────────────────────────────
    // APLICAR RESTRIÇÕES GRANULARES NA PÁGINA ATUAL
    // Oculta/desabilita botões de criar/editar/excluir conforme permissão
    // ─────────────────────────────────────────────────────────────────
    function aplicarRestricoesGranulares(perms) {
        if (!perms || perms.is_admin) return;

        var params = new URLSearchParams(window.location.search);
        var page = params.get('page');
        if (!page) return;

        var chave = PAGE_TO_MODULO[page];
        if (!chave) return;

        var mod = perms.permissoes && perms.permissoes[chave];
        if (!mod) return;

        // Ocultar botões de criar se não tem pode_criar
        if (!mod.pode_criar) {
            document.querySelectorAll(
                '[data-perm="criar"], .btn-novo, .btn-add, .btn-criar, ' +
                '[data-action="criar"], [data-action="novo"]'
            ).forEach(function (el) { el.style.display = 'none'; });
        }

        // Ocultar botões de editar se não tem pode_editar
        if (!mod.pode_editar) {
            document.querySelectorAll(
                '[data-perm="editar"], .btn-edit, .btn-editar, ' +
                '[data-action="editar"], [data-action="edit"]'
            ).forEach(function (el) { el.style.display = 'none'; });
        }

        // Ocultar botões de excluir se não tem pode_excluir
        if (!mod.pode_excluir) {
            document.querySelectorAll(
                '[data-perm="excluir"], .btn-delete, .btn-excluir, ' +
                '[data-action="excluir"], [data-action="delete"]'
            ).forEach(function (el) { el.style.display = 'none'; });
        }

        // Ocultar botões de exportar se não tem pode_exportar
        if (!mod.pode_exportar) {
            document.querySelectorAll(
                '[data-perm="exportar"], .btn-export, .btn-exportar, ' +
                '[data-action="exportar"], [data-action="export"]'
            ).forEach(function (el) { el.style.display = 'none'; });
        }

        console.log('[PermissoesModulos v2] Restrições granulares aplicadas para:', page, mod);
    }

    // ─────────────────────────────────────────────────────────────────
    // INVALIDAR CACHE
    // ─────────────────────────────────────────────────────────────────
    function invalidarCache() {
        _cache = null;
        try { sessionStorage.removeItem('_perm_v2'); } catch (e) { /* ignorar */ }
        // Compatibilidade com versão anterior
        try { sessionStorage.removeItem('_perm_modulos'); } catch (e) { /* ignorar */ }
        console.log('[PermissoesModulos v2] Cache invalidado.');
    }

    // ─────────────────────────────────────────────────────────────────
    // INICIALIZAÇÃO
    // ─────────────────────────────────────────────────────────────────
    function init() {
        console.log('[PermissoesModulos v2] Inicializando...');

        // Verificar redirecionamento por acesso negado
        verificarRedirecionamento();

        // Carregar permissões e aplicar filtros
        carregarPermissoes(function (perms) {
            console.log('[PermissoesModulos v2] Permissões carregadas. is_admin:', perms.is_admin);

            // Filtrar menu lateral
            filtrarMenu(perms);

            // Verificar se a página atual está acessível
            verificarPaginaAtual(perms);

            // Aplicar restrições granulares (criar/editar/excluir/exportar)
            // Aguardar o conteúdo da página ser carregado pelo router
            setTimeout(function () {
                aplicarRestricoesGranulares(perms);
            }, 1200);
        });

        // Re-aplicar filtro quando o sidebar for recarregado
        document.addEventListener('sidebarLoaded', function () {
            if (_cache) {
                filtrarMenu(_cache);
            } else {
                carregarPermissoes(filtrarMenu);
            }
        });

        // Re-aplicar restrições granulares quando uma nova página for carregada pelo router
        document.addEventListener('pageLoaded', function () {
            if (_cache) {
                aplicarRestricoesGranulares(_cache);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // API PÚBLICA
    // ─────────────────────────────────────────────────────────────────
    global.PermissoesModulos = {
        init:               init,
        invalidarCache:     invalidarCache,
        carregarPermissoes: carregarPermissoes,

        /** Verifica se o usuário pode acessar a página (usa cache) */
        temAcesso: function (page) {
            return temAcesso(page, _cache);
        },

        /** Verifica permissão granular: pode_criar, pode_editar, pode_excluir, pode_exportar */
        temPermissao: function (page, permissao) {
            return temPermissao(page, permissao);
        },

        /** Retorna o objeto de permissões de uma página específica */
        getPermissoes: function (page) {
            if (!_cache) return null;
            if (_cache.is_admin) return { pode_acessar:1, pode_criar:1, pode_editar:1, pode_excluir:1, pode_exportar:1 };
            var chave = PAGE_TO_MODULO[page];
            if (!chave) return null;
            return (_cache.permissoes && _cache.permissoes[chave]) || null;
        },

        /** Retorna true se o usuário logado é admin */
        isAdmin: function () {
            return _cache ? !!_cache.is_admin : true;
        }
    };

    // Auto-inicializar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(window);
