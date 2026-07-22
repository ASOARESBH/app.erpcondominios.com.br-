/**
 * Protocolos.js — Módulo de Protocolo de Mercadorias
 * Padrão: ES6 Module com init/destroy, listeners gerenciados, URLs absolutas
 * @version 2.0.0
 */

'use strict';

// ========== ESTADO DO MÓDULO ==========
const _state = {
    protocolos: [],       // cache completo
    unidades: [],
    usuarios: [],
    paginaAtual: 1,
    itensPorPagina: 15,
    filtroStatus: '',
    termoBusca: '',
    refreshInterval: null,
    editandoId: null
};

// Referências nomeadas para removeEventListener
const _listeners = [];

// Base URL absoluta — elimina ambiguidade de path relativo
const API_BASE = window.location.origin + '/api';

// ========== EXPORT: INIT ==========
export function init() {
    console.log('[Protocolos] Inicializando módulo v2.0...');

    _carregarUnidades();
    _carregarUsuarios();
    _carregarProtocolos();
    _definirDataHoraAtual();
    _setupTabs();
    _setupFormularios();
    _setupBusca();
    _setupModais();
    _setupBotoesExtra();

    // Auto-refresh a cada 90 segundos
    _state.refreshInterval = setInterval(() => {
        _carregarProtocolos(false); // silent refresh
    }, 90000);

    // Expor funções globais para uso em onclick nas tabelas
    window.ProtocolosPage = {
        editarProtocolo: _editarProtocolo,
        excluirProtocolo: _excluirProtocolo,
        abrirModalEntrega: _abrirModalEntrega,
        verDetalhes: _verDetalhes
    };

    console.log('[Protocolos] Módulo pronto.');
}

// ========== EXPORT: DESTROY ==========
export function destroy() {
    console.log('[Protocolos] Destruindo módulo...');

    // Limpar interval
    if (_state.refreshInterval) {
        clearInterval(_state.refreshInterval);
        _state.refreshInterval = null;
    }

    // Remover todos os listeners registrados
    _listeners.forEach(({ el, event, fn }) => {
        if (el) el.removeEventListener(event, fn);
    });
    _listeners.length = 0;

    // Limpar namespace global
    if (window.ProtocolosPage) delete window.ProtocolosPage;

    // Resetar estado
    _state.protocolos = [];
    _state.unidades = [];
    _state.usuarios = [];
    _state.editandoId = null;

    console.log('[Protocolos] Módulo destruído.');
}

// ========== SETUP: TABS ==========
function _setupTabs() {
    const tabButtons = document.querySelectorAll('.page-protocolos .tab-button');
    tabButtons.forEach(btn => {
        const fn = () => _ativarTab(btn.dataset.tab);
        btn.addEventListener('click', fn);
        _listeners.push({ el: btn, event: 'click', fn });
    });
}

function _ativarTab(tabId) {
    document.querySelectorAll('.page-protocolos .tab-button').forEach(b => {
        b.classList.toggle('active', b.dataset.tab === tabId);
    });
    document.querySelectorAll('.page-protocolos .tab-content').forEach(c => {
        c.classList.toggle('active', c.id === `tab-${tabId}`);
    });

    // Carregar dados específicos da aba ao ativar
    if (tabId === 'pendentes') _renderizarPendentes();
    if (tabId === 'historico') _setupHistoricoDefault();
}

// ========== SETUP: FORMULÁRIOS ==========
function _setupFormularios() {
    // Form principal
    const formProto = document.getElementById('formProtocolo');
    if (formProto) {
        const fn = e => { e.preventDefault(); _salvarProtocolo(); };
        formProto.addEventListener('submit', fn);
        _listeners.push({ el: formProto, event: 'submit', fn });
    }

    // Cascata unidade → morador
    const unidadeSelect = document.getElementById('unidadeId');
    if (unidadeSelect) {
        const fn = () => _carregarMoradores();
        unidadeSelect.addEventListener('change', fn);
        _listeners.push({ el: unidadeSelect, event: 'change', fn });
    }
}

// ========== SETUP: BUSCA E FILTROS ==========
function _setupBusca() {
    const busca = document.getElementById('buscaProtocolo');
    if (busca) {
        const fn = () => {
            _state.termoBusca = busca.value.toLowerCase().trim();
            _state.paginaAtual = 1;
            _renderizarTabela();
        };
        busca.addEventListener('input', fn);
        _listeners.push({ el: busca, event: 'input', fn });
    }

    const filtroStatus = document.getElementById('filtroStatus');
    if (filtroStatus) {
        const fn = () => {
            _state.filtroStatus = filtroStatus.value;
            _state.paginaAtual = 1;
            _renderizarTabela();
        };
        filtroStatus.addEventListener('change', fn);
        _listeners.push({ el: filtroStatus, event: 'change', fn });
    }
}

