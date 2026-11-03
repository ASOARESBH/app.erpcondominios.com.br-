const API_ALERTAS = '../api/api_alertas_unidade.php';
const PAGE_SIZE = 10;
const unitCollator = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });

function compareUnits(a, b) {
    return unitCollator.compare(String(a ?? ''), String(b ?? ''));
}

const LABELS = {
    nivel: {
        INFORMATIVO: 'Informativo',
        ATENCAO: 'Atenção',
        CRITICO: 'Crítico',
    },
    acao: {
        APENAS_AVISAR: 'Apenas avisar',
        EXIGIR_CIENTE: 'Exigir ciência',
        CONTATAR_MORADOR: 'Contatar morador',
        BLOQUEAR: 'Bloquear',
    },
    publico: {
        TODOS: 'Todos',
        VISITANTE: 'Visitante',
        PRESTADOR: 'Prestador',
        ENTREGADOR_AUTORIZADO: 'Entregador autorizado',
        FAMILIAR: 'Familiar',
        VEICULO: 'Veículo',
    },
    abrangencia: {
        TODOS_DO_TIPO: 'Todos do tipo',
        ESPECIFICO: 'Específico',
    },
    status: {
        ATIVO: 'Ativo',
        AGENDADO: 'Agendado',
        PAUSADO: 'Pausado',
        CANCELADO: 'Cancelado',
        EXPIRADO: 'Expirado',
        ENCERRADO: 'Encerrado',
    },
    origem: {
        PAINEL: 'Painel',
        REGISTRO: 'Registro',
        AUTOMATICO: 'Automático',
        INTEGRACAO: 'Integração',
    },
};

const state = {
    initialized: false,
    root: null,
    listeners: [],
    controllers: new Set(),
    listController: null,
    page: 1,
    total: 0,
    pageSize: PAGE_SIZE,
    itens: [],
    options: { unidades: [], moradores: [], dependentes: [] },
    optionsLoaded: false,
    pendingSavedUnit: '',
    loaded: false,
    permissionsKnown: false,
    permissions: null,
    editingId: null,
    targets: [],
    lastFocus: null,
    targetSearchTimer: null,
};

function byId(id) {
    return document.getElementById(id);
}

function bind(target, event, handler, options) {
    if (!target) return;
    target.addEventListener(event, handler, options);
    state.listeners.push(() => target.removeEventListener(event, handler, options));
}

function setText(target, value) {
    const element = typeof target === 'string' ? byId(target) : target;
    if (element) element.textContent = value == null || value === '' ? '—' : String(value);
}

function createElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined && text !== null) element.textContent = String(text);
    return element;
}

function createIconButton(action, id, icon, label, permission) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `action-btn alertas-action-${action}`;
    button.dataset.alertaAction = action;
    button.dataset.alertaId = String(id);
    button.title = label;
    button.setAttribute('aria-label', label);
    if (permission) button.dataset.alertaPerm = permission;
    const iconElement = document.createElement('i');
    iconElement.className = `fas ${icon}`;
    iconElement.setAttribute('aria-hidden', 'true');
    button.appendChild(iconElement);
    return button;
}

function normalizeBool(value) {
    return value === true || value === 1 || value === '1' || String(value).toLowerCase() === 'true' || String(value).toLowerCase() === 'sim';
}

function normalizePermissions(perms) {
    const result = { visualizar: false, criar: false, editar: false, excluir: false, exportar: false };
    if (!perms || typeof perms !== 'object') return { known: false, values: result };

    const sources = [
        perms.moradores,
        perms.permissoes?.moradores,
        perms.modulos?.moradores,
    ];
    let source = sources.find(value => value && typeof value === 'object');
    // Só aplicar o bypass administrativo quando o payload também expõe o
    // domínio novo; o gerenciador legado pode usar is_admin=true como fallback
    // após erro de rede, e esse caso não deve liberar mutações na UI.
    if (!source && normalizeBool(perms.is_admin)) return { known: false, values: result };
    if (!source && (Object.prototype.hasOwnProperty.call(perms, 'visualizar') || Object.prototype.hasOwnProperty.call(perms, 'pode_visualizar') || Object.prototype.hasOwnProperty.call(perms, 'pode_acessar'))) {
        source = perms;
    }
    if (!source) return { known: false, values: result };

    const aliases = {
        visualizar: ['visualizar', 'pode_visualizar', 'pode_acessar'],
        criar: ['criar', 'pode_criar'],
        editar: ['editar', 'pode_editar'],
        excluir: ['excluir', 'pode_excluir'],
        exportar: ['exportar', 'pode_exportar'],
    };
    Object.entries(aliases).forEach(([action, keys]) => {
        const key = keys.find(candidate => Object.prototype.hasOwnProperty.call(source, candidate));
        result[action] = key ? normalizeBool(source[key]) : false;
    });
    return { known: true, values: result };
}

function hasPermission(action) {
    if (state.permissionsKnown && normalizeBool(state.permissions?.is_admin)) return true;
    // Sem o objeto específico, não se presume autorização para mutações.
    if (!state.permissionsKnown) return action === 'visualizar';
    return Boolean(state.permissions?.values?.[action]);
}

function applyPermissionVisibility() {
    if (!state.root) return;
    state.root.querySelectorAll('[data-alerta-perm]').forEach(element => {
        const permission = element.id === 'alertas-form-submit'
            ? (state.editingId ? 'editar' : 'criar')
            : element.dataset.alertaPerm;
        const allowed = hasPermission(permission);
        element.hidden = !allowed;
        if ('disabled' in element) element.disabled = !allowed;
        element.setAttribute('aria-hidden', allowed ? 'false' : 'true');
    });
    const form = byId('alertas-form');
    if (form) form.hidden = !hasPermission(state.editingId ? 'editar' : 'criar');
}

function showFeedback(type, message) {
    const box = byId('alertas-feedback');
    if (!box) return;
    box.className = `alertas-feedback ${type || 'info'}`;
    box.textContent = message || '';
    box.hidden = !message;
}

function showState(name, visible) {
    const element = byId(`alertas-${name}`);
    if (element) element.hidden = !visible;
}

function labelFor(group, value) {
    if (value == null || value === '') return '—';
    return LABELS[group]?.[String(value)] || String(value).replace(/_/g, ' ');
}

function formatDate(value, withTime = true) {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return raw;
    try {
        return new Intl.DateTimeFormat('pt-BR', withTime ? { dateStyle: 'short', timeStyle: 'short' } : { dateStyle: 'short' }).format(date);
    } catch (_) {
        return raw;
    }
}

