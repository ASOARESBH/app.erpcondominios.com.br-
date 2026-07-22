/**
 * CONTAS BANCÁRIAS — Módulo Financeiro
 * Versão: 1.0  |  2026-06-08
 * ES Module — export init/destroy para AppRouter
 */

// ─── Estado ──────────────────────────────────────────────
const S = {
    contas:         [],
    contaAtual:     null,
    movimentacoes:  [],
    movTotal:       0,
    movOffset:      0,
    movLimite:      50,
    movSelecionados:new Set(),
    abaAtual:       'movimentacoes',
    ofxArquivo:     null,
    ofxPreview:     null,
    relDados:       null,
    // conciliação
    concPendentes:  [],
    concTotal:      0,
    concOffset:     0,
    concLimite:     50,
    concMovId:      null,
};

const API = '/api/api_contas_bancarias.php';
// ─── Bancos brasileiros (autocomplete via API) ────────────────
let _bancosTimer = null;  // debounce timer
let _bancosDropdown = null; // elemento dropdown ativo

// ─── Init / Destroy ──────────────────────────────────────
export function init() {
    console.log('[ContasBancarias] init()');
    window.ContasBancarias = _api_publica();
    _carregarKPIs();
    _carregarContas();
    _definirDatasPadrao();
}

export function destroy() {
    console.log('[ContasBancarias] destroy()');
    delete window.ContasBancarias;
}

// ─── API Pública (exposta no window) ─────────────────────
function _api_publica() {
    return {
        abrirModalConta,
        fecharModalConta,
        salvarConta,
        editarConta,
        selecionarConta,
        abrirModalMovimentacao,
        fecharModalMovimentacao,
        salvarMovimentacao,
        editarMovimentacao,
        excluirMovimentacao,
        mudarAba,
        filtrarMovimentacoes,
        selecionarTodos,
        conciliarSelecionados,
        exportarCSV,
        autocompletarBanco,
        autocompletarBancoNome,
        setTipoMov,
        dragOver,
        dragLeave,
        dropArquivo,
        selecionarArquivo,
        cancelarOFX,
        confirmarImportacao,
        novaImportacao,
        gerarRelatorio,
        exportarRelatorioCSV,
        imprimirRelatorio,
        // conciliação
        filtrarConciliacao,
        abrirModalCandidatos,
        fecharModalCandidatos,
        vincularConciliacao,
        ignorarMovimentacao,
        _carregarPendentes,
    };
}

// =====================================================
// CARREGAMENTO DE DADOS
// =====================================================

async function _carregarKPIs() {
    try {
        const d = await _get('kpis');
        if (!d.sucesso) return;
        const k = d.dados;
        _el('kpi-total-contas').textContent = k.total_contas;
        _el('kpi-saldo-total').textContent   = _moeda(k.saldo_total);
        _el('kpi-creditos-mes').textContent  = _moeda(k.total_creditos_mes);
        _el('kpi-debitos-mes').textContent   = _moeda(k.total_debitos_mes);
        _el('kpi-pendentes').textContent     = k.pendentes_conciliacao;
    } catch(e) { console.error('[CB] KPIs:', e); }
}

async function _carregarContas() {
    const lista = _el('cb-lista-contas');
    lista.innerHTML = '<div class="cb-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    try {
        const d = await _get('listar_contas');
        if (!d.sucesso) { lista.innerHTML = `<div class="cb-erro">${d.mensagem}</div>`; return; }
        S.contas = d.dados;
        _renderContas();
    } catch(e) {
        lista.innerHTML = '<div class="cb-erro">Erro ao carregar contas</div>';
    }
}

function _renderContas() {
    const lista = _el('cb-lista-contas');
    if (!S.contas.length) {
        lista.innerHTML = '<div class="cb-vazio-lista"><i class="fas fa-university"></i><p>Nenhuma conta cadastrada</p><p>Clique em "Nova Conta" para começar</p></div>';
        return;
    }
    lista.innerHTML = S.contas.map(c => `
        <div class="cb-conta-item ${S.contaAtual?.id === c.id ? 'ativa' : ''}"
             onclick="ContasBancarias.selecionarConta(${c.id})">
            <div class="cb-conta-item-icon">
                <i class="fas fa-university"></i>
            </div>
            <div class="cb-conta-item-info">
                <strong>${_esc(c.nome)}</strong>
                <span>${_esc(c.banco_nome)} · Ag. ${_esc(c.agencia)} · C/C ${_esc(c.conta_numero)}</span>
                <span class="cb-conta-tipo-badge">${_labelTipo(c.conta_tipo)}</span>
            </div>
            <div class="cb-conta-item-saldo ${c.saldo_atual >= 0 ? 'positivo' : 'negativo'}">
                ${_moeda(c.saldo_atual)}
            </div>
        </div>
    `).join('');
}

async function selecionarConta(id) {
    S.contaAtual = S.contas.find(c => c.id === id) || null;
    if (!S.contaAtual) return;

    // Atualizar lista (highlight)
    _renderContas();

    // Mostrar painel de detalhe
    _el('cb-detalhe-vazio').style.display = 'none';
    _el('cb-detalhe-conteudo').style.display = 'block';

    // Preencher header da conta
    _el('cb-conta-nome').textContent  = S.contaAtual.nome;
    _el('cb-conta-dados').textContent = `${S.contaAtual.banco_nome} · Ag. ${S.contaAtual.agencia} · ${S.contaAtual.conta_numero}`;
    _el('cb-conta-saldo').textContent = _moeda(S.contaAtual.saldo_atual);
    _el('cb-conta-saldo').className   = 'cb-conta-saldo-valor ' + (S.contaAtual.saldo_atual >= 0 ? 'positivo' : 'negativo');

    // Carregar aba atual
    mudarAba(S.abaAtual);
}

// =====================================================
// ABAS
// =====================================================