// ========== SETUP: MODAIS ==========
function _setupModais() {
    // Modal Entrega — fechar
    const btnFechar = document.getElementById('btnFecharModalEntrega');
    if (btnFechar) {
        const fn = () => _fecharModalEntrega();
        btnFechar.addEventListener('click', fn);
        _listeners.push({ el: btnFechar, event: 'click', fn });
    }

    const btnCancelar = document.getElementById('btnCancelarEntrega');
    if (btnCancelar) {
        const fn = () => _fecharModalEntrega();
        btnCancelar.addEventListener('click', fn);
        _listeners.push({ el: btnCancelar, event: 'click', fn });
    }

    // Modal Entrega — confirmar
    const btnConfirmar = document.getElementById('btnConfirmarEntrega');
    if (btnConfirmar) {
        const fn = () => _registrarEntrega();
        btnConfirmar.addEventListener('click', fn);
        _listeners.push({ el: btnConfirmar, event: 'click', fn });
    }

    // Modal Detalhes — fechar
    ['btnFecharModalDetalhes', 'btnFecharDetalhesFooter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            const fn = () => _fecharModalDetalhes();
            el.addEventListener('click', fn);
            _listeners.push({ el, event: 'click', fn });
        }
    });

    // Fechar modal ao clicar fora
    const fnClickFora = e => {
        const modalEntrega = document.getElementById('modalEntrega');
        const modalDetalhes = document.getElementById('modalDetalhes');
        if (e.target === modalEntrega) _fecharModalEntrega();
        if (e.target === modalDetalhes) _fecharModalDetalhes();
    };
    document.addEventListener('click', fnClickFora);
    _listeners.push({ el: document, event: 'click', fn: fnClickFora });
}

// ========== SETUP: BOTÕES EXTRAS ==========
function _setupBotoesExtra() {
    // Limpar formulário
    const btnLimpar = document.getElementById('btnLimparProtocolo');
    if (btnLimpar) {
        const fn = () => _limparFormulario();
        btnLimpar.addEventListener('click', fn);
        _listeners.push({ el: btnLimpar, event: 'click', fn });
    }

    // Atualizar listagem
    const btnAtualizar = document.getElementById('btnAtualizarListagem');
    if (btnAtualizar) {
        const fn = () => _carregarProtocolos();
        btnAtualizar.addEventListener('click', fn);
        _listeners.push({ el: btnAtualizar, event: 'click', fn });
    }

    // Atualizar pendentes
    const btnAtualizarPend = document.getElementById('btnAtualizarPendentes');
    if (btnAtualizarPend) {
        const fn = () => _carregarProtocolos();
        btnAtualizarPend.addEventListener('click', fn);
        _listeners.push({ el: btnAtualizarPend, event: 'click', fn });
    }

    // Exportar CSV
    const btnExportar = document.getElementById('btnExportarCSV');
    if (btnExportar) {
        const fn = () => _exportarCSV();
        btnExportar.addEventListener('click', fn);
        _listeners.push({ el: btnExportar, event: 'click', fn });
    }

    // Filtrar histórico
    const btnFiltrar = document.getElementById('btnFiltrarHistorico');
    if (btnFiltrar) {
        const fn = () => _filtrarHistorico();
        btnFiltrar.addEventListener('click', fn);
        _listeners.push({ el: btnFiltrar, event: 'click', fn });
    }

    // Exportar histórico
    const btnExpHist = document.getElementById('btnExportarHistorico');
    if (btnExpHist) {
        const fn = () => _exportarHistoricoCSV();
        btnExpHist.addEventListener('click', fn);
        _listeners.push({ el: btnExpHist, event: 'click', fn });
    }
}

// ========== CARREGAMENTO DE DADOS ==========

function _carregarUnidades() {
    console.log('[Protocolos] Carregando unidades...');
    fetch(`${API_BASE}/api_unidades.php?acao=select`, { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) throw new Error(data.mensagem || 'Erro ao carregar unidades');
            const lista = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
            _state.unidades = lista.sort((a, b) => {
                const nA = parseInt(a.nome.replace(/\D/g, '')) || 0;
                const nB = parseInt(b.nome.replace(/\D/g, '')) || 0;
                return nA - nB;
            });
            const sel = document.getElementById('unidadeId');
            if (sel) {
                sel.innerHTML = '<option value="">Selecione uma unidade...</option>';
                _state.unidades.forEach(u => {
                    sel.innerHTML += `<option value="${u.id}">${_esc(u.nome)}</option>`;
                });
            }
            console.log(`[Protocolos] ${_state.unidades.length} unidades carregadas.`);
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao carregar unidades:', err);
            _toast('Erro ao carregar unidades', 'error');
        });
}

