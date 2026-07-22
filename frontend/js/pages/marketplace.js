/**
 * Marketplace — Módulo de Página SPA
 * Gerencia Vitrine, Fornecedores e Ramos de Atividade
 * Padrão: ES6 module, init/destroy, listeners via _state.listeners, URLs absolutas
 */

// ============================================================
// ESTADO INTERNO
// ============================================================
const _state = {
    fornecedores: [],
    fornecedoresFiltrados: [],
    ramos: [],
    listeners: [],
    confirmCallback: null,
};

// ============================================================
// CICLO DE VIDA DO MÓDULO
// ============================================================

export function init() {
    console.log('[Marketplace] Inicializando módulo v2.0...');
    _setupTabs();
    _setupVitrine();
    _setupFornecedores();
    _setupRamos();
    _setupModais();
    _carregarFornecedoresVitrine();
    _carregarVitrine();
    console.log('[Marketplace] ✅ Módulo pronto.');
}

export function destroy() {
    console.log('[Marketplace] Destruindo módulo...');
    _state.listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _state.listeners = [];
    _state.fornecedores = [];
    _state.fornecedoresFiltrados = [];
    _state.ramos = [];
    _state.confirmCallback = null;
    console.log('[Marketplace] ✅ Módulo destruído.');
}

// ============================================================
// HELPERS
// ============================================================

function _on(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    _state.listeners.push({ el, ev, fn });
}

function _esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function _api(path) {
    return window.location.origin + '/api/' + path;
}

