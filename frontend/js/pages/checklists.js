/**
 * checklists.js — Módulo de Checklist Veicular
 * Routed page module para layout-base.html?page=checklists
 *
 * APIs utilizadas:
 *   ../api/api_checklist.php          → listar, listar_abertos, buscar, criar, fechar, deletar, listar_veiculos, listar_operadores
 *   ../api/api_checklist_itens.php    → salvar_abertura, salvar_fechamento, buscar_por_checklist
 *   ../api/api_checklist_alertas.php  → listar_alertas, resolver_alerta, ignorar_alerta
 *
 * @version 2.0.0
 */
'use strict';

// ══════════════════════════════════════════════════════════
// CONSTANTES — Taxonomia de itens
// ══════════════════════════════════════════════════════════
const CHK_ITENS_NIVEL = [
    { categoria: 'nivel_oleo',         nome: 'Nível do Óleo do Motor',     radio: 'nivel_oleo' },
    { categoria: 'nivel_agua',         nome: 'Nível da Água do Radiador',  radio: 'nivel_agua' },
    { categoria: 'pneu_dianteiro_esq', nome: 'Pneu Dianteiro Esq. (LD)',   radio: 'pneu_dianteiro_esq' },
    { categoria: 'pneu_dianteiro_dir', nome: 'Pneu Dianteiro Dir. (LE)',   radio: 'pneu_dianteiro_dir' },
    { categoria: 'pneu_traseiro_esq',  nome: 'Pneu Traseiro Esq. (LD)',    radio: 'pneu_traseiro_esq' },
    { categoria: 'pneu_traseiro_dir',  nome: 'Pneu Traseiro Dir. (LE)',    radio: 'pneu_traseiro_dir' },
];

const CHK_ITENS_FUNC = [
    { categoria: 'buzina',     nome: 'Buzina',                   radio: 'buzina' },
    { categoria: 'farois',     nome: 'Faróis Alto',              radio: 'farois' },
    { categoria: 'freios',     nome: 'Luzes de Freio',           radio: 'luzes_freio' },
    { categoria: 'cintos',     nome: 'Cintos de Segurança',      radio: 'cintos' },
    { categoria: 'limpadores', nome: 'Limpadores de Para-brisa', radio: 'limpadores' },
    { categoria: 'extintor',   nome: 'Extintor de Incêndio',     radio: 'extintor' },
];

const CHK_TODOS_ITENS = [
    ...CHK_ITENS_NIVEL.map(i => ({ ...i, tipo_item: 'nivel' })),
    ...CHK_ITENS_FUNC.map(i  => ({ ...i, tipo_item: 'funcional' })),
];

// ══════════════════════════════════════════════════════════
// ESTADO INTERNO
// ══════════════════════════════════════════════════════════
let _veiculos   = [];
let _operadores = [];
let _listeners  = [];

// ══════════════════════════════════════════════════════════
// INIT / DESTROY
// ══════════════════════════════════════════════════════════
export function init() {
    console.log('[Checklists] Inicializando módulo v2.0.0...');

    // Breadcrumb
    document.querySelectorAll('.page-checklists [data-page]').forEach(el => {
        _on(el, 'click', () => window.AppRouter && AppRouter.loadPage(el.dataset.page));
    });

    // Tabs
    document.querySelectorAll('.page-checklists .tab-button').forEach(btn => {
        _on(btn, 'click', () => _ativarTab(btn.dataset.tab));
    });

    // Fechar modais ao clicar no overlay
    ['chk_modalFechar','chk_modalVisualizar','chk_modalAlerta','chk_modalExcluir'].forEach(id => {
        const el = document.getElementById(id);
        if (el) _on(el, 'click', e => { if (e.target === el) _fecharModal(id); });
    });

    // Botões de fechar modal
    _bindClick('chk_btnFecharModalClose',  () => _fecharModal('chk_modalFechar'));
    _bindClick('chk_btnVisualizarClose',   () => _fecharModal('chk_modalVisualizar'));
    _bindClick('chk_btnAlertaClose',       () => _fecharModal('chk_modalAlerta'));
    _bindClick('chk_btnExcluirClose',      () => _fecharModal('chk_modalExcluir'));
    _bindClick('chk_btnFecharVisualizar',  () => _fecharModal('chk_modalVisualizar'));
    _bindClick('chk_btnCancelarFechar',    () => _fecharModal('chk_modalFechar'));
    _bindClick('chk_btnCancelarAlerta',    () => _fecharModal('chk_modalAlerta'));
    _bindClick('chk_btnCancelarExcluir',   () => _fecharModal('chk_modalExcluir'));

    // Formulário de abertura
    const formAbertura = document.getElementById('chk_formAbertura');
    if (formAbertura) _on(formAbertura, 'submit', _onSubmitAbertura);
    _bindClick('chk_btnLimparForm', _limparFormAbertura);

    // Formulário de fechamento
    _bindClick('chk_btnConfirmarFechar', _onConfirmarFechamento);

    // Alertas e exclusão
    _bindClick('chk_btnConfirmarAlerta',  _onConfirmarAlerta);
    _bindClick('chk_btnConfirmarExcluir', _onConfirmarExcluir);

    // Atualizar listas
    _bindClick('chk_btnAtualizarAbertos', carregarAbertos);
    _bindClick('chk_btnAtualizarHist',    carregarHistorico);
    _bindClick('chk_btnAtualizarAlertas', carregarAlertas);
    _bindClick('chk_btnFiltrar',          carregarHistorico);

    // Filtro de status de alertas
    const filtroAlertaStatus = document.getElementById('chk_filtroAlertaStatus');
    if (filtroAlertaStatus) _on(filtroAlertaStatus, 'change', carregarAlertas);

    // KM final — hint dinâmico
    const kmFinal = document.getElementById('chk_kmFinal');
    if (kmFinal) _on(kmFinal, 'input', _atualizarKmHint);

    // Definir datetime padrão
    _setDatetimeDefault('chk_dataAbertura');

    // Expor globalmente para onclick no HTML
    window.Checklists = {
        abrirModalFechar,
        abrirVisualizar,
        abrirModalAlerta,
        abrirExcluir,
        carregarAbertos,
        carregarHistorico,
        carregarAlertas,
    };

    // Carregar dados iniciais
    Promise.all([_carregarVeiculos(), _carregarOperadores()])
        .then(() => {
            carregarAbertos();
            carregarHistorico();
            carregarAlertas();
            _atualizarKPIs();
        })
        .catch(err => console.error('[Checklists] Erro na inicialização:', err));

    console.log('[Checklists] Módulo pronto.');
}

