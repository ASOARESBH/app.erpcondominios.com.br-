'use strict';
/**
 * relatorios_bancarios.js — Relatórios Bancários
 * ES Module: export init() / destroy()  para AppRouter
 * Requer Chart.js no layout base (já presente no sistema)
 */

const API    = '/api/api_relatorios_bancarios.php';
const CB_API = '/api/api_contas_bancarias.php';

const S = {
    aba:     'extrato',
    contaId: 0,
    extrato: [], extrTotal: 0, extrOffset: 0, extrLimite: 100,
    chart:   null,
};

// ─────────────────────────────────────────────────────────────────
// CICLO DE VIDA
// ─────────────────────────────────────────────────────────────────
export function init() {
    window.RelatoriosBancariosPage = _pub();
    _inicializarFiltros();
    _carregarContas();
    carregarExtrato();
}

export function destroy() {
    if (S.chart) { S.chart.destroy(); S.chart = null; }
    window.RelatoriosBancariosPage = null;
}

// ─────────────────────────────────────────────────────────────────
// HTTP HELPERS
// ─────────────────────────────────────────────────────────────────
async function _get(qs) {
    const r = await fetch(`${API}?${qs}`, { credentials: 'include' });
    return r.json();
}
const _el  = id => document.getElementById(id);
const _val = id => (_el(id)?.value ?? '').trim();
const _moeda = v => 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const _data  = s => s ? s.split('-').reverse().join('/') : '—';
const _esc   = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function _toast(msg, tipo = 'sucesso') {
    const div = document.createElement('div');
    div.style.cssText = [
        'position:fixed','bottom:24px','right:24px','z-index:9999',
        'padding:12px 20px','border-radius:10px','font-size:14px','font-weight:500',
        'color:#fff','box-shadow:0 4px 20px rgba(0,0,0,.15)',
        tipo === 'erro' ? 'background:#ef4444' : 'background:#4338ca',
    ].join(';');
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 3500);
}

// ─────────────────────────────────────────────────────────────────
// INICIALIZAÇÃO
// ─────────────────────────────────────────────────────────────────
function _inicializarFiltros() {
    const hoje   = new Date();
    const ini    = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    const fmtDt  = d => d.toISOString().slice(0,10);

    const setV = (id, v) => { const el = _el(id); if (el) el.value = v; };
    setV('ext-dt-ini', fmtDt(ini));
    setV('ext-dt-fim', fmtDt(hoje));

    // Preencher select de ano no DRE
    const selAno = _el('dre-ano');
    if (selAno) {
        const ano = hoje.getFullYear();
        selAno.innerHTML = [ano, ano-1, ano-2].map(a =>
            `<option value="${a}">${a}</option>`).join('');
        selAno.value = ano;
    }
    setV('dre-dt-ini', `${hoje.getFullYear()}-01-01`);
    setV('dre-dt-fim', `${hoje.getFullYear()}-12-31`);
}

async function _carregarContas() {
    const d = await fetch(`${CB_API}?acao=listar_contas`, { credentials: 'include' }).then(r => r.json());
    if (!d.sucesso) return;
    const sel = _el('rb-sel-conta');
    if (!sel) return;
    sel.innerHTML = '<option value="0">Todas as Contas</option>'
        + (d.dados || []).map(c => `<option value="${c.id}">${_esc(c.nome)}</option>`).join('');
}

