/**
 * ═══════════════════════════════════════════════════════════════════
 * MODULO: CONTRATOS — Controller ES6
 * Alinhado com api_contratos.php (campos e acoes exatos)
 * ═══════════════════════════════════════════════════════════════════
 */

const TAG = '[Contratos]';
const API = '../api/api_contratos.php';

/* ── Estado ──────────────────────────────────────────────────────── */
let _state = {
    lista: [],
    planos: [],
    contratoAtual: null,
    documentos: [],
    orcamentos: [],
    orcamentoAtual: null,
    orcamentoDocumentos: [],
    pagina: 1,
    porPagina: 15,
    _listeners: []
};

/* ── Helpers ─────────────────────────────────────────────────────── */
const $ = id => document.getElementById(id);
const log  = (...a) => console.log(TAG, ...a);
const warn = (...a) => console.warn(TAG, ...a);
const err  = (...a) => console.error(TAG, ...a);

function _on(el, ev, fn) {
    if (!el) { warn('_on: elemento nao encontrado para evento', ev); return; }
    el.addEventListener(ev, fn);
    _state._listeners.push({ el, ev, fn });
}

function _toast(msg, tipo = 'success') {
    const t = $('ctr_toast');
    if (!t) return;
    const icones = { success: 'check-circle', error: 'times-circle', info: 'info-circle', warning: 'exclamation-triangle' };
    t.className = `ctr-toast-${tipo}`;
    t.innerHTML = `<i class="fas fa-${icones[tipo] || 'info-circle'}"></i> ${msg}`;
    t.style.cssText = `display:flex;position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:10px;font-size:.9rem;font-weight:600;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.15);`;
    const cores = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#2563eb' };
    t.style.background = cores[tipo] || '#2563eb';
    t.style.color = '#fff';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.display = 'none'; }, 4500);
}

