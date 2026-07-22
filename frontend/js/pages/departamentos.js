/**
 * Módulo — Departamentos (Central)
 * Fonte única de departamentos para todo o sistema
 */

const API_DEPT = '../api/api_departamentos.php';

const state = {
    lista:          [],
    filtro:         '',
    mostrarInativos: false,
};

// ── Ciclo de vida AppRouter ────────────────────────────────────────────
export function init() {
    _bindEvents();
    carregarDepartamentos();

    window.deptEditar    = _abrirModalEditar;
    window.deptDesativar = _desativar;
    window.deptReativar  = _reativar;
}

export function destroy() {
    window.deptEditar    = null;
    window.deptDesativar = null;
    window.deptReativar  = null;
}

// ── Eventos ───────────────────────────────────────────────────────────
function _bindEvents() {
    document.getElementById('btnNovoDepartamento').addEventListener('click', _abrirModalNovo);
    document.getElementById('btnSalvarDept').addEventListener('click', _salvar);
    document.getElementById('btnFecharModalDept').addEventListener('click', _fecharModal);
    document.getElementById('btnCancelarDept').addEventListener('click', _fecharModal);
    document.getElementById('dept-modal').addEventListener('click', e => {
        if (e.target === e.currentTarget) _fecharModal();
    });
    document.getElementById('dept-busca').addEventListener('input', e => {
        state.filtro = e.target.value.toLowerCase();
        _renderLista();
    });
    document.getElementById('dept-mostrar-inativos').addEventListener('change', e => {
        state.mostrarInativos = e.target.checked;
        _renderLista();
    });
    document.getElementById('dept-nome').addEventListener('input', e => {
        e.target.value = e.target.value.toUpperCase();
    });
    document.addEventListener('keydown', _teclaEsc);
}

function _teclaEsc(e) {
    if (e.key === 'Escape') _fecharModal();
}

// ── Carregar / Render ─────────────────────────────────────────────────
async function carregarDepartamentos() {
    const el = document.getElementById('lista-departamentos');
    el.innerHTML = '<div class="dept-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    try {
        const res  = await fetch(`${API_DEPT}?acao=listar`, { credentials: 'include' });
        const json = await res.json();
        if (!json.sucesso) throw new Error(json.mensagem);
        state.lista = json.dados || [];
        _renderStats();
        _renderLista();
    } catch (err) {
        el.innerHTML = `<div class="dept-loading dept-erro"><i class="fas fa-exclamation-circle"></i> Erro ao carregar departamentos</div>`;
    }
}

function _renderStats() {
    const total   = state.lista.length;
    const ativos  = state.lista.filter(d => String(d.ativo) === '1').length;
    const inativos = total - ativos;
    const el = document.getElementById('dept-stats');
    el.innerHTML = `
        <div class="dept-stat-card">
            <div class="dept-stat-valor">${total}</div>
            <div class="dept-stat-label">Total</div>
        </div>
        <div class="dept-stat-card green">
            <div class="dept-stat-valor">${ativos}</div>
            <div class="dept-stat-label">Ativos</div>
        </div>
        <div class="dept-stat-card gray">
            <div class="dept-stat-valor">${inativos}</div>
            <div class="dept-stat-label">Inativos</div>
        </div>`;
}

function _renderLista() {
    const el = document.getElementById('lista-departamentos');
    let lista = state.lista;
    if (!state.mostrarInativos) lista = lista.filter(d => String(d.ativo) === '1');
    if (state.filtro) lista = lista.filter(d => d.nome.toLowerCase().includes(state.filtro));

    if (!lista.length) {
        const msg = state.filtro ? 'Nenhum departamento encontrado para este filtro' : 'Nenhum departamento cadastrado';
        el.innerHTML = `<div class="dept-loading"><i class="fas fa-inbox"></i><br>${msg}</div>`;
        return;
    }

    el.innerHTML = `<div class="dept-lista">` + lista.map(d => {
        const inativo = String(d.ativo) !== '1';
        return `
        <div class="dept-item${inativo ? ' dept-item-inativo' : ''}">
            <div class="dept-item-icon"><i class="fas fa-building"></i></div>
            <div class="dept-item-info">
                <div class="dept-item-nome">${d.nome}</div>
                ${d.descricao ? `<div class="dept-item-desc">${d.descricao}</div>` : ''}
            </div>
            <div>
                ${inativo
                    ? '<span class="dept-badge inativo">Inativo</span>'
                    : '<span class="dept-badge ativo">Ativo</span>'}
            </div>
            <div class="dept-item-acoes">
                <button class="dept-btn-acao editar" onclick="deptEditar(${d.id})" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                ${inativo
                    ? `<button class="dept-btn-acao reativar" onclick="deptReativar(${d.id}, ${JSON.stringify(d.nome)})" title="Reativar">
                           <i class="fas fa-toggle-off"></i>
                       </button>`
                    : `<button class="dept-btn-acao excluir" onclick="deptDesativar(${d.id}, ${JSON.stringify(d.nome)})" title="Desativar">
                           <i class="fas fa-toggle-on"></i>
                       </button>`
                }
            </div>
        </div>`;
    }).join('') + `</div>`;
}

