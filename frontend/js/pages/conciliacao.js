'use strict';
/**
 * conciliacao.js — Módulo de Conciliação Bancária
 * ES Module: export init() / destroy()  para AppRouter
 */

const API    = '/api/api_conciliacao.php';
const CB_API = '/api/api_contas_bancarias.php';

const S = {
    aba:        'pendentes',
    contaId:    0,
    // pendentes
    pendentes:  [], pendTotal: 0, pendOffset: 0, pendLimite: 50,
    // conciliadas
    concil:     [], concTotal: 0, concOffset: 0, concLimite: 50,
    // historico
    hist:       [], histTotal: 0, histOffset: 0, histLimite: 50,
};

// ─────────────────────────────────────────────────────────────────
// CICLO DE VIDA AppRouter
// ─────────────────────────────────────────────────────────────────
export function init() {
    window.ConciliacaoPage = _pub();
    _carregarContas();
    _carregarKPIs();
    _carregarPendentes(0);
    _escutarEventos();
}

export function destroy() {
    window.ConciliacaoPage = null;
}

// ─────────────────────────────────────────────────────────────────
// HELPERS HTTP
// ─────────────────────────────────────────────────────────────────
async function _get(qs) {
    const r = await fetch(`${API}?${qs}`, { credentials: 'include' });
    return r.json();
}
async function _post(body) {
    const r = await fetch(API, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
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
        'animation:concFadeIn .3s ease',
        tipo === 'erro' ? 'background:#ef4444' : tipo === 'aviso' ? 'background:#f59e0b' : 'background:#0f766e',
    ].join(';');
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}

// ─────────────────────────────────────────────────────────────────
// DADOS INICIAIS
// ─────────────────────────────────────────────────────────────────
async function _carregarContas() {
    const d = await fetch(`${CB_API}?acao=listar_contas`, { credentials: 'include' }).then(r => r.json());
    if (!d.sucesso) return;
    const sel = _el('conc-sel-conta');
    sel.innerHTML = '<option value="0">Todas as Contas</option>'
        + (d.dados || []).map(c => `<option value="${c.id}">${_esc(c.nome)}</option>`).join('');
}

async function _carregarKPIs() {
    const qs = S.contaId ? `acao=estatisticas&conta_id=${S.contaId}` : 'acao=estatisticas';
    const d  = await _get(qs);
    if (!d.sucesso) return;
    const k = d.dados;
    const set = (id, v) => { const el = _el(id); if (el) el.textContent = v; };

    set('conc-kpi-pendentes',  k.pendentes);
    set('conc-kpi-conciliados', k.conciliados);
    set('conc-kpi-auto',       k.auto_conciliados);
    set('conc-kpi-taxa',       k.taxa_conciliacao + '%');
    set('conc-kpi-ignorados',  k.ignorados);
    set('conc-taxa-pct',       k.taxa_conciliacao + '%');

    const fill = _el('conc-taxa-fill');
    if (fill) fill.style.width = k.taxa_conciliacao + '%';

    const badge = _el('conc-badge-pend');
    if (badge) {
        badge.textContent = k.pendentes > 0 ? k.pendentes : '';
        badge.style.display = k.pendentes > 0 ? 'inline-flex' : 'none';
    }
}

