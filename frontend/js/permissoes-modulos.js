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
    // MAPEAMENTO: rota da SPA → chave do catálogo RBAC ativo.
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
        rondas_vigilante:       'rondas_vigilante',
        relatorios:             'relatorios_acesso',
        lpr:                    'lpr',
        unidades:               'unidades',

        // Financeiro
        financeiro:             'financeiro',
        contas_bancarias:       'contas_bancarias',
        conciliacao:            'conciliacao',
        relatorios_bancarios:   'relatorios_bancarios',
        contas_pagar:           'contas_pagar',
        contas_receber:         'contas_receber',
        planos_contas:          'financeiro',
        importacao_financeira:  'importacao_financeira',
        inadimplencia:          'inadimplencia',
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
        administrativa:         'administrativo',
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
        fornecedores:           'marketplace_admin',
        cadastro_fornecedor_admin: 'marketplace_admin',

        // Rotas de submódulos e configuração
        ordens_servico:         'ordens_servico',
        imprimir_os:            'imprimir_os',
        assembleia:             'assembleia',
        protocolo:              'protocolo',
        notificacoes_push:      'notificacoes_push',
        departamentos:          'departamentos',
        email_alertas:          'email_alertas',
        manual:                 'manual',
        'admin-sessoes':        'admin_sessoes',
        admin_sessoes:          'admin_sessoes',
        auditoria:              'auditoria',
        superadmin:             'superadmin',
        aplicativos:            'superadmin_aplicativos',
        superadmin_aplicativos: 'superadmin_aplicativos',
        monitoring:             'superadmin',
        config_visitantes:      'sistema',
        config_moradores:       'sistema',
        config_controle_acesso: 'sistema',

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
    const PAGE_TO_MODULO_LEGADO = {
        relatorios: 'relatorios',
        administrativa: 'administrativa',
        contas_bancarias: 'financeiro',
        ordens_servico: 'manutencao',
        imprimir_os: 'manutencao',
        checklists: 'manutencao',
        unidades: 'moradores',
        lpr: 'acesso',
        rondas_vigilante: 'acesso'
    };

    // Páginas sempre visíveis (independente de permissão)
    const SEMPRE_VISIVEIS = ['dashboard', 'meu_perfil', 'chatin']; // Chat-In: comunicador de todos os usuários autenticados

    // Cache de permissões (carregado uma vez por sessão)
    let _cache = null;
    let _carregando = false;
    let _callbacks = [];

    // ─────────────────────────────────────────────────────────────────
    // CARREGAR PERMISSÕES
    // ─────────────────────────────────────────────────────────────────
    function mostrarFalhaCarregamento() {
        console.warn('[PermissoesModulos v2] Falha ao carregar permissões; operações de escrita bloqueadas.');
        if (document.getElementById('pm-load-error-message')) return;
        var aviso = document.createElement('div');
        aviso.id = 'pm-load-error-message';
        aviso.setAttribute('role', 'alert');
        aviso.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:10050;max-width:min(720px,calc(100vw - 32px));padding:12px 16px;border:1px solid #fca5a5;border-radius:8px;background:#fef2f2;color:#991b1b;box-shadow:0 4px 16px rgba(0,0,0,.15);font:14px/1.45 sans-serif;';
        aviso.textContent = 'Não foi possível carregar as permissões. As operações de escrita estão bloqueadas; atualize a página ou contate o administrador.';
        document.body.appendChild(aviso);
    }

    function limparAvisoFalha() {
        document.getElementById('pm-load-error-message')?.remove();
    }

    function bloquearTemporariamente(el, bloquear) {
        if (bloquear) {
            if (el.dataset.pmPermBloqueado === '1') return;
            el.dataset.pmPermOriginal = JSON.stringify({
                disabled: 'disabled' in el ? el.disabled : null,
                ariaDisabled: el.getAttribute('aria-disabled'),
                tabindex: el.getAttribute('tabindex'),
                pointerEvents: el.style.pointerEvents,
            });
            el.dataset.pmPermBloqueado = '1';
            if ('disabled' in el) el.disabled = true;
            else {
                el.style.pointerEvents = 'none';
                el.setAttribute('tabindex', '-1');
            }
            el.setAttribute('aria-disabled', 'true');
            return;
        }
        if (el.dataset.pmPermBloqueado !== '1') return;
        var original = {};
        try { original = JSON.parse(el.dataset.pmPermOriginal || '{}'); } catch (e) { /* manter bloqueado até nova renderização */ return; }
        if ('disabled' in el && original.disabled !== null) el.disabled = !!original.disabled;
        if (original.ariaDisabled === null) el.removeAttribute('aria-disabled');
        else el.setAttribute('aria-disabled', original.ariaDisabled);
        if (original.tabindex === null) el.removeAttribute('tabindex');
        else el.setAttribute('tabindex', original.tabindex);
        el.style.pointerEvents = original.pointerEvents || '';
        delete el.dataset.pmPermBloqueado;
        delete el.dataset.pmPermOriginal;
    }

    var SELETORES_POR_ACAO = {
        criar: '[data-perm="criar"], .btn-novo, .btn-add, .btn-criar, [data-action="criar"], [data-action="novo"]',
        editar: '[data-perm="editar"], .btn-edit, .btn-editar, [data-action="editar"], [data-action="edit"]',
        excluir: '[data-perm="excluir"], .btn-delete, .btn-excluir, [data-action="excluir"], [data-action="delete"]',
        exportar: '[data-perm="exportar"], .btn-export, .btn-exportar, [data-action="exportar"], [data-action="export"]',
        importar: '[data-perm="importar"], .btn-importar, [data-action="importar"]',
        imprimir: '[data-perm="imprimir"], .btn-imprimir, [data-action="imprimir"]',
        aprovar: '[data-perm="aprovar"], .btn-aprovar, [data-action="aprovar"]',
        executar: '[data-perm="executar"], .btn-executar, [data-action="executar"]',
        configurar: '[data-perm="configurar"], .btn-configurar, [data-action="configurar"]',
        bloquear: '[data-perm="bloquear"], [data-action="bloquear"]',
        desbloquear: '[data-perm="desbloquear"], [data-action="desbloquear"]'
    };
    var SELETOR_ACOES_ESCRITA = Object.values(SELETORES_POR_ACAO).join(',');
    var VERSAO_CACHE_PERMISSOES = 4;

    function bloquearEscritaAteCarregarPermissoes() {
        document.querySelectorAll(SELETOR_ACOES_ESCRITA).forEach(function (el) { bloquearTemporariamente(el, true); });
    }

    function _concluirCarregamento(permissoes) {
        _cache = permissoes;
        if (permissoes.carregamento_falhou) {
            try { sessionStorage.removeItem('_perm_v2'); } catch (e) { /* ignorar */ }
            mostrarFalhaCarregamento();
        } else {
            try { sessionStorage.setItem('_perm_v2', JSON.stringify(permissoes)); } catch (e) { /* ignorar */ }
            limparAvisoFalha();
        }
        _carregando = false;
        var callbacks = _callbacks.splice(0);
        callbacks.forEach(function (cb) { try { cb(_cache); } catch (err) { console.error('[PermissoesModulos v2] Callback RBAC falhou:', err); } });
        try { document.dispatchEvent(new CustomEvent('permissoesAtualizadas', { detail:_cache })); } catch (e) { /* manter atualização interna mesmo sem CustomEvent */ }
        try { global.dispatchEvent(new CustomEvent('permissoesAtualizadas', { detail:_cache })); } catch (e) { /* compatibilidade para listeners no window */ }
    }

    function carregarPermissoes(callback, validarRevisaoRemota) {
        if (callback) _callbacks.push(callback);
        if (_carregando) return;
        if (_cache !== null && !_cache.carregamento_falhou && !validarRevisaoRemota) {
            var callbacksCache = _callbacks.splice(0);
            callbacksCache.forEach(function (cb) { cb(_cache); });
            return;
        }

        _carregando = true;
        bloquearEscritaAteCarregarPermissoes();
        var BASE = (global.APP_BASE_PATH || '') + '/api/';
        var candidato = _cache && !_cache.carregamento_falhou ? _cache : null;
        if (!candidato) {
            try {
                var armazenado = JSON.parse(sessionStorage.getItem('_perm_v2') || 'null');
                if (armazenado?._versao_cache === VERSAO_CACHE_PERMISSOES && !armazenado.carregamento_falhou &&
                    Number.isInteger(Number(armazenado.usuario_id)) && Number.isInteger(Number(armazenado.tenant_id)) &&
                    Number.isInteger(Number(armazenado.revisao)) && typeof armazenado.is_admin === 'boolean' &&
                    armazenado.permissoes && typeof armazenado.permissoes === 'object') candidato = armazenado;
            } catch (e) { /* ignorar cache malformado */ }
        }

        fetch(BASE + 'api_permissoes_modulos.php?acao=revisao_permissoes', { credentials:'include', cache:'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                if (!data.sucesso || !data.dados) throw new Error('Resposta inválida ao consultar revisão RBAC.');
                var remoto = data.dados;
                var corresponde = candidato && Number(candidato.usuario_id) === Number(remoto.usuario_id) &&
                    Number(candidato.tenant_id) === Number(remoto.tenant_id) &&
                    Number(candidato.revisao) === Number(remoto.revisao) &&
                    String(candidato.permissao || '') === String(remoto.permissao || '');
                if (corresponde && Number(remoto.revisao) > 0) {
                    _concluirCarregamento(candidato);
                    return null;
                }
                return fetch(BASE + 'api_permissoes_modulos.php?acao=minhas_permissoes', { credentials:'include', cache:'no-store' })
                    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(function (full) {
                        if (!full.sucesso || !full.dados) throw new Error('Resposta inválida ao carregar permissões.');
                        var permissaoAtual = full.dados;
                        if (Number(permissaoAtual.usuario_id) !== Number(remoto.usuario_id) || Number(permissaoAtual.tenant_id) !== Number(remoto.tenant_id) ||
                            (Number(remoto.revisao) > 0 && Number(permissaoAtual.revisao) !== Number(remoto.revisao))) {
                            throw new Error('A revisão RBAC mudou durante a consulta; atualize a página para tentar novamente.');
                        }
                        _concluirCarregamento({
                            _versao_cache: VERSAO_CACHE_PERMISSOES,
                            usuario_id:Number(permissaoAtual.usuario_id), tenant_id:Number(permissaoAtual.tenant_id),
                            permissao:String(permissaoAtual.permissao || ''), revisao:Number(permissaoAtual.revisao || 0),
                            is_admin:!!permissaoAtual.is_admin, permissoes:permissaoAtual.modulos || permissaoAtual.permissoes || {}
                        });
                    });
            })
            .catch(function (err) {
                console.warn('[PermissoesModulos v2] Falha ao validar/carregar permissões:', err);
                _concluirCarregamento({ is_admin:false, permissoes:{}, carregamento_falhou:true });
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
        if (!p) return true;                         // mantém navegação disponível enquanto carrega
        if (p.carregamento_falhou) return true;      // preservar menu; escrita fica bloqueada e APIs validam no servidor
        if (p.is_admin) return true;                 // admin tem tudo
        if (SEMPRE_VISIVEIS.indexOf(page) !== -1) return true;

        var chave = chavePermissaoDaPagina(page, p);
        if (!chave) return false;
        var mod = p.permissoes && p.permissoes[chave];
        if (!mod) return false;
        return !!mod.pode_acessar;
    }

    function chavePermissaoDaPagina(page, perms) {
        var canonica = PAGE_TO_MODULO[page];
        if (!canonica) return null;
        var mapa = perms && perms.permissoes;
        if (mapa && Object.prototype.hasOwnProperty.call(mapa, canonica)) return canonica;
        var legada = PAGE_TO_MODULO_LEGADO[page];
        return legada && mapa && Object.prototype.hasOwnProperty.call(mapa, legada) ? legada : canonica;
    }

    /**
     * Verifica permissão granular para uma página.
     * @param {string} page       - chave data-page
     * @param {string} permissao  - 'pode_criar' | 'pode_editar' | 'pode_excluir' | 'pode_exportar'
     */
    function temPermissao(page, permissao) {
        if (!_cache || _cache.carregamento_falhou) return false;
        if (_cache.is_admin) return true;

        var chave = chavePermissaoDaPagina(page, _cache);
        if (!chave) return false;

        var mod = _cache.permissoes && _cache.permissoes[chave];
        if (!mod) return false;
        return !!mod[permissao];
    }

    // ─────────────────────────────────────────────────────────────────
    // FILTRAR MENU LATERAL
    // ─────────────────────────────────────────────────────────────────
    function filtrarMenu(perms) {
        if (!perms) return;
        if (perms.carregamento_falhou) {
            restaurarMenuPermissoes();
            return;
        }
        restaurarMenuPermissoes();
        if (perms.is_admin) return; // admin: não filtrar nada

        // Ocultar todos os nav-link cujo data-page não tem acesso
        var links = document.querySelectorAll('[data-page]');
        links.forEach(function (link) {
            var page = link.getAttribute('data-page');
            if (!page) return;
            if (!temAcesso(page, perms)) {
                var container = link.closest('li.nav-item') || link.parentElement;
                if (container) {
                    container.style.display = 'none';
                    container.dataset.pmMenuOculto = '1';
                    console.log('[PermissoesModulos v2] Menu oculto:', page);
                }
            }
        });

        // Ocultar submenus que ficaram completamente vazios
        document.querySelectorAll('.submenu-container').forEach(function (container) {
            var visiveis = container.querySelectorAll('.submenu-content li:not([style*="display: none"])');
            if (visiveis.length === 0) {
                container.style.display = 'none';
                container.dataset.pmSubmenuOculto = '1';
            }
        });
    }

    function restaurarMenuPermissoes() {
        document.querySelectorAll('[data-pm-menu-oculto="1"]').forEach(function (el) {
            el.style.display = '';
            delete el.dataset.pmMenuOculto;
        });
        document.querySelectorAll('[data-pm-submenu-oculto="1"]').forEach(function (el) {
            el.style.display = '';
            delete el.dataset.pmSubmenuOculto;
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // VERIFICAR PÁGINA ATUAL (guard de rota)
    // ─────────────────────────────────────────────────────────────────
    function verificarPaginaAtual(perms) {
        if (!perms || perms.is_admin || perms.carregamento_falhou) return;

        var pageParam = window.AppRouter && typeof window.AppRouter.getPageFromURL === 'function'
            ? window.AppRouter.getPageFromURL('')
            : new URLSearchParams(window.location.search).get('page');
        if (!pageParam) return;

        if (!temAcesso(pageParam, perms)) {
            console.warn('[PermissoesModulos v2] Acesso negado à página:', pageParam);
            var url = '/dashboard?acesso_negado=' + encodeURIComponent(pageParam);
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
        if (!perms) return;
        document.querySelectorAll(SELETOR_ACOES_ESCRITA).forEach(function (el) { bloquearTemporariamente(el, false); });
        if (perms.carregamento_falhou) {
            document.querySelectorAll(SELETOR_ACOES_ESCRITA).forEach(function (el) { bloquearTemporariamente(el, true); });
            return;
        }
        if (perms.is_admin) {
            Object.values(SELETORES_POR_ACAO).forEach(function (seletor) { _aplicarVisibilidadeAcao(seletor, true); });
            return;
        }

        var page = window.AppRouter && typeof window.AppRouter.getPageFromURL === 'function'
            ? window.AppRouter.getPageFromURL('')
            : new URLSearchParams(window.location.search).get('page');
        if (!page) return;

        var chave = chavePermissaoDaPagina(page, perms);
        if (!chave) {
            Object.values(SELETORES_POR_ACAO).forEach(function (seletor) { _aplicarVisibilidadeAcao(seletor, false); });
            return;
        }
        var mod = perms.permissoes && perms.permissoes[chave];
        Object.entries(SELETORES_POR_ACAO).forEach(function ([acao, seletor]) {
            _aplicarVisibilidadeAcao(seletor, !!mod && !!mod['pode_' + acao]);
        });

        console.log('[PermissoesModulos v2] Restrições granulares aplicadas para:', page, mod || 'sem permissões');
    }

    function _aplicarVisibilidadeAcao(seletor, permitido) {
        document.querySelectorAll(seletor).forEach(function (el) {
            if (permitido) {
                if (el.dataset.pmPermOculto !== '1') return;
                el.style.display = el.dataset.pmPermDisplayOriginal || '';
                delete el.dataset.pmPermOculto;
                delete el.dataset.pmPermDisplayOriginal;
                return;
            }
            if (el.dataset.pmPermOculto !== '1') {
                el.dataset.pmPermDisplayOriginal = el.style.display || '';
                el.dataset.pmPermOculto = '1';
            }
            el.style.display = 'none';
        });
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

    function aplicarPoliticaAtual(perms) {
        filtrarMenu(perms);
        verificarPaginaAtual(perms);
        setTimeout(function () { aplicarRestricoesGranulares(perms); }, 1200);
    }

    function revalidarPermissoes() {
        carregarPermissoes(aplicarPoliticaAtual, true);
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
            aplicarPoliticaAtual(perms);
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
            revalidarPermissoes();
        });

        var ultimaValidacao = 0;
        function validarSessaoAtiva() {
            if (document.visibilityState === 'hidden' || Date.now() - ultimaValidacao < 30000) return;
            ultimaValidacao = Date.now();
            revalidarPermissoes();
        }
        document.addEventListener('visibilitychange', validarSessaoAtiva);
        window.addEventListener('focus', validarSessaoAtiva);
        window.setInterval(validarSessaoAtiva, 60000);
    }

    // ─────────────────────────────────────────────────────────────────
    // API PÚBLICA
    // ─────────────────────────────────────────────────────────────────
    global.PermissoesModulos = {
        init:               init,
        invalidarCache:     invalidarCache,
        carregarPermissoes: carregarPermissoes,
        revalidarPermissoes: revalidarPermissoes,

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
            var chave = chavePermissaoDaPagina(page, _cache);
            if (!chave) return null;
            return (_cache.permissoes && _cache.permissoes[chave]) || null;
        },

        /** Retorna true se o usuário logado é admin */
        isAdmin: function () {
            return !!_cache && !_cache.carregamento_falhou && !!_cache.is_admin;
        }
    };

    // Auto-inicializar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(window);
