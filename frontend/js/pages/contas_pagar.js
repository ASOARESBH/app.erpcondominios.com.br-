/**
 * contas_pagar.js — Módulo de Contas a Pagar
 * Padrão SPA — layout-base.html?page=contas_pagar
 * @version 1.1.0 — corrigido para usar ?acao= e FormData (padrão da API PHP)
 */
'use strict';

// ─── Estado do módulo ────────────────────────────────────────────────────────
const _state = {
    lista: [],
    editandoId: null,
    carregando: false
};

// ─── Constantes ───────────────────────────────────────────────────────────────
const API        = '../api/api_contas_pagar.php';
const API_PLANOS = '../api/api_planos_contas.php';

// ─── Ciclo de Vida ────────────────────────────────────────────────────────────
export function init() {
    console.log('[ContasPagar] Init v1.1');
    _bindForm();
    _bindPagamento();
    _carregarPlanos();
    _carregar();
    // Expor globalmente para uso nos botões inline do HTML
    window.ContasPagar = {
        carregar: _carregar,
        filtrar,
        editar,
        excluir,
        limparForm,
        abrirModalPagamento,
        fecharModal
    };
    console.log('[ContasPagar] Módulo pronto.');
}

export function destroy() {
    console.log('[ContasPagar] Destroy');
    Object.assign(_state, { lista: [], editandoId: null, carregando: false });
    delete window.ContasPagar;
}

// ─── Bind de eventos ─────────────────────────────────────────────────────────
function _bindForm() {
    const form = document.getElementById('cp_formCadastro');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); _salvar(); });
}

function _bindPagamento() {
    const form = document.getElementById('cp_formPagamento');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); _registrarPagamento(); });
}

