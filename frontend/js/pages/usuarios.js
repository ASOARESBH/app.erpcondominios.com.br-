/**
 * Controlador da página de Usuários
 * v4.0 — Sistema completo de controle de acesso por módulo
 */

const state = {
    apiBase: '/api/',
    dom: {},
    usuarioEditando: null,
    // Módulos
    todosUsuarios: [],
    usuarioModuloAtual: null,
    permissoesEditadas: {},   // { chave: { estados: { acao: HERDAR|PERMITIR|NEGAR } } }
    permissoesOriginais: {},
    todosModulos: [],
    rbacGrupos: [],
    rbacCatalogo: [],
    rbacPodeConfigurar: false,
    usuarioGruposSelecionado: null,
    abaAtual: 'usuarios'
};
let permissoesAtualizadasHandler = null;

export function init() {
    console.log('[Usuarios v4.0] Inicializando...');
    bindDOM();
    bindEvents();
    _carregarDepartamentosSelect();
    carregarUsuarios();
    // Expor no window para onclick inline
    window.UsuariosPage = {
        mostrarTab:               mostrarTab,
        selecionarUsuarioModulos: selecionarUsuarioModulos,
        filtrarModulos:           filtrarModulos,
        habilitarTodos:           habilitarTodos,
        desabilitarTodos:         desabilitarTodos,
        resetarParaPerfil:        resetarParaPerfil,
        salvarPermissoes:         salvarPermissoes,
        abrirModulosUsuario:      abrirModulosUsuario,
        novoGrupoRbac:            novoGrupoRbac,
        editarGrupoRbac:          editarGrupoRbac,
        cancelarGrupoRbac:        cancelarGrupoRbac,
        salvarGrupoRbac:          salvarGrupoRbac,
        excluirGrupoRbac:         excluirGrupoRbac,
        carregarGruposUsuario:    carregarGruposUsuario,
        salvarGruposUsuario:      salvarGruposUsuario,
        carregarSegurancaRbac:     carregarSegurancaRbac,
        revogarSessaoRbac:         revogarSessaoRbac
    };
}

export function destroy() {
    console.log('[Usuarios] Destruindo...');
    if (permissoesAtualizadasHandler) {
        document.removeEventListener('permissoesAtualizadas', permissoesAtualizadasHandler);
        permissoesAtualizadasHandler = null;
    }
    window.UsuariosPage = null;
}

// ─────────────────────────────────────────────────────────────────────
// DOM & EVENTS
// ─────────────────────────────────────────────────────────────────────
function bindDOM() {
    state.dom = {
        alertContainer:  document.getElementById('alertContainer'),
        formUsuario:     document.getElementById('formUsuario'),
        btnLimpar:       document.getElementById('btnLimpar'),
        loading:         document.getElementById('loading'),
        tabelaUsuarios:  document.getElementById('tabelaUsuarios'),
        formTitle:       document.getElementById('formTitle'),
        btnSalvarTexto:  document.getElementById('btnSalvarTexto'),
        usuarioId:       document.getElementById('usuarioId'),
        nome:            document.getElementById('nome'),
        email:           document.getElementById('email'),
        senha:           document.getElementById('senha'),
        funcao:          document.getElementById('funcao'),
        departamento:    document.getElementById('departamento'),
        permissao:       document.getElementById('permissao'),
        sessao_inativa:  document.getElementById('sessao_inativa'),
        // Módulos
        painelUsuarios:  document.getElementById('painelUsuarios'),
        painelModulos:   document.getElementById('painelModulos'),
        tabUsuarios:     document.getElementById('tabUsuarios'),
        tabModulos:      document.getElementById('tabModulos'),
        tabGrupos:       document.getElementById('tabGrupos'),
        painelGrupos:    document.getElementById('painelGrupos'),
        painelSeguranca: document.getElementById('painelSeguranca'),
        tabSeguranca: document.getElementById('tabSeguranca'),
        tabelaSessoesRbac: document.getElementById('tabelaSessoesRbac'),
        tabelaAuditoriaRbac: document.getElementById('tabelaAuditoriaRbac'),
        tabelaGruposRbac: document.getElementById('tabelaGruposRbac'),
        grupoEditor: document.getElementById('grupoEditor'),
        grupoId: document.getElementById('grupoId'),
        grupoNome: document.getElementById('grupoNome'),
        grupoSlug: document.getElementById('grupoSlug'),
        grupoDescricao: document.getElementById('grupoDescricao'),
        grupoPermissoesCatalog: document.getElementById('grupoPermissoesCatalog'),
        grupoUsuarioSelect: document.getElementById('grupoUsuarioSelect'),
        grupoUsuarioVinculos: document.getElementById('grupoUsuarioVinculos'),
        modGruposContainer: document.getElementById('modGruposContainer'),
        modLoading:      document.getElementById('modLoading'),
        modHeader:       document.getElementById('modHeader'),
        modSelecionarUsuario: document.getElementById('modSelecionarUsuario'),
        modUsuarioNome:  document.getElementById('modUsuarioNome'),
        modPerfilBadge:  document.getElementById('modPerfilBadge'),
        modPerfilDesc:   document.getElementById('modPerfilDesc'),
        modSelectUsuario:document.getElementById('modSelectUsuario'),
        modAdminBanner:  document.getElementById('modAdminBanner'),
        modLegenda:      document.getElementById('modLegenda'),
        modBusca:        document.getElementById('modBusca'),
        modBadgeUsuario:  document.getElementById('modBadgeUsuario'),
        modPendingBadge: document.getElementById('modPendingBadge'),
        modAuditoriaBar: document.getElementById('modAuditoriaBar'),
        modAuditData:    document.getElementById('modAuditData'),
        modAuditAutor:   document.getElementById('modAuditAutor'),
        modAuditOrigem:  document.getElementById('modAuditOrigem'),
        modEstadoVazio:  document.getElementById('modEstadoVazio')
    };
}

function bindEvents() {
    if (state.dom.formUsuario) {
        state.dom.formUsuario.addEventListener('submit', salvarUsuario);
    }
    if (state.dom.btnLimpar) {
        state.dom.btnLimpar.addEventListener('click', limparFormulario);
    }
    if (state.dom.tabelaUsuarios) {
        state.dom.tabelaUsuarios.addEventListener('click', (e) => {
            const btnEdit    = e.target.closest('.btn-edit');
            const btnDelete  = e.target.closest('.btn-delete');
            const btnModulos = e.target.closest('.btn-modulos');
            const btnToggle  = e.target.closest('.btn-toggle-status');
            if (btnEdit)         editarUsuario(btnEdit.dataset.id);
            else if (btnDelete)  excluirUsuario(btnDelete.dataset.id, btnDelete.dataset.nome);
            else if (btnModulos) abrirModulosUsuario(btnModulos.dataset.id);
            else if (btnToggle)  toggleStatusUsuario(btnToggle.dataset.id, btnToggle.dataset.nome, btnToggle.dataset.ativo);
        });
    }
    state.dom.modGruposContainer?.addEventListener('change', (e) => {
        const select = e.target.closest('.rbac-individual-state');
        if (select) _alterarEstadoIndividual(select);
    });
    state.dom.modGruposContainer?.addEventListener('click', (e) => {
        const bulk = e.target.closest('.rbac-group-bulk');
        if (bulk) {
            e.stopPropagation();
            window.UsuariosPage?._toggleGrupo?.(bulk.dataset.rbacGrupo, bulk.dataset.rbacEstado);
            return;
        }
        const collapse = e.target.closest('.rbac-group-collapse');
        if (collapse) document.getElementById(collapse.dataset.target)?.classList.toggle('hidden');
    });
    state.dom.tabelaGruposRbac?.addEventListener('click', (e) => {
        const editar = e.target.closest('[data-rbac-grupo-editar]');
        const excluir = e.target.closest('[data-rbac-grupo-excluir]');
        if (editar) editarGrupoRbac(editar.dataset.rbacGrupoEditar);
        else if (excluir) excluirGrupoRbac(excluir.dataset.rbacGrupoExcluir, excluir.dataset.nome || 'grupo');
    });
    state.dom.grupoPermissoesCatalog?.addEventListener('change', (e) => {
        const select = e.target.closest('.rbac-grupo-state');
        if (select) _atualizarPreviaGrupo(select);
    });
    if (permissoesAtualizadasHandler) document.removeEventListener('permissoesAtualizadas', permissoesAtualizadasHandler);
    permissoesAtualizadasHandler = (e) => {
        const perms = e.detail;
        _definirPodeConfigurarRBAC(!!perms && !perms.carregamento_falhou &&
            (!!perms.is_admin || !!perms.permissoes?.usuarios?.pode_configurar));
    };
    document.addEventListener('permissoesAtualizadas', permissoesAtualizadasHandler);
}