function mudarAba(aba) {
    S.abaAtual = aba;
    document.querySelectorAll('.cb-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === aba));
    document.querySelectorAll('.cb-tab-content').forEach(c => c.classList.toggle('active', c.id === `tab-${aba}`));

    if (!S.contaAtual) return;
    if (aba === 'movimentacoes') _carregarMovimentacoes();
    if (aba === 'importar')      _verificarUltimoImportado();
    if (aba === 'historico')     _carregarHistorico();
    if (aba === 'conciliacao')   _carregarPendentes(0);
}

// =====================================================
// MOVIMENTAÇÕES
// =====================================================

async function _carregarMovimentacoes(offset = 0) {
    S.movOffset = offset;
    const params = new URLSearchParams({
        acao:       'listar_movimentacoes',
        conta_id:   S.contaAtual.id,
        tipo:       _val('mov-filtro-tipo'),
        conciliado: _val('mov-filtro-conciliado'),
        dt_ini:     _val('mov-filtro-dt-ini'),
        dt_fim:     _val('mov-filtro-dt-fim'),
        busca:      _val('mov-filtro-busca'),
        limite:     S.movLimite,
        offset:     offset,
    });
    const tbody = _el('cb-tbody-mov');
    tbody.innerHTML = '<tr><td colspan="8" class="cb-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
    try {
        const d = await _get_params(params);
        if (!d.sucesso) { tbody.innerHTML = `<tr><td colspan="8" class="cb-erro">${d.mensagem}</td></tr>`; return; }
        S.movimentacoes = d.dados.movimentacoes;
        S.movTotal = d.dados.total;
        _renderMovimentacoes();
        _renderPaginacao();
        _el('mov-total-badge').textContent = `${S.movTotal} registros`;
    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="8" class="cb-erro">Erro ao carregar movimentações</td></tr>';
    }
}

function _renderMovimentacoes() {
    const tbody = _el('cb-tbody-mov');
    if (!S.movimentacoes.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="cb-vazio">Nenhuma movimentação encontrada</td></tr>';
        return;
    }
    tbody.innerHTML = S.movimentacoes.map(m => `
        <tr class="${S.movSelecionados.has(m.id) ? 'selecionada' : ''}">
            <td><input type="checkbox" class="cb-check-mov" data-id="${m.id}"
                       ${S.movSelecionados.has(m.id) ? 'checked' : ''}
                       onchange="ContasBancarias._toggleSelecionado(${m.id}, this.checked)"></td>
            <td>${_formatarData(m.data_lancamento)}</td>
            <td class="cb-descricao-cell" title="${_esc(m.descricao)}">${_esc(m.descricao)}</td>
            <td>${m.checknum ? _esc(m.checknum) : '<span class="cb-dash">—</span>'}</td>
            <td>
                <span class="cb-badge ${m.tipo}">
                    ${m.tipo === 'credito' ? '▼ Crédito' : '▲ Débito'}
                </span>
            </td>
            <td class="text-right ${m.tipo}">
                ${m.tipo === 'credito' ? '+' : '-'} ${_moeda(m.valor)}
            </td>
            <td>
                <span class="cb-badge ${m.conciliado ? 'conciliado' : 'pendente'}">
                    ${m.conciliado ? '✓ Conciliado' : '● Pendente'}
                </span>
            </td>
            <td class="cb-acoes-cell">
                <button class="cb-btn-icon" title="Editar"
                        onclick="ContasBancarias.editarMovimentacao(${m.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="cb-btn-icon vermelho" title="Excluir"
                        onclick="ContasBancarias.excluirMovimentacao(${m.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function _renderPaginacao() {
    const pag = _el('cb-paginacao');
    const totalPags = Math.ceil(S.movTotal / S.movLimite);
    const pagAtual  = Math.floor(S.movOffset / S.movLimite) + 1;
    if (totalPags <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    if (pagAtual > 1) html += `<button onclick="ContasBancarias._carregarMovimentacoes(${(pagAtual-2)*S.movLimite})">‹</button>`;
    for (let p = Math.max(1,pagAtual-2); p <= Math.min(totalPags,pagAtual+2); p++) {
        html += `<button class="${p===pagAtual?'ativa':''}" onclick="ContasBancarias._carregarMovimentacoes(${(p-1)*S.movLimite})">${p}</button>`;
    }
    if (pagAtual < totalPags) html += `<button onclick="ContasBancarias._carregarMovimentacoes(${pagAtual*S.movLimite})">›</button>`;
    pag.innerHTML = html;
}

function filtrarMovimentacoes() {
    clearTimeout(window._cbFiltroTimer);
    window._cbFiltroTimer = setTimeout(() => _carregarMovimentacoes(0), 400);
}

function selecionarTodos(cb) {
    S.movimentacoes.forEach(m => {
        if (cb.checked) S.movSelecionados.add(m.id);
        else S.movSelecionados.delete(m.id);
    });
    _renderMovimentacoes();
    _atualizarConciliacaoLote();
}

window.ContasBancarias = window.ContasBancarias || {};
window.ContasBancarias._toggleSelecionado = function(id, checked) {
    if (checked) S.movSelecionados.add(id);
    else S.movSelecionados.delete(id);
    _atualizarConciliacaoLote();
};
window.ContasBancarias._carregarMovimentacoes = _carregarMovimentacoes;
window.ContasBancarias._carregarPendentes     = _carregarPendentes;

function _atualizarConciliacaoLote() {
    const lote = _el('cb-conciliacao-lote');
    const n = S.movSelecionados.size;
    lote.style.display = n > 0 ? 'flex' : 'none';
    _el('cb-selecionados-count').textContent = `${n} selecionado${n>1?'s':''}`;
}

async function conciliarSelecionados(valor) {
    if (!S.movSelecionados.size) return;
    const ids = Array.from(S.movSelecionados);
    const d = await _post({ acao: 'conciliar', ids, conciliado: valor });
    if (d.sucesso) {
        _toast('Conciliação atualizada', 'sucesso');
        S.movSelecionados.clear();
        _carregarMovimentacoes(S.movOffset);
        _carregarKPIs();
    } else {
        _toast(d.mensagem, 'erro');
    }
}

async function exportarCSV() {
    if (!S.contaAtual) return;
    const params = new URLSearchParams({
        acao:       'relatorio_extrato',
        conta_id:   S.contaAtual.id,
        dt_ini:     _val('mov-filtro-dt-ini') || '2000-01-01',
        dt_fim:     _val('mov-filtro-dt-fim') || new Date().toISOString().slice(0,10),
    });
    const d = await _get_params(params);
    if (!d.sucesso) { _toast(d.mensagem, 'erro'); return; }
    const movs = d.dados.movimentacoes;
    const linhas = [['Data','Descrição','Documento','Tipo','Valor','Conciliado']];
    movs.forEach(m => linhas.push([
        m.data_lancamento, m.descricao, m.checknum||'', m.tipo,
        (m.tipo==='debito'?'-':'')+m.valor.toFixed(2).replace('.',','),
        m.conciliado?'Sim':'Não'
    ]));
    _downloadCSV(linhas, `extrato_${S.contaAtual.nome}_${Date.now()}.csv`);
}

// =====================================================
// MODAL CONTA
// =====================================================

function abrirModalConta(conta = null) {
    _el('modal-conta-titulo').innerHTML = conta
        ? '<i class="fas fa-edit"></i> Editar Conta Bancária'
        : '<i class="fas fa-university"></i> Nova Conta Bancária';
    _el('conta-id').value           = conta?.id || '';
    _el('conta-nome').value         = conta?.nome || '';
    _el('conta-tipo').value         = conta?.conta_tipo || 'corrente';
    _el('conta-banco-codigo').value = conta?.banco_codigo || '';
    _el('conta-banco-nome').value   = conta?.banco_nome || '';
    _el('conta-agencia').value      = conta?.agencia || '';
    _el('conta-numero').value       = conta?.conta_numero || '';
    _el('conta-moeda').value        = conta?.moeda || 'BRL';
    _el('conta-saldo-inicial').value= conta?.saldo_inicial || 0;
    _el('conta-obs').value          = conta?.observacoes || '';
    _el('modal-conta').style.display = 'flex';
}

function fecharModalConta() {
    _el('modal-conta').style.display = 'none';
}

function editarConta() {
    if (!S.contaAtual) return;
    abrirModalConta(S.contaAtual);
}

async function salvarConta() {
    const id = _val('conta-id');
    const body = {
        acao:           id ? 'atualizar_conta' : 'criar_conta',
        id:             id || undefined,
        nome:           _val('conta-nome'),
        conta_tipo:     _val('conta-tipo'),
        banco_codigo:   _val('conta-banco-codigo'),
        banco_nome:     _val('conta-banco-nome'),
        agencia:        _val('conta-agencia'),
        conta_numero:   _val('conta-numero'),
        moeda:          _val('conta-moeda'),
        saldo_inicial:  _val('conta-saldo-inicial'),
        observacoes:    _val('conta-obs'),
    };
    if (!body.nome || !body.banco_codigo || !body.banco_nome || !body.agencia || !body.conta_numero) {
        _toast('Preencha todos os campos obrigatórios', 'aviso'); return;
    }
    const d = await _post(body);
    if (d.sucesso) {
        _toast(id ? 'Conta atualizada' : 'Conta criada com sucesso', 'sucesso');
        fecharModalConta();
        await _carregarContas();
        _carregarKPIs();
        if (d.dados?.id) selecionarConta(d.dados.id);
        else if (S.contaAtual) selecionarConta(S.contaAtual.id);
    } else {
        _toast(d.mensagem, 'erro');
    }
}

// Autocomplete de banco: busca na API com debounce 300ms
function autocompletarBanco(query) {
    clearTimeout(_bancosTimer);
    _fecharDropdownBancos();
    const q = query.trim();
    if (!q) return;
    _bancosTimer = setTimeout(async () => {
        try {
            const res = await fetch(`${API}?acao=buscar_banco&q=${encodeURIComponent(q)}`, { credentials: 'include' });
            const data = await res.json();
            if (data.sucesso && data.dados && data.dados.length > 0) {
                _abrirDropdownBancos(data.dados);
            }
        } catch(e) { console.warn('[ContasBancarias] autocompletarBanco erro:', e); }
    }, 300);
}

function _abrirDropdownBancos(bancos) {
    _fecharDropdownBancos();
    const input = _el('conta-banco-codigo');
    if (!input) return;
    const dd = document.createElement('div');
    dd.id = 'banco-dropdown';
    dd.className = 'banco-autocomplete-dropdown';
    bancos.forEach(b => {
        const item = document.createElement('div');
        item.className = 'banco-autocomplete-item';
        item.innerHTML = `<span class="banco-codigo">${b.codigo}</span><span class="banco-nome">${b.nome}</span>`;
        item.addEventListener('mousedown', (e) => {
            e.preventDefault();
            _el('conta-banco-codigo').value = b.codigo;
            _el('conta-banco-nome').value   = b.nome;
            _fecharDropdownBancos();
        });
        dd.appendChild(item);
    });
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(dd);
    _bancosDropdown = dd;
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', _fecharDropdownBancos, { once: true });
    }, 10);
}

function _fecharDropdownBancos() {
    if (_bancosDropdown) {
        _bancosDropdown.remove();
        _bancosDropdown = null;
    }
}

// Autocomplete por nome do banco
function autocompletarBancoNome(query) {
    clearTimeout(_bancosTimer);
    _fecharDropdownBancos();
    const q = query.trim();
    if (q.length < 2) return;
    _bancosTimer = setTimeout(async () => {
        try {
            const res = await fetch(`${API}?acao=buscar_banco&q=${encodeURIComponent(q)}`, { credentials: 'include' });
            const data = await res.json();
            if (data.sucesso && data.dados && data.dados.length > 0) {
                // Dropdown ancorado no campo nome
                _fecharDropdownBancos();
                const input = _el('conta-banco-nome');
                if (!input) return;
                const dd = document.createElement('div');
                dd.id = 'banco-dropdown';
                dd.className = 'banco-autocomplete-dropdown';
                data.dados.forEach(b => {
                    const item = document.createElement('div');
                    item.className = 'banco-autocomplete-item';
                    item.innerHTML = `<span class="banco-codigo">${b.codigo}</span><span class="banco-nome">${b.nome}</span>`;
                    item.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        _el('conta-banco-codigo').value = b.codigo;
                        _el('conta-banco-nome').value   = b.nome;
                        _fecharDropdownBancos();
                    });
                    dd.appendChild(item);
                });
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dd);
                _bancosDropdown = dd;
                setTimeout(() => {
                    document.addEventListener('click', _fecharDropdownBancos, { once: true });
                }, 10);
            }
        } catch(e) { console.warn('[ContasBancarias] autocompletarBancoNome erro:', e); }
    }, 300);
}

// =====================================================
// MODAL MOVIMENTAÇÃO
// =====================================================

function abrirModalMovimentacao(mov = null) {
    _el('modal-mov-titulo').innerHTML = mov
        ? '<i class="fas fa-edit"></i> Editar Lançamento'
        : '<i class="fas fa-exchange-alt"></i> Novo Lançamento';
    _el('mov-id').value               = mov?.id || '';
    _el('mov-data').value             = mov?.data_lancamento || new Date().toISOString().slice(0,10);
    _el('mov-valor').value            = mov?.valor || '';
    _el('mov-descricao').value        = mov?.descricao || '';
    _el('mov-favorecido').value       = mov?.favorecido || '';
    _el('mov-checknum').value         = mov?.checknum || '';
    _el('mov-numero-documento').value = mov?.numero_documento || '';
    _el('mov-categoria').value        = mov?.categoria || '';
    _el('mov-centro-custo').value     = mov?.centro_custo || '';
    _el('mov-status').value           = mov?.status || 'pendente';
    _el('mov-obs').value              = mov?.observacoes || '';
    setTipoMov(mov?.tipo || 'credito');
    _el('modal-movimentacao').style.display = 'flex';
}

function fecharModalMovimentacao() {
    _el('modal-movimentacao').style.display = 'none';
}

function setTipoMov(tipo) {
    _el('mov-tipo').value = tipo;
    _el('btn-tipo-credito').classList.toggle('active', tipo === 'credito');
    _el('btn-tipo-debito').classList.toggle('active', tipo === 'debito');
}

async function editarMovimentacao(id) {
    const mov = S.movimentacoes.find(m => m.id === id);
    if (mov) abrirModalMovimentacao(mov);
}

async function excluirMovimentacao(id) {
    if (!confirm('Excluir esta movimentação?')) return;
    const d = await _post({ acao: 'excluir_movimentacao', id });
    if (d.sucesso) {
        _toast('Movimentação excluída', 'sucesso');
        _carregarMovimentacoes(S.movOffset);
        _carregarKPIs();
        // Atualizar saldo no header
        const conta = S.contas.find(c => c.id === S.contaAtual?.id);
        if (conta) { const r = await _get(`obter_conta&id=${S.contaAtual.id}`); if(r.sucesso){ S.contaAtual=r.dados; _el('cb-conta-saldo').textContent=_moeda(r.dados.saldo_atual); } }
    } else {
        _toast(d.mensagem, 'erro');
    }
}

async function salvarMovimentacao() {
    const id = _val('mov-id');
    const body = {
        acao:             id ? 'atualizar_movimentacao' : 'criar_movimentacao',
        id:               id || undefined,
        conta_id:         S.contaAtual?.id,
        tipo:             _val('mov-tipo'),
        data_lancamento:  _val('mov-data'),
        valor:            _val('mov-valor'),
        descricao:        _val('mov-descricao'),
        favorecido:       _val('mov-favorecido'),
        checknum:         _val('mov-checknum'),
        numero_documento: _val('mov-numero-documento'),
        categoria:        _val('mov-categoria'),
        centro_custo:     _val('mov-centro-custo'),
        status:           _val('mov-status'),
        observacoes:      _val('mov-obs'),
    };
    if (!body.data_lancamento || !body.valor || !body.descricao) {
        _toast('Preencha data, valor e descrição', 'aviso'); return;
    }
    const d = await _post(body);
    if (d.sucesso) {
        _toast(id ? 'Lançamento atualizado' : 'Lançamento criado', 'sucesso');
        fecharModalMovimentacao();
        _carregarMovimentacoes(S.movOffset);
        _carregarKPIs();
        // Atualizar saldo
        const r = await _get(`obter_conta&id=${S.contaAtual.id}`);
        if (r.sucesso) { S.contaAtual = r.dados; _el('cb-conta-saldo').textContent = _moeda(r.dados.saldo_atual); }
    } else {
        _toast(d.mensagem, 'erro');
    }
}

// =====================================================
// IMPORTAÇÃO OFX
// =====================================================

async function _verificarUltimoImportado() {
    if (!S.contaAtual) return;
    const el = _el('cb-ultimo-importado-texto');
    try {
        const d = await _get(`ultimo_importado&conta_id=${S.contaAtual.id}`);
        if (d.sucesso && d.dados) {
            const u = d.dados;
            el.innerHTML = `<strong>Último arquivo importado:</strong> ${_esc(u.nome_arquivo)} em ${_formatarDataHora(u.importado_em)} — último FITID: <code>${_esc(u.ultimo_fitid)}</code>`;
        } else {
            el.textContent = 'Nenhum arquivo OFX importado ainda para esta conta.';
        }
    } catch(e) {
        el.textContent = 'Não foi possível verificar o histórico de importações.';
    }
}

function dragOver(e) {
    e.preventDefault();
    _el('cb-ofx-drop').classList.add('drag-over');
}

function dragLeave(e) {
    _el('cb-ofx-drop').classList.remove('drag-over');
}

function dropArquivo(e) {
    e.preventDefault();
    _el('cb-ofx-drop').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) _processarArquivoOFX(file);
}

function selecionarArquivo(input) {
    if (input.files[0]) _processarArquivoOFX(input.files[0]);
}

async function _processarArquivoOFX(file) {
    if (!S.contaAtual) { _toast('Selecione uma conta primeiro', 'aviso'); return; }
    S.ofxArquivo = file;

    // Mostrar loading no drop
    _el('cb-ofx-drop').innerHTML = '<i class="fas fa-spinner fa-spin fa-2x"></i><p>Analisando arquivo...</p>';

    const form = new FormData();
    form.append('acao', 'preview_ofx');
    form.append('conta_id', S.contaAtual.id);
    form.append('ofx_file', file);

    try {
        const resp = await fetch(API, { method: 'POST', credentials: 'include', body: form });
        const d = await resp.json();
        if (!d.sucesso) { _toast(d.mensagem, 'erro'); _resetarDropZone(); return; }
        S.ofxPreview = d.dados;
        _mostrarPreviewOFX(d.dados);
    } catch(e) {
        _toast('Erro ao analisar arquivo OFX', 'erro');
        _resetarDropZone();
    }
}

function _mostrarPreviewOFX(p) {
    _el('cb-ofx-drop').style.display = 'none';
    const prev = _el('cb-ofx-preview');
    prev.style.display = 'block';

    _el('prev-nome-arquivo').textContent = p.nome_arquivo;
    _el('prev-periodo').textContent = `Período: ${_formatarData(p.dt_inicio)} a ${_formatarData(p.dt_fim)}`;
    _el('prev-total').textContent = p.total_transacoes;
    _el('prev-novas').textContent = p.novas;
    _el('prev-dup').textContent   = p.duplicatas;
    _el('prev-saldo').textContent = _moeda(p.saldo_final);

    const btn = _el('btn-importar-ofx');
    if (p.novas === 0) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-check"></i> Tudo já importado';
    } else {
        btn.disabled = false;
        btn.innerHTML = `<i class="fas fa-file-import"></i> Importar ${p.novas} Transações Novas`;
    }
}

function cancelarOFX() {
    S.ofxArquivo = null;
    S.ofxPreview = null;
    _el('cb-ofx-preview').style.display = 'none';
    _el('cb-ofx-resultado').style.display = 'none';
    _el('cb-ofx-progresso').style.display = 'none';
    _resetarDropZone();
}

function _resetarDropZone() {
    const drop = _el('cb-ofx-drop');
    drop.style.display = 'flex';
    drop.innerHTML = `
        <i class="fas fa-cloud-upload-alt"></i>
        <p>Arraste o arquivo <strong>.OFX</strong> aqui ou</p>
        <label class="btn-primary" for="ofx-input">
            <i class="fas fa-folder-open"></i> Selecionar Arquivo
        </label>
        <input type="file" id="ofx-input" accept=".ofx,.OFX" style="display:none"
               onchange="ContasBancarias.selecionarArquivo(this)">
        <p class="cb-ofx-hint">Suporta arquivos OFX de qualquer banco brasileiro</p>`;
}

async function confirmarImportacao() {
    if (!S.ofxArquivo || !S.contaAtual) return;

    _el('cb-ofx-preview').style.display = 'none';
    const prog = _el('cb-ofx-progresso');
    prog.style.display = 'block';
    _el('cb-progresso-fill').style.width = '30%';
    _el('cb-progresso-texto').textContent = 'Enviando arquivo...';

    const form = new FormData();
    form.append('acao', 'importar_ofx');
    form.append('conta_id', S.contaAtual.id);
    form.append('ofx_file', S.ofxArquivo);

    try {
        _el('cb-progresso-fill').style.width = '60%';
        _el('cb-progresso-texto').textContent = 'Processando transações...';

        const resp = await fetch(API, { method: 'POST', credentials: 'include', body: form });
        const d = await resp.json();

        _el('cb-progresso-fill').style.width = '100%';
        prog.style.display = 'none';

        if (d.sucesso) {
            _mostrarResultadoOFX(d.dados, true);
            _carregarKPIs();
            // Atualizar saldo da conta
            const r = await _get(`obter_conta&id=${S.contaAtual.id}`);
            if (r.sucesso) {
                S.contaAtual = r.dados;
                S.contas = S.contas.map(c => c.id === r.dados.id ? r.dados : c);
                _el('cb-conta-saldo').textContent = _moeda(r.dados.saldo_atual);
                _renderContas();
            }
            _verificarUltimoImportado();
        } else {
            _mostrarResultadoOFX({ erro: d.mensagem }, false);
        }
    } catch(e) {
        prog.style.display = 'none';
        _mostrarResultadoOFX({ erro: 'Erro de comunicação com o servidor' }, false);
    }
}

function _mostrarResultadoOFX(dados, sucesso) {
    const res = _el('cb-ofx-resultado');
    res.style.display = 'block';
    _el('cb-resultado-icon').innerHTML = sucesso
        ? '<i class="fas fa-check-circle verde"></i>'
        : '<i class="fas fa-times-circle vermelho"></i>';
    _el('cb-resultado-titulo').textContent = sucesso ? 'Importação concluída com sucesso!' : 'Erro na importação';
    if (sucesso) {
        const fmt = v => String(v ?? 0);
        const fmtOFX = s => s === 'sgml' ? 'SGML (Bradesco/BB/Caixa/Santander)' : s === 'xml' ? 'XML (Itaú/Nubank)' : (s || '—');
        _el('cb-resultado-stats').innerHTML = `
            <div class="cb-res-stat verde"><strong>${fmt(dados.importadas)}</strong><span>Importadas</span></div>
            <div class="cb-res-stat cinza"><strong>${fmt(dados.duplicatas)}</strong><span>Já existiam</span></div>
            <div class="cb-res-stat azul"><strong>${fmt(dados.total_arq)}</strong><span>Total no arquivo</span></div>
            <div class="cb-res-stat verde"><strong>${fmt(dados.conciliadas_auto)}</strong><span>Auto-conciliadas</span></div>
            <div class="cb-res-stat amarelo"><strong>${fmt(dados.pendentes)}</strong><span>Pendentes conciliação</span></div>
            <div class="cb-res-stat" style="font-size:11px;color:#64748b"><strong>${fmtOFX(dados.formato_ofx)}</strong><span>Formato OFX</span></div>
        `;
    } else {
        _el('cb-resultado-stats').innerHTML = `<p class="cb-erro">${dados.erro}</p>`;
    }
}

function novaImportacao() {
    _el('cb-ofx-resultado').style.display = 'none';
    cancelarOFX();
}

// =====================================================
// HISTÓRICO DE IMPORTAÇÕES
// =====================================================

async function _carregarHistorico() {
    if (!S.contaAtual) return;
    const tbody = _el('cb-tbody-historico');
    tbody.innerHTML = '<tr><td colspan="8" class="cb-loading"><i class="fas fa-spinner fa-spin"></i></td></tr>';
    const d = await _get(`historico_importacoes&conta_id=${S.contaAtual.id}`);
    if (!d.sucesso) { tbody.innerHTML = `<tr><td colspan="8" class="cb-erro">${d.mensagem}</td></tr>`; return; }
    if (!d.dados.length) { tbody.innerHTML = '<tr><td colspan="8" class="cb-vazio">Nenhuma importação registrada</td></tr>'; return; }
    tbody.innerHTML = d.dados.map(h => `
        <tr>
            <td>${_formatarDataHora(h.importado_em)}</td>
            <td class="cb-descricao-cell" title="${_esc(h.nome_arquivo)}">${_esc(h.nome_arquivo)}</td>
            <td>${_formatarData(h.dt_inicio_ofx)} a ${_formatarData(h.dt_fim_ofx)}</td>
            <td class="text-right">${h.total_transacoes}</td>
            <td class="text-right verde">${h.importadas}</td>
            <td class="text-right cinza">${h.duplicadas}</td>
            <td>${h.saldo_final_ofx != null ? _moeda(h.saldo_final_ofx) : '—'}</td>
            <td>${_esc(h.importado_por || '—')}</td>
        </tr>
    `).join('');
}

// =====================================================
// RELATÓRIOS
// =====================================================

async function gerarRelatorio() {
    if (!S.contaAtual) return;
    const dtIni = _val('rel-dt-ini') || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0,10);
    const dtFim = _val('rel-dt-fim') || new Date().toISOString().slice(0,10);
    const params = new URLSearchParams({ acao: 'relatorio_extrato', conta_id: S.contaAtual.id, dt_ini: dtIni, dt_fim: dtFim });
    const d = await _get_params(params);
    if (!d.sucesso) { _toast(d.mensagem, 'erro'); return; }
    S.relDados = d.dados;
    _renderRelatorio(d.dados);
}

function _renderRelatorio(dados) {
    _el('cb-rel-vazio').style.display = 'none';
    _el('cb-rel-resumo').style.display = 'flex';
    _el('cb-rel-tabela-wrap').style.display = 'block';
    _el('rel-total-c').textContent = _moeda(dados.total_creditos);
    _el('rel-total-d').textContent = _moeda(dados.total_debitos);
    const saldo = dados.saldo_periodo;
    const el = _el('rel-saldo-p');
    el.textContent = _moeda(Math.abs(saldo));
    el.className = saldo >= 0 ? 'verde' : 'vermelho';

    const tbody = _el('cb-tbody-rel');
    if (!dados.movimentacoes.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="cb-vazio">Nenhuma movimentação no período</td></tr>';
        return;
    }
    tbody.innerHTML = dados.movimentacoes.map(m => `
        <tr>
            <td>${_formatarData(m.data_lancamento)}</td>
            <td>${_esc(m.descricao)}</td>
            <td>${m.checknum || '—'}</td>
            <td><span class="cb-badge ${m.tipo}">${m.tipo === 'credito' ? '▼ Crédito' : '▲ Débito'}</span></td>
            <td class="text-right ${m.tipo}">${m.tipo==='credito'?'+':'-'} ${_moeda(m.valor)}</td>
            <td><span class="cb-badge ${m.conciliado?'conciliado':'pendente'}">${m.conciliado?'✓':'●'}</span></td>
        </tr>
    `).join('');
}

function exportarRelatorioCSV() {
    if (!S.relDados) { _toast('Gere o relatório primeiro', 'aviso'); return; }
    const linhas = [['Data','Descrição','Documento','Tipo','Valor','Conciliado']];
    S.relDados.movimentacoes.forEach(m => linhas.push([
        m.data_lancamento, m.descricao, m.checknum||'', m.tipo,
        (m.tipo==='debito'?'-':'')+m.valor.toFixed(2).replace('.',','),
        m.conciliado?'Sim':'Não'
    ]));
    _downloadCSV(linhas, `extrato_${S.contaAtual?.nome||'conta'}_${Date.now()}.csv`);
}

function imprimirRelatorio() {
    if (!S.relDados) { _toast('Gere o relatório primeiro', 'aviso'); return; }
    const dtIni = _val('rel-dt-ini');
    const dtFim = _val('rel-dt-fim');
    const url = `/api/api_contas_bancarias.php?acao=relatorio_extrato&conta_id=${S.contaAtual.id}&dt_ini=${dtIni}&dt_fim=${dtFim}&formato=html`;
    window.open(url, '_blank');
}

// =====================================================
// ABA CONCILIAÇÃO
// =====================================================

async function _carregarPendentes(offset = 0) {
    if (!S.contaAtual) return;
    S.concOffset = offset;
    const tbody = _el('cb-tbody-conc');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="cb-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    const p = new URLSearchParams({
        acao:       'pendentes_conciliacao',
        conta_id:   S.contaAtual.id,
        tipo:       _val('conc-filtro-tipo'),
        dt_ini:     _val('conc-filtro-dt-ini'),
        dt_fim:     _val('conc-filtro-dt-fim'),
        busca:      _val('conc-filtro-busca'),
        limite:     S.concLimite,
        offset,
    });

    try {
        const d = await _get_params(p);
        if (!d.sucesso) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="cb-erro">${d.mensagem}</td></tr>`;
            return;
        }
        S.concPendentes = d.dados.movimentacoes;
        S.concTotal     = d.dados.total;

        const badge = _el('conc-total-badge');
        if (badge) badge.textContent = `${S.concTotal} pendente${S.concTotal !== 1 ? 's' : ''}`;

        const tabBadge = _el('cb-badge-conc');
        if (tabBadge) {
            tabBadge.textContent = S.concTotal || '';
            tabBadge.style.display = S.concTotal > 0 ? 'inline-flex' : 'none';
        }

        if (!S.concPendentes.length) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="cb-vazio">Nenhuma movimentação pendente de conciliação</td></tr>';
            const pag = _el('cb-pag-conc'); if (pag) pag.innerHTML = '';
            return;
        }

        if (tbody) {
            tbody.innerHTML = S.concPendentes.map(m => {
                const cands = (parseInt(m.cand_receber || 0) + parseInt(m.cand_pagar || 0));
                const candBadge = cands > 0
                    ? `<span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11.5px;font-weight:600">${cands}</span>`
                    : `<span style="color:#94a3b8;font-size:12px">—</span>`;
                return `<tr>
                    <td style="white-space:nowrap;font-size:12px">${_formatarData(m.data_lancamento)}</td>
                    <td>
                        <div style="font-size:13px">${_esc(m.descricao)}</div>
                        ${m.favorecido ? `<div style="font-size:11.5px;color:#64748b">${_esc(m.favorecido)}</div>` : ''}
                    </td>
                    <td style="font-size:12px">${_esc(m.numero_documento || m.checknum || '—')}</td>
                    <td><span class="cb-badge ${m.tipo}">${m.tipo === 'credito' ? '▼' : '▲'}</span></td>
                    <td class="text-right ${m.tipo}">${m.tipo === 'credito' ? '+' : '-'} ${_moeda(m.valor)}</td>
                    <td style="text-align:center">${candBadge}</td>
                    <td class="cb-acoes-cell">
                        <button class="cb-btn-icon" title="Ver candidatos"
                                onclick="ContasBancarias.abrirModalCandidatos(${m.id})">
                            <i class="fas fa-link"></i>
                        </button>
                        <button class="cb-btn-icon cinza" title="Ignorar"
                                onclick="ContasBancarias.ignorarMovimentacao(${m.id})">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Paginação
        const pag = _el('cb-pag-conc');
        if (pag) {
            const pages = Math.ceil(S.concTotal / S.concLimite);
            const cur   = Math.floor(offset / S.concLimite) + 1;
            if (pages <= 1) { pag.innerHTML = ''; return; }
            let h = '';
            if (cur > 1) h += `<button onclick="ContasBancarias._carregarPendentes(${(cur-2)*S.concLimite})">‹</button>`;
            for (let i = Math.max(1, cur-2); i <= Math.min(pages, cur+2); i++) {
                h += `<button class="${i===cur?'ativa':''}" onclick="ContasBancarias._carregarPendentes(${(i-1)*S.concLimite})">${i}</button>`;
            }
            if (cur < pages) h += `<button onclick="ContasBancarias._carregarPendentes(${cur*S.concLimite})">›</button>`;
            pag.innerHTML = h;
        }
    } catch(e) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="cb-erro">Erro ao carregar pendências</td></tr>';
    }
}

function filtrarConciliacao() {
    clearTimeout(window._cbConcTimer);
    window._cbConcTimer = setTimeout(() => _carregarPendentes(0), 400);
}

async function abrirModalCandidatos(movId) {
    S.concMovId = movId;
    const modal = _el('modal-conc-candidatos');
    const body  = _el('modal-conc-body');
    if (!modal || !body) return;
    modal.style.display = 'flex';
    body.innerHTML = '<div style="text-align:center;padding:24px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Buscando candidatos...</div>';

    try {
        const d = await _get_params(new URLSearchParams({ acao: 'candidatos_conciliacao', mov_id: movId }));
        if (!d.sucesso) { body.innerHTML = `<p style="color:#ef4444;padding:16px">${_esc(d.mensagem)}</p>`; return; }

        const { movimentacao, receber, pagar } = d.dados;
        let html = `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
            <strong>${_esc(movimentacao.descricao)}</strong>
            <span style="float:right;font-weight:700;color:${movimentacao.tipo==='credito'?'#15803d':'#dc2626'}">${movimentacao.tipo==='credito'?'+':'-'}${_moeda(movimentacao.valor)}</span>
            <div style="color:#64748b;font-size:11.5px;margin-top:2px">${_formatarData(movimentacao.data_lancamento)}${movimentacao.favorecido ? ' · ' + _esc(movimentacao.favorecido) : ''}</div>
        </div>`;

        const mkCand = (arr, tipo) => arr.length ? arr.map(c => `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;background:#fff;">
                <div style="flex:1">
                    <div style="font-size:13px;font-weight:500">${_esc(c.descricao || c.numero_documento || '—')}</div>
                    <div style="font-size:11.5px;color:#64748b">${_formatarData(c.data_vencimento)} · ${_esc(c.favorecido || c.morador_nome || '—')}</div>
                    ${c.score ? `<span style="background:#dbeafe;color:#1d4ed8;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600">Score ${c.score}</span>` : ''}
                </div>
                <div style="white-space:nowrap;font-weight:700;color:#1e293b;font-size:14px">${_moeda(c.valor_original || c.valor)}</div>
                <button class="btn-sm-verde" onclick="ContasBancarias.vincularConciliacao(${movId},'${tipo}',${c.id})">
                    <i class="fas fa-link"></i> Vincular
                </button>
            </div>`).join('')
            : `<p style="color:#94a3b8;font-size:13px;padding:8px 0">Nenhum candidato encontrado</p>`;

        if (receber.length) html += `<h4 style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin:16px 0 8px">Contas a Receber (${receber.length})</h4>${mkCand(receber,'receber')}`;
        if (pagar.length)   html += `<h4 style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin:16px 0 8px">Contas a Pagar (${pagar.length})</h4>${mkCand(pagar,'pagar')}`;
        if (!receber.length && !pagar.length) html += '<p style="text-align:center;color:#94a3b8;padding:24px">Nenhum candidato encontrado no período ±30 dias e tolerância ±5% de valor</p>';

        body.innerHTML = html;
    } catch(e) {
        body.innerHTML = '<p style="color:#ef4444;padding:16px">Erro ao buscar candidatos</p>';
    }
}

function fecharModalCandidatos() {
    const modal = _el('modal-conc-candidatos');
    if (modal) modal.style.display = 'none';
    S.concMovId = null;
}

async function vincularConciliacao(movId, tipoTitulo, tituloId) {
    const d = await _post({ acao: 'conciliar_manual', mov_id: movId, tipo_titulo: tipoTitulo, titulo_id: tituloId });
    if (d.sucesso) {
        _toast('Conciliação realizada com sucesso', 'sucesso');
        fecharModalCandidatos();
        _carregarPendentes(S.concOffset);
        _carregarKPIs();
    } else {
        _toast(d.mensagem, 'erro');
    }
}

async function ignorarMovimentacao(movId) {
    if (!confirm('Ignorar esta movimentação? Ela não aparecerá mais como pendente.')) return;
    const d = await _post({ acao: 'atualizar_movimentacao', id: movId, status: 'ignorado' });
    if (d.sucesso) {
        _toast('Movimentação ignorada', 'sucesso');
        _carregarPendentes(S.concOffset);
        _carregarKPIs();
    } else {
        _toast(d.mensagem, 'erro');
    }
}

// =====================================================
// HELPERS
// =====================================================

function _definirDatasPadrao() {
    const hoje = new Date();
    const ini  = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    const fmt  = d => d.toISOString().slice(0,10);
    const el1 = _el('rel-dt-ini'); if(el1) el1.value = fmt(ini);
    const el2 = _el('rel-dt-fim'); if(el2) el2.value = fmt(hoje);
}

function _el(id) { return document.getElementById(id); }
function _val(id) { const e = _el(id); return e ? e.value.trim() : ''; }
function _esc(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function _moeda(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
}

function _formatarData(d) {
    if (!d) return '—';
    const [y,m,dia] = d.split('-');
    return `${dia}/${m}/${y}`;
}

function _formatarDataHora(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return dt.toLocaleString('pt-BR');
}

function _labelTipo(tipo) {
    const m = { corrente:'Corrente', poupanca:'Poupança', investimento:'Investimento', caixa:'Caixa' };
    return m[tipo] || tipo;
}

async function _get(acao) {
    const resp = await fetch(`${API}?acao=${acao}`, { credentials: 'include' });
    const text = await resp.text();
    try { return JSON.parse(text); }
    catch(e) { return { sucesso: false, mensagem: `Erro ${resp.status}: resposta inválida do servidor` }; }
}

async function _get_params(params) {
    const resp = await fetch(`${API}?${params.toString()}`, { credentials: 'include' });
    const text = await resp.text();
    try { return JSON.parse(text); }
    catch(e) { return { sucesso: false, mensagem: `Erro ${resp.status}: resposta inválida` }; }
}

async function _post(body) {
    console.log('[CB] POST →', JSON.stringify(body));
    const resp = await fetch(API, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    const text = await resp.text();
    console.log(`[CB] POST ← HTTP ${resp.status} | URL: ${API} | Body enviado: ${JSON.stringify(body)} | Resposta: ${text.substring(0, 800)}`);
    try {
        const parsed = JSON.parse(text);
        if (!parsed.sucesso) {
            console.warn('[CB] Falha na ação:', body.acao, '| Mensagem:', parsed.mensagem, '| HTTP:', resp.status);
        }
        return parsed;
    } catch(e) {
        console.error('[CB] Resposta não-JSON (HTTP ' + resp.status + '):', text.substring(0, 800));
        return { sucesso: false, mensagem: `Erro ${resp.status}: ${text.substring(0, 300)}` };
    }
}

function _toast(msg, tipo = 'info') {
    const cores = { sucesso: '#22c55e', erro: '#ef4444', aviso: '#f59e0b', info: '#3b82f6' };
    const t = document.createElement('div');
    t.className = 'cb-toast';
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;background:${cores[tipo]||cores.info};color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:360px;animation:cbToastIn .3s ease`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function _downloadCSV(linhas, nome) {
    const bom = '\uFEFF';
    const csv = bom + linhas.map(l => l.map(c => `"${String(c).replace(/"/g,'""')}"`).join(';')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = nome;
    a.click();
}