// ─────────────────────────────────────────────────────────────────
// ABA: PENDENTES
// ─────────────────────────────────────────────────────────────────
async function _carregarPendentes(offset = 0) {
    S.pendOffset = offset;
    const tbody = _el('tbody-pendentes');
    tbody.innerHTML = '<tr><td colspan="8" class="conc-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    const p = new URLSearchParams({
        acao:    'listar_pendentes',
        limite:  S.pendLimite,
        offset,
        conta_id: S.contaId,
        tipo:    _val('pend-filtro-tipo'),
        dt_ini:  _val('pend-filtro-dt-ini'),
        dt_fim:  _val('pend-filtro-dt-fim'),
        busca:   _val('pend-busca'),
    });

    const d = await _get(p.toString());
    if (!d.sucesso) {
        tbody.innerHTML = `<tr><td colspan="8" class="conc-vazio">${_esc(d.mensagem)}</td></tr>`;
        return;
    }
    S.pendentes  = d.dados.movimentacoes;
    S.pendTotal  = d.dados.total;

    const badge = _el('pend-total-badge');
    if (badge) badge.textContent = `${S.pendTotal} pendente${S.pendTotal !== 1 ? 's' : ''}`;

    if (!S.pendentes.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="conc-vazio"><i class="fas fa-check-circle" style="color:#15803d"></i> Nenhuma movimentação pendente</td></tr>';
        _el('paginacao-pendentes').innerHTML = '';
        return;
    }

    tbody.innerHTML = S.pendentes.map(m => {
        const cands = (m.cand_receber > 0 || m.cand_pagar > 0)
            ? `<span style="color:#0f766e;font-weight:600">${m.cand_receber + m.cand_pagar}</span>`
            : '<span style="color:#94a3b8">—</span>';
        return `<tr>
            <td style="white-space:nowrap">${_data(m.data_lancamento)}</td>
            <td style="font-size:12px;color:#64748b">${_esc(m.conta_nome)}</td>
            <td>
                <div style="font-weight:500;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${_esc(m.descricao)}">${_esc(m.descricao)}</div>
                ${m.favorecido ? `<div style="font-size:11.5px;color:#64748b">${_esc(m.favorecido)}</div>` : ''}
            </td>
            <td style="font-size:12px">${_esc(m.checknum || '—')}</td>
            <td><span class="conc-badge ${m.tipo}">${m.tipo === 'credito' ? '▼ Crédito' : '▲ Débito'}</span></td>
            <td class="text-right conc-valor-${m.tipo}">${_moeda(m.valor)}</td>
            <td style="text-align:center">${cands}</td>
            <td><div class="conc-acoes-cell">
                <button class="btn-conc-buscar" onclick="ConciliacaoPage.abrirModal(${m.id})">
                    <i class="fas fa-search"></i> Vincular
                </button>
                <button class="btn-conc-ignorar" onclick="ConciliacaoPage.marcarIgnorado(${m.id})" title="Ignorar">
                    <i class="fas fa-eye-slash"></i>
                </button>
            </div></td>
        </tr>`;
    }).join('');

    _renderPag('paginacao-pendentes', S.pendTotal, S.pendLimite, offset, '_carregarPendentes');
}

// ─────────────────────────────────────────────────────────────────
// ABA: CONCILIADAS
// ─────────────────────────────────────────────────────────────────
async function _carregarConciliadas(offset = 0) {
    S.concOffset = offset;
    const tbody = _el('tbody-conciliadas');
    tbody.innerHTML = '<tr><td colspan="10" class="conc-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    const p = new URLSearchParams({
        acao:    'listar_conciliadas',
        limite:  S.concLimite,
        offset,
        conta_id: S.contaId,
        tipo:    _val('conc-filtro-tipo'),
        dt_ini:  _val('conc-filtro-dt-ini'),
        dt_fim:  _val('conc-filtro-dt-fim'),
        busca:   _val('conc-busca'),
    });

    const d = await _get(p.toString());
    if (!d.sucesso) {
        tbody.innerHTML = `<tr><td colspan="10" class="conc-vazio">${_esc(d.mensagem)}</td></tr>`;
        return;
    }
    S.concil  = d.dados.movimentacoes;
    S.concTotal = d.dados.total;

    const badge = _el('conc-total-badge');
    if (badge) badge.textContent = `${S.concTotal} conciliada${S.concTotal !== 1 ? 's' : ''}`;

    if (!S.concil.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="conc-vazio">Nenhuma conciliação encontrada para os filtros selecionados</td></tr>';
        _el('paginacao-conciliadas').innerHTML = '';
        return;
    }

    tbody.innerHTML = S.concil.map(m => {
        const score     = m.score !== null ? _renderScore(m.score) : '—';
        const tipoCBadge= `<span class="conc-badge ${m.tipo_conciliacao || 'manual'}">${m.tipo_conciliacao === 'automatica' ? '🤖 Auto' : '👤 Manual'}</span>`;
        return `<tr>
            <td style="white-space:nowrap">${_data(m.data_lancamento)}</td>
            <td style="font-size:12px">${_esc(m.conta_nome)}</td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${_esc(m.descricao)}">${_esc(m.descricao)}</td>
            <td><span class="conc-badge ${m.tipo}">${m.tipo === 'credito' ? '▼' : '▲'} ${m.tipo}</span></td>
            <td class="text-right conc-valor-${m.tipo}">${_moeda(m.valor)}</td>
            <td style="font-size:12px">
                ${m.nome_titulo ? `<div style="font-weight:500">${_esc(m.nome_titulo)}</div>` : ''}
                ${m.doc_titulo  ? `<div style="color:#64748b">${_esc(m.doc_titulo)}</div>` : '<span style="color:#94a3b8">—</span>'}
            </td>
            <td style="text-align:center">${tipoCBadge}</td>
            <td style="text-align:center">${score}</td>
            <td style="font-size:12px;color:#64748b">${_esc(m.conciliado_por || '—')}</td>
            <td>
                ${m.conc_id ? `<button class="btn-conc-desfazer" onclick="ConciliacaoPage.desfazer(${m.conc_id})">
                    <i class="fas fa-undo"></i> Desfazer
                </button>` : '—'}
            </td>
        </tr>`;
    }).join('');

    _renderPag('paginacao-conciliadas', S.concTotal, S.concLimite, offset, '_carregarConciliadas');
}

