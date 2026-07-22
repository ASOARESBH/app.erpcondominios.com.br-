/**
 * cadastro_fornecedor_admin.js — Módulo de Gestão de Fornecedores (Admin)
 * Padrão SPA — layout-base.html?page=cadastro_fornecedor_admin
 * @version 1.0.0
 *
 * Responsabilidades:
 *   - Listar, cadastrar, editar, aprovar/rejeitar e ativar/desativar fornecedores
 *   - Carregar ramos de atividade para o select
 *   - Exibir KPIs e barra de alerta de pendentes
 *
 * APIs utilizadas:
 *   - api_admin_fornecedores.php  → listar_todos, estatisticas, cadastrar, atualizar, alternar_status, alternar_aprovacao
 *   - api_ramos_atividade.php     → listar (dropdown público)
 */
'use strict';

// ─── Estado do módulo ────────────────────────────────────────────────────────
const _state = {
    lista: [],
    listaFiltrada: [],
    ramos: [],
    editandoId: null,
    confirmCallback: null
};

// ─── Helpers de URL ──────────────────────────────────────────────────────────
function _api(path) {
    return window.location.origin + '/api/' + path;
}

// ─── Ciclo de Vida ────────────────────────────────────────────────────────────
export function init() {
    console.log('[CadastroFornecedorAdmin] Init v1.0.0');
    _bindForm();
    _bindFiltros();
    _carregarRamos();
    _carregarEstatisticas();
    _carregarLista();
    window.CadastroFornecedorAdmin = {
        abrirModal,
        fecharModal,
        fecharConfirm,
        filtrar: _filtrar,
        limparFiltros: _limparFiltros,
        editar,
        excluir,
        alternarStatus,
        alternarAprovacao
    };
    console.log('[CadastroFornecedorAdmin] Módulo pronto.');
}

export function destroy() {
    console.log('[CadastroFornecedorAdmin] Destroy');
    Object.assign(_state, { lista: [], listaFiltrada: [], ramos: [], editandoId: null, confirmCallback: null });
    delete window.CadastroFornecedorAdmin;
}

// ─── Toast ────────────────────────────────────────────────────────────────────
function _toast(msg, tipo = 'success') {
    const el = document.getElementById('cfa-toast');
    if (!el) return;
    const cores = {
        success: { bg: '#d1fae5', color: '#065f46', border: '#10b981' },
        danger:  { bg: '#fee2e2', color: '#991b1b', border: '#ef4444' },
        warning: { bg: '#fef3c7', color: '#92400e', border: '#f59e0b' },
        info:    { bg: '#dbeafe', color: '#1e40af', border: '#3b82f6' }
    };
    const c = cores[tipo] || cores.info;
    el.style.cssText += `background:${c.bg};color:${c.color};border-left:4px solid ${c.border};display:block;`;
    el.innerHTML = `<i class="fas fa-${tipo === 'success' ? 'check-circle' : tipo === 'danger' ? 'exclamation-circle' : 'info-circle'}"></i> ${msg}`;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.style.display = 'none'; }, 4500);
}

// ─── Bind de eventos ─────────────────────────────────────────────────────────
function _bindForm() {
    const form = document.getElementById('cfa-form');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); _salvar(); });
}

function _bindFiltros() {
    const busca = document.getElementById('cfa-busca');
    if (busca) {
        busca.addEventListener('keyup', e => { if (e.key === 'Enter') _filtrar(); });
    }
}

// ─── Carregar Ramos ───────────────────────────────────────────────────────────
async function _carregarRamos() {
    try {
        const res = await fetch(_api('api_ramos_atividade.php?acao=listar'), { credentials: 'include' });
        const d = await res.json();
        const sel = document.getElementById('cfa-ramo_atividade_id');
        if (!sel) return;
        if (d.sucesso && Array.isArray(d.dados)) {
            _state.ramos = d.dados;
            sel.innerHTML = '<option value="">Selecione um ramo...</option>';
            d.dados.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.nome;
                sel.appendChild(opt);
            });
        } else {
            sel.innerHTML = '<option value="">Erro ao carregar ramos</option>';
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro ao carregar ramos:', err);
    }
}