// ── Modal ─────────────────────────────────────────────────────────────
function _abrirModalNovo() {
    document.getElementById('dept-id').value          = '';
    document.getElementById('dept-nome').value        = '';
    document.getElementById('dept-descricao').value   = '';
    document.getElementById('dept-ativo').checked     = true;
    document.getElementById('dept-modal-titulo').textContent = 'Novo Departamento';
    document.getElementById('dept-grupo-ativo').style.display = 'none';
    document.getElementById('dept-modal').style.display = 'flex';
    document.getElementById('dept-nome').focus();
}

function _abrirModalEditar(id) {
    const d = state.lista.find(x => String(x.id) === String(id));
    if (!d) return;
    document.getElementById('dept-id').value        = d.id;
    document.getElementById('dept-nome').value      = d.nome;
    document.getElementById('dept-descricao').value = d.descricao || '';
    document.getElementById('dept-ativo').checked   = String(d.ativo) === '1';
    document.getElementById('dept-modal-titulo').textContent = 'Editar Departamento';
    document.getElementById('dept-grupo-ativo').style.display = '';
    document.getElementById('dept-modal').style.display = 'flex';
    document.getElementById('dept-nome').focus();
}

function _fecharModal() {
    document.getElementById('dept-modal').style.display = 'none';
}

// ── CRUD ──────────────────────────────────────────────────────────────
async function _salvar() {
    const id        = document.getElementById('dept-id').value;
    const nome      = document.getElementById('dept-nome').value.trim().toUpperCase();
    const descricao = document.getElementById('dept-descricao').value.trim();
    const ativo     = document.getElementById('dept-ativo').checked ? 1 : 0;

    if (!nome) { _toast('Nome é obrigatório', 'aviso'); return; }

    const btn = document.getElementById('btnSalvarDept');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    const payload = id
        ? { acao: 'editar', id: parseInt(id), nome, descricao, ativo }
        : { acao: 'criar', nome, descricao };

    try {
        const res  = await fetch(API_DEPT, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (json.sucesso) {
            _toast(id ? 'Departamento atualizado' : 'Departamento criado', 'sucesso');
            _fecharModal();
            carregarDepartamentos();
        } else {
            _toast(json.mensagem || 'Erro ao salvar', 'erro');
        }
    } catch {
        _toast('Erro de comunicação com o servidor', 'erro');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Salvar';
    }
}

async function _desativar(id, nome) {
    if (!confirm(`Desativar o departamento "${nome}"?\n\nEle não aparecerá mais nos seletores, mas os registros existentes são mantidos.`)) return;
    const res  = await fetch(API_DEPT, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'excluir', id }),
    });
    const json = await res.json();
    if (json.sucesso) { _toast('Departamento desativado', 'sucesso'); carregarDepartamentos(); }
    else _toast(json.mensagem || 'Erro ao desativar', 'erro');
}

async function _reativar(id, nome) {
    const d = state.lista.find(x => String(x.id) === String(id));
    if (!d) return;
    const res  = await fetch(API_DEPT, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'editar', id: parseInt(id), nome: d.nome, descricao: d.descricao || '', ativo: 1 }),
    });
    const json = await res.json();
    if (json.sucesso) { _toast(`Departamento "${nome}" reativado`, 'sucesso'); carregarDepartamentos(); }
    else _toast(json.mensagem || 'Erro ao reativar', 'erro');
}

// ── Toast ──────────────────────────────────────────────────────────────
function _toast(msg, tipo = 'sucesso') {
    if (window.showToast) { window.showToast(msg, tipo); return; }
    const colors = { sucesso: '#16a34a', erro: '#dc2626', aviso: '#d97706' };
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = `position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;
        font-size:.875rem;font-weight:600;z-index:99999;color:#fff;
        background:${colors[tipo] || '#16a34a'};box-shadow:0 4px 16px rgba(0,0,0,.2);
        animation:fadeIn .2s ease`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