function formatDateTimeInput(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value).slice(0, 16);
    const pad = number => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function applyQuickModel(model) {
    const modelos = {
        temporario: { titulo: 'Autorização temporária', mensagem: 'Validar identidade e período autorizado antes de liberar o acesso.', nivel: 'ATENCAO', acao: 'EXIGIR_CIENTE', publico: 'VISITANTE', abrangencia: 'TODOS_DO_TIPO', instrucao: 'Conferir documento e vigência.' },
        restricao: { titulo: 'Restrição de acesso específica', mensagem: 'Confirmar a identidade do alvo e consultar a orientação antes de liberar.', nivel: 'CRITICO', acao: 'BLOQUEAR', publico: 'VISITANTE', abrangencia: 'ESPECIFICO', instrucao: 'Não liberar sem autorização do responsável.' },
        entrega: { titulo: 'Entrega autorizada', mensagem: 'Avisar o morador sobre a chegada da entrega e registrar a decisão da portaria.', nivel: 'INFORMATIVO', acao: 'APENAS_AVISAR', publico: 'ENTREGADOR_AUTORIZADO', abrangencia: 'TODOS_DO_TIPO', instrucao: 'Confirmar unidade de destino.' },
        contato: { titulo: 'Contatar morador antes da liberação', mensagem: 'A portaria deve confirmar a autorização com o morador antes de liberar.', nivel: 'ATENCAO', acao: 'CONTATAR_MORADOR', publico: 'VISITANTE', abrangencia: 'TODOS_DO_TIPO', instrucao: 'Ligar para o telefone cadastrado.' },
        critico: { titulo: 'Alerta crítico de acesso', mensagem: 'Não liberar sem validação e autorização expressa do responsável.', nivel: 'CRITICO', acao: 'BLOQUEAR', publico: 'VISITANTE', abrangencia: 'TODOS_DO_TIPO', instrucao: 'Escalar divergências ao supervisor.' },
    };
    const dados = modelos[model];
    if (!dados) return;
    setFormValue('alertas-form-titulo', dados.titulo);
    setFormValue('alertas-form-mensagem', dados.mensagem);
    setFormValue('alertas-form-nivel', dados.nivel);
    setFormValue('alertas-form-acao', dados.acao);
    setFormValue('alertas-form-publico', dados.publico);
    setFormValue('alertas-form-abrangencia', dados.abrangencia);
    setFormValue('alertas-form-instrucao', dados.instrucao);
    const inicio = new Date();
    inicio.setMinutes(0, 0, 0);
    const fim = new Date(inicio);
    fim.setDate(fim.getDate() + 7);
    setFormValue('alertas-form-inicio', formatDateTimeInput(inicio.toISOString()));
    setFormValue('alertas-form-fim', formatDateTimeInput(fim.toISOString()));
    setFormValue('alertas-form-hora-inicio', '');
    setFormValue('alertas-form-hora-fim', '');
    document.querySelectorAll('#alertas-form-dias input[type="checkbox"]').forEach(input => { input.checked = false; });
    const targets = byId('alertas-targets-wrap');
    if (targets) targets.hidden = dados.abrangencia !== 'ESPECIFICO';
    updateFormPreview();
}

function updateFormPreview() {
    const preview = byId('alertas-form-preview');
    if (!preview) return;
    const value = id => byId(id)?.value?.trim() || '';
    const unidadeSelect = byId('alertas-form-unidade');
    const unidade = unidadeSelect?.selectedOptions?.[0]?.textContent?.trim() || value('alertas-form-unidade') || 'unidade selecionada';
    const inicio = value('alertas-form-inicio');
    const fim = value('alertas-form-fim');
    const publico = LABELS.publico[value('alertas-form-publico')] || 'público selecionado';
    const acao = LABELS.acao[value('alertas-form-acao')] || 'ação selecionada';
    const alvo = value('alertas-form-abrangencia') === 'ESPECIFICO' ? 'alvo(s) específico(s)' : `todos: ${publico}`;
    preview.textContent = inicio
        ? `${value('alertas-form-titulo') || 'Alerta sem título'} — ${acao} para ${alvo} da ${unidade}, de ${formatDate(inicio, false)} a ${fim ? formatDate(fim, false) : 'sem data final'}. Revise os dados antes de salvar.`
        : 'Preencha o formulário para visualizar o resumo operacional.';
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

function unwrap(data) {
    if (data && data.sucesso === true && Object.prototype.hasOwnProperty.call(data, 'dados')) return data.dados;
    return data;
}

async function requestJson(action, { params = {}, method = 'GET', body, controller } = {}) {
    const query = new URLSearchParams();
    query.set('acao', action);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
    });
    const requestController = controller || new AbortController();
    state.controllers.add(requestController);
    try {
        const response = await fetch(`${API_ALERTAS}?${query.toString()}`, {
            method,
            credentials: 'include',
            cache: 'no-store',
            signal: requestController.signal,
            headers: body ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
        });
        const text = await response.text();
        let data;
        try { data = text ? JSON.parse(text) : null; } catch (_) { data = null; }
        if (!data || typeof data !== 'object') throw new Error(`A API não retornou JSON válido (HTTP ${response.status}).`);
        if (!response.ok || data.sucesso === false) throw new Error(data.mensagem || `Falha HTTP ${response.status}.`);
        return data;
    } finally {
        state.controllers.delete(requestController);
    }
}

function populateSelect(select, items, placeholder, getValue, getLabel) {
    if (!select) return;
    const previous = select.value;
    select.replaceChildren();
    const first = createElement('option', '', placeholder);
    first.value = '';
    select.appendChild(first);
    (Array.isArray(items) ? items : []).forEach(item => {
        const option = createElement('option', '', getLabel(item));
        option.value = String(getValue(item));
        select.appendChild(option);
    });
    if (previous && Array.from(select.options).some(option => option.value === previous)) select.value = previous;
}

function optionValue(item, fallback = '') {
    if (item == null) return fallback;
    if (typeof item !== 'object') return item;
    return item.id ?? item.id_unidade ?? item.id_morador ?? item.id_dependente ?? item.valor ?? item.nome ?? fallback;
}

function optionLabel(item, fallback = '') {
    if (item == null) return fallback;
    if (typeof item !== 'object') return item;
    return item.nome ?? item.nome_completo ?? item.descricao ?? item.label ?? item.unidade ?? String(optionValue(item, fallback));
}

function normalizeUnit(value) {
    return String(value ?? '').trim().toUpperCase();
}

function moradorOwnerId(item) {
    const id = item?.morador_id ?? item?.id_morador ?? item?.titular_id;
    return id == null || id === '' ? null : String(id);
}

function moradoresDaUnidade(unidade) {
    const unidadeNormalizada = normalizeUnit(unidade);
    if (!unidadeNormalizada) return [];
    return state.options.moradores.filter(morador => normalizeUnit(morador?.unidade) === unidadeNormalizada);
}

function populateOptions() {
    populateSelect(byId('alertas-filtro-unidade'), state.options.unidades, 'Todas as unidades', optionValue, optionLabel);
    const filtroUnidade = byId('alertas-filtro-unidade');
    if (state.pendingSavedUnit && filtroUnidade && Array.from(filtroUnidade.options).some(option => option.value === state.pendingSavedUnit)) filtroUnidade.value = state.pendingSavedUnit;
    state.pendingSavedUnit = '';
    populateSelect(byId('alertas-form-unidade'), state.options.unidades, 'Selecione a unidade', optionValue, optionLabel);
    updateMoradorOptions();
}

function updateMoradorOptions() {
    const select = byId('alertas-form-morador');
    if (!select) return;
    const unidade = byId('alertas-form-unidade')?.value || '';
    if (!unidade) {
        populateSelect(select, [], 'Selecione a unidade primeiro', optionValue, optionLabel);
        select.disabled = true;
        updateDependenteOptions();
        return;
    }

    const moradores = moradoresDaUnidade(unidade);
    populateSelect(select, moradores, moradores.length ? 'Selecione o morador titular' : 'Nenhum morador ativo nesta unidade', optionValue, optionLabel);
    select.disabled = moradores.length === 0;
    if (moradores.length === 1) select.value = String(optionValue(moradores[0]));
    updateDependenteOptions();
}