function _money(v) {
    return parseFloat(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function _dateBR(d) {
    if (!d) return '-';
    const p = String(d).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : d;
}

function _parseMoney(s) {
    if (!s) return 0;
    return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
}

function _esc(s) {
    if (s === null || s === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

function _tipoLabel(t) {
    return t === 'prestacao_servico' ? 'Prestacao de Servico' : t === 'venda' ? 'Venda' : t || '-';
}

function _recLabel(r) {
    const m = { unica: 'Unica', mensal: 'Mensal', anual: 'Anual', diaria: 'Diaria' };
    return m[r] || r || '-';
}

function _statusBadge(s) {
    const m = {
        ativo:      ['Ativo',      'ctr-status-green'],
        aguardando: ['Aguardando', 'ctr-status-yellow'],
        encerrado:  ['Encerrado',  'ctr-status-red'],
        cancelado:  ['Cancelado',  'ctr-status-gray']
    };
    const [label, cls] = m[s] || [s, 'ctr-status-gray'];
    return `<span class="ctr-status ${cls}">${label}</span>`;
}

/* ── API Fetch ───────────────────────────────────────────────────── */
async function _fetch(url, opts = {}) {
    log('FETCH', url, opts.method || 'GET');
    try {
        const r = await fetch(url, { credentials: 'include', ...opts });
        const text = await r.text();
        log('RESPONSE status:', r.status, 'body:', text.substring(0, 400));
        try {
            return JSON.parse(text);
        } catch (e) {
            err('JSON parse error:', e, 'raw:', text.substring(0, 500));
            return { sucesso: false, mensagem: 'Resposta invalida do servidor' };
        }
    } catch (e) {
        err('FETCH error:', e);
        return { sucesso: false, mensagem: 'Erro de conexao: ' + e.message };
    }
}

async function _post(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    for (const [k, v] of Object.entries(dados)) {
        if (v instanceof File) fd.append(k, v);
        else fd.append(k, v ?? '');
    }
    return _fetch(API, { method: 'POST', body: fd });
}

async function _get(acao, params = {}) {
    const qs = new URLSearchParams({ acao, ...params });
    return _fetch(`${API}?${qs}`);
}

/* ═══════════════════════════════════════════════════════════════════
   INIT / DESTROY
   ═══════════════════════════════════════════════════════════════════ */
export function init() {
    log('Init');
    window.Contratos = { fecharModal, fecharModalExcluir, fecharModalOrc };
    _bindEvents();
    _carregarPlanos();
    _carregarLista();
}

export function destroy() {
    log('Destroy');
    _state._listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _state._listeners = [];
    _state = { lista: [], planos: [], contratoAtual: null, documentos: [], orcamentos: [], orcamentoAtual: null, orcamentoDocumentos: [], pagina: 1, porPagina: 15, _listeners: [] };
    delete window.Contratos;
}

/* ═══════════════════════════════════════════════════════════════════
   BIND EVENTS
   ═══════════════════════════════════════════════════════════════════ */
function _bindEvents() {
    log('bindEvents');

    // Abas globais
    document.querySelectorAll('.ctr-tab-btn').forEach(tab => {
        _on(tab, 'click', () => _switchGlobalTab(tab.dataset.tab));
    });

    // Sub-abas do detalhe
    document.querySelectorAll('.ctr-subtab-btn').forEach(tab => {
        _on(tab, 'click', () => _switchDetalheTab(tab.dataset.subtab));
    });

    // Filtros
    _on($('ctr_btnFiltrar'), 'click', () => { _state.pagina = 1; _carregarLista(); });
    _on($('ctr_btnLimpar'), 'click', _limparFiltros);
    _on($('ctr_fBusca'), 'keydown', e => { if (e.key === 'Enter') { _state.pagina = 1; _carregarLista(); } });

    // Novo contrato
    _on($('ctr_btnNovo'), 'click', _abrirModalNovo);

    // Modal
    _on($('ctr_modalClose'), 'click', fecharModal);
    _on($('ctr_btnCancelar'), 'click', fecharModal);
    _on($('ctr_btnSalvar'), 'click', _salvarContrato);

    // Fornecedor autocomplete
    _on($('ctr_formFornecedorBusca'), 'input', _filtrarFornecedor);
    _on($('ctr_formFornecedorBusca'), 'focus', _filtrarFornecedor);
    _on($('ctr_fornecedorLimpar'), 'click', _limparFornecedor);

    // Fechar autocomplete ao clicar fora
    document.addEventListener('click', e => {
        const wrap = document.querySelector('.ctr-autocomplete-wrap');
        if (wrap && !wrap.contains(e.target)) {
            const lista = $('ctr_fornecedorLista');
            if (lista) lista.innerHTML = '';
        }
    });

    // Preview parcelas
    _on($('ctr_formRecorrencia'), 'change', _calcularParcelas);
    _on($('ctr_formInicio'), 'change', _calcularParcelas);
    _on($('ctr_formFim'), 'change', _calcularParcelas);
    _on($('ctr_formValor'), 'input', _calcularParcelas);

    // Detalhe: voltar
    _on($('ctr_btnVoltar'), 'click', _voltarParaLista);

    // Detalhe: editar / excluir
    _on($('ctr_btnEditar'), 'click', _editarContrato);
    _on($('ctr_btnExcluir'), 'click', _confirmarExclusao);

    // Modal excluir
    _on($('ctr_modalExcluirClose'), 'click', fecharModalExcluir);
    _on($('ctr_btnExcluirCancelar'), 'click', fecharModalExcluir);
    _on($('ctr_btnExcluirConfirmar'), 'click', _excluirContrato);

    // Documentos
    _on($('ctr_btnUploadDoc'), 'click', _uploadDocumento);

    // Orcamentos
    _on($('ctr_btnSalvarOrc'), 'click', _salvarOrcamento);
    _on($('ctr_btnCancelarOrc'), 'click', _cancelarEdicaoOrc);
    _on($('ctr_orcValor'), 'input', _verificarValorOrcamento);

    // Modal de visualizacao do orcamento + documentos
    _on($('ctr_modalOrcClose'), 'click', fecharModalOrc);
    _on($('ctr_btnUploadOrcDoc'), 'click', _uploadOrcamentoDocumento);

    // Relatorios
    _on($('ctr_btnRelAtivos'), 'click', () => _gerarRelatorio('relatorio_ativos'));
    _on($('ctr_btnRelVenc'), 'click', () => _gerarRelatorio('relatorio_vencimentos'));
    _on($('ctr_btnRelFornecedor'), 'click', () => _gerarRelatorio('relatorio_por_fornecedor'));
    _on($('ctr_btnRelFinanceiro'), 'click', () => _gerarRelatorio('relatorio_financeiro'));
    _on($('ctr_btnExportCSV'), 'click', _exportarCSV);
}

/* ═══════════════════════════════════════════════════════════════════
   NAVEGACAO DE ABAS
   ═══════════════════════════════════════════════════════════════════ */
function _switchGlobalTab(tab) {
    log('switchGlobalTab:', tab);
    document.querySelectorAll('.ctr-tab-btn').forEach(t => t.classList.remove('active'));
    document.querySelector(`.ctr-tab-btn[data-tab="${tab}"]`)?.classList.add('active');

    $('ctr_secLista').style.display      = tab === 'lista' ? '' : 'none';
    $('ctr_secDetalhe').style.display    = 'none';
    $('ctr_secRelatorios').style.display = tab === 'relatorios' ? '' : 'none';
}

function _switchDetalheTab(subtab) {
    log('switchDetalheTab:', subtab);
    document.querySelectorAll('.ctr-subtab-btn').forEach(t => t.classList.remove('active'));
    document.querySelector(`.ctr-subtab-btn[data-subtab="${subtab}"]`)?.classList.add('active');

    $('ctr_subDados').style.display         = subtab === 'dados'          ? '' : 'none';
    $('ctr_subDocs').style.display          = subtab === 'documentos'     ? '' : 'none';
    $('ctr_subOrc').style.display           = subtab === 'orcamentos'     ? '' : 'none';
    const subRel = $('ctr_subRelLicitacao');
    if (subRel) subRel.style.display        = subtab === 'rel_licitacao'  ? '' : 'none';
    if (subtab === 'rel_licitacao') _carregarRelLicitacao();
}

function _voltarParaLista() {
    log('voltarParaLista');
    _state.contratoAtual = null;
    $('ctr_secDetalhe').style.display    = 'none';
    $('ctr_secLista').style.display      = '';
    $('ctr_secRelatorios').style.display = 'none';
    document.querySelectorAll('.ctr-tab-btn').forEach(t => t.classList.remove('active'));
    $('ctr_tabLista')?.classList.add('active');
    _carregarLista();
}

/* ═══════════════════════════════════════════════════════════════════
   CARREGAR PLANOS DE CONTAS
   ═══════════════════════════════════════════════════════════════════ */
async function _carregarPlanos() {
    log('carregarPlanos');
    try {
        const d = await _fetch('../api/api_planos_contas.php?acao=listar');
        const lista = Array.isArray(d) ? d : Array.isArray(d?.dados) ? d.dados : [];
        _state.planos = lista;
        const sel = $('ctr_formPlano');
        if (sel) {
            sel.innerHTML = '<option value="">Selecione o plano de contas...</option>';
            lista.forEach(p => {
                sel.innerHTML += `<option value="${p.id}">${_esc(p.codigo)} - ${_esc(p.nome)}</option>`;
            });
        }
        log('planos carregados:', lista.length);
    } catch (e) {
        err('Erro ao carregar planos:', e);
    }
}

/* ═══════════════════════════════════════════════════════════════════
   FORNECEDOR AUTOCOMPLETE
   Usa api_contratos.php?acao=buscar_fornecedores&q=...
   Retorna: { sucesso, mensagem, dados: [{id, nome, cnpj, email, telefone}] }
   ═══════════════════════════════════════════════════════════════════ */
let _debounce = null;

function _filtrarFornecedor() {
    clearTimeout(_debounce);
    _debounce = setTimeout(async () => {
        const q = ($('ctr_formFornecedorBusca')?.value || '').trim();
        const el = $('ctr_fornecedorLista');
        if (!el) return;

        if (!q || q.length < 2) {
            el.innerHTML = '';
            return;
        }

        log('filtrarFornecedor:', q);
        el.innerHTML = '<div class="ctr-ac-item ctr-ac-empty"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

        const d = await _get('buscar_fornecedores', { q });
        const lista = Array.isArray(d?.dados) ? d.dados : [];

        if (!lista.length) {
            el.innerHTML = '<div class="ctr-ac-item ctr-ac-empty">Nenhum fornecedor encontrado</div>';
            return;
        }

        el.innerHTML = lista.map(f =>
            `<div class="ctr-ac-item" data-id="${f.id}" data-nome="${_esc(f.nome)}" data-cnpj="${_esc(f.cnpj || '')}">
                <strong>${_esc(f.nome)}</strong>
                <small>${_esc(f.cnpj || '') || 'Sem CNPJ'}${f.email ? ' · ' + _esc(f.email) : ''}</small>
            </div>`
        ).join('');

        el.querySelectorAll('.ctr-ac-item[data-id]').forEach(item => {
            item.addEventListener('click', () => _selecionarFornecedor(item));
        });
    }, 300);
}

function _selecionarFornecedor(item) {
    log('selecionarFornecedor:', item.dataset.id, item.dataset.nome);
    $('ctr_formFornecedorId').value   = item.dataset.id;
    $('ctr_formFornecedorNome').value = item.dataset.nome;
    $('ctr_formFornecedorCnpj').value = item.dataset.cnpj || '';

    const busca = $('ctr_formFornecedorBusca');
    if (busca) { busca.value = ''; busca.style.display = 'none'; }

    const sel = $('ctr_fornecedorSelecionado');
    if (sel) sel.style.display = 'flex';

    const info = $('ctr_fornecedorInfo');
    if (info) info.textContent = item.dataset.cnpj
        ? `${item.dataset.nome} — ${item.dataset.cnpj}`
        : item.dataset.nome;

    $('ctr_fornecedorLista').innerHTML = '';
}

function _limparFornecedor() {
    log('limparFornecedor');
    $('ctr_formFornecedorId').value   = '0';
    $('ctr_formFornecedorNome').value = '';
    $('ctr_formFornecedorCnpj').value = '';

    const busca = $('ctr_formFornecedorBusca');
    if (busca) { busca.value = ''; busca.style.display = ''; busca.focus(); }

    const sel = $('ctr_fornecedorSelecionado');
    if (sel) sel.style.display = 'none';

    const lista = $('ctr_fornecedorLista');
    if (lista) lista.innerHTML = '';
}

/* ═══════════════════════════════════════════════════════════════════
   CARREGAR LISTA DE CONTRATOS + KPIs
   API: listar — params: status, fornecedor, tipo, pagina, por_pagina
   Retorna: { sucesso, dados: { lista, total, pagina, por_pagina, paginas } }
   ═══════════════════════════════════════════════════════════════════ */
async function _carregarLista() {
    log('carregarLista pagina:', _state.pagina);
    const busca  = $('ctr_fBusca')?.value  || '';
    const status = $('ctr_fStatus')?.value || '';
    const tipo   = $('ctr_fTipo')?.value   || '';

    const d = await _get('listar', {
        fornecedor: busca,
        status,
        tipo,
        pagina: _state.pagina,
        por_pagina: _state.porPagina
    });

    const lista = Array.isArray(d?.dados?.lista) ? d.dados.lista : [];
    _state.lista = lista;
    log('lista carregada:', lista.length, 'total:', d?.dados?.total);

    _renderTabela(lista);
    _calcularKPIs(lista);
}

function _calcularKPIs(lista) {
    let total = lista.length, ativos = 0, aguardando = 0, encerrados = 0, valorAtivo = 0, vencendo30 = 0;
    const hoje = new Date().toISOString().split('T')[0];
    const em30 = new Date(); em30.setDate(em30.getDate() + 30);

    lista.forEach(c => {
        const s = c.status || _calcStatus(c);
        if (s === 'ativo')      { ativos++;      valorAtivo += parseFloat(c.valor_total || 0); }
        if (s === 'aguardando') aguardando++;
        if (s === 'encerrado')  encerrados++;
        if (s === 'ativo' && c.data_fim) {
            const fim = new Date(c.data_fim);
            if (fim <= em30) vencendo30++;
        }
    });

    const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
    set('ctr_kpiTotal',      total);
    set('ctr_kpiAtivos',     ativos);
    set('ctr_kpiAguardando', aguardando);
    set('ctr_kpiEncerrados', encerrados);
    set('ctr_kpiValor',      _money(valorAtivo));

    const alertBar = $('ctr_alertBar');
    if (vencendo30 > 0 && alertBar) {
        alertBar.style.display = 'flex';
        const txt = $('ctr_alertText');
        if (txt) txt.textContent = `${vencendo30} contrato(s) vencem nos proximos 30 dias!`;
    } else if (alertBar) {
        alertBar.style.display = 'none';
    }
}

function _calcStatus(c) {
    const hoje = new Date().toISOString().split('T')[0];
    if (c.status === 'cancelado') return 'cancelado';
    if (c.data_inicio > hoje)     return 'aguardando';
    if (c.data_fim < hoje)        return 'encerrado';
    return 'ativo';
}

/* ═══════════════════════════════════════════════════════════════════
   RENDER TABELA
   Campos da API: numero_contrato, fornecedor_nome, tipo_servico, nome_contrato,
                  recorrencia, valor_total, data_inicio, data_fim, status,
                  total_documentos, total_orcamentos
   ═══════════════════════════════════════════════════════════════════ */
function _renderTabela(lista) {
    const tbody = $('ctr_tbody');
    if (!tbody) return;

    if (!lista.length) {
        tbody.innerHTML = `<tr><td colspan="11" class="empty-table"><i class="fas fa-folder-open"></i> Nenhum contrato encontrado</td></tr>`;
        return;
    }

    tbody.innerHTML = lista.map(c => {
        const status   = c.status || _calcStatus(c);
        const docCount = parseInt(c.total_documentos || 0);
        const orcCount = parseInt(c.total_orcamentos || 0);
        return `<tr class="ctr-row-clickable" data-id="${c.id}">
            <td><strong style="color:#2563eb;font-family:monospace;">${_esc(c.numero_contrato)}</strong></td>
            <td>${_esc(c.fornecedor_nome || '-')}</td>
            <td>${_tipoLabel(c.tipo_servico)}</td>
            <td>${_esc(c.nome_contrato || c.nome || '-')}</td>
            <td>${_recLabel(c.recorrencia)}</td>
            <td>${_money(c.valor_total)}</td>
            <td>${_dateBR(c.data_inicio)} a ${_dateBR(c.data_fim)}</td>
            <td>${_statusBadge(status)}</td>
            <td><span class="ctr-badge-count">${docCount}/4</span></td>
            <td><span class="ctr-badge-count ${orcCount < 3 ? 'ctr-badge-warn' : 'ctr-badge-ok'}">${orcCount}/3</span></td>
            <td>
                <button class="ctr-btn-icon ctr-btn-view" title="Ver Detalhe" data-id="${c.id}"><i class="fas fa-eye"></i></button>
                <button class="ctr-btn-icon ctr-btn-edit" title="Editar" data-id="${c.id}"><i class="fas fa-edit"></i></button>
            </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('.ctr-row-clickable').forEach(row => {
        row.addEventListener('click', e => {
            if (e.target.closest('button')) return;
            _abrirDetalhe(parseInt(row.dataset.id));
        });
    });
    tbody.querySelectorAll('.ctr-btn-view').forEach(btn => {
        btn.addEventListener('click', () => _abrirDetalhe(parseInt(btn.dataset.id)));
    });
    tbody.querySelectorAll('.ctr-btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const c = _state.lista.find(x => x.id == btn.dataset.id);
            if (c) _abrirModalEditar(c);
        });
    });
}

/* ═══════════════════════════════════════════════════════════════════
   DETALHE DO CONTRATO
   API: buscar?id=X
   Retorna: { sucesso, dados: { id, numero_contrato, fornecedor_nome,
             fornecedor_cnpj, tipo_servico, nome_contrato, recorrencia,
             valor_total, data_inicio, data_fim, data_vencimento,
             plano_conta_id, plano_conta_nome, observacoes, status } }
   ═══════════════════════════════════════════════════════════════════ */
async function _abrirDetalhe(id) {
    log('abrirDetalhe id:', id);
    const d = await _get('buscar', { id });
    if (!d?.sucesso) {
        _toast(d?.mensagem || 'Erro ao buscar contrato', 'error');
        return;
    }
    const c = d.dados;
    _state.contratoAtual = c;

    $('ctr_secLista').style.display      = 'none';
    $('ctr_secRelatorios').style.display = 'none';
    $('ctr_secDetalhe').style.display    = '';

    const status = c.status || _calcStatus(c);
    const nomeTitulo = c.nome_contrato || c.nome || '';
    $('ctr_detalheTitulo').textContent = `${c.numero_contrato} — ${nomeTitulo}`;

    const badgeEl = $('ctr_detalheStatus');
    if (badgeEl) {
        const badgeLabels = { ativo: 'Ativo', aguardando: 'Aguardando', encerrado: 'Encerrado', cancelado: 'Cancelado' };
        const badgeBg     = { ativo: '#d1fae5', aguardando: '#fef3c7', encerrado: '#f1f5f9', cancelado: '#fee2e2' };
        const badgeColor  = { ativo: '#065f46', aguardando: '#92400e', encerrado: '#475569', cancelado: '#991b1b' };
        badgeEl.textContent = badgeLabels[status] || status;
        badgeEl.style.cssText = `display:inline-block;padding:3px 12px;border-radius:20px;font-size:.78rem;font-weight:700;background:${badgeBg[status] || '#f1f5f9'};color:${badgeColor[status] || '#475569'}`;
    }

    $('ctr_dFornecedor').textContent  = c.fornecedor_nome || '-';
    $('ctr_dCnpj').textContent        = c.fornecedor_cnpj || '-';
    $('ctr_dNumero').textContent      = c.numero_contrato;
    $('ctr_dTipo').textContent        = _tipoLabel(c.tipo_servico);
    $('ctr_dNome').textContent        = c.nome_contrato || c.nome || '-';
    $('ctr_dRecorrencia').textContent = _recLabel(c.recorrencia);
    $('ctr_dInicio').textContent      = _dateBR(c.data_inicio);
    $('ctr_dFim').textContent         = _dateBR(c.data_fim);
    $('ctr_dVencimento').textContent  = _dateBR(c.data_vencimento);
    $('ctr_dPlano').textContent       = c.plano_conta_nome ? `${c.plano_conta_codigo} - ${c.plano_conta_nome}` : (c.plano_conta_id || '-');
    $('ctr_dValor').textContent       = _money(c.valor_total);

    const cardObs = $('ctr_cardObs');
    if (c.observacoes && cardObs) {
        cardObs.style.display = '';
        $('ctr_dObs').textContent = c.observacoes;
    } else if (cardObs) {
        cardObs.style.display = 'none';
    }

    _switchDetalheTab('dados');
    _carregarDocumentos(c.id);
    _carregarOrcamentos(c.id);
}

/* ═══════════════════════════════════════════════════════════════════
   DOCUMENTOS
   API: listar_documentos?contrato_id=X
   Retorna: { sucesso, dados: [{id, nome_documento, tipo_documento, nome_arquivo, url_arquivo, tamanho, data_upload}] }
   Upload: POST acao=upload_documento, contrato_id, nome_documento, tipo_documento, arquivo
   Excluir: POST acao=deletar_documento, id
   ═══════════════════════════════════════════════════════════════════ */
async function _carregarDocumentos(contratoId) {
    log('carregarDocumentos contratoId:', contratoId);
    const d = await _get('listar_documentos', { contrato_id: contratoId });
    const lista = Array.isArray(d?.dados) ? d.dados : [];
    _state.documentos = lista;

    const count = lista.length;
    const docCount = $('ctr_docCount');
    const badgeDocs = $('ctr_badgeDocs');
    if (docCount) docCount.textContent = count;
    if (badgeDocs) badgeDocs.textContent = count;

    const bar = $('ctr_docProgressBar');
    if (bar) bar.style.width = ((count / 4) * 100) + '%';

    const form = $('ctr_docUploadForm');
    if (form) form.style.display = count >= 4 ? 'none' : '';

    const tbody = $('ctr_docTbody');
    const empty = $('ctr_docEmpty');
    if (!tbody) return;

    if (!lista.length) {
        tbody.innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';

    tbody.innerHTML = lista.map((doc, i) => {
        const ext  = (doc.nome_arquivo || '').split('.').pop().toUpperCase();
        const icon = ext === 'PDF' ? 'fa-file-pdf' : (ext === 'DOC' || ext === 'DOCX') ? 'fa-file-word' : 'fa-file-image';
        const tam  = doc.tamanho ? (parseFloat(doc.tamanho) / 1024).toFixed(1) + ' KB' : '-';
        const url  = doc.url_arquivo || ('../uploads/contratos/' + contratoId + '/' + doc.nome_arquivo);
        return `<tr>
            <td>${i + 1}</td>
            <td>${_esc(doc.nome_documento || doc.nome)}</td>
            <td>${_esc(doc.tipo_documento || '-')}</td>
            <td><i class="fas ${icon}"></i> ${_esc(doc.nome_arquivo || '-')}</td>
            <td>${tam}</td>
            <td>${_dateBR((doc.data_upload || '').split(' ')[0])}</td>
            <td>
                <a href="${url}" target="_blank" class="ctr-btn-icon ctr-btn-view" title="Baixar"><i class="fas fa-download"></i></a>
                <button class="ctr-btn-icon" style="background:#fee2e2;color:#991b1b;" data-doc-id="${doc.id}" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('button[data-doc-id]').forEach(btn => {
        btn.addEventListener('click', () => _excluirDocumento(parseInt(btn.dataset.docId)));
    });
}

async function _uploadDocumento() {
    const contratoId = _state.contratoAtual?.id;
    if (!contratoId) { _toast('Selecione um contrato primeiro', 'error'); return; }

    const nome      = $('ctr_docNome')?.value?.trim();
    const tipo      = $('ctr_docTipo')?.value;
    const fileInput = $('ctr_docArquivo');
    const arquivo   = fileInput?.files?.[0];

    if (!nome || !tipo || !arquivo) {
        _toast('Preencha nome, tipo e selecione um arquivo', 'error');
        return;
    }

    if (_state.documentos.length >= 4) {
        _toast('Limite de 4 documentos atingido', 'error');
        return;
    }

    log('uploadDocumento:', nome, tipo, arquivo.name);
    const fd = new FormData();
    fd.append('acao', 'upload_documento');
    fd.append('contrato_id', contratoId);
    fd.append('nome_documento', nome);       // campo correto: nome_documento
    fd.append('tipo_documento', tipo);
    fd.append('arquivo', arquivo);

    const d = await _fetch(API, { method: 'POST', body: fd });
    if (d?.sucesso) {
        _toast('Documento enviado com sucesso');
        $('ctr_docNome').value = '';
        $('ctr_docTipo').value = '';
        fileInput.value = '';
        _carregarDocumentos(contratoId);
    } else {
        _toast(d?.mensagem || 'Erro ao enviar documento', 'error');
    }
}

async function _excluirDocumento(docId) {
    if (!confirm('Excluir este documento?')) return;
    log('excluirDocumento:', docId);
    const d = await _post('deletar_documento', { id: docId });  // campo correto: id
    if (d?.sucesso) {
        _toast('Documento excluido');
        _carregarDocumentos(_state.contratoAtual?.id);
    } else {
        _toast(d?.mensagem || 'Erro ao excluir', 'error');
    }
}

/* ═══════════════════════════════════════════════════════════════════
   ORCAMENTOS
   API: listar_orcamentos?contrato_id=X
   Retorna: { sucesso, dados: [{id, contrato_id, fornecedor, descricao, valor, justificativa, data_criacao}] }
   Salvar: POST acao=salvar_orcamento, id(0=novo), contrato_id, fornecedor, descricao, valor, justificativa
   Excluir: POST acao=deletar_orcamento, id
   ═══════════════════════════════════════════════════════════════════ */
async function _carregarOrcamentos(contratoId) {
    log('carregarOrcamentos contratoId:', contratoId);
    const d = await _get('listar_orcamentos', { contrato_id: contratoId });
    const lista = Array.isArray(d?.dados) ? d.dados : [];
    _state.orcamentos = lista;

    const count = lista.length;
    const orcCount = $('ctr_orcCount');
    const badgeOrc = $('ctr_badgeOrc');
    if (orcCount) orcCount.textContent = count;
    if (badgeOrc) badgeOrc.textContent = count;

    const alertEl = $('ctr_orcAlert');
    if (alertEl) {
        if (count < 3) {
            alertEl.style.display = 'flex';
            const faltam = $('ctr_orcFaltam');
            if (faltam) faltam.textContent = 3 - count;
        } else {
            alertEl.style.display = 'none';
        }
    }

    const tbody = $('ctr_orcTbody');
    const empty = $('ctr_orcEmpty');
    if (!tbody) return;

    if (!lista.length) {
        tbody.innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';

    const valorContrato = parseFloat(_state.contratoAtual?.valor_total || 0);

    tbody.innerHTML = lista.map((o, i) => {
        const valor = parseFloat(o.valor || 0);
        const acima = valor > valorContrato;
        return `<tr>
            <td>${i + 1}</td>
            <td>${_esc(o.fornecedor)}</td>
            <td>${_esc(o.descricao)}</td>
            <td style="${acima ? 'color:#ef4444;font-weight:700;' : ''}">${_money(valor)}${acima ? ' <i class="fas fa-exclamation-triangle" title="Acima do valor do contrato"></i>' : ''}</td>
            <td>${o.justificativa ? _esc(o.justificativa) : '<span style="color:#94a3b8;">-</span>'}</td>
            <td>${_dateBR((o.data_criacao || '').split(' ')[0])}</td>
            <td>
                <button class="ctr-btn-icon ctr-btn-view ctr-orc-view" data-idx="${i}" title="Ver / Documentos"><i class="fas fa-eye"></i></button>
                <button class="ctr-btn-icon ctr-orc-edit" data-idx="${i}" title="Editar"><i class="fas fa-edit"></i></button>
                <button class="ctr-btn-icon" style="background:#fee2e2;color:#991b1b;" data-orc-id="${o.id}" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('.ctr-orc-view').forEach(btn => {
        btn.addEventListener('click', () => {
            const o = _state.orcamentos[parseInt(btn.dataset.idx)];
            if (o) _verOrcamento(o);
        });
    });
    tbody.querySelectorAll('.ctr-orc-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const o = _state.orcamentos[parseInt(btn.dataset.idx)];
            if (o) _editarOrcamento(o);
        });
    });
    tbody.querySelectorAll('button[data-orc-id]').forEach(btn => {
        btn.addEventListener('click', () => _excluirOrcamento(parseInt(btn.dataset.orcId)));
    });
}

function _editarOrcamento(o) {
    $('ctr_orcId').value            = o.id;
    $('ctr_orcFornecedor').value    = o.fornecedor || '';     // campo: fornecedor
    $('ctr_orcDescricao').value     = o.descricao || '';
    $('ctr_orcValor').value         = parseFloat(o.valor || 0).toFixed(2).replace('.', ',');
    $('ctr_orcJustificativa').value = o.justificativa || '';
    $('ctr_btnCancelarOrc').style.display = '';
    _verificarValorOrcamento();
}

function _cancelarEdicaoOrc() {
    $('ctr_orcId').value            = '0';
    $('ctr_orcFornecedor').value    = '';
    $('ctr_orcDescricao').value     = '';
    $('ctr_orcValor').value         = '';
    $('ctr_orcJustificativa').value = '';
    const wrap = $('ctr_orcJustificativaWrap');
    if (wrap) wrap.style.display = 'none';
    $('ctr_btnCancelarOrc').style.display = 'none';
}

function _verificarValorOrcamento() {
    const valor = _parseMoney($('ctr_orcValor')?.value);
    const valorContrato = parseFloat(_state.contratoAtual?.valor_total || 0);
    const wrap = $('ctr_orcJustificativaWrap');
    if (wrap) wrap.style.display = valor > valorContrato ? '' : 'none';
}

async function _salvarOrcamento() {
    const contratoId    = _state.contratoAtual?.id;
    if (!contratoId) { _toast('Selecione um contrato primeiro', 'error'); return; }

    const fornecedor    = $('ctr_orcFornecedor')?.value?.trim();
    const descricao     = $('ctr_orcDescricao')?.value?.trim();
    const valor         = _parseMoney($('ctr_orcValor')?.value);
    const justificativa = $('ctr_orcJustificativa')?.value?.trim();
    const orcId         = $('ctr_orcId')?.value || '0';

    if (!fornecedor || !descricao || !valor) {
        _toast('Preencha fornecedor, descricao e valor', 'error');
        return;
    }

    const valorContrato = parseFloat(_state.contratoAtual?.valor_total || 0);
    if (valor > valorContrato && !justificativa) {
        _toast('Justificativa obrigatoria quando valor excede o contrato', 'error');
        return;
    }

    const dados = {
        id:           orcId,                 // 0 = novo, >0 = editar
        contrato_id:  contratoId,
        fornecedor:   fornecedor,            // campo correto: fornecedor
        descricao,
        valor:        valor.toFixed(2),
        justificativa: justificativa || ''
    };

    log('salvarOrcamento:', dados);
    const d = await _post('salvar_orcamento', dados);   // acao correta: salvar_orcamento
    if (d?.sucesso) {
        _toast(orcId !== '0' ? 'Orcamento atualizado' : 'Orcamento cadastrado');
        _cancelarEdicaoOrc();
        _carregarOrcamentos(contratoId);
    } else {
        _toast(d?.mensagem || 'Erro ao salvar orcamento', 'error');
    }
}

async function _excluirOrcamento(orcId) {
    if (!confirm('Excluir este orcamento?')) return;
    log('excluirOrcamento:', orcId);
    const d = await _post('deletar_orcamento', { id: orcId });  // campo correto: id
    if (d?.sucesso) {
        _toast('Orcamento excluido');
        _carregarOrcamentos(_state.contratoAtual?.id);
    } else {
        _toast(d?.mensagem || 'Erro ao excluir', 'error');
    }
}

/* ═══════════════════════════════════════════════════════════════════
   VISUALIZAR ORCAMENTO + DOCUMENTOS DO ORCAMENTO
   API: listar_orcamento_documentos?orcamento_id=X
   Retorna: { sucesso, dados: [{id, orcamento_id, nome_documento, tipo_documento,
             nome_arquivo, url_arquivo, tamanho, data_upload}] }
   Upload: POST acao=upload_orcamento_documento, orcamento_id, nome_documento, tipo_documento, arquivo
   Excluir: POST acao=deletar_orcamento_documento, id
   ═══════════════════════════════════════════════════════════════════ */
function _orcDocTipoLabel(t) {
    const m = { proposta: 'Proposta', planilha_orcamentaria: 'Planilha Orcamentaria', nf_recibo: 'NF / Recibo', outros: 'Outros' };
    return m[t] || t || '-';
}

function _verOrcamento(o) {
    log('verOrcamento:', o.id);
    _state.orcamentoAtual = o;

    $('ctr_orcViewFornecedor').textContent = o.fornecedor || '-';
    $('ctr_orcViewValor').textContent      = _money(o.valor);
    $('ctr_orcViewDescricao').textContent  = o.descricao || '-';

    const justWrap = $('ctr_orcViewJustWrap');
    if (o.justificativa) {
        $('ctr_orcViewJustificativa').textContent = o.justificativa;
        if (justWrap) justWrap.style.display = '';
    } else if (justWrap) {
        justWrap.style.display = 'none';
    }

    $('ctr_orcViewData').textContent = _dateBR((o.data_criacao || '').split(' ')[0]);

    if ($('ctr_orcDocNome'))     $('ctr_orcDocNome').value = '';
    if ($('ctr_orcDocTipo'))     $('ctr_orcDocTipo').value = '';
    if ($('ctr_orcDocArquivo'))  $('ctr_orcDocArquivo').value = '';

    $('ctr_modalOrcOverlay').style.display = 'flex';
    _carregarOrcamentoDocumentos(o.id);
}

function fecharModalOrc() {
    const overlay = $('ctr_modalOrcOverlay');
    if (overlay) overlay.style.display = 'none';
    _state.orcamentoAtual = null;
}

async function _carregarOrcamentoDocumentos(orcamentoId) {
    log('carregarOrcamentoDocumentos orcamentoId:', orcamentoId);
    const d = await _get('listar_orcamento_documentos', { orcamento_id: orcamentoId });
    const lista = Array.isArray(d?.dados) ? d.dados : [];
    _state.orcamentoDocumentos = lista;

    const countEl = $('ctr_orcDocCount');
    if (countEl) countEl.textContent = lista.length;

    const tbody = $('ctr_orcDocTbody');
    const empty = $('ctr_orcDocEmpty');
    if (!tbody) return;

    if (!lista.length) {
        tbody.innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';

    tbody.innerHTML = lista.map((doc, i) => {
        const ext  = (doc.nome_arquivo || '').split('.').pop().toUpperCase();
        const icon = ext === 'PDF' ? 'fa-file-pdf' : (ext === 'DOC' || ext === 'DOCX') ? 'fa-file-word' : 'fa-file-image';
        const tam  = doc.tamanho ? (parseFloat(doc.tamanho) / 1024).toFixed(1) + ' KB' : '-';
        const url  = doc.url_arquivo || '';
        return `<tr>
            <td>${i + 1}</td>
            <td>${_esc(doc.nome_documento)}</td>
            <td>${_esc(_orcDocTipoLabel(doc.tipo_documento))}</td>
            <td><i class="fas ${icon}"></i> ${_esc(doc.nome_arquivo || '-')}</td>
            <td>${tam}</td>
            <td>${_dateBR((doc.data_upload || '').split(' ')[0])}</td>
            <td>
                <a href="${url}" target="_blank" class="ctr-btn-icon ctr-btn-view" title="Baixar"><i class="fas fa-download"></i></a>
                <button class="ctr-btn-icon" style="background:#fee2e2;color:#991b1b;" data-orcdoc-id="${doc.id}" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('button[data-orcdoc-id]').forEach(btn => {
        btn.addEventListener('click', () => _excluirOrcamentoDocumento(parseInt(btn.dataset.orcdocId)));
    });
}

async function _uploadOrcamentoDocumento() {
    const orcamentoId = _state.orcamentoAtual?.id;
    if (!orcamentoId) { _toast('Nenhum orcamento selecionado', 'error'); return; }

    const nome      = $('ctr_orcDocNome')?.value?.trim();
    const tipo      = $('ctr_orcDocTipo')?.value;
    const fileInput = $('ctr_orcDocArquivo');
    const arquivo   = fileInput?.files?.[0];

    if (!nome || !tipo || !arquivo) {
        _toast('Preencha nome, tipo e selecione um arquivo', 'error');
        return;
    }

    log('uploadOrcamentoDocumento:', nome, tipo, arquivo.name);
    const fd = new FormData();
    fd.append('acao', 'upload_orcamento_documento');
    fd.append('orcamento_id', orcamentoId);
    fd.append('nome_documento', nome);
    fd.append('tipo_documento', tipo);
    fd.append('arquivo', arquivo);

    const d = await _fetch(API, { method: 'POST', body: fd });
    if (d?.sucesso) {
        _toast('Documento enviado com sucesso');
        $('ctr_orcDocNome').value = '';
        $('ctr_orcDocTipo').value = '';
        fileInput.value = '';
        _carregarOrcamentoDocumentos(orcamentoId);
    } else {
        _toast(d?.mensagem || 'Erro ao enviar documento', 'error');
    }
}

async function _excluirOrcamentoDocumento(docId) {
    if (!confirm('Excluir este documento?')) return;
    log('excluirOrcamentoDocumento:', docId);
    const d = await _post('deletar_orcamento_documento', { id: docId });
    if (d?.sucesso) {
        _toast('Documento excluido');
        _carregarOrcamentoDocumentos(_state.orcamentoAtual?.id);
    } else {
        _toast(d?.mensagem || 'Erro ao excluir', 'error');
    }
}

/* ═══════════════════════════════════════════════════════════════════
   MODAL NOVO / EDITAR CONTRATO
   ═══════════════════════════════════════════════════════════════════ */
function _abrirModalNovo() {
    log('abrirModalNovo');
    $('ctr_formId').value = '0';
    $('ctr_modalTitulo').innerHTML = '<i class="fas fa-file-contract"></i> Novo Contrato';
    $('ctr_btnSalvar').innerHTML   = '<i class="fas fa-save"></i> Gerar Contrato';
    $('ctr_formContrato').reset();
    _limparFornecedor();
    const preview = $('ctr_parcelasPreview');
    if (preview) preview.style.display = 'none';
    $('ctr_modalOverlay').style.display = 'flex';
}

function _abrirModalEditar(c) {
    log('abrirModalEditar:', c.id);
    $('ctr_formId').value = c.id;
    $('ctr_modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Contrato';
    $('ctr_btnSalvar').innerHTML   = '<i class="fas fa-save"></i> Salvar Alteracoes';

    // Preenche fornecedor
    $('ctr_formFornecedorId').value   = c.fornecedor_id || '0';
    $('ctr_formFornecedorNome').value = c.fornecedor_nome || '';
    $('ctr_formFornecedorCnpj').value = c.fornecedor_cnpj || '';

    const busca = $('ctr_formFornecedorBusca');
    if (busca) { busca.value = ''; busca.style.display = 'none'; }

    const sel = $('ctr_fornecedorSelecionado');
    if (sel) sel.style.display = 'flex';

    const info = $('ctr_fornecedorInfo');
    if (info) info.textContent = c.fornecedor_cnpj
        ? `${c.fornecedor_nome} — ${c.fornecedor_cnpj}`
        : (c.fornecedor_nome || '');

    // Preenche campos — usa nome_contrato (campo real da tabela)
    $('ctr_formTipo').value        = c.tipo_servico || '';
    $('ctr_formRecorrencia').value = c.recorrencia || '';
    $('ctr_formNome').value        = c.nome_contrato || c.nome || '';
    $('ctr_formInicio').value      = c.data_inicio || '';
    $('ctr_formFim').value         = c.data_fim || '';
    $('ctr_formValor').value       = parseFloat(c.valor_total || 0).toFixed(2).replace('.', ',');
    $('ctr_formVencimento').value  = c.data_vencimento || '';
    $('ctr_formPlano').value       = c.plano_conta_id || '';
    $('ctr_formObs').value         = c.observacoes || '';

    _calcularParcelas();
    $('ctr_modalOverlay').style.display = 'flex';
}

function _editarContrato() {
    if (_state.contratoAtual) _abrirModalEditar(_state.contratoAtual);
}

function fecharModal() {
    const overlay = $('ctr_modalOverlay');
    if (overlay) overlay.style.display = 'none';
}

function fecharModalExcluir() {
    const overlay = $('ctr_modalExcluirOverlay');
    if (overlay) overlay.style.display = 'none';
}

/* ═══════════════════════════════════════════════════════════════════
   CALCULAR PARCELAS PREVIEW
   ═══════════════════════════════════════════════════════════════════ */
function _calcularParcelas() {
    const rec    = $('ctr_formRecorrencia')?.value;
    const ini    = $('ctr_formInicio')?.value;
    const fim    = $('ctr_formFim')?.value;
    const valor  = _parseMoney($('ctr_formValor')?.value);
    const preview = $('ctr_parcelasPreview');
    if (!preview) return;

    if (!rec || !ini || !fim || !valor) { preview.style.display = 'none'; return; }

    let parcelas = 1;
    if (rec === 'mensal') {
        const d1 = new Date(ini), d2 = new Date(fim);
        parcelas = Math.max(1, (d2.getFullYear() - d1.getFullYear()) * 12 + d2.getMonth() - d1.getMonth());
    } else if (rec === 'anual') {
        const d1 = new Date(ini), d2 = new Date(fim);
        parcelas = Math.max(1, d2.getFullYear() - d1.getFullYear());
    } else if (rec === 'diaria') {
        const d1 = new Date(ini), d2 = new Date(fim);
        parcelas = Math.max(1, Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24)));
    }

    const valorParcela = valor / parcelas;
    preview.style.display = 'flex';

    const qtd = $('ctr_parcelasQtd');
    const val = $('ctr_parcelasValor');
    if (qtd) qtd.textContent = parcelas;
    if (val) val.textContent = _money(valorParcela);
}

/* ═══════════════════════════════════════════════════════════════════
   SALVAR CONTRATO
   API: POST acao=cadastrar|atualizar
   Campos: fornecedor_id, fornecedor_nome, fornecedor_cnpj, tipo_servico,
           nome_contrato, data_inicio, data_fim, recorrencia, valor_total,
           data_vencimento, plano_conta_id, observacoes, [id para atualizar]
   ═══════════════════════════════════════════════════════════════════ */
async function _salvarContrato() {
    const id             = $('ctr_formId')?.value || '0';
    const fornecedorId   = $('ctr_formFornecedorId')?.value;
    const tipo           = $('ctr_formTipo')?.value;
    const recorrencia    = $('ctr_formRecorrencia')?.value;
    const nome           = $('ctr_formNome')?.value?.trim();
    const dataInicio     = $('ctr_formInicio')?.value;
    const dataFim        = $('ctr_formFim')?.value;
    const valor          = _parseMoney($('ctr_formValor')?.value);
    const dataVencimento = $('ctr_formVencimento')?.value;
    const planoContaId   = $('ctr_formPlano')?.value;
    const obs            = $('ctr_formObs')?.value?.trim();

    if (!fornecedorId || fornecedorId === '0') { _toast('Selecione um fornecedor', 'error'); return; }
    if (!tipo)          { _toast('Selecione o tipo de servico', 'error'); return; }
    if (!recorrencia)   { _toast('Selecione a recorrencia', 'error'); return; }
    if (!nome)          { _toast('Informe o nome do contrato', 'error'); return; }
    if (!dataInicio)    { _toast('Informe a data de inicio', 'error'); return; }
    if (!dataFim)       { _toast('Informe a data de fim', 'error'); return; }
    if (dataFim <= dataInicio) { _toast('Data de fim deve ser posterior a data de inicio', 'error'); return; }
    if (!valor || valor <= 0) { _toast('Informe o valor total', 'error'); return; }
    if (!dataVencimento) { _toast('Informe a data de vencimento', 'error'); return; }
    if (!planoContaId)  { _toast('Selecione o plano de contas', 'error'); return; }

    const acao = id !== '0' ? 'atualizar' : 'cadastrar';
    const dados = {
        fornecedor_id:   fornecedorId,
        fornecedor_nome: $('ctr_formFornecedorNome')?.value || '',
        fornecedor_cnpj: $('ctr_formFornecedorCnpj')?.value || '',
        tipo_servico:    tipo,
        nome_contrato:   nome,          // campo correto: nome_contrato
        recorrencia,
        data_inicio:     dataInicio,
        data_fim:        dataFim,
        valor_total:     valor.toFixed(2),
        data_vencimento: dataVencimento,
        plano_conta_id:  planoContaId,
        observacoes:     obs || ''
    };
    if (id !== '0') dados.id = id;

    log('salvarContrato:', acao, dados);
    const d = await _post(acao, dados);

    if (d?.sucesso) {
        _toast(id !== '0' ? 'Contrato atualizado!' : `Contrato ${d.dados?.numero_contrato || ''} gerado!`);
        fecharModal();
        const novoId = d.dados?.id;
        if (novoId) {
            await _carregarLista();
            _abrirDetalhe(novoId);
        } else {
            _carregarLista();
        }
    } else {
        _toast(d?.mensagem || 'Erro ao salvar contrato', 'error');
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EXCLUIR CONTRATO
   ═══════════════════════════════════════════════════════════════════ */
function _confirmarExclusao() {
    if (!_state.contratoAtual) return;
    const el = $('ctr_excluirNome');
    if (el) el.textContent = `${_state.contratoAtual.numero_contrato} — ${_state.contratoAtual.nome_contrato || _state.contratoAtual.nome || ''}`;
    $('ctr_modalExcluirOverlay').style.display = 'flex';
}

async function _excluirContrato() {
    const id = _state.contratoAtual?.id;
    if (!id) return;
    log('excluirContrato:', id);
    const d = await _post('deletar', { id });
    if (d?.sucesso) {
        _toast('Contrato excluido');
        fecharModalExcluir();
        _voltarParaLista();
    } else {
        _toast(d?.mensagem || 'Erro ao excluir', 'error');
    }
}

/* ═══════════════════════════════════════════════════════════════════
   FILTROS
   ═══════════════════════════════════════════════════════════════════ */
function _limparFiltros() {
    if ($('ctr_fBusca'))  $('ctr_fBusca').value  = '';
    if ($('ctr_fStatus')) $('ctr_fStatus').value = '';
    if ($('ctr_fTipo'))   $('ctr_fTipo').value   = '';
    _state.pagina = 1;
    _carregarLista();
}

/* ═══════════════════════════════════════════════════════════════════
   RELATORIOS
   APIs: relatorio_ativos, relatorio_vencimentos, relatorio_por_fornecedor, relatorio_financeiro
   Retornam: { sucesso, dados: { lista: [...], total, total_valor? } }
   ═══════════════════════════════════════════════════════════════════ */
let _relDados   = [];
let _relColunas = [];

async function _gerarRelatorio(acao) {
    log('gerarRelatorio:', acao);
    let params = {};

    if (acao === 'relatorio_ativos')      params.tipo = $('ctr_relAtivosTipo')?.value || '';
    if (acao === 'relatorio_vencimentos') params.dias = $('ctr_relVencDias')?.value || '30';
    if (acao === 'relatorio_financeiro') {
        params.data_inicio = $('ctr_relFinIni')?.value || '';
        params.data_fim    = $('ctr_relFinFim')?.value || '';
    }

    const d = await _get(acao, params);
    if (!d?.sucesso) {
        _toast(d?.mensagem || 'Erro ao gerar relatorio', 'error');
        return;
    }

    const dados = Array.isArray(d?.dados?.lista) ? d.dados.lista : [];
    _relDados = dados;

    if (!dados.length) {
        _toast('Nenhum dado encontrado para este relatorio', 'info');
        $('ctr_relResultado').style.display = 'none';
        return;
    }

    _relColunas = Object.keys(dados[0]);
    $('ctr_relThead').innerHTML = '<tr>' + _relColunas.map(c => `<th>${c.toUpperCase().replace(/_/g, ' ')}</th>`).join('') + '</tr>';
    $('ctr_relTbody').innerHTML = dados.map(row =>
        '<tr>' + _relColunas.map(c => `<td>${_esc(String(row[c] ?? '-'))}</td>`).join('') + '</tr>'
    ).join('');

    $('ctr_relResultado').style.display = '';
    _toast(`Relatorio gerado: ${dados.length} registro(s)`);
}

function _exportarCSV() {
    if (!_relDados.length) return;
    const header = _relColunas.join(';');
    const rows   = _relDados.map(r => _relColunas.map(c => `"${String(r[c] ?? '').replace(/"/g, '""')}"`).join(';'));
    const csv    = '\uFEFF' + header + '\n' + rows.join('\n');
    const blob   = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url    = URL.createObjectURL(blob);
    const a      = document.createElement('a');
    a.href       = url;
    a.download   = `relatorio_contratos_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}


/* ═══════════════════════════════════════════════════════════════════
   RELATORIO DE LICITAÇÃO
   Sub-aba dentro do detalhe do contrato que mostra preview e gera PDF
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Carrega e renderiza o preview do relatório de licitação
 * usando os dados já carregados do contrato e orçamentos.
 */
async function _carregarRelLicitacao() {
    log('carregarRelLicitacao');
    const c = _state.contratoAtual;
    if (!c) return;

    // Buscar orçamentos se ainda não carregados
    const d = await _get('listar_orcamentos', { contrato_id: c.id });
    const orcs = Array.isArray(d?.dados) ? d.dados : [];

    // Ordenar por valor ASC (menor = vencedor)
    orcs.sort((a, b) => parseFloat(a.valor) - parseFloat(b.valor));

    const vencedor = orcs.length > 0 ? orcs[0] : null;

    // ── Dados da empresa (buscar uma vez) ──
    let empresa = _state._empresaCache;
    if (!empresa) {
        try {
            const re = await fetch('../api/api_empresa.php?acao=obter', { credentials: 'include' });
            const je = await re.json();
            empresa = je?.dados || {};
            _state._empresaCache = empresa;
        } catch(e) {
            empresa = {};
        }
    }

    const nomeEmp = empresa.nome_fantasia || empresa.razao_social || 'ASSOCIAÇÃO SERRA DA LIBERDADE';
    const cnpjEmp = empresa.cnpj || '';
    const endEmp  = [empresa.endereco_rua, empresa.endereco_numero, empresa.endereco_cidade, empresa.endereco_estado]
                    .filter(Boolean).join(', ');
    const logoUrl = empresa.logo_url || '';
    const cidade  = empresa.endereco_cidade || 'Brumadinho';
    const estado  = empresa.endereco_estado || 'MG';

    // ── Preencher cabeçalho ──
    const setTxt = (id, v) => { const el = $(id); if (el) el.textContent = v || '—'; };

    // Logo
    const logoImg = $('ctr_licLogoPreview');
    const logoPlaceholder = $('ctr_licLogoPlaceholder');
    if (logoImg && logoUrl) {
        logoImg.src = logoUrl;
        logoImg.style.display = '';
        if (logoPlaceholder) logoPlaceholder.style.display = 'none';
    }

    setTxt('ctr_licEmpresaNome', nomeEmp);
    setTxt('ctr_licEmpresaCnpj', cnpjEmp ? 'CNPJ: ' + cnpjEmp : '');
    setTxt('ctr_licEmpresaEndereco', endEmp);
    setTxt('ctr_licNumContrato', c.numero_contrato);

    const hoje = new Date();
    const dataFmt = hoje.toLocaleDateString('pt-BR');
    setTxt('ctr_licDataRelatorio', 'Emitido em: ' + dataFmt);

    // ── Dados do contrato ──
    setTxt('ctr_licContNome', c.nome_contrato || c.nome || '—');
    setTxt('ctr_licContTipo', c.tipo_servico === 'venda' ? 'Venda de Produto' : 'Prestação de Serviço');
    setTxt('ctr_licContValor', _money(c.valor_total));
    const vigencia = (_dateBR(c.data_inicio) || '—') + ' a ' + (_dateBR(c.data_fim) || '—');
    setTxt('ctr_licContVigencia', vigencia);

    // ── Empresa vencedora ──
    if (vencedor) {
        setTxt('ctr_licVencNome', vencedor.fornecedor);
        setTxt('ctr_licVencValor', _money(vencedor.valor));
        setTxt('ctr_licVencDesc', vencedor.descricao);
    }

    // ── Tabela comparativa ──
    const tbody = $('ctr_licOrcTbody');
    if (tbody) {
        if (!orcs.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;opacity:.5;padding:16px;">Nenhum orçamento cadastrado</td></tr>';
        } else {
            tbody.innerHTML = orcs.map((o, i) => {
                const isVenc = i === 0;
                const badge  = isVenc
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:20px;font-size:9px;font-weight:700;">✓ Vencedor</span>'
                    : '<span style="background:#f1f5f9;color:#475569;padding:2px 10px;border-radius:20px;font-size:9px;">Cotado</span>';
                const dataCriacao = o.data_criacao ? new Date(o.data_criacao).toLocaleDateString('pt-BR') : '—';
                return `<tr style="${isVenc ? 'background:#f0fdf4;' : ''}">
                    <td>${i + 1}</td>
                    <td style="font-weight:${isVenc ? '700' : '400'};">${_esc(o.fornecedor)}</td>
                    <td>${_esc(o.descricao)}</td>
                    <td style="font-weight:${isVenc ? '800' : '400'};color:${isVenc ? '#16a34a' : 'inherit'};">${_money(o.valor)}</td>
                    <td>${badge}</td>
                </tr>`;
            }).join('');
        }
    }

    // ── Justificativa (se houver no vencedor) ──
    const justSection = $('ctr_licJustSection');
    const justTexto   = $('ctr_licJustTexto');
    if (vencedor?.justificativa && justSection && justTexto) {
        justTexto.textContent = vencedor.justificativa;
        justSection.style.display = '';
    } else if (justSection) {
        justSection.style.display = 'none';
    }

    // ── Local e data ──
    setTxt('ctr_licLocalData', cidade + '/' + estado + ', ' + dataFmt);
    setTxt('ctr_licRodapeData', dataFmt);
}

/**
 * Abre o relatório de licitação em nova aba para impressão/PDF
 */
function _gerarPdfLicitacao() {
    log('gerarPdfLicitacao');
    const c = _state.contratoAtual;
    if (!c) { _toast('Nenhum contrato selecionado', 'error'); return; }

    const nome  = $('ctr_licInputNome')?.value?.trim() || '';
    const cargo = $('ctr_licInputCargo')?.value?.trim() || 'Presidente da Associação';

    const params = new URLSearchParams({
        contrato_id: c.id,
        responsavel: nome,
        cargo: cargo
    });

    const url = `../api/api_relatorio_licitacao_pdf.php?${params.toString()}`;
    window.open(url, '_blank');
}

// Expor para uso no HTML (onclick)
if (!window.ContratosPage) window.ContratosPage = {};
window.ContratosPage.gerarPdfLicitacao = _gerarPdfLicitacao;