export function destroy() {
    console.log('[Checklists] Destruindo módulo...');
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
}

// ══════════════════════════════════════════════════════════
// HELPERS DE EVENTOS
// ══════════════════════════════════════════════════════════
function _on(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    _listeners.push({ el, ev, fn });
}

function _bindClick(id, fn) {
    const el = document.getElementById(id);
    if (el) _on(el, 'click', fn);
}

// ══════════════════════════════════════════════════════════
// TABS
// ══════════════════════════════════════════════════════════
function _ativarTab(tabId) {
    document.querySelectorAll('.page-checklists .tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.page-checklists .tab-content').forEach(c => c.classList.remove('active'));

    const btn     = document.querySelector(`.page-checklists .tab-button[data-tab="${tabId}"]`);
    const content = document.getElementById(`tab-${tabId}`);

    if (btn)     btn.classList.add('active');
    if (content) content.classList.add('active');

    if (tabId === 'chk-abertos')   carregarAbertos();
    if (tabId === 'chk-historico') carregarHistorico();
    if (tabId === 'chk-alertas')   carregarAlertas();
}

// ══════════════════════════════════════════════════════════
// MODAIS
// ══════════════════════════════════════════════════════════
function _abrirModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function _fecharModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// ══════════════════════════════════════════════════════════
// TOAST
// ══════════════════════════════════════════════════════════
function _toast(msg, tipo = 'info') {
    const container = document.getElementById('chk_toastContainer');
    if (!container) return;

    const icons = {
        success: 'fa-check-circle',
        error:   'fa-times-circle',
        info:    'fa-info-circle',
        warning: 'fa-exclamation-triangle'
    };

    const div = document.createElement('div');
    div.className = `chk-toast chk-toast-${tipo}`;
    div.innerHTML = `<i class="fas ${icons[tipo] || 'fa-info-circle'}"></i><span>${msg}</span>`;
    container.appendChild(div);

    setTimeout(() => {
        div.style.opacity = '0';
        div.style.transition = 'opacity .3s';
        setTimeout(() => div.remove(), 300);
    }, 4000);
}

// ══════════════════════════════════════════════════════════
// UTILITÁRIOS
// ══════════════════════════════════════════════════════════
function _setDatetimeDefault(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const now = new Date();
    now.setSeconds(0, 0);
    el.value = now.toISOString().slice(0, 16);
}

function _fmtDataHora(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        return d.toLocaleString('pt-BR');
    } catch { return str; }
}

function _fmtData(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        return d.toLocaleDateString('pt-BR');
    } catch { return str; }
}

function _fmtKm(v) {
    if (v == null || v === '') return '—';
    return Number(v).toLocaleString('pt-BR') + ' km';
}

function _statusBadge(status) {
    const map = {
        aberto:    '<span class="badge badge-aberto"><i class="fas fa-folder-open"></i> Aberto</span>',
        fechado:   '<span class="badge badge-fechado"><i class="fas fa-check"></i> Fechado</span>',
        pendente:  '<span class="badge badge-pendente"><i class="fas fa-clock"></i> Pendente</span>',
        resolvido: '<span class="badge badge-resolvido"><i class="fas fa-check-circle"></i> Resolvido</span>',
        ignorado:  '<span class="badge badge-ignorado"><i class="fas fa-ban"></i> Ignorado</span>',
    };
    return map[status] || `<span class="badge">${status}</span>`;
}