function updateDependenteOptions() {
    const select = byId('alertas-form-dependente');
    if (!select) return;
    const titularTipo = byId('alertas-form-titular-tipo')?.value || 'MORADOR';
    const unidade = byId('alertas-form-unidade')?.value || '';
    const moradorId = byId('alertas-form-morador')?.value || '';
    const label = state.root?.querySelector('label[for="alertas-form-dependente"]');

    if (titularTipo !== 'DEPENDENTE') {
        populateSelect(select, [], 'Não se aplica — titular é o morador', optionValue, optionLabel);
        select.value = '';
        select.disabled = true;
        select.required = false;
        if (label) label.textContent = 'Dependente';
        return;
    }

    select.required = true;
    if (!unidade) {
        populateSelect(select, [], 'Selecione a unidade primeiro', optionValue, optionLabel);
        select.value = '';
        select.disabled = true;
        if (label) label.textContent = moradorId ? 'Dependente' : 'Dependente — Morador';
        return;
    }

    const moradores = moradoresDaUnidade(unidade);
    const moradoresDaLista = new Set(moradores.map(morador => String(optionValue(morador))));
    const dependentes = state.options.dependentes.filter(item => {
        const ownerId = moradorOwnerId(item);
        if (!ownerId || !moradoresDaLista.has(ownerId)) return false;
        return !moradorId || ownerId === String(moradorId);
    });
    const placeholder = dependentes.length
        ? 'Selecione o dependente'
        : moradorId ? 'Nenhum dependente ativo para este morador' : 'Nenhum dependente ativo nesta unidade';
    populateSelect(select, dependentes, placeholder, optionValue, item => {
        const name = optionLabel(item);
        const owner = item?.morador_nome || item?.morador || state.options.moradores.find(morador => String(optionValue(morador)) === moradorOwnerId(item))?.nome;
        return owner ? `${name} — ${owner}` : name;
    });
    select.disabled = dependentes.length === 0;
    if (label) label.textContent = moradorId ? 'Dependente' : 'Dependente — Morador';
}

function handleDependenteChange() {
    const dependenteId = byId('alertas-form-dependente')?.value || '';
    const moradorSelect = byId('alertas-form-morador');
    if (!dependenteId || !moradorSelect || moradorSelect.value) return;
    const dependente = state.options.dependentes.find(item => String(optionValue(item)) === String(dependenteId));
    const ownerId = moradorOwnerId(dependente);
    if (!ownerId || !Array.from(moradorSelect.options).some(option => option.value === ownerId)) return;
    moradorSelect.value = ownerId;
    updateDependenteOptions();
    byId('alertas-form-dependente').value = String(dependenteId);
}

async function loadOptions() {
    try {
        const data = unwrap(await requestJson('opcoes')) || {};
        state.options = {
            unidades: (Array.isArray(data.unidades) ? data.unidades.slice() : []).sort((a, b) => compareUnits(optionLabel(a), optionLabel(b))),
            moradores: Array.isArray(data.moradores) ? data.moradores : [],
            dependentes: Array.isArray(data.dependentes) ? data.dependentes : [],
        };
        if (data.permissoes && typeof data.permissoes === 'object') {
            const normalized = normalizePermissions(data.permissoes);
            state.permissionsKnown = normalized.known;
            state.permissions = { values: normalized.values };
            applyPermissionVisibility();
        }
        state.optionsLoaded = true;
        populateOptions();
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', `Não foi possível carregar as opções dos alertas: ${error.message}`);
    }
}

function setKpi(id, value) {
    const element = byId(id);
    if (element) element.textContent = value == null ? '—' : Number.isFinite(Number(value)) ? Number(value).toLocaleString('pt-BR') : String(value);
}

function getKpi(data, keys) {
    for (const key of keys) {
        if (data && data[key] !== undefined && data[key] !== null) return data[key];
    }
    return 0;
}

function renderKpis(data) {
    const kpis = data && typeof data === 'object' ? data : {};
    setKpi('alertas-kpi-total', getKpi(kpis, ['total', 'total_alertas', 'quantidade']));
    setKpi('alertas-kpi-ativos', getKpi(kpis, ['ativos', 'ativo', 'em_vigencia']));
    setKpi('alertas-kpi-criticos', getKpi(kpis, ['criticos', 'critico']));
    setKpi('alertas-kpi-pausados', getKpi(kpis, ['pausados', 'pausado']));
    setKpi('alertas-kpi-ciencia', getKpi(kpis, ['pendentes_ciencia', 'pendentes_de_ciencia', 'exigem_ciente']));
    setKpi('alertas-kpi-expiram', getKpi(kpis, ['expiram_7_dias']));
    setKpi('alertas-kpi-acionamentos', getKpi(kpis, ['acionamentos_hoje']));
    setKpi('alertas-kpi-overrides', getKpi(kpis, ['overrides_mes']));
}

function currentFilters() {
    const value = id => byId(id)?.value?.trim() || '';
    return {
        'unidade': value('alertas-filtro-unidade'),
        'nivel': value('alertas-filtro-nivel'),
        'publico': value('alertas-filtro-publico'),
        'status': value('alertas-filtro-status'),
        'origem': value('alertas-filtro-origem'),
        'busca': value('alertas-filtro-busca'),
        'data_inicio': value('alertas-filtro-data-inicio'),
        'data_fim': value('alertas-filtro-data-fim'),
    };
}

function filterStorageKey() {
    try {
        const user = window.sessionManager?.getUser?.() || {};
        const id = Number(user.usuario_id ?? user.id_usuario ?? user.id ?? user.user_id ?? 0);
        return id > 0 ? `erp:alertas-filtros:v1:${id}` : null;
    } catch (_) { return null; }
}

function restoreSavedFilters() {
    const key = filterStorageKey();
    if (!key) return;
    try {
        const saved = JSON.parse(localStorage.getItem(key) || '{}');
        const mapping = {
            unidade: 'alertas-filtro-unidade', nivel: 'alertas-filtro-nivel', publico: 'alertas-filtro-publico',
            status: 'alertas-filtro-status', origem: 'alertas-filtro-origem', busca: 'alertas-filtro-busca',
            data_inicio: 'alertas-filtro-data-inicio', data_fim: 'alertas-filtro-data-fim',
        };
        Object.entries(mapping).forEach(([field, id]) => {
            const input = byId(id);
            if (field === 'unidade') { state.pendingSavedUnit = typeof saved[field] === 'string' ? saved[field].slice(0, 120) : ''; return; }
            if (input && typeof saved[field] === 'string') input.value = saved[field].slice(0, 120);
        });
    } catch (_) { /* armazenamento bloqueado ou inválido: filtros permanecem com defaults */ }
}

function persistCurrentFilters() {
    const key = filterStorageKey();
    if (!key) return;
    try { localStorage.setItem(key, JSON.stringify(currentFilters())); }
    catch (_) { /* quota ou política do navegador: o filtro continua funcionando na tela */ }
}

function renderPagination() {
    const wrapper = byId('alertas-paginacao');
    if (!wrapper) return;
    wrapper.replaceChildren();
    if (!state.total) return;
    const totalPages = Math.max(1, Math.ceil(state.total / state.pageSize));
    const start = (state.page - 1) * state.pageSize + 1;
    const end = Math.min(state.page * state.pageSize, state.total);
    const info = createElement('span', 'pag-info', `${start}–${end} de ${state.total}`);
    wrapper.appendChild(info);
    if (totalPages <= 1) return;
    const buttons = createElement('div', 'pag-btns');
    const addPageButton = (page, text, label) => {
        const button = createElement('button', `pag-btn${page === state.page ? ' active' : ''}`, text);
        button.type = 'button';
        button.dataset.alertaPage = String(page);
        button.setAttribute('aria-label', label || `Página ${page}`);
        button.disabled = page === state.page;
        buttons.appendChild(button);
    };
    addPageButton(Math.max(1, state.page - 1), '‹', 'Página anterior');
    const first = Math.max(1, state.page - 2);
    const last = Math.min(totalPages, state.page + 2);
    for (let page = first; page <= last; page += 1) addPageButton(page, String(page));
    addPageButton(Math.min(totalPages, state.page + 1), '›', 'Próxima página');
    wrapper.appendChild(buttons);
}