// ─────────────────────────────────────────────────────────────────────
// ALERTAS
// ─────────────────────────────────────────────────────────────────────
function mostrarAlerta(mensagem, tipo = 'success') {
    if (!state.dom.alertContainer) return;
    const cor = {
        success: { bg:'#dcfce7', text:'#166534', border:'#22c55e', icon:'fa-check-circle' },
        error:   { bg:'#fee2e2', text:'#b91c1c', border:'#f87171', icon:'fa-exclamation-circle' },
        warning: { bg:'#fef3c7', text:'#92400e', border:'#fbbf24', icon:'fa-exclamation-triangle' },
        info:    { bg:'#dbeafe', text:'#1e40af', border:'#93c5fd', icon:'fa-info-circle' }
    }[tipo] || { bg:'#dcfce7', text:'#166534', border:'#22c55e', icon:'fa-check-circle' };
    state.dom.alertContainer.innerHTML = `
        <div style="background:${cor.bg};color:${cor.text};border:1px solid ${cor.border};
                    padding:1rem;border-radius:8px;margin-bottom:1.5rem;font-weight:500;
                    display:flex;align-items:center;gap:0.75rem;">
            <i class="fas ${cor.icon}"></i> ${mensagem}
        </div>`;
    setTimeout(() => { if (state.dom.alertContainer) state.dom.alertContainer.innerHTML = ''; }, 6000);
}

// ─────────────────────────────────────────────────────────────────────
// TABS
// ─────────────────────────────────────────────────────────────────────
function mostrarTab(aba) {
    state.abaAtual = aba;
    state.dom.tabUsuarios?.classList.toggle('active', aba === 'usuarios');
    state.dom.tabModulos?.classList.toggle('active',  aba === 'modulos');
    state.dom.tabGrupos?.classList.toggle('active', aba === 'grupos');
    state.dom.tabSeguranca?.classList.toggle('active', aba === 'seguranca');
    state.dom.painelUsuarios?.setAttribute('style', aba === 'usuarios' ? '' : 'display:none');
    state.dom.painelModulos?.classList.toggle('show', aba === 'modulos');
    state.dom.painelModulos?.setAttribute('style', aba === 'modulos' ? '' : 'display:none');
    state.dom.painelGrupos?.setAttribute('style', aba === 'grupos' ? '' : 'display:none');
    state.dom.painelSeguranca?.setAttribute('style', aba === 'seguranca' ? '' : 'display:none');
    if (aba === 'modulos') _popularSelectUsuarios();
    if (aba === 'grupos') carregarCentralGrupos();
    if (aba === 'seguranca') carregarSegurancaRbac();
}

function _popularSelectUsuarios() {
    const sel = state.dom.modSelectUsuario;
    if (!sel || state.todosUsuarios.length === 0) return;
    const valorAtual = sel.value;
    const opcoes = '<option value="">— Selecione um usuário —</option>' + state.todosUsuarios.map(u => `<option value="${u.id}" ${u.id == valorAtual ? 'selected' : ''}>${_escHtml(u.nome)} (${_textoPermissao(u.permissao)})</option>`).join('');
    sel.innerHTML = opcoes;
    if (state.dom.grupoUsuarioSelect) {
        const atualGrupo = state.dom.grupoUsuarioSelect.value;
        state.dom.grupoUsuarioSelect.innerHTML = '<option value="">— Selecione um usuário —</option>' + state.todosUsuarios.map(u => `<option value="${u.id}" ${u.id == atualGrupo ? 'selected' : ''}>${_escHtml(u.nome)}</option>`).join('');
    }
}

// ─────────────────────────────────────────────────────────────────────
// DEPARTAMENTOS — carrega do cadastro central
// ─────────────────────────────────────────────────────────────────────
function _carregarDepartamentosSelect() {
    const sel = document.getElementById('departamento');
    if (!sel) return;
    fetch('/api/api_departamentos.php?acao=listar_nomes', { credentials: 'include' })
        .then(r => r.json())
        .then(json => {
            if (!json.sucesso) return;
            const atual = sel.value;
            const nomes = json.dados || [];
            sel.innerHTML = '<option value="">— Selecione —</option>' +
                nomes.map(n => `<option value="${n}">${n}</option>`).join('');
            if (atual) sel.value = atual;
        })
        .catch(() => { /* mantém select vazio, não bloqueia o módulo */ });
}

// ─────────────────────────────────────────────────────────────────────
// CARREGAR USUÁRIOS
// ─────────────────────────────────────────────────────────────────────
function carregarUsuarios() {
    if (state.dom.loading) state.dom.loading.style.display = 'block';
    console.log('[Usuarios] Carregando lista...');
    fetch(`${state.apiBase}api_usuarios.php`, { credentials:'include' })
        .then(r => {
            console.log('[Usuarios] HTTP status:', r.status);
            if (r.status === 401) {
                mostrarAlerta('Sessão expirada. Redirecionando para o login...', 'error');
                setTimeout(() => { window.location.href = '/frontend/login.html'; }, 2000);
                throw new Error('SESSION_EXPIRED');
            }
            if (r.status === 403) {
                mostrarAlerta('Permissão insuficiente. Esta página requer perfil Gerente ou superior.', 'error');
                throw new Error('PERMISSION_DENIED');
            }
            return r.json();
        })
        .then(data => {
            if (state.dom.loading) state.dom.loading.style.display = 'none';
            console.log('[Usuarios] Resposta:', data.sucesso, '| Qtd:', data.dados?.length ?? 0);
            if (data.sucesso) {
                state.todosUsuarios = data.dados || [];
                renderizarTabela(state.todosUsuarios);
                _popularSelectUsuarios();
            } else {
                mostrarAlerta('Erro ao carregar usuários: ' + data.mensagem, 'error');
            }
        })
        .catch(err => {
            if (state.dom.loading) state.dom.loading.style.display = 'none';
            if (err.message !== 'SESSION_EXPIRED' && err.message !== 'PERMISSION_DENIED') {
                console.error('[Usuarios] Erro:', err);
                mostrarAlerta('Erro de conexão ao carregar usuários.', 'error');
            }
        });
}

