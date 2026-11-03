const API_CONVIDADOS = '../api/api_convidados.php';
const API_VISITANTES = '../api/api_visitantes.php';
const API_UNIDADES = '../api/api_unidades.php';
const API_MORADORES = '../api/api_moradores.php';
const API_DEPENDENTES = '../api/api_dependentes.php';
const ABA_STORAGE_KEY = 'registro_aba';

const TIPO_LABELS = {
    VISITANTE: 'Visitante',
    PRESTADOR: 'Prestador',
    ENTREGADOR_AUTORIZADO: 'Entregador Autorizado',
    FAMILIAR: 'Familiar'
};

const ORIGEM_LABELS = {
    EMAIL: 'E-mail',
    WHATSAPP: 'WhatsApp',
    PRESENCIAL: 'Presencial',
    ADMINISTRATIVO: 'Administrativo',
    PORTAL_MORADOR: 'Portal do morador'
};

const STATUS_LABELS = {
    PREVISTO: 'Previsto',
    PRESENTE: 'Presente',
    FINALIZADO: 'Finalizado',
    CANCELADO: 'Cancelado',
    EXPIRADO: 'Expirado'
};

const AUTORIA_LABELS = {
    USUARIO: 'Usuário',
    MORADOR: 'Morador',
    DEPENDENTE: 'Dependente'
};

const state = {
    root: null,
    selectedDate: '',
    activeTab: 'manual',
    filters: { unidade: '', tipo: '', status: '', busca: '' },
    guests: [],
    summary: {},
    listController: null,
    listTimer: null,
    urlUpdateTimer: null,
    listeners: [],
    unitsPromise: null,
    cascadeToken: 0,
    documentSearchToken: 0,
    editingId: null,
    permissionsReady: false,
    initialized: false
};

function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function listen(target, eventName, handler, options) {
    if (!target) return;
    target.addEventListener(eventName, handler, options);
    state.listeners.push({ target, eventName, handler, options });
}

function byId(id) {
    return state.root?.querySelector(`#${id}`) || document.getElementById(id);
}

function isActiveRoot(root) {
    return Boolean(root && state.initialized && state.root === root && root.isConnected);
}

function throwIfRootStale(root) {
    if (isActiveRoot(root)) return;
    const error = new Error('A página foi desmontada durante a operação.');
    error.name = 'AbortError';
    throw error;
}

function todaySaoPaulo() {
    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/Sao_Paulo',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    } catch (_) {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }
}

function parseDateParts(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) } : null;
}

function shiftDate(value, days) {
    const parts = parseDateParts(value) || parseDateParts(todaySaoPaulo());
    const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + Number(days || 0), 12));
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

function formatDateLabel(value) {
    const parts = parseDateParts(value);
    if (!parts) return value || 'Data inválida';
    const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day, 12));
    try {
        const formatted = new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo', weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric'
        }).format(date);
        return formatted.charAt(0).toUpperCase() + formatted.slice(1);
    } catch (_) {
        return `${String(parts.day).padStart(2, '0')}/${String(parts.month).padStart(2, '0')}/${parts.year}`;
    }
}