function _toast(msg, tipo = 'success') {
    const container = document.getElementById('toast-mk');
    if (!container) return;
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    const el = document.createElement('div');
    el.className = `toast-mk toast-mk-${tipo}`;
    el.innerHTML = `<i class="fas ${icons[tipo] || icons.info}"></i><span>${_esc(msg)}</span>`;
    container.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function _showLoading(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function _hideLoading(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

function _openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function _closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

function _confirm(titulo, msg, callback) {
    document.getElementById('confirm-titulo-mk').innerHTML = `<i class="fas fa-question-circle"></i> ${_esc(titulo)}`;
    document.getElementById('confirm-msg-mk').textContent = msg;
    _state.confirmCallback = callback;
    _openModal('modal-confirm-mk');
}

function _stars(nota) {
    const n = Math.round(parseFloat(nota) || 0);
    return '★'.repeat(n) + '☆'.repeat(5 - n);
}

// ============================================================
// TABS
// ============================================================

function _setupTabs() {
    document.querySelectorAll('.tab-mk').forEach(btn => {
        _on(btn, 'click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.tab-mk').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-mk-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const content = document.getElementById('tab-' + tab);
            if (content) content.classList.add('active');

            // Mostrar/ocultar botão de novo ramo no header
            const btnNovoRamo = document.getElementById('btn-novo-ramo');
            if (btnNovoRamo) btnNovoRamo.style.display = tab === 'ramos' ? 'inline-flex' : 'none';

            // Carregar dados ao mudar de aba
            if (tab === 'fornecedores' && _state.fornecedores.length === 0) {
                _carregarFornecedores();
                _carregarEstatisticas();
            }
            if (tab === 'ramos' && _state.ramos.length === 0) {
                _carregarRamos();
            }
        });
    });
}

// ============================================================
// VITRINE
// ============================================================

function _setupVitrine() {
    _on(document.getElementById('btn-buscar-vitrine'), 'click', _carregarVitrine);
    _on(document.getElementById('btn-limpar-vitrine'), 'click', () => {
        const busca = document.getElementById('vt-busca');
        const tipo = document.getElementById('vt-tipo');
        const forn = document.getElementById('vt-fornecedor');
        if (busca) busca.value = '';
        if (tipo) tipo.value = '';
        if (forn) forn.value = '';
        _carregarVitrine();
    });

    // Busca ao pressionar Enter
    _on(document.getElementById('vt-busca'), 'keydown', (e) => {
        if (e.key === 'Enter') _carregarVitrine();
    });
}

async function _carregarFornecedoresVitrine() {
    try {
        const res = await fetch(_api('api_marketplace.php?acao=listar_fornecedores'), { credentials: 'include' });
        const data = await res.json();
        const select = document.getElementById('vt-fornecedor');
        if (!select) return;
        if (data.sucesso && Array.isArray(data.dados)) {
            data.dados.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.nome_estabelecimento;
                select.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('[Marketplace] Erro ao carregar fornecedores para vitrine:', err);
    }
}

async function _carregarVitrine() {
    _showLoading('loading-vitrine');
    const grid = document.getElementById('grid-produtos');
    const empty = document.getElementById('empty-vitrine');
    if (grid) grid.innerHTML = '';
    if (empty) empty.style.display = 'none';

    const busca = document.getElementById('vt-busca')?.value || '';
    const tipo = document.getElementById('vt-tipo')?.value || '';
    const fornId = document.getElementById('vt-fornecedor')?.value || '';

    let url = _api('api_marketplace.php?acao=listar');
    if (busca) url += '&busca=' + encodeURIComponent(busca);
    if (tipo) url += '&tipo=' + encodeURIComponent(tipo);
    if (fornId) url += '&fornecedor_id=' + encodeURIComponent(fornId);

    try {
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        _hideLoading('loading-vitrine');

        if (!data.sucesso || !Array.isArray(data.dados) || data.dados.length === 0) {
            if (empty) empty.style.display = 'block';
            return;
        }

        if (!grid) return;
        grid.innerHTML = data.dados.map(p => {
            const tipoClass = p.tipo === 'produto' ? 'produto-tipo-produto-mk' : 'produto-tipo-servico-mk';
            const tipoLabel = p.tipo === 'produto' ? '<i class="fas fa-box"></i> Produto' : '<i class="fas fa-tools"></i> Serviço';
            const preco = parseFloat(p.valor || p.preco_venda || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            const negociavel = (p.valor_negociavel == 1) ? '<span class="badge-mk badge-mk-info" style="margin-left:0.4rem;">Negociável</span>' : '';
            const nota = parseFloat(p.media_nota || p.media_avaliacoes_fornecedor || 0);
            const totalAval = parseInt(p.total_avaliacoes || p.total_avaliacoes_fornecedor || 0);

            return `
            <div class="produto-card-mk">
                <div class="produto-card-header-mk">
                    <span class="produto-tipo-badge-mk ${tipoClass}">${tipoLabel}</span>
                    ${negociavel}
                </div>
                <div class="produto-card-body-mk">
                    <div class="produto-nome-mk">${_esc(p.nome)}</div>
                    <div class="produto-fornecedor-mk">
                        <i class="fas fa-store"></i> ${_esc(p.fornecedor_nome || p.nome_estabelecimento || '')}
                    </div>
                    <div class="produto-descricao-mk">${_esc(p.descricao || 'Sem descrição')}</div>
                    <div class="produto-preco-mk">${preco}</div>
                    <div class="produto-rating-mk">
                        <span class="stars-mk">${_stars(nota)}</span>
                        <span>${nota.toFixed(1)} (${totalAval} avaliações)</span>
                    </div>
                </div>
                <div class="produto-card-footer-mk">
                    <button class="btn-mk btn-mk-primary btn-mk-sm" data-ver-produto="${_esc(p.id)}">
                        <i class="fas fa-eye"></i> Ver Detalhes
                    </button>
                </div>
            </div>`;
        }).join('');

        // Eventos nos botões do grid
        grid.querySelectorAll('[data-ver-produto]').forEach(btn => {
            _on(btn, 'click', () => _verDetalhesProduto(btn.dataset.verProduto));
        });

        console.log('[Marketplace] Vitrine carregada:', data.dados.length, 'itens');
    } catch (err) {
        _hideLoading('loading-vitrine');
        console.error('[Marketplace] Erro ao carregar vitrine:', err);
        _toast('Erro ao carregar produtos', 'error');
    }
}

async function _verDetalhesProduto(id) {
    _openModal('modal-produto');
    const body = document.getElementById('modal-produto-body');
    if (body) body.innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#2563eb;"></i></div>';

    try {
        const res = await fetch(_api(`api_marketplace.php?acao=obter&id=${encodeURIComponent(id)}`), { credentials: 'include' });
        const data = await res.json();

        if (!data.sucesso || !data.dados) {
            if (body) body.innerHTML = '<p style="color:#ef4444;">Produto não encontrado.</p>';
            return;
        }

        const p = data.dados;
        const preco = parseFloat(p.valor || p.preco_venda || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        const negociavel = (p.valor_negociavel == 1) ? '<span class="badge-mk badge-mk-info">Negociável</span>' : '<span class="badge-mk badge-mk-danger">Não negociável</span>';
        const tipoLabel = p.tipo === 'produto' ? 'Produto' : 'Serviço';

        document.getElementById('modal-produto-titulo').innerHTML = `<i class="fas fa-box"></i> ${_esc(p.nome)}`;

        if (body) {
            body.innerHTML = `
            <div class="modal-produto-grid-mk">
                <div class="modal-produto-field-mk">
                    <label>Tipo</label>
                    <span>${_esc(tipoLabel)}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Preço</label>
                    <span>${preco} ${negociavel}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Fornecedor</label>
                    <span>${_esc(p.fornecedor_nome || p.nome_estabelecimento || '-')}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Ramo de Atividade</label>
                    <span>${_esc(p.ramo_atividade || '-')}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Telefone do Fornecedor</label>
                    <span>${_esc(p.fornecedor_telefone || p.telefone || '-')}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Email do Fornecedor</label>
                    <span>${_esc(p.fornecedor_email || p.email || '-')}</span>
                </div>
                <div class="modal-produto-field-mk modal-produto-full-mk">
                    <label>Descrição</label>
                    <span>${_esc(p.descricao || 'Sem descrição')}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Avaliação Média</label>
                    <span class="stars-mk">${_stars(p.media_nota || 0)}</span>
                </div>
                <div class="modal-produto-field-mk">
                    <label>Total de Avaliações</label>
                    <span>${_esc(p.total_avaliacoes || 0)}</span>
                </div>
            </div>`;
        }
    } catch (err) {
        console.error('[Marketplace] Erro ao carregar produto:', err);
        if (body) body.innerHTML = '<p style="color:#ef4444;">Erro ao carregar detalhes.</p>';
    }
}

// ============================================================
// FORNECEDORES
// ============================================================

function _setupFornecedores() {
    _on(document.getElementById('btn-filtrar-forn'), 'click', _aplicarFiltrosForn);
    _on(document.getElementById('btn-limpar-forn'), 'click', _limparFiltrosForn);
    _on(document.getElementById('btn-atualizar-forn'), 'click', () => {
        _carregarFornecedores();
        _carregarEstatisticas();
    });
    _on(document.getElementById('forn-busca'), 'keydown', (e) => {
        if (e.key === 'Enter') _aplicarFiltrosForn();
    });
}

async function _carregarEstatisticas() {
    try {
        const res = await fetch(_api('api_admin_fornecedores.php?acao=estatisticas'), { credentials: 'include' });
        const data = await res.json();
        if (data.sucesso && data.dados) {
            const d = data.dados;
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || 0; };
            set('kpi-total-forn', d.total_fornecedores);
            set('kpi-ativos-forn', d.fornecedores_ativos);
            set('kpi-inativos-forn', d.fornecedores_inativos);
            set('kpi-pendentes-forn', d.fornecedores_pendentes);
        }
    } catch (err) {
        console.error('[Marketplace] Erro ao carregar estatísticas:', err);
    }
}

async function _carregarFornecedores() {
    _showLoading('loading-forn');
    try {
        const res = await fetch(_api('api_admin_fornecedores.php?acao=listar_todos'), { credentials: 'include' });
        const data = await res.json();
        _hideLoading('loading-forn');
        if (data.sucesso && Array.isArray(data.dados)) {
            _state.fornecedores = data.dados;
            _state.fornecedoresFiltrados = [...data.dados];
            _renderFornecedores();
            console.log('[Marketplace] Fornecedores carregados:', data.dados.length);
        } else {
            _toast(data.mensagem || 'Erro ao carregar fornecedores', 'error');
        }
    } catch (err) {
        _hideLoading('loading-forn');
        console.error('[Marketplace] Erro ao carregar fornecedores:', err);
        _toast('Erro ao carregar fornecedores', 'error');
    }
}

function _renderFornecedores() {
    const tbody = document.getElementById('tbody-fornecedores');
    if (!tbody) return;

    if (_state.fornecedoresFiltrados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="empty-row-mk">Nenhum fornecedor encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = _state.fornecedoresFiltrados.map(f => {
        const ativo = f.ativo == 1 || f.is_ativo == 1;
        const aprovado = f.aprovado == 1 || f.is_aprovado == 1;
        const id = f.id;

        const badgeStatus = ativo
            ? '<span class="badge-mk badge-mk-success"><i class="fas fa-check"></i> Ativo</span>'
            : '<span class="badge-mk badge-mk-danger"><i class="fas fa-ban"></i> Inativo</span>';

        const badgeAprov = aprovado
            ? '<span class="badge-mk badge-mk-success"><i class="fas fa-check-circle"></i> Aprovado</span>'
            : '<span class="badge-mk badge-mk-warning"><i class="fas fa-clock"></i> Pendente</span>';

        const acoes = !aprovado
            ? `<button class="btn-mk btn-mk-success btn-mk-sm" data-aprovar="${id}" title="Aprovar"><i class="fas fa-check"></i></button>
               <button class="btn-mk btn-mk-danger btn-mk-sm" data-rejeitar="${id}" title="Rejeitar"><i class="fas fa-times"></i></button>`
            : `<button class="btn-mk btn-mk-warning btn-mk-sm" data-toggle-status="${id}" data-ativo="${ativo ? 1 : 0}" title="${ativo ? 'Desativar' : 'Ativar'}">
                   <i class="fas fa-${ativo ? 'ban' : 'check'}"></i>
               </button>`;

        return `<tr>
            <td>${_esc(id)}</td>
            <td><strong>${_esc(f.nome_estabelecimento)}</strong><br><small style="color:#64748b;">${_esc(f.nome_responsavel || '')}</small></td>
            <td>${_esc(f.cpf_cnpj || '-')}</td>
            <td><i class="fas ${_esc(f.ramo_icone || 'fa-briefcase')}"></i> ${_esc(f.ramo_atividade || '-')}</td>
            <td>${_esc(f.telefone || '-')}</td>
            <td>${_esc(f.email || '-')}</td>
            <td>${badgeStatus}</td>
            <td>${badgeAprov}</td>
            <td>${_esc(f.data_cadastro || '-')}</td>
            <td class="actions-mk">${acoes}</td>
        </tr>`;
    }).join('');

    // Eventos nos botões da tabela
    tbody.querySelectorAll('[data-aprovar]').forEach(btn => {
        _on(btn, 'click', () => _aprovarFornecedor(btn.dataset.aprovar));
    });
    tbody.querySelectorAll('[data-rejeitar]').forEach(btn => {
        _on(btn, 'click', () => _rejeitarFornecedor(btn.dataset.rejeitar));
    });
    tbody.querySelectorAll('[data-toggle-status]').forEach(btn => {
        _on(btn, 'click', () => _toggleStatusFornecedor(btn.dataset.toggleStatus, btn.dataset.ativo));
    });
}

function _aplicarFiltrosForn() {
    const busca = (document.getElementById('forn-busca')?.value || '').toLowerCase();
    const status = document.getElementById('forn-status')?.value || '';
    const aprovacao = document.getElementById('forn-aprovacao')?.value || '';

    _state.fornecedoresFiltrados = _state.fornecedores.filter(f => {
        const ativo = f.ativo ?? f.is_ativo;
        const aprovado = f.aprovado ?? f.is_aprovado;
        if (status !== '' && String(ativo) !== status) return false;
        if (aprovacao !== '' && String(aprovado) !== aprovacao) return false;
        if (busca) {
            const nome = (f.nome_estabelecimento || '').toLowerCase();
            const cnpj = (f.cpf_cnpj || '').toLowerCase();
            if (!nome.includes(busca) && !cnpj.includes(busca)) return false;
        }
        return true;
    });
    _renderFornecedores();
}

function _limparFiltrosForn() {
    const fields = ['forn-busca', 'forn-status', 'forn-aprovacao'];
    fields.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    _state.fornecedoresFiltrados = [..._state.fornecedores];
    _renderFornecedores();
}

async function _aprovarFornecedor(id) {
    _confirm('Aprovar Fornecedor', 'Deseja aprovar este fornecedor?', async () => {
        try {
            const fd = new FormData();
            fd.append('acao', 'alternar_aprovacao');
            fd.append('id', parseInt(id));
            const res = await fetch(_api('api_admin_fornecedores.php'), {
                method: 'POST',
                credentials: 'include',
                body: fd,
            });
            const data = await res.json();
            if (data.sucesso) {
                _toast('Fornecedor aprovado com sucesso!', 'success');
                _carregarFornecedores();
                _carregarEstatisticas();
            } else {
                _toast(data.mensagem || 'Erro ao aprovar', 'error');
            }
        } catch (err) {
            console.error('[Marketplace] Erro ao aprovar fornecedor:', err);
            _toast('Erro ao aprovar fornecedor', 'error');
        }
    });
}

async function _rejeitarFornecedor(id) {
    _confirm('Rejeitar Fornecedor', 'Deseja rejeitar este fornecedor? A aprovação será removida.', async () => {
        try {
            const fd = new FormData();
            fd.append('acao', 'alternar_aprovacao');
            fd.append('id', parseInt(id));
            const res = await fetch(_api('api_admin_fornecedores.php'), {
                method: 'POST',
                credentials: 'include',
                body: fd,
            });
            const data = await res.json();
            if (data.sucesso) {
                _toast('Aprovação removida', 'warning');
                _carregarFornecedores();
                _carregarEstatisticas();
            } else {
                _toast(data.mensagem || 'Erro ao rejeitar', 'error');
            }
        } catch (err) {
            console.error('[Marketplace] Erro ao rejeitar fornecedor:', err);
            _toast('Erro ao rejeitar fornecedor', 'error');
        }
    });
}

async function _toggleStatusFornecedor(id, atoAtual) {
    const ativo = parseInt(atoAtual) === 1;
    const msg = ativo ? 'Desativar este fornecedor?' : 'Ativar este fornecedor?';
    _confirm(ativo ? 'Desativar Fornecedor' : 'Ativar Fornecedor', msg, async () => {
        try {
            const fd = new FormData();
            fd.append('acao', 'alternar_status');
            fd.append('id', parseInt(id));
            const res = await fetch(_api('api_admin_fornecedores.php'), {
                method: 'POST',
                credentials: 'include',
                body: fd,
            });
            const data = await res.json();
            if (data.sucesso) {
                _toast(ativo ? 'Fornecedor desativado' : 'Fornecedor ativado', 'success');
                _carregarFornecedores();
                _carregarEstatisticas();
            } else {
                _toast(data.mensagem || 'Erro ao alterar status', 'error');
            }
        } catch (err) {
            console.error('[Marketplace] Erro ao alterar status:', err);
            _toast('Erro ao alterar status', 'error');
        }
    });
}

// ============================================================
// RAMOS DE ATIVIDADE
// ============================================================

function _setupRamos() {
    _on(document.getElementById('btn-novo-ramo'), 'click', () => _abrirModalRamo());
    _on(document.getElementById('btn-novo-ramo-tab'), 'click', () => _abrirModalRamo());

    // Preview do ícone em tempo real
    _on(document.getElementById('ramo-icone'), 'input', (e) => {
        const val = e.target.value.trim();
        const icon = document.getElementById('icone-preview-icon');
        const nome = document.getElementById('icone-preview-nome');
        if (icon) { icon.className = `fas ${val || 'fa-briefcase'}`; }
        if (nome) nome.textContent = val || 'fa-briefcase';
    });
}

async function _carregarRamos() {
    _showLoading('loading-ramos');
    try {
        const res = await fetch(_api('api_ramos_atividade.php?acao=listar_todos'), { credentials: 'include' });
        const data = await res.json();
        _hideLoading('loading-ramos');
        if (data.sucesso && Array.isArray(data.dados)) {
            _state.ramos = data.dados;
            _renderRamos();
            console.log('[Marketplace] Ramos carregados:', data.dados.length);
        } else {
            _toast(data.mensagem || 'Erro ao carregar ramos', 'error');
        }
    } catch (err) {
        _hideLoading('loading-ramos');
        console.error('[Marketplace] Erro ao carregar ramos:', err);
        _toast('Erro ao carregar ramos', 'error');
    }
}

function _renderRamos() {
    const tbody = document.getElementById('tbody-ramos');
    if (!tbody) return;

    if (_state.ramos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty-row-mk">Nenhum ramo encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = _state.ramos.map(r => {
        const ativo = r.ativo == 1;
        const badgeStatus = ativo
            ? '<span class="badge-mk badge-mk-success">Ativo</span>'
            : '<span class="badge-mk badge-mk-danger">Inativo</span>';

        return `<tr>
            <td>${_esc(r.id)}</td>
            <td style="font-size:1.25rem;"><i class="fas ${_esc(r.icone || 'fa-briefcase')}" style="color:#2563eb;"></i></td>
            <td><strong>${_esc(r.nome)}</strong></td>
            <td>${_esc(r.descricao || '-')}</td>
            <td>${_esc(r.total_fornecedores || 0)}</td>
            <td>${badgeStatus}</td>
            <td>${_esc(r.data_criacao_formatada || r.data_criacao || '-')}</td>
            <td class="actions-mk">
                <button class="btn-mk btn-mk-primary btn-mk-sm" data-editar-ramo="${r.id}" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-mk btn-mk-warning btn-mk-sm" data-toggle-ramo="${r.id}" title="${ativo ? 'Desativar' : 'Ativar'}">
                    <i class="fas fa-${ativo ? 'ban' : 'check'}"></i>
                </button>
            </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-editar-ramo]').forEach(btn => {
        _on(btn, 'click', () => _editarRamo(btn.dataset.editarRamo));
    });
    tbody.querySelectorAll('[data-toggle-ramo]').forEach(btn => {
        _on(btn, 'click', () => _toggleStatusRamo(btn.dataset.toggleRamo));
    });
}

function _abrirModalRamo(ramo = null) {
    const titulo = document.getElementById('modal-ramo-titulo');
    const idInput = document.getElementById('ramo-id');
    const nomeInput = document.getElementById('ramo-nome');
    const descInput = document.getElementById('ramo-descricao');
    const iconeInput = document.getElementById('ramo-icone');
    const previewIcon = document.getElementById('icone-preview-icon');
    const previewNome = document.getElementById('icone-preview-nome');

    if (ramo) {
        if (titulo) titulo.innerHTML = `<i class="fas fa-edit"></i> Editar Ramo: ${_esc(ramo.nome)}`;
        if (idInput) idInput.value = ramo.id;
        if (nomeInput) nomeInput.value = ramo.nome || '';
        if (descInput) descInput.value = ramo.descricao || '';
        if (iconeInput) iconeInput.value = ramo.icone || 'fa-briefcase';
        if (previewIcon) previewIcon.className = `fas ${ramo.icone || 'fa-briefcase'}`;
        if (previewNome) previewNome.textContent = ramo.icone || 'fa-briefcase';
    } else {
        if (titulo) titulo.innerHTML = '<i class="fas fa-plus"></i> Novo Ramo de Atividade';
        if (idInput) idInput.value = '';
        if (nomeInput) nomeInput.value = '';
        if (descInput) descInput.value = '';
        if (iconeInput) iconeInput.value = 'fa-briefcase';
        if (previewIcon) previewIcon.className = 'fas fa-briefcase';
        if (previewNome) previewNome.textContent = 'fa-briefcase';
    }

    _openModal('modal-ramo');
}

function _editarRamo(id) {
    const ramo = _state.ramos.find(r => String(r.id) === String(id));
    if (!ramo) {
        _toast('Ramo não encontrado', 'error');
        return;
    }
    _abrirModalRamo(ramo);
}

async function _salvarRamo() {
    const id = document.getElementById('ramo-id')?.value || '';
    const nome = document.getElementById('ramo-nome')?.value?.trim() || '';
    const descricao = document.getElementById('ramo-descricao')?.value?.trim() || '';
    const icone = document.getElementById('ramo-icone')?.value?.trim() || 'fa-briefcase';

    if (!nome) {
        _toast('O nome do ramo é obrigatório', 'warning');
        document.getElementById('ramo-nome')?.focus();
        return;
    }

    const isEdicao = !!id;
    const formData = new FormData();
    formData.append('acao', isEdicao ? 'atualizar' : 'cadastrar');
    if (isEdicao) formData.append('id', id);
    formData.append('nome', nome);
    formData.append('descricao', descricao);
    formData.append('icone', icone);

    const btn = document.getElementById('btn-salvar-ramo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const res = await fetch(_api('api_ramos_atividade.php'), {
            method: 'POST',
            credentials: 'include',
            body: formData,
        });
        const data = await res.json();

        if (data.sucesso) {
            _toast(isEdicao ? 'Ramo atualizado com sucesso!' : 'Ramo cadastrado com sucesso!', 'success');
            _closeModal('modal-ramo');
            _state.ramos = [];
            _carregarRamos();
        } else {
            _toast(data.mensagem || 'Erro ao salvar ramo', 'error');
        }
    } catch (err) {
        console.error('[Marketplace] Erro ao salvar ramo:', err);
        _toast('Erro ao salvar ramo', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar'; }
    }
}

async function _toggleStatusRamo(id) {
    const ramo = _state.ramos.find(r => String(r.id) === String(id));
    const ativo = ramo?.ativo == 1;
    const msg = ativo ? 'Desativar este ramo de atividade?' : 'Ativar este ramo de atividade?';

    _confirm(ativo ? 'Desativar Ramo' : 'Ativar Ramo', msg, async () => {
        const formData = new FormData();
        formData.append('acao', 'alternar_status');
        formData.append('id', id);

        try {
            const res = await fetch(_api('api_ramos_atividade.php'), {
                method: 'POST',
                credentials: 'include',
                body: formData,
            });
            const data = await res.json();
            if (data.sucesso) {
                _toast(ativo ? 'Ramo desativado' : 'Ramo ativado', 'success');
                _state.ramos = [];
                _carregarRamos();
            } else {
                _toast(data.mensagem || 'Erro ao alterar status', 'error');
            }
        } catch (err) {
            console.error('[Marketplace] Erro ao alterar status do ramo:', err);
            _toast('Erro ao alterar status', 'error');
        }
    });
}

// ============================================================
// MODAIS
// ============================================================

function _setupModais() {
    // Fechar modal produto
    _on(document.getElementById('btn-fechar-produto'), 'click', () => _closeModal('modal-produto'));
    _on(document.getElementById('btn-fechar-produto-2'), 'click', () => _closeModal('modal-produto'));

    // Fechar modal ramo
    _on(document.getElementById('btn-fechar-ramo'), 'click', () => _closeModal('modal-ramo'));
    _on(document.getElementById('btn-cancelar-ramo'), 'click', () => _closeModal('modal-ramo'));

    // Salvar ramo
    _on(document.getElementById('btn-salvar-ramo'), 'click', _salvarRamo);

    // Confirmação
    _on(document.getElementById('btn-fechar-confirm'), 'click', () => _closeModal('modal-confirm-mk'));
    _on(document.getElementById('btn-confirm-cancel'), 'click', () => _closeModal('modal-confirm-mk'));
    _on(document.getElementById('btn-confirm-ok'), 'click', () => {
        _closeModal('modal-confirm-mk');
        if (typeof _state.confirmCallback === 'function') {
            _state.confirmCallback();
            _state.confirmCallback = null;
        }
    });

    // Fechar ao clicar no overlay
    document.querySelectorAll('.modal-overlay-mk.page-marketplace').forEach(overlay => {
        _on(overlay, 'click', (e) => {
            if (e.target === overlay) overlay.classList.remove('show');
        });
    });
}