// ─────────────────────────────────────────────────────────────────────
// RENDERIZAR TABELA
// ─────────────────────────────────────────────────────────────────────
function renderizarTabela(usuarios) {
    if (!state.dom.tabelaUsuarios) return;
    if (!usuarios || usuarios.length === 0) {
        state.dom.tabelaUsuarios.innerHTML = '<tr><td colspan="9" style="text-align:center;">Nenhum usuário cadastrado</td></tr>';
        return;
    }
    state.dom.tabelaUsuarios.innerHTML = usuarios.map(u => {
        const inativa = u.sessao_inativa == 1;
        const badgeSessao = inativa
            ? `<span title="Sessão Inativa: nunca expira" style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap;"><i class="fas fa-infinity"></i> Inativa</span>`
            : `<span title="Sessão normal: expira em 8h" style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#64748b,#475569);color:#fff;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap;"><i class="fas fa-clock"></i> 8h</span>`;
        return `
        <tr>
            <td>${u.id}</td>
            <td><strong>${u.nome}</strong></td>
            <td style="font-size:0.85rem;">${u.email}</td>
            <td>${u.funcao}</td>
            <td>${u.departamento || '—'}</td>
            <td><span class="badge badge-${_badgePermissao(u.permissao)}">${_textoPermissao(u.permissao)}</span></td>
            <td><span class="badge badge-${u.ativo == 1 ? 'success' : 'danger'}">${u.ativo == 1 ? 'Ativo' : 'Inativo'}</span></td>
            <td style="text-align:center;">${badgeSessao}</td>
            <td style="white-space:nowrap;">
                <button class="btn-edit" data-id="${u.id}"
                    style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:0.35rem 0.75rem;font-size:0.82rem;color:white;border:none;border-radius:4px;cursor:pointer;margin-right:4px;">
                    <i class="fas fa-edit"></i> Editar
                </button>
                <button class="btn-modulos" data-id="${u.id}"
                    title="Configurar módulos de acesso"
                    style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:0.35rem 0.75rem;font-size:0.82rem;color:white;border:none;border-radius:4px;cursor:pointer;margin-right:4px;">
                    <i class="fas fa-th-large"></i> Módulos
                </button>
                ${u.id != 1 ? `
                <button class="btn-toggle-status" data-id="${u.id}" data-nome="${u.nome}" data-ativo="${u.ativo}"
                    title="${u.ativo == 1 ? 'Inativar usuário — bloquear acesso ao sistema' : 'Ativar usuário — restaurar acesso ao sistema'}"
                    style="background:${u.ativo == 1 ? 'linear-gradient(135deg,#f97316,#ea580c)' : 'linear-gradient(135deg,#16a34a,#15803d)'};padding:0.35rem 0.75rem;font-size:0.82rem;color:white;border:none;border-radius:4px;cursor:pointer;margin-right:4px;">
                    <i class="fas fa-${u.ativo == 1 ? 'ban' : 'check-circle'}"></i> ${u.ativo == 1 ? 'Inativar' : 'Ativar'}
                </button>
                <button class="btn-delete" data-id="${u.id}" data-nome="${u.nome}"
                    style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:0.35rem 0.75rem;font-size:0.82rem;color:white;border:none;border-radius:4px;cursor:pointer;">
                    <i class="fas fa-trash"></i>
                </button>` : ''}
            </td>
        </tr>`;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────
// MÓDULOS — Abrir configuração de um usuário
// ─────────────────────────────────────────────────────────────────────
function abrirModulosUsuario(userId) {
    mostrarTab('modulos');
    setTimeout(() => {
        const sel = state.dom.modSelectUsuario;
        if (sel) { sel.value = userId; }
        selecionarUsuarioModulos(userId);
    }, 100);
}

function selecionarUsuarioModulos(userId) {
    _definirPodeConfigurarRBAC(false);
    if (!userId) {
        // Sem usuário: mostrar estado vazio
        if (state.dom.modEstadoVazio)   state.dom.modEstadoVazio.style.display = 'block';
        if (state.dom.modAdminBanner)   state.dom.modAdminBanner.classList.remove('show');
        if (state.dom.modHeader)        state.dom.modHeader.style.display = 'none';
        if (state.dom.modLegenda)       state.dom.modLegenda.style.display = 'none';
        if (state.dom.modBusca)         state.dom.modBusca.style.display = 'none';
        if (state.dom.modAuditoriaBar)  state.dom.modAuditoriaBar.classList.remove('show');
        if (state.dom.modGruposContainer) state.dom.modGruposContainer.innerHTML = '';
        if (state.dom.modBadgeUsuario)  state.dom.modBadgeUsuario.style.display = 'none';
        return;
    }
    const uid = parseInt(userId);
    const usuario = state.todosUsuarios.find(u => u.id == uid);
    if (!usuario) return;

    state.usuarioModuloAtual = usuario;
    state.permissoesEditadas = {};
    state.alteracoesPendentes = 0;
    _atualizarBadgePendente(0);

    // Ocultar estado vazio
    if (state.dom.modEstadoVazio) state.dom.modEstadoVazio.style.display = 'none';

    // Atualizar badge na tab
    if (state.dom.modBadgeUsuario) {
        state.dom.modBadgeUsuario.textContent = usuario.nome.split(' ')[0];
        state.dom.modBadgeUsuario.style.display = 'inline';
    }
    if (state.dom.modUsuarioNome) state.dom.modUsuarioNome.textContent = usuario.nome;
    if (state.dom.modPerfilBadge) {
        state.dom.modPerfilBadge.textContent = _textoPermissao(usuario.permissao);
        state.dom.modPerfilBadge.className = `mod-perfil-badge mod-perfil-${usuario.permissao}`;
    }
    if (state.dom.modPerfilDesc) {
        const descs = {
            admin:        'Acesso total a todos os módulos — sem restrições',
            gerente:      'Acesso a módulos operacionais e financeiros por padrão',
            operador:     'Acesso a módulos operacionais básicos por padrão',
            visualizador: 'Acesso somente leitura a módulos básicos'
        };
        state.dom.modPerfilDesc.textContent = descs[usuario.permissao] || '';
    }

    // Admin: mostrar banner especial
    const isAdmin = usuario.permissao === 'admin';
    if (state.dom.modAdminBanner) state.dom.modAdminBanner.classList.toggle('show', isAdmin);
    if (state.dom.modHeader)      state.dom.modHeader.style.display = isAdmin ? 'none' : 'flex';
    if (state.dom.modLegenda)     state.dom.modLegenda.style.display   = isAdmin ? 'none' : 'flex';
    if (state.dom.modBusca)       state.dom.modBusca.style.display     = isAdmin ? 'none' : 'block';
    if (state.dom.modAuditoriaBar) state.dom.modAuditoriaBar.classList.remove('show');

    if (isAdmin) {
        if (state.dom.modGruposContainer) state.dom.modGruposContainer.innerHTML = '';
        return;
    }

    // Carregar permissões do usuário
    if (state.dom.modLoading) state.dom.modLoading.style.display = 'block';
    if (state.dom.modGruposContainer) state.dom.modGruposContainer.innerHTML = '';

    fetch(`${state.apiBase}api_permissoes_modulos.php?acao=permissoes_usuario&id=${uid}`, { credentials:'include' })
        .then(r => r.json())
        .then(data => {
            if (state.dom.modLoading) state.dom.modLoading.style.display = 'none';
            if (!data.sucesso) { mostrarAlerta('Erro ao carregar módulos: ' + data.mensagem, 'error'); return; }
            _definirPodeConfigurarRBAC(!!data.dados.pode_configurar);
            const permissoes = data.dados.permissoes || {};
            state.todosModulos = Object.entries(permissoes).map(([chave, m]) => {
                const meta = m.modulo || m;
                return { ...m, modulo_chave: meta.chave || m.modulo_chave || chave, nome: meta.nome || m.nome || chave, grupo: meta.grupo || m.grupo || 'Sistema', icone: meta.icone || m.icone || 'fas fa-shield-alt', descricao: meta.descricao || m.descricao || '', permissao_minima: meta.perfil_compatibilidade || m.permissao_minima || 'visualizador', perfil_permite: true };
            });
            state.permissoesEditadas = {};
            state.permissoesOriginais = {};
            state.todosModulos.forEach(m => {
                const estados = {};
                (m.modulo?.acoes || []).forEach(acao => {
                    estados[acao] = m.estados_individuais?.[acao] || 'HERDAR';
                });
                state.permissoesEditadas[m.modulo_chave] = { estados };
                state.permissoesOriginais[m.modulo_chave] = { ...estados };
            });
            // Exibir rastreabilidade de auditoria
            _exibirAuditoria(permissoes);
            renderizarGruposModulos(state.todosModulos, usuario);
        })
        .catch(err => {
            if (state.dom.modLoading) state.dom.modLoading.style.display = 'none';
            console.error('[Usuarios] Erro ao carregar módulos:', err);
            mostrarAlerta('Erro de conexão ao carregar módulos.', 'error');
        });
}

function _definirPodeConfigurarRBAC(permitido) {
    state.rbacPodeConfigurar = !!permitido;
    state.dom.modHeader?.querySelectorAll('button').forEach(btn => { btn.disabled = !state.rbacPodeConfigurar; });
    state.dom.painelGrupos?.querySelectorAll('button:not(.mod-btn-outline)').forEach(btn => { btn.disabled = !state.rbacPodeConfigurar; });
    state.dom.grupoEditor?.querySelectorAll('input,textarea,select,button:not(.mod-btn-outline)').forEach(el => { el.disabled = !state.rbacPodeConfigurar; });
    state.dom.modGruposContainer?.querySelectorAll('.rbac-individual-state').forEach(select => { select.disabled = !state.rbacPodeConfigurar; });
}

// ─────────────────────────────────────────────────────────────────────
// RENDERIZAR GRUPOS DE MÓDULOS
// ─────────────────────────────────────────────────────────────────────
function renderizarGruposModulos(modulos, usuario) {
    const container = state.dom.modGruposContainer;
    if (!container) return;

    // Agrupar
    const grupos = {};
    modulos.forEach(m => {
        if (!grupos[m.grupo]) grupos[m.grupo] = [];
        grupos[m.grupo].push(m);
    });

    const iconeGrupo = {
        'Core':          'fas fa-home',
        'Condomínios':   'fas fa-building',
        'Acesso':        'fas fa-door-open',
        'Financeiro':    'fas fa-money-bill-wave',
        'Manutenção':    'fas fa-tools',
        'Administrativo':'fas fa-briefcase',
        'RH':            'fas fa-id-card',
        'CRM':           'fas fa-handshake',
        'Marketplace':   'fas fa-store',
        'Sistema':       'fas fa-server'
    };

    container.innerHTML = Object.entries(grupos).map(([grupo, mods]) => {
        const habilitados = mods.filter(m => _estadoPrevisto(m, 'visualizar') === 'PERMITIR' || _estadoPrevisto(m, 'visualizar') === 'ADMIN_TOTAL').length;
        const total = mods.length;
        const grupoId = 'grupo_' + grupo.replace(/[^a-z0-9]/gi, '_');
        const grupoSeguro = _escHtml(grupo);
        return `
        <div class="mod-grupo" data-grupo="${grupoSeguro}">
            <div class="mod-grupo-header">
                <div class="mod-grupo-titulo">
                    <i class="${iconeGrupo[grupo] || 'fas fa-folder'}" style="color:#2563eb;"></i>
                    ${grupoSeguro}
                    <span class="mod-grupo-badge">${habilitados}/${total}</span>
                </div>
                <div class="mod-grupo-toggle">
                    <button type="button" class="mod-grupo-btn rbac-group-bulk" data-rbac-grupo="${grupoSeguro}" data-rbac-estado="PERMITIR">
                        <i class="fas fa-check"></i> Permitir ações disponíveis
                    </button>
                    <button type="button" class="mod-grupo-btn rbac-group-bulk" data-rbac-grupo="${grupoSeguro}" data-rbac-estado="NEGAR">
                        <i class="fas fa-times"></i> Negar ações
                    </button>
                    <button type="button" class="mod-grupo-btn rbac-group-collapse" data-target="${grupoId}" aria-label="Expandir ou recolher ${grupoSeguro}"><i class="fas fa-chevron-down" style="color:#94a3b8;font-size:0.8rem;"></i></button>
                </div>
            </div>
            <div class="mod-grid" id="${grupoId}">
                ${mods.map(m => _renderCardModulo(m, usuario)).join('')}
            </div>
        </div>`;
    }).join('');

    // Expor _toggleGrupo
    window.UsuariosPage._toggleGrupo = function(grupo, estado) {
        const mods = state.todosModulos.filter(m => m.grupo === grupo);
        mods.forEach(m => {
            const estados = state.permissoesEditadas[m.modulo_chave]?.estados || {};
            (m.modulo?.acoes || []).forEach(acao => {
                if (estado === 'PERMITIR' && !m.acoes_concediveis?.[acao]) return;
                estados[acao] = estado;
            });
        });
        state.dom.modGruposContainer?.querySelectorAll('.rbac-individual-state').forEach(select => {
            if (mods.some(m => m.modulo_chave === select.dataset.modulo)) select.value = state.permissoesEditadas[select.dataset.modulo]?.estados?.[select.dataset.acao] || 'HERDAR';
        });
        _atualizarContadorGrupo(grupo);
        _recalcularPendentes();
    };
}

function _renderCardModulo(m) {
    const modulo = m.modulo_chave;
    const grupos = state.permissoesEditadas[modulo]?.estados || {};
    const labels = {visualizar:'Visualizar',criar:'Criar',editar:'Editar',excluir:'Excluir',exportar:'Exportar',imprimir:'Imprimir',importar:'Importar',aprovar:'Aprovar',configurar:'Configurar',executar:'Executar',bloquear:'Bloquear',desbloquear:'Desbloquear'};
    const acoes = (m.modulo?.acoes || []).map(acao => {
        const estado = grupos[acao] || 'HERDAR';
        const concedivel = !!m.acoes_concediveis?.[acao];
        const nomeAcao = _escHtml(labels[acao] || acao);
        const origem = _escHtml(_origemPrevista(m, acao));
        const efetiva = _escHtml(_estadoPrevisto(m, acao));
        return `<div class="rbac-permission-row">
            <label for="ind_${_escHtml(modulo)}_${_escHtml(acao)}">${nomeAcao}</label>
            <select class="rbac-individual-state" id="ind_${_escHtml(modulo)}_${_escHtml(acao)}" data-modulo="${_escHtml(modulo)}" data-acao="${_escHtml(acao)}" ${state.rbacPodeConfigurar?'':'disabled'}>
                <option value="HERDAR" ${estado==='HERDAR'?'selected':''}>Herdar</option>
                <option value="PERMITIR" ${estado==='PERMITIR'?'selected':''} ${concedivel?'':'disabled'}>Permitir</option>
                <option value="NEGAR" ${estado==='NEGAR'?'selected':''}>Negar</option>
            </select>
        <small class="rbac-effective-preview" data-preview-modulo="${_escHtml(modulo)}" data-preview-acao="${_escHtml(acao)}">Efetiva: ${efetiva.replace(/_/g,' ')} · ${origem}</small>
        </div>`;
    }).join('');
    const grants = (m.modulo?.acoes || []).filter(acao => m.acoes_concediveis?.[acao]).length;
    return `<div class="mod-card" id="card_${_escHtml(modulo)}" data-chave="${_escHtml(modulo)}" data-grupo="${_escHtml(m.grupo)}">
        <div class="mod-card-top"><div class="mod-card-icon"><i class="${_escHtml(m.icone || 'fas fa-shield-alt')}"></i></div><div class="mod-card-info">
            <div class="mod-card-nome">${_escHtml(m.nome)}</div><div class="mod-card-desc">${_escHtml(m.descricao || '')}</div>
            <span class="mod-min-badge">${_escHtml(m.permissao_minima || 'RBAC')} · ${grants} ação${grants===1?'':'ões'} concedível${grants===1?'':'s'} por você</span>
        </div></div>
        <div class="rbac-permission-matrix">${acoes || '<span style="color:#64748b;">Nenhuma ação cadastrada.</span>'}</div>
    </div>`;
}

function _estadoPrevisto(modulo, acao) {
    const estado = state.permissoesEditadas[modulo.modulo_chave]?.estados?.[acao] || 'HERDAR';
    if (estado !== 'HERDAR') return estado;
    return modulo.herdadas?.[acao] || 'SEM_ACESSO';
}

function _origemPrevista(modulo, acao) {
    const estado = state.permissoesEditadas[modulo.modulo_chave]?.estados?.[acao] || 'HERDAR';
    if (estado !== 'HERDAR') return 'Exceção individual';
    const origens = modulo.origens_herdadas?.[acao] || [];
    return origens.length ? origens.join(', ') : 'Sem concessão herdada';
}

function _alterarEstadoIndividual(select) {
    const { modulo, acao } = select.dataset;
    const m = state.todosModulos.find(item => item.modulo_chave === modulo);
    if (!m || !state.permissoesEditadas[modulo]) return;
    state.permissoesEditadas[modulo].estados[acao] = select.value;
    const preview = [...(state.dom.modGruposContainer?.querySelectorAll('.rbac-effective-preview') || [])]
        .find(el => el.dataset.previewModulo === modulo && el.dataset.previewAcao === acao);
    if (preview) preview.textContent = `Efetiva: ${_estadoPrevisto(m, acao).replace(/_/g,' ')} · ${_origemPrevista(m, acao)}`;
    _atualizarContadorGrupo(m.grupo);
    _recalcularPendentes();
}

function _recalcularPendentes() {
    let count = 0;
    Object.entries(state.permissoesEditadas).forEach(([modulo, perm]) => {
        Object.entries(perm.estados || {}).forEach(([acao, estado]) => {
            if ((state.permissoesOriginais[modulo]?.[acao] || 'HERDAR') !== estado) count++;
        });
    });
    state.alteracoesPendentes = count;
    _atualizarBadgePendente(count);
}

function _atualizarContadorGrupo(grupo) {
    const mods = state.todosModulos.filter(m => m.grupo === grupo);
    const habilitados = mods.filter(m => ['PERMITIR','ADMIN_TOTAL'].includes(_estadoPrevisto(m, 'visualizar'))).length;
    const badge = [...document.querySelectorAll('.mod-grupo')].find(el => el.dataset.grupo === grupo)?.querySelector('.mod-grupo-badge');
    if (badge) badge.textContent = `${habilitados}/${mods.length}`;
}

// ─────────────────────────────────────────────────────────────────────
// AÇÕES GLOBAIS
// ─────────────────────────────────────────────────────────────────────
function habilitarTodos() {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar permissões.', 'error'); return; }
    state.todosModulos.forEach(m => {
        const estados = state.permissoesEditadas[m.modulo_chave]?.estados || {};
        (m.modulo?.acoes || []).forEach(acao => {
            if (m.acoes_concediveis?.[acao]) estados[acao] = 'PERMITIR';
        });
    });
    _sincronizarSelectsIndividuais();
    _atualizarTodosContadoresGrupo();
    _recalcularPendentes();
    mostrarAlerta('Ações que você possui foram marcadas como Permitir. Revise a prévia e salve para confirmar.', 'info');
}

function desabilitarTodos() {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar permissões.', 'error'); return; }
    state.todosModulos.forEach(m => {
        const estados = state.permissoesEditadas[m.modulo_chave]?.estados || {};
        (m.modulo?.acoes || []).forEach(acao => { estados[acao] = 'NEGAR'; });
    });
    _sincronizarSelectsIndividuais();
    _atualizarTodosContadoresGrupo();
    _recalcularPendentes();
    mostrarAlerta('Ações de todos os módulos foram marcadas como Negar. Revise a prévia e salve para confirmar.', 'warning');
}

function _sincronizarSelectsIndividuais() {
    state.dom.modGruposContainer?.querySelectorAll('.rbac-individual-state').forEach(select => {
        select.value = state.permissoesEditadas[select.dataset.modulo]?.estados?.[select.dataset.acao] || 'HERDAR';
        const modulo = state.todosModulos.find(item => item.modulo_chave === select.dataset.modulo);
        const preview = select.closest('.rbac-permission-row')?.querySelector('.rbac-effective-preview');
        if (modulo && preview) preview.textContent = `Efetiva: ${_estadoPrevisto(modulo, select.dataset.acao).replace(/_/g,' ')} · ${_origemPrevista(modulo, select.dataset.acao)}`;
    });
}

function _atualizarTodosContadoresGrupo() {
    [...new Set(state.todosModulos.map(m => m.grupo))].forEach(_atualizarContadorGrupo);
}

function resetarParaPerfil() {
    if (!state.usuarioModuloAtual) return;
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar permissões.', 'error'); return; }
    if (!confirm(`Resetar todas as permissões de "${state.usuarioModuloAtual.nome}" para o padrão do perfil "${_textoPermissao(state.usuarioModuloAtual.permissao)}"?`)) return;
    fetch(`${state.apiBase}api_permissoes_modulos.php`, {
        method: 'POST',
        credentials:'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'resetar_para_perfil', usuario_id: state.usuarioModuloAtual.id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta('Permissões resetadas para o padrão do perfil.', 'success');
            window.PermissoesModulos?.revalidarPermissoes?.();
            selecionarUsuarioModulos(state.usuarioModuloAtual.id);
        } else {
            mostrarAlerta('Erro: ' + data.mensagem, 'error');
        }
    });
}

function salvarPermissoes() {
    if (!state.usuarioModuloAtual) return;
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar permissões.', 'error'); return; }
    const alteradas = {};
    Object.entries(state.permissoesEditadas).forEach(([modulo, perm]) => {
        const estados = {};
        Object.entries(perm.estados || {}).forEach(([acao, estado]) => {
            if ((state.permissoesOriginais[modulo]?.[acao] || 'HERDAR') !== estado) estados[acao] = estado;
        });
        if (Object.keys(estados).length) alteradas[modulo] = { estados };
    });
    if (!Object.keys(alteradas).length) { mostrarAlerta('Não há alterações pendentes para salvar.', 'info'); return; }
    const payload = {
        acao: 'salvar_permissoes',
        usuario_id: state.usuarioModuloAtual.id,
        permissoes: alteradas
    };
    fetch(`${state.apiBase}api_permissoes_modulos.php`, {
        method: 'POST',
        credentials:'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta(`✓ Permissões de "${state.usuarioModuloAtual.nome}" salvas com sucesso!`, 'success');
            state.alteracoesPendentes = 0;
            _atualizarBadgePendente(0);
            // Atualizar rastreabilidade
            if (state.dom.modAuditData)   state.dom.modAuditData.textContent   = new Date().toLocaleString('pt-BR');
            if (state.dom.modAuditAutor)  state.dom.modAuditAutor.textContent  = 'Você (agora)';
            const total = state.todosModulos.length;
            const hab   = state.todosModulos.filter(m => ['PERMITIR','ADMIN_TOTAL'].includes(_estadoPrevisto(m,'visualizar'))).length;
            if (state.dom.modAuditOrigem) state.dom.modAuditOrigem.textContent = `${hab}/${total} módulos habilitados`;
            console.log('[Usuarios] Permissões salvas; recarregando estado efetivo do usuário', state.usuarioModuloAtual.id);
            selecionarUsuarioModulos(state.usuarioModuloAtual.id);
        } else {
            mostrarAlerta('Erro ao salvar: ' + data.mensagem, 'error');
        }
    })
    .catch(err => {
        console.error('[Usuarios] Erro ao salvar permissões:', err);
        mostrarAlerta('Erro de conexão ao salvar permissões.', 'error');
    });
}

// ─────────────────────────────────────────────────────────────────────
// FILTRO DE BUSCA DE MÓDULOS
// ─────────────────────────────────────────────────────────────────────
function filtrarModulos(termo) {
    const t = termo.toLowerCase().trim();
    document.querySelectorAll('.mod-card').forEach(card => {
        const nome  = card.querySelector('.mod-card-nome')?.textContent.toLowerCase() || '';
        const grupo = card.dataset.grupo?.toLowerCase() || '';
        const desc  = card.querySelector('.mod-card-desc')?.textContent.toLowerCase() || '';
        card.style.display = (!t || nome.includes(t) || grupo.includes(t) || desc.includes(t)) ? '' : 'none';
    });
    // Esconder grupos vazios
    document.querySelectorAll('.mod-grupo').forEach(g => {
        const visiveis = g.querySelectorAll('.mod-card:not([style*="display: none"])').length;
        g.style.display = visiveis > 0 ? '' : 'none';
    });
}

// ─────────────────────────────────────────────────────────────────────
// CRUD DE USUÁRIOS
// ─────────────────────────────────────────────────────────────────────
function salvarUsuario(e) {
    e.preventDefault();
    const dados = {
        nome:          state.dom.nome.value,
        email:         state.dom.email.value,
        senha:         state.dom.senha.value,
        funcao:        state.dom.funcao.value,
        departamento:  state.dom.departamento.value,
        permissao:     state.dom.permissao.value,
        sessao_inativa: state.dom.sessao_inativa?.checked ? 1 : 0,
        ativo: 1
    };
    const metodo = state.usuarioEditando ? 'PUT' : 'POST';
    if (state.usuarioEditando) {
        dados.id = state.usuarioEditando;
        if (dados.senha === '********') delete dados.senha;
    }
    fetch(`${state.apiBase}api_usuarios.php`, {
        method:  metodo,
        credentials:'include',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(dados)
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta(data.mensagem, 'success');
            limparFormulario();
            carregarUsuarios();
        } else {
            mostrarAlerta(data.mensagem, 'error');
        }
    })
    .catch(err => {
        console.error('[Usuarios] Erro ao salvar:', err);
        mostrarAlerta('Erro ao salvar usuário', 'error');
    });
}

