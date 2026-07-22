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
    permissoesEditadas: {},   // { chave: { pode_acessar, pode_criar, pode_editar, pode_excluir, pode_exportar } }
    todosModulos: [],
    abaAtual: 'usuarios'
};

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
        _onToggleModulo:          _onToggleModulo,
        _onTogglePerm:            _onTogglePerm
    };
}

export function destroy() {
    console.log('[Usuarios] Destruindo...');
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
    if (aba === 'usuarios') {
        state.dom.painelUsuarios?.classList.remove('hidden');
        state.dom.painelUsuarios?.removeAttribute('style');
        state.dom.painelModulos?.classList.remove('show');
        state.dom.painelModulos?.setAttribute('style','display:none');
    } else {
        state.dom.painelUsuarios?.setAttribute('style','display:none');
        state.dom.painelModulos?.classList.add('show');
        state.dom.painelModulos?.removeAttribute('style');
        // Popular select de usuários
        _popularSelectUsuarios();
    }
}

function _popularSelectUsuarios() {
    const sel = state.dom.modSelectUsuario;
    if (!sel || state.todosUsuarios.length === 0) return;
    const valorAtual = sel.value;
    sel.innerHTML = '<option value="">— Selecione um usuário —</option>' +
        state.todosUsuarios.map(u =>
            `<option value="${u.id}" ${u.id == valorAtual ? 'selected' : ''}>${u.nome} (${_textoPermissao(u.permissao)})</option>`
        ).join('');
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
    fetch(`${state.apiBase}api_usuarios.php`)
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

    fetch(`${state.apiBase}api_permissoes_modulos.php?acao=permissoes_usuario&id=${uid}`)
        .then(r => r.json())
        .then(data => {
            if (state.dom.modLoading) state.dom.modLoading.style.display = 'none';
            if (!data.sucesso) { mostrarAlerta('Erro ao carregar módulos: ' + data.mensagem, 'error'); return; }
            const permissoes = data.dados.permissoes || {};
            state.todosModulos = Object.values(permissoes);
            // Inicializar estado editado
            state.todosModulos.forEach(m => {
                state.permissoesEditadas[m.modulo_chave] = {
                    pode_acessar:  m.pode_acessar,
                    pode_criar:    m.pode_criar,
                    pode_editar:   m.pode_editar,
                    pode_excluir:  m.pode_excluir,
                    pode_exportar: m.pode_exportar
                };
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
        const habilitados = mods.filter(m => state.permissoesEditadas[m.modulo_chave]?.pode_acessar).length;
        const total = mods.length;
        const grupoId = 'grupo_' + grupo.replace(/[^a-z0-9]/gi, '_');
        return `
        <div class="mod-grupo" data-grupo="${grupo}">
            <div class="mod-grupo-header" onclick="document.getElementById('${grupoId}').classList.toggle('hidden')">
                <div class="mod-grupo-titulo">
                    <i class="${iconeGrupo[grupo] || 'fas fa-folder'}" style="color:#2563eb;"></i>
                    ${grupo}
                    <span class="mod-grupo-badge">${habilitados}/${total}</span>
                </div>
                <div class="mod-grupo-toggle">
                    <button class="mod-grupo-btn" onclick="event.stopPropagation();UsuariosPage._toggleGrupo('${grupo}',true)">
                        <i class="fas fa-check"></i> Todos
                    </button>
                    <button class="mod-grupo-btn" onclick="event.stopPropagation();UsuariosPage._toggleGrupo('${grupo}',false)">
                        <i class="fas fa-times"></i> Nenhum
                    </button>
                    <i class="fas fa-chevron-down" style="color:#94a3b8;font-size:0.8rem;"></i>
                </div>
            </div>
            <div class="mod-grid" id="${grupoId}">
                ${mods.map(m => _renderCardModulo(m, usuario)).join('')}
            </div>
        </div>`;
    }).join('');

    // Expor _toggleGrupo
    window.UsuariosPage._toggleGrupo = function(grupo, habilitar) {
        const mods = state.todosModulos.filter(m => m.grupo === grupo);
        mods.forEach(m => {
            if (!m.perfil_permite && habilitar) return; // não habilitar o que o perfil não permite
            const perm = state.permissoesEditadas[m.modulo_chave];
            if (perm) perm.pode_acessar = habilitar ? 1 : 0;
            const toggle = document.getElementById(`toggle_${m.modulo_chave}`);
            if (toggle) toggle.checked = habilitar && !!m.perfil_permite;
            const card = document.getElementById(`card_${m.modulo_chave}`);
            if (card) _atualizarClasseCard(card, habilitar && !!m.perfil_permite, m.perfil_permite);
        });
        _atualizarContadorGrupo(grupo);
    };
}

function _renderCardModulo(m, usuario) {
    const perm = state.permissoesEditadas[m.modulo_chave] || {};
    const habilitado = !!perm.pode_acessar;
    const perfilPermite = !!m.perfil_permite;
    const classeCard = habilitado ? 'habilitado' : (perfilPermite ? '' : 'perfil-sem-acesso');

    const minBadgeClass = {
        visualizador: 'mod-min-visualizador',
        operador:     'mod-min-operador',
        gerente:      'mod-min-gerente',
        admin:        'mod-min-admin'
    }[m.permissao_minima] || 'mod-min-operador';

    const perms = [
        { key:'pode_acessar',  label:'Ver',      icon:'fa-eye' },
        { key:'pode_criar',    label:'Criar',    icon:'fa-plus' },
        { key:'pode_editar',   label:'Editar',   icon:'fa-edit' },
        { key:'pode_excluir',  label:'Excluir',  icon:'fa-trash' },
        { key:'pode_exportar', label:'Exportar', icon:'fa-download' }
    ];

    return `
    <div class="mod-card ${classeCard}" id="card_${m.modulo_chave}" data-chave="${m.modulo_chave}" data-grupo="${m.grupo}">
        <div class="mod-card-top">
            <div class="mod-card-icon"><i class="${m.icone}"></i></div>
            <div class="mod-card-info">
                <div class="mod-card-nome">${m.nome}</div>
                <div class="mod-card-desc">${m.descricao || ''}</div>
                <span class="mod-min-badge ${minBadgeClass}">
                    <i class="fas fa-lock" style="font-size:0.65rem;"></i> mínimo: ${_textoPermissao(m.permissao_minima)}
                </span>
            </div>
            ${!perfilPermite ? `<div title="Perfil não tem permissão mínima" style="color:#94a3b8;font-size:0.85rem;"><i class="fas fa-lock"></i></div>` : ''}
        </div>
        <div class="mod-card-perms" id="perms_${m.modulo_chave}">
            ${perms.map(p => {
                const ativo = !!perm[p.key];
                const desabilitado = !perfilPermite || (p.key !== 'pode_acessar' && !perm.pode_acessar);
                return `<div class="mod-perm-chip ${ativo ? 'on' : 'off'} ${desabilitado ? 'disabled' : ''}"
                     id="chip_${m.modulo_chave}_${p.key}"
                     onclick="UsuariosPage._onTogglePerm('${m.modulo_chave}','${p.key}',this)"
                     title="${p.label}: ${ativo ? 'Habilitado — clique para desabilitar' : 'Desabilitado — clique para habilitar'}">
                    <i class="fas ${p.icon}" style="font-size:0.65rem;"></i> ${p.label}
                </div>`;
            }).join('')}
        </div>
    </div>`;
}

// ─────────────────────────────────────────────────────────────────────
// INTERAÇÕES COM MÓDULOS
// ─────────────────────────────────────────────────────────────────────
function _onToggleModulo(chave, habilitado) {
    const perm = state.permissoesEditadas[chave];
    if (!perm) return;
    perm.pode_acessar = habilitado ? 1 : 0;
    const card = document.getElementById(`card_${chave}`);
    const m = state.todosModulos.find(x => x.modulo_chave === chave);
    if (card) _atualizarClasseCard(card, habilitado, m?.perfil_permite);
    // Desabilitar chips se desabilitado
    const chips = document.querySelectorAll(`[id^="chip_${chave}_"]`);
    chips.forEach(c => c.classList.toggle('disabled', !habilitado));
    // Atualizar contador do grupo
    if (m) _atualizarContadorGrupo(m.grupo);
}

function _onTogglePerm(chave, permKey, chip) {
    const perm = state.permissoesEditadas[chave];
    if (!perm) return;
    // Se clicar em 'Ver' (pode_acessar), ativar/desativar o módulo todo
    if (permKey === 'pode_acessar') {
        perm.pode_acessar = perm.pode_acessar ? 0 : 1;
        chip.classList.toggle('on',  !!perm.pode_acessar);
        chip.classList.toggle('off', !perm.pode_acessar);
        // Desabilitar/habilitar os outros chips
        const card = document.getElementById(`card_${chave}`);
        const m = state.todosModulos.find(x => x.modulo_chave === chave);
        if (card) _atualizarClasseCard(card, !!perm.pode_acessar, m?.perfil_permite);
        const outrosChips = document.querySelectorAll(`[id^="chip_${chave}_"]`);
        outrosChips.forEach(c => {
            if (c.id === `chip_${chave}_pode_acessar`) return;
            c.classList.toggle('disabled', !perm.pode_acessar);
        });
        if (m) _atualizarContadorGrupo(m.grupo);
    } else {
        if (!perm.pode_acessar) return; // não alterar se módulo desabilitado
        perm[permKey] = perm[permKey] ? 0 : 1;
        chip.classList.toggle('on',  !!perm[permKey]);
        chip.classList.toggle('off', !perm[permKey]);
    }
    // Rastrear alterações pendentes
    state.alteracoesPendentes = (state.alteracoesPendentes || 0) + 1;
    _atualizarBadgePendente(state.alteracoesPendentes);
}

function _atualizarClasseCard(card, habilitado, perfilPermite) {
    card.classList.remove('habilitado', 'bloqueado', 'perfil-sem-acesso');
    if (habilitado) card.classList.add('habilitado');
    else if (!perfilPermite) card.classList.add('perfil-sem-acesso');
}

function _atualizarContadorGrupo(grupo) {
    const mods = state.todosModulos.filter(m => m.grupo === grupo);
    const habilitados = mods.filter(m => state.permissoesEditadas[m.modulo_chave]?.pode_acessar).length;
    const badge = document.querySelector(`[data-grupo="${grupo}"] .mod-grupo-badge`);
    if (badge) badge.textContent = `${habilitados}/${mods.length}`;
}

// ─────────────────────────────────────────────────────────────────────
// AÇÕES GLOBAIS
// ─────────────────────────────────────────────────────────────────────
function habilitarTodos() {
    state.todosModulos.forEach(m => {
        if (!m.perfil_permite) return;
        const perm = state.permissoesEditadas[m.modulo_chave];
        if (perm) { perm.pode_acessar = 1; perm.pode_criar = 1; perm.pode_editar = 1; perm.pode_exportar = 1; }
        const toggle = document.getElementById(`toggle_${m.modulo_chave}`);
        if (toggle && !toggle.disabled) toggle.checked = true;
        const card = document.getElementById(`card_${m.modulo_chave}`);
        if (card) _atualizarClasseCard(card, true, m.perfil_permite);
        const chips = document.querySelectorAll(`[id^="chip_${m.modulo_chave}_"]`);
        chips.forEach(c => { c.classList.add('on'); c.classList.remove('off','disabled'); });
    });
    Object.keys(
        state.todosModulos.reduce((acc, m) => { acc[m.grupo] = true; return acc; }, {})
    ).forEach(g => _atualizarContadorGrupo(g));
    mostrarAlerta('Todos os módulos habilitados. Clique em Salvar para confirmar.', 'info');
}

function desabilitarTodos() {
    state.todosModulos.forEach(m => {
        const perm = state.permissoesEditadas[m.modulo_chave];
        if (perm) { perm.pode_acessar = 0; }
        const toggle = document.getElementById(`toggle_${m.modulo_chave}`);
        if (toggle) toggle.checked = false;
        const card = document.getElementById(`card_${m.modulo_chave}`);
        if (card) _atualizarClasseCard(card, false, m.perfil_permite);
        const chips = document.querySelectorAll(`[id^="chip_${m.modulo_chave}_"]`);
        chips.forEach(c => c.classList.add('disabled'));
    });
    Object.keys(
        state.todosModulos.reduce((acc, m) => { acc[m.grupo] = true; return acc; }, {})
    ).forEach(g => _atualizarContadorGrupo(g));
    mostrarAlerta('Todos os módulos desabilitados. Clique em Salvar para confirmar.', 'warning');
}

function resetarParaPerfil() {
    if (!state.usuarioModuloAtual) return;
    if (!confirm(`Resetar todas as permissões de "${state.usuarioModuloAtual.nome}" para o padrão do perfil "${_textoPermissao(state.usuarioModuloAtual.permissao)}"?`)) return;
    fetch(`${state.apiBase}api_permissoes_modulos.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'resetar_para_perfil', usuario_id: state.usuarioModuloAtual.id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            mostrarAlerta('Permissões resetadas para o padrão do perfil.', 'success');
            selecionarUsuarioModulos(state.usuarioModuloAtual.id);
        } else {
            mostrarAlerta('Erro: ' + data.mensagem, 'error');
        }
    });
}

function salvarPermissoes() {
    if (!state.usuarioModuloAtual) return;
    const payload = {
        acao: 'salvar_permissoes',
        usuario_id: state.usuarioModuloAtual.id,
        permissoes: state.permissoesEditadas
    };
    fetch(`${state.apiBase}api_permissoes_modulos.php`, {
        method: 'POST',
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
            const total = Object.keys(state.permissoesEditadas).length;
            const hab   = Object.values(state.permissoesEditadas).filter(p => p.pode_acessar).length;
            if (state.dom.modAuditOrigem) state.dom.modAuditOrigem.textContent = `${hab}/${total} módulos habilitados`;
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
    fetch(`${state.apiBase}api_usuarios.php?id=${id}`)
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

    // Verificar se algum módulo tem registro individual (origem = 'individual')
    const comRegistro = Object.values(permissoes).filter(p => p.origem === 'individual');
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

// Expor funções de toggle para uso inline no HTML
if (typeof window !== 'undefined') {
    window.UsuariosPage = window.UsuariosPage || {};
    window.UsuariosPage._onToggleModulo = _onToggleModulo;
    window.UsuariosPage._onTogglePerm   = _onTogglePerm;
}