// ─── Carregar Planos de Contas ────────────────────────────────────────────────
async function _carregarPlanos() {
    try {
        const r = await fetch(`${API_PLANOS}?acao=listar`);
        const d = await r.json();
        const sel = document.getElementById('cp_selectPlano');
        if (!sel) return;
        sel.innerHTML = '<option value="">Selecione um plano...</option>';
        const lista = Array.isArray(d.dados) ? d.dados : [];
        lista.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.codigo ? p.codigo + ' — ' : ''}${p.nome || p.descricao || p.name}`;
            sel.appendChild(opt);
        });
    } catch (err) {
        console.error('[ContasPagar] Erro ao carregar planos:', err);
    }
}

// ─── Carregar Contas ──────────────────────────────────────────────────────────
async function _carregar() {
    if (_state.carregando) return;
    _state.carregando = true;

    const tbody = document.getElementById('cp_corpoTabela');
    if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="empty-table"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    try {
        const filtro = document.getElementById('cp_filtroStatus');
        const status = filtro ? filtro.value : '';
        const url = status
            ? `${API}?acao=listar&status=${encodeURIComponent(status)}`
            : `${API}?acao=listar`;

        const r = await fetch(url);
        const d = await r.json();

        _state.lista = Array.isArray(d.dados) ? d.dados : [];
        _renderTabela();
        _calcularKPIs();
    } catch (err) {
        console.error('[ContasPagar] Erro ao carregar:', err);
        _mostrarAlerta('Erro ao carregar contas a pagar.', 'danger');
    } finally {
        _state.carregando = false;
    }
}

function filtrar() { _carregar(); }

// ─── Renderizar Tabela ────────────────────────────────────────────────────────
function _renderTabela() {
    const tbody = document.getElementById('cp_corpoTabela');
    if (!tbody) return;

    if (!_state.lista.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="empty-table"><i class="fas fa-inbox"></i> Nenhuma conta encontrada</td></tr>';
        return;
    }

    tbody.innerHTML = _state.lista.map(c => {
        const badge    = _badgeStatus(c.status);
        const venc     = _formatarData(c.data_vencimento);
        const atrasada = c.status === 'PENDENTE' && new Date(c.data_vencimento) < new Date()
            ? ' style="color:#dc2626;font-weight:600;"' : '';
        return `
        <tr>
            <td>${_esc(c.numero_documento || '-')}</td>
            <td>${_esc(c.fornecedor_nome || '-')}</td>
            <td>${_esc(c.classificacao_despesa || '-')}</td>
            <td>${_esc(c.grupo || '-')}</td>
            <td>${_esc(c.descricao || '-')}</td>
            <td${atrasada}>${_formatarMoeda(c.valor_original)}</td>
            <td${atrasada}>${venc}</td>
            <td>${badge}</td>
            <td class="acoes-cell">
                ${c.status === 'PENDENTE' || c.status === 'PARCIAL' ? `
                <button class="btn btn-xs btn-success" title="Registrar Pagamento"
                    onclick="ContasPagar.abrirModalPagamento(${c.id})">
                    <i class="fas fa-money-bill-wave"></i>
                </button>` : ''}
                <button class="btn btn-xs btn-outline" title="Editar"
                    onclick="ContasPagar.editar(${c.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-xs btn-danger" title="Excluir"
                    onclick="ContasPagar.excluir(${c.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

// ─── Calcular KPIs ────────────────────────────────────────────────────────────
function _calcularKPIs() {
    let pendente = 0, pagoTotal = 0, pagoMes = 0, atrasadas = 0;
    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);
    const anoAtual = hoje.getFullYear();
    const mesAtual = hoje.getMonth(); // 0-indexed

    _state.lista.forEach(c => {
        const val = parseFloat(c.valor_original) || 0;
        if (c.status === 'PAGO') {
            pagoTotal += val;
            // Verificar se o pagamento foi no mês atual
            if (c.data_pagamento) {
                // data_pagamento pode vir como 'YYYY-MM-DD'
                const dp = new Date(c.data_pagamento + 'T00:00:00');
                if (dp.getFullYear() === anoAtual && dp.getMonth() === mesAtual) {
                    pagoMes += val;
                }
            }
        } else if (c.status === 'PENDENTE' || c.status === 'PARCIAL') {
            pendente += val;
            if (new Date(c.data_vencimento + 'T00:00:00') < hoje) atrasadas++;
        }
    });

    _setEl('cp_totalPendente',   _formatarMoeda(pendente));
    // Mostrar pago do mês no KPI principal; pago total como subtexto
    _setEl('cp_totalPago',       _formatarMoeda(pagoMes));
    // Subtexto com total geral pago (se existir o elemento)
    const elSubPago = document.getElementById('cp_subPagoTotal');
    if (elSubPago) elSubPago.textContent = 'Total geral: ' + _formatarMoeda(pagoTotal);
    _setEl('cp_contasAtrasadas', atrasadas);

    // Barra de alerta de contas atrasadas
    const alertBar = document.getElementById('cp_alertBar');
    if (alertBar) alertBar.style.display = atrasadas > 0 ? 'flex' : 'none';
}