// ─────────────────────────────────────────────────────────────────
// ABA: EXTRATO
// ─────────────────────────────────────────────────────────────────
async function carregarExtrato(offset = 0) {
    S.extrOffset = offset;
    const tbody = _el('rb-tbody-extrato');
    if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="rb-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    const p = new URLSearchParams({
        acao:     'extrato',
        conta_id: S.contaId,
        dt_ini:   _val('ext-dt-ini'),
        dt_fim:   _val('ext-dt-fim'),
        tipo:     _val('ext-tipo'),
        status:   _val('ext-status'),
        busca:    _val('ext-busca'),
        limite:   S.extrLimite,
        offset,
    });

    const d = await _get(p.toString());
    if (!d.sucesso) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="10" class="rb-vazio">${_esc(d.mensagem)}</td></tr>`;
        return;
    }

    S.extrato   = d.dados.movimentacoes;
    S.extrTotal = d.dados.total;

    // Resumo
    const set = (id, v) => { const el = _el(id); if (el) el.textContent = v; };
    set('ext-total-credito',  _moeda(d.dados.total_credito));
    set('ext-total-debito',   _moeda(d.dados.total_debito));
    set('ext-saldo-periodo',  _moeda(d.dados.saldo_periodo));
    set('ext-total-regs',     d.dados.total);

    const saldoEl = _el('ext-saldo-periodo');
    if (saldoEl) saldoEl.className = 'rb-resumo-valor ' + (d.dados.saldo_periodo >= 0 ? 'verde' : 'vermelho');

    const badge = _el('ext-total-badge');
    if (badge) badge.textContent = `${S.extrTotal} registro${S.extrTotal !== 1 ? 's' : ''}`;

    if (!S.extrato.length) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="rb-vazio">Nenhuma movimentação encontrada para os filtros selecionados</td></tr>';
        const pag = _el('rb-pag-extrato');
        if (pag) pag.innerHTML = '';
        return;
    }

    if (tbody) {
        tbody.innerHTML = S.extrato.map(m => `<tr>
            <td style="white-space:nowrap;font-size:12px">${_data(m.data_lancamento)}</td>
            <td style="font-size:11.5px;color:#64748b">${_esc(m.conta_nome)}</td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${_esc(m.descricao)}">${_esc(m.descricao)}</td>
            <td style="font-size:12px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_esc(m.favorecido || '—')}</td>
            <td style="font-size:12px">${_esc(m.categoria || '—')}</td>
            <td style="font-size:12px">${_esc(m.checknum || m.numero_documento || '—')}</td>
            <td style="text-align:center"><span class="rb-badge ${m.tipo}">${m.tipo === 'credito' ? '▼' : '▲'}</span></td>
            <td class="text-right rb-valor-${m.tipo}" style="white-space:nowrap">${_moeda(m.valor)}</td>
            <td style="text-align:center"><span class="rb-badge ${m.status}">${_capFirst(m.status)}</span></td>
            <td style="text-align:center"><span class="rb-badge ${m.origem}">${m.origem.toUpperCase()}</span></td>
        </tr>`).join('');
    }

    _renderPag('rb-pag-extrato', S.extrTotal, S.extrLimite, offset, 'carregarExtrato');
}

function filtrarExtrato() {
    clearTimeout(S._timerExt);
    S._timerExt = setTimeout(() => carregarExtrato(0), 400);
}

function exportarCSV() {
    const p = new URLSearchParams({
        acao:     'exportar_csv',
        conta_id: S.contaId,
        dt_ini:   _val('ext-dt-ini'),
        dt_fim:   _val('ext-dt-fim'),
        tipo:     _val('ext-tipo'),
        status:   _val('ext-status'),
    });
    window.location.href = `${API}?${p.toString()}`;
}

// ─────────────────────────────────────────────────────────────────
// ABA: FLUXO DE CAIXA
// ─────────────────────────────────────────────────────────────────
async function carregarFluxo() {
    const tbody = _el('rb-tbody-fluxo');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="rb-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    const p = new URLSearchParams({
        acao:     'fluxo_caixa',
        conta_id: S.contaId,
        meses:    _val('fluxo-meses') || 12,
    });
    const d = await _get(p.toString());
    if (!d.sucesso) { _toast(d.mensagem, 'erro'); return; }

    const real = d.dados.realizado;
    const prev = d.dados.previsao;

    // Montar mapa mês → previsto
    const prevMap = {};
    (prev || []).forEach(r => { prevMap[r.mes] = r; });

    // Tabela
    if (tbody) {
        tbody.innerHTML = real.map(r => {
            const pv    = prevMap[r.mes] || {};
            const saldo = (parseFloat(r.entradas) - parseFloat(r.saidas));
            const saldoP= (parseFloat(pv.a_receber_previsto || 0) - parseFloat(pv.a_pagar_previsto || 0));
            return `<tr>
                <td style="font-weight:500">${r.mes_label}</td>
                <td class="text-right rb-valor-credito">${_moeda(r.entradas)}</td>
                <td class="text-right rb-valor-debito">${_moeda(r.saidas)}</td>
                <td class="text-right ${saldo >= 0 ? 'rb-valor-pos' : 'rb-valor-neg'}">${_moeda(saldo)}</td>
                <td class="text-right" style="color:#64748b">${_moeda(pv.a_receber_previsto || 0)}</td>
                <td class="text-right" style="color:#64748b">${_moeda(pv.a_pagar_previsto || 0)}</td>
                <td class="text-right ${saldoP >= 0 ? 'rb-valor-pos' : 'rb-valor-neg'}">${_moeda(saldoP)}</td>
            </tr>`;
        }).join('') || '<tr><td colspan="7" class="rb-vazio">Sem dados no período</td></tr>';
    }

    // Gráfico
    _renderGrafico(real, prevMap);
}

function _renderGrafico(real, prevMap) {
    const canvas = _el('rb-chart-fluxo');
    if (!canvas || !window.Chart) return;

    if (S.chart) { S.chart.destroy(); S.chart = null; }

    const labels    = real.map(r => r.mes_label);
    const entradas  = real.map(r => parseFloat(r.entradas));
    const saidas    = real.map(r => parseFloat(r.saidas));
    const a_receber = real.map(r => parseFloat(prevMap[r.mes]?.a_receber_previsto || 0));
    const a_pagar   = real.map(r => parseFloat(prevMap[r.mes]?.a_pagar_previsto   || 0));

    S.chart = new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Entradas',       data: entradas,  backgroundColor: '#22c55e', borderRadius: 4, order: 1 },
                { label: 'Saídas',         data: saidas,    backgroundColor: '#ef4444', borderRadius: 4, order: 2 },
                { label: 'A Receber (Prev)', data: a_receber, backgroundColor: 'rgba(134,239,172,.5)', borderRadius: 4, order: 3 },
                { label: 'A Pagar (Prev)',   data: a_pagar,   backgroundColor: 'rgba(252,165,165,.5)', borderRadius: 4, order: 4 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: {
                callbacks: {
                    label: ctx => `${ctx.dataset.label}: ${_moeda(ctx.parsed.y)}`,
                },
            }},
            scales: {
                x: { grid: { display: false } },
                y: {
                    ticks: {
                        callback: v => 'R$ ' + Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 0 }),
                    },
                },
            },
        },
    });
}

// ─────────────────────────────────────────────────────────────────
// ABA: DRE SIMPLIFICADO
// ─────────────────────────────────────────────────────────────────
async function carregarDRE() {
    const dtIni = _val('dre-dt-ini') || `${new Date().getFullYear()}-01-01`;
    const dtFim = _val('dre-dt-fim') || `${new Date().getFullYear()}-12-31`;

    _el('rb-dre-rows-rec').innerHTML = '<div style="padding:20px;text-align:center"><i class="fas fa-spinner fa-spin"></i></div>';
    _el('rb-dre-rows-des').innerHTML = '<div style="padding:20px;text-align:center"><i class="fas fa-spinner fa-spin"></i></div>';

    const p = new URLSearchParams({ acao: 'dre', conta_id: S.contaId, dt_ini: dtIni, dt_fim: dtFim });
    const d = await _get(p.toString());
    if (!d.sucesso) { _toast(d.mensagem, 'erro'); return; }

    const { receitas, despesas, total_receitas, total_despesas, resultado_liquido } = d.dados;

    _el('rb-dre-periodo').textContent = `${_data(dtIni)} a ${_data(dtFim)}`;
    _el('rb-dre-total-rec').textContent = _moeda(total_receitas);
    _el('rb-dre-total-des').textContent = _moeda(total_despesas);

    const liqEl = _el('rb-dre-liquido');
    if (liqEl) {
        liqEl.textContent  = _moeda(resultado_liquido);
        liqEl.className    = 'rb-dre-resultado-valor ' + (resultado_liquido >= 0 ? 'positivo' : 'negativo');
    }

    const mkRows = (arr, cls) => arr.length
        ? arr.map(r => `<div class="rb-dre-row">
            <span class="rb-dre-row-cat">${_esc(r.categoria)}</span>
            <span class="rb-dre-row-val ${cls}">${_moeda(r.total)}</span>
          </div>`).join('')
        : '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">Sem lançamentos</div>';

    _el('rb-dre-rows-rec').innerHTML = mkRows(receitas, 'verde');
    _el('rb-dre-rows-des').innerHTML = mkRows(despesas, 'vermelho');
}

// ─────────────────────────────────────────────────────────────────
// TABS E CONTA
// ─────────────────────────────────────────────────────────────────
function mudarAba(aba) {
    S.aba = aba;
    document.querySelectorAll('.rb-tab').forEach(b => b.classList.remove('ativa'));
    const btn = document.querySelector(`.rb-tab[onclick*="'${aba}'"]`);
    if (btn) btn.classList.add('ativa');

    ['extrato','fluxo','dre'].forEach(a => {
        const p = _el(`rb-panel-${a}`);
        if (p) p.classList.toggle('rb-oculto', a !== aba);
    });

    if (aba === 'fluxo' && !S.chart) carregarFluxo();
    if (aba === 'dre')               carregarDRE();
}

function mudarConta() {
    S.contaId = parseInt(_val('rb-sel-conta')) || 0;
    if (S.aba === 'extrato') carregarExtrato(0);
    if (S.aba === 'fluxo')  carregarFluxo();
    if (S.aba === 'dre')    carregarDRE();
}

// ─────────────────────────────────────────────────────────────────
// UTILITÁRIOS
// ─────────────────────────────────────────────────────────────────
function _capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'; }

function _renderPag(elId, total, limite, offset, fn) {
    const pag   = _el(elId);
    if (!pag) return;
    const pages = Math.ceil(total / limite);
    const cur   = Math.floor(offset / limite) + 1;
    if (pages <= 1) { pag.innerHTML = ''; return; }
    let h = '';
    if (cur > 1) h += `<button onclick="RelatoriosBancariosPage.${fn}(${(cur-2)*limite})">‹</button>`;
    for (let p = Math.max(1,cur-2); p <= Math.min(pages,cur+2); p++) {
        h += `<button class="${p===cur?'ativa':''}" onclick="RelatoriosBancariosPage.${fn}(${(p-1)*limite})">${p}</button>`;
    }
    if (cur < pages) h += `<button onclick="RelatoriosBancariosPage.${fn}(${cur*limite})">›</button>`;
    pag.innerHTML = h;
}

// ─────────────────────────────────────────────────────────────────
// API PÚBLICA
// ─────────────────────────────────────────────────────────────────
function _pub() {
    return {
        mudarAba, mudarConta,
        carregarExtrato, filtrarExtrato, exportarCSV,
        carregarFluxo, carregarDRE,
    };
}