function editarUsuario(id) {
    fetch(`${state.apiBase}api_usuarios.php?id=${id}`, { credentials:'include' })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            const u = data.dados;
            state.dom.usuarioId.value    = u.id;
            state.dom.nome.value         = u.nome;
            state.dom.email.value        = u.email;
            state.dom.senha.value        = '********';
            state.dom.funcao.value       = u.funcao;
            state.dom.departamento.value = u.departamento || '';
            state.dom.permissao.value    = u.permissao;
            if (state.dom.sessao_inativa) state.dom.sessao_inativa.checked = u.sessao_inativa == 1;
            state.usuarioEditando = id;
            if (state.dom.formTitle)      state.dom.formTitle.textContent     = 'Editar Usuário';
            if (state.dom.btnSalvarTexto) state.dom.btnSalvarTexto.textContent = 'Atualizar Usuário';
            // Voltar para aba de lista
            mostrarTab('usuarios');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            mostrarAlerta('Erro ao carregar usuário', 'error');
        }
    });
}

function excluirUsuario(id, nome) {
    if (!confirm(`Deseja realmente excluir o usuário "${nome}"?`)) return;
    fetch(`${state.apiBase}api_usuarios.php`, {
        method:  'DELETE',
        credentials:'include',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) { mostrarAlerta(data.mensagem, 'success'); carregarUsuarios(); }
        else mostrarAlerta(data.mensagem, 'error');
    })
    .catch(err => {
        console.error('[Usuarios] Erro ao excluir:', err);
        mostrarAlerta('Erro ao excluir usuário', 'error');
    });
}