// ─────────────────────────────────────────────────────────────────
// ABA: HISTÓRICO
// ─────────────────────────────────────────────────────────────────
async function _carregarHistorico(offset = 0) {
    S.histOffset = offset;
    const tbody = _el('tbody-historico');
    tbody.innerHTML = '<tr><td colspan="9" class="conc-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    const p = new URLSearchParams({
        acao:     'historico',
        limite:   S.histLimite,
        offset,
        conta_id: S.contaId,
        tipo_conc: _val('hist-filtro-tipo'),
        dt_ini:    _val('hist-filtro-dt-ini'),
        dt_fim:    _val('hist-filtro-dt-fim'),
    });

    const d = await _get(p.toString());
    if (!d.sucesso) {
        tbody.innerHTML = `<tr><td colspan="9" class="conc-vazio">${_esc(d.mensagem)}</td></tr>`;
        return;
    }
    S.hist      = d.dados.conciliacoes;
    S.histTotal = d.dados.total;

    const badge = _el('hist-total-badge');
    if (badge) badge.textContent = `${S.histTotal} evento${S.histTotal !== 1 ? 's' : ''}`;

    if (!S.hist.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="conc-vazio">Nenhum evento de conciliação encontrado</td></tr>';
        _el('paginacao-historico').innerHTML = '';
        return;
    }

    tbody.innerHTML = S.hist.map(c => {
        const statusBadge = c.ativa == 1
            ? '<span class="conc-badge ativa">Ativa</span>'
            : '<span class="conc-badge desfeita">Desfeita</span>';
        return `<tr>
            <td style="white-space:nowrap;font-size:12px">${c.conciliado_em?.slice(0,16).replace('T',' ') || '—'}</td>
            <td style="font-size:12px">${_esc(c.conta_nome || '—')}</td>
            <td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_esc(c.mov_descricao || '—')}</td>
            <td style="font-size:12px">
                ${c.nome_titulo ? `<div style="font-weight:500">${_esc(c.nome_titulo)}</div>` : ''}
                ${c.doc_titulo  ? `<div style="color:#64748b">${_esc(c.doc_titulo)}</div>` : '<span style="color:#94a3b8">—</span>'}
            </td>
            <td style="text-align:center"><span class="conc-badge ${c.tipo_conciliacao}">${c.tipo_conciliacao === 'automatica' ? '🤖 Auto' : '👤 Manual'}</span></td>
            <td style="text-align:center">${c.score !== null ? _renderScore(c.score) : '—'}</td>
            <td style="font-size:12px;color:#64748b">${_esc(c.conciliado_por || '—')}</td>
            <td style="text-align:center">${statusBadge}</td>
            <td style="font-size:12px;color:#64748b">${c.ativa == 0 ? _esc(c.desfeito_por || '—') : '—'}</td>
        </tr>`;
    }).join('');

    _renderPag('paginacao-historico', S.histTotal, S.histLimite, offset, '_carregarHistorico');
}