function _valorBadge(val) {
    if (!val) return '—';
    const map = {
        minimo: '<span class="val-minimo"><i class="fas fa-exclamation-triangle"></i> Mínimo</span>',
        medio:  '<span class="val-medio"><i class="fas fa-minus-circle"></i> Médio</span>',
        maximo: '<span class="val-maximo"><i class="fas fa-check-circle"></i> Máximo</span>',
        sim:    '<span class="val-sim"><i class="fas fa-check-circle"></i> Sim</span>',
        nao:    '<span class="val-nao"><i class="fas fa-times-circle"></i> Não</span>',
    };
    return map[val] || val;
}

function _atualizarKmHint() {
    const kmInicial = parseInt(document.getElementById('chk_fecharKmInicial')?.value || '0');
    const kmFinalEl = document.getElementById('chk_kmFinal');
    const hintEl    = document.getElementById('chk_kmFinalHint');
    if (!kmFinalEl || !hintEl) return;

    const kmFinal = parseInt(kmFinalEl.value || '0');
    if (kmInicial > 0) {
        kmFinalEl.min = kmInicial + 1;
        if (kmFinal > kmInicial) {
            const percorrido = kmFinal - kmInicial;
            hintEl.textContent = `KM inicial: ${kmInicial.toLocaleString('pt-BR')} km | Percorrido: ${percorrido.toLocaleString('pt-BR')} km`;
        } else {
            hintEl.textContent = `KM inicial: ${kmInicial.toLocaleString('pt-BR')} km — informe um valor maior`;
        }
    }
}

// ══════════════════════════════════════════════════════════
// CARREGAR VEÍCULOS E OPERADORES
// ══════════════════════════════════════════════════════════
async function _carregarVeiculos() {
    try {
        const r = await fetch('../api/api_checklist.php?acao=listar_veiculos');
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);

        _veiculos = d.dados || [];

        ['chk_veiculo', 'chk_filtroVeiculo'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const isFilter = id === 'chk_filtroVeiculo';
            sel.innerHTML = isFilter
                ? '<option value="">Todos os veículos</option>'
                : '<option value="">Selecione o veículo...</option>';
            _veiculos.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.id;
                opt.textContent = `${v.placa} — ${v.modelo} (${v.ano})`;
                sel.appendChild(opt);
            });
        });

        console.log(`[Checklists] ${_veiculos.length} veículo(s) carregado(s).`);
    } catch (e) {
        console.error('[Checklists] Erro ao carregar veículos:', e);
        _toast('Erro ao carregar veículos: ' + e.message, 'error');
    }
}