function _carregarMoradores() {
    const unidadeId = document.getElementById('unidadeId')?.value;
    const moradorSel = document.getElementById('moradorId');
    if (!moradorSel) return;

    if (!unidadeId) {
        moradorSel.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        moradorSel.disabled = true;
        return;
    }

    moradorSel.innerHTML = '<option value="">Carregando...</option>';
    moradorSel.disabled = true;

    const unidade = _state.unidades.find(u => u.id == unidadeId);
    const nomeUnidade = unidade ? unidade.nome : '';

    fetch(`${API_BASE}/api_moradores.php?unidade=${encodeURIComponent(nomeUnidade)}&por_pagina=0`, { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            moradorSel.disabled = false;
            // api_moradores retorna dados paginados: { itens: [...], total, ... }
            const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
            if (data.sucesso && moradores.length > 0) {
                moradorSel.innerHTML = '<option value="">Selecione o morador...</option>';
                moradores.forEach(m => {
                    moradorSel.innerHTML += `<option value="${m.id}">${_esc(m.nome)}</option>`;
                });
                console.log(`[Protocolos] ${moradores.length} moradores carregados.`);
            } else {
                moradorSel.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
            }
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao carregar moradores:', err);
            moradorSel.innerHTML = '<option value="">Erro ao carregar</option>';
            moradorSel.disabled = false;
        });
}

function _carregarUsuarios() {
    console.log('[Protocolos] Carregando usuários...');
    fetch(`${API_BASE}/api_usuarios.php`, { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) throw new Error(data.mensagem);
            _state.usuarios = data.dados.filter(u => u.ativo == 1);
            const sel = document.getElementById('recebedorPortaria');
            if (sel) {
                sel.innerHTML = '<option value="">Selecione o porteiro...</option>';
                _state.usuarios.forEach(u => {
                    const funcao = u.funcao ? ` — ${u.funcao}` : '';
                    sel.innerHTML += `<option value="${_esc(u.nome)}">${_esc(u.nome)}${_esc(funcao)}</option>`;
                });
            }
            console.log(`[Protocolos] ${_state.usuarios.length} usuários carregados.`);
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao carregar usuários:', err);
        });
}

function _carregarProtocolos(showLoading = true) {
    console.log('[Protocolos] Carregando protocolos...');
    if (showLoading) {
        const tbody = document.getElementById('tabelaProtocolos');
        if (tbody) tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></td></tr>`;
    }

    fetch(`${API_BASE}/api_protocolos.php`, { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) throw new Error(data.mensagem);
            _state.protocolos = data.dados || [];
            _state.paginaAtual = 1;
            _renderizarTabela();
            _renderizarPendentes();
            _atualizarKPIs();
            console.log(`[Protocolos] ${_state.protocolos.length} protocolos carregados.`);
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao carregar protocolos:', err);
            const tbody = document.getElementById('tabelaProtocolos');
            if (tbody) tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Erro ao carregar protocolos</p></td></tr>`;
        });
}

// ========== RENDERIZAÇÃO ==========

function _atualizarKPIs() {
    const total = _state.protocolos.length;
    const pendentes = _state.protocolos.filter(p => p.status === 'pendente').length;
    const hoje = new Date().toLocaleDateString('pt-BR');
    const entreguesHoje = _state.protocolos.filter(p => {
        if (p.status !== 'entregue' || !p.data_hora_entrega) return false;
        return new Date(p.data_hora_entrega).toLocaleDateString('pt-BR') === hoje;
    }).length;

    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setVal('kpiTotalValor', total);
    setVal('kpiPendentesValor', pendentes);
    setVal('kpiEntreguesValor', entreguesHoje);

    // Badge na aba pendentes
    const badge = document.getElementById('badgePendentes');
    if (badge) {
        badge.textContent = pendentes;
        badge.style.display = pendentes > 0 ? 'inline-flex' : 'none';
    }
}