function limparFormulario() {
    if (state.dom.formUsuario)    state.dom.formUsuario.reset();
    if (state.dom.usuarioId)      state.dom.usuarioId.value = '';
    if (state.dom.sessao_inativa) state.dom.sessao_inativa.checked = false;
    state.usuarioEditando = null;
    if (state.dom.formTitle)      state.dom.formTitle.textContent     = 'Novo Usuário';
    if (state.dom.btnSalvarTexto) state.dom.btnSalvarTexto.textContent = 'Salvar Usuário';
}

// ─────────────────────────────────────────────────────────────────────
// TOGGLE STATUS — ATIVAR / INATIVAR USUÁRIO
// ─────────────────────────────────────────────────────────────────────
function toggleStatusUsuario(id, nome, ativoAtual) {
    const estaAtivo  = ativoAtual == 1 || ativoAtual === '1';
    const novaAcao   = estaAtivo ? 'inativar' : 'ativar';
    const verbo      = estaAtivo ? 'inativar' : 'ativar';
    const aviso      = estaAtivo
        ? `Deseja INATIVAR o usuário "${nome}"?\n\nO acesso ao sistema será bloqueado imediatamente.\nTodo o histórico e dados do usuário serão preservados.`
        : `Deseja ATIVAR o usuário "${nome}"?\n\nO acesso ao sistema será restaurado.`;

    if (!confirm(aviso)) return;

    // Feedback visual imediato — desabilitar botão
    const btnEl = document.querySelector(`.btn-toggle-status[data-id="${id}"]`);
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Aguarde...`;
    }

    fetch(`${state.apiBase}api_usuarios.php`, {
        method:  'PATCH',
        credentials:'include',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ id: parseInt(id), acao: novaAcao })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta(data.mensagem, estaAtivo ? 'warning' : 'success');
            carregarUsuarios(); // Recarregar tabela para refletir novo status
        } else {
            mostrarAlerta('Erro: ' + data.mensagem, 'error');
            if (btnEl) { btnEl.disabled = false; }
            carregarUsuarios();
        }
    })
    .catch(err => {
        console.error('[Usuarios] Erro ao alterar status:', err);
        mostrarAlerta('Erro de conexão ao alterar status do usuário.', 'error');
        if (btnEl) { btnEl.disabled = false; }
    });
}

// ─────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────
function _badgePermissao(p) {
    return { admin:'danger', gerente:'warning', operador:'primary', visualizador:'success' }[p] || 'primary';
}
function _textoPermissao(p) {
    return { admin:'Administrador', gerente:'Gerente', operador:'Operador', visualizador:'Visualizador' }[p] || p;
}

// ─────────────────────────────────────────────────────────────────────
// RASTREABILIDADE / AUDITORIA
// ─────────────────────────────────────────────────────────────────────
function _exibirAuditoria(permissoes) {
    const d = state.dom;
    if (!d.modAuditoriaBar) return;

    // O RBAC moderno informa a origem por ação, enquanto a camada legada
    // informa p.origem no módulo. Ambos representam uma exceção persistida.
    const comRegistro = Object.values(permissoes).filter(p =>
        p.origem === 'individual' || Object.values(p.origens || {}).some(origens =>
            Array.isArray(origens) && origens.some(origem => origem.origem === 'individual')
        )
    );
    const totalModulos = Object.values(permissoes).length;
    const habilitados  = Object.values(permissoes).filter(p => p.pode_acessar).length;

    if (comRegistro.length > 0) {
        // Buscar a data mais recente de atualização
        d.modAuditoriaBar.classList.add('show');
        if (d.modAuditData)   d.modAuditData.textContent   = 'Permissões personalizadas';
        if (d.modAuditAutor)  d.modAuditAutor.textContent  = 'Configurado manualmente';
        if (d.modAuditOrigem) d.modAuditOrigem.textContent = `${habilitados}/${totalModulos} módulos habilitados`;
    } else {
        d.modAuditoriaBar.classList.add('show');
        if (d.modAuditData)   d.modAuditData.textContent   = 'Padrão do perfil';
        if (d.modAuditAutor)  d.modAuditAutor.textContent  = 'Automático';
        if (d.modAuditOrigem) d.modAuditOrigem.textContent = `${habilitados}/${totalModulos} módulos habilitados`;
    }
}

function _atualizarBadgePendente(count) {
    const badge = state.dom.modPendingBadge;
    if (!badge) return;
    if (count > 0) {
        badge.textContent = `● ${count} alteração${count > 1 ? 'ões' : ''} não salva${count > 1 ? 's' : ''}`;
        badge.classList.add('show');
    } else {
        badge.classList.remove('show');
    }
}

// ─────────────────────────────────────────────────────────────────────
// CENTRAL RBAC — GRUPOS, VÍNCULOS E CATÁLOGO DE PERMISSÕES
// ─────────────────────────────────────────────────────────────────────
function _rbacEsc(valor) {
    return String(valor ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

async function _rbacRequest(acao, options = {}) {
    const method = options.method || 'GET';
    const url = method === 'GET'
        ? `${state.apiBase}api_rbac.php?acao=${encodeURIComponent(acao)}${options.query || ''}`
        : `${state.apiBase}api_rbac.php`;
    const response = await fetch(url, method === 'GET' ? { credentials:'include' } : {
        method, credentials:'include', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ acao, ...(options.body || {}) })
    });
    const texto = await response.text();
    let data;
    try { data = JSON.parse(texto); } catch (_) { throw new Error('A Central RBAC retornou uma resposta inválida.'); }
    if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Não foi possível concluir a operação RBAC.');
    return data.dados || {};
}

async function carregarCentralGrupos() {
    _definirPodeConfigurarRBAC(false);
    try {
        _popularSelectUsuarios();
        const [dadosGrupos, dadosCatalogo] = await Promise.all([
            _rbacRequest('grupos'), _rbacRequest('catalogo')
        ]);
        state.rbacGrupos = dadosGrupos.grupos || [];
        state.rbacCatalogo = dadosCatalogo.modulos || [];
        _definirPodeConfigurarRBAC(!!dadosCatalogo.pode_configurar);
        _renderizarTabelaGrupos();
    } catch (erro) {
        console.error('[RBAC] Falha ao carregar grupos:', erro);
        if (state.dom.tabelaGruposRbac) state.dom.tabelaGruposRbac.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#b91c1c;">${_rbacEsc(erro.message)}</td></tr>`;
        mostrarAlerta(erro.message, 'error');
    }
}