// ─── Estatísticas (KPIs) ──────────────────────────────────────────────────────
async function _carregarEstatisticas() {
    try {
        const res = await fetch(_api('api_admin_fornecedores.php?acao=estatisticas'), { credentials: 'include' });
        const d = await res.json();
        if (d.sucesso && d.dados) {
            const s = d.dados;
            _setKpi('cfa-kpi-total',    s.total     ?? s.total_fornecedores     ?? '—');
            _setKpi('cfa-kpi-ativos',   s.ativos    ?? s.fornecedores_ativos    ?? '—');
            _setKpi('cfa-kpi-inativos', s.inativos  ?? s.fornecedores_inativos  ?? '—');
            _setKpi('cfa-kpi-pendentes', s.pendentes ?? s.aguardando_aprovacao ?? s.fornecedores_pendentes ?? '—');
            // Barra de alerta
            const pendentes = parseInt(s.pendentes ?? s.aguardando_aprovacao ?? s.fornecedores_pendentes ?? 0);
            const bar = document.getElementById('cfa-alert-bar');
            const msg = document.getElementById('cfa-alert-msg');
            if (bar && msg && pendentes > 0) {
                msg.textContent = ` ${pendentes} fornecedor(es) aguardando aprovação. Revise a lista abaixo.`;
                bar.style.display = 'block';
            }
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro ao carregar estatísticas:', err);
    }
}

function _setKpi(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

// ─── Carregar Lista ───────────────────────────────────────────────────────────
async function _carregarLista() {
    const container = document.getElementById('cfa-tabela-container');
    if (!container) return;
    container.innerHTML = '<div class="cfa-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    try {
        const res = await fetch(_api('api_admin_fornecedores.php?acao=listar_todos'), { credentials: 'include' });
        const d = await res.json();
        if (d.sucesso && Array.isArray(d.dados)) {
            _state.lista = d.dados;
            _state.listaFiltrada = [...d.dados];
            _renderTabela(_state.listaFiltrada);
        } else {
            container.innerHTML = `<div class="cfa-empty"><i class="fas fa-store-slash"></i><p>${d.mensagem || 'Nenhum fornecedor encontrado.'}</p></div>`;
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro ao carregar lista:', err);
        container.innerHTML = '<div class="cfa-empty"><i class="fas fa-exclamation-circle"></i><p>Erro ao carregar fornecedores.</p></div>';
    }
}

// ─── Renderizar Tabela ────────────────────────────────────────────────────────
function _renderTabela(lista) {
    const container = document.getElementById('cfa-tabela-container');
    if (!container) return;
    if (!lista || lista.length === 0) {
        container.innerHTML = '<div class="cfa-empty"><i class="fas fa-store-slash"></i><p>Nenhum fornecedor encontrado.</p></div>';
        return;
    }
    let html = `
    <div class="cfa-table-responsive">
    <table class="cfa-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Estabelecimento</th>
                <th>CPF/CNPJ</th>
                <th>Ramo</th>
                <th>Telefone</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Aprovação</th>
                <th>Cadastro</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>`;

    lista.forEach((f, i) => {
        const ativo    = parseInt(f.ativo)    === 1 || f.ativo    === true;
        const aprovado = parseInt(f.aprovado) === 1 || f.aprovado === true;
        const badgeStatus   = ativo    ? 'cfa-badge-green' : 'cfa-badge-red';
        const badgeAprov    = aprovado ? 'cfa-badge-blue'  : 'cfa-badge-yellow';
        const textoStatus   = ativo    ? 'Ativo'    : 'Inativo';
        const textoAprov    = aprovado ? 'Aprovado' : 'Pendente';
        const dataFormatada = f.data_cadastro_formatada || f.data_cadastro || '—';

        html += `
        <tr>
            <td>${i + 1}</td>
            <td>
                <strong>${_esc(f.nome_estabelecimento || f.nome || '—')}</strong>
                ${f.nome_responsavel ? `<br><small style="color:#64748b;">${_esc(f.nome_responsavel)}</small>` : ''}
            </td>
            <td>${_esc(f.cpf_cnpj || '—')}</td>
            <td>${_esc(f.ramo_nome || f.ramo || '—')}</td>
            <td>${_esc(f.telefone || '—')}</td>
            <td>${_esc(f.email || '—')}</td>
            <td><span class="cfa-badge ${badgeStatus}">${textoStatus}</span></td>
            <td><span class="cfa-badge ${badgeAprov}">${textoAprov}</span></td>
            <td style="font-size:12px;">${_esc(dataFormatada)}</td>
            <td>
                <div class="cfa-action-btns">
                    <button class="btn-icon btn-icon-blue" title="Editar"
                        onclick="window.CadastroFornecedorAdmin.editar(${f.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    ${!aprovado
                        ? `<button class="btn-icon btn-icon-green" title="Aprovar"
                            onclick="window.CadastroFornecedorAdmin.alternarAprovacao(${f.id}, 1)">
                            <i class="fas fa-check"></i>
                           </button>`
                        : `<button class="btn-icon btn-icon-orange" title="Revogar Aprovação"
                            onclick="window.CadastroFornecedorAdmin.alternarAprovacao(${f.id}, 0)">
                            <i class="fas fa-ban"></i>
                           </button>`
                    }
                    <button class="btn-icon ${ativo ? 'btn-icon-red' : 'btn-icon-green'}" title="${ativo ? 'Desativar' : 'Ativar'}"
                        onclick="window.CadastroFornecedorAdmin.alternarStatus(${f.id}, ${ativo ? 0 : 1})">
                        <i class="fas fa-${ativo ? 'toggle-on' : 'toggle-off'}"></i>
                    </button>
                    <button class="btn-icon btn-icon-red" title="Excluir"
                        onclick="window.CadastroFornecedorAdmin.excluir(${f.id}, '${_esc(f.nome_estabelecimento || f.nome || '')}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}

// ─── Filtrar ──────────────────────────────────────────────────────────────────
function _filtrar() {
    const busca   = (document.getElementById('cfa-busca')?.value || '').toLowerCase().trim();
    const status  = document.getElementById('cfa-filtro-status')?.value;
    const aprov   = document.getElementById('cfa-filtro-aprovacao')?.value;

    _state.listaFiltrada = _state.lista.filter(f => {
        const nome  = (f.nome_estabelecimento || f.nome || '').toLowerCase();
        const cpf   = (f.cpf_cnpj || '').toLowerCase();
        const email = (f.email || '').toLowerCase();
        const matchBusca  = !busca || nome.includes(busca) || cpf.includes(busca) || email.includes(busca);
        const matchStatus = status === '' || String(f.ativo) === status || (parseInt(f.ativo) === 1 ? '1' : '0') === status;
        const matchAprov  = aprov  === '' || String(f.aprovado) === aprov || (parseInt(f.aprovado) === 1 ? '1' : '0') === aprov;
        return matchBusca && matchStatus && matchAprov;
    });

    _renderTabela(_state.listaFiltrada);
}

function _limparFiltros() {
    const b = document.getElementById('cfa-busca');
    const s = document.getElementById('cfa-filtro-status');
    const a = document.getElementById('cfa-filtro-aprovacao');
    if (b) b.value = '';
    if (s) s.value = '';
    if (a) a.value = '';
    _state.listaFiltrada = [..._state.lista];
    _renderTabela(_state.listaFiltrada);
}

// ─── Modal Cadastro/Edição ────────────────────────────────────────────────────
function abrirModal(fornecedorData = null) {
    const modal  = document.getElementById('cfa-modal');
    const titulo = document.getElementById('cfa-modal-titulo');
    const form   = document.getElementById('cfa-form');
    if (!modal || !form) return;

    form.reset();
    _limparErros();

    if (fornecedorData) {
        // Modo edição
        _state.editandoId = fornecedorData.id;
        titulo.innerHTML  = '<i class="fas fa-edit"></i> Editar Fornecedor';
        document.getElementById('cfa-id').value                   = fornecedorData.id;
        document.getElementById('cfa-cpf_cnpj').value             = fornecedorData.cpf_cnpj || '';
        document.getElementById('cfa-ramo_atividade_id').value    = fornecedorData.ramo_atividade_id || '';
        document.getElementById('cfa-nome_estabelecimento').value = fornecedorData.nome_estabelecimento || fornecedorData.nome || '';
        document.getElementById('cfa-email').value                = fornecedorData.email || '';
        document.getElementById('cfa-telefone').value             = fornecedorData.telefone || '';
        document.getElementById('cfa-nome_responsavel').value     = fornecedorData.nome_responsavel || '';
        document.getElementById('cfa-endereco').value             = fornecedorData.endereco || '';
        document.getElementById('cfa-ativo').value                = String(parseInt(fornecedorData.ativo) === 1 ? '1' : '0');
        document.getElementById('cfa-aprovado').value             = String(parseInt(fornecedorData.aprovado) === 1 ? '1' : '0');
        // Mostrar campos de status/aprovação na edição
        document.getElementById('cfa-grupo-status').style.display = 'grid';
        // Senha opcional na edição
        document.getElementById('cfa-senha').required = false;
        document.getElementById('cfa-senha-req').style.display = 'none';
        document.getElementById('cfa-senha-edit-hint').style.display = 'block';
        document.getElementById('cfa-senha-hint').style.display = 'none';
    } else {
        // Modo cadastro
        _state.editandoId = null;
        titulo.innerHTML  = '<i class="fas fa-user-plus"></i> Novo Fornecedor';
        document.getElementById('cfa-id').value = '';
        document.getElementById('cfa-grupo-status').style.display = 'none';
        document.getElementById('cfa-senha').required = true;
        document.getElementById('cfa-senha-req').style.display = 'inline';
        document.getElementById('cfa-senha-edit-hint').style.display = 'none';
        document.getElementById('cfa-senha-hint').style.display = 'inline';
    }

    modal.style.display = 'flex';
}

function fecharModal() {
    const modal = document.getElementById('cfa-modal');
    if (modal) modal.style.display = 'none';
    _state.editandoId = null;
}

// ─── Buscar dados do fornecedor para edição ───────────────────────────────────
async function editar(id) {
    console.log('[CadastroFornecedorAdmin] Editando id:', id);
    // Buscar na lista local primeiro
    let forn = _state.lista.find(f => parseInt(f.id) === parseInt(id));
    if (!forn) {
        // Fallback: buscar na API
        try {
            const res = await fetch(_api(`api_admin_fornecedores.php?acao=buscar&id=${id}`), { credentials: 'include' });
            const d = await res.json();
            if (d.sucesso && d.dados) forn = d.dados;
        } catch (err) {
            console.error('[CadastroFornecedorAdmin] Erro ao buscar fornecedor:', err);
            _toast('Erro ao carregar dados do fornecedor.', 'danger');
            return;
        }
    }
    if (forn) {
        abrirModal(forn);
    } else {
        _toast('Fornecedor não encontrado.', 'danger');
    }
}

// ─── Salvar (Cadastrar ou Atualizar) ─────────────────────────────────────────
async function _salvar() {
    if (!_validar()) return;

    const btn = document.getElementById('cfa-btn-salvar');
    const txtOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    try {
        const fd = new FormData(document.getElementById('cfa-form'));

        if (_state.editandoId) {
            // Atualização
            fd.append('acao', 'atualizar');
            fd.set('id', _state.editandoId);
            // Se senha vazia na edição, remover do FormData
            if (!fd.get('senha') || fd.get('senha').trim() === '') {
                fd.delete('senha');
            }
        } else {
            // Cadastro novo
            fd.append('acao', 'cadastrar');
        }

        const res = await fetch(_api('api_admin_fornecedores.php'), {
            method: 'POST',
            body: fd,
            credentials: 'include'
        });

        const d = await res.json();
        console.log('[CadastroFornecedorAdmin] Resposta salvar:', d);

        if (d.sucesso) {
            _toast(d.mensagem || (_state.editandoId ? 'Fornecedor atualizado com sucesso!' : 'Fornecedor cadastrado com sucesso!'), 'success');
            fecharModal();
            await _carregarEstatisticas();
            await _carregarLista();
        } else {
            _toast(d.mensagem || 'Erro ao salvar fornecedor.', 'danger');
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro ao salvar:', err);
        _toast('Erro de comunicação com o servidor.', 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = txtOriginal;
    }
}

// ─── Alternar Status (Ativar/Desativar) ───────────────────────────────────────
async function alternarStatus(id, novoStatus) {
    try {
        const fd = new FormData();
        fd.append('acao', 'alternar_status');
        fd.append('id', id);
        const res = await fetch(_api('api_admin_fornecedores.php'), {
            method: 'POST', body: fd, credentials: 'include'
        });
        const d = await res.json();
        if (d.sucesso) {
            _toast(d.mensagem || 'Status atualizado.', 'success');
            await _carregarEstatisticas();
            await _carregarLista();
        } else {
            _toast(d.mensagem || 'Erro ao alterar status.', 'danger');
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro alternar status:', err);
        _toast('Erro de comunicação.', 'danger');
    }
}

// ─── Alternar Aprovação ───────────────────────────────────────────────────────
async function alternarAprovacao(id, novoValor) {
    try {
        const fd = new FormData();
        fd.append('acao', 'alternar_aprovacao');
        fd.append('id', id);
        const res = await fetch(_api('api_admin_fornecedores.php'), {
            method: 'POST', body: fd, credentials: 'include'
        });
        const d = await res.json();
        if (d.sucesso) {
            _toast(d.mensagem || 'Aprovação atualizada.', 'success');
            await _carregarEstatisticas();
            await _carregarLista();
        } else {
            _toast(d.mensagem || 'Erro ao alterar aprovação.', 'danger');
        }
    } catch (err) {
        console.error('[CadastroFornecedorAdmin] Erro alternar aprovação:', err);
        _toast('Erro de comunicação.', 'danger');
    }
}

// ─── Excluir ──────────────────────────────────────────────────────────────────
function excluir(id, nome) {
    const modal = document.getElementById('cfa-modal-confirm');
    const msg   = document.getElementById('cfa-confirm-msg');
    const btnOk = document.getElementById('cfa-btn-confirm-ok');
    if (!modal || !msg || !btnOk) return;

    msg.textContent = `Deseja realmente excluir o fornecedor "${nome}"? Esta ação desativará o cadastro.`;
    _state.confirmCallback = async () => {
        fecharConfirm();
        try {
            const fd = new FormData();
            fd.append('acao', 'deletar');
            fd.append('id', id);
            const res = await fetch(_api('api_admin_fornecedores.php'), {
                method: 'POST', body: fd, credentials: 'include'
            });
            const d = await res.json();
            if (d.sucesso) {
                _toast(d.mensagem || 'Fornecedor excluído.', 'success');
                await _carregarEstatisticas();
                await _carregarLista();
            } else {
                _toast(d.mensagem || 'Erro ao excluir.', 'danger');
            }
        } catch (err) {
            console.error('[CadastroFornecedorAdmin] Erro excluir:', err);
            _toast('Erro de comunicação.', 'danger');
        }
    };

    // Remover listener anterior e adicionar novo
    const novoBtn = btnOk.cloneNode(true);
    btnOk.parentNode.replaceChild(novoBtn, btnOk);
    novoBtn.addEventListener('click', _state.confirmCallback);

    modal.style.display = 'flex';
}

function fecharConfirm() {
    const modal = document.getElementById('cfa-modal-confirm');
    if (modal) modal.style.display = 'none';
    _state.confirmCallback = null;
}

// ─── Validação do Formulário ──────────────────────────────────────────────────
function _validar() {
    _limparErros();
    let valido = true;

    const cpf   = document.getElementById('cfa-cpf_cnpj')?.value.trim();
    const ramo  = document.getElementById('cfa-ramo_atividade_id')?.value;
    const nome  = document.getElementById('cfa-nome_estabelecimento')?.value.trim();
    const email = document.getElementById('cfa-email')?.value.trim();
    const senha = document.getElementById('cfa-senha')?.value;

    if (!cpf) {
        _setErro('cfa-err-cpf_cnpj', 'CPF/CNPJ é obrigatório.');
        valido = false;
    }
    if (!ramo) {
        _setErro('cfa-err-ramo', 'Ramo de atividade é obrigatório.');
        valido = false;
    }
    if (!nome) {
        _setErro('cfa-err-nome', 'Nome do estabelecimento é obrigatório.');
        valido = false;
    }
    if (!email || !email.includes('@')) {
        _setErro('cfa-err-email', 'E-mail válido é obrigatório.');
        valido = false;
    }
    // Senha obrigatória apenas no cadastro; na edição é opcional
    if (!_state.editandoId && (!senha || senha.length < 6)) {
        _setErro('cfa-err-senha', 'Senha deve ter no mínimo 6 caracteres.');
        valido = false;
    }
    if (_state.editandoId && senha && senha.length > 0 && senha.length < 6) {
        _setErro('cfa-err-senha', 'Nova senha deve ter no mínimo 6 caracteres.');
        valido = false;
    }

    return valido;
}

function _setErro(id, msg) {
    const el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'block'; }
    // Marcar campo pai como erro
    const campo = el?.previousElementSibling;
    if (campo) campo.style.borderColor = '#ef4444';
}

function _limparErros() {
    document.querySelectorAll('.cfa-field-error').forEach(el => {
        el.textContent = '';
        el.style.display = 'none';
    });
    document.querySelectorAll('.cfa-input').forEach(el => {
        el.style.borderColor = '';
    });
}

// ─── Escape HTML ──────────────────────────────────────────────────────────────
function _esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