function _renderizarTabela() {
    const tbody = document.getElementById('tabelaProtocolos');
    if (!tbody) return;

    let dados = [..._state.protocolos];

    // Filtro por status
    if (_state.filtroStatus) {
        dados = dados.filter(p => p.status === _state.filtroStatus);
    }

    // Filtro por busca
    if (_state.termoBusca) {
        dados = dados.filter(p =>
            (p.unidade_nome || '').toLowerCase().includes(_state.termoBusca) ||
            (p.morador_nome || '').toLowerCase().includes(_state.termoBusca) ||
            (p.descricao_mercadoria || '').toLowerCase().includes(_state.termoBusca) ||
            (p.codigo_nf || '').toLowerCase().includes(_state.termoBusca)
        );
    }

    // Paginação
    const total = dados.length;
    const totalPaginas = Math.ceil(total / _state.itensPorPagina);
    if (_state.paginaAtual > totalPaginas) _state.paginaAtual = 1;
    const inicio = (_state.paginaAtual - 1) * _state.itensPorPagina;
    const paginados = dados.slice(inicio, inicio + _state.itensPorPagina);

    if (paginados.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="10"><i class="fas fa-inbox"></i><p>Nenhum protocolo encontrado</p></td></tr>`;
        document.getElementById('paginacaoListagem').innerHTML = '';
        return;
    }

    tbody.innerHTML = paginados.map(p => `
        <tr>
            <td><strong>#${p.id}</strong></td>
            <td>${_esc(p.unidade_nome || '—')}</td>
            <td>${_esc(p.morador_nome || '—')}</td>
            <td>${_esc(p.descricao_mercadoria)}</td>
            <td>${_esc(p.codigo_nf || '—')}</td>
            <td>${_esc(p.pagina || '—')}</td>
            <td style="white-space:nowrap;">${_formatarDT(p.data_hora_recebimento)}</td>
            <td>${_esc(p.recebedor_portaria)}</td>
            <td><span class="badge badge-${p.status === 'entregue' ? 'entregue' : 'pendente'}">
                <i class="fas fa-${p.status === 'entregue' ? 'check-circle' : 'clock'}"></i>
                ${p.status === 'entregue' ? 'Entregue' : 'Pendente'}
            </span></td>
            <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                    <button class="btn-secondary btn-sm" onclick="window.ProtocolosPage.verDetalhes(${p.id})" title="Ver detalhes">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${p.status === 'pendente' ? `
                        <button class="btn-success btn-sm" onclick="window.ProtocolosPage.abrirModalEntrega(${p.id})" title="Registrar entrega">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn-secondary btn-sm" onclick="window.ProtocolosPage.editarProtocolo(${p.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-danger btn-sm" onclick="window.ProtocolosPage.excluirProtocolo(${p.id})" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : `
                        <span style="font-size:.78rem;color:#16a34a;white-space:nowrap;">
                            <i class="fas fa-check-circle"></i> ${_formatarDT(p.data_hora_entrega)}
                        </span>
                    `}
                </div>
            </td>
        </tr>
    `).join('');

    // Paginação
    _renderizarPaginacao('paginacaoListagem', _state.paginaAtual, totalPaginas, pg => {
        _state.paginaAtual = pg;
        _renderizarTabela();
    });
}

function _renderizarPendentes() {
    const tbody = document.getElementById('tabelaPendentes');
    if (!tbody) return;

    const pendentes = _state.protocolos.filter(p => p.status === 'pendente');

    if (pendentes.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="9"><i class="fas fa-check-circle" style="color:#16a34a;font-size:2rem;"></i><p>Nenhum protocolo pendente</p></td></tr>`;
        return;
    }

    const agora = new Date();

    tbody.innerHTML = pendentes.map(p => {
        const receb = new Date(p.data_hora_recebimento);
        const diffHoras = Math.floor((agora - receb) / 3600000);
        let tempoClass = 'tempo-normal';
        let tempoTexto = '';
        if (diffHoras < 24) {
            tempoTexto = `${diffHoras}h`;
            tempoClass = diffHoras >= 12 ? 'tempo-atencao' : 'tempo-normal';
        } else {
            const dias = Math.floor(diffHoras / 24);
            tempoTexto = `${dias} dia${dias > 1 ? 's' : ''}`;
            tempoClass = dias >= 3 ? 'tempo-critico' : 'tempo-atencao';
        }

        return `
            <tr>
                <td><strong>#${p.id}</strong></td>
                <td>${_esc(p.unidade_nome || '—')}</td>
                <td>${_esc(p.morador_nome || '—')}</td>
                <td>${_esc(p.descricao_mercadoria)}</td>
                <td>${_esc(p.codigo_nf || '—')}</td>
                <td style="white-space:nowrap;">${_formatarDT(p.data_hora_recebimento)}</td>
                <td>${_esc(p.recebedor_portaria)}</td>
                <td><span class="${tempoClass}">${tempoTexto}</span></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <button class="btn-success btn-sm" onclick="window.ProtocolosPage.abrirModalEntrega(${p.id})" title="Registrar entrega">
                            <i class="fas fa-check"></i> Entregar
                        </button>
                        <button class="btn-secondary btn-sm" onclick="window.ProtocolosPage.editarProtocolo(${p.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function _renderizarPaginacao(containerId, paginaAtual, totalPaginas, onChangePage) {
    const container = document.getElementById(containerId);
    if (!container || totalPaginas <= 1) {
        if (container) container.innerHTML = '';
        return;
    }

    let html = '';
    html += `<button ${paginaAtual === 1 ? 'disabled' : ''} data-pg="${paginaAtual - 1}">‹</button>`;
    for (let i = 1; i <= totalPaginas; i++) {
        if (i === 1 || i === totalPaginas || Math.abs(i - paginaAtual) <= 2) {
            html += `<button class="${i === paginaAtual ? 'active' : ''}" data-pg="${i}">${i}</button>`;
        } else if (Math.abs(i - paginaAtual) === 3) {
            html += `<button disabled>…</button>`;
        }
    }
    html += `<button ${paginaAtual === totalPaginas ? 'disabled' : ''} data-pg="${paginaAtual + 1}">›</button>`;
    container.innerHTML = html;

    container.querySelectorAll('button[data-pg]').forEach(btn => {
        btn.addEventListener('click', () => onChangePage(parseInt(btn.dataset.pg)));
    });
}

// ========== HISTÓRICO ==========

function _setupHistoricoDefault() {
    const hoje = new Date();
    const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    const setData = (id, d) => {
        const el = document.getElementById(id);
        if (el) el.value = d.toISOString().split('T')[0];
    };
    setData('histDataInicio', primeiroDia);
    setData('histDataFim', hoje);
}

function _filtrarHistorico() {
    const inicio = document.getElementById('histDataInicio')?.value;
    const fim = document.getElementById('histDataFim')?.value;

    if (!inicio || !fim) {
        _toast('Selecione o período para filtrar', 'warning');
        return;
    }

    const dataInicio = new Date(inicio + 'T00:00:00');
    const dataFim = new Date(fim + 'T23:59:59');

    const filtrados = _state.protocolos.filter(p => {
        const dt = new Date(p.data_hora_recebimento);
        return dt >= dataInicio && dt <= dataFim;
    });

    // Renderizar tabela
    const tbody = document.getElementById('tabelaHistorico');
    if (!tbody) return;

    if (filtrados.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="9"><i class="fas fa-inbox"></i><p>Nenhum protocolo no período selecionado</p></td></tr>`;
        document.getElementById('statsHistorico').style.display = 'none';
        return;
    }

    tbody.innerHTML = filtrados.map(p => `
        <tr>
            <td><strong>#${p.id}</strong></td>
            <td>${_esc(p.unidade_nome || '—')}</td>
            <td>${_esc(p.morador_nome || '—')}</td>
            <td>${_esc(p.descricao_mercadoria)}</td>
            <td style="white-space:nowrap;">${_formatarDT(p.data_hora_recebimento)}</td>
            <td>${_esc(p.recebedor_portaria)}</td>
            <td><span class="badge badge-${p.status === 'entregue' ? 'entregue' : 'pendente'}">
                ${p.status === 'entregue' ? 'Entregue' : 'Pendente'}
            </span></td>
            <td style="white-space:nowrap;">${p.data_hora_entrega ? _formatarDT(p.data_hora_entrega) : '—'}</td>
            <td>${_esc(p.nome_recebedor_morador || '—')}</td>
        </tr>
    `).join('');

    // KPIs do período
    const entregues = filtrados.filter(p => p.status === 'entregue');
    const pendentes = filtrados.filter(p => p.status === 'pendente');

    // Calcular média de dias para entrega
    let mediaDias = '—';
    if (entregues.length > 0) {
        const totalMs = entregues.reduce((acc, p) => {
            if (!p.data_hora_entrega) return acc;
            return acc + (new Date(p.data_hora_entrega) - new Date(p.data_hora_recebimento));
        }, 0);
        const mediaHoras = totalMs / entregues.length / 3600000;
        mediaDias = mediaHoras < 24 ? `${Math.round(mediaHoras)}h` : `${(mediaHoras / 24).toFixed(1)}d`;
    }

    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setVal('statTotal', filtrados.length);
    setVal('statEntregues', entregues.length);
    setVal('statPendentes', pendentes.length);
    setVal('statMediaDias', mediaDias);

    document.getElementById('statsHistorico').style.display = 'grid';
}

function _exportarHistoricoCSV() {
    const inicio = document.getElementById('histDataInicio')?.value;
    const fim = document.getElementById('histDataFim')?.value;
    if (!inicio || !fim) { _toast('Filtre o período antes de exportar', 'warning'); return; }

    const dataInicio = new Date(inicio + 'T00:00:00');
    const dataFim = new Date(fim + 'T23:59:59');
    const filtrados = _state.protocolos.filter(p => {
        const dt = new Date(p.data_hora_recebimento);
        return dt >= dataInicio && dt <= dataFim;
    });

    _gerarCSV(filtrados, `historico_protocolos_${inicio}_${fim}`);
}

// ========== CRUD ==========

function _salvarProtocolo() {
    const id = document.getElementById('protocoloId')?.value;
    const dados = {
        unidade_id: document.getElementById('unidadeId')?.value,
        morador_id: document.getElementById('moradorId')?.value,
        descricao_mercadoria: document.getElementById('descricaoMercadoria')?.value?.trim(),
        codigo_nf: document.getElementById('codigoNf')?.value?.trim(),
        pagina: document.getElementById('pagina')?.value,
        data_hora_recebimento: document.getElementById('dataHoraRecebimento')?.value,
        recebedor_portaria: document.getElementById('recebedorPortaria')?.value,
        observacao: document.getElementById('observacao')?.value?.trim()
    };

    // Validações básicas
    if (!dados.unidade_id) { _toast('Selecione a unidade', 'warning'); return; }
    if (!dados.morador_id) { _toast('Selecione o morador', 'warning'); return; }
    if (!dados.descricao_mercadoria) { _toast('Informe a descrição da mercadoria', 'warning'); return; }
    if (!dados.recebedor_portaria) { _toast('Selecione o recebedor da portaria', 'warning'); return; }

    const metodo = id ? 'PUT' : 'POST';
    if (id) dados.id = parseInt(id);

    const btnSalvar = document.getElementById('btnSalvarProtocolo');
    if (btnSalvar) { btnSalvar.disabled = true; btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    fetch(`${API_BASE}/api_protocolos.php`, {
        method: metodo,
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                _toast(id ? 'Protocolo atualizado com sucesso!' : 'Protocolo registrado com sucesso!', 'success');
                _limparFormulario();
                _carregarProtocolos();
            } else {
                _toast(data.mensagem || 'Erro ao salvar protocolo', 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao salvar:', err);
            _toast('Erro ao conectar com o servidor', 'error');
        })
        .finally(() => {
            if (btnSalvar) { btnSalvar.disabled = false; btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Protocolo'; }
        });
}

function _editarProtocolo(id) {
    const p = _state.protocolos.find(x => x.id == id);
    if (!p) return;

    if (p.status === 'entregue') {
        _toast('Não é possível editar protocolo já entregue', 'error');
        return;
    }

    // Preencher formulário
    document.getElementById('protocoloId').value = p.id;
    document.getElementById('unidadeId').value = p.unidade_id;
    document.getElementById('descricaoMercadoria').value = p.descricao_mercadoria;
    document.getElementById('codigoNf').value = p.codigo_nf || '';
    document.getElementById('pagina').value = p.pagina || '';
    document.getElementById('dataHoraRecebimento').value = p.data_hora_recebimento.replace(' ', 'T').slice(0, 16);
    document.getElementById('recebedorPortaria').value = p.recebedor_portaria;
    document.getElementById('observacao').value = p.observacao || '';

    // Atualizar título do form
    const titulo = document.getElementById('formTitulo');
    if (titulo) titulo.innerHTML = `<i class="fas fa-edit" style="color:#2563eb;margin-right:8px;"></i>Editando Protocolo #${p.id}`;

    // Carregar moradores e selecionar o correto
    _carregarMoradores();
    setTimeout(() => {
        const moradorSel = document.getElementById('moradorId');
        if (moradorSel) moradorSel.value = p.morador_id;
    }, 1000);

    // Navegar para aba de novo/edição
    _ativarTab('novo');

    // Scroll suave ao topo
    window.scrollTo({ top: 0, behavior: 'smooth' });
    _toast(`Editando protocolo #${p.id}`, 'info');
}

function _excluirProtocolo(id) {
    const p = _state.protocolos.find(x => x.id == id);
    if (!p) return;

    if (p.status === 'entregue') {
        _toast('Não é possível excluir protocolo já entregue', 'error');
        return;
    }

    if (!confirm(`Deseja realmente excluir o protocolo #${id}?\n"${p.descricao_mercadoria}" — ${p.unidade_nome || ''}`)) return;

    fetch(`${API_BASE}/api_protocolos.php`, {
        method: 'DELETE',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(id) })
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                _toast('Protocolo excluído com sucesso!', 'success');
                _carregarProtocolos();
            } else {
                _toast(data.mensagem || 'Erro ao excluir protocolo', 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao excluir:', err);
            _toast('Erro ao conectar com o servidor', 'error');
        });
}

// ========== MODAL ENTREGA ==========

function _abrirModalEntrega(id) {
    const p = _state.protocolos.find(x => x.id == id);
    if (!p) return;

    document.getElementById('entregaProtocoloId').value = id;

    // Preencher info do protocolo no modal
    const infoTexto = document.getElementById('infoEntregaTexto');
    if (infoTexto) {
        infoTexto.innerHTML = `
            <strong>Protocolo #${p.id}</strong> — ${_esc(p.descricao_mercadoria)}<br>
            <span style="font-size:.85rem;">Unidade: <strong>${_esc(p.unidade_nome || '—')}</strong> | 
            Morador: <strong>${_esc(p.morador_nome || '—')}</strong></span>
        `;
    }

    // Preencher data/hora atual
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    const dtEl = document.getElementById('dataHoraEntrega');
    if (dtEl) dtEl.value = agora.toISOString().slice(0, 16);

    // Limpar campo de recebedor
    const recebEl = document.getElementById('nomeRecebedorMorador');
    if (recebEl) recebEl.value = '';

    document.getElementById('modalEntrega').classList.add('show');
}

function _fecharModalEntrega() {
    const modal = document.getElementById('modalEntrega');
    if (modal) modal.classList.remove('show');
    const form = document.getElementById('formEntrega');
    if (form) form.reset();
}

function _registrarEntrega() {
    const id = document.getElementById('entregaProtocoloId')?.value;
    const nomeRecebedor = document.getElementById('nomeRecebedorMorador')?.value?.trim();
    const dataHoraEntrega = document.getElementById('dataHoraEntrega')?.value;

    if (!nomeRecebedor) { _toast('Informe o nome de quem retirou', 'warning'); return; }
    if (!dataHoraEntrega) { _toast('Informe a data e hora da entrega', 'warning'); return; }

    const btnConfirmar = document.getElementById('btnConfirmarEntrega');
    if (btnConfirmar) { btnConfirmar.disabled = true; btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando...'; }

    const dados = {
        acao: 'entregar',
        id: parseInt(id),
        nome_recebedor_morador: nomeRecebedor,
        data_hora_entrega: dataHoraEntrega
    };

    fetch(`${API_BASE}/api_protocolos.php`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    })
        .then(r => r.json())
        .then(data => {
            if (data.sucesso) {
                _toast('Entrega registrada com sucesso!', 'success');
                _fecharModalEntrega();
                _carregarProtocolos();
            } else {
                _toast(data.mensagem || 'Erro ao registrar entrega', 'error');
            }
        })
        .catch(err => {
            console.error('[Protocolos] Erro ao registrar entrega:', err);
            _toast('Erro ao conectar com o servidor', 'error');
        })
        .finally(() => {
            if (btnConfirmar) { btnConfirmar.disabled = false; btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Confirmar Entrega'; }
        });
}

// ========== MODAL DETALHES ==========

function _verDetalhes(id) {
    const p = _state.protocolos.find(x => x.id == id);
    if (!p) return;

    const conteudo = document.getElementById('detalhesConteudo');
    if (!conteudo) return;

    conteudo.innerHTML = `
        <div class="detalhe-grid">
            <div class="detalhe-item">
                <label>Protocolo</label>
                <span>#${p.id}</span>
            </div>
            <div class="detalhe-item">
                <label>Status</label>
                <span><span class="badge badge-${p.status === 'entregue' ? 'entregue' : 'pendente'}">
                    ${p.status === 'entregue' ? 'Entregue' : 'Pendente'}
                </span></span>
            </div>
            <div class="detalhe-item">
                <label>Unidade</label>
                <span>${_esc(p.unidade_nome || '—')}</span>
            </div>
            <div class="detalhe-item">
                <label>Morador</label>
                <span>${_esc(p.morador_nome || '—')}</span>
            </div>
            <div class="detalhe-item" style="grid-column:span 2;">
                <label>Mercadoria</label>
                <span>${_esc(p.descricao_mercadoria)}</span>
            </div>
            <div class="detalhe-item">
                <label>Código / NF</label>
                <span>${_esc(p.codigo_nf || '—')}</span>
            </div>
            <div class="detalhe-item">
                <label>Página (Livro)</label>
                <span>${_esc(p.pagina || '—')}</span>
            </div>
            <div class="detalhe-item">
                <label>Data Recebimento</label>
                <span>${_formatarDT(p.data_hora_recebimento)}</span>
            </div>
            <div class="detalhe-item">
                <label>Recebedor (Portaria)</label>
                <span>${_esc(p.recebedor_portaria)}</span>
            </div>
            ${p.status === 'entregue' ? `
            <div class="detalhe-item">
                <label>Data Entrega</label>
                <span>${_formatarDT(p.data_hora_entrega)}</span>
            </div>
            <div class="detalhe-item">
                <label>Recebedor (Morador)</label>
                <span>${_esc(p.nome_recebedor_morador || '—')}</span>
            </div>
            ` : ''}
            ${p.observacao ? `
            <div class="detalhe-item" style="grid-column:span 2;">
                <label>Observação</label>
                <span>${_esc(p.observacao)}</span>
            </div>
            ` : ''}
        </div>
    `;

    document.getElementById('modalDetalhes').classList.add('show');
}

function _fecharModalDetalhes() {
    const modal = document.getElementById('modalDetalhes');
    if (modal) modal.classList.remove('show');
}

// ========== UTILITÁRIOS ==========

function _limparFormulario() {
    const form = document.getElementById('formProtocolo');
    if (form) form.reset();
    const idEl = document.getElementById('protocoloId');
    if (idEl) idEl.value = '';
    const moradorSel = document.getElementById('moradorId');
    if (moradorSel) {
        moradorSel.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        moradorSel.disabled = true;
    }
    const titulo = document.getElementById('formTitulo');
    if (titulo) titulo.innerHTML = `<i class="fas fa-plus-circle" style="color:#2563eb;margin-right:8px;"></i>Novo Protocolo`;
    _definirDataHoraAtual();
    _state.editandoId = null;
}

function _definirDataHoraAtual() {
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    const el = document.getElementById('dataHoraRecebimento');
    if (el) el.value = agora.toISOString().slice(0, 16);
}

function _exportarCSV() {
    let dados = [..._state.protocolos];
    if (_state.filtroStatus) dados = dados.filter(p => p.status === _state.filtroStatus);
    if (_state.termoBusca) {
        dados = dados.filter(p =>
            (p.unidade_nome || '').toLowerCase().includes(_state.termoBusca) ||
            (p.morador_nome || '').toLowerCase().includes(_state.termoBusca) ||
            (p.descricao_mercadoria || '').toLowerCase().includes(_state.termoBusca)
        );
    }
    _gerarCSV(dados, 'protocolos');
}

function _gerarCSV(dados, nomeArquivo) {
    if (dados.length === 0) { _toast('Nenhum dado para exportar', 'warning'); return; }

    const cabecalho = ['ID', 'Unidade', 'Morador', 'Mercadoria', 'Código/NF', 'Página', 'Recebimento', 'Porteiro', 'Status', 'Entrega', 'Recebedor Morador'];
    const linhas = dados.map(p => [
        p.id, p.unidade_nome || '', p.morador_nome || '', p.descricao_mercadoria,
        p.codigo_nf || '', p.pagina || '', _formatarDT(p.data_hora_recebimento),
        p.recebedor_portaria, p.status === 'entregue' ? 'Entregue' : 'Pendente',
        p.data_hora_entrega ? _formatarDT(p.data_hora_entrega) : '',
        p.nome_recebedor_morador || ''
    ].map(v => `"${String(v).replace(/"/g, '""')}"`));

    const csv = '\uFEFF' + [cabecalho.join(';'), ...linhas.map(l => l.join(';'))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${nomeArquivo}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    _toast('CSV exportado com sucesso!', 'success');
}

function _formatarDT(dt) {
    if (!dt) return '—';
    try {
        return new Date(dt).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch { return dt; }
}

function _esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;');
}

function _toast(msg, tipo = 'info') {
    const container = document.getElementById('toastContainerProtocolo');
    if (!container) return;

    const iconMap = { success: 'check-circle', error: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
    const div = document.createElement('div');
    div.className = `toast-proto ${tipo}`;
    div.innerHTML = `<i class="fas fa-${iconMap[tipo] || 'info-circle'}"></i> ${_esc(msg)}`;
    container.appendChild(div);

    setTimeout(() => {
        div.style.opacity = '0';
        div.style.transform = 'translateX(30px)';
        div.style.transition = 'all 0.3s';
        setTimeout(() => div.remove(), 300);
    }, 4000);
}