function _renderizarTabelaGrupos() {
    const tabela = state.dom.tabelaGruposRbac;
    if (!tabela) return;
    if (!state.rbacGrupos.length) {
        tabela.innerHTML = '<tr><td colspan="6" style="text-align:center;">Nenhum grupo cadastrado.</td></tr>';
        return;
    }
    tabela.innerHTML = state.rbacGrupos.map(g => `
        <tr>
            <td><strong>${_rbacEsc(g.nome)}</strong><br><small style="color:#64748b;">${_rbacEsc(g.slug)}</small></td>
            <td>${_rbacEsc(g.descricao || '—')}</td>
            <td style="text-align:center;">${Number(g.total_usuarios || 0)}</td>
            <td style="text-align:center;">${Number(g.total_permissoes || 0)}</td>
            <td><span class="badge badge-${g.ativo == 1 ? 'success':'danger'}">${g.protegido == 1 ? 'Protegido' : (g.ativo == 1 ? 'Ativo' : 'Inativo')}</span></td>
            <td style="white-space:nowrap;">
                ${g.protegido == 1 ? '<span style="color:#64748b;font-size:.8rem;"><i class="fas fa-lock"></i> Sistema</span>' : `
                <button class="mod-grupo-btn" data-rbac-grupo-editar="${Number(g.id)}" ${state.rbacPodeConfigurar?'':'disabled'}><i class="fas fa-edit"></i> Editar</button>
                <button class="mod-grupo-btn" data-rbac-grupo-excluir="${Number(g.id)}" data-nome="${_rbacEsc(g.nome)}" ${state.rbacPodeConfigurar?'':'disabled'} style="color:#b91c1c;"><i class="fas fa-trash"></i></button>`}
            </td>
        </tr>`).join('');
}

