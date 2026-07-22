/**
 * Relatorios Hidrometro Page Module
 * 
 * Relatórios de leituras de hidrômetros com:
 *  - Filtros por data, unidade e morador
 *  - KPIs: total, consumo, valor, média
 *  - Exportação CSV e PDF (via print)
 * 
 * @module relatorios_hidrometro
 * @version 2.0.0
 */

'use strict';

// ============================================================
// CONSTANTES
// ============================================================
const API_LEITURAS  = window.location.origin + '/api/api_leituras.php';
const API_UNIDADES  = window.location.origin + '/api/api_unidades.php';
const API_MORADORES = window.location.origin + '/api/api_moradores.php';

// ============================================================
// ESTADO DO MÓDULO
// ============================================================
let _state = {
    resultados : [],
};

// ============================================================
// LIFECYCLE
// ============================================================

export function init() {
    console.log('[RelatorioHidrometro] Inicializando módulo v2.0...');

    _carregarUnidades();
    _carregarMoradores();
    _setFiltrosPadrao();

    window.RelatorioHidrometroPage = {
        pesquisar    : pesquisar,
        limparFiltros: limparFiltros,
        exportarCSV  : exportarCSV,
        exportarPDF  : exportarPDF,
    };

    console.log('[RelatorioHidrometro] Módulo pronto.');
}

export function destroy() {
    console.log('[RelatorioHidrometro] Destruindo módulo...');
    delete window.RelatorioHidrometroPage;
    _state = { resultados: [] };
}

// ============================================================
// FILTROS PADRÃO (último mês)
// ============================================================

function _setFiltrosPadrao() {
    const hoje    = new Date();
    const primDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);

    const fmt = d => d.toISOString().slice(0, 10);

    const campoInicial = document.getElementById('filtro_data_inicial');
    const campoFinal   = document.getElementById('filtro_data_final');

    if (campoInicial) campoInicial.value = fmt(primDia);
    if (campoFinal)   campoFinal.value   = fmt(hoje);
}

// ============================================================
// CARREGAMENTO DE SELECTS
// ============================================================

async function _carregarUnidades() {
    try {
        const data = await _apiCall(API_UNIDADES + '?acao=select');
        if (!data.sucesso) return;

        const lista = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
        const sel = document.getElementById('filtro_unidade');
        if (!sel) return;
        sel.innerHTML = '<option value="">Todas as unidades</option>';
        lista.forEach(u => {
            const val = u.unidade || u.nome || u;
            sel.add(new Option(val, val));
        });
    } catch (err) {
        console.error('[RelatorioHidrometro] Erro ao carregar unidades:', err);
    }
}

async function _carregarMoradores() {
    try {
        const data = await _apiCall(API_MORADORES + '?por_pagina=0');
        if (!data.sucesso) return;

        const sel = document.getElementById('filtro_morador');
        if (!sel) return;
        sel.innerHTML = '<option value="">Todos os moradores</option>';
        // api_moradores retorna dados paginados: { itens: [...], total, ... }
        const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        moradores.forEach(m => sel.add(new Option(m.nome, m.id)));
    } catch (err) {
        console.error('[RelatorioHidrometro] Erro ao carregar moradores:', err);
    }
}

// ============================================================
// PESQUISA
// ============================================================

