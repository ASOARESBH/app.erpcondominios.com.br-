/**
 * Módulo: Contas a Receber
 * Padrão SPA — layout-base.html?page=contas_receber
 * @version 1.1.0 — corrigido para usar ?acao= e FormData (padrão da API PHP)
 */
'use strict';

const API        = '../api/api_contas_receber.php';
const API_PLANOS = '../api/api_planos_contas.php';

let _state = {
    lista: [],
    editandoId: null,
    carregando: false
};

// ─── Ciclo de Vida ───────────────────────────────────────────────────────────
export function init() {
    console.log('[ContasReceber] Init v1.1');
    _bindForm();
    _bindRecebimento();
    _carregarPlanos();
    _carregar();
    // Expor globalmente para uso nos botões inline do HTML
    window.ContasReceber = {
        carregar: _carregar,
        filtrar,
        editar,
        excluir,
        limparForm,
        abrirModalRecebimento,
        fecharModal
    };
}

export function destroy() {
    console.log('[ContasReceber] Destroy');
    _state = { lista: [], editandoId: null, carregando: false };
    delete window.ContasReceber;
}

// ─── Bind de eventos ─────────────────────────────────────────────────────────
function _bindForm() {
    const form = document.getElementById('cr_formCadastro');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); _salvar(); });
}

function _bindRecebimento() {
    const form = document.getElementById('cr_formRecebimento');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); _registrarRecebimento(); });
}

// ─── Carregar Planos de Contas ────────────────────────────────────────────────
async function _carregarPlanos() {
    try {
        const r = await fetch(`${API_PLANOS}?acao=listar`);
        const d = await r.json();
        const sel = document.getElementById('cr_selectPlano');
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
        console.error('[ContasReceber] Erro ao carregar planos:', err);
    }
}

// ─── Carregar Contas ──────────────────────────────────────────────────────────
async function _carregar() {
    if (_state.carregando) return;
    _state.carregando = true;

    const tbody = document.getElementById('cr_corpoTabela');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-table"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    try {
        const filtro = document.getElementById('cr_filtroStatus');
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
        console.error('[ContasReceber] Erro ao carregar:', err);
        _mostrarAlerta('Erro ao carregar contas a receber.', 'danger');
    } finally {
        _state.carregando = false;
    }
}

function filtrar() { _carregar(); }