function novoGrupoRbac() {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar grupos RBAC.', 'error'); return; }
    if (!state.dom.grupoEditor) return;
    state.dom.grupoEditor.style.display = 'block';
    state.dom.grupoId.value = '';
    state.dom.grupoNome.value = '';
    state.dom.grupoSlug.value = '';
    state.dom.grupoDescricao.value = '';
    _renderizarPermissoesGrupo([]);
    state.dom.grupoNome.focus();
}

async function editarGrupoRbac(id) {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar grupos RBAC.', 'error'); return; }
    try {
        const dados = await _rbacRequest('grupo', { query:`&id=${encodeURIComponent(id)}` });
        state.dom.grupoEditor.style.display = 'block';
        state.dom.grupoId.value = dados.grupo.id;
        state.dom.grupoNome.value = dados.grupo.nome || '';
        state.dom.grupoSlug.value = dados.grupo.slug || '';
        state.dom.grupoDescricao.value = dados.grupo.descricao || '';
        _renderizarPermissoesGrupo(dados.permissoes || []);
        state.dom.grupoEditor.scrollIntoView({ behavior:'smooth', block:'start' });
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}

function cancelarGrupoRbac() {
    if (state.dom.grupoEditor) state.dom.grupoEditor.style.display = 'none';
}

function _renderizarPermissoesGrupo(selecionadas) {
    const destino = state.dom.grupoPermissoesCatalog;
    if (!destino) return;
    const estadosSelecionados = new Map();
    (selecionadas || []).forEach(item => {
        if (typeof item === 'string') estadosSelecionados.set(item, 'PERMITIR');
        else if (item?.modulo && item?.acao) estadosSelecionados.set(`${item.modulo}.${item.acao}`, item.estado || 'PERMITIR');
    });
    const nomesAcoes = {visualizar:'Visualizar',criar:'Criar',editar:'Editar',excluir:'Excluir',exportar:'Exportar',imprimir:'Imprimir',importar:'Importar',aprovar:'Aprovar',configurar:'Configurar',executar:'Executar',bloquear:'Bloquear',desbloquear:'Desbloquear'};
    destino.innerHTML = state.rbacCatalogo.map(m => {
        const acoes = (m.acoes || []).map(acao => {
            const chave = `${m.chave}.${acao}`;
            const estado = estadosSelecionados.get(chave) || 'HERDAR';
            const concedivel = !!m.acoes_concediveis?.[acao];
            const nome = _rbacEsc(nomesAcoes[acao] || acao);
            return `<div class="rbac-permission-row">
                <label for="grp_${_rbacEsc(m.chave)}_${_rbacEsc(acao)}">${nome}</label>
                <select class="rbac-grupo-state" id="grp_${_rbacEsc(m.chave)}_${_rbacEsc(acao)}" data-modulo="${_rbacEsc(m.chave)}" data-acao="${_rbacEsc(acao)}" ${state.rbacPodeConfigurar?'':'disabled'}>
                    <option value="HERDAR" ${estado==='HERDAR'?'selected':''}>Herdar / sem regra</option>
                    <option value="PERMITIR" ${estado==='PERMITIR'?'selected':''} ${concedivel?'':'disabled'}>Permitir</option>
                    <option value="NEGAR" ${estado==='NEGAR'?'selected':''}>Negar</option>
                </select>
                <small class="rbac-group-preview">${_rbacEsc(_descricaoEstadoGrupo(estado))}</small>
            </div>`;
        }).join('');
        return `<div class="mod-card"><div class="mod-card-top"><div class="mod-card-icon"><i class="${_rbacEsc(m.icone || 'fas fa-shield-alt')}"></i></div><div class="mod-card-info"><div class="mod-card-nome">${_rbacEsc(m.nome)}</div><div class="mod-card-desc">${_rbacEsc(m.descricao || m.chave)}</div></div></div><div class="rbac-permission-matrix">${acoes || '<span style="color:#94a3b8;font-size:.75rem;">Sem ações cadastradas</span>'}</div></div>`;
    }).join('');
}

function _descricaoEstadoGrupo(estado) {
    return estado === 'PERMITIR' ? 'Este grupo concede a ação.' : estado === 'NEGAR' ? 'Este grupo nega explicitamente a ação.' : 'Este grupo não define a ação.';
}

function _atualizarPreviaGrupo(select) {
    const preview = select.closest('.rbac-permission-row')?.querySelector('.rbac-group-preview');
    if (preview) preview.textContent = _descricaoEstadoGrupo(select.value);
}

async function salvarGrupoRbac() {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar grupos RBAC.', 'error'); return; }
    const nome = state.dom.grupoNome?.value.trim();
    const slug = state.dom.grupoSlug?.value.trim();
    if (!nome || !slug) { mostrarAlerta('Informe nome e identificador do grupo.', 'warning'); return; }
    const permissoes = [...(state.dom.grupoPermissoesCatalog?.querySelectorAll('.rbac-grupo-state') || [])]
        .map(el => ({ modulo:el.dataset.modulo, acao:el.dataset.acao, estado:el.value }));
    try {
        await _rbacRequest('salvar_grupo', { method:'POST', body:{ id:Number(state.dom.grupoId?.value || 0), nome, slug, descricao:state.dom.grupoDescricao?.value.trim() || '', permissoes } });
        mostrarAlerta('Grupo salvo com sucesso. As permissões foram recalculadas.', 'success');
        cancelarGrupoRbac();
        await carregarCentralGrupos();
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}

async function excluirGrupoRbac(id, nome) {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar grupos RBAC.', 'error'); return; }
    if (!confirm(`Excluir o grupo "${nome}"? O histórico de auditoria será preservado.`)) return;
    try {
        await _rbacRequest('excluir_grupo', { method:'DELETE', body:{id:Number(id)} });
        mostrarAlerta('Grupo excluído com segurança.', 'success');
        await carregarCentralGrupos();
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}

async function carregarGruposUsuario(usuarioId) {
    if (!usuarioId) { if (state.dom.grupoUsuarioVinculos) state.dom.grupoUsuarioVinculos.style.display='none'; return; }
    state.usuarioGruposSelecionado = Number(usuarioId);
    try {
        const dados = await _rbacRequest('usuario_grupos',{query:`&usuario_id=${encodeURIComponent(usuarioId)}`});
        const vinculados = new Set((dados.grupos || []).map(g => Number(g.id)));
        const destino = state.dom.grupoUsuarioVinculos;
        destino.style.display = 'block';
        destino.innerHTML = `<strong style="display:block;margin-bottom:.6rem;color:#334155;"><i class="fas fa-link"></i> Grupos do usuário</strong>` + state.rbacGrupos.map(g => `<label style="display:inline-flex;align-items:center;gap:.45rem;margin:.3rem .9rem .3rem 0;padding:.38rem .6rem;background:#fff;border:1px solid #e2e8f0;border-radius:6px;cursor:${g.protegido == 1 || !state.rbacPodeConfigurar ? 'not-allowed':'pointer'};opacity:${g.protegido == 1 || !state.rbacPodeConfigurar ? '.75':'1'};"><input type="checkbox" class="rbac-usuario-grupo" value="${Number(g.id)}" ${vinculados.has(Number(g.id)) ? 'checked':''} ${g.protegido == 1 || !state.rbacPodeConfigurar ? 'disabled':''}> ${_rbacEsc(g.nome)}${g.protegido == 1 ? ' <small>(compat.)</small>':''}</label>`).join('');
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}

async function salvarGruposUsuario() {
    if (!state.rbacPodeConfigurar) { mostrarAlerta('Você não possui autorização para configurar grupos RBAC.', 'error'); return; }
    if (!state.usuarioGruposSelecionado) { mostrarAlerta('Selecione um usuário.', 'warning'); return; }
    const grupos_ids = [...document.querySelectorAll('.rbac-usuario-grupo:checked')].map(el => Number(el.value));
    try {
        await _rbacRequest('atribuir_grupos',{method:'POST',body:{usuario_id:state.usuarioGruposSelecionado,grupos_ids}});
        mostrarAlerta('Vínculos de grupos atualizados. A nova política será aplicada na próxima requisição do usuário.', 'success');
        window.PermissoesModulos?.revalidarPermissoes?.();
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}


// ─────────────────────────────────────────────────────────────────────
// CENTRAL RBAC — SESSÕES E AUDITORIA
// ─────────────────────────────────────────────────────────────────────
async function carregarSegurancaRbac() {
    const tabelaSessoes = state.dom.tabelaSessoesRbac;
    const tabelaAuditoria = state.dom.tabelaAuditoriaRbac;
    if (tabelaSessoes) tabelaSessoes.innerHTML = '<tr><td colspan="7" style="text-align:center;">Carregando sessões...</td></tr>';
    if (tabelaAuditoria) tabelaAuditoria.innerHTML = '<tr><td colspan="7" style="text-align:center;">Carregando auditoria...</td></tr>';
    try {
        const [sessoes, auditoria] = await Promise.all([_rbacRequest('sessoes'), _rbacRequest('auditoria', { query:'&limite=30' })]);
        const itensSessoes = sessoes.itens || [];
        if (tabelaSessoes) tabelaSessoes.innerHTML = itensSessoes.length ? itensSessoes.map(s => `
            <tr><td><strong>${_rbacEsc(s.usuario_nome || 'Usuário removido')}</strong><br><small>${_rbacEsc(s.usuario_email || '')}</small></td>
            <td>${_rbacEsc([s.dispositivo,s.navegador,s.sistema_operacional].filter(Boolean).join(' · ') || '—')}</td><td>${_rbacEsc(s.ip || '—')}</td>
            <td>${_rbacEsc(s.iniciada_em || '—')}</td><td>${_rbacEsc(s.ultima_atividade_em || '—')}</td>
            <td><span class="badge badge-${s.status === 'ATIVA' ? 'success':'danger'}">${_rbacEsc(s.status)}</span></td>
            <td>${s.status === 'ATIVA' ? `<button class="mod-grupo-btn" style="color:#b91c1c;" onclick="UsuariosPage.revogarSessaoRbac(${Number(s.id)})"><i class="fas fa-ban"></i> Revogar</button>` : _rbacEsc(s.motivo_encerramento || '—')}</td></tr>`).join('') : '<tr><td colspan="7" style="text-align:center;">Nenhuma sessão RBAC registrada.</td></tr>';
        const itensAuditoria = auditoria.itens || [];
        if (tabelaAuditoria) tabelaAuditoria.innerHTML = itensAuditoria.length ? itensAuditoria.map(a => `
            <tr><td>${_rbacEsc(a.ocorrido_em)}</td><td>${_rbacEsc(a.usuario_nome || 'Sistema')}</td><td>${_rbacEsc([a.modulo_chave,a.submodulo_chave].filter(Boolean).join(' / ') || '—')}</td><td>${_rbacEsc(a.acao)}</td>
            <td><span class="badge badge-${a.resultado === 'SUCESSO' ? 'success' : 'danger'}">${_rbacEsc(a.resultado)}</span></td><td>${_rbacEsc([a.registro_tipo,a.registro_id].filter(Boolean).join(' #') || '—')}</td><td title="${_rbacEsc(a.hash_evento)}"><i class="fas fa-link" style="color:#16a34a;"></i> Íntegro</td></tr>`).join('') : '<tr><td colspan="7" style="text-align:center;">Nenhum evento de auditoria.</td></tr>';
    } catch (erro) {
        const mensagem = _rbacEsc(erro.message);
        if (tabelaSessoes) tabelaSessoes.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#b91c1c;">${mensagem}</td></tr>`;
        if (tabelaAuditoria) tabelaAuditoria.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#b91c1c;">${mensagem}</td></tr>`;
    }
}

async function revogarSessaoRbac(sessaoId) {
    if (!confirm('Revogar esta sessão? O usuário será desconectado na próxima validação.')) return;
    try {
        await _rbacRequest('revogar_sessao', { method:'POST', body:{ sessao_id:Number(sessaoId), motivo:'REVOGADA_PELA_CENTRAL_RBAC' } });
        mostrarAlerta('Sessão revogada com segurança.', 'success');
        await carregarSegurancaRbac();
    } catch (erro) { mostrarAlerta(erro.message, 'error'); }
}


/**
 * Escapa conteúdo dinâmico antes da renderização em HTML.
 * Mantém a Central de Usuários resiliente a nomes, grupos e dados de auditoria.
 */
function _escHtml(valor) {
    return String(valor ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
