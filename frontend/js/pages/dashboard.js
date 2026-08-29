/**
 * Dashboard Page Module
 * Professional separation: HTML in pages/dashboard.html, Logic here
 */

const API_BASE = '../api/api_dashboard_agua.php';
const API_OS   = '../api/api_ordens_servico.php';
const DEBUG = true;

let _osInterval = null;
let _osLastSig  = '';

function log(msg, data = null) {
    if (DEBUG) {
        console.log('[Dashboard]', msg, data || '');
    }
}

// ========== LIFECYCLE FUNCTIONS ==========

/**
 * Initialize dashboard (called by AppRouter)
 */
export function init() {
    log('Inicializando Dashboard...');

    // A configuração de widgets é administrada exclusivamente em Empresa > Dashboard.
    // A tela inicial mantém o Dashboard operacional original.
    carregarChamados();
    _osInterval = setInterval(carregarChamados, 30000);

    // Load Chart.js if not already loaded
    if (typeof Chart === 'undefined') {
        loadChartJS().then(() => {
            carregarDados();
        });
    } else {
        carregarDados();
    }
}

/**
 * Cleanup (called by AppRouter before navigating away)
 */
export function destroy() {
    log('Limpando Dashboard...');
    if (_osInterval) {
        clearInterval(_osInterval);
        _osInterval = null;
    }
    _osLastSig = '';
}

// ========== CHART.JS LOADER ==========
function loadChartJS() {
    return new Promise((resolve, reject) => {
        if (document.querySelector('script[src*="Chart.js"]')) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Chart.js'));
        document.head.appendChild(script);
    });
}

// ========== DATA LOADING ==========
function carregarDados() {
    log('Carregando dados do dashboard...');
    carregarEstatisticas();
    carregarTopConsumo();
    carregarAbastecimento();
    carregarHistoricoAbastecimento();
}