// ─── Salvar Conta ─────────────────────────────────────────────────────────────
async function _salvar() {
    const form = document.getElementById('cp_formCadastro');
    if (!form) return;

    const btn = document.getElementById('cp_btnSalvar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    // A API usa $_POST (não JSON), então enviamos FormData com acao
    const fd = new FormData(form);
    const acao = _state.editandoId ? 'atualizar' : 'cadastrar';
    fd.append('acao', acao);
    if (_state.editandoId) fd.append('id', _state.editandoId);

    try {
        const r = await fetch(API, { method: 'POST', body: fd });
        const d = await r.json();

        if (d.sucesso) {
            _mostrarAlerta(
                _state.editandoId ? 'Conta atualizada com sucesso!' : 'Conta cadastrada com sucesso!',
                'success'
            );
            limparForm();
            _carregar();
        } else {
            _mostrarAlerta(d.mensagem || 'Erro ao salvar conta.', 'danger');
        }
    } catch (err) {
        console.error('[ContasPagar] Erro ao salvar:', err);
        _mostrarAlerta('Erro de conexão ao salvar.', 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Cadastrar Conta'; }
    }
}

// ─── Editar ───────────────────────────────────────────────────────────────────
function editar(id) {
    const conta = _state.lista.find(c => c.id == id);
    if (!conta) return;

    _state.editandoId = id;
    const form = document.getElementById('cp_formCadastro');
    if (!form) return;

    Object.keys(conta).forEach(k => {
        const el = form.elements[k];
        if (el) el.value = conta[k] || '';
    });

    const btn = document.getElementById('cp_btnSalvar');
    if (btn) btn.innerHTML = '<i class="fas fa-save"></i> Atualizar Conta';

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─── Excluir ──────────────────────────────────────────────────────────────────
async function excluir(id) {
    if (!confirm('Deseja realmente excluir esta conta?')) return;

    const fd = new FormData();
    fd.append('acao', 'deletar');
    fd.append('id', id);

    try {
        const r = await fetch(API, { method: 'POST', body: fd });
        const d = await r.json();

        if (d.sucesso) {
            _mostrarAlerta('Conta excluída com sucesso!', 'success');
            _carregar();
        } else {
            _mostrarAlerta(d.mensagem || 'Erro ao excluir.', 'danger');
        }
    } catch (err) {
        console.error('[ContasPagar] Erro ao excluir:', err);
        _mostrarAlerta('Erro de conexão ao excluir.', 'danger');
    }
}

// ─── Modal de Pagamento ───────────────────────────────────────────────────────
function abrirModalPagamento(id) {
    const modal = document.getElementById('cp_modalPagamento');
    if (!modal) return;

    const formPag = document.getElementById('cp_formPagamento');
    if (formPag) {
        formPag.reset();
        const dataPag = formPag.elements['data_pagamento'];
        if (dataPag) dataPag.value = new Date().toISOString().split('T')[0];
    }
    document.getElementById('cp_pagarId').value = id;
    modal.style.display = 'flex';
}

function fecharModal() {
    const modal = document.getElementById('cp_modalPagamento');
    if (modal) modal.style.display = 'none';
}

async function _registrarPagamento() {
    const form = document.getElementById('cp_formPagamento');
    if (!form) return;

    const fd = new FormData(form);
    fd.append('acao', 'pagar');
    // Garantir que o id está no FormData (vem do hidden cp_pagarId)
    if (!fd.get('id')) {
        fd.append('id', document.getElementById('cp_pagarId').value);
    }

    try {
        const r = await fetch(API, { method: 'POST', body: fd });
        const d = await r.json();

        if (d.sucesso) {
            fecharModal();
            _mostrarAlerta('Pagamento registrado com sucesso!', 'success');
            _carregar();
        } else {
            _mostrarAlerta(d.mensagem || 'Erro ao registrar pagamento.', 'danger');
        }
    } catch (err) {
        console.error('[ContasPagar] Erro ao registrar pagamento:', err);
        _mostrarAlerta('Erro de conexão ao registrar pagamento.', 'danger');
    }
}

// ─── Limpar Formulário ────────────────────────────────────────────────────────
function limparForm() {
    const form = document.getElementById('cp_formCadastro');
    if (form) form.reset();
    _state.editandoId = null;
    const btn = document.getElementById('cp_btnSalvar');
    if (btn) btn.innerHTML = '<i class="fas fa-save"></i> Cadastrar Conta';
}

// ─── Utilitários ──────────────────────────────────────────────────────────────
function _badgeStatus(status) {
    const mapa = {
        'PENDENTE':  ['badge-pendente',  'Pendente'],
        'PAGO':      ['badge-pago',      'Pago'],
        'PARCIAL':   ['badge-parcial',   'Parcial'],
        'CANCELADO': ['badge-cancelado', 'Cancelado']
    };
    const [cls, label] = mapa[status] || ['badge-pendente', status || '-'];
    return `<span class="status-badge ${cls}">${label}</span>`;
}

function _formatarMoeda(v) {
    const n = parseFloat(v) || 0;
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function _formatarData(d) {
    if (!d) return '-';
    const [y, m, dia] = d.split('T')[0].split('-');
    return `${dia}/${m}/${y}`;
}

function _esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function _setEl(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function _mostrarAlerta(msg, tipo) {
    // Usar toast flutuante (sem cp_alertContainer que foi removido do HTML)
    const toast = document.createElement('div');
    toast.className = `alert alert-${tipo}`;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;min-width:280px;max-width:400px;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}