// ─────────────────────────────────────────────────────────────────
// MODAL DE CANDIDATOS
// ─────────────────────────────────────────────────────────────────
async function abrirModal(mov_id) {
    const overlay = _el('modal-candidatos');
    overlay.style.display = 'flex';
    _el('modal-candidatos-body').innerHTML = '<div style="text-align:center;padding:32px"><i class="fas fa-spinner fa-spin"></i></div>';

    const d = await _get(`acao=candidatos&movimentacao_id=${mov_id}`);
    if (!d.sucesso) {
        _el('modal-candidatos-body').innerHTML = `<p style="color:#dc2626;text-align:center;padding:24px">${_esc(d.mensagem)}</p>`;
        return;
    }

    const mov   = d.dados.movimentacao;
    const cands_cr = d.dados.receber || [];
    const cands_cp = d.dados.pagar   || [];

    if (!cands_cr.length && !cands_cp.length) {
        _el('modal-candidatos-body').innerHTML = `
            <div class="conc-mov-info">
                <strong>${_esc(mov.descricao)}</strong>
                <span>${_data(mov.data_lancamento)} &middot; ${_moeda(mov.valor)}</span>
            </div>
            <div style="text-align:center;padding:32px;color:#94a3b8">
                <i class="fas fa-search" style="font-size:32px;margin-bottom:12px;display:block"></i>
                Nenhum candidato encontrado. Considere lançamento manual ou marque como ignorado.
            </div>`;
        return;
    }

    const html_cr = cands_cr.map(c => `
        <div class="conc-cand-item">
            <div class="conc-cand-info">
                <strong>${_esc(c.nome)}</strong>
                <span>Doc: ${_esc(c.numero_documento || '—')} &middot; Venc: ${_data(c.data_vencimento)}</span>
                <span class="conc-badge pendente" style="margin-top:3px">${c.status}</span>
            </div>
            <div class="conc-cand-valor verde">+${_moeda(c.valor_original)}</div>
            <button class="btn-conc-link" onclick="ConciliacaoPage.conciliar(${mov_id},'receber',${c.id})">
                <i class="fas fa-link"></i> Conciliar
            </button>
        </div>`).join('');

    const html_cp = cands_cp.map(c => `
        <div class="conc-cand-item">
            <div class="conc-cand-info">
                <strong>${_esc(c.nome)}</strong>
                <span>Doc: ${_esc(c.numero_documento || '—')} &middot; Venc: ${_data(c.data_vencimento)}</span>
                <span class="conc-badge pendente" style="margin-top:3px">${c.status}</span>
            </div>
            <div class="conc-cand-valor vermelho">-${_moeda(c.valor_original)}</div>
            <button class="btn-conc-link" onclick="ConciliacaoPage.conciliar(${mov_id},'pagar',${c.id})">
                <i class="fas fa-link"></i> Conciliar
            </button>
        </div>`).join('');

    _el('modal-candidatos-body').innerHTML = `
        <div class="conc-mov-info">
            <strong>${_esc(mov.descricao)}</strong>
            <span>${_data(mov.data_lancamento)} &middot; <span class="conc-valor-${mov.tipo}">${_moeda(mov.valor)}</span></span>
        </div>
        ${cands_cr.length ? `<h4 class="conc-cand-secao verde"><i class="fas fa-arrow-down"></i> Contas a Receber (${cands_cr.length})</h4>${html_cr}` : ''}
        ${cands_cp.length ? `<h4 class="conc-cand-secao vermelho"><i class="fas fa-arrow-up"></i> Contas a Pagar (${cands_cp.length})</h4>${html_cp}` : ''}`;
}

function fecharModal() {
    const overlay = _el('modal-candidatos');
    if (overlay) overlay.style.display = 'none';
}

async function conciliar(mov_id, tipo_titulo, titulo_id) {
    if (!confirm(`Confirmar conciliação desta movimentação com o título #${titulo_id}?`)) return;
    const d = await _post({ acao: 'conciliar_manual', movimentacao_id: mov_id, tipo_titulo, titulo_id });
    if (d.sucesso) {
        _toast('Conciliação realizada com sucesso!');
        fecharModal();
        _carregarKPIs();
        _carregarPendentes(S.pendOffset);
    } else {
        _toast(d.mensagem, 'erro');
    }
}

async function desfazer(conc_id) {
    if (!confirm('Desfazer esta conciliação? Os títulos retornarão ao status PENDENTE.')) return;
    const d = await _post({ acao: 'desfazer', conciliacao_id: conc_id });
    if (d.sucesso) {
        _toast('Conciliação desfeita');
        _carregarKPIs();
        _carregarConciliadas(S.concOffset);
    } else {
        _toast(d.mensagem, 'erro');
    }
}