async function pesquisar() {
    const dataInicial = document.getElementById('filtro_data_inicial')?.value || '';
    const dataFinal   = document.getElementById('filtro_data_final')?.value   || '';
    const unidade     = document.getElementById('filtro_unidade')?.value      || '';
    const moradorId   = document.getElementById('filtro_morador')?.value      || '';

    const loading = document.getElementById('loadingRelatorio');
    const tbody   = document.getElementById('listaRelatorio');

    if (loading) loading.style.display = 'block';
    if (tbody)   tbody.innerHTML = '';

    // Desabilitar botões de exportação
    _setExportButtons(false);

    try {
        const params = new URLSearchParams();
        if (dataInicial) params.append('data_inicial', dataInicial);
        if (dataFinal)   params.append('data_final',   dataFinal);
        if (unidade)     params.append('unidade',      unidade);
        if (moradorId)   params.append('morador_id',   moradorId);

        const url  = params.toString() ? `${API_LEITURAS}?${params}` : API_LEITURAS;
        const data = await _apiCall(url);

        if (!data.sucesso) throw new Error(data.mensagem);

        _state.resultados = data.dados || [];
        _renderResultados(_state.resultados);
        _atualizarKPIs(_state.resultados);

        if (_state.resultados.length > 0) {
            _setExportButtons(true);
        }

        console.log(`[RelatorioHidrometro] ${_state.resultados.length} registros encontrados.`);
    } catch (err) {
        console.error('[RelatorioHidrometro] Erro na pesquisa:', err);
        _toast('Erro ao pesquisar: ' + err.message, 'error');
        if (tbody) {
            tbody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="10">
                        <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                        <p>Erro ao carregar dados: ${err.message}</p>
                    </td>
                </tr>`;
        }
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

function limparFiltros() {
    document.getElementById('filtro_unidade') && (document.getElementById('filtro_unidade').value = '');
    document.getElementById('filtro_morador') && (document.getElementById('filtro_morador').value = '');
    _setFiltrosPadrao();

    const tbody = document.getElementById('listaRelatorio');
    if (tbody) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="10">
                    <i class="fas fa-search"></i>
                    <p>Use os filtros acima para gerar o relatório</p>
                </td>
            </tr>`;
    }

    const kpi = document.getElementById('kpiRelatorio');
    if (kpi) kpi.style.display = 'none';

    _setExportButtons(false);
    _state.resultados = [];
}

// ============================================================
// RENDER TABELA
// ============================================================

function _renderResultados(lista) {
    const tbody = document.getElementById('listaRelatorio');
    if (!tbody) return;

    if (lista.length === 0) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="10">
                    <i class="fas fa-search"></i>
                    <p>Nenhum resultado encontrado para os filtros informados</p>
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = lista.map(l => `
        <tr>
            <td>${_esc(l.data_leitura_formatada)}</td>
            <td>${_esc(l.unidade)}</td>
            <td>${_esc(l.morador_nome)}</td>
            <td><strong>${_esc(l.numero_hidrometro)}</strong></td>
            <td>${_esc(l.numero_lacre) || 'N/A'}</td>
            <td>${parseFloat(l.leitura_anterior).toFixed(2)}</td>
            <td>${parseFloat(l.leitura_atual).toFixed(2)}</td>
            <td><strong>${parseFloat(l.consumo).toFixed(2)}</strong></td>
            <td><strong>R$ ${parseFloat(l.valor_total).toFixed(2).replace('.', ',')}</strong></td>
            <td><span class="badge badge-info">${_esc(l.lancado_por_descricao || l.lancado_por_nome || '—')}</span></td>
        </tr>`
    ).join('');
}

// ============================================================
// KPIs
// ============================================================

function _atualizarKPIs(lista) {
    const kpiBox = document.getElementById('kpiRelatorio');
    if (!kpiBox) return;

    if (lista.length === 0) {
        kpiBox.style.display = 'none';
        return;
    }

    kpiBox.style.display = 'grid';

    const total   = lista.length;
    const consumo = lista.reduce((s, l) => s + parseFloat(l.consumo || 0), 0);
    const valor   = lista.reduce((s, l) => s + parseFloat(l.valor_total || 0), 0);
    const media   = total > 0 ? consumo / total : 0;

    _setEl('rel_kpi_total',   total);
    _setEl('rel_kpi_consumo', consumo.toFixed(2) + ' m³');
    _setEl('rel_kpi_valor',   'R$ ' + valor.toFixed(2).replace('.', ','));
    _setEl('rel_kpi_media',   media.toFixed(2) + ' m³');
}

// ============================================================
// EXPORTAÇÕES
// ============================================================

function exportarCSV() {
    if (_state.resultados.length === 0) {
        _toast('Nenhum dado para exportar.', 'warning');
        return;
    }

    const cabecalho = [
        'Data/Hora', 'Unidade', 'Morador', 'Nº Hidrômetro', 'Nº Lacre',
        'Leit. Anterior (m³)', 'Leit. Atual (m³)', 'Consumo (m³)',
        'Valor (R$)', 'Lançado por'
    ];

    const linhas = _state.resultados.map(l => [
        l.data_leitura_formatada,
        l.unidade,
        l.morador_nome,
        l.numero_hidrometro,
        l.numero_lacre || 'N/A',
        parseFloat(l.leitura_anterior).toFixed(2),
        parseFloat(l.leitura_atual).toFixed(2),
        parseFloat(l.consumo).toFixed(2),
        parseFloat(l.valor_total).toFixed(2),
        l.lancado_por_nome || '—',
    ].map(v => `"${String(v).replace(/"/g, '""')}"`));

    const csv = [cabecalho.join(';'), ...linhas.map(l => l.join(';'))].join('\n');
    const bom  = '\uFEFF'; // BOM para Excel reconhecer UTF-8
    const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href     = url;
    link.download = `relatorio_hidrometros_${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();

    URL.revokeObjectURL(url);
    _toast('Relatório CSV exportado com sucesso!', 'success');
    console.log('[RelatorioHidrometro] CSV exportado.');
}

function exportarPDF() {
    if (_state.resultados.length === 0) {
        _toast('Nenhum dado para exportar.', 'warning');
        return;
    }

    // Gera uma janela de impressão com a tabela formatada
    const dataInicial = document.getElementById('filtro_data_inicial')?.value || '—';
    const dataFinal   = document.getElementById('filtro_data_final')?.value   || '—';
    const unidade     = document.getElementById('filtro_unidade')?.value      || 'Todas';
    const morador     = document.getElementById('filtro_morador')?.options[document.getElementById('filtro_morador')?.selectedIndex]?.text || 'Todos';

    const total   = _state.resultados.length;
    const consumo = _state.resultados.reduce((s, l) => s + parseFloat(l.consumo || 0), 0);
    const valor   = _state.resultados.reduce((s, l) => s + parseFloat(l.valor_total || 0), 0);

    const linhas = _state.resultados.map(l => `
        <tr>
            <td>${_esc(l.data_leitura_formatada)}</td>
            <td>${_esc(l.unidade)}</td>
            <td>${_esc(l.morador_nome)}</td>
            <td>${_esc(l.numero_hidrometro)}</td>
            <td>${parseFloat(l.leitura_anterior).toFixed(2)}</td>
            <td>${parseFloat(l.leitura_atual).toFixed(2)}</td>
            <td><strong>${parseFloat(l.consumo).toFixed(2)}</strong></td>
            <td><strong>R$ ${parseFloat(l.valor_total).toFixed(2).replace('.', ',')}</strong></td>
        </tr>`
    ).join('');

    const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Hidrômetros</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1e293b; margin: 20px; }
        h1   { font-size: 18px; color: #1e3a8a; margin-bottom: 4px; }
        .sub { color: #64748b; margin-bottom: 16px; font-size: 11px; }
        .filtros { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; }
        .filtros span { margin-right: 20px; }
        .kpis { display: flex; gap: 12px; margin-bottom: 16px; }
        .kpi  { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 8px 14px; flex: 1; text-align: center; }
        .kpi strong { display: block; font-size: 16px; color: #1e3a8a; }
        .kpi span   { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; }
        th    { background: #1e3a8a; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td    { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
        tr:nth-child(even) td { background: #f8fafc; }
        @media print { body { margin: 10px; } }
    </style>
</head>
<body>
    <h1>Relatório de Hidrômetros — ERP Condomínio</h1>
    <div class="sub">Gerado em: ${new Date().toLocaleString('pt-BR')}</div>
    <div class="filtros">
        <span><strong>Período:</strong> ${dataInicial} a ${dataFinal}</span>
        <span><strong>Unidade:</strong> ${_esc(unidade)}</span>
        <span><strong>Morador:</strong> ${_esc(morador)}</span>
    </div>
    <div class="kpis">
        <div class="kpi"><strong>${total}</strong><span>Total Leituras</span></div>
        <div class="kpi"><strong>${consumo.toFixed(2)} m³</strong><span>Consumo Total</span></div>
        <div class="kpi"><strong>R$ ${valor.toFixed(2).replace('.', ',')}</strong><span>Valor Total</span></div>
        <div class="kpi"><strong>${(consumo / (total || 1)).toFixed(2)} m³</strong><span>Consumo Médio</span></div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Data/Hora</th><th>Unidade</th><th>Morador</th><th>Nº Hidrômetro</th>
                <th>Leit. Ant.</th><th>Leit. Atual</th><th>Consumo</th><th>Valor</th>
            </tr>
        </thead>
        <tbody>${linhas}</tbody>
    </table>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=1000,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 500);

    _toast('Janela de impressão aberta. Use Ctrl+P para salvar como PDF.', 'info');
    console.log('[RelatorioHidrometro] PDF (print) iniciado.');
}

// ============================================================
// HELPERS
// ============================================================

function _setExportButtons(enabled) {
    ['btnExportCSV', 'btnExportPDF'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = !enabled;
    });
}

// ============================================================
// API HELPER
// ============================================================

async function _apiCall(url, options = {}) {
    const defaultOptions = {
        credentials : 'include',
        headers     : { 'Accept': 'application/json', ...(options.headers || {}) },
    };

    const response = await fetch(url, { ...defaultOptions, ...options });

    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        const text = await response.text();
        console.error('[RelatorioHidrometro] Resposta não-JSON:', text.slice(0, 200));
        throw new Error(`Servidor retornou resposta inválida (HTTP ${response.status})`);
    }

    const data = await response.json();
    if (!response.ok && data.mensagem) throw new Error(data.mensagem);
    return data;
}

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================

function _toast(mensagem, tipo = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const icons = {
        success : 'fa-check-circle',
        error   : 'fa-exclamation-circle',
        warning : 'fa-exclamation-triangle',
        info    : 'fa-info-circle',
    };

    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;
    toast.innerHTML = `
        <i class="fas ${icons[tipo] || icons.info}"></i>
        <span>${_esc(mensagem)}</span>`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// ============================================================
// UTILITÁRIOS
// ============================================================

function _esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function _setEl(id, valor, prop = 'textContent') {
    const el = document.getElementById(id);
    if (el) el[prop] = valor ?? '';
}