function appendCell(row, value, className) {
    const cell = document.createElement('td');
    if (className) cell.className = className;
    if (value instanceof Node) cell.appendChild(value);
    else cell.textContent = value == null || value === '' ? '—' : String(value);
    row.appendChild(cell);
    return cell;
}

function statusClass(status) {
    return String(status || '').toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function appendBadge(parent, text, className) {
    const badge = createElement('span', `alertas-badge ${className || ''}`, text);
    parent.appendChild(badge);
    return badge;
}

function renderActions(parent, item) {
    const id = item.id ?? item.id_alerta;
    if (id == null) return;
    parent.appendChild(createIconButton('ver', id, 'fa-eye', 'Ver alerta', 'visualizar'));
    parent.appendChild(createIconButton('editar', id, 'fa-edit', 'Editar alerta', 'editar'));
    if (hasPermission('criar') && hasPermission('editar')) parent.appendChild(createIconButton('duplicar', id, 'fa-copy', 'Duplicar alerta', 'criar'));
    const status = String(item.status || '').toUpperCase();
    if (status === 'PAUSADO') parent.appendChild(createIconButton('retomar', id, 'fa-play', 'Retomar alerta', 'editar'));
    else if (!['CANCELADO', 'EXPIRADO', 'ENCERRADO'].includes(status)) parent.appendChild(createIconButton('pausar', id, 'fa-pause', 'Pausar alerta', 'editar'));
    if (!['CANCELADO', 'EXPIRADO', 'ENCERRADO'].includes(status)) parent.appendChild(createIconButton('cancelar', id, 'fa-ban', 'Cancelar alerta', 'excluir'));
}

function renderTableRow(item) {
    const row = document.createElement('tr');
    const id = item.id ?? item.id_alerta ?? '—';
    appendCell(row, id);
    appendCell(row, item.titulo || '—');
    appendCell(row, item.unidade || item.unidade_nome || '—');
    const levelCell = document.createElement('td');
    appendBadge(levelCell, labelFor('nivel', item.nivel), `alertas-nivel-${statusClass(item.nivel)}`);
    row.appendChild(levelCell);
    appendCell(row, labelFor('publico', item.publico));
    const statusCell = document.createElement('td');
    appendBadge(statusCell, labelFor('status', item.status), `alertas-status-${statusClass(item.status)}`);
    row.appendChild(statusCell);
    appendCell(row, formatDate(item.vigencia_inicio));
    appendCell(row, formatDate(item.vigencia_fim));
    const actions = document.createElement('td');
    actions.className = 'alertas-acoes';
    renderActions(actions, item);
    row.appendChild(actions);
    return row;
}

function renderCard(item) {
    const card = createElement('article', 'alertas-card');
    const header = createElement('div', 'alertas-card-header');
    const title = createElement('h3', '', item.titulo || 'Alerta sem título');
    header.appendChild(title);
    const badges = createElement('div', 'alertas-card-badges');
    appendBadge(badges, labelFor('nivel', item.nivel), `alertas-nivel-${statusClass(item.nivel)}`);
    appendBadge(badges, labelFor('status', item.status), `alertas-status-${statusClass(item.status)}`);
    header.appendChild(badges);
    card.appendChild(header);
    const details = createElement('dl', 'alertas-card-details');
    [['Unidade', item.unidade || item.unidade_nome], ['Público', labelFor('publico', item.publico)], ['Início', formatDate(item.vigencia_inicio)], ['Fim', formatDate(item.vigencia_fim)]].forEach(([label, value]) => {
        const term = createElement('dt', '', label);
        const description = createElement('dd', '', value || '—');
        details.append(term, description);
    });
    card.appendChild(details);
    const actions = createElement('div', 'alertas-acoes alertas-card-actions');
    renderActions(actions, item);
    card.appendChild(actions);
    return card;
}

function renderList() {
    const tbody = byId('alertas-tbody');
    const cards = byId('alertas-cards');
    if (!tbody || !cards) return;
    tbody.replaceChildren();
    cards.replaceChildren();
    if (!state.itens.length) {
        showState('empty', true);
        showState('table-container', false);
        showState('cards', false);
        renderPagination();
        return;
    }
    showState('empty', false);
    showState('table-container', true);
    showState('cards', true);
    state.itens.forEach(item => {
        tbody.appendChild(renderTableRow(item));
        cards.appendChild(renderCard(item));
    });
    renderPagination();
    applyPermissionVisibility();
}

async function loadList(page = state.page) {
    if (!state.root) return;
    if (state.listController) state.listController.abort();
    const controller = new AbortController();
    state.listController = controller;
    state.page = page;
    showState('loading', true);
    showState('error', false);
    showState('empty', false);
    showState('table-container', false);
    showState('cards', false);
    try {
        const payload = { ...currentFilters(), pagina: page, por_pagina: state.pageSize };
        const response = unwrap(await requestJson('listar', { params: payload, controller }));
        const data = response && typeof response === 'object' ? response : {};
        state.itens = Array.isArray(data.itens) ? data.itens : [];
        state.total = Number(data.total ?? state.itens.length) || 0;
        state.page = Number(data.pagina ?? page) || page;
        state.pageSize = Number(data.por_pagina ?? state.pageSize) || PAGE_SIZE;
        state.loaded = true;
        renderList();
    } catch (error) {
        if (error.name !== 'AbortError') {
            state.itens = [];
            state.total = 0;
            showState('error', true);
            const message = byId('alertas-error-message');
            if (message) message.textContent = error.message || 'Não foi possível carregar os alertas.';
        }
    } finally {
        if (state.listController === controller) state.listController = null;
        showState('loading', false);
    }
}

async function loadKpis() {
    try {
        renderKpis(unwrap(await requestJson('kpis')) || {});
    } catch (error) {
        if (error.name !== 'AbortError') renderKpis({});
    }
}

async function loadAll() {
    if (!state.root || state.loadingAll) return;
    state.loadingAll = true;
    await Promise.all([loadOptions(), loadKpis(), loadList(1)]);
    state.loadingAll = false;
}

function openModal(id) {
    const modal = byId(id);
    if (!modal) return;
    state.lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('alertas-modal-open');
    const focusable = modal.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
    focusable?.focus();
}

function closeModal(id) {
    const modal = byId(id);
    if (modal) modal.hidden = true;
    if (!document.querySelector('.page-moradores .alertas-modal:not([hidden])')) document.body.classList.remove('alertas-modal-open');
    if (state.lastFocus && document.contains(state.lastFocus)) state.lastFocus.focus();
    state.lastFocus = null;
}

function resetForm() {
    const form = byId('alertas-form');
    if (!form) return;
    form.reset();
    state.editingId = null;
    state.targets = [];
    byId('alertas-form-id').value = '';
    byId('alertas-form-idempotency').value = generateKey();
    setText('alertas-modal-title', 'Novo alerta da unidade');
    setText('alertas-form-submit-label', 'Salvar alerta');
    renderTargets();
    applyPermissionVisibility();
    const modelo = byId('alertas-form-modelo');
    if (modelo) modelo.value = '';
    updateMoradorOptions();
    updateFormPreview();
}

function readDays() {
    return Array.from(document.querySelectorAll('#alertas-form-dias input[type="checkbox"]:checked')).map(input => input.value);
}

function readForm() {
    const value = id => byId(id)?.value?.trim() || '';
    const payload = {
        unidade: value('alertas-form-unidade'),
        titular_tipo: value('alertas-form-titular-tipo') || 'MORADOR',
        morador_id: Number(value('alertas-form-morador')),
        titulo: value('alertas-form-titulo'),
        mensagem: value('alertas-form-mensagem'),
        nivel: value('alertas-form-nivel') || 'INFORMATIVO',
        acao: value('alertas-form-acao') || 'APENAS_AVISAR',
        publico: value('alertas-form-publico') || 'TODOS',
        abrangencia: value('alertas-form-abrangencia') || 'TODOS_DO_TIPO',
        vigencia_inicio: value('alertas-form-inicio'),
        vigencia_fim: value('alertas-form-fim'),
        alvos: state.targets.map(target => ({ ...target })),
        idempotency_key: value('alertas-form-idempotency'),
    };
    if (state.editingId) payload.id = Number(state.editingId);
    const dependenteId = value('alertas-form-dependente');
    const days = readDays();
    const horaInicio = value('alertas-form-hora-inicio');
    const horaFim = value('alertas-form-hora-fim');
    const instrucao = value('alertas-form-instrucao');
    const telefone = value('alertas-form-telefone');
    if (payload.titular_tipo === 'DEPENDENTE' && dependenteId) payload.dependente_id = Number(dependenteId);
    if (days.length) payload.dias_semana = days;
    if (horaInicio) payload.hora_inicio = horaInicio;
    if (horaFim) payload.hora_fim = horaFim;
    if (instrucao) payload.instrucao_operador = instrucao;
    if (telefone) payload.telefone_contato = telefone;
    return payload;
}

function validateForm(payload) {
    const form = byId('alertas-form');
    if (!payload.unidade) {
        showFeedback('error', 'Selecione a unidade do alerta.');
        return false;
    }
    if (!payload.morador_id || Number.isNaN(payload.morador_id)) {
        showFeedback('error', 'Selecione o morador titular do alerta.');
        return false;
    }
    if (payload.titular_tipo === 'DEPENDENTE' && !payload.dependente_id) {
        showFeedback('error', 'Selecione o dependente titular do alerta.');
        return false;
    }
    if (!form?.checkValidity()) {
        form?.reportValidity();
        return false;
    }
    if (payload.vigencia_inicio && payload.vigencia_fim && payload.vigencia_fim < payload.vigencia_inicio) {
        showFeedback('error', 'A vigência final deve ser posterior à vigência inicial.');
        return false;
    }
    if (payload.abrangencia === 'ESPECIFICO' && !payload.alvos.length) {
        showFeedback('error', 'Adicione pelo menos um alvo para a abrangência específica.');
        return false;
    }
    return true;
}

async function saveAlert(event) {
    event.preventDefault();
    if (!hasPermission(state.editingId ? 'editar' : 'criar')) {
        showFeedback('error', 'Você não possui permissão para esta operação.');
        return;
    }
    const payload = readForm();
    if (!validateForm(payload)) return;
    const submit = byId('alertas-form-submit');
    if (submit) submit.disabled = true;
    try {
        await requestJson('salvar', { method: 'POST', body: payload });
        closeModal('alertas-form-modal');
        showFeedback('success', 'Alerta salvo com sucesso.');
        await Promise.all([loadList(state.page), loadKpis()]);
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível salvar o alerta.');
    } finally {
        if (submit) submit.disabled = false;
        applyPermissionVisibility();
    }
}

function setFormValue(id, value) {
    const element = byId(id);
    if (element) element.value = value == null ? '' : String(value);
}

function setUnitFormValue(value) {
    const select = byId('alertas-form-unidade');
    if (!select) return;
    const unidadeNormalizada = normalizeUnit(value);
    const option = Array.from(select.options).find(item => normalizeUnit(item.value) === unidadeNormalizada);
    select.value = option ? option.value : '';
}

function renderTargets() {
    const list = byId('alertas-alvos-list');
    if (!list) return;
    list.replaceChildren();
    if (!state.targets.length) {
        list.appendChild(createElement('p', 'alertas-targets-empty', 'Nenhum alvo específico adicionado.'));
        return;
    }
    state.targets.forEach((target, index) => {
        const item = createElement('div', 'alertas-target-item');
        const label = target.nome_referencia || target.nome || target.documento || target.placa || target.visitante_id || 'Alvo selecionado';
        const kind = labelFor('publico', target.tipo_alvo) || target.tipo_alvo;
        item.appendChild(createElement('span', '', `${kind}: ${label}`));
        const remove = createElement('button', 'alertas-target-remove', 'Remover');
        remove.type = 'button';
        remove.dataset.alertaRemoveTarget = String(index);
        remove.setAttribute('aria-label', `Remover alvo ${label}`);
        item.appendChild(remove);
        list.appendChild(item);
    });
}

function addTarget(target) {
    if (!target || !target.tipo_alvo) return;
    const normalized = {
        tipo_alvo: String(target.tipo_alvo).toUpperCase(),
        ...(target.visitante_id ? { visitante_id: target.visitante_id } : {}),
        ...(target.documento ? { documento: target.documento } : {}),
        ...(target.tipo_documento ? { tipo_documento: target.tipo_documento } : {}),
        ...(target.placa ? { placa: target.placa } : {}),
        ...(target.nome_referencia || target.nome ? { nome_referencia: target.nome_referencia || target.nome } : {}),
    };
    if (!Object.keys(normalized).some(key => key !== 'tipo_alvo')) {
        showFeedback('error', 'Informe os dados do alvo antes de adicionar.');
        return;
    }
    state.targets.push(normalized);
    renderTargets();
}

function renderTargetResults(items) {
    const select = byId('alertas-alvo-resultados');
    if (!select) return;
    select.replaceChildren();
    const first = createElement('option', '', 'Selecione um resultado para adicionar');
    first.value = '';
    select.appendChild(first);
    (Array.isArray(items) ? items : []).forEach(item => {
        const option = createElement('option', '', item.nome_referencia || item.nome || item.descricao || item.placa || item.documento || 'Alvo');
        option.value = JSON.stringify({
            tipo_alvo: item.tipo_alvo || item.tipo || 'VISITANTE',
            visitante_id: item.visitante_id ?? item.id_visitante ?? item.id,
            documento: item.documento,
            tipo_documento: item.tipo_documento,
            placa: item.placa,
            nome_referencia: item.nome_referencia || item.nome || item.descricao,
        });
        select.appendChild(option);
    });
    showState('target-results', Array.isArray(items) && items.length > 0);
}

async function searchTarget() {
    if (!hasPermission('criar') && !state.editingId) return;
    const query = byId('alertas-alvo-busca')?.value?.trim() || '';
    if (!query) {
        renderTargetResults([]);
        return;
    }
    const button = byId('alertas-alvo-buscar');
    if (button) button.disabled = true;
    try {
        const data = unwrap(await requestJson('buscar_alvo', { params: { q: query } }));
        renderTargetResults(Array.isArray(data) ? data : data?.itens || []);
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível buscar o alvo.');
    } finally {
        if (button) button.disabled = false;
    }
}

function dateInputValue(date) {
    const pad = value => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function fillReportPeriod(days) {
    const fim = new Date();
    const inicio = new Date(fim);
    inicio.setDate(inicio.getDate() - (days - 1));
    setFormValue('alertas-relatorio-inicio', dateInputValue(inicio));
    setFormValue('alertas-relatorio-fim', dateInputValue(fim));
    const feedback = byId('alertas-relatorio-feedback');
    if (feedback) feedback.textContent = `Período selecionado: últimos ${days} dias. Escolha CSV ou PDF para gerar o relatório.`;
}

async function exportCiencias(formato, relatorio = false) {
    if (!hasPermission('exportar')) {
        showFeedback('error', 'Sua permissão não permite exportar ciências.');
        return;
    }
    const params = { formato: 'json' };
    if (relatorio) {
        const inicio = byId('alertas-relatorio-inicio')?.value || '';
        const fim = byId('alertas-relatorio-fim')?.value || '';
        if (!inicio || !fim) {
            const feedback = byId('alertas-relatorio-feedback');
            if (feedback) feedback.textContent = 'Informe a data inicial e final do relatório.';
            return;
        }
        const inicioDate = new Date(`${inicio}T00:00:00`);
        const fimDate = new Date(`${fim}T00:00:00`);
        const dias = Math.round((fimDate - inicioDate) / 86400000) + 1;
        if (!Number.isFinite(dias) || dias < 1 || dias > 90) {
            const feedback = byId('alertas-relatorio-feedback');
            if (feedback) feedback.textContent = 'O período deve ser válido e não pode ultrapassar 90 dias.';
            return;
        }
        params.data_inicio = inicio;
        params.data_fim = fim;
    }
    const printWindow = formato === 'pdf' ? window.open('about:blank', '_blank') : null;
    if (formato === 'pdf' && !printWindow) {
        showFeedback('error', 'Permita a abertura da janela para imprimir o relatório.');
        return;
    }
    try {
        const data = await requestJson('exportar', { params });
        const pacote = data?.dados || data;
        const linhas = Array.isArray(pacote?.linhas) ? pacote.linhas : [];
        const colunas = [
            ['alerta_id', 'Alerta ID'], ['titulo', 'Título'], ['unidade', 'Unidade'], ['contexto', 'Contexto'],
            ['decisao', 'Decisão'], ['justificativa', 'Justificativa'], ['usuario_nome', 'Operador'],
            ['pessoa_nome', 'Pessoa'], ['pessoa_documento_mascarado', 'Documento mascarado'],
            ['tipo_pessoa', 'Tipo'], ['placa', 'Placa'], ['criado_em', 'Data/hora']
        ];
        if (formato === 'csv') {
            const csv = [colunas.map(([, titulo]) => titulo), ...linhas.map(item => colunas.map(([chave]) => item[chave] ?? ''))]
                .map(row => row.map(valor => `"${String(valor).replace(/"/g, '""')}"`).join(',')).join('\r\n');
            const blob = new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'alertas-unidade-ciencias.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            showFeedback('success', `${linhas.length} ciência(s) exportada(s) em CSV.`);
            if (relatorio && byId('alertas-relatorio-feedback')) byId('alertas-relatorio-feedback').textContent = `${linhas.length} ciência(s) exportada(s) para o período selecionado.`;
            return;
        }

        printWindow.opener = null;
        const doc = printWindow.document;
        doc.title = 'Alertas e ciências';
        const style = doc.createElement('style');
        style.textContent = 'body{font:12px Arial,sans-serif;color:#172033;padding:24px}h1{font-size:20px}p{color:#475569}button{margin:0 0 16px;padding:8px 12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:6px;text-align:left;vertical-align:top}th{background:#e2e8f0}@media print{button{display:none}body{padding:0}}';
        doc.head.appendChild(style);
        const title = doc.createElement('h1');
        title.textContent = 'Alertas da Unidade — Registro de ciências';
        doc.body.appendChild(title);
        const subtitle = doc.createElement('p');
        subtitle.textContent = `${linhas.length} registro(s) · Gerado em ${new Date().toLocaleString('pt-BR')}`;
        doc.body.appendChild(subtitle);
        const button = doc.createElement('button');
        button.type = 'button';
        button.textContent = 'Imprimir / Salvar como PDF';
        button.addEventListener('click', () => printWindow.print());
        doc.body.appendChild(button);
        const table = doc.createElement('table');
        const thead = doc.createElement('thead');
        const headerRow = doc.createElement('tr');
        colunas.forEach(([, nome]) => { const th = doc.createElement('th'); th.textContent = nome; headerRow.appendChild(th); });
        thead.appendChild(headerRow);
        table.appendChild(thead);
        const tbody = doc.createElement('tbody');
        linhas.forEach(linha => {
            const row = doc.createElement('tr');
            colunas.forEach(([chave]) => { const td = doc.createElement('td'); td.textContent = linha[chave] == null ? '' : String(linha[chave]); row.appendChild(td); });
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        doc.body.appendChild(table);
        doc.close();
        printWindow.focus();
        showFeedback('success', 'Relatório de ciências preparado para impressão ou PDF.');
        if (relatorio && byId('alertas-relatorio-feedback')) byId('alertas-relatorio-feedback').textContent = `${linhas.length} ciência(s) preparada(s) para impressão/PDF.`;
    } catch (error) {
        if (printWindow && !printWindow.closed) printWindow.close();
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível exportar as ciências.');
    }
}

function openNew() {
    if (!hasPermission('criar')) return;
    resetForm();
    openModal('alertas-form-modal');
}

async function openEdit(id) {
    if (!hasPermission('editar')) return;
    try {
        const data = unwrap(await requestJson('detalhe', { params: { id, modo: 'edicao' } })) || {};
        const alert = data.alerta || data;
        resetForm();
        state.editingId = id;
        setText('alertas-modal-title', 'Editar alerta da unidade');
        setText('alertas-form-submit-label', 'Salvar alterações');
        setFormValue('alertas-form-id', id);
        setUnitFormValue(alert.unidade ?? alert.unidade_id);
        setFormValue('alertas-form-titular-tipo', alert.titular_tipo || 'MORADOR');
        updateMoradorOptions();
        setFormValue('alertas-form-morador', alert.morador_id);
        updateDependenteOptions();
        setFormValue('alertas-form-dependente', alert.dependente_id);
        setFormValue('alertas-form-titulo', alert.titulo);
        setFormValue('alertas-form-mensagem', alert.mensagem);
        setFormValue('alertas-form-nivel', alert.nivel || 'INFORMATIVO');
        setFormValue('alertas-form-acao', alert.acao || 'APENAS_AVISAR');
        setFormValue('alertas-form-publico', alert.publico || 'TODOS');
        setFormValue('alertas-form-abrangencia', alert.abrangencia || 'TODOS_DO_TIPO');
        setFormValue('alertas-form-inicio', formatDateTimeInput(alert.vigencia_inicio));
        setFormValue('alertas-form-fim', formatDateTimeInput(alert.vigencia_fim));
        setFormValue('alertas-form-hora-inicio', alert.hora_inicio);
        setFormValue('alertas-form-hora-fim', alert.hora_fim);
        setFormValue('alertas-form-instrucao', alert.instrucao_operador);
        setFormValue('alertas-form-telefone', alert.telefone_contato);
        const days = Array.isArray(alert.dias_semana) ? alert.dias_semana : String(alert.dias_semana || '').split(',').map(item => item.trim()).filter(Boolean);
        document.querySelectorAll('#alertas-form-dias input[type="checkbox"]').forEach(input => { input.checked = days.includes(input.value); });
        state.targets = (data.alvos || alert.alvos || []).map(item => ({ ...item, ...(item.tipo_alvo === 'DOCUMENTO' && item.documento_edicao ? { documento: item.documento_edicao } : {}) }));
        renderTargets();
        applyPermissionVisibility();
        updateFormPreview();
        openModal('alertas-form-modal');
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível carregar o alerta.');
    }
}

async function openDuplicate(id) {
    if (!hasPermission('criar') || !hasPermission('editar')) return;
    try {
        const data = unwrap(await requestJson('detalhe', { params: { id, modo: 'edicao' } })) || {};
        const alert = data.alerta || data;
        resetForm();
        setUnitFormValue(alert.unidade ?? alert.unidade_id);
        setFormValue('alertas-form-titular-tipo', alert.titular_tipo || 'MORADOR');
        updateMoradorOptions();
        setFormValue('alertas-form-morador', alert.morador_id);
        updateDependenteOptions();
        setFormValue('alertas-form-dependente', alert.dependente_id);
        setFormValue('alertas-form-titulo', `${String(alert.titulo || 'Alerta').slice(0, 112)} (cópia)`);
        setFormValue('alertas-form-mensagem', alert.mensagem);
        setFormValue('alertas-form-nivel', alert.nivel || 'INFORMATIVO');
        setFormValue('alertas-form-acao', alert.acao || 'APENAS_AVISAR');
        setFormValue('alertas-form-publico', alert.publico || 'TODOS');
        setFormValue('alertas-form-abrangencia', alert.abrangencia || 'TODOS_DO_TIPO');
        setFormValue('alertas-form-inicio', formatDateTimeInput(alert.vigencia_inicio));
        setFormValue('alertas-form-fim', formatDateTimeInput(alert.vigencia_fim));
        setFormValue('alertas-form-hora-inicio', alert.hora_inicio);
        setFormValue('alertas-form-hora-fim', alert.hora_fim);
        setFormValue('alertas-form-instrucao', alert.instrucao_operador);
        setFormValue('alertas-form-telefone', alert.telefone_contato);
        const days = Array.isArray(alert.dias_semana) ? alert.dias_semana : String(alert.dias_semana || '').split(',').map(item => item.trim()).filter(Boolean);
        document.querySelectorAll('#alertas-form-dias input[type="checkbox"]').forEach(input => { input.checked = days.includes(input.value); });
        state.targets = (data.alvos || alert.alvos || []).map(item => ({ ...item, ...(item.tipo_alvo === 'DOCUMENTO' && item.documento_edicao ? { documento: item.documento_edicao } : {}) }));
        renderTargets();
        setText('alertas-modal-title', 'Duplicar alerta da unidade');
        setText('alertas-form-submit-label', 'Criar cópia');
        applyPermissionVisibility();
        updateFormPreview();
        openModal('alertas-form-modal');
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível preparar a cópia do alerta.');
    }
}

function renderDetailList(id, items, formatter) {
    const list = byId(id);
    if (!list) return;
    list.replaceChildren();
    if (!Array.isArray(items) || !items.length) {
        list.appendChild(createElement('li', '', 'Nenhum registro.'));
        return;
    }
    items.forEach(item => list.appendChild(createElement('li', '', formatter(item))));
}

function renderAlertTimeline(id, items) {
    const list = byId(id);
    if (!list) return;
    list.replaceChildren();
    if (!Array.isArray(items) || !items.length) {
        list.appendChild(createElement('li', '', 'Nenhum registro.'));
        return;
    }
    items.forEach(item => {
        const li = createElement('li', 'alertas-evento');
        const operador = item.usuario_nome || 'Automático / Sistema';
        const decisao = item.decisao || item.status || 'Registro';
        const pessoa = item.pessoa_nome || 'Pessoa não identificada';
        li.appendChild(createElement('strong', '', `${formatDate(item.criado_em || item.data, true)} · ${decisao}`));
        li.appendChild(createElement('span', '', `Operador: ${operador}`));
        li.appendChild(createElement('span', '', `Pessoa: ${pessoa}${item.pessoa_documento_mascarado ? ` · ${item.pessoa_documento_mascarado}` : ''}`));
        const contexto = [item.tipo_pessoa, item.placa ? `Placa ${item.placa}` : null, item.divergencia_unidade ? 'Unidade divergente' : null].filter(Boolean).join(' · ');
        if (contexto) li.appendChild(createElement('span', '', contexto));
        if (item.justificativa) li.appendChild(createElement('span', '', `Justificativa: ${item.justificativa}`));
        const registroId = Number(item.registro_acesso_id);
        if (Number.isSafeInteger(registroId) && registroId > 0) {
            const link = createElement('a', 'alertas-evento-link', `Abrir registro #${registroId}`);
            link.href = `/frontend/layout-base.html?page=registro&aba=manual&registro_id=${encodeURIComponent(String(registroId))}`;
            li.appendChild(link);
        }
        list.appendChild(li);
    });
}

function activateDetailTab(name, focus = false) {
    if (!state.root) return;
    const tabs = Array.from(state.root.querySelectorAll('[data-alerta-detail-tab]'));
    tabs.forEach(tab => {
        const active = tab.dataset.alertaDetailTab === name;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        if (active && focus) tab.focus();
    });
    state.root.querySelectorAll('[data-alerta-detail-panel]').forEach(panel => {
        panel.hidden = panel.dataset.alertaDetailPanel !== name;
    });
}

async function openDetail(id) {
    try {
        const data = unwrap(await requestJson('detalhe', { params: { id } })) || {};
        const alert = data.alerta || data;
        const alertaId = Number(alert?.id);
        if (!alert || typeof alert !== 'object' || !Number.isFinite(alertaId) || alertaId <= 0 || !String(alert.titulo || '').trim() || !String(alert.mensagem || '').trim()) {
            throw new Error('A API não retornou os dados do alerta. Atualize a lista e tente novamente.');
        }
        setText('alertas-detalhe-titulo', alert.titulo || 'Detalhes do alerta');
        setText('alertas-detalhe-mensagem', alert.mensagem || '—');
        setText('alertas-detalhe-unidade', alert.unidade || alert.unidade_nome);
        setText('alertas-detalhe-titular', alert.morador_nome || alert.morador || alert.morador_id);
        setText('alertas-detalhe-nivel', labelFor('nivel', alert.nivel));
        setText('alertas-detalhe-status', labelFor('status', alert.status));
        setText('alertas-detalhe-acao', labelFor('acao', alert.acao));
        setText('alertas-detalhe-publico', labelFor('publico', alert.publico));
        setText('alertas-detalhe-vigencia', `${formatDate(alert.vigencia_inicio)} — ${formatDate(alert.vigencia_fim)}`);
        renderDetailList('alertas-detalhe-alvos', data.alvos, item => `${item.tipo_alvo || item.tipo || 'Alvo'}: ${item.nome_referencia || item.nome || item.documento || item.placa || '—'}`);
        renderAlertTimeline('alertas-detalhe-acionamentos', data.acionamentos);
        renderAlertTimeline('alertas-detalhe-ciencias', data.ciencias);
        renderDetailList('alertas-detalhe-historico', data.historico, item => `${item.operacao || item.acao || 'Alteração'} — ${formatDate(item.criado_em || item.data)}`);
        const edit = byId('alertas-detalhe-editar');
        if (edit) {
            edit.dataset.alertaId = String(id);
            edit.hidden = !hasPermission('editar');
        }
        activateDetailTab('visao');
        openModal('alertas-detalhe-modal');
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível carregar os detalhes do alerta.');
    }
}

async function changeStatus(id, operation) {
    const permission = operation === 'CANCELAR' ? 'excluir' : 'editar';
    if (!hasPermission(permission)) return;
    const labels = { PAUSAR: 'pausar', RETOMAR: 'retomar', CANCELAR: 'cancelar' };
    if (!window.confirm(`Deseja ${labels[operation] || 'alterar'} este alerta?`)) return;
    const motivo = window.prompt('Motivo (opcional):', '') || '';
    try {
        await requestJson('alterar_status', { method: 'POST', body: { id: Number(id), operacao: operation, ...(motivo ? { motivo } : {}) } });
        showFeedback('success', 'Status do alerta atualizado.');
        await Promise.all([loadList(state.page), loadKpis()]);
    } catch (error) {
        if (error.name !== 'AbortError') showFeedback('error', error.message || 'Não foi possível alterar o status.');
    }
}

function handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const detailTab = target.closest('[data-alerta-detail-tab]');
    if (detailTab) {
        activateDetailTab(detailTab.dataset.alertaDetailTab);
        return;
    }
    const pageButton = target.closest('[data-alerta-page]');
    if (pageButton) {
        const page = Number(pageButton.dataset.alertaPage);
        if (page && page !== state.page) loadList(page);
        return;
    }
    const removeTarget = target.closest('[data-alerta-remove-target]');
    if (removeTarget) {
        state.targets.splice(Number(removeTarget.dataset.alertaRemoveTarget), 1);
        renderTargets();
        return;
    }
    const actionButton = target.closest('[data-alerta-action]');
    if (actionButton) {
        const action = actionButton.dataset.alertaAction;
        const id = actionButton.dataset.alertaId;
        if (action === 'novo') openNew();
        else if (action === 'recarregar') loadList(1);
        else if (action === 'exportar-csv') exportCiencias('csv');
        else if (action === 'exportar-pdf') exportCiencias('pdf');
        else if (action === 'relatorio-7-dias') fillReportPeriod(7);
        else if (action === 'relatorio-30-dias') fillReportPeriod(30);
        else if (action === 'relatorio-csv') exportCiencias('csv', true);
        else if (action === 'relatorio-pdf') exportCiencias('pdf', true);
        else if (action === 'ver') openDetail(id);
        else if (action === 'editar') openEdit(id);
        else if (action === 'duplicar') openDuplicate(id);
        else if (action === 'pausar') changeStatus(id, 'PAUSAR');
        else if (action === 'retomar') changeStatus(id, 'RETOMAR');
        else if (action === 'cancelar') changeStatus(id, 'CANCELAR');
        return;
    }
    const closeButton = target.closest('[data-alerta-close]');
    if (closeButton) {
        closeModal(closeButton.dataset.alertaClose);
        return;
    }
    if (target.classList.contains('alertas-modal')) {
        closeModal(target.id);
    }
}

function handleFormChange(event) {
    if (event.target?.id === 'alertas-form-modelo') applyQuickModel(event.target.value);
    if (event.target?.id === 'alertas-form-unidade') {
        setFormValue('alertas-form-morador', '');
        updateMoradorOptions();
    }
    if (event.target?.id === 'alertas-form-titular-tipo') updateDependenteOptions();
    if (event.target?.id === 'alertas-form-morador') updateDependenteOptions();
    if (event.target?.id === 'alertas-form-dependente') handleDependenteChange();
    if (event.target?.id === 'alertas-form-abrangencia') {
        const targets = byId('alertas-targets-wrap');
        if (targets) targets.hidden = event.target.value !== 'ESPECIFICO';
    }
    if (event.target?.closest?.('#alertas-form')) updateFormPreview();
}

function handleTargetResults(event) {
    if (event.target?.id !== 'alertas-alvo-resultados' || !event.target.value) return;
    try { addTarget(JSON.parse(event.target.value)); } catch (_) { /* resultado inválido não é adicionado */ }
    event.target.value = '';
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        const open = document.querySelector('.page-moradores .alertas-modal:not([hidden])');
        if (open) closeModal(open.id);
        return;
    }
    const tab = event.target instanceof Element ? event.target.closest('[data-alerta-detail-tab]') : null;
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = Array.from(state.root?.querySelectorAll('[data-alerta-detail-tab]') || []);
    const current = tabs.indexOf(tab);
    if (current < 0 || !tabs.length) return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    activateDetailTab(tabs[next].dataset.alertaDetailTab, true);
}

function bindEvents() {
    const root = state.root;
    bind(root, 'click', handleClick);
    bind(root, 'change', handleFormChange);
    bind(root, 'change', handleTargetResults);
    bind(root.querySelector('#alertas-form'), 'input', updateFormPreview);
    bind(root.querySelector('#alertas-filtros'), 'submit', event => { event.preventDefault(); persistCurrentFilters(); loadList(1); });
    bind(root.querySelector('#alertas-filtros'), 'reset', () => setTimeout(() => { persistCurrentFilters(); loadList(1); }, 0));
    bind(root.querySelector('#alertas-form'), 'submit', saveAlert);
    bind(root.querySelector('#alertas-alvo-buscar'), 'click', searchTarget);
    bind(root.querySelector('#alertas-alvo-busca'), 'keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchTarget(); } });
    bind(root.querySelector('#alertas-detalhe-editar'), 'click', event => {
        closeModal('alertas-detalhe-modal');
        openEdit(event.currentTarget.dataset.alertaId);
    });
    bind(document, 'keydown', handleKeydown);
    document.querySelectorAll('.tab-button[data-tab="alertas"]').forEach(button => {
        bind(button, 'click', () => {
            if (!state.loaded) loadAll();
        });
    });
}

function applyInitialTargetVisibility() {
    const targets = byId('alertas-targets-wrap');
    const abrangencia = byId('alertas-form-abrangencia');
    if (targets && abrangencia) targets.hidden = abrangencia.value !== 'ESPECIFICO';
}

export function init() {
    if (state.initialized) return;
    const tab = byId('tab-alertas');
    const root = tab?.closest('.page-moradores') || tab;
    if (!tab || !root) return;
    state.initialized = true;
    state.root = root;
    state.controllers = new Set();
    state.page = 1;
    state.total = 0;
    state.loaded = false;
    state.loadingAll = false;
    state.permissionsKnown = false;
    state.permissions = null;
    restoreSavedFilters();
    bindEvents();
    applyInitialTargetVisibility();
    renderTargets();
    applyPermissionVisibility();

    applyPermissionVisibility();

    const requestedTab = new URLSearchParams(window.location.search).get('aba');
    if (requestedTab === 'alertas' || root.classList.contains('active')) loadAll();
    window.MoradoresAlertasPage = { init, destroy, reload: () => loadList(1) };
}

export function destroy() {
    state.listeners.splice(0).forEach(remove => remove());
    if (state.listController) state.listController.abort();
    state.controllers.forEach(controller => controller.abort());
    state.controllers.clear();
    if (state.targetSearchTimer) clearTimeout(state.targetSearchTimer);
    closeModal('alertas-form-modal');
    closeModal('alertas-detalhe-modal');
    state.initialized = false;
    state.root = null;
    state.listController = null;
    state.loaded = false;
    state.loadingAll = false;
    state.editingId = null;
    state.targets = [];
    delete window.MoradoresAlertasPage;
}