async function _carregarOperadores() {
    try {
        const r = await fetch('../api/api_checklist.php?acao=listar_operadores');
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);

        _operadores = d.dados || [];

        const sel = document.getElementById('chk_operador');
        if (sel) {
            sel.innerHTML = '<option value="">Selecione o operador...</option>';
            _operadores.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = `${o.nome}${o.funcao ? ' — ' + o.funcao : ''}`;
                sel.appendChild(opt);
            });
        }

        console.log(`[Checklists] ${_operadores.length} operador(es) carregado(s).`);
    } catch (e) {
        console.error('[Checklists] Erro ao carregar operadores:', e);
        _toast('Erro ao carregar operadores: ' + e.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// KPIs
// ══════════════════════════════════════════════════════════
async function _atualizarKPIs() {
    try {
        const [rHist, rAlertas] = await Promise.all([
            fetch('../api/api_checklist.php?acao=listar'),
            fetch('../api/api_checklist_alertas.php?acao=listar_alertas&status=pendente'),
        ]);

        const dHist    = await rHist.json();
        const dAlertas = await rAlertas.json();

        if (dHist.sucesso) {
            const lista    = dHist.dados || [];
            const total    = lista.length;
            const abertos  = lista.filter(c => c.status === 'aberto').length;
            const fechados = lista.filter(c => c.status === 'fechado').length;

            _setKpi('chk_kpiTotal',    total);
            _setKpi('chk_kpiAbertos',  abertos);
            _setKpi('chk_kpiFechados', fechados);

            const badge = document.getElementById('chk_badgeAbertos');
            if (badge) {
                badge.textContent = abertos;
                badge.style.display = abertos > 0 ? 'inline-flex' : 'none';
            }
        }

        if (dAlertas.sucesso) {
            const qtdAlertas = (dAlertas.dados || []).length;
            _setKpi('chk_kpiAlertas', qtdAlertas);

            const badge = document.getElementById('chk_badgeAlertas');
            if (badge) {
                badge.textContent = qtdAlertas;
                badge.style.display = qtdAlertas > 0 ? 'inline-flex' : 'none';
            }
        }
    } catch (e) {
        console.warn('[Checklists] Erro ao atualizar KPIs:', e);
    }
}

function _setKpi(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

// ══════════════════════════════════════════════════════════
// CARREGAR ABERTOS
// ══════════════════════════════════════════════════════════
export async function carregarAbertos() {
    const tbody = document.getElementById('chk_tabelaAbertos');
    if (!tbody) return;

    tbody.innerHTML = `<tr class="empty-row"><td colspan="7"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></td></tr>`;

    try {
        const r = await fetch('../api/api_checklist.php?acao=listar_abertos');
        const d = await r.json();

        if (!d.sucesso) throw new Error(d.mensagem);

        const lista = d.dados || [];
        console.log(`[Checklists] ${lista.length} checklist(s) aberto(s).`);

        if (lista.length === 0) {
            tbody.innerHTML = `<tr class="empty-row"><td colspan="7"><i class="fas fa-check-circle" style="color:#10b981;font-size:2rem;"></i><p>Nenhum checklist aberto</p></td></tr>`;
            return;
        }

        tbody.innerHTML = lista.map(c => `
            <tr>
                <td><strong>#${c.id}</strong></td>
                <td><strong>${c.placa}</strong><br><small style="color:#64748b;">${c.veiculo_modelo || ''}</small></td>
                <td>${c.operador_nome || '—'}</td>
                <td>${_fmtKm(c.km_inicial)}</td>
                <td>${_fmtDataHora(c.data_hora_abertura)}</td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${c.observacao_abertura || ''}">${c.observacao_abertura || '—'}</td>
                <td>
                    <button class="btn-primary btn-xs" onclick="Checklists.abrirModalFechar(${c.id})">
                        <i class="fas fa-flag-checkered"></i> Fechar
                    </button>
                    <button class="btn-secondary btn-xs" style="margin-left:4px;" onclick="Checklists.abrirVisualizar(${c.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-danger btn-xs" style="margin-left:4px;" onclick="Checklists.abrirExcluir(${c.id},'${(c.placa + ' - ' + (c.veiculo_modelo||'')).replace(/'/g,"\\'")}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');

    } catch (e) {
        console.error('[Checklists] Erro ao carregar abertos:', e);
        tbody.innerHTML = `<tr class="empty-row"><td colspan="7"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Erro: ${e.message}</p></td></tr>`;
        _toast('Erro ao carregar checklists abertos: ' + e.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// CARREGAR HISTÓRICO
// ══════════════════════════════════════════════════════════
export async function carregarHistorico() {
    const tbody = document.getElementById('chk_tabelaHistorico');
    if (!tbody) return;

    tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></td></tr>`;

    const status  = document.getElementById('chk_filtroStatus')?.value  || '';
    const veiculo = document.getElementById('chk_filtroVeiculo')?.value || '';
    const dataIni = document.getElementById('chk_filtroDataIni')?.value || '';
    const dataFim = document.getElementById('chk_filtroDataFim')?.value || '';

    const params = new URLSearchParams({ acao: 'listar' });
    if (status)  params.set('status', status);
    if (veiculo) params.set('veiculo_id', veiculo);
    if (dataIni) params.set('data_inicio', dataIni);
    if (dataFim) params.set('data_fim', dataFim);

    try {
        const r = await fetch('../api/api_checklist.php?' + params.toString());
        const d = await r.json();

        if (!d.sucesso) throw new Error(d.mensagem);

        const lista = d.dados || [];
        console.log(`[Checklists] Histórico: ${lista.length} registro(s).`);

        if (lista.length === 0) {
            tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-search"></i><p>Nenhum checklist encontrado</p></td></tr>`;
            return;
        }

        tbody.innerHTML = lista.map(c => `
            <tr>
                <td><strong>#${c.id}</strong></td>
                <td><strong>${c.placa}</strong><br><small style="color:#64748b;">${c.veiculo_modelo || ''}</small></td>
                <td>${c.operador_nome || '—'}</td>
                <td>${_fmtKm(c.km_inicial)}</td>
                <td>${c.km_final ? _fmtKm(c.km_final) : '—'}</td>
                <td>${c.km_percorrido ? _fmtKm(c.km_percorrido) : '—'}</td>
                <td>${_fmtDataHora(c.data_hora_abertura)}</td>
                <td>${c.data_hora_fechamento ? _fmtDataHora(c.data_hora_fechamento) : '—'}</td>
                <td>${_statusBadge(c.status)}</td>
                <td>
                    <button class="btn-secondary btn-xs" onclick="Checklists.abrirVisualizar(${c.id})">
                        <i class="fas fa-eye"></i> Ver
                    </button>
                    ${c.status === 'aberto' ? `
                    <button class="btn-primary btn-xs" style="margin-left:4px;" onclick="Checklists.abrirModalFechar(${c.id})">
                        <i class="fas fa-flag-checkered"></i> Fechar
                    </button>
                    <button class="btn-danger btn-xs" style="margin-left:4px;" onclick="Checklists.abrirExcluir(${c.id},'${(c.placa + ' - ' + (c.veiculo_modelo||'')).replace(/'/g,"\\'")}')">
                        <i class="fas fa-trash"></i>
                    </button>` : ''}
                </td>
            </tr>
        `).join('');

    } catch (e) {
        console.error('[Checklists] Erro ao carregar histórico:', e);
        tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Erro: ${e.message}</p></td></tr>`;
        _toast('Erro ao carregar histórico: ' + e.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// CARREGAR ALERTAS
// ══════════════════════════════════════════════════════════
export async function carregarAlertas() {
    const tbody = document.getElementById('chk_tabelaAlertas');
    if (!tbody) return;

    tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i><p>Carregando alertas...</p></td></tr>`;

    const status = document.getElementById('chk_filtroAlertaStatus')?.value || 'pendente';
    const params = new URLSearchParams({ acao: 'listar_alertas' });
    if (status) params.set('status', status);

    try {
        const r = await fetch('../api/api_checklist_alertas.php?' + params.toString());
        const d = await r.json();

        if (!d.sucesso) throw new Error(d.mensagem);

        const lista = d.dados || [];
        console.log(`[Checklists] Alertas: ${lista.length} registro(s).`);

        if (lista.length === 0) {
            tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-bell-slash" style="color:#10b981;font-size:2rem;"></i><p>Nenhum alerta ${status || ''}</p></td></tr>`;
            return;
        }

        tbody.innerHTML = lista.map(a => `
            <tr>
                <td><strong>#${a.id}</strong></td>
                <td><strong>${a.placa || '—'}</strong><br><small style="color:#64748b;">${a.veiculo_modelo || ''}</small></td>
                <td><span style="font-size:.75rem;background:#f1f5f9;padding:2px 8px;border-radius:4px;">${a.categoria || '—'}</span></td>
                <td style="max-width:200px;">${a.descricao || a.nome_alerta || '—'}</td>
                <td>${_fmtKm(a.km_atual)}</td>
                <td>${_fmtKm(a.km_limite)}</td>
                <td style="color:${a.km_excedido > 0 ? '#ef4444' : '#10b981'};font-weight:700;">
                    ${a.km_excedido > 0 ? '+' + Number(a.km_excedido).toLocaleString('pt-BR') + ' km' : '—'}
                </td>
                <td>${_fmtData(a.data_geracao)}</td>
                <td>${_statusBadge(a.status)}</td>
                <td>
                    ${a.status === 'pendente' ? `
                    <button class="btn-success btn-xs" onclick="Checklists.abrirModalAlerta(${a.id},'resolver','${(a.descricao||a.nome_alerta||'').replace(/'/g,"\\'")}')">
                        <i class="fas fa-check"></i> Resolver
                    </button>
                    <button class="btn-warning btn-xs" style="margin-left:4px;" onclick="Checklists.abrirModalAlerta(${a.id},'ignorar','${(a.descricao||a.nome_alerta||'').replace(/'/g,"\\'")}')">
                        <i class="fas fa-ban"></i> Ignorar
                    </button>` : '—'}
                </td>
            </tr>
        `).join('');

    } catch (e) {
        console.error('[Checklists] Erro ao carregar alertas:', e);
        tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Erro: ${e.message}</p></td></tr>`;
        _toast('Erro ao carregar alertas: ' + e.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// SUBMIT: ABRIR CHECKLIST
// ══════════════════════════════════════════════════════════
async function _onSubmitAbertura(e) {
    e.preventDefault();

    const btn = document.getElementById('chk_btnAbrirChecklist');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const veiculoId  = document.getElementById('chk_veiculo')?.value;
        const operadorId = document.getElementById('chk_operador')?.value;
        const kmInicial  = document.getElementById('chk_kmInicial')?.value;
        const dataAbert  = (document.getElementById('chk_dataAbertura')?.value || '').replace('T', ' ') + ':00';
        const obs        = document.getElementById('chk_obsAbertura')?.value || '';

        if (!veiculoId || !operadorId || !kmInicial) {
            _toast('Preencha todos os campos obrigatórios.', 'warning');
            return;
        }

        const itens = _coletarItensAbertura();
        if (!itens) return;

        // Criar checklist
        const fd = new FormData();
        fd.append('acao', 'criar');
        fd.append('veiculo_id', veiculoId);
        fd.append('operador_id', operadorId);
        fd.append('km_inicial', kmInicial);
        fd.append('data_hora_abertura', dataAbert);
        fd.append('observacao_abertura', obs);

        const r1 = await fetch('../api/api_checklist.php', { method: 'POST', body: fd });
        const d1 = await r1.json();
        if (!d1.sucesso) throw new Error(d1.mensagem);

        const checklistId = d1.dados.id;
        console.log(`[Checklists] Checklist criado: #${checklistId}`);

        // Salvar itens de abertura
        const fd2 = new FormData();
        fd2.append('acao', 'salvar_abertura');
        fd2.append('checklist_id', checklistId);
        fd2.append('itens', JSON.stringify(itens));

        const r2 = await fetch('../api/api_checklist_itens.php', { method: 'POST', body: fd2 });
        const d2 = await r2.json();
        if (!d2.sucesso) throw new Error('Checklist criado, mas erro ao salvar itens: ' + d2.mensagem);

        console.log('[Checklists] Itens de abertura salvos.');
        _toast(`Checklist #${checklistId} aberto com sucesso!`, 'success');

        _limparFormAbertura();
        _atualizarKPIs();
        carregarAbertos();
        _ativarTab('chk-abertos');

    } catch (e) {
        console.error('[Checklists] Erro ao abrir checklist:', e);
        _toast('Erro: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clipboard-check"></i> Abrir Checklist'; }
    }
}

function _coletarItensAbertura() {
    const itens = [];
    let valido = true;

    CHK_TODOS_ITENS.forEach(item => {
        const checked = document.querySelector(`input[name="${item.radio}"]:checked`);
        if (!checked) {
            _toast(`Selecione um valor para: ${item.nome}`, 'warning');
            valido = false;
            return;
        }
        itens.push({
            tipo_item:      item.tipo_item,
            nome_item:      item.nome,
            categoria:      item.categoria,
            valor_abertura: checked.value,
        });
    });

    return valido ? itens : null;
}

function _limparFormAbertura() {
    const form = document.getElementById('chk_formAbertura');
    if (form) form.reset();
    _setDatetimeDefault('chk_dataAbertura');
}

// ══════════════════════════════════════════════════════════
// MODAL FECHAR CHECKLIST
// ══════════════════════════════════════════════════════════
export async function abrirModalFechar(id) {
    console.log(`[Checklists] Abrindo modal de fechamento para #${id}`);

    try {
        const r = await fetch(`../api/api_checklist.php?acao=buscar&id=${id}`);
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);

        const c = d.dados;

        const infoEl = document.getElementById('chk_infoFecharTexto');
        if (infoEl) infoEl.textContent = `${c.placa} — ${c.veiculo_modelo || ''} | Operador: ${c.operador_nome || '—'} | KM Inicial: ${Number(c.km_inicial).toLocaleString('pt-BR')} km`;

        const fecharId = document.getElementById('chk_fecharId');
        const fecharKm = document.getElementById('chk_fecharKmInicial');
        if (fecharId) fecharId.value = id;
        if (fecharKm) fecharKm.value = c.km_inicial;

        const kmFinalEl = document.getElementById('chk_kmFinal');
        if (kmFinalEl) {
            kmFinalEl.value = '';
            kmFinalEl.min = parseInt(c.km_inicial) + 1;
        }

        _setDatetimeDefault('chk_dataFechamento');

        const obsEl = document.getElementById('chk_obsFechamento');
        if (obsEl) obsEl.value = '';

        // Buscar itens de abertura para pré-exibir no fechamento
        const r2 = await fetch(`../api/api_checklist_itens.php?acao=buscar_por_checklist&checklist_id=${id}`);
        const d2 = await r2.json();

        _renderItensFechar(d2.sucesso ? (d2.dados || []) : []);
        _atualizarKmHint();
        _abrirModal('chk_modalFechar');

    } catch (e) {
        console.error('[Checklists] Erro ao abrir modal de fechamento:', e);
        _toast('Erro ao carregar checklist: ' + e.message, 'error');
    }
}

function _renderItensFechar(itensAbertura) {
    const nivelEl = document.getElementById('chk_itensNivelFechamento');
    const funcEl  = document.getElementById('chk_itensFuncFechamento');
    if (!nivelEl || !funcEl) return;

    const mapaAbertura = {};
    itensAbertura.forEach(i => { mapaAbertura[i.categoria] = i; });

    function renderItem(item, tipo) {
        const aberturaVal = mapaAbertura[item.categoria]?.valor_abertura || '';
        const aberturaLabel = aberturaVal
            ? `<div style="margin-bottom:.5rem;"><small style="color:#94a3b8;">Abertura: ${_valorBadge(aberturaVal)}</small></div>`
            : '';

        if (tipo === 'nivel') {
            return `
            <div class="chk-item-card" data-categoria="${item.categoria}">
                <div class="chk-item-header">
                    <i class="fas fa-tint chk-item-icon"></i>
                    <span class="chk-item-nome">${item.nome}</span>
                </div>
                ${aberturaLabel}
                <div class="chk-radio-group">
                    <label class="chk-radio-opt chk-nivel-min">
                        <input type="radio" name="fech_${item.categoria}" value="minimo" required>
                        <i class="fas fa-exclamation-triangle"></i> Mínimo
                    </label>
                    <label class="chk-radio-opt chk-nivel-med">
                        <input type="radio" name="fech_${item.categoria}" value="medio">
                        <i class="fas fa-minus-circle"></i> Médio
                    </label>
                    <label class="chk-radio-opt chk-nivel-max">
                        <input type="radio" name="fech_${item.categoria}" value="maximo">
                        <i class="fas fa-check-circle"></i> Máximo
                    </label>
                </div>
            </div>`;
        } else {
            return `
            <div class="chk-item-card" data-categoria="${item.categoria}">
                <div class="chk-item-header">
                    <i class="fas fa-check-square chk-item-icon"></i>
                    <span class="chk-item-nome">${item.nome}</span>
                </div>
                ${aberturaLabel}
                <div class="chk-radio-group">
                    <label class="chk-radio-opt chk-func-sim">
                        <input type="radio" name="fech_${item.categoria}" value="sim" required>
                        <i class="fas fa-check-circle"></i> Sim
                    </label>
                    <label class="chk-radio-opt chk-func-nao">
                        <input type="radio" name="fech_${item.categoria}" value="nao">
                        <i class="fas fa-times-circle"></i> Não
                    </label>
                </div>
            </div>`;
        }
    }

    nivelEl.innerHTML = CHK_ITENS_NIVEL.map(i => renderItem(i, 'nivel')).join('');
    funcEl.innerHTML  = CHK_ITENS_FUNC.map(i  => renderItem(i, 'funcional')).join('');
}

async function _onConfirmarFechamento() {
    const id        = document.getElementById('chk_fecharId')?.value;
    const kmInicial = parseInt(document.getElementById('chk_fecharKmInicial')?.value || '0');
    const kmFinal   = parseInt(document.getElementById('chk_kmFinal')?.value || '0');
    const dataFech  = (document.getElementById('chk_dataFechamento')?.value || '').replace('T', ' ') + ':00';
    const obs       = document.getElementById('chk_obsFechamento')?.value || '';

    if (!id || kmFinal <= 0) {
        _toast('Informe o KM final.', 'warning');
        return;
    }

    if (kmFinal <= kmInicial) {
        _toast(`KM final deve ser maior que o KM inicial (${kmInicial.toLocaleString('pt-BR')} km).`, 'warning');
        return;
    }

    const btn = document.getElementById('chk_btnConfirmarFechar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fechando...'; }

    try {
        // Coletar itens de fechamento
        const itensFechamento = [];
        let valido = true;

        CHK_TODOS_ITENS.forEach(item => {
            const checked = document.querySelector(`input[name="fech_${item.categoria}"]:checked`);
            if (!checked) {
                _toast(`Selecione o valor de fechamento para: ${item.nome}`, 'warning');
                valido = false;
                return;
            }
            itensFechamento.push({ categoria: item.categoria, valor_fechamento: checked.value });
        });

        if (!valido) return;

        // Salvar itens de fechamento
        const fd1 = new FormData();
        fd1.append('acao', 'salvar_fechamento');
        fd1.append('checklist_id', id);
        fd1.append('itens', JSON.stringify(itensFechamento));

        const r1 = await fetch('../api/api_checklist_itens.php', { method: 'POST', body: fd1 });
        const d1 = await r1.json();
        if (!d1.sucesso) throw new Error('Erro ao salvar itens de fechamento: ' + d1.mensagem);

        // Fechar checklist
        const fd2 = new FormData();
        fd2.append('acao', 'fechar');
        fd2.append('id', id);
        fd2.append('km_final', kmFinal);
        fd2.append('data_hora_fechamento', dataFech);
        fd2.append('observacao_fechamento', obs);

        const r2 = await fetch('../api/api_checklist.php', { method: 'POST', body: fd2 });
        const d2 = await r2.json();
        if (!d2.sucesso) throw new Error(d2.mensagem);

        console.log(`[Checklists] Checklist #${id} fechado com sucesso.`);
        _toast(`Checklist #${id} fechado com sucesso!`, 'success');
        _fecharModal('chk_modalFechar');
        _atualizarKPIs();
        carregarAbertos();
        carregarHistorico();
        carregarAlertas();

    } catch (e) {
        console.error('[Checklists] Erro ao fechar checklist:', e);
        _toast('Erro: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Confirmar Fechamento'; }
    }
}

// ══════════════════════════════════════════════════════════
// MODAL VISUALIZAR
// ══════════════════════════════════════════════════════════
export async function abrirVisualizar(id) {
    const conteudo = document.getElementById('chk_visualizarConteudo');
    if (!conteudo) return;

    conteudo.innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#2563eb;"></i></div>';
    _abrirModal('chk_modalVisualizar');

    try {
        const [r1, r2] = await Promise.all([
            fetch(`../api/api_checklist.php?acao=buscar&id=${id}`),
            fetch(`../api/api_checklist_itens.php?acao=buscar_por_checklist&checklist_id=${id}`),
        ]);

        const d1 = await r1.json();
        const d2 = await r2.json();

        if (!d1.sucesso) throw new Error(d1.mensagem);

        const c    = d1.dados;
        const itens = d2.sucesso ? (d2.dados || []) : [];
        const kmPercorrido = c.km_final ? (parseInt(c.km_final) - parseInt(c.km_inicial)) : null;

        conteudo.innerHTML = `
            <div class="chk-detail-grid">
                <div class="chk-detail-item"><label>Veículo</label><span>${c.placa} — ${c.veiculo_modelo || ''} (${c.veiculo_ano || ''})</span></div>
                <div class="chk-detail-item"><label>Operador</label><span>${c.operador_nome || '—'}</span></div>
                <div class="chk-detail-item"><label>Status</label><span>${_statusBadge(c.status)}</span></div>
                <div class="chk-detail-item"><label>KM Inicial</label><span>${_fmtKm(c.km_inicial)}</span></div>
                <div class="chk-detail-item"><label>KM Final</label><span>${c.km_final ? _fmtKm(c.km_final) : '—'}</span></div>
                <div class="chk-detail-item"><label>KM Percorrido</label><span>${kmPercorrido != null ? _fmtKm(kmPercorrido) : '—'}</span></div>
                <div class="chk-detail-item"><label>Abertura</label><span>${_fmtDataHora(c.data_hora_abertura)}</span></div>
                <div class="chk-detail-item"><label>Fechamento</label><span>${c.data_hora_fechamento ? _fmtDataHora(c.data_hora_fechamento) : '—'}</span></div>
            </div>
            ${c.observacao_abertura ? `<div style="margin-bottom:1rem;"><strong style="font-size:.8rem;color:#94a3b8;text-transform:uppercase;">Obs. Abertura:</strong><p style="margin:.25rem 0 0;font-size:.875rem;">${c.observacao_abertura}</p></div>` : ''}
            ${c.observacao_fechamento ? `<div style="margin-bottom:1rem;"><strong style="font-size:.8rem;color:#94a3b8;text-transform:uppercase;">Obs. Fechamento:</strong><p style="margin:.25rem 0 0;font-size:.875rem;">${c.observacao_fechamento}</p></div>` : ''}
            ${itens.length > 0 ? `
            <h4 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:1rem 0 .5rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
                <i class="fas fa-list-check" style="color:#2563eb;margin-right:6px;"></i>Itens Inspecionados
            </h4>
            <table class="chk-items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Tipo</th>
                        <th>Abertura</th>
                        <th>Fechamento</th>
                    </tr>
                </thead>
                <tbody>
                    ${itens.map(i => `
                    <tr>
                        <td>${i.nome_item}</td>
                        <td><span style="font-size:.72rem;background:#f1f5f9;padding:2px 6px;border-radius:4px;">${i.tipo_item}</span></td>
                        <td>${_valorBadge(i.valor_abertura)}</td>
                        <td>${i.valor_fechamento ? _valorBadge(i.valor_fechamento) : '—'}</td>
                    </tr>`).join('')}
                </tbody>
            </table>` : '<p style="color:#94a3b8;font-size:.875rem;margin-top:1rem;">Nenhum item registrado.</p>'}
        `;

    } catch (e) {
        console.error('[Checklists] Erro ao visualizar checklist:', e);
        conteudo.innerHTML = `<p style="color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Erro: ${e.message}</p>`;
    }
}

// ══════════════════════════════════════════════════════════
// MODAL ALERTAS
// ══════════════════════════════════════════════════════════
export function abrirModalAlerta(id, acao, descricao) {
    const titulo = document.getElementById('chk_modalAlertaTitulo');
    const info   = document.getElementById('chk_infoAlertaTexto');
    const idEl   = document.getElementById('chk_alertaId');
    const acaoEl = document.getElementById('chk_alertaAcao');
    const obsEl  = document.getElementById('chk_alertaObs');

    if (titulo) titulo.innerHTML = acao === 'resolver'
        ? '<i class="fas fa-check-circle" style="margin-right:8px;"></i>Resolver Alerta'
        : '<i class="fas fa-ban" style="margin-right:8px;"></i>Ignorar Alerta';

    if (info)   info.textContent = descricao;
    if (idEl)   idEl.value  = id;
    if (acaoEl) acaoEl.value = acao;
    if (obsEl)  obsEl.value = '';

    _abrirModal('chk_modalAlerta');
}

async function _onConfirmarAlerta() {
    const id   = document.getElementById('chk_alertaId')?.value;
    const acao = document.getElementById('chk_alertaAcao')?.value;
    const obs  = document.getElementById('chk_alertaObs')?.value?.trim();

    if (!obs) {
        _toast('Informe uma observação.', 'warning');
        return;
    }

    const btn = document.getElementById('chk_btnConfirmarAlerta');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const fd = new FormData();
        fd.append('acao', acao === 'resolver' ? 'resolver_alerta' : 'ignorar_alerta');
        fd.append('id', id);
        fd.append('observacao_resolucao', obs);

        const r = await fetch('../api/api_checklist_alertas.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);

        _toast(`Alerta ${acao === 'resolver' ? 'resolvido' : 'ignorado'} com sucesso!`, 'success');
        _fecharModal('chk_modalAlerta');
        carregarAlertas();
        _atualizarKPIs();

    } catch (e) {
        console.error('[Checklists] Erro ao processar alerta:', e);
        _toast('Erro: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Confirmar'; }
    }
}

// ══════════════════════════════════════════════════════════
// MODAL EXCLUIR
// ══════════════════════════════════════════════════════════
export function abrirExcluir(id, nome) {
    const nomeEl = document.getElementById('chk_excluirNome');
    const idEl   = document.getElementById('chk_excluirId');
    if (nomeEl) nomeEl.textContent = nome;
    if (idEl)   idEl.value = id;
    _abrirModal('chk_modalExcluir');
}

async function _onConfirmarExcluir() {
    const id = document.getElementById('chk_excluirId')?.value;
    if (!id) return;

    const btn = document.getElementById('chk_btnConfirmarExcluir');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...'; }

    try {
        const fd = new FormData();
        fd.append('acao', 'deletar');
        fd.append('id', id);

        const r = await fetch('../api/api_checklist.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);

        _toast('Checklist excluído com sucesso!', 'success');
        _fecharModal('chk_modalExcluir');
        _atualizarKPIs();
        carregarAbertos();
        carregarHistorico();

    } catch (e) {
        console.error('[Checklists] Erro ao excluir checklist:', e);
        _toast('Erro: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Sim, Excluir'; }
    }
}