// ─── Renderizar Tabela ────────────────────────────────────────────────────────
function _renderTabela() {
    const tbody = document.getElementById('cr_corpoTabela');
    if (!tbody) return;

    if (!_state.lista.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-table"><i class="fas fa-inbox"></i> Nenhuma conta encontrada</td></tr>';
        return;
    }

    tbody.innerHTML = _state.lista.map(c => {
        const badge   = _badgeStatus(c.status);
        const venc    = _formatarData(c.data_vencimento);
        const atrasada = c.status === 'PENDENTE' && new Date(c.data_vencimento) < new Date()
            ? ' style="color:#dc2626;font-weight:600;"' : '';
        return `
        <tr>
            <td>${_esc(c.numero_documento || '-')}</td>
            <td>${_esc(c.morador_nome || '-')}</td>
            <td>${_esc(c.unidade_numero || '-')}</td>
            <td${atrasada}>${_formatarMoeda(c.valor_original)}</td>
            <td${atrasada}>${venc}</td>
            <td>${badge}</td>
            <td class="acoes-cell">
                ${c.status === 'PENDENTE' || c.status === 'PARCIAL' ? `
                <button class="btn btn-xs btn-success" title="Registrar Recebimento"
                    onclick="ContasReceber.abrirModalRecebimento(${c.id})">
                    <i class="fas fa-hand-holding-usd"></i>
                </button>` : ''}
                <button class="btn btn-xs btn-outline" title="Editar"
                    onclick="ContasReceber.editar(${c.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-xs btn-danger" title="Excluir"
                    onclick="ContasReceber.excluir(${c.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

// ─── Calcular KPIs ────────────────────────────────────────────────────────────
function _calcularKPIs() {
    let aReceber = 0, recebido = 0, atrasadas = 0;
    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);

    _state.lista.forEach(c => {
        const val = parseFloat(c.valor_original) || 0;
        if (c.status === 'RECEBIDO') {
            recebido += val;
        } else if (c.status === 'PENDENTE' || c.status === 'PARCIAL') {
            aReceber += val;
            if (new Date(c.data_vencimento) < hoje) atrasadas++;
        }
    });

    _setEl('cr_totalReceber',    _formatarMoeda(aReceber));
    _setEl('cr_totalRecebido',   _formatarMoeda(recebido));
    _setEl('cr_contasAtrasadas', atrasadas);

    // Barra de alerta de contas atrasadas
    const alertBar = document.getElementById('cr_alertBar');
    if (alertBar) alertBar.style.display = atrasadas > 0 ? 'flex' : 'none';
}

// ─── Salvar Conta ─────────────────────────────────────────────────────────────
async function _salvar() {
    const form = document.getElementById('cr_formCadastro');
    if (!form) return;

    const btn = document.getElementById('cr_btnSalvar');
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
        console.error('[ContasReceber] Erro ao salvar:', err);
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
    const form = document.getElementById('cr_formCadastro');
    if (!form) return;

    Object.keys(conta).forEach(k => {
        const el = form.elements[k];
        if (el) el.value = conta[k] || '';
    });

    const btn = document.getElementById('cr_btnSalvar');
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
        console.error('[ContasReceber] Erro ao excluir:', err);
        _mostrarAlerta('Erro de conexão ao excluir.', 'danger');
    }
}

// ─── Modal de Recebimento ─────────────────────────────────────────────────────
function abrirModalRecebimento(id) {
    const modal = document.getElementById('cr_modalRecebimento');
    if (!modal) return;

    const formRec = document.getElementById('cr_formRecebimento');
    if (formRec) {
        formRec.reset();
        const dataRec = formRec.elements['data_recebimento'];
        if (dataRec) dataRec.value = new Date().toISOString().split('T')[0];
    }
    document.getElementById('cr_receberId').value = id;
    modal.style.display = 'flex';
}

function fecharModal() {
    const modal = document.getElementById('cr_modalRecebimento');
    if (modal) modal.style.display = 'none';
}

async function _registrarRecebimento() {
    const form = document.getElementById('cr_formRecebimento');
    if (!form) return;

    const fd = new FormData(form);
    fd.append('acao', 'receber');
    // Garantir que o id está no FormData (vem do hidden cr_receberId)
    if (!fd.get('id')) {
        fd.append('id', document.getElementById('cr_receberId').value);
    }

    try {
        const r = await fetch(API, { method: 'POST', body: fd });
        const d = await r.json();

        if (d.sucesso) {
            fecharModal();
            _mostrarAlerta('Recebimento registrado com sucesso!', 'success');
            _carregar();
        } else {
            _mostrarAlerta(d.mensagem || 'Erro ao registrar recebimento.', 'danger');
        }
    } catch (err) {
        console.error('[ContasReceber] Erro ao registrar recebimento:', err);
        _mostrarAlerta('Erro de conexão ao registrar recebimento.', 'danger');
    }
}

// ─── Limpar Formulário ────────────────────────────────────────────────────────
function limparForm() {
    const form = document.getElementById('cr_formCadastro');
    if (form) form.reset();
    _state.editandoId = null;
    const btn = document.getElementById('cr_btnSalvar');
    if (btn) btn.innerHTML = '<i class="fas fa-save"></i> Cadastrar Conta';
}

// ─── Utilitários ──────────────────────────────────────────────────────────────
function _badgeStatus(status) {
    const mapa = {
        'PENDENTE':  ['badge-pendente',  'Pendente'],
        'RECEBIDO':  ['badge-recebido',  'Recebido'],
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
    // Usar toast flutuante (sem cr_alertContainer que foi removido do HTML)
    const toast = document.createElement('div');
    toast.className = `alert alert-${tipo}`;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;min-width:280px;max-width:400px;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}