function formatDateOnly(value) {
    const match = String(value || '').match(/(\d{4})-(\d{2})-(\d{2})/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : (value || '—');
}

function formatDateTime(value) {
    const match = String(value || '').match(/(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?/);
    return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : (value || '—');
}

function statusOf(guest) {
    return String(guest?.status_exibicao || guest?.status || '').toUpperCase() || 'PREVISTO';
}

function statusLabel(status) {
    return STATUS_LABELS[String(status || '').toUpperCase()] || String(status || '—');
}

function typeLabel(type) {
    const key = String(type || '').toUpperCase();
    return TIPO_LABELS[key] || String(type || '—');
}

function originLabel(origin) {
    const key = String(origin || '').toUpperCase();
    return ORIGEM_LABELS[key] || String(origin || '—');
}

function authorTypeLabel(type) {
    const key = String(type || '').toUpperCase();
    return AUTORIA_LABELS[key] || String(type || 'Usuário');
}

function maskDocument(documento, tipo) {
    const raw = String(documento || '').trim();
    if (!raw) return '—';
    if (String(tipo || '').toUpperCase() === 'CPF') {
        const digits = raw.replace(/\D/g, '');
        if (digits.length === 11) return `***.${digits.slice(3, 6)}.${digits.slice(6, 9)}-**`;
    }
    return raw;
}

function ownerName(guest) {
    return guest?.anfitriao_nome || guest?.dependente_nome || guest?.morador_nome || guest?.nome_morador || '—';
}

function ownerType(guest) {
    return String(guest?.anfitriao_tipo || (guest?.dependente_id ? 'DEPENDENTE' : 'MORADOR')).toUpperCase();
}

function permanenceLabel(guest) {
    const days = Number(guest?.dias_permanencia || 1);
    const end = guest?.validade_fim || '';
    return `${days} ${days === 1 ? 'dia' : 'dias'}${end ? `, até ${formatDateOnly(end)}` : ''}`;
}

function entriesLabel(guest) {
    const used = Number(guest?.entradas_utilizadas || 0);
    const limit = guest?.limite_entradas === null || typeof guest?.limite_entradas === 'undefined' || guest?.limite_entradas === ''
        ? null : Number(guest.limite_entradas);
    return limit === null || Number.isNaN(limit) ? `${used} / Indeterminado` : `${used} / ${limit}`;
}

function generateKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (globalThis.crypto?.getRandomValues) {
        const bytes = new Uint8Array(16);
        globalThis.crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 36);
    }
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`.slice(0, 36);
}

function renewIdempotencyKey() {
    const input = byId('convidadoIdempotencyKey');
    if (input) input.value = generateKey();
}

function hasPermission(action) {
    if (!state.permissionsReady) return false;
    const permissions = window.PermissoesModulos;
    const key = `pode_${action}`;
    if (permissions && typeof permissions.temPermissao === 'function') {
        return permissions.temPermissao('registro', key) !== false;
    }
    if (typeof window.rbacPode === 'function') {
        try {
            return window.rbacPode('registro', action) !== false;
        } catch (_) {
            return true;
        }
    }
    return true;
}

function syncPermissionVisibility() {
    const button = state.root?.querySelector('[data-guest-action="new"]');
    if (button) button.hidden = !hasPermission('criar');
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'include', ...options });
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (_) { data = null; }
    if (!data || typeof data !== 'object') {
        throw new Error(`A API não retornou JSON válido (HTTP ${response.status}).`);
    }
    if (!response.ok && data.sucesso !== true) {
        throw new Error(data.mensagem || `Falha HTTP ${response.status}.`);
    }
    return data;
}

function showFeedback(type, message) {
    const box = byId('convidadosFeedback');
    if (!box) return;
    box.className = `guest-feedback ${type || 'info'}`;
    box.textContent = message || '';
    box.hidden = !message;
}

function showManualFeedback(type, message) {
    const box = byId('alertBox');
    if (!box) return;
    const icon = type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle';
    box.innerHTML = message ? `<div class="guest-manual-feedback ${esc(type || 'info')}"><i class="fas fa-${icon}"></i><span>${esc(message)}</span></div>` : '';
}

function setDisplay(element, visible) {
    if (element) element.hidden = !visible;
}

function updateUrlForTab(tab) {
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'registro');
        url.searchParams.set('aba', tab);
        window.history.replaceState({ ...(window.history.state || {}), page: 'registro', aba: tab }, '', url.href);
    } catch (_) {
        // URL/sessionStorage são apenas uma conveniência; a navegação continua funcionando sem eles.
    }
}

function readStoredTab() {
    const urlTab = new URLSearchParams(window.location.search).get('aba');
    if (urlTab === 'convidados' || urlTab === 'manual') return urlTab;
    try {
        const stored = sessionStorage.getItem(ABA_STORAGE_KEY);
        return stored === 'convidados' ? 'convidados' : 'manual';
    } catch (_) {
        return 'manual';
    }
}

function persistTab(tab) {
    try { sessionStorage.setItem(ABA_STORAGE_KEY, tab); } catch (_) { /* storage indisponível */ }
}

function activateTab(tab, updateUrl = true) {
    const nextTab = tab === 'convidados' ? 'convidados' : 'manual';
    const button = state.root?.querySelector(`.tab-button[data-tab="${nextTab}"]`);
    const content = state.root?.querySelector(`#tab-${nextTab === 'convidados' ? 'lista-convidados' : 'registro-manual'}`);
    if (!button || !content) return false;

    state.root.querySelectorAll('.tab-button[data-tab]').forEach(item => {
        const active = item === button;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    state.root.querySelectorAll('.tab-content').forEach(item => item.classList.toggle('active', item === content));
    state.activeTab = nextTab;
    persistTab(nextTab);
    if (updateUrl) updateUrlForTab(nextTab);
    if (nextTab === 'convidados' && !state.guests.length) scheduleList(true);
    return true;
}

function renderDateNavigation() {
    const input = byId('convidadosData');
    const label = byId('convidadosDataLabel');
    const todayButton = byId('btnConvidadosHoje');
    if (input) input.value = state.selectedDate;
    if (label) {
        const today = todaySaoPaulo();
        const relative = state.selectedDate === today ? 'HOJE' : state.selectedDate === shiftDate(today, -1) ? 'ONTEM' : state.selectedDate === shiftDate(today, 1) ? 'AMANHÃ' : '';
        label.textContent = `${formatDateLabel(state.selectedDate)}${relative ? ` · ${relative}` : ''}`;
    }
    if (todayButton) todayButton.disabled = state.selectedDate === todaySaoPaulo();
}

function setDate(value) {
    if (!parseDateParts(value)) return;
    state.selectedDate = value;
    renderDateNavigation();
    scheduleList();
}

function selectedFilterParams() {
    const params = new URLSearchParams({ acao: 'listar', data: state.selectedDate, pagina: '1', por_pagina: '50' });
    Object.entries(state.filters).forEach(([key, value]) => {
        if (String(value || '').trim()) params.set(key, String(value).trim());
    });
    return params;
}

function scheduleList(immediate = false) {
    if (state.listTimer) clearTimeout(state.listTimer);
    if (immediate) {
        void loadGuests();
        return;
    }
    state.listTimer = window.setTimeout(() => { state.listTimer = null; void loadGuests(); }, 220);
}

function renderLoading(isLoading) {
    setDisplay(byId('convidadosLoading'), isLoading);
    if (isLoading) {
        setDisplay(byId('convidadosEmpty'), false);
        setDisplay(byId('convidadosError'), false);
    }
}

function updateSummary(summary = {}) {
    state.summary = summary || {};
    const values = {
        PREVISTO: Number(summary.previstos || 0),
        PRESENTE: Number(summary.presentes || 0),
        FINALIZADO: Number(summary.finalizados || 0),
        EXPIRADO: Number(summary.expirados || 0),
        CANCELADO: Number(summary.cancelados || 0)
    };
    Object.entries(values).forEach(([status, value]) => {
        const element = byId(`convidadosResumo${status}`);
        if (element) element.textContent = String(Number.isFinite(value) ? value : 0);
    });
    const badge = byId('convidadosBadge');
    if (badge) badge.textContent = String(values.PREVISTO);
}

function statusBadge(status) {
    const safeStatus = String(status || '').toUpperCase();
    const className = ['PREVISTO', 'PRESENTE', 'FINALIZADO', 'CANCELADO', 'EXPIRADO'].includes(safeStatus) ? safeStatus.toLowerCase() : 'indefinido';
    return `<span class="guest-status ${className}">${esc(statusLabel(safeStatus))}</span>`;
}

function actionButtons(guest) {
    const id = Number(guest?.id);
    if (!Number.isFinite(id) || id <= 0) return '';
    const actualStatus = String(guest?.status || '').toUpperCase();
    const displayedStatus = statusOf(guest);
    const ultimaMovimentacao = String(guest?.ultima_tipo_acesso || '').trim();
    const entradasRestantes = guest?.entradas_restantes === null ? null : Number(guest?.entradas_restantes);
    const possuiEntradas = entradasRestantes === null || (Number.isFinite(entradasRestantes) && entradasRestantes > 0);
    const podeEntrar = possuiEntradas && displayedStatus !== 'EXPIRADO'
        && (actualStatus === 'PREVISTO' || (actualStatus === 'PRESENTE' && ultimaMovimentacao === 'Saída'));
    const podeSair = actualStatus === 'PRESENTE' && ultimaMovimentacao === 'Entrada';
    const buttons = [];
    if (hasPermission('criar') && podeEntrar) {
        buttons.push(`<button type="button" class="guest-action entry" data-guest-action="entry" data-id="${id}" title="Dar entrada"><i class="fas fa-sign-in-alt"></i><span>Entrada</span></button>`);
    }
    if (hasPermission('criar') && podeSair) {
        buttons.push(`<button type="button" class="guest-action exit" data-guest-action="exit" data-id="${id}" title="Registrar saída"><i class="fas fa-sign-out-alt"></i><span>Saída</span></button>`);
    }
    if (hasPermission('editar') && actualStatus === 'PREVISTO') {
        buttons.push(`<button type="button" class="guest-action edit" data-guest-action="edit" data-id="${id}" title="Editar"><i class="fas fa-edit"></i><span>Editar</span></button>`);
    }
    if (hasPermission('excluir') && actualStatus === 'PREVISTO') {
        buttons.push(`<button type="button" class="guest-action cancel" data-guest-action="cancel" data-id="${id}" title="Cancelar"><i class="fas fa-ban"></i><span>Cancelar</span></button>`);
    }
    buttons.push(`<button type="button" class="guest-action history" data-guest-action="history" data-id="${id}" title="Ver histórico"><i class="fas fa-history"></i><span>Histórico</span></button>`);
    return `<div class="guest-actions">${buttons.join('')}</div>`;
}

function guestAuthor(guest) {
    const typeKey = String(guest?.cadastrado_por_tipo || '').toUpperCase();
    const badgeClass = ({ USUARIO: 'usuario', MORADOR: 'morador', DEPENDENTE: 'dependente' })[typeKey] || '';
    const type = authorTypeLabel(guest?.cadastrado_por_tipo);
    const name = guest?.cadastrado_por_nome || guest?.autor_nome || '—';
    const date = guest?.criado_em || guest?.cadastrado_em || '';
    return `<span class="guest-author-badge ${badgeClass}">${esc(type)}</span><span>${esc(name)}</span><small>${esc(formatDateTime(date))}</small>`;
}

function renderGuestRows(guests) {
    const tbody = byId('convidadosTableBody');
    const cards = byId('convidadosCards');
    if (!tbody || !cards) return;
    if (!guests.length) {
        tbody.innerHTML = '<tr class="guest-empty-row"><td colspan="12">Nenhum convidado para os filtros selecionados.</td></tr>';
        cards.innerHTML = '';
        return;
    }

    tbody.innerHTML = guests.map(guest => {
        const owner = `${ownerType(guest) === 'DEPENDENTE' ? 'Dependente' : 'Morador'} · ${ownerName(guest)}`;
        return `<tr>
            <td><strong>${esc(guest.nome || guest.nome_completo || '—')}</strong></td>
            <td>${esc(maskDocument(guest.documento_mascarado || guest.documento_formatado || guest.documento, guest.tipo_documento))}</td>
            <td>${esc(typeLabel(guest.tipo_convidado || guest.tipo))}</td>
            <td>${esc(guest.unidade || guest.unidade_nome || '—')}</td>
            <td>${esc(owner)}</td>
            <td>${esc(formatDateOnly(guest.data_prevista_chegada))}</td>
            <td>${esc(permanenceLabel(guest))}</td>
            <td>${esc(entriesLabel(guest))}</td>
            <td>${esc(originLabel(guest.origem_cadastro))}</td>
            <td class="guest-author-cell">${guestAuthor(guest)}</td>
            <td>${statusBadge(statusOf(guest))}</td>
            <td>${actionButtons(guest)}</td>
        </tr>`;
    }).join('');

    cards.innerHTML = guests.map(guest => {
        const id = Number(guest.id);
        const owner = `${ownerType(guest) === 'DEPENDENTE' ? 'Dependente' : 'Morador'} · ${ownerName(guest)}`;
        return `<article class="guest-card">
            <div class="guest-card-heading"><div><strong>${esc(guest.nome || guest.nome_completo || '—')}</strong><small>${esc(typeLabel(guest.tipo_convidado || guest.tipo))}</small></div>${statusBadge(statusOf(guest))}</div>
            <dl class="guest-card-details">
                <div><dt>Documento</dt><dd>${esc(maskDocument(guest.documento_mascarado || guest.documento_formatado || guest.documento, guest.tipo_documento))}</dd></div>
                <div><dt>Unidade</dt><dd>${esc(guest.unidade || guest.unidade_nome || '—')}</dd></div>
                <div><dt>Convidado de</dt><dd>${esc(owner)}</dd></div>
                <div><dt>Chegada prevista</dt><dd>${esc(formatDateOnly(guest.data_prevista_chegada))}</dd></div>
                <div><dt>Permanência</dt><dd>${esc(permanenceLabel(guest))}</dd></div>
                <div><dt>Entradas</dt><dd>${esc(entriesLabel(guest))}</dd></div>
                <div><dt>Origem</dt><dd>${esc(originLabel(guest.origem_cadastro))}</dd></div>
            </dl>
            <div class="guest-card-author">${guestAuthor(guest)}</div>
            <div class="guest-card-actions">${Number.isFinite(id) ? actionButtons(guest) : ''}</div>
        </article>`;
    }).join('');
}

async function loadGuests() {
    if (!state.root) return;
    if (state.listController) state.listController.abort();
    const controller = new AbortController();
    state.listController = controller;
    renderLoading(true);
    try {
        const data = await requestJson(`${API_CONVIDADOS}?${selectedFilterParams().toString()}`, { signal: controller.signal });
        if (controller.signal.aborted) return;
        if (!data.sucesso) throw new Error(data.mensagem || 'Não foi possível carregar a lista de convidados.');
        const payload = data.dados || {};
        state.guests = Array.isArray(payload.convidados) ? payload.convidados : (Array.isArray(payload.itens) ? payload.itens : []);
        updateSummary(payload.resumo || {});
        renderGuestRows(state.guests);
        setDisplay(byId('convidadosLoading'), false);
        setDisplay(byId('convidadosError'), false);
        setDisplay(byId('convidadosEmpty'), state.guests.length === 0);
        setDisplay(byId('convidadosList'), state.guests.length > 0);
        showFeedback('', '');
    } catch (error) {
        if (error?.name === 'AbortError') return;
        console.error('[RegistroConvidados] Falha ao carregar convidados:', error);
        setDisplay(byId('convidadosLoading'), false);
        setDisplay(byId('convidadosList'), false);
        setDisplay(byId('convidadosEmpty'), false);
        const errorBox = byId('convidadosError');
        if (errorBox) errorBox.textContent = error?.message || 'Não foi possível carregar os convidados.';
        setDisplay(errorBox, true);
    }
}

function extractList(data) {
    if (Array.isArray(data?.dados)) return data.dados;
    if (Array.isArray(data?.dados?.itens)) return data.dados.itens;
    if (Array.isArray(data?.dados?.dados)) return data.dados.dados;
    return [];
}

function setSelectLoading(select, message) {
    if (!select) return;
    select.innerHTML = '';
    select.add(new Option(message, ''));
    select.disabled = true;
}

async function loadUnits() {
    const root = state.root;
    const select = byId('convidadoUnidade');
    const filter = byId('filtroConvidadosUnidade');
    try {
        const data = await requestJson(`${API_UNIDADES}?acao=select`);
        if (!isActiveRoot(root)) return [];
        const units = extractList(data);
        const fill = (target, placeholder) => {
            if (!target) return;
            target.innerHTML = '';
            target.add(new Option(placeholder, ''));
            units.forEach(unit => {
                const name = unit.nome || unit.unidade || unit.descricao || unit.id;
                target.add(new Option(name, name));
            });
            target.disabled = false;
        };
        fill(select, 'Selecione a unidade...');
        fill(filter, 'Todas as unidades');
        return units;
    } catch (error) {
        if (!isActiveRoot(root)) return [];
        console.warn('[RegistroConvidados] Não foi possível carregar unidades:', error);
        setSelectLoading(select, 'Unidades indisponíveis');
        if (filter) setSelectLoading(filter, 'Unidades indisponíveis');
        showFeedback('warning', 'Não foi possível carregar as unidades. Tente atualizar a lista.');
        return [];
    }
}

function resetMoradorSelect(message = 'Selecione a unidade primeiro') {
    const select = byId('convidadoMorador');
    if (!select) return;
    select.innerHTML = '';
    select.add(new Option(message, ''));
    select.disabled = true;
    resetDependenteSelect();
}

function resetDependenteSelect(message = 'Selecione o morador primeiro') {
    const select = byId('convidadoDependente');
    const wrap = byId('convidadoDependenteWrap');
    if (select) {
        select.innerHTML = '';
        select.add(new Option(message, ''));
        select.disabled = true;
        select.required = false;
    }
    if (wrap) wrap.hidden = !isDependentHost();
}

function isDependentHost() {
    return state.root?.querySelector('input[name="convidado_anfitriao_tipo"]:checked')?.value === 'DEPENDENTE';
}

function selectedHostType() {
    return isDependentHost() ? 'DEPENDENTE' : 'MORADOR';
}

async function loadMoradores(unidade, selectedId = '') {
    const root = state.root;
    const select = byId('convidadoMorador');
    const token = ++state.cascadeToken;
    if (!unidade) {
        resetMoradorSelect();
        return [];
    }
    setSelectLoading(select, 'Carregando moradores...');
    try {
        const data = await requestJson(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`);
        if (!isActiveRoot(root) || token !== state.cascadeToken) return [];
        const moradores = extractList(data);
        select.innerHTML = '';
        select.add(new Option('Selecione o morador...', ''));
        moradores.forEach(morador => select.add(new Option(morador.nome_completo || morador.nome || 'Morador', morador.id)));
        select.disabled = moradores.length === 0;
        if (!moradores.length) select.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
        if (selectedId) {
            select.value = String(selectedId);
            if (select.value !== String(selectedId)) select.add(new Option(String(selectedId), selectedId));
        }
        if (select.value) {
            await loadDependentes(select.value);
            if (!isActiveRoot(root) || token !== state.cascadeToken) return [];
        }
        return moradores;
    } catch (error) {
        if (!isActiveRoot(root) || token !== state.cascadeToken) return [];
        setSelectLoading(select, 'Erro ao carregar moradores');
        showFeedback('error', error?.message || 'Não foi possível carregar os moradores.');
        return [];
    }
}

async function loadDependentes(moradorId, selectedId = '') {
    const root = state.root;
    const token = state.cascadeToken;
    const select = byId('convidadoDependente');
    if (!select || !moradorId) {
        resetDependenteSelect();
        return [];
    }
    setSelectLoading(select, 'Carregando dependentes...');
    try {
        const data = await requestJson(`${API_DEPENDENTES}?acao=listar&morador_id=${encodeURIComponent(moradorId)}&por_pagina=0`);
        if (!isActiveRoot(root) || token !== state.cascadeToken || byId('convidadoMorador')?.value !== String(moradorId)) return [];
        const dependentes = extractList(data);
        select.innerHTML = '';
        select.add(new Option('Selecione o dependente...', ''));
        dependentes.forEach(dependente => select.add(new Option(dependente.nome_completo || dependente.nome || 'Dependente', dependente.id)));
        select.disabled = dependentes.length === 0;
        select.required = isDependentHost();
        if (!dependentes.length) select.innerHTML = '<option value="">Nenhum dependente cadastrado</option>';
        if (selectedId) {
            select.value = String(selectedId);
            if (select.value !== String(selectedId)) select.add(new Option(String(selectedId), selectedId));
        }
        return dependentes;
    } catch (error) {
        if (!isActiveRoot(root) || token !== state.cascadeToken) return [];
        setSelectLoading(select, 'Erro ao carregar dependentes');
        showFeedback('error', error?.message || 'Não foi possível carregar os dependentes.');
        return [];
    }
}

function updateHostTypeUI() {
    const wrap = byId('convidadoDependenteWrap');
    const select = byId('convidadoDependente');
    const dependent = isDependentHost();
    if (wrap) wrap.hidden = !dependent;
    if (select) select.required = dependent;
    if (dependent && byId('convidadoMorador')?.value) void loadDependentes(byId('convidadoMorador').value);
}

function cpfValid(value) {
    const cpf = String(value || '').replace(/\D/g, '');
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (let position = 9; position < 11; position += 1) {
        let sum = 0;
        for (let index = 0; index < position; index += 1) sum += Number(cpf[index]) * ((position + 1) - index);
        const digit = (sum % 11) < 2 ? 0 : 11 - (sum % 11);
        if (Number(cpf[position]) !== digit) return false;
    }
    return true;
}

function maskDocumentInput() {
    const type = byId('convidadoTipoDocumento')?.value || 'CPF';
    const input = byId('convidadoDocumento');
    if (!input) return;
    if (type === 'CPF') {
        let value = input.value.replace(/\D/g, '');
        value = value.slice(0, 11);
        if (value.length > 9) value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (value.length > 6) value = value.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (value.length > 3) value = value.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        input.value = value;
    } else {
        let value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 9);
        if (value.length > 8) value = value.replace(/^([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{3})([A-Z0-9])$/, '$1.$2.$3-$4');
        else if (value.length > 5) value = value.replace(/^([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{1,3})$/, '$1.$2.$3');
        else if (value.length > 2) value = value.replace(/^([A-Z0-9]{2})([A-Z0-9]{1,3})$/, '$1.$2');
        input.value = value;
    }
}

function normalizeDocument(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function resetDocumentState() {
    state.documentSearchToken += 1;
    const name = byId('convidadoNome');
    const visitorId = byId('convidadoVisitanteId');
    const feedback = byId('convidadoDocumentoFeedback');
    if (visitorId) visitorId.value = '';
    if (name) { name.value = ''; name.readOnly = true; }
    if (feedback) { feedback.textContent = ''; feedback.hidden = true; feedback.className = 'guest-document-feedback'; }
}

async function searchVisitor() {
    const root = state.root;
    const input = byId('convidadoDocumento');
    const button = byId('btnBuscarConvidadoDocumento');
    const type = byId('convidadoTipoDocumento')?.value || 'CPF';
    const documentValue = input?.value.trim() || '';
    if (!documentValue) {
        showFeedback('warning', 'Informe o número do documento para buscar.');
        input?.focus();
        return;
    }
    if (type === 'CPF' && !cpfValid(documentValue)) {
        showFeedback('error', 'CPF inválido. Confira os dígitos informados.');
        input?.focus();
        return;
    }
    const requestToken = ++state.documentSearchToken;
    const isCurrent = () => isActiveRoot(root) && requestToken === state.documentSearchToken
        && byId('convidadoTipoDocumento')?.value === type
        && normalizeDocument(byId('convidadoDocumento')?.value || '') === normalizeDocument(documentValue);
    if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando'; }
    try {
        const query = new URLSearchParams({ documento: documentValue, tipo_documento: type });
        const data = await requestJson(`${API_VISITANTES}?${query.toString()}`);
        if (!isCurrent()) return;
        const visitor = data.sucesso ? (data.dados?.visitante || data.dados) : null;
        const feedback = byId('convidadoDocumentoFeedback');
        const name = byId('convidadoNome');
        const visitorId = byId('convidadoVisitanteId');
        if (visitor && (visitor.id || visitor.visitor_id)
            && String(visitor.tipo_documento || '').toUpperCase() === type
            && normalizeDocument(visitor.documento || '') === normalizeDocument(documentValue)) {
            if (name) { name.value = visitor.nome_completo || visitor.nome || ''; name.readOnly = true; }
            if (visitorId) visitorId.value = visitor.id || visitor.visitor_id;
            if (feedback) {
                feedback.className = 'guest-document-feedback found';
                feedback.innerHTML = `<i class="fas fa-check-circle"></i> Cadastro de visitante encontrado: <strong>${esc(visitor.nome_completo || visitor.nome || 'Visitante')}</strong>.`;
                feedback.hidden = false;
            }
        } else {
            if (name) { name.value = ''; name.readOnly = false; }
            if (visitorId) visitorId.value = '';
            if (feedback) {
                feedback.className = 'guest-document-feedback pending';
                feedback.innerHTML = '<i class="fas fa-info-circle"></i> Visitante ainda não cadastrado — o cadastro completo (com anexo, se exigido) será feito na chegada.';
                feedback.hidden = false;
            }
            showFeedback('warning', 'Visitante não encontrado. Informe o nome manualmente.');
            name?.focus();
        }
    } catch (error) {
        if (!isCurrent()) return;
        console.error('[RegistroConvidados] Falha ao buscar visitante:', error);
        showFeedback('error', error?.message || 'Não foi possível buscar o visitante.');
    } finally {
        if (isCurrent() && button?.isConnected) { button.disabled = false; button.innerHTML = '<i class="fas fa-search"></i> Buscar'; }
    }
}

function modalityPayload(value) {
    if (value === 'INDETERMINADO') return { modalidade_entradas: 'INDETERMINADO', limite_entradas: null };
    if (value === 'UNICO') return { modalidade_entradas: 'UNICO', limite_entradas: 1 };
    const limit = Number(String(value || '').replace('MULTIPLO_', ''));
    return { modalidade_entradas: 'MULTIPLO', limite_entradas: [2, 4, 6, 8, 10].includes(limit) ? limit : 2 };
}

function collectFormPayload() {
    const modality = modalityPayload(byId('convidadoModalidade')?.value || 'UNICO');
    return {
        unidade: byId('convidadoUnidade')?.value || '',
        morador_id: Number(byId('convidadoMorador')?.value || 0),
        anfitriao_tipo: selectedHostType(),
        dependente_id: selectedHostType() === 'DEPENDENTE' ? Number(byId('convidadoDependente')?.value || 0) : null,
        visitante_id: Number(byId('convidadoVisitanteId')?.value || 0) || null,
        tipo_documento: byId('convidadoTipoDocumento')?.value || 'CPF',
        documento: byId('convidadoDocumento')?.value.trim() || '',
        nome: byId('convidadoNome')?.value.trim() || '',
        tipo_convidado: byId('convidadoTipo')?.value || '',
        data_prevista_chegada: byId('convidadoData')?.value || '',
        dias_permanencia: Number(byId('convidadoDias')?.value || 1),
        ...modality,
        origem_cadastro: byId('convidadoOrigem')?.value || '',
        observacao: byId('convidadoObservacao')?.value.trim() || '',
        idempotency_key: byId('convidadoIdempotencyKey')?.value || generateKey()
    };
}

function setFormValue(id, value) {
    const element = byId(id);
    if (element) element.value = value ?? '';
}

function ensureOption(select, value, label = value) {
    if (!select || value === null || typeof value === 'undefined' || value === '') return;
    if (![...select.options].some(option => String(option.value) === String(value))) select.add(new Option(label, value));
    select.value = String(value);
}

function resetForm() {
    const form = byId('formConvidado');
    if (form) form.reset();
    state.editingId = null;
    const title = byId('convidadoModalTitle');
    if (title) title.textContent = 'Novo Convidado';
    setFormValue('convidadoData', state.selectedDate || todaySaoPaulo());
    setFormValue('convidadoDias', 1);
    setFormValue('convidadoId', '');
    resetMoradorSelect();
    resetDocumentState();
    updateHostTypeUI();
    renewIdempotencyKey();
    showFeedback('', '');
}

async function openNewGuest() {
    if (!hasPermission('criar')) return;
    resetForm();
    openModal('modalConvidado');
    byId('convidadoUnidade')?.focus();
}

async function loadDetail(id) {
    const root = state.root;
    const data = await requestJson(`${API_CONVIDADOS}?acao=obter&id=${encodeURIComponent(id)}`);
    throwIfRootStale(root);
    if (!data.sucesso) throw new Error(data.mensagem || 'Não foi possível carregar o convidado.');
    return { guest: data.dados?.convidado || data.dados || {}, movements: data.dados?.movimentacoes || [] };
}

async function openEditGuest(id) {
    if (!hasPermission('editar')) return;
    const root = state.root;
    try {
        const detail = await loadDetail(id);
        throwIfRootStale(root);
        const guest = detail.guest;
        if (String(guest.status || '').toUpperCase() !== 'PREVISTO') {
            showFeedback('warning', 'Somente convidados previstos podem ser editados.');
            return;
        }
        resetForm();
        state.editingId = Number(id);
        const title = byId('convidadoModalTitle');
        if (title) title.textContent = 'Editar Convidado';
        const unit = byId('convidadoUnidade');
        ensureOption(unit, guest.unidade, guest.unidade);
        setFormValue('convidadoUnidade', guest.unidade || '');
        await loadMoradores(guest.unidade || '', guest.morador_id);
        throwIfRootStale(root);
        const hostRadio = state.root?.querySelector(`input[name="convidado_anfitriao_tipo"][value="${String(guest.anfitriao_tipo || 'MORADOR').toUpperCase()}"]`);
        if (hostRadio) hostRadio.checked = true;
        updateHostTypeUI();
        await loadDependentes(guest.morador_id, guest.dependente_id);
        throwIfRootStale(root);
        ensureOption(byId('convidadoMorador'), guest.morador_id, guest.morador_nome || guest.morador_id);
        setFormValue('convidadoMorador', guest.morador_id || '');
        setFormValue('convidadoDependente', guest.dependente_id || '');
        setFormValue('convidadoTipoDocumento', guest.tipo_documento || 'CPF');
        setFormValue('convidadoDocumento', guest.documento_completo || guest.documento || '');
        setFormValue('convidadoNome', guest.nome || guest.nome_completo || '');
        setFormValue('convidadoTipo', String(guest.tipo_convidado || '').toUpperCase());
        setFormValue('convidadoData', guest.data_prevista_chegada || state.selectedDate);
        setFormValue('convidadoDias', guest.dias_permanencia || 1);
        const limit = guest.limite_entradas === null || typeof guest.limite_entradas === 'undefined' ? 'INDETERMINADO' : Number(guest.limite_entradas) === 1 ? 'UNICO' : `MULTIPLO_${guest.limite_entradas}`;
        setFormValue('convidadoModalidade', limit);
        setFormValue('convidadoOrigem', guest.origem_cadastro || 'ADMINISTRATIVO');
        setFormValue('convidadoObservacao', guest.observacao || '');
        setFormValue('convidadoId', id);
        setFormValue('convidadoVisitanteId', guest.visitante_id || '');
        const name = byId('convidadoNome');
        if (name) name.readOnly = false;
        openModal('modalConvidado');
    } catch (error) {
        if (error?.name === 'AbortError' || !isActiveRoot(root)) return;
        console.error('[RegistroConvidados] Falha ao abrir edição:', error);
        showFeedback('error', error?.message || 'Não foi possível abrir o convidado.');
    }
}

function openModal(id) {
    const modal = byId(id);
    if (!modal) return;
    modal.classList.add('show');
    modal.hidden = false;
    const focusable = modal.querySelector('input, select, textarea, button');
    focusable?.focus();
}

function closeModal(id) {
    const modal = byId(id);
    if (!modal) return;
    modal.classList.remove('show');
    modal.hidden = true;
}

function closeAllModals() {
    state.root?.querySelectorAll('.guest-modal').forEach(modal => {
        modal.classList.remove('show');
        modal.hidden = true;
    });
}

function setFormBusy(busy) {
    const form = byId('formConvidado');
    if (!form) return;
    form.querySelectorAll('button').forEach(element => { element.disabled = busy; });
}

async function submitGuest(event) {
    event.preventDefault();
    const root = state.root;
    const form = byId('formConvidado');
    if (!form || !form.checkValidity()) {
        form?.reportValidity();
        return;
    }
    const payload = collectFormPayload();
    const today = todaySaoPaulo();
    if (payload.data_prevista_chegada < today && !state.editingId) {
        showFeedback('error', 'A data prevista de chegada não pode ser anterior a hoje.');
        return;
    }
    if (payload.dias_permanencia < 1 || payload.dias_permanencia > 365) {
        showFeedback('error', 'Os dias de permanência devem ficar entre 1 e 365.');
        return;
    }
    if (!payload.morador_id || (payload.anfitriao_tipo === 'DEPENDENTE' && !payload.dependente_id)) {
        showFeedback('error', 'Selecione o anfitrião e, se aplicável, o dependente.');
        return;
    }
    setFormBusy(true);
    try {
        const editing = Number.isFinite(state.editingId) && state.editingId > 0;
        if (!editing) {
            const preview = await requestJson(API_CONVIDADOS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'verificar_alertas', ...payload })
            });
            throwIfRootStale(root);
            const dadosPreview = preview.dados || {};
            const alertas = Array.isArray(dadosPreview.alertas) ? dadosPreview.alertas : [];
            let mensagem = '';
            if (dadosPreview.verificacao_disponivel === false) {
                mensagem = `${preview.mensagem || 'Não foi possível verificar os alertas.'}\n\nA portaria revalidará as regras no momento da entrada. Deseja continuar com o convite?`;
            } else if (alertas.length) {
                const resumo = alertas.map(alerta => `• ${alerta.titulo} (${alerta.nivel} — ${alerta.acao})`).join('\n');
                mensagem = `Há alerta(s) potencialmente conflitante(s) para a data prevista ${payload.data_prevista_chegada}:\n\n${resumo}\n\nA portaria verificará novamente no horário real. Deseja continuar com o convite?`;
            }
            if (mensagem && !window.confirm(mensagem)) return;
        }
        const body = editing ? { acao: 'editar', id: state.editingId, ...payload } : { acao: 'criar', ...payload };
        const data = await requestJson(API_CONVIDADOS, {
            method: editing ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        throwIfRootStale(root);
        if (!data.sucesso) throw new Error(data.mensagem || 'Não foi possível salvar o convidado.');
        closeModal('modalConvidado');
        showFeedback('success', editing ? 'Convidado atualizado com sucesso.' : 'Convidado criado com sucesso.');
        renewIdempotencyKey();
        state.guests = [];
        scheduleList(true);
    } catch (error) {
        if (error?.name === 'AbortError' || !isActiveRoot(root)) return;
        console.error('[RegistroConvidados] Falha ao salvar convidado:', error);
        showFeedback('error', error?.message || 'Não foi possível salvar o convidado.');
    } finally {
        if (isActiveRoot(root)) setFormBusy(false);
    }
}

async function openCancelGuest(id) {
    if (!hasPermission('excluir')) return;
    const guest = state.guests.find(item => Number(item.id) === Number(id));
    setFormValue('convidadoCancelId', id);
    const label = byId('convidadoCancelNome');
    if (label) label.textContent = guest?.nome || `#${id}`;
    setFormValue('convidadoCancelMotivo', '');
    openModal('modalCancelarConvidado');
}

async function submitCancel(event) {
    event.preventDefault();
    const root = state.root;
    const id = byId('convidadoCancelId')?.value || '';
    const reason = byId('convidadoCancelMotivo')?.value.trim() || '';
    if (!id || !reason) {
        byId('convidadoCancelMotivo')?.focus();
        return;
    }
    if (!window.confirm('Confirma o cancelamento deste convidado?')) return;
    const button = byId('btnConfirmarCancelamento');
    if (button) button.disabled = true;
    try {
        const data = await requestJson(API_CONVIDADOS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'cancelar', id: Number(id), motivo: reason })
        });
        throwIfRootStale(root);
        if (!data.sucesso) throw new Error(data.mensagem || 'Não foi possível cancelar o convidado.');
        closeModal('modalCancelarConvidado');
        showFeedback('success', 'Convidado cancelado com sucesso.');
        state.guests = [];
        scheduleList(true);
    } catch (error) {
        if (error?.name === 'AbortError' || !isActiveRoot(root)) return;
        console.error('[RegistroConvidados] Falha ao cancelar convidado:', error);
        showFeedback('error', error?.message || 'Não foi possível cancelar o convidado.');
    } finally {
        if (isActiveRoot(root) && button?.isConnected) button.disabled = false;
    }
}

function renderHistory(guest, movements) {
    const content = byId('convidadoHistoryContent');
    const title = byId('convidadoHistoryTitle');
    if (title) title.textContent = `Histórico — ${guest?.nome || 'Convidado'}`;
    if (!content) return;
    if (!Array.isArray(movements) || !movements.length) {
        content.innerHTML = '<div class="guest-history-empty"><i class="fas fa-history"></i><p>Nenhuma movimentação registrada.</p></div>';
        return;
    }
    content.innerHTML = `<div class="guest-history-list">${movements.map(movement => {
        const access = movement.tipo_acesso || movement.acao || movement.tipo || 'Movimentação';
        return `<div class="guest-history-item"><div class="guest-history-icon"><i class="fas ${String(access).toLowerCase().includes('sa') ? 'fa-sign-out-alt' : 'fa-sign-in-alt'}"></i></div><div><strong>${esc(access)}</strong><span>${esc(formatDateTime(movement.data_hora || movement.criado_em || movement.data))}</span><small>${esc(movement.usuario_liberou || movement.usuario_nome || movement.observacao || 'Registro Manual')}</small></div></div>`;
    }).join('')}</div>`;
}

async function openHistoryGuest(id) {
    const root = state.root;
    try {
        const detail = await loadDetail(id);
        throwIfRootStale(root);
        renderHistory(detail.guest, detail.movements);
        openModal('modalHistoricoConvidado');
    } catch (error) {
        if (error?.name === 'AbortError' || !isActiveRoot(root)) return;
        console.error('[RegistroConvidados] Falha ao carregar histórico:', error);
        showFeedback('error', error?.message || 'Não foi possível carregar o histórico.');
    }
}

async function startGuestMovement(id, typeAccess) {
    const root = state.root;
    try {
        const detail = await loadDetail(id);
        throwIfRootStale(root);
        activateTab('manual');
        const registrar = window.RegistroPage;
        if (registrar && typeof registrar.iniciarConvidado === 'function') {
            const result = registrar.iniciarConvidado(detail.guest, typeAccess);
            if (result && typeof result.catch === 'function') result.catch(error => console.error('[RegistroConvidados] Falha ao iniciar movimentação:', error));
            return;
        }
        const message = 'Registro Manual não carregado. Reabra a página e tente novamente.';
        showManualFeedback('warning', message);
        showFeedback('warning', message);
    } catch (error) {
        if (error?.name === 'AbortError' || !isActiveRoot(root)) return;
        console.error('[RegistroConvidados] Falha ao preparar movimentação:', error);
        showFeedback('error', error?.message || 'Não foi possível preparar a movimentação.');
    }
}

function onRootClick(event) {
    const tab = event.target.closest('.tab-button[data-tab]');
    if (tab && state.root.contains(tab)) {
        event.preventDefault();
        activateTab(tab.dataset.tab);
        return;
    }
    const summaryCard = event.target.closest('[data-summary-status]');
    if (summaryCard && state.root.contains(summaryCard)) {
        const status = summaryCard.dataset.summaryStatus || '';
        const select = byId('filtroConvidadosStatus');
        if (select) select.value = status;
        state.filters.status = status;
        scheduleList(true);
        return;
    }
    const action = event.target.closest('[data-guest-action]');
    if (action && state.root.contains(action)) {
        const type = action.dataset.guestAction;
        const id = Number(action.dataset.id);
        if (!Number.isFinite(id)) return;
        if (type === 'new') void openNewGuest();
        if (type === 'entry') void startGuestMovement(id, 'Entrada');
        if (type === 'exit') void startGuestMovement(id, 'Saída');
        if (type === 'edit') void openEditGuest(id);
        if (type === 'cancel') void openCancelGuest(id);
        if (type === 'history') void openHistoryGuest(id);
        if (type === 'search-document') void searchVisitor();
        if (type === 'prev-date') setDate(shiftDate(state.selectedDate, -1));
        if (type === 'next-date') setDate(shiftDate(state.selectedDate, 1));
        if (type === 'today-date') setDate(todaySaoPaulo());
        if (type === 'refresh-list') scheduleList(true);
        return;
    }
    const closeButton = event.target.closest('[data-modal-close]');
    if (closeButton && state.root.contains(closeButton)) {
        closeModal(closeButton.dataset.modalClose);
        return;
    }
    if (event.target.classList.contains('guest-modal')) closeModal(event.target.id);
}

function onDocumentKeydown(event) {
    if (!state.root || !document.body.contains(state.root)) return;
    if (event.key === 'Escape') {
        closeAllModals();
        return;
    }
    const target = event.target;
    if (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(target?.tagName) || target?.isContentEditable) return;
    if (event.key === 'ArrowLeft') setDate(shiftDate(state.selectedDate, -1));
    if (event.key === 'ArrowRight') setDate(shiftDate(state.selectedDate, 1));
}

function setupEvents() {
    listen(state.root, 'click', onRootClick);
    listen(document, 'keydown', onDocumentKeydown);
    listen(byId('convidadosData'), 'change', event => setDate(event.target.value));
    listen(state.root?.querySelector('.guest-date-display'), 'click', () => {
        const input = byId('convidadosData');
        if (input?.showPicker) {
            try { input.showPicker(); } catch (_) { /* seletor nativo bloqueado pelo navegador */ }
        }
    });
    listen(byId('filtroConvidadosUnidade'), 'change', event => { state.filters.unidade = event.target.value; scheduleList(); });
    listen(byId('filtroConvidadosTipo'), 'change', event => { state.filters.tipo = event.target.value; scheduleList(); });
    listen(byId('filtroConvidadosStatus'), 'change', event => { state.filters.status = event.target.value; scheduleList(); });
    listen(byId('filtroConvidadosBusca'), 'input', event => { state.filters.busca = event.target.value; scheduleList(); });
    listen(byId('formConvidado'), 'submit', submitGuest);
    listen(byId('formCancelarConvidado'), 'submit', submitCancel);
    listen(byId('convidadoUnidade'), 'change', event => { void loadMoradores(event.target.value); });
    listen(byId('convidadoMorador'), 'change', event => { void loadDependentes(event.target.value); });
    listen(byId('convidadoTipoDocumento'), 'change', () => { setFormValue('convidadoDocumento', ''); resetDocumentState(); renewIdempotencyKey(); });
    listen(byId('convidadoDocumento'), 'input', () => { maskDocumentInput(); resetDocumentState(); renewIdempotencyKey(); });
    listen(byId('convidadoDocumento'), 'keydown', event => { if (event.key === 'Enter') { event.preventDefault(); void searchVisitor(); } });
    listen(byId('formConvidado'), 'input', renewIdempotencyKey);
    listen(byId('formConvidado'), 'change', renewIdempotencyKey);
    state.root.querySelectorAll('input[name="convidado_anfitriao_tipo"]').forEach(input => listen(input, 'change', updateHostTypeUI));
}

export function init() {
    if (state.initialized) return;
    state.root = document.querySelector('.page-registro');
    if (!state.root) return;
    state.initialized = true;
    state.selectedDate = todaySaoPaulo();
    state.activeTab = readStoredTab();
    state.permissionsReady = false;
    syncPermissionVisibility();
    renderDateNavigation();
    activateTab(state.activeTab, false);
    setupEvents();
    state.unitsPromise = loadUnits();
    scheduleList(true);
    const rootAtInit = state.root;
    if (window.PermissoesModulos?.carregarPermissoes) {
        window.PermissoesModulos.carregarPermissoes(() => {
            if (state.root !== rootAtInit || !rootAtInit?.isConnected) return;
            state.permissionsReady = true;
            syncPermissionVisibility();
            scheduleList(true);
        });
    } else if (typeof window.rbacPode === 'function') {
        state.permissionsReady = true;
        syncPermissionVisibility();
        scheduleList(true);
    }
    state.urlUpdateTimer = window.setTimeout(() => {
        state.urlUpdateTimer = null;
        if (isActiveRoot(rootAtInit)) updateUrlForTab(state.activeTab);
    }, 0);
    console.log('[RegistroConvidados] Módulo pronto.');
}

export function destroy() {
    if (state.listTimer) clearTimeout(state.listTimer);
    if (state.urlUpdateTimer) clearTimeout(state.urlUpdateTimer);
    if (state.listController) state.listController.abort();
    state.cascadeToken += 1;
    state.documentSearchToken += 1;
    state.listeners.forEach(({ target, eventName, handler, options }) => target.removeEventListener(eventName, handler, options));
    state.listeners = [];
    closeAllModals();
    state.root = null;
    state.guests = [];
    state.summary = {};
    state.filters = { unidade: '', tipo: '', status: '', busca: '' };
    state.permissionsReady = false;
    state.unitsPromise = null;
    state.initialized = false;
}