// ========== ESTATÍSTICAS GERAIS ==========
function carregarEstatisticas() {
    log('Carregando estatísticas gerais...');

    fetch(API_BASE + '?estatisticas_gerais=1')
        .then(response => {
            log('Resposta recebida:', response.status);
            if (!response.ok) throw new Error('Erro HTTP: ' + response.status);
            return response.json();
        })
        .then(data => {
            log('Dados de estatísticas:', data);
            if (data.sucesso && data.dados) {
                const d = data.dados;
                document.getElementById('totalMoradores').textContent = d.total_moradores || 0;
                document.getElementById('totalConsumoAgua').textContent = (d.total_consumo_agua || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 }) + ' m³';
                document.getElementById('consumoMedioMorador').textContent = 'Média: ' + (d.consumo_medio_por_morador || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' m³/morador';
                document.getElementById('totalValorAgua').textContent = 'R$ ' + (d.total_valor_agua || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
            }
        })
        .catch(error => {
            log('Erro ao carregar estatísticas:', error);
            console.error('Erro:', error);
        });

    fetch(API_BASE + '?saldo_abastecimento=1')
        .then(response => {
            if (!response.ok) throw new Error('Erro HTTP: ' + response.status);
            return response.json();
        })
        .then(data => {
            log('Dados de saldo:', data);
            if (data.sucesso && data.dados) {
                const saldo = data.dados;
                document.getElementById('saldoAbastecimento').textContent = 'R$ ' + (saldo.saldo_atual || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                const statusEl = document.getElementById('statusSaldo');
                statusEl.textContent = saldo.status_saldo || 'Sem dados';
                statusEl.style.color = saldo.cor_status || '#6c757d';
            }
        })
        .catch(error => {
            log('Erro ao carregar saldo:', error);
            console.error('Erro:', error);
        });
}

// ========== TOP 10 CONSUMO DE ÁGUA ==========
function carregarTopConsumo() {
    log('Carregando top consumo...');

    fetch(API_BASE + '?top_consumo_agua=1')
        .then(response => {
            if (!response.ok) throw new Error('Erro HTTP: ' + response.status);
            return response.json();
        })
        .then(data => {
            log('Dados de top consumo:', data);
            if (data.sucesso && data.dados && data.dados.length > 0) {
                renderizarTopConsumo(data.dados);
            } else {
                document.getElementById('topConsumoContainer').innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhum dado de consumo disponível</p></div>';
            }
        })
        .catch(error => {
            log('Erro ao carregar top consumo:', error);
            console.error('Erro:', error);
            document.getElementById('topConsumoContainer').innerHTML = '<div class="error-state"><i class="fas fa-exclamation-triangle"></i>Erro ao carregar dados: ' + error.message + '</div>';
        });
}

function renderizarTopConsumo(dados) {
    let html = '<div class="table-container"><table>';
    html += '<thead><tr>';
    html += '<th style="width: 50px;">Pos.</th>';
    html += '<th>Unidade</th>';
    html += '<th>Nome do Morador</th>';
    html += '<th style="text-align: right;">Consumo (m³)</th>';
    html += '<th style="text-align: right;">Valor Total</th>';
    html += '<th>Última Leitura</th>';
    html += '<th style="text-align: right;">Leitura Valor</th>';
    html += '</tr></thead><tbody>';

    dados.forEach((morador, index) => {
        let badgeClass = 'badge';
        if (index === 0) badgeClass += ' top-1';
        else if (index === 1) badgeClass += ' top-2';
        else if (index === 2) badgeClass += ' top-3';

        html += '<tr>';
        html += '<td><span class="' + badgeClass + '">#' + morador.posicao + '</span></td>';
        html += '<td><strong>' + escapeHtml(morador.unidade || '-') + '</strong></td>';
        html += '<td>' + escapeHtml(morador.nome_morador || '-') + '</td>';
        html += '<td style="text-align: right;"><strong>' + (morador.consumo_total || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '</strong></td>';
        html += '<td style="text-align: right;">R$ ' + (morador.valor_total || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</td>';
        html += '<td>' + (morador.ultima_leitura_formatada || 'Sem leitura') + '</td>';
        html += '<td style="text-align: right;">' + (morador.ultima_leitura_valor || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('topConsumoContainer').innerHTML = html;
}

// ========== ABASTECIMENTO DE VEÍCULOS ==========
function carregarAbastecimento() {
    log('Carregando abastecimento...');

    fetch(API_BASE + '?ultimo_lancamento_abastecimento=1')
        .then(response => {
            if (!response.ok) throw new Error('Erro HTTP: ' + response.status);
            return response.json();
        })
        .then(data => {
            log('Dados de abastecimento:', data);
            if (data.sucesso && data.dados && Object.keys(data.dados).length > 0) {
                renderizarAbastecimento(data.dados);
            } else {
                document.getElementById('abastecimentoContainer').innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhum lançamento de abastecimento registrado</p></div>';
            }
        })
        .catch(error => {
            log('Erro ao carregar abastecimento:', error);
            console.error('Erro:', error);
            document.getElementById('abastecimentoContainer').innerHTML = '<div class="error-state"><i class="fas fa-exclamation-triangle"></i>Erro ao carregar dados: ' + error.message + '</div>';
        });
}

function renderizarAbastecimento(lancamento) {
    let html = '<div class="info-box">';
    html += '<h4><i class="fas fa-info-circle"></i> Último Lançamento de Abastecimento</h4>';
    html += '<p><strong>Veículo:</strong> ' + escapeHtml(lancamento.modelo || '-') + ' - ' + escapeHtml(lancamento.placa || '-') + '</p>';
    html += '<p><strong>Data:</strong> ' + (lancamento.data_abastecimento_formatada || '-') + '</p>';
    html += '<p><strong>Quilometragem:</strong> ' + (lancamento.km_abastecimento || 0).toLocaleString('pt-BR') + ' km</p>';
    html += '<p><strong>Combustível:</strong> ' + escapeHtml(lancamento.tipo_combustivel || '-') + '</p>';
    html += '<p><strong>Litros:</strong> ' + (lancamento.litros || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ' L</p>';
    html += '<p><strong>Valor:</strong> R$ ' + (lancamento.valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</p>';
    html += '<p><strong>Operador:</strong> ' + escapeHtml(lancamento.usuario_logado || '-') + '</p>';
    html += '</div>';

    // Carregar saldo
    fetch(API_BASE + '?saldo_abastecimento=1')
        .then(response => response.json())
        .then(data => {
            if (data.sucesso && data.dados) {
                const saldo = data.dados;
                html += '<div class="abastecimento-info">';
                html += '<div class="abastecimento-item">';
                html += '<h5>Saldo Atual</h5>';
                html += '<div class="value">R$ ' + (saldo.saldo_atual || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</div>';
                html += '</div>';
                html += '<div class="abastecimento-item">';
                html += '<h5>Saldo Mínimo</h5>';
                html += '<div class="value">R$ ' + (saldo.saldo_minimo || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</div>';
                html += '</div>';
                html += '<div class="abastecimento-item">';
                html += '<h5>Status</h5>';
                const statusClass = (saldo.status_saldo || 'normal').toLowerCase().replace(/ã/g, 'a').replace(/í/g, 'i');
                html += '<div class="value"><span class="badge status-' + statusClass + '">' + (saldo.status_saldo || '-') + '</span></div>';
                html += '</div>';
                html += '<div class="abastecimento-item">';
                html += '<h5>Abastecimentos Hoje</h5>';
                html += '<div class="value">' + (saldo.abastecimentos_hoje || 0) + '</div>';
                html += '</div>';
                html += '</div>';
                document.getElementById('abastecimentoContainer').innerHTML = html;
            }
        })
        .catch(error => console.error('Erro ao carregar saldo:', error));
}

// ========== HISTÓRICO DE ABASTECIMENTOS ==========
function carregarHistoricoAbastecimento() {
    log('Carregando histórico de abastecimentos...');

    fetch(API_BASE + '?historico_abastecimentos=1')
        .then(response => {
            if (!response.ok) throw new Error('Erro HTTP: ' + response.status);
            return response.json();
        })
        .then(data => {
            log('Dados de histórico:', data);
            if (data.sucesso && data.dados && data.dados.length > 0) {
                renderizarHistoricoAbastecimento(data.dados);
            } else {
                document.getElementById('historicoAbastecimentoContainer').innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhum histórico de abastecimento disponível</p></div>';
            }
        })
        .catch(error => {
            log('Erro ao carregar histórico:', error);
            console.error('Erro:', error);
            document.getElementById('historicoAbastecimentoContainer').innerHTML = '<div class="error-state"><i class="fas fa-exclamation-triangle"></i>Erro ao carregar dados: ' + error.message + '</div>';
        });
}

function renderizarHistoricoAbastecimento(dados) {
    let html = '<div class="table-container"><table>';
    html += '<thead><tr>';
    html += '<th>Data</th>';
    html += '<th>Veículo</th>';
    html += '<th>Placa</th>';
    html += '<th style="text-align: right;">KM</th>';
    html += '<th style="text-align: right;">Litros</th>';
    html += '<th>Combustível</th>';
    html += '<th style="text-align: right;">Valor</th>';
    html += '<th>Operador</th>';
    html += '</tr></thead><tbody>';

    dados.forEach(abastecimento => {
        html += '<tr>';
        html += '<td>' + (abastecimento.data_abastecimento_formatada || '-') + '</td>';
        html += '<td>' + escapeHtml(abastecimento.modelo || '-') + '</td>';
        html += '<td><strong>' + escapeHtml(abastecimento.placa || '-') + '</strong></td>';
        html += '<td style="text-align: right;">' + (abastecimento.km_abastecimento || 0).toLocaleString('pt-BR') + '</td>';
        html += '<td style="text-align: right;">' + (abastecimento.litros || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</td>';
        html += '<td>' + escapeHtml(abastecimento.tipo_combustivel || '-') + '</td>';
        html += '<td style="text-align: right;">R$ ' + (abastecimento.valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '</td>';
        html += '<td>' + escapeHtml(abastecimento.usuario_logado || '-') + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('historicoAbastecimentoContainer').innerHTML = html;
}

// ========== CHAMADOS / ORDENS DE SERVIÇO ==========
function carregarChamados() {
    fetch(API_OS + '?acao=dashboard_kpis', { credentials: 'include' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
            if (!data.sucesso || !data.dados) return;
            const d = data.dados;

            const el = id => document.getElementById(id);
            if (el('dashOsAbertos'))  el('dashOsAbertos').textContent  = d.abertos    ?? 0;
            if (el('dashOsAndamento')) el('dashOsAndamento').textContent = d.andamento  ?? 0;
            if (el('dashOsUrgentes')) el('dashOsUrgentes').textContent  = d.urgentes_abertas ?? 0;
            if (el('dashOsVencidos')) el('dashOsVencidos').textContent  = d.prazo_vencido    ?? 0;

            const hora = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            if (el('dashOsAtualizado')) el('dashOsAtualizado').textContent = 'Atualizado às ' + hora;

            const sig   = JSON.stringify((d.ultimas_os || []).map(o => o.id + ':' + o.status));
            const mudou = _osLastSig !== '' && sig !== _osLastSig;
            _osLastSig  = sig;

            const badge = el('dashOsBadge');
            if (badge) badge.classList.toggle('visivel', mudou);

            _renderizarChamados(d.ultimas_os || [], mudou);
        })
        .catch(err => log('Erro ao carregar chamados:', err));
}

function _renderizarChamados(lista, destacar) {
    const el = document.getElementById('dashOsLista');
    if (!el) return;

    if (!lista.length) {
        el.innerHTML = '<p class="dash-os-empty"><i class="fas fa-check-circle"></i> Nenhum chamado registrado.</p>';
        return;
    }

    let html = '<div class="dash-os-lista">';
    lista.forEach(os => {
        const novoCls = destacar ? ' novo' : '';
        html += `<div class="dash-os-item${novoCls}">`;
        html += `<span class="dash-os-prioridade ${escapeHtml(os.prioridade || 'baixa')}" title="${escapeHtml(os.prioridade || '')}"></span>`;
        html += `<span class="dash-os-num">#${escapeHtml(os.numero || String(os.id))}</span>`;
        html += `<span class="dash-os-titulo" title="${escapeHtml(os.titulo || '')}">${escapeHtml(os.titulo || '-')}</span>`;
        if (os.departamento) html += `<span class="dash-os-dept">${escapeHtml(os.departamento)}</span>`;
        html += `<span class="dash-os-status ${escapeHtml(os.status || '')}">${escapeHtml(os.status || '-')}</span>`;
        html += `<span class="dash-os-data">${escapeHtml(os.data_abertura || '')}</span>`;
        html += '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
}

// ========== FUNÇÕES AUXILIARES ==========
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ========== DASHBOARD PERSONALIZADO ==========
const DASH_PERSONAL_API = '../api/api_dashboard_personalizacao.php';
let _dashboardWidgets = [];
let _dashboardPersonalizadoAtivo = false;

async function carregarDashboardPersonalizado() {
    try {
        const response = await fetch(`${DASH_PERSONAL_API}?acao=usuario_config`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Configuração indisponível');
        _dashboardWidgets = data.dados?.widgets || [];
        _dashboardPersonalizadoAtivo = true;
        document.querySelectorAll('[id^="dashboard-legado-"]').forEach(el => { el.hidden = true; });
        document.getElementById('dashboard-personalizado').hidden = false;
        renderizarDashboardWidgets();
        configurarEventosDashboardPersonalizado();
    } catch (error) {
        log('Dashboard personalizado indisponível; mantendo dashboard legado.', error.message);
    }
}

function renderizarDashboardWidgets() {
    const grid = document.getElementById('dashboard-widgets-grid');
    const vazio = document.getElementById('dashboard-vazio');
    if (!grid || !vazio) return;
    const ativos = _dashboardWidgets.filter(widget => Number(widget.habilitado) === 1);
    grid.innerHTML = '';
    vazio.hidden = ativos.length > 0;
    ativos.forEach(widget => {
        const card = document.createElement('article');
        card.className = `dashboard-widget-card dashboard-widget-${escapeHtml(widget.widget_tipo || 'kpi')}`;
        card.dataset.widgetKey = widget.widget_key;
        card.innerHTML = `<div class="dashboard-widget-card-head"><span><i class="${escapeHtml(widget.modulo_icone || 'fas fa-chart-line')}"></i> ${escapeHtml(widget.modulo_nome || '')}</span><small>${escapeHtml(widget.widget_tipo || '')}</small></div><h3>${escapeHtml(widget.widget_nome || '')}</h3><div class="dashboard-widget-value"><i class="fas fa-spinner fa-spin"></i></div><p>${escapeHtml(widget.descricao || '')}</p>`;
        grid.appendChild(card);
        carregarDadosWidget(card, widget);
    });
}

async function carregarDadosWidget(card, widget) {
    const destino = card.querySelector('.dashboard-widget-value');
    try {
        if (widget.widget_key === 'os_abertas') {
            const response = await fetch(`${API_OS}?acao=dashboard_kpis`, { credentials: 'include' });
            const data = await response.json();
            if (data.sucesso) { destino.textContent = data.dados?.abertos ?? 0; return; }
        }
        const response = await fetch(`${DASH_PERSONAL_API}?acao=widget_data&widget_key=${encodeURIComponent(widget.widget_key)}`, { credentials: 'include' });
        const data = await response.json();
        if (data.sucesso && data.dados?.disponivel) destino.textContent = Number(data.dados.total || 0).toLocaleString('pt-BR');
        else destino.innerHTML = `<span class="dashboard-widget-empty"><i class="fas fa-inbox"></i> ${escapeHtml(data.dados?.mensagem || 'Nenhum dado disponível ainda.')}</span>`;
    } catch (error) {
        destino.innerHTML = '<span class="dashboard-widget-empty"><i class="fas fa-triangle-exclamation"></i> Não foi possível carregar.</span>';
    }
}

function configurarEventosDashboardPersonalizado() {
    document.getElementById('btnPersonalizarDashboard')?.addEventListener('click', () => {
        const editor = document.getElementById('dashboard-editor');
        if (!editor) return;
        editor.hidden = false;
        const lista = document.getElementById('dashboard-editor-lista');
        lista.innerHTML = _dashboardWidgets.map((widget, index) => `<label class="dashboard-editor-item" draggable="true" data-editor-widget="${escapeHtml(widget.widget_key)}"><input type="checkbox" ${Number(widget.habilitado) ? 'checked' : ''}><span>${escapeHtml(widget.widget_nome)}<small>${escapeHtml(widget.modulo_nome)}</small></span><b title="Arraste para reordenar">☷</b></label>`).join('');
        habilitarOrdenacaoDashboard(lista);
    });
    document.getElementById('btnCancelarPersonalizacao')?.addEventListener('click', () => { const el = document.getElementById('dashboard-editor'); if (el) el.hidden = true; });
    document.getElementById('btnSalvarPreferenciasDashboard')?.addEventListener('click', salvarPreferenciasDashboard);
    document.getElementById('btnRestaurarPreferenciasDashboard')?.addEventListener('click', async () => { _dashboardWidgets.forEach(w => { w.habilitado = 1; }); renderizarDashboardWidgets(); await salvarPreferenciasDashboard(); });
}

function habilitarOrdenacaoDashboard(lista) {
    if (!lista) return;
    let arrastado = null;
    lista.querySelectorAll('[data-editor-widget]').forEach(item => {
        item.addEventListener('dragstart', () => { arrastado = item; item.classList.add('arrastando'); });
        item.addEventListener('dragend', () => { arrastado = null; item.classList.remove('arrastando'); });
        item.addEventListener('dragover', event => { event.preventDefault(); if (arrastado && arrastado !== item) item.before(arrastado); });
    });
}

async function salvarPreferenciasDashboard() {
    const lista = document.getElementById('dashboard-editor-lista');
    if (lista) {
        const ordem = [...lista.querySelectorAll('[data-editor-widget]')];
        _dashboardWidgets.forEach(widget => {
            const item = ordem.find(el => el.dataset.editorWidget === widget.widget_key);
            widget.habilitado = item?.querySelector('input')?.checked ? 1 : 0;
            widget.posicao = item ? ordem.indexOf(item) : widget.posicao;
        });
    }
    try {
        const response = await fetch(`${DASH_PERSONAL_API}?acao=usuario_config`, { method: 'POST', headers: {'Content-Type':'application/json'}, credentials:'include', body: JSON.stringify({ widgets: _dashboardWidgets }) });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Não foi possível salvar');
        document.getElementById('dashboard-editor').hidden = true;
        renderizarDashboardWidgets();
    } catch (error) { console.error('[Dashboard] Erro ao salvar preferências:', error); }
}