async function marcarIgnorado(id) {
    if (!confirm('Marcar como "Ignorado"? A movimentação não aparecerá nos pendentes.')) return;
    const d = await _post({ acao: 'atualizar_status', id, status: 'ignorado' });
    if (d.sucesso) {
        _toast('Marcado como ignorado', 'aviso');
        _carregarKPIs();
        _carregarPendentes(S.pendOffset);
    } else {
        _toast(d.mensagem, 'erro');
    }
}

// ─────────────────────────────────────────────────────────────────
// TABS E FILTROS
// ─────────────────────────────────────────────────────────────────
function mudarAba(aba) {
    S.aba = aba;
    document.querySelectorAll('.conc-tab').forEach(b => b.classList.remove('ativa'));
    const btn = document.querySelector(`.conc-tab[onclick*="'${aba}'"]`);
    if (btn) btn.classList.add('ativa');

    ['pendentes','conciliadas','historico'].forEach(a => {
        const p = _el(`panel-${a}`);
        if (p) p.classList.toggle('conc-oculto', a !== aba);
    });

    if (aba === 'conciliadas' && !S.concil.length) _carregarConciliadas(0);
    if (aba === 'historico'   && !S.hist.length)   _carregarHistorico(0);
}

function mudarConta() {
    S.contaId = parseInt(_val('conc-sel-conta')) || 0;
    _carregarKPIs();
    _carregarPendentes(0);
    if (S.aba === 'conciliadas') _carregarConciliadas(0);
    if (S.aba === 'historico')   _carregarHistorico(0);
}

let _timerFiltros = null;
function filtrarPendentes()  { clearTimeout(_timerFiltros); _timerFiltros = setTimeout(() => _carregarPendentes(0), 400); }
function filtrarConciliadas(){ clearTimeout(_timerFiltros); _timerFiltros = setTimeout(() => _carregarConciliadas(0), 400); }
function filtrarHistorico()  { clearTimeout(_timerFiltros); _timerFiltros = setTimeout(() => _carregarHistorico(0), 400); }

// ─────────────────────────────────────────────────────────────────
// UTILITÁRIOS
// ─────────────────────────────────────────────────────────────────
function _renderScore(score) {
    const n   = parseFloat(score);
    const cls = n >= 80 ? 'alto' : n >= 50 ? 'medio' : 'baixo';
    return `<span class="conc-score ${cls}">${n.toFixed(0)}</span>`;
}

function _renderPag(elId, total, limite, offset, fn) {
    const pag   = _el(elId);
    const pages = Math.ceil(total / limite);
    const cur   = Math.floor(offset / limite) + 1;
    if (pages <= 1) { pag.innerHTML = ''; return; }
    let h = '';
    if (cur > 1) h += `<button onclick="ConciliacaoPage.${fn}(${(cur-2)*limite})">‹</button>`;
    for (let p = Math.max(1, cur-2); p <= Math.min(pages, cur+2); p++) {
        h += `<button class="${p===cur?'ativa':''}" onclick="ConciliacaoPage.${fn}(${(p-1)*limite})">${p}</button>`;
    }
    if (cur < pages) h += `<button onclick="ConciliacaoPage.${fn}(${cur*limite})">›</button>`;
    pag.innerHTML = h;
}

function _escutarEventos() {
    document.addEventListener('keydown', _onEsc);
    const overlay = _el('modal-candidatos');
    if (overlay) overlay.addEventListener('click', e => { if (e.target === overlay) fecharModal(); });
}

function _onEsc(e) { if (e.key === 'Escape') fecharModal(); }

// ─────────────────────────────────────────────────────────────────
// API PÚBLICA (window.ConciliacaoPage)
// ─────────────────────────────────────────────────────────────────
function _pub() {
    return {
        mudarAba, mudarConta,
        filtrarPendentes, filtrarConciliadas, filtrarHistorico,
        abrirModal, fecharModal, conciliar, desfazer, marcarIgnorado,
        // paginação exposta para buttons no HTML
        _carregarPendentes:  o => _carregarPendentes(o),
        _carregarConciliadas:o => _carregarConciliadas(o),
        _carregarHistorico:  o => _carregarHistorico(o),
    };
}
