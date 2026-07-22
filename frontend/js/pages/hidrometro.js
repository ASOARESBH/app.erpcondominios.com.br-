/**
 * Hidrometro Page Module
 * 
 * Gerencia o CRUD completo de hidrômetros:
 *  - Cadastro com seleção de unidade → morador
 *  - Listagem com busca e filtros
 *  - Edição com registro de histórico
 *  - Visualização do histórico de alterações
 * 
 * @module hidrometro
 * @version 2.0.0
 */

'use strict';

// ============================================================
// CONSTANTES
// ============================================================
const API_HIDROMETROS    = window.location.origin + '/api/api_hidrometros.php';
const API_UNIDADES       = window.location.origin + '/api/api_unidades.php';
const API_MORADORES      = window.location.origin + '/api/api_moradores.php';
const API_LEITURAS       = window.location.origin + '/api/api_leituras.php';
const API_CONFIG_PERIODO = window.location.origin + '/api/api_config_periodo_leitura.php';

// Tarifas de água
const VALOR_M3       = 6.16;
const VALOR_MINIMO   = 61.60;
const CONSUMO_MINIMO = 10;
const ITENS_POR_PAG  = 20;
// ============================================================
// ESTADO DO MÓDULO
// ============================================================

let _state = {
    hidrometros      : [],   // lista completa (após ordenação)
    unidades         : [],
    moradores        : [],
    buscarTimer      : null,
    currentTab       : 'cadastro',
    currentSubTab    : 'individual',
    // Paginação hidrômetros
    currentPage      : 1,
    perPage          : 20,
    // Leituras coletivas
    hidrometrosAtivos: [],
    paginaAtual      : 1,
    totalPaginas     : 1,
    // Rascunho persistente da leitura coletiva: hidrometro_id -> { leitura, selecionado }
    // Sobrevive à troca de página — só é limpo ao lançar com sucesso ou clicar "Limpar Seleção"
    leituraColetivaDraft: new Map(),
    // Cache dos hidrômetros do morador selecionado na leitura individual
    // (usado para exibir Nº hidrômetro/lacre no info-box sem nova chamada à API)
    indHidrometrosCache: [],
    // Última lista carregada no Histórico de Leituras (usada pelo visualizador
    // de fotos para exibir data/unidade/leitura sem precisar refazer a consulta)
    historicoCache   : [],
    // Referência ao handler para remoção no destroy()
    _modalClickRef   : null,
};

// ============================================================
// LIFECYCLE
// ============================================================

export function init() {
    console.log('[Hidrometro] Inicializando módulo v2.0...');

    _setupTabs();
    _setupSubTabs();
    _setupForms();
    _setupFormsLeitura();
    _setDataAtual();
    _setDataAtual('ind_data_leitura');
    _setDataAtual('col_data_leitura');
    _carregarUnidades();
    _carregarHidrometros();
    leituraCarregarConfigPeriodo();

    // Listener para fechar modal ao clicar fora — registrado aqui para
    // poder ser removido no destroy() e evitar acúmulo de listeners
    _state._modalClickRef = e => {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('show');
            document.body.style.overflow = '';
            // Clicar fora da modal da câmera precisa liberar o dispositivo,
            // senão a câmera continua ativa mesmo com a modal fechada
            if (e.target.id === 'modalFotoCaptura') _fotoPararCamera();
        }
        // Fechar dropdown de patrimônio (cadastro) ao clicar fora
        const dropdown = document.getElementById('patrimonioDropdown');
        const inputBusca = document.getElementById('cad_patrimonio_busca');
        if (dropdown && !dropdown.contains(e.target) && e.target !== inputBusca) {
            dropdown.style.display = 'none';
        }
        // Fechar dropdown de patrimônio (edição) ao clicar fora
        const dropdownEdit = document.getElementById('editPatrimonioDropdown');
        const inputBuscaEdit = document.getElementById('edit_patrimonio_busca');
        if (dropdownEdit && !dropdownEdit.contains(e.target) && e.target !== inputBuscaEdit) {
            dropdownEdit.style.display = 'none';
        }
    };
    document.addEventListener('click', _state._modalClickRef);

    // Expõe API pública para onclick inline
    window.HidrometroPage = {
        // Hidrômetros
        buscar              : buscar,
        buscarDebounce      : buscarDebounce,
        limparBusca         : limparBusca,
        limparCadastro      : limparCadastro,
        editar              : abrirModalEditar,
        verHistorico        : abrirModalHistorico,
        fecharModal         : fecharModal,
        salvarEdicao        : salvarEdicao,
        buscarPatrimonio        : buscarPatrimonio,
        limparPatrimonio        : limparPatrimonio,
        selecionarPatrimonio    : selecionarPatrimonio,
        buscarPatrimonioEdit    : buscarPatrimonioEdit,
        limparPatrimonioEdit    : limparPatrimonioEdit,
        selecionarPatrimonioEdit: selecionarPatrimonioEdit,
        irParaPagina        : irParaPagina,
        alterarPerPage      : alterarPerPage,
        // Leituras
        calcularPreview         : leituraCalcularPreview,
        limparIndividual        : leituraLimparIndividual,
        carregarColetiva        : leituraCarregarHidrometrosAtivos,
        selecionarTodos         : leituraSelecionarTodos,
        lancarSelecionados      : leituraLancarSelecionados,
        limparSelecao           : leituraLimparSelecao,
        mudarPagina             : leituraMudarPagina,
        colAtualizarValor       : leituraColetivaAtualizarValor,
        colAtualizarSelecao     : leituraColetivaAtualizarSelecao,
        buscarHistorico         : leituraBuscarHistorico,
        carregarConfigPeriodo   : leituraCarregarConfigPeriodo,
        // Relatórios
        relSelecionarTipo       : relSelecionarTipo,
        relGerar                : relGerar,
        relExportarCSV          : relExportarCSV,
        relExportarPDF          : relExportarPDF,
        relAnaliticoFiltrarBusca    : relAnaliticoFiltrarBusca,
        relAnaliticoOrdenarColuna   : _relAnaliticoOrdenarClique,
        relAnaliticoPaginaAnterior  : relAnaliticoPaginaAnterior,
        relAnaliticoProximaPagina   : relAnaliticoProximaPagina,
        // Demonstrativo de água
        gerarDemonstrativo      : gerarDemonstrativo,
        // Evidência fotográfica das leituras
        fotoAbrirMenuIndividual : fotoAbrirMenuIndividual,
        fotoAbrirMenuColetiva   : fotoAbrirMenuColetiva,
        fotoFecharMenu          : fotoFecharMenu,
        fotoEscolherOpcao       : fotoEscolherOpcao,
        fotoInputArquivoChange  : fotoInputArquivoChange,
        fotoCapturarFoto        : fotoCapturarFoto,
        fotoRefazer             : fotoRefazer,
        fotoConfirmar           : fotoConfirmar,
        fotoCancelar            : fotoCancelar,
        fotoRemoverIndividual   : fotoRemoverIndividual,
        fotoRemoverColetiva     : fotoRemoverColetiva,
        fotoAbrirVisualizadorLeitura : fotoAbrirVisualizadorLeitura,
        fotoAbrirGaleriaHidrometro   : fotoAbrirGaleriaHidrometro,
        fotoGaleriaAnterior     : fotoGaleriaAnterior,
        fotoGaleriaProxima      : fotoGaleriaProxima,
        fotoAlternarZoom        : fotoAlternarZoom,
        fotoVisualizadorDownload: fotoVisualizadorDownload,
        fotoVisualizadorImprimir: fotoVisualizadorImprimir,
    };

    console.log('[Hidrometro] Módulo pronto.');
}

export function destroy() {
    console.log('[Hidrometro] Destruindo módulo...');
    if (_state.buscarTimer) clearTimeout(_state.buscarTimer);
    if (_state._modalClickRef) {
        document.removeEventListener('click', _state._modalClickRef);
    }
    document.body.style.overflow = '';
    delete window.HidrometroPage;
    _state = {
        hidrometros: [], unidades: [], moradores: [], buscarTimer: null,
        currentTab: 'cadastro', currentSubTab: 'individual',
        currentPage: 1, perPage: 20,
        hidrometrosAtivos: [], paginaAtual: 1, totalPaginas: 1,
        leituraColetivaDraft: new Map(),
        indHidrometrosCache: [],
        historicoCache: [],
        _modalClickRef: null
    };
    _fotoPararCamera();
}

// ============================================================
// TABS
// ============================================================

function _setupTabs() {
    // Tabs principais (data-tab) — seletor amplo para funcionar com layout-base
    document.querySelectorAll('.page-hidrometro .tabs .tab-button[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => _switchTab(btn.dataset.tab));
    });
}

function _setupSubTabs() {
    // Sub-tabs de leituras (data-subtab)
    document.querySelectorAll('.page-hidrometro .tab-button[data-subtab]').forEach(btn => {
        btn.addEventListener('click', () => _switchSubTab(btn.dataset.subtab));
    });
}

function _switchTab(tabName) {
    // Atualiza botões das tabs principais
    document.querySelectorAll('.page-hidrometro .tabs .tab-button[data-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    // Alterna painéis de conteúdo de primeiro nível (não sub-tabs)
    document.querySelectorAll('.page-hidrometro .tab-content[id^="tab-"]').forEach(content => {
        if (!content.closest('.subtabs-content')) {
            content.classList.toggle('active', content.id === `tab-${tabName}`);
        }
    });
    _state.currentTab = tabName;

    if (tabName === 'lista') {
        // _state.unidades já foi carregado em init() — só popular o select do filtro aqui
        _carregarUnidadesLeitura();
        _carregarHidrometros();
    }
    if (tabName === 'leituras') {
        _carregarUnidadesLeitura();
        if (_state.currentSubTab === 'configuracoes') {
            leituraCarregarConfigPeriodo();
        }
    }
    if (tabName === 'relatorios') {
        // _state.unidades já foi carregado em init() — só popular o select aqui.
        // O relatório só é gerado depois que o usuário escolhe um cartão (relSelecionarTipo).
        _carregarUnidadesLeitura();
    }
}

function _switchSubTab(subTabName) {
    document.querySelectorAll('.page-hidrometro .tab-button[data-subtab]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.subtab === subTabName);
    });
    document.querySelectorAll('.page-hidrometro .subtab-content').forEach(content => {
        content.classList.toggle('active', content.id === `subtab-${subTabName}`);
    });
    _state.currentSubTab = subTabName;

    if (subTabName === 'coletiva' && _state.hidrometrosAtivos.length === 0) {
        leituraCarregarHidrometrosAtivos();
    }
    if (subTabName === 'configuracoes') {
        leituraCarregarConfigPeriodo();
    }
}

// ============================================================
// FORMULÁRIOS
// ============================================================

function _setupForms() {
    // Cadastro
    const formCadastro = document.getElementById('formCadastro');
    if (formCadastro) {
        formCadastro.addEventListener('submit', e => {
            e.preventDefault();
            _salvarHidrometro();
        });
    }

    // Unidade → Moradores (cadastro)
    const selUnidade = document.getElementById('cad_unidade');
    if (selUnidade) {
        selUnidade.addEventListener('change', () => _carregarMoradoresPorUnidade('cad'));
    }

    // Unidade → Moradores (edição)
    const selEditUnidade = document.getElementById('edit_unidade');
    if (selEditUnidade) {
        selEditUnidade.addEventListener('change', () => _carregarMoradoresPorUnidade('edit'));
    }
}

// ============================================================
// FORMULÁRIOS DE LEITURA
// ============================================================

function _setupFormsLeitura() {
    // Form leitura individual
    const formInd = document.getElementById('formIndividual');
    if (formInd) {
        formInd.addEventListener('submit', e => { e.preventDefault(); _leituraSalvarIndividual(); });
    }

    // Form configuração de período
    const formConfig = document.getElementById('formConfigPeriodo');
    if (formConfig) {
        formConfig.addEventListener('submit', e => { e.preventDefault(); _leituraSalvarConfigPeriodo(); });
    }

    // Cascata: unidade → morador (leitura individual)
    const selUnidadeLeit = document.getElementById('ind_unidade');
    if (selUnidadeLeit) {
        selUnidadeLeit.addEventListener('change', _leituraCarregarMoradores);
    }

    // Cascata: morador → hidrômetro
    const selMoradorLeit = document.getElementById('ind_morador');
    if (selMoradorLeit) {
        selMoradorLeit.addEventListener('change', _leituraCarregarHidrometrosMorador);
    }

    // Hidrômetro → última leitura
    const selHidroLeit = document.getElementById('ind_hidrometro');
    if (selHidroLeit) {
        selHidroLeit.addEventListener('change', _leituraCarregarUltimaLeitura);
    }
}

async function _carregarUnidadesLeitura() {
    // Popula os selects de unidade da aba Leituras com ordenação numérica
    const isAdm = str => /adm/i.test(str || '');
    const numKey = str => { const m = String(str).match(/(\d+)/); return m ? parseInt(m[1], 10) : 0; };

    const ordenadas = [..._state.unidades].sort((a, b) => {
        const nA = String(a.unidade || a.nome || a).trim();
        const nB = String(b.unidade || b.nome || b).trim();
        if (isAdm(nA) && !isAdm(nB)) return -1;
        if (!isAdm(nA) && isAdm(nB)) return  1;
        return numKey(nA) - numKey(nB);
    });

    ['ind_unidade', 'hist_unidade', 'rel_unidade', 'filtro_unidade'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const valorAtual  = sel.value;
        const placeholder = id === 'ind_unidade' ? 'Selecione uma unidade...' : 'Todas as unidades';
        sel.innerHTML = `<option value="">${placeholder}</option>`;
        ordenadas.forEach(u => {
            const val = u.unidade || u.nome || u;
            sel.add(new Option(val, val));
        });
        if (valorAtual) sel.value = valorAtual;
    });
}

// ============================================================
// DATA ATUAL
// ============================================================

function _setDataAtual(campoId = 'cad_data') {
    const campo = document.getElementById(campoId);
    if (!campo) return;
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    campo.value = agora.toISOString().slice(0, 16);
}

// ============================================================
// CARREGAMENTO DE DADOS
// ============================================================

async function _carregarUnidades() {
    console.log('[Hidrometro] Carregando unidades...');
    try {
        const data = await _apiCall(API_UNIDADES + '?acao=select');
        if (!data.sucesso) throw new Error(data.mensagem);

        _state.unidades = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
        _popularSelectUnidades('cad_unidade');
        _popularSelectUnidades('edit_unidade');
        console.log(`[Hidrometro] ${_state.unidades.length} unidades carregadas.`);
    } catch (err) {
        console.error('[Hidrometro] Erro ao carregar unidades:', err);
        _toast('Erro ao carregar unidades: ' + err.message, 'error');
    }
}

function _popularSelectUnidades(selectId) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const valorAtual = sel.value;
    sel.innerHTML = '<option value="">Selecione uma unidade...</option>';

    // Ordena: ADMINISTRATIVO primeiro, depois numericamente (Gleba 1, 2, 3...)
    const isAdm      = str => /adm/i.test(str || '');
    const numericKey = str => { const m = (str || '').match(/(\d+)/); return m ? parseInt(m[1], 10) : Infinity; };

    const ordenadas = [..._state.unidades].sort((a, b) => {
        const nomeA = a.unidade || a.nome || a;
        const nomeB = b.unidade || b.nome || b;
        const admA  = isAdm(nomeA);
        const admB  = isAdm(nomeB);
        if (admA && !admB) return -1;
        if (!admA && admB) return  1;
        const nA = numericKey(nomeA);
        const nB = numericKey(nomeB);
        if (nA !== nB) return nA - nB;
        return nomeA.localeCompare(nomeB, 'pt-BR', { numeric: true });
    });

    ordenadas.forEach(u => {
        const nome = u.unidade || u.nome || u;
        const opt  = new Option(nome, nome);
        sel.add(opt);
    });
    if (valorAtual) sel.value = valorAtual;
}

async function _carregarMoradoresPorUnidade(prefixo) {
    const unidade = document.getElementById(`${prefixo}_unidade`)?.value;
    const selMorador = document.getElementById(`${prefixo}_morador`);
    if (!selMorador) return;

    selMorador.innerHTML = '<option value="">Carregando...</option>';
    selMorador.disabled = true;

    if (!unidade) {
        selMorador.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        return;
    }

    try {
        const data = await _apiCall(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`);
        selMorador.innerHTML = '<option value="">Selecione um morador...</option>';
        // api_moradores retorna dados paginados: { itens: [...], total, ... }
        const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        if (data.sucesso && moradores.length > 0) {
            moradores.forEach(m => {
                const opt = new Option(m.nome, m.id);
                selMorador.add(opt);
            });
            selMorador.disabled = false;
        } else {
            selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
        }
    } catch (err) {
        console.error('[Hidrometro] Erro ao carregar moradores:', err);
        selMorador.innerHTML = '<option value="">Erro ao carregar moradores</option>';
    }
}

async function _carregarHidrometros() {
    console.log('[Hidrometro] Carregando hidrômetros...');
    const loading = document.getElementById('loadingLista');
    const tbody   = document.getElementById('listaHidrometros');

    if (loading) loading.style.display = 'block';
    if (tbody)   tbody.innerHTML = '';

    try {
        // Pesquisa avançada: todos os filtros preenchidos são combinados em uma única requisição
        const busca       = document.getElementById('busca')?.value?.trim()               || '';
        const status      = document.getElementById('filtro_status')?.value               || '';
        const unidade     = document.getElementById('filtro_unidade')?.value              || '';
        const dataInicial = document.getElementById('filtro_data_inicial')?.value         || '';
        const dataFinal   = document.getElementById('filtro_data_final')?.value           || '';

        const params = new URLSearchParams();
        if (busca)       params.set('busca', busca);
        if (status)      params.set('status', status);
        if (unidade)     params.set('unidade', unidade);
        if (dataInicial) params.set('data_inicial', dataInicial);
        if (dataFinal)   params.set('data_final', dataFinal);

        const url = API_HIDROMETROS + (params.toString() ? `?${params.toString()}` : '');

        const data = await _apiCall(url);
        if (!data.sucesso) throw new Error(data.mensagem);

        _state.hidrometros = _ordenarHidrometros(data.dados || []);
        _state.currentPage = 1;  // reset página ao recarregar
        _renderTabela(_state.hidrometros);
        _atualizarKPIs(_state.hidrometros);
        console.log(`[Hidrometro] ${_state.hidrometros.length} hidrômetros carregados.`);
    } catch (err) {
        console.error('[Hidrometro] Erro ao carregar hidrômetros:', err);
        if (tbody) {
            tbody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="9">
                        <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                        <p>Erro ao carregar dados: ${err.message}</p>
                    </td>
                </tr>`;
        }
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

// ============================================================
// ORDENAÇÃO
// ============================================================

/**
 * Ordena a lista de hidrômetros:
 *  1º Unidades que contêm "adm" ou "administrativo" (case-insensitive)
 *  2º Demais unidades, ordenadas numericamente (extraí o primeiro número encontrado)
 *     e alfabética como desempate.
 */
function _ordenarHidrometros(lista) {
    const isAdm = str => /adm/i.test(str || '');

    // Extrai o primeiro número de uma string para ordenação numérica
    const numericKey = str => {
        const m = (str || '').match(/(\d+)/);
        return m ? parseInt(m[1], 10) : Infinity;
    };

    return [...lista].sort((a, b) => {
        const admA = isAdm(a.unidade);
        const admB = isAdm(b.unidade);

        // Administrativos sempre primeiro
        if (admA && !admB) return -1;
        if (!admA && admB) return  1;

        // Ambos administrativos ou ambos normais: ordenação numérica
        const nA = numericKey(a.unidade);
        const nB = numericKey(b.unidade);
        if (nA !== nB) return nA - nB;

        // Desempate alfabético
        return (a.unidade || '').localeCompare(b.unidade || '', 'pt-BR', { numeric: true });
    });
}

// ============================================================
// RENDER TABELA (com paginação)
// ============================================================

function _renderTabela(lista) {
    const tbody = document.getElementById('listaHidrometros');
    if (!tbody) return;

    if (!lista || lista.length === 0) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="9">
                    <i class="fas fa-tint"></i>
                    <p>Nenhum hidrômetro encontrado para os filtros informados.</p>
                </td>
            </tr>`;
        _renderPaginacao(0);
        return;
    }

    const total      = lista.length;
    const perPage    = _state.perPage;
    const totalPages = Math.ceil(total / perPage);

    // Garante que currentPage está dentro dos limites
    if (_state.currentPage < 1)           _state.currentPage = 1;
    if (_state.currentPage > totalPages)  _state.currentPage = totalPages;

    const start  = (_state.currentPage - 1) * perPage;
    const end    = Math.min(start + perPage, total);
    const pagina = lista.slice(start, end);

    tbody.innerHTML = pagina.map(h => {
        const ativo = h.ativo == 1;
        const badge = ativo
            ? '<span class="badge badge-active"><i class="fas fa-check"></i> Ativo</span>'
            : '<span class="badge badge-inactive"><i class="fas fa-times"></i> Inativo</span>';

        const ultimaLeitura = h.ultima_leitura != null
            ? `${parseFloat(h.ultima_leitura).toFixed(2)} m³`
            : '<span style="color:#94a3b8;">Sem leitura</span>';

        return `
            <tr>
                <td><strong>#${h.id}</strong></td>
                <td>${_esc(h.unidade)}</td>
                <td>${_esc(h.morador_nome)}</td>
                <td><strong>${_esc(h.numero_hidrometro)}</strong></td>
                <td>${_esc(h.numero_lacre) || '<span style="color:#94a3b8;">N/A</span>'}</td>
                <td>${_esc(h.data_instalacao_formatada)}</td>
                <td>${ultimaLeitura}</td>
                <td>${badge}</td>
                <td>
                    <button class="action-btn edit" title="Editar"
                        onclick="window.HidrometroPage.editar(${h.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btn history" title="Histórico"
                        onclick="window.HidrometroPage.verHistorico(${h.id})">
                        <i class="fas fa-history"></i>
                    </button>
                    <button class="action-btn btn-gerar-demo" title="Gerar Demonstrativo de Água"
                        onclick="window.HidrometroPage.gerarDemonstrativo(${h.id})"
                        style="background:linear-gradient(135deg,#16a34a,#166534);color:#fff;border:none;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;">
                        <i class="fas fa-file-invoice"></i>
                    </button>
                    <button class="action-btn view" title="Visualizar Foto"
                        onclick="window.HidrometroPage.fotoAbrirGaleriaHidrometro(${h.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>`;
    }).join('');

    _renderPaginacao(total);
}

// ============================================================
// PAGINAÇÃO
// ============================================================

function _renderPaginacao(total) {
    // Garante que o container de paginação existe; cria se necessário
    let container = document.getElementById('paginacaoHidrometros');
    if (!container) {
        const tableCard = document.querySelector('.page-hidrometro .table-container')?.closest('.page-card');
        if (tableCard) {
            container = document.createElement('div');
            container.id = 'paginacaoHidrometros';
            container.className = 'hidrometro-pagination';
            tableCard.appendChild(container);
        }
    }
    if (!container) return;

    const perPage    = _state.perPage;
    const totalPages = Math.ceil(total / perPage);
    const current    = _state.currentPage;

    if (totalPages <= 1) {
        container.innerHTML = total > 0
            ? `<div class="pagination-info">Exibindo <strong>${total}</strong> hidrômetro${total !== 1 ? 's' : ''}</div>`
            : '';
        return;
    }

    // Gera os botões de página com janela deslizante
    const pages = [];
    const delta = 2;
    const left  = Math.max(1, current - delta);
    const right = Math.min(totalPages, current + delta);

    if (left > 1) {
        pages.push(1);
        if (left > 2) pages.push('...');
    }
    for (let i = left; i <= right; i++) pages.push(i);
    if (right < totalPages) {
        if (right < totalPages - 1) pages.push('...');
        pages.push(totalPages);
    }

    const inicio = (current - 1) * perPage + 1;
    const fim    = Math.min(current * perPage, total);

    container.innerHTML = `
        <div class="pagination-info">
            Exibindo <strong>${inicio}–${fim}</strong> de <strong>${total}</strong> hidrômetros
        </div>
        <div class="pagination-controls">
            <button class="page-btn" ${current === 1 ? 'disabled' : ''}
                onclick="window.HidrometroPage.irParaPagina(${current - 1})" title="Página anterior">
                <i class="fas fa-chevron-left"></i>
            </button>
            ${pages.map(p => p === '...'
                ? '<span class="page-ellipsis">…</span>'
                : `<button class="page-btn ${p === current ? 'active' : ''}"
                    onclick="window.HidrometroPage.irParaPagina(${p})">${p}</button>`
            ).join('')}
            <button class="page-btn" ${current === totalPages ? 'disabled' : ''}
                onclick="window.HidrometroPage.irParaPagina(${current + 1})" title="Próxima página">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="pagination-perpage">
            <label>Por página:
                <select onchange="window.HidrometroPage.alterarPerPage(this.value)">
                    ${[10, 20, 50, 100].map(n =>
                        `<option value="${n}" ${n === perPage ? 'selected' : ''}>${n}</option>`
                    ).join('')}
                </select>
            </label>
        </div>`;
}

function irParaPagina(pagina) {
    const total      = _state.hidrometros.length;
    const totalPages = Math.ceil(total / _state.perPage);
    const p          = parseInt(pagina, 10);
    if (isNaN(p) || p < 1 || p > totalPages) return;
    _state.currentPage = p;
    _renderTabela(_state.hidrometros);
    // Scroll suave até o topo da tabela
    document.getElementById('listaHidrometros')
        ?.closest('.page-card')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function alterarPerPage(valor) {
    _state.perPage     = parseInt(valor, 10) || 20;
    _state.currentPage = 1;
    _renderTabela(_state.hidrometros);
}

// ============================================================
// KPIs
// ============================================================

function _atualizarKPIs(lista) {
    const total      = lista.length;
    const ativos     = lista.filter(h => h.ativo == 1).length;
    const inativos   = total - ativos;
    const comLeitura = lista.filter(h => h.ultima_leitura != null).length;

    _setEl('kpi_total',      total);
    _setEl('kpi_ativos',     ativos);
    _setEl('kpi_inativos',   inativos);
    _setEl('kpi_com_leitura', comLeitura);
}

// ============================================================
// CADASTRO
// ============================================================

async function _salvarHidrometro() {
    // Proteção contra duplo submit
    const btnSalvar = document.querySelector('#formCadastro button[type="submit"]');
    if (btnSalvar?.disabled) return;
    if (btnSalvar) btnSalvar.disabled = true;

    const moradorId = document.getElementById('cad_morador')?.value;
    const unidade   = document.getElementById('cad_unidade')?.value;
    const numero    = document.getElementById('cad_numero')?.value?.trim();
    const lacre     = document.getElementById('cad_lacre')?.value?.trim();
    const data      = document.getElementById('cad_data')?.value;
    if (!moradorId || !unidade || !numero || !data) {
        _toast('Preencha todos os campos obrigatórios.', 'warning');
        if (btnSalvar) btnSalvar.disabled = false;
        return;
    }
    const inventarioId = document.getElementById('cad_inventario_id')?.value;
    const payload = {
        morador_id          : parseInt(moradorId),
        unidade             : unidade,
        numero_hidrometro   : numero,
        numero_lacre        : lacre || '',
        data_instalacao     : data,
        inventario_id       : inventarioId ? parseInt(inventarioId) : null,
    };
    console.log('[Hidrometro] Salvando:', payload);
    try {
        const data_resp = await _apiCall(API_HIDROMETROS, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(payload),
        });
        if (!data_resp.sucesso) throw new Error(data_resp.mensagem);
        _toast('Hidrômetro cadastrado com sucesso!', 'success');
        limparCadastro();
        _carregarHidrometros();
        _switchTab('lista');
    } catch (err) {
        console.error('[Hidrometro] Erro ao salvar:', err);
        _toast('Erro ao cadastrar: ' + err.message, 'error');
        if (btnSalvar) btnSalvar.disabled = false;
    }
}

function limparCadastro() {
    document.getElementById('formCadastro')?.reset();
    const selMorador = document.getElementById('cad_morador');
    if (selMorador) {
        selMorador.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        selMorador.disabled = true;
    }
    // Limpar campo de patrimônio
    limparPatrimonio();
    _setDataAtual();
}

// ============================================================
// BUSCA
// ============================================================

function buscar() {
    // Validação amigável: Data Final não pode ser anterior à Data Inicial
    const dataInicial = document.getElementById('filtro_data_inicial')?.value || '';
    const dataFinal   = document.getElementById('filtro_data_final')?.value   || '';
    if (dataInicial && dataFinal && dataFinal < dataInicial) {
        _toast('A Data Final não pode ser anterior à Data Inicial.', 'warning');
        return;
    }
    _carregarHidrometros();
}

function buscarDebounce() {
    if (_state.buscarTimer) clearTimeout(_state.buscarTimer);
    _state.buscarTimer = setTimeout(buscar, 400);
}

function limparBusca() {
    const campo = document.getElementById('busca');
    if (campo) campo.value = '';
    const selStatus = document.getElementById('filtro_status');
    if (selStatus) selStatus.value = '';
    const selUnidade = document.getElementById('filtro_unidade');
    if (selUnidade) selUnidade.value = '';
    const campoDataInicial = document.getElementById('filtro_data_inicial');
    if (campoDataInicial) campoDataInicial.value = '';
    const campoDataFinal = document.getElementById('filtro_data_final');
    if (campoDataFinal) campoDataFinal.value = '';
    _carregarHidrometros();
}

// ============================================================
// MODAL EDITAR
// ============================================================

async function abrirModalEditar(id) {
    console.log('[Hidrometro] Abrindo edição do ID:', id);

    const hidrometro = _state.hidrometros.find(h => h.id == id);
    if (!hidrometro) {
        _toast('Hidrômetro não encontrado na lista. Recarregue a página.', 'error');
        return;
    }

    // Preencher campos
    _setEl('edit_id',                 hidrometro.id,                  'value');
    _setEl('edit_numero_hidrometro',  hidrometro.numero_hidrometro,   'value');
    _setEl('edit_numero_lacre',       hidrometro.numero_lacre || '',  'value');
    _setEl('edit_ativo',              hidrometro.ativo,               'value');
    _setEl('edit_observacao',         '',                             'value');

    // Data instalação: converter para datetime-local
    const dataRaw = hidrometro.data_instalacao_formatada || '';
    if (dataRaw) {
        // Formato vindo da API: dd/mm/yyyy HH:ii → converter para yyyy-mm-ddTHH:ii
        const partes = dataRaw.split(' ');
        if (partes.length === 2) {
            const [d, m, y] = partes[0].split('/');
            const hora = partes[1].slice(0, 5);
            _setEl('edit_data_instalacao', `${y}-${m}-${d}T${hora}`, 'value');
        }
    }

    // Popular select de unidades e aguardar
    _popularSelectUnidades('edit_unidade');
    const selUnidade = document.getElementById('edit_unidade');
    if (selUnidade) selUnidade.value = hidrometro.unidade;

    // Carregar moradores da unidade e selecionar o correto
    await _carregarMoradoresPorUnidade('edit');
    const selMorador = document.getElementById('edit_morador');
    if (selMorador) selMorador.value = hidrometro.morador_id;

    // Preencher campo de patrimônio se existir
    const editPatrimonioBusca = document.getElementById('edit_patrimonio_busca');
    const editInventarioId    = document.getElementById('edit_inventario_id');
    const editDropdown        = document.getElementById('editPatrimonioDropdown');
    if (editPatrimonioBusca) editPatrimonioBusca.value = '';
    if (editInventarioId)    editInventarioId.value    = '';
    if (editDropdown)        editDropdown.style.display = 'none';

    // Se o hidrômetro já tem inventario_id, buscar o número do patrimônio para exibir
    if (hidrometro.inventario_id) {
        if (editInventarioId) editInventarioId.value = hidrometro.inventario_id;
        if (editPatrimonioBusca) {
            // Exibir o numero_patrimonio se vier no objeto, senão buscar na API
            if (hidrometro.numero_patrimonio) {
                editPatrimonioBusca.value = hidrometro.numero_patrimonio +
                    (hidrometro.nome_patrimonio ? ' — ' + hidrometro.nome_patrimonio : '');
            } else {
                // Buscar na API de inventário pelo ID
                try {
                    const inv = await _apiCall(`${API_INVENTARIO}?id=${hidrometro.inventario_id}`);
                    const item = (inv.dados || [])[0] || inv.dados;
                    if (item && item.numero_patrimonio) {
                        editPatrimonioBusca.value = item.numero_patrimonio +
                            (item.nome_item ? ' — ' + item.nome_item : '');
                    }
                } catch (e) {
                    console.warn('[Hidrometro] Não foi possível carregar patrimônio:', e);
                }
            }
        }
    }

    abrirModal('modalEditar');
}

async function salvarEdicao() {
    const id       = document.getElementById('edit_id')?.value;
    const moradorId= document.getElementById('edit_morador')?.value;
    const unidade  = document.getElementById('edit_unidade')?.value;
    const numero   = document.getElementById('edit_numero_hidrometro')?.value?.trim();
    const lacre    = document.getElementById('edit_numero_lacre')?.value?.trim();
    const dataInst = document.getElementById('edit_data_instalacao')?.value;
    const ativo    = document.getElementById('edit_ativo')?.value;
    const obs      = document.getElementById('edit_observacao')?.value?.trim();

    if (!id || !moradorId || !unidade || !numero || !dataInst || !obs) {
        _toast('Preencha todos os campos obrigatórios, incluindo o motivo da alteração.', 'warning');
        return;
    }

    const inventarioId = document.getElementById('edit_inventario_id')?.value;

    const payload = {
        id                  : parseInt(id),
        morador_id          : parseInt(moradorId),
        unidade             : unidade,
        numero_hidrometro   : numero,
        numero_lacre        : lacre || '',
        data_instalacao     : dataInst,
        ativo               : parseInt(ativo),
        observacao          : obs,
        inventario_id       : inventarioId ? parseInt(inventarioId) : null,
    };

    console.log('[Hidrometro] Atualizando:', payload);

    try {
        const data = await _apiCall(API_HIDROMETROS, {
            method  : 'PUT',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(payload),
        });

        if (!data.sucesso) throw new Error(data.mensagem);

        _toast('Hidrômetro atualizado com sucesso!', 'success');
        fecharModal('modalEditar');
        _carregarHidrometros();
    } catch (err) {
        console.error('[Hidrometro] Erro ao atualizar:', err);
        _toast('Erro ao atualizar: ' + err.message, 'error');
    }
}

// ============================================================
// MODAL HISTÓRICO
// ============================================================

async function abrirModalHistorico(id) {
    console.log('[Hidrometro] Carregando histórico do ID:', id);

    const container = document.getElementById('historicoContent');
    if (container) {
        container.innerHTML = `
            <div class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Carregando histórico...</p>
            </div>`;
    }

    abrirModal('modalHistorico');

    try {
        const data = await _apiCall(`${API_HIDROMETROS}?historico=${id}`);
        if (!data.sucesso) throw new Error(data.mensagem);

        const historico = data.dados || [];

        if (historico.length === 0) {
            container.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <span>Nenhuma alteração registrada para este hidrômetro.</span>
                </div>`;
            return;
        }

        const camposLabel = {
            morador_id          : 'Morador',
            numero_hidrometro   : 'Nº Hidrômetro',
            numero_lacre        : 'Nº Lacre',
            ativo               : 'Status',
        };

        container.innerHTML = `
            <div class="historico-list">
                ${historico.map(h => `
                    <div class="historico-item">
                        <div class="hist-meta">
                            <span class="hist-campo">
                                <i class="fas fa-tag"></i>
                                ${camposLabel[h.campo_alterado] || h.campo_alterado}
                            </span>
                            <span class="hist-data">
                                <i class="fas fa-clock"></i> ${_esc(h.data_formatada)}
                            </span>
                        </div>
                        <div class="hist-valores">
                            <span class="hist-anterior">
                                <i class="fas fa-arrow-right"></i> Antes: ${_esc(h.valor_anterior)}
                            </span>
                            <span class="hist-novo">
                                <i class="fas fa-check"></i> Depois: ${_esc(h.valor_novo)}
                            </span>
                        </div>
                        <div class="hist-obs">
                            <i class="fas fa-comment"></i> ${_esc(h.observacao)}
                        </div>
                    </div>
                `).join('')}
            </div>`;
    } catch (err) {
        console.error('[Hidrometro] Erro ao carregar histórico:', err);
        if (container) {
            container.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Erro ao carregar histórico: ${err.message}</span>
                </div>`;
        }
    }
}

// ============================================================
// MODAL HELPERS
// ============================================================

function abrirModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function fecharModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Listener de click fora do modal movido para init() — ver acima

// ============================================================
// BUSCA DE PATRIMÔNIO (INVENTÁRIO)
// ============================================================

const API_INVENTARIO = window.location.origin + '/api/api_inventario.php';
let _patrimonioTimer = null;

/**
 * Busca patrimônios no inventário filtrados por:
 * - status = ativo
 * - situacao = circulante
 * - grupo = Hidrômetros
 */
async function buscarPatrimonio(termo) {
    if (_patrimonioTimer) clearTimeout(_patrimonioTimer);

    const dropdown = document.getElementById('patrimonioDropdown');
    if (!dropdown) return;

    if (!termo || termo.length < 1) {
        dropdown.style.display = 'none';
        dropdown.innerHTML = '';
        return;
    }

    _patrimonioTimer = setTimeout(async () => {
        dropdown.style.display = 'block';
        dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#64748b;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

        try {
            const url = `${API_INVENTARIO}?busca=${encodeURIComponent(termo)}&status=ativo&situacao=circulante&grupo_nome=Hidr%C3%B4metros`;
            const data = await _apiCall(url);

            const itens = data.dados || data.itens || [];

            if (itens.length === 0) {
                dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#94a3b8;font-size:13px;">Nenhum item encontrado</div>';
                return;
            }

            dropdown.innerHTML = itens.map(item => `
                <div onclick="window.HidrometroPage.selecionarPatrimonio(${item.id}, '${_esc(item.numero_patrimonio)}', '${_esc(item.nome_item)}')" style="
                    padding:0.65rem 1rem;
                    cursor:pointer;
                    border-bottom:1px solid #f1f5f9;
                    font-size:13px;
                    transition:background 0.15s;
                " onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''">
                    <strong style="color:#1e293b;">${_esc(item.numero_patrimonio)}</strong>
                    <span style="color:#64748b;"> — ${_esc(item.nome_item)}</span>
                    ${item.modelo ? `<span style="color:#94a3b8;font-size:11px;"> (${_esc(item.modelo)})</span>` : ''}
                </div>
            `).join('');

        } catch (err) {
            console.error('[Hidrometro] Erro ao buscar patrimônio:', err);
            dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#ef4444;font-size:13px;">Erro ao buscar</div>';
        }
    }, 350);
}

function selecionarPatrimonio(id, numero, nome) {
    const inputBusca = document.getElementById('cad_patrimonio_busca');
    const inputId    = document.getElementById('cad_inventario_id');
    const dropdown   = document.getElementById('patrimonioDropdown');

    if (inputBusca) inputBusca.value = `${numero} — ${nome}`;
    if (inputId)    inputId.value    = id;
    if (dropdown)   dropdown.style.display = 'none';

    console.log(`[Hidrometro] Patrimônio selecionado: ID=${id}, Nº=${numero}`);
}

function limparPatrimonio() {
    const inputBusca = document.getElementById('cad_patrimonio_busca');
    const inputId    = document.getElementById('cad_inventario_id');
    const dropdown   = document.getElementById('patrimonioDropdown');

    if (inputBusca) inputBusca.value = '';
    if (inputId)    inputId.value    = '';
    if (dropdown)   dropdown.style.display = 'none';
}

// ============================================================
// PATRIMÔNIO — EDIÇÃO
// ============================================================
let _patrimonioEditTimer = null;
async function buscarPatrimonioEdit(termo) {
    if (_patrimonioEditTimer) clearTimeout(_patrimonioEditTimer);
    const dropdown = document.getElementById('editPatrimonioDropdown');
    if (!dropdown) return;
    if (!termo || termo.length < 1) {
        dropdown.style.display = 'none';
        dropdown.innerHTML = '';
        return;
    }
    _patrimonioEditTimer = setTimeout(async () => {
        dropdown.style.display = 'block';
        dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#64748b;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
        try {
            const url = `${API_INVENTARIO}?busca=${encodeURIComponent(termo)}&status=ativo&situacao=circulante&grupo_nome=Hidr%C3%B4metros`;
            const data = await _apiCall(url);
            const itens = data.dados || data.itens || [];
            if (itens.length === 0) {
                dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#94a3b8;font-size:13px;">Nenhum item encontrado</div>';
                return;
            }
            dropdown.innerHTML = itens.map(item => `
                <div onclick="window.HidrometroPage.selecionarPatrimonioEdit(${item.id}, '${_esc(item.numero_patrimonio)}', '${_esc(item.nome_item)}')" style="
                    padding:0.65rem 1rem;
                    cursor:pointer;
                    border-bottom:1px solid #f1f5f9;
                    font-size:13px;
                    transition:background 0.15s;
                " onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''">
                    <strong style="color:#1e293b;">${_esc(item.numero_patrimonio)}</strong>
                    <span style="color:#64748b;"> — ${_esc(item.nome_item)}</span>
                    ${item.modelo ? `<span style="color:#94a3b8;font-size:11px;"> (${_esc(item.modelo)})</span>` : ''}
                </div>
            `).join('');
        } catch (err) {
            console.error('[Hidrometro] Erro ao buscar patrimônio (edição):', err);
            dropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#ef4444;font-size:13px;">Erro ao buscar</div>';
        }
    }, 350);
}

function selecionarPatrimonioEdit(id, numero, nome) {
    const inputBusca = document.getElementById('edit_patrimonio_busca');
    const inputId    = document.getElementById('edit_inventario_id');
    const dropdown   = document.getElementById('editPatrimonioDropdown');
    if (inputBusca) inputBusca.value = `${numero} — ${nome}`;
    if (inputId)    inputId.value    = id;
    if (dropdown)   dropdown.style.display = 'none';
    console.log(`[Hidrometro] Patrimônio edição selecionado: ID=${id}, Nº=${numero}`);
}

function limparPatrimonioEdit() {
    const inputBusca = document.getElementById('edit_patrimonio_busca');
    const inputId    = document.getElementById('edit_inventario_id');
    const dropdown   = document.getElementById('editPatrimonioDropdown');
    if (inputBusca) inputBusca.value = '';
    if (inputId)    inputId.value    = '';
    if (dropdown)   dropdown.style.display = 'none';
}

// ============================================================
// API HELPER
// ============================================================

/**
 * Wrapper defensivo para fetch — garante que erros de rede e
 * respostas não-JSON sejam tratados de forma consistente.
 */
async function _apiCall(url, options = {}) {
    const defaultOptions = {
        credentials : 'include',
        headers     : { 'Accept': 'application/json', ...(options.headers || {}) },
    };

    const response = await fetch(url, { ...defaultOptions, ...options });

    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        const text = await response.text();
        console.error('[Hidrometro] Resposta não-JSON:', text.slice(0, 200));
        throw new Error(`Servidor retornou resposta inválida (HTTP ${response.status})`);
    }

    const data = await response.json();

    if (!response.ok && data.mensagem) {
        throw new Error(data.mensagem);
    }

    return data;
}

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================

function _toast(mensagem, tipo = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const icons = {
        success : 'fa-check-circle',
        error   : 'fa-exclamation-circle',
        warning : 'fa-exclamation-triangle',
        info    : 'fa-info-circle',
    };

    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;
    toast.innerHTML = `
        <i class="fas ${icons[tipo] || icons.info}"></i>
        <span>${_esc(mensagem)}</span>`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============================================================
// UTILITÁRIOS
// ============================================================

function _esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function _setEl(id, valor, prop = 'textContent') {
    const el = document.getElementById(id);
    if (el) el[prop] = valor ?? '';
}

// ============================================================
// LEITURAS — INDIVIDUAL
// ============================================================

async function _leituraCarregarMoradores() {
    const unidade = document.getElementById('ind_unidade')?.value;
    const selMorador = document.getElementById('ind_morador');
    const selHidro   = document.getElementById('ind_hidrometro');
    if (!selMorador) return;

    selMorador.innerHTML = '<option value="">Carregando...</option>';
    selMorador.disabled = true;
    if (selHidro) {
        selHidro.innerHTML = '<option value="">Selecione o morador primeiro</option>';
        selHidro.disabled = true;
    }
    _leituraLimparPreview();

    if (!unidade) {
        selMorador.innerHTML = '<option value="">Selecione a unidade primeiro</option>';
        return;
    }

    try {
        const data = await _apiCall(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`);
        // api_moradores retorna dados paginados: { itens: [...], total, ... }
        const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        if (data.sucesso && moradores.length > 0) {
            selMorador.innerHTML = '<option value="">Selecione o morador...</option>';
            moradores.forEach(m => selMorador.add(new Option(m.nome, m.id)));
            selMorador.disabled = false;
            // Unidade normalmente tem um único morador vinculado — localiza automaticamente
            if (moradores.length === 1) {
                selMorador.value = moradores[0].id;
                _leituraCarregarHidrometrosMorador();
            }
        } else {
            selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
        }
    } catch (err) {
        selMorador.innerHTML = '<option value="">Erro ao carregar</option>';
        console.error('[Leitura] Erro ao carregar moradores:', err);
    }
}

async function _leituraCarregarHidrometrosMorador() {
    const moradorId = document.getElementById('ind_morador')?.value;
    const selHidro  = document.getElementById('ind_hidrometro');
    if (!selHidro) return;

    selHidro.innerHTML = '<option value="">Carregando...</option>';
    selHidro.disabled = true;
    _leituraLimparPreview();

    if (!moradorId) {
        selHidro.innerHTML = '<option value="">Selecione o morador primeiro</option>';
        return;
    }

    try {
        const data = await _apiCall(`${API_HIDROMETROS}?morador_id=${moradorId}&ativos=1`);
        const hidros = data.dados || data.hidrometros || [];
        _state.indHidrometrosCache = hidros;
        if (data.sucesso && hidros.length > 0) {
            selHidro.innerHTML = '<option value="">Selecione o hidrômetro...</option>';
            hidros.forEach(h => selHidro.add(new Option(`Nº ${h.numero_hidrometro}`, h.id)));
            selHidro.disabled = false;
            if (hidros.length === 1) {
                selHidro.value = hidros[0].id;
                _leituraCarregarUltimaLeitura();
            }
        } else {
            selHidro.innerHTML = '<option value="">Nenhum hidrômetro ativo para este morador</option>';
        }
    } catch (err) {
        selHidro.innerHTML = '<option value="">Erro ao carregar</option>';
        console.error('[Leitura] Erro ao carregar hidrômetros:', err);
    }
}

async function _leituraCarregarUltimaLeitura() {
    const hidroId = document.getElementById('ind_hidrometro')?.value;
    _leituraLimparPreview();
    if (!hidroId) return;

    // Nº hidrômetro / lacre vêm do cache já buscado em _leituraCarregarHidrometrosMorador
    const hidro = (_state.indHidrometrosCache || []).find(h => String(h.id) === String(hidroId));

    try {
        const data = await _apiCall(`${API_LEITURAS}?ultima_leitura=${hidroId}`);
        const ultima = data.dados || data.leitura || null;
        const leituraAnterior = (ultima && ultima.leitura_atual != null) ? parseFloat(ultima.leitura_atual) : 0;

        _setEl('ind_leitura_anterior', leituraAnterior.toFixed(2), 'value');

        const infoBox = document.getElementById('ind_info_hidrometro');
        if (infoBox) {
            infoBox.style.display = 'block';
            _setEl('info_numero', hidro ? hidro.numero_hidrometro : '—');
            _setEl('info_lacre', (hidro && hidro.numero_lacre) ? hidro.numero_lacre : '—');
            _setEl('info_leitura_anterior', ultima ? `${leituraAnterior.toFixed(2)} m³` : 'Nenhuma leitura anterior');
            _setEl('info_data_anterior', ultima ? (ultima.data_leitura_formatada || ultima.data_leitura) : '—');
        }
    } catch (err) {
        console.error('[Leitura] Erro ao carregar última leitura:', err);
    }
}

function leituraCalcularPreview() {
    const leituraAnterior = parseFloat(document.getElementById('ind_leitura_anterior')?.value || 0);
    const leituraAtual    = parseFloat(document.getElementById('ind_leitura_atual')?.value    || 0);

    const consumo = Math.max(0, leituraAtual - leituraAnterior);
    const valor   = consumo <= CONSUMO_MINIMO ? VALOR_MINIMO : VALOR_MINIMO + (consumo - CONSUMO_MINIMO) * VALOR_M3;

    const elConsumo = document.getElementById('calc_consumo');
    const elValor   = document.getElementById('calc_valor');
    const elBox     = document.getElementById('ind_calculo');

    if (elConsumo) elConsumo.textContent = `${consumo.toFixed(2)} m³`;
    if (elValor)   elValor.textContent   = valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    if (elBox)     elBox.style.display   = 'block';
}

function _leituraLimparPreview() {
    const elBox = document.getElementById('ind_calculo');
    if (elBox) elBox.style.display = 'none';
    const infoBox = document.getElementById('ind_info_hidrometro');
    if (infoBox) infoBox.style.display = 'none';
    const campoAnterior = document.getElementById('ind_leitura_anterior');
    if (campoAnterior) campoAnterior.value = '';
}

async function _leituraSalvarIndividual() {
    const hidroId         = document.getElementById('ind_hidrometro')?.value;
    const leituraAnterior = document.getElementById('ind_leitura_anterior')?.value;
    const leituraAtual    = document.getElementById('ind_leitura_atual')?.value;
    const dataLeitura     = document.getElementById('ind_data_leitura')?.value;
    const observacao      = document.getElementById('ind_observacao')?.value || '';

    if (!hidroId || !leituraAtual || !dataLeitura) {
        _toast('Preencha todos os campos obrigatórios.', 'warning');
        return;
    }
    if (parseFloat(leituraAtual) < parseFloat(leituraAnterior || 0)) {
        _toast('A leitura atual não pode ser menor que a anterior.', 'error');
        return;
    }

    const consumo = Math.max(0, parseFloat(leituraAtual) - parseFloat(leituraAnterior || 0));
    const valor   = consumo <= CONSUMO_MINIMO ? VALOR_MINIMO : VALOR_MINIMO + (consumo - CONSUMO_MINIMO) * VALOR_M3;

    const btn = document.getElementById('btnSalvarLeitura');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const data = await _apiCall(API_LEITURAS, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify({
                hidrometro_id    : parseInt(hidroId),
                leitura_anterior : parseFloat(leituraAnterior || 0),
                leitura_atual    : parseFloat(leituraAtual),
                consumo          : consumo,
                valor_cobrado    : valor,
                data_leitura     : dataLeitura,
                observacao       : observacao,
                foto_id          : _fotoPendenteIndividual ? _fotoPendenteIndividual.id : null,
            }),
        });

        if (data.sucesso) {
            _toast('Leitura registrada com sucesso!', 'success');
            _fotoPendenteIndividual = null; // já foi vinculada pelo backend — não excluir
            leituraLimparIndividual();
            leituraBuscarHistorico();
        } else {
            _toast(data.mensagem || 'Erro ao salvar leitura.', 'error');
        }
    } catch (err) {
        _toast(`Erro: ${err.message}`, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Registrar Leitura'; }
    }
}

function leituraLimparIndividual() {
    ['ind_unidade','ind_morador','ind_hidrometro','ind_leitura_anterior','ind_leitura_atual','ind_observacao'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    _setDataAtual('ind_data_leitura');
    _leituraLimparPreview();
    const selMorador = document.getElementById('ind_morador');
    if (selMorador) {
        selMorador.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        selMorador.disabled = true;
    }
    const selHidro = document.getElementById('ind_hidrometro');
    if (selHidro) {
        selHidro.innerHTML = '<option value="">Primeiro selecione o morador</option>';
        selHidro.disabled = true;
    }
    _state.indHidrometrosCache = [];

    // Se havia uma foto anexada mas a leitura não chegou a ser salva, remove
    // o arquivo órfão do servidor (fotos já vinculadas não passam por aqui,
    // pois _leituraSalvarIndividual já zera _fotoPendenteIndividual antes de chamar limpar)
    if (_fotoPendenteIndividual) {
        _fotoExcluirPendente(_fotoPendenteIndividual.id);
        _fotoPendenteIndividual = null;
    }
    _fotoAtualizarBotaoIndividual();
}

// ============================================================
// LEITURAS — COLETIVA
// ============================================================

async function leituraCarregarHidrometrosAtivos(pagina = 1) {
    // O tbody da tabela estática do HTML usa id="listaColetiva"
    const tbody = document.getElementById('listaColetiva');
    if (!tbody) {
        console.error('[Hidrometro] Container listaColetiva não encontrado');
        return;
    }

    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Carregando hidrômetros ativos...</td></tr>';

    try {
        // Endpoint correto: api_leituras.php?hidrometros_ativos=1&pagina=N
        // Retorna: { dados: { hidrometros: [], pagina_atual, total_paginas, total_registros } }
        const data = await _apiCall(`${API_LEITURAS}?hidrometros_ativos=1&pagina=${pagina}`);
        const payload = data.dados || {};
        _state.hidrometrosAtivos = payload.hidrometros || [];
        _state.paginaAtual       = payload.pagina_atual   || pagina;
        _state.totalPaginas      = payload.total_paginas  || 1;

        console.log(`[Hidrometro] Leitura coletiva carregada: ${_state.hidrometrosAtivos.length} registros (pág ${_state.paginaAtual}/${_state.totalPaginas})`);
        _leituraRenderizarColetiva();
    } catch (err) {
        console.error('[Hidrometro] Erro ao carregar leitura coletiva:', err);
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:#dc2626;"><i class="fas fa-exclamation-circle"></i> Erro ao carregar: ${err.message}</td></tr>`;
    }
}

function _leituraRenderizarColetiva() {
    // Usa os elementos estáticos do HTML: tbody#listaColetiva, div#paginacaoColetiva, span#infoPagina
    const tbody = document.getElementById('listaColetiva');
    if (!tbody) return;

    // A ordenação (Administrativo primeiro, depois numérica natural: Gleba 1, 2 ... 10, 11)
    // já vem pronta do backend — a paginação acontece no servidor, então reordenar aqui
    // só bagunçaria a sequência entre páginas.
    const lista = _state.hidrometrosAtivos;

    if (lista.length === 0) {
        tbody.innerHTML = '<tr class="empty-row"><td colspan="7"><i class="fas fa-tint-slash"></i><p>Nenhum hidrômetro pendente de leitura encontrado.</p></td></tr>';
        const pag = document.getElementById('paginacaoColetiva');
        if (pag) pag.style.display = 'none';
        _leituraAtualizarContadores();
        return;
    }

    // Renderizar linhas restaurando o que já foi digitado/marcado em páginas anteriores
    tbody.innerHTML = lista.map(h => {
        const draft    = _state.leituraColetivaDraft.get(h.id) || {};
        const checked  = draft.selecionado ? 'checked' : '';
        const valorInp = (draft.leitura != null && !isNaN(draft.leitura)) ? draft.leitura : '';
        const foto     = draft.foto || null;
        const fotoCel  = foto
            ? `<img src="${foto.previewUrl}" alt="Foto anexada" style="width:26px;height:26px;border-radius:5px;object-fit:cover;vertical-align:middle;margin-right:4px;">
               <button type="button" class="btn-foto anexada" title="Foto anexada — clique para substituir" onclick="window.HidrometroPage.fotoAbrirMenuColetiva(${h.id})"><i class="fas fa-check"></i></button>
               <button type="button" class="btn-foto-remover" title="Remover foto" onclick="window.HidrometroPage.fotoRemoverColetiva(${h.id})"><i class="fas fa-times"></i></button>`
            : `<button type="button" class="btn-foto" title="Anexar foto da leitura" onclick="window.HidrometroPage.fotoAbrirMenuColetiva(${h.id})"><i class="fas fa-camera"></i></button>`;

        return `
        <tr>
            <td><input type="checkbox" class="col-check" data-id="${h.id}" ${checked}
                onchange="window.HidrometroPage.colAtualizarSelecao(${h.id}, this.checked)"></td>
            <td>${_esc(h.unidade)}</td>
            <td>${_esc(h.morador_nome)}</td>
            <td>${_esc(h.numero_hidrometro)}</td>
            <td>${h.leitura_anterior != null && parseFloat(h.leitura_anterior) > 0
                ? parseFloat(h.leitura_anterior).toFixed(2) + ' m³'
                : '<span style="color:#94a3b8">Sem leitura</span>'}</td>
            <td><input type="number" step="0.01" min="0" class="col-leitura-input" data-id="${h.id}" value="${valorInp}"
                oninput="window.HidrometroPage.colAtualizarValor(${h.id}, this.value)"
                placeholder="0.00" style="width:100px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;"></td>
            <td style="text-align:center;white-space:nowrap;">${fotoCel}</td>
        </tr>`;
    }).join('');

    // Atualizar paginação (elementos estáticos do HTML)
    const pag       = document.getElementById('paginacaoColetiva');
    const infoPag   = document.getElementById('infoPagina');
    const btnAnt    = document.getElementById('btnAnterior');
    const btnProx   = document.getElementById('btnProximo');
    const selectAll = document.getElementById('selectAll');

    // "Selecionar todos" reflete se TODA a página atual já está marcada no rascunho
    if (selectAll) {
        selectAll.checked = lista.every(h => (_state.leituraColetivaDraft.get(h.id) || {}).selecionado === true);
    }

    if (pag) {
        pag.style.display = _state.totalPaginas > 1 ? 'flex' : 'none';
    }
    if (infoPag) {
        infoPag.textContent = `Página ${_state.paginaAtual} de ${_state.totalPaginas}`;
    }
    if (btnAnt) btnAnt.disabled = _state.paginaAtual <= 1;
    if (btnProx) btnProx.disabled = _state.paginaAtual >= _state.totalPaginas;

    _leituraAtualizarContadores();
}

// ------------------------------------------------------------
// Persistência do rascunho (Map em memória) — sobrevive à troca de página
// ------------------------------------------------------------

function leituraColetivaAtualizarValor(id, valor) {
    const key   = parseInt(id, 10);
    const draft = _state.leituraColetivaDraft.get(key) || {};
    const num   = valor === '' ? null : parseFloat(valor);
    draft.leitura = (num !== null && !isNaN(num)) ? num : null;
    _state.leituraColetivaDraft.set(key, draft);
    _leituraAtualizarContadores();
}

function leituraColetivaAtualizarSelecao(id, checked) {
    const key   = parseInt(id, 10);
    const draft = _state.leituraColetivaDraft.get(key) || {};
    draft.selecionado = !!checked;
    _state.leituraColetivaDraft.set(key, draft);
    _leituraAtualizarContadores();
}

function _leituraAtualizarContadores() {
    let preenchidas = 0;
    let selecionados = 0;
    _state.leituraColetivaDraft.forEach(item => {
        if (item.leitura != null && !isNaN(item.leitura)) preenchidas++;
        if (item.selecionado) selecionados++;
    });
    _setEl('colCountPreenchidas', preenchidas);
    _setEl('colCountSelecionados', selecionados);
}

function leituraSelecionarTodos(checked) {
    // Afeta apenas os hidrômetros da página atualmente exibida
    document.querySelectorAll('.col-check').forEach(cb => {
        cb.checked = checked;
        leituraColetivaAtualizarSelecao(cb.dataset.id, checked);
    });
}

function leituraLimparSelecao() {
    // Reset total: limpa o rascunho inteiro (todas as páginas), não só a visível.
    // Fotos ainda não vinculadas a nenhuma leitura são removidas do servidor
    // para não acumular arquivos órfãos.
    _state.leituraColetivaDraft.forEach(item => {
        if (item.foto && item.foto.id) _fotoExcluirPendente(item.foto.id);
    });
    _state.leituraColetivaDraft.clear();
    document.querySelectorAll('.col-check').forEach(cb => { cb.checked = false; });
    document.querySelectorAll('.col-leitura-input').forEach(inp => { inp.value = ''; });
    const checkTodos = document.getElementById('selectAll');
    if (checkTodos) checkTodos.checked = false;
    _leituraRenderizarColetiva();
}

function leituraMudarPagina(delta) {
    // O HTML passa delta relativo: -1 (anterior) ou +1 (próximo)
    // Os valores digitados/marcados ficam em _state.leituraColetivaDraft e são
    // restaurados automaticamente pelo render ao voltar para qualquer página.
    const novaPagina = (_state.paginaAtual || 1) + delta;
    if (novaPagina < 1 || novaPagina > _state.totalPaginas) return;
    leituraCarregarHidrometrosAtivos(novaPagina);
}

async function leituraLancarSelecionados() {
    const dataLeituraInput = document.getElementById('col_data_leitura')?.value;
    if (!dataLeituraInput) { _toast('Informe a data de leitura.', 'warning'); return; }

    // Monta o lote com TODAS as leituras acumuladas durante a navegação entre páginas
    // (não apenas as visíveis na página atual)
    const leituras = [];
    _state.leituraColetivaDraft.forEach((item, hidrometro_id) => {
        if (item.selecionado && item.leitura != null && !isNaN(item.leitura) && item.leitura >= 0) {
            const linha = { hidrometro_id, leitura: item.leitura, selecionado: true };
            if (item.foto && item.foto.id) linha.foto_id = item.foto.id;
            leituras.push(linha);
        }
    });

    if (leituras.length === 0) {
        _toast('Nenhuma leitura selecionada e preenchida para lançamento.', 'warning');
        return;
    }

    // "YYYY-MM-DDTHH:MM" (datetime-local) -> "YYYY-MM-DD HH:MM:00"
    const dataLeitura = dataLeituraInput.replace('T', ' ') + ':00';

    const btn = document.getElementById('btnLancarColetiva');
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Lançando ${leituras.length}...`; }

    try {
        // Envio único: o backend grava tudo dentro de uma transação (tudo ou nada)
        const res = await _apiCall(API_LEITURAS, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify({ dataLeitura, leituras }),
        });

        if (res.sucesso) {
            _toast(res.mensagem || `${leituras.length} leitura(s) lançada(s) com sucesso!`, 'success');
            // Só limpa o rascunho quando o backend confirma que gravou — nada se perde em caso de erro
            _state.leituraColetivaDraft.clear();
            leituraCarregarHidrometrosAtivos(1);
            leituraBuscarHistorico();
        } else {
            const detalhes = Array.isArray(res.dados?.erros) ? ' — ' + res.dados.erros.join(' | ') : '';
            _toast((res.mensagem || 'Erro ao lançar leituras.') + detalhes, 'error');
        }
    } catch (err) {
        _toast(`Erro: ${err.message}`, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Lançar Selecionados'; }
        _leituraAtualizarContadores();
    }
}

// ============================================================
// LEITURAS — HISTÓRICO
// ============================================================

async function leituraBuscarHistorico() {
    const unidade = document.getElementById('hist_unidade')?.value || '';
    const de      = document.getElementById('hist_data_inicial')?.value || '';
    const ate     = document.getElementById('hist_data_final')?.value || '';
    const container = document.getElementById('listaHistorico');
    if (!container) return;

    container.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:2rem;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    try {
        let url = `${API_LEITURAS}?historico=1`;
        if (unidade) url += `&unidade=${encodeURIComponent(unidade)}`;
        if (de)      url += `&data_inicial=${encodeURIComponent(de)}`;
        if (ate)     url += `&data_final=${encodeURIComponent(ate)}`;

        const data = await _apiCall(url);
        const lista = data.dados || data.leituras || [];
        _state.historicoCache = lista;

        if (lista.length === 0) {
            container.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-inbox"></i> Nenhuma leitura encontrada.</td></tr>';
            return;
        }

        container.innerHTML = lista.map(l => `
            <tr>
                <td>${_esc(l.data_leitura_formatada || l.data_leitura)}</td>
                <td>${_esc(l.unidade)}</td>
                <td>${_esc(l.morador_nome)}</td>
                <td>${_esc(l.numero_hidrometro)}</td>
                <td>${_esc(l.leitura_anterior)} m³</td>
                <td>${_esc(l.leitura_atual)} m³</td>
                <td><strong>${_esc(l.consumo)} m³</strong></td>
                <td><strong style="color:#16a34a;">${parseFloat(l.valor_total || 0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}</strong></td>
                <td>${_esc(l.lancado_por_descricao || '—')}</td>
                <td style="text-align:center;">${parseInt(l.total_fotos || 0, 10) > 0
                    ? `<button type="button" class="action-btn view" title="Ver evidência fotográfica" onclick="window.HidrometroPage.fotoAbrirVisualizadorLeitura(${l.id})"><i class="fas fa-eye"></i></button>`
                    : '<span style="color:#94a3b8;">—</span>'}</td>
            </tr>
        `).join('');
    } catch (err) {
        container.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:2rem;color:#ef4444;"><i class="fas fa-exclamation-circle"></i> Erro: ${_esc(err.message)}</td></tr>`;
    }
}

// ============================================================
// LEITURAS — CONFIGURAÇÃO DE PERÍODO
// ============================================================

async function leituraCarregarConfigPeriodo() {
    try {
        const data = await _apiCall(API_CONFIG_PERIODO);
        const config = data.dados || data.config || {};
        const campos = {
            'config_periodo_inicio' : config.periodo_inicio || '',
            'config_periodo_fim'    : config.periodo_fim    || '',
            'config_valor_m3'       : config.valor_m3       || VALOR_M3,
            'config_valor_minimo'   : config.valor_minimo   || VALOR_MINIMO,
            'config_consumo_minimo' : config.consumo_minimo || CONSUMO_MINIMO,
        };
        Object.entries(campos).forEach(([id, val]) => {
            const el = document.getElementById(id);
            if (el) el.value = val;
        });
    } catch (err) {
        console.warn('[Leitura] Config período não disponível:', err.message);
    }
}

async function _leituraSalvarConfigPeriodo() {
    const btn = document.getElementById('btnSalvarConfig');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    const payload = {
        periodo_inicio  : document.getElementById('config_periodo_inicio')?.value,
        periodo_fim     : document.getElementById('config_periodo_fim')?.value,
        valor_m3        : parseFloat(document.getElementById('config_valor_m3')?.value || VALOR_M3),
        valor_minimo    : parseFloat(document.getElementById('config_valor_minimo')?.value || VALOR_MINIMO),
        consumo_minimo  : parseFloat(document.getElementById('config_consumo_minimo')?.value || CONSUMO_MINIMO),
    };

    try {
        const data = await _apiCall(API_CONFIG_PERIODO, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(payload),
        });
        _toast(data.sucesso ? 'Configuração salva com sucesso!' : (data.mensagem || 'Erro ao salvar.'), data.sucesso ? 'success' : 'error');
    } catch (err) {
        _toast(`Erro: ${err.message}`, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Configuração'; }
    }
}

// ============================================================
// RELATÓRIOS — Seletor de tipo (mesmo padrão de Moradores > Relatórios)
// ============================================================

const API_RELATORIOS_HIDRO = window.location.origin + '/api/api_relatorios_hidrometro.php';

let _relTipoAtual   = null; // tipo de relatório selecionado
let _relDadosAtuais = null; // payload da última geração — usado por CSV/PDF
let _relGrafico     = null; // instância Chart.js ativa

const REL_TIPOS_HIDRO = {
    geral:      { titulo: 'Relatório Geral de Consumo',      icon: '<i class="fas fa-tint"></i>',                 filtros: ['data', 'unidade'] },
    evolucao:   { titulo: 'Evolução de Consumo',             icon: '<i class="fas fa-chart-line"></i>',           filtros: ['data', 'unidade'] },
    alertas:    { titulo: 'Alertas de Consumo',              icon: '<i class="fas fa-exclamation-triangle"></i>', filtros: ['data', 'unidade'] },
    inativos:   { titulo: 'Histórico de Hidrômetros Inativos', icon: '<i class="fas fa-ban"></i>',                filtros: ['data', 'unidade', 'motivo'] },
    ranking:    { titulo: 'Ranking de Consumo',              icon: '<i class="fas fa-trophy"></i>',               filtros: ['data'] },
    financeiro: { titulo: 'Relatório Financeiro da Água',    icon: '<i class="fas fa-coins"></i>',                filtros: ['data', 'unidade'] },
    unidade:    { titulo: 'Histórico Completo por Unidade',  icon: '<i class="fas fa-file-invoice"></i>',         filtros: ['data', 'unidadeObrigatoria'] },
    analitico:  { titulo: 'Relatório de Consumo Analítico',  icon: '<i class="fas fa-chart-area"></i>',           filtros: ['data', 'unidade', 'status', 'ordenacao', 'busca'] },
};

// Estado da paginação/ordenação client-side do Relatório de Consumo Analítico
const REL_ANALITICO_PAGE_SIZE = 50;
let _relAnaliticoLinhasFiltradas = []; // após busca rápida — o que é exibido/paginado/exportado
let _relAnaliticoPaginaAtual = 1;
let _relAnaliticoOrdemColuna = null;   // { campo, asc } — clique nos cabeçalhos da tabela
let _relAnaliticoGraficos = { unidade: null, evolucao: null, distribuicao: null };

const REL_FILTROS_CACHE_KEY = 'hidrometro_rel_filtros_cache';

function _relSalvarFiltrosCache() {
    if (!_relTipoAtual) return;
    try {
        const cache = JSON.parse(sessionStorage.getItem(REL_FILTROS_CACHE_KEY) || '{}');
        cache[_relTipoAtual] = {
            data_inicial: document.getElementById('rel_data_inicial')?.value || '',
            data_final  : document.getElementById('rel_data_final')?.value   || '',
            unidade     : document.getElementById('rel_unidade')?.value      || '',
            motivo      : document.getElementById('rel_motivo')?.value       || '',
            status      : document.getElementById('rel_status')?.value      || '',
            ordenacao   : document.getElementById('rel_ordenacao')?.value    || '',
        };
        sessionStorage.setItem(REL_FILTROS_CACHE_KEY, JSON.stringify(cache));
    } catch (e) { /* sessionStorage indisponível — cache é apenas conveniência, não é crítico */ }
}

function _relCarregarFiltrosCache(tipo) {
    try {
        const cache = JSON.parse(sessionStorage.getItem(REL_FILTROS_CACHE_KEY) || '{}');
        const f = cache[tipo];
        if (!f) return;
        if (document.getElementById('rel_data_inicial')) document.getElementById('rel_data_inicial').value = f.data_inicial || '';
        if (document.getElementById('rel_data_final'))   document.getElementById('rel_data_final').value   = f.data_final   || '';
        if (document.getElementById('rel_unidade'))      document.getElementById('rel_unidade').value      = f.unidade     || '';
        if (document.getElementById('rel_motivo'))       document.getElementById('rel_motivo').value       = f.motivo      || '';
        if (document.getElementById('rel_status'))       document.getElementById('rel_status').value       = f.status      || '';
        if (document.getElementById('rel_ordenacao'))     document.getElementById('rel_ordenacao').value    = f.ordenacao   || '';
    } catch (e) { /* sessionStorage indisponível — segue sem restaurar */ }
}

// ── Seleção de tipo de relatório ─────────────────────────────
function relSelecionarTipo(tipo) {
    const cfg = REL_TIPOS_HIDRO[tipo];
    if (!cfg) return;
    _relTipoAtual = tipo;

    document.querySelectorAll('.rel-tipo-card').forEach(c => {
        c.classList.toggle('ativo', c.dataset.tipo === tipo);
    });

    const elIcon = document.getElementById('rel-painel-icon');
    if (elIcon) elIcon.innerHTML = cfg.icon;
    _setEl('rel-painel-nome', cfg.titulo);

    _relToggleFiltro('rel-grupo-data-inicial', cfg.filtros.includes('data'));
    _relToggleFiltro('rel-grupo-data-final',   cfg.filtros.includes('data'));
    _relToggleFiltro('rel-grupo-unidade',      cfg.filtros.includes('unidade') || cfg.filtros.includes('unidadeObrigatoria'));
    _relToggleFiltro('rel-grupo-motivo',       cfg.filtros.includes('motivo'));
    _relToggleFiltro('rel-grupo-status',       cfg.filtros.includes('status'));
    _relToggleFiltro('rel-grupo-ordenacao',    cfg.filtros.includes('ordenacao'));

    const painel = document.getElementById('rel-painel');
    if (painel) { painel.style.display = ''; painel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

    _relToggleFiltro('rel-resumo-alertas', tipo === 'alertas');
    _relToggleFiltro('rel-resumo-analitico', tipo === 'analitico');
    _relToggleFiltro('rel-grafico-wrap', ['evolucao', 'ranking', 'financeiro', 'unidade'].includes(tipo));
    _relToggleFiltro('rel-analitico-graficos', tipo === 'analitico');
    _relToggleFiltro('rel-analitico-busca-wrap', tipo === 'analitico');
    _relToggleFiltro('rel-analitico-paginacao', tipo === 'analitico');
    _relToggleFiltro('rel-tabela2-container', false);

    _relCarregarFiltrosCache(tipo);
    relGerar();
}

function _relToggleFiltro(id, mostrar) {
    const el = document.getElementById(id);
    if (el) el.style.display = mostrar ? '' : 'none';
}

// ── Geração do relatório (chamada pelo botão "Gerar" e pelos filtros) ──
async function relGerar() {
    if (!_relTipoAtual) return;

    const dataInicial = document.getElementById('rel_data_inicial')?.value || '';
    const dataFinal   = document.getElementById('rel_data_final')?.value   || '';
    const unidade     = document.getElementById('rel_unidade')?.value      || '';
    const motivo      = document.getElementById('rel_motivo')?.value       || '';

    if (dataInicial && dataFinal && dataFinal < dataInicial) {
        _toast('A Data Final não pode ser anterior à Data Inicial.', 'warning');
        return;
    }
    if (_relTipoAtual === 'unidade' && !unidade) {
        _setEl('rel-contador-texto', 'Selecione uma unidade para gerar este relatório.');
        _relSetTabela(['Data', 'Leitura', 'Consumo', 'Valor'], [], 'Selecione uma unidade para gerar este relatório.');
        return;
    }
    if (_relTipoAtual === 'analitico' && (!dataInicial || !dataFinal)) {
        _setEl('rel-contador-texto', 'Informe a Data Inicial e a Data Final para gerar este relatório.');
        _relSetTabelaAnalitico([]);
        return;
    }

    _relSalvarFiltrosCache();

    const loading = document.getElementById('loadingRelatorio');
    if (loading) loading.style.display = 'flex';

    try {
        if (_relTipoAtual === 'geral') {
            await _relGerarGeral(dataInicial, dataFinal, unidade);
        } else if (_relTipoAtual === 'analitico') {
            const status    = document.getElementById('rel_status')?.value    || '';
            const ordenacao = document.getElementById('rel_ordenacao')?.value  || 'unidade';
            await _relGerarAnalitico(dataInicial, dataFinal, unidade, status, ordenacao);
        } else {
            const params = new URLSearchParams({ tipo: _relTipoAtual });
            if (dataInicial) params.set('data_de', dataInicial);
            if (dataFinal)   params.set('data_ate', dataFinal);
            if (unidade)     params.set('unidade', unidade);
            if (motivo)      params.set('motivo', motivo);

            const data = await _apiCall(`${API_RELATORIOS_HIDRO}?${params.toString()}`);
            if (!data.sucesso) throw new Error(data.mensagem);
            _relDadosAtuais = data.dados;

            const renderers = {
                evolucao:   _relRenderEvolucao,
                alertas:    _relRenderAlertas,
                inativos:   _relRenderInativos,
                ranking:    _relRenderRanking,
                financeiro: _relRenderFinanceiro,
                unidade:    _relRenderUnidade,
            };
            renderers[_relTipoAtual]?.(data.dados);
        }
    } catch (err) {
        console.error('[Relatório]', err);
        _toast(`Erro ao gerar relatório: ${err.message}`, 'error');
        _setEl('rel-contador-texto', 'Erro ao gerar relatório.');
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

// ── KPIs (reaproveita o mesmo .stats-grid usado em todo o módulo) ────
function _relAtualizarKpis(vals) {
    const kpiGrid = document.getElementById('kpiRelatorio');
    if (!vals) { if (kpiGrid) kpiGrid.style.display = 'none'; return; }
    if (kpiGrid) kpiGrid.style.display = 'grid';
    _setEl('rel_kpi_total',   vals.total);
    _setEl('rel_kpi_consumo', `${vals.consumo.toFixed(2)} m³`);
    _setEl('rel_kpi_valor',   vals.valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));
    _setEl('rel_kpi_media',   `${vals.media.toFixed(2)} m³`);
}

// ── Tabelas genéricas (tabela principal + tabela secundária) ────────
function _relSetTabela(headers, rows, emptyMsg) {
    const thead = document.getElementById('rel-tabela-thead');
    const tbody = document.getElementById('rel-tabela-tbody');
    if (thead) thead.innerHTML = '<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="${headers.length}"><i class="fas fa-inbox"></i><p>${emptyMsg || 'Nenhum hidrômetro encontrado para os filtros informados.'}</p></td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(cols => '<tr>' + cols.map(c => `<td>${c}</td>`).join('') + '</tr>').join('');
}

function _relSetTabela2(headers, rows) {
    const thead = document.getElementById('rel-tabela2-thead');
    const tbody = document.getElementById('rel-tabela2-tbody');
    if (thead) thead.innerHTML = '<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
    if (tbody) tbody.innerHTML = rows.map(cols => '<tr>' + cols.map(c => `<td>${c}</td>`).join('') + '</tr>').join('');
}

// ── Gráficos (Chart.js, carregado sob demanda — mesmo padrão de moradores.js) ──
function _relCarregarChartJs(callback) {
    if (typeof Chart !== 'undefined') { callback(); return; }
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    script.onload = callback;
    document.head.appendChild(script);
}

function _relRenderGraficoSimples(labels, valores, label, tipo, cor) {
    const canvas = document.getElementById('rel-grafico');
    if (!canvas) return;
    _relCarregarChartJs(() => {
        if (_relGrafico) { _relGrafico.destroy(); _relGrafico = null; }
        _relGrafico = new Chart(canvas, {
            type: tipo || 'bar',
            data: {
                labels,
                datasets: [{
                    label, data: valores,
                    backgroundColor: (cor || '#2563eb') + 'B3',
                    borderColor: cor || '#1e3a8a',
                    borderWidth: 2, borderRadius: 6, tension: 0.3,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } },
                scales: { y: { beginAtZero: true } },
            },
        });
    });
}

function _relRenderGraficoDuoEixo(labels, consumo, valor) {
    const canvas = document.getElementById('rel-grafico');
    if (!canvas) return;
    _relCarregarChartJs(() => {
        if (_relGrafico) { _relGrafico.destroy(); _relGrafico = null; }
        _relGrafico = new Chart(canvas, {
            data: {
                labels,
                datasets: [
                    { type: 'bar',  label: 'Consumo (m³)', data: consumo, backgroundColor: '#2563ebB3', borderColor: '#1e3a8a', borderWidth: 1, yAxisID: 'y' },
                    { type: 'line', label: 'Receita (R$)', data: valor,   borderColor: '#16a34a', backgroundColor: '#16a34a33', borderWidth: 2, tension: 0.3, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { type: 'linear', position: 'left',  beginAtZero: true, title: { display: true, text: 'Consumo (m³)' } },
                    y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Receita (R$)' } },
                },
            },
        });
    });
}

// ── 1. Relatório Geral de Consumo (endpoint já existente — api_leituras.php?relatorio=1) ──
async function _relGerarGeral(de, ate, unidade) {
    let url = `${API_LEITURAS}?relatorio=1`;
    if (de)      url += `&data_de=${encodeURIComponent(de)}`;
    if (ate)     url += `&data_ate=${encodeURIComponent(ate)}`;
    if (unidade) url += `&unidade=${encodeURIComponent(unidade)}`;

    const data = await _apiCall(url);
    if (!data.sucesso) throw new Error(data.mensagem);
    const leituras = data.dados || [];
    _relDadosAtuais = { leituras };

    _relToggleFiltro('rel-grafico-wrap', false);
    _relToggleFiltro('rel-tabela2-container', false);

    if (leituras.length === 0) {
        _relAtualizarKpis(null);
        _setEl('rel-contador-texto', 'Nenhum dado encontrado para os filtros informados.');
        _relSetTabela(['Unidade', 'Morador', 'Nº Hidrômetro', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)', 'Consumo Médio (m³)', 'Última Leitura'], []);
        return;
    }

    const totalConsumo = leituras.reduce((s, l) => s + parseFloat(l.consumo || 0), 0);
    const totalValor   = leituras.reduce((s, l) => s + parseFloat(l.valor_total || 0), 0);
    _relAtualizarKpis({ total: leituras.length, consumo: totalConsumo, valor: totalValor, media: totalConsumo / leituras.length });

    const porHidro = {};
    leituras.forEach(l => {
        const key = l.numero_hidrometro || l.hidrometro_id;
        if (!porHidro[key]) {
            porHidro[key] = { unidade: l.unidade, morador: l.morador_nome, numero_hidrometro: l.numero_hidrometro, leituras: 0, consumo_total: 0, valor_total: 0, ultima_leitura: '' };
        }
        porHidro[key].leituras++;
        porHidro[key].consumo_total += parseFloat(l.consumo || 0);
        porHidro[key].valor_total   += parseFloat(l.valor_total || 0);
        const dataL = l.data_leitura_formatada || l.data_leitura || '';
        if (dataL > porHidro[key].ultima_leitura) porHidro[key].ultima_leitura = dataL;
    });
    const linhas = Object.values(porHidro);
    _relDadosAtuais = { leituras, linhas };

    _setEl('rel-contador-texto', `${linhas.length} hidrômetro(s) encontrado(s) — ${leituras.length} leitura(s) no período.`);

    const rows = linhas.map(h => [
        `<span class="rel-badge-unidade">${_esc(h.unidade)}</span>`,
        _esc(h.morador),
        `<strong>${_esc(h.numero_hidrometro)}</strong>`,
        `<span style="display:block;text-align:center;">${h.leituras}</span>`,
        `<strong>${h.consumo_total.toFixed(2)} m³</strong>`,
        `<strong style="color:#16a34a;">${h.valor_total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</strong>`,
        `${(h.consumo_total / h.leituras).toFixed(2)} m³`,
        _esc(h.ultima_leitura),
    ]);
    _relSetTabela(['Unidade', 'Morador', 'Nº Hidrômetro', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)', 'Consumo Médio (m³)', 'Última Leitura'], rows);
}

// ── 2. Evolução de Consumo ───────────────────────────────────
function _relRenderEvolucao(dados) {
    const mensal    = dados.mensal    || [];
    const detalhado = dados.detalhado || [];

    _relToggleFiltro('rel-grafico-wrap', mensal.length > 0);

    if (!mensal.length) {
        _relAtualizarKpis(null);
        _setEl('rel-contador-texto', 'Nenhum dado encontrado para os filtros informados.');
        _relSetTabela(['Mês', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)'], []);
        _relToggleFiltro('rel-tabela2-container', false);
        return;
    }

    const totalLeituras = mensal.reduce((s, m) => s + parseInt(m.leituras || 0, 10), 0);
    const totalConsumo  = mensal.reduce((s, m) => s + parseFloat(m.consumo_total || 0), 0);
    const totalValor    = mensal.reduce((s, m) => s + parseFloat(m.valor_total || 0), 0);
    _relAtualizarKpis({ total: totalLeituras, consumo: totalConsumo, valor: totalValor, media: totalConsumo / mensal.length });

    _setEl('rel-contador-texto', `${mensal.length} mês(es) no período selecionado.`);

    const rows = mensal.map(m => [
        `<strong>${_esc(m.mes_label)}</strong>`,
        `<span style="display:block;text-align:center;">${m.leituras}</span>`,
        `${parseFloat(m.consumo_total).toFixed(2)} m³`,
        `${parseFloat(m.valor_total).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`,
    ]);
    _relSetTabela(['Mês', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)'], rows);

    _relToggleFiltro('rel-tabela2-container', detalhado.length > 0);
    _setEl('rel-tabela2-titulo', 'Detalhamento por Unidade e Mês');
    const rows2 = detalhado.map(d => [
        _esc(d.mes_label),
        `<span class="rel-badge-unidade">${_esc(d.unidade)}</span>`,
        `${parseFloat(d.consumo_total).toFixed(2)} m³`,
        `${parseFloat(d.valor_total).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`,
    ]);
    _relSetTabela2(['Mês', 'Unidade', 'Consumo (m³)', 'Valor (R$)'], rows2);

    _relRenderGraficoSimples(mensal.map(m => m.mes_label), mensal.map(m => parseFloat(m.consumo_total)), 'Consumo Total (m³)', 'bar', '#2563eb');
}

// ── 3. Alertas de Consumo ────────────────────────────────────
function _relRenderAlertas(dados) {
    const resumo   = dados.resumo   || { zero: 0, moderado: 0, alto: 0, vazio: 0, total: 0 };
    const alertas  = dados.alertas  || [];

    _relAtualizarKpis(null);
    _relToggleFiltro('rel-grafico-wrap', false);
    _relToggleFiltro('rel-tabela2-container', false);

    _setEl('rel_alerta_zero',     resumo.zero);
    _setEl('rel_alerta_moderada', resumo.moderado);
    _setEl('rel_alerta_alta',     resumo.alto);
    _setEl('rel_alerta_vazio',    resumo.vazio);

    _setEl('rel-contador-texto', resumo.total > 0
        ? `⚠ ${resumo.total} alerta(s) encontrado(s) no período.`
        : 'Nenhum alerta encontrado — consumo dentro do padrão em todas as unidades.');

    const labelsCategoria = { zero: 'Sem Consumo', moderado: 'Oscilação Moderada', alto: 'Oscilação Alta', vazio: 'Possível Imóvel Vazio' };
    const rows = alertas.map(a => {
        const badge = `<span class="rel-alerta-badge ${a.categoria}">${labelsCategoria[a.categoria] || a.categoria}</span>`;
        const detalhe = a.categoria === 'zero'
            ? (a.dias_sem_consumo != null ? `${a.dias_sem_consumo} dia(s) sem consumo` : 'Sem leituras registradas')
            : `Média: ${a.consumo_medio ?? '—'} m³ | Última: ${a.consumo_atual ?? '—'} m³` +
              (a.oscilacao_pct != null ? ` (${a.oscilacao_pct > 0 ? '+' : ''}${a.oscilacao_pct}%)` : '');
        const msg = a.mensagem ? `<span class="rel-alerta-msg">${_esc(a.mensagem)}</span>` : '';
        return [
            `<span class="rel-badge-unidade">${_esc(a.unidade)}</span>`,
            _esc(a.morador_nome || '—'),
            _esc(a.numero_hidrometro),
            badge,
            `${detalhe}${msg}`,
            _esc(a.ultima_leitura || '—'),
        ];
    });
    _relSetTabela(['Unidade', 'Morador', 'Nº Hidrômetro', 'Categoria', 'Detalhes', 'Última Leitura'], rows,
        'Nenhum alerta encontrado — consumo dentro do padrão em todas as unidades.');
}

// ── 4. Histórico de Hidrômetros Inativos ─────────────────────
function _relRenderInativos(linhas) {
    linhas = linhas || [];
    _relAtualizarKpis(null);
    _relToggleFiltro('rel-grafico-wrap', false);
    _relToggleFiltro('rel-tabela2-container', false);

    _setEl('rel-contador-texto', linhas.length
        ? `${linhas.length} hidrômetro(s) inativo(s) encontrado(s).`
        : 'Nenhum hidrômetro inativo encontrado para os filtros informados.');

    const rows = linhas.map(h => [
        `<span class="rel-badge-unidade">${_esc(h.unidade)}</span>`,
        _esc(h.morador_nome || '—'),
        _esc(h.numero_hidrometro),
        _esc(h.data_instalacao_fmt),
        _esc(h.data_inativacao_fmt || '—'),
        _esc(h.motivo || '—'),
        h.ultima_leitura != null ? `${parseFloat(h.ultima_leitura).toFixed(2)} m³` : '<span style="color:#94a3b8;">Sem leitura</span>',
        _esc(h.tempo_operacao || '—'),
    ]);
    _relSetTabela(['Unidade', 'Morador', 'Nº Hidrômetro', 'Data Instalação', 'Data Inativação', 'Motivo', 'Última Leitura', 'Tempo em Operação'], rows,
        'Nenhum hidrômetro inativo encontrado para os filtros informados.');
}

// ── 5. Ranking de Consumo ────────────────────────────────────
function _relRenderRanking(dados) {
    const maiores = dados.maiores || [];
    const menores = dados.menores || [];

    _relAtualizarKpis(null);
    _relToggleFiltro('rel-grafico-wrap', maiores.length > 0);

    _setEl('rel-contador-texto', `Top ${maiores.length} maiores e ${menores.length} menores consumidores no período.`);

    const rowsMaiores = maiores.map((h, i) => [
        `<strong>#${i + 1}</strong>`,
        `<span class="rel-badge-unidade">${_esc(h.unidade)}</span>`,
        _esc(h.morador_nome),
        _esc(h.numero_hidrometro),
        `<strong>${parseFloat(h.consumo_total).toFixed(2)} m³</strong>`,
        `${parseFloat(h.valor_total).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`,
    ]);
    _relSetTabela(['#', 'Unidade', 'Morador', 'Nº Hidrômetro', 'Consumo Total (m³)', 'Valor Total (R$)'], rowsMaiores,
        'Nenhum consumo registrado no período informado.');

    _relToggleFiltro('rel-tabela2-container', menores.length > 0);
    _setEl('rel-tabela2-titulo', '10 Menores Consumidores');
    const rowsMenores = menores.map((h, i) => [
        `#${i + 1}`,
        `<span class="rel-badge-unidade">${_esc(h.unidade)}</span>`,
        _esc(h.morador_nome),
        `${parseFloat(h.consumo_total).toFixed(2)} m³`,
    ]);
    _relSetTabela2(['#', 'Unidade', 'Morador', 'Consumo Total (m³)'], rowsMenores);

    _relRenderGraficoSimples(maiores.map(h => h.unidade), maiores.map(h => parseFloat(h.consumo_total)), 'Consumo Total (m³)', 'bar', '#b45309');
}

// ── 6. Relatório Financeiro da Água ───────────────────────────
function _relRenderFinanceiro(dados) {
    const resumo = dados.resumo || {};
    const mensal = dados.mensal || [];
    const tabela = dados.tabela || [];

    _relAtualizarKpis({
        total:   resumo.total_leituras || 0,
        consumo: parseFloat(resumo.consumo_total || 0),
        valor:   parseFloat(resumo.valor_cobrado || 0),
        media:   resumo.total_leituras > 0 ? parseFloat(resumo.consumo_total || 0) / resumo.total_leituras : 0,
    });

    _relToggleFiltro('rel-grafico-wrap', mensal.length > 0);
    _relToggleFiltro('rel-tabela2-container', false);

    const fmtMoeda = v => parseFloat(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    _setEl('rel-contador-texto',
        `Receita Gerada: ${fmtMoeda(resumo.receita_gerada)} · Valor Médio/Unidade: ${fmtMoeda(resumo.valor_medio_unidade)} · Valor Médio/m³: ${fmtMoeda(resumo.valor_medio_m3)}`);

    const rows = tabela.map(h => [
        `<span class="rel-badge-unidade">${_esc(h.unidade)}</span>`,
        _esc(h.morador_nome),
        _esc(h.numero_hidrometro),
        `<span style="display:block;text-align:center;">${h.leituras}</span>`,
        `${parseFloat(h.consumo_total).toFixed(2)} m³`,
        fmtMoeda(h.valor_total),
    ]);
    _relSetTabela(['Unidade', 'Morador', 'Nº Hidrômetro', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)'], rows);

    _relRenderGraficoDuoEixo(mensal.map(m => m.mes_label), mensal.map(m => parseFloat(m.consumo_total)), mensal.map(m => parseFloat(m.valor_total)));
}

// ── 7. Histórico Completo por Unidade ─────────────────────────
function _relRenderUnidade(dados) {
    const resumo   = dados.resumo   || {};
    const leituras = dados.leituras || [];

    const totalConsumo = leituras.reduce((s, l) => s + parseFloat(l.consumo || 0), 0);
    const totalValor   = leituras.reduce((s, l) => s + parseFloat(l.valor_total || 0), 0);
    _relAtualizarKpis({ total: resumo.total_leituras || 0, consumo: totalConsumo, valor: totalValor, media: parseFloat(resumo.consumo_medio || 0) });

    _relToggleFiltro('rel-grafico-wrap', leituras.length > 0);
    _relToggleFiltro('rel-tabela2-container', false);

    _setEl('rel-contador-texto',
        `Unidade ${resumo.unidade || ''} — Consumo Médio: ${resumo.consumo_medio ?? '—'} m³ · Maior: ${resumo.maior_consumo ?? '—'} m³ · Menor: ${resumo.menor_consumo ?? '—'} m³`);

    const rows = leituras.map(l => [
        _esc(l.data_fmt),
        `${parseFloat(l.leitura_atual).toFixed(2)} m³`,
        `${parseFloat(l.consumo).toFixed(2)} m³`,
        `${parseFloat(l.valor_total).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`,
    ]);
    _relSetTabela(['Data', 'Leitura (m³)', 'Consumo (m³)', 'Valor (R$)'], rows,
        'Nenhuma leitura encontrada para esta unidade no período selecionado.');

    _relRenderGraficoSimples(leituras.map(l => l.data_fmt), leituras.map(l => parseFloat(l.consumo)), 'Consumo (m³)', 'line', '#6b21a8');
}

// ── 8. Relatório de Consumo Analítico ─────────────────────────
async function _relGerarAnalitico(de, ate, unidade, status, ordenacao) {
    const params = new URLSearchParams({ tipo: 'analitico', data_de: de, data_ate: ate });
    if (unidade)   params.set('unidade', unidade);
    if (status)    params.set('status', status);
    if (ordenacao) params.set('ordenacao', ordenacao);

    const data = await _apiCall(`${API_RELATORIOS_HIDRO}?${params.toString()}`);
    if (!data.sucesso) throw new Error(data.mensagem);
    _relDadosAtuais = data.dados;

    _relToggleFiltro('rel-grafico-wrap', false);
    _relToggleFiltro('rel-tabela2-container', false);

    _relAnaliticoPaginaAtual = 1;
    _relAnaliticoOrdemColuna = null;
    const buscaEl = document.getElementById('rel_analitico_busca');
    if (buscaEl) buscaEl.value = '';

    _relRenderAnalitico(data.dados);
}

function _relAnaliticoFmtM3(v) { return v == null ? '—' : `${parseFloat(v).toFixed(2)} m³`; }
function _relAnaliticoFmtRS(v) { return v == null ? '—' : parseFloat(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }

const REL_ANALITICO_SITUACAO_EMOJI = { normal: '🟢', moderado: '🟡', alto: '🔴', zero: '⚪' };

function _relRenderAnalitico(dados) {
    const resumo = dados.resumo || {};

    _relAtualizarKpis({
        total:   resumo.total_unidades || 0,
        consumo: parseFloat(resumo.consumo_total || 0),
        valor:   parseFloat(resumo.valor_total || 0),
        media:   parseFloat(resumo.media_por_unidade || 0),
    });

    _setEl('rel_analitico_maior_consumo', resumo.maior_consumo ? `Unidade ${resumo.maior_consumo.unidade} — ${_relAnaliticoFmtM3(resumo.maior_consumo.valor)}` : '—');
    _setEl('rel_analitico_menor_consumo', resumo.menor_consumo ? `Unidade ${resumo.menor_consumo.unidade} — ${_relAnaliticoFmtM3(resumo.menor_consumo.valor)}` : '—');
    _setEl('rel_analitico_maior_faturamento', resumo.maior_faturamento ? _relAnaliticoFmtRS(resumo.maior_faturamento.valor) : '—');
    _setEl('rel_analitico_media_geral', _relAnaliticoFmtM3(resumo.media_geral));

    _setEl('rel-contador-texto', `${resumo.total_unidades || 0} unidade(s) encontrada(s) no período selecionado.`);

    _relAnaliticoLinhasFiltradas = (dados.linhas || []).slice();
    _relAnaliticoRenderTabela();
    _relAnaliticoRenderGraficos(dados);
}

function _relAnaliticoLinhaCorresponde(l, termo) {
    if (!termo) return true;
    const alvo = `${l.unidade || ''} ${l.morador_nome || ''} ${l.numero_hidrometro || ''}`.toLowerCase();
    return alvo.includes(termo);
}

function relAnaliticoFiltrarBusca() {
    if (!_relDadosAtuais) return;
    const termo = (document.getElementById('rel_analitico_busca')?.value || '').trim().toLowerCase();
    _relAnaliticoLinhasFiltradas = (_relDadosAtuais.linhas || []).filter(l => _relAnaliticoLinhaCorresponde(l, termo));
    _relAnaliticoPaginaAtual = 1;
    _relAnaliticoRenderTabela();
}

function _relAnaliticoOrdenarClique(campo) {
    if (_relAnaliticoOrdemColuna && _relAnaliticoOrdemColuna.campo === campo) {
        _relAnaliticoOrdemColuna.asc = !_relAnaliticoOrdemColuna.asc;
    } else {
        _relAnaliticoOrdemColuna = { campo, asc: true };
    }
    const { asc } = _relAnaliticoOrdemColuna;
    _relAnaliticoLinhasFiltradas.sort((a, b) => {
        const va = a[campo]; const vb = b[campo];
        if (va == null && vb == null) return 0;
        if (va == null) return 1;
        if (vb == null) return -1;
        if (typeof va === 'number' || typeof vb === 'number') return asc ? va - vb : vb - va;
        return asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
    });
    _relAnaliticoPaginaAtual = 1;
    _relAnaliticoRenderTabela();
}

function _relSetTabelaAnalitico(linhasPagina) {
    const colunas = [
        { label: 'Unidade', campo: 'unidade' },
        { label: 'Morador', campo: 'morador_nome' },
        { label: 'Hidrômetro', campo: 'numero_hidrometro' },
        { label: 'Leitura Anterior', campo: 'leitura_anterior' },
        { label: 'Data Anterior', campo: 'data_anterior_fmt' },
        { label: 'Leitura Atual', campo: 'leitura_atual' },
        { label: 'Data Atual', campo: 'data_atual_fmt' },
        { label: 'Consumo (m³)', campo: 'consumo' },
        { label: 'Valor (R$)', campo: 'valor' },
        { label: 'Situação', campo: 'situacao_label' },
    ];

    const thead = document.getElementById('rel-tabela-thead');
    const tbody = document.getElementById('rel-tabela-tbody');
    if (thead) {
        thead.innerHTML = '<tr>' + colunas.map(c => {
            const ativo = _relAnaliticoOrdemColuna && _relAnaliticoOrdemColuna.campo === c.campo;
            const seta = ativo ? (_relAnaliticoOrdemColuna.asc ? ' ▲' : ' ▼') : '';
            return `<th class="rel-th-ordenavel" onclick="window.HidrometroPage.relAnaliticoOrdenarColuna('${c.campo}')">${c.label}${seta}</th>`;
        }).join('') + '</tr>';
    }
    if (!tbody) return;

    if (!linhasPagina.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="${colunas.length}"><i class="fas fa-inbox"></i><p>Nenhum hidrômetro encontrado para os filtros informados.</p></td></tr>`;
        return;
    }

    tbody.innerHTML = linhasPagina.map(l => {
        const emoji = REL_ANALITICO_SITUACAO_EMOJI[l.situacao] || '⚪';
        return '<tr>' + [
            `<span class="rel-badge-unidade">${_esc(l.unidade)}</span>`,
            _esc(l.morador_nome || '—'),
            `<strong>${_esc(l.numero_hidrometro)}</strong>`,
            _relAnaliticoFmtM3(l.leitura_anterior),
            _esc(l.data_anterior_fmt || '—'),
            _relAnaliticoFmtM3(l.leitura_atual),
            _esc(l.data_atual_fmt || '—'),
            l.consumo != null ? `<strong>${_relAnaliticoFmtM3(l.consumo)}</strong>` : '—',
            l.valor != null ? `<strong style="color:#16a34a;">${_relAnaliticoFmtRS(l.valor)}</strong>` : '—',
            `<span class="rel-alerta-badge ${l.situacao}">${emoji} ${_esc(l.situacao_label)}</span>`,
        ].map(c => `<td>${c}</td>`).join('') + '</tr>';
    }).join('');
}

function _relAnaliticoRenderTabela() {
    const total = _relAnaliticoLinhasFiltradas.length;
    const totalPaginas = Math.max(1, Math.ceil(total / REL_ANALITICO_PAGE_SIZE));
    if (_relAnaliticoPaginaAtual > totalPaginas) _relAnaliticoPaginaAtual = totalPaginas;

    const inicio = (_relAnaliticoPaginaAtual - 1) * REL_ANALITICO_PAGE_SIZE;
    const linhasPagina = _relAnaliticoLinhasFiltradas.slice(inicio, inicio + REL_ANALITICO_PAGE_SIZE);

    _relSetTabelaAnalitico(linhasPagina);

    const fim = total === 0 ? 0 : Math.min(inicio + REL_ANALITICO_PAGE_SIZE, total);
    _setEl('rel-analitico-paginacao-texto', `Mostrando ${total === 0 ? 0 : inicio + 1}–${fim} de ${total}`);
}

function relAnaliticoPaginaAnterior() {
    if (_relAnaliticoPaginaAtual <= 1) return;
    _relAnaliticoPaginaAtual--;
    _relAnaliticoRenderTabela();
}

function relAnaliticoProximaPagina() {
    const totalPaginas = Math.max(1, Math.ceil(_relAnaliticoLinhasFiltradas.length / REL_ANALITICO_PAGE_SIZE));
    if (_relAnaliticoPaginaAtual >= totalPaginas) return;
    _relAnaliticoPaginaAtual++;
    _relAnaliticoRenderTabela();
}

function _relAnaliticoRenderGraficos(dados) {
    _relCarregarChartJs(() => {
        const gu = dados.grafico_unidade || { labels: [], valores: [] };
        const canvasU = document.getElementById('rel-analitico-grafico-unidade');
        if (canvasU) {
            if (_relAnaliticoGraficos.unidade) _relAnaliticoGraficos.unidade.destroy();
            _relAnaliticoGraficos.unidade = new Chart(canvasU, {
                type: 'bar',
                data: { labels: gu.labels, datasets: [{ label: 'Consumo (m³)', data: gu.valores, backgroundColor: '#2563ebB3', borderColor: '#1e3a8a', borderWidth: 1, borderRadius: 4 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
            });
        }

        const ge = dados.grafico_evolucao || { labels: [], valores: [] };
        const canvasE = document.getElementById('rel-analitico-grafico-evolucao');
        if (canvasE) {
            if (_relAnaliticoGraficos.evolucao) _relAnaliticoGraficos.evolucao.destroy();
            _relAnaliticoGraficos.evolucao = new Chart(canvasE, {
                type: 'line',
                data: { labels: ge.labels, datasets: [{ label: 'Consumo Total (m³)', data: ge.valores, borderColor: '#1e3a8a', backgroundColor: '#2563eb33', borderWidth: 2, tension: 0.3, fill: true }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
            });
        }

        const gd = dados.grafico_distribuicao || { labels: [], valores: [] };
        const canvasD = document.getElementById('rel-analitico-grafico-distribuicao');
        if (canvasD) {
            if (_relAnaliticoGraficos.distribuicao) _relAnaliticoGraficos.distribuicao.destroy();
            _relAnaliticoGraficos.distribuicao = new Chart(canvasD, {
                type: 'pie',
                data: { labels: gd.labels, datasets: [{ data: gd.valores, backgroundColor: ['#16a34a', '#eab308', '#f97316', '#dc2626'] }] },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
            });
        }
    });
}

// ── Exportar CSV (por tipo — usa os mesmos dados já carregados na tela) ──
function relExportarCSV() {
    if (!_relTipoAtual || !_relDadosAtuais) { _toast('Gere o relatório antes de exportar.', 'warning'); return; }

    let headers = [];
    let rows = [];

    switch (_relTipoAtual) {
        case 'geral':
            headers = ['Data', 'Unidade', 'Morador', 'Nº Hidrômetro', 'Leitura Anterior (m³)', 'Leitura Atual (m³)', 'Consumo (m³)', 'Valor (R$)'];
            rows = (_relDadosAtuais.leituras || []).map(l => [
                l.data_leitura_formatada || l.data_leitura, l.unidade, l.morador_nome, l.numero_hidrometro,
                parseFloat(l.leitura_anterior || 0).toFixed(2).replace('.', ','),
                parseFloat(l.leitura_atual || 0).toFixed(2).replace('.', ','),
                parseFloat(l.consumo || 0).toFixed(2).replace('.', ','),
                parseFloat(l.valor_total || 0).toFixed(2).replace('.', ','),
            ]);
            break;
        case 'evolucao':
            headers = ['Mês', 'Unidade', 'Consumo (m³)', 'Valor (R$)'];
            rows = (_relDadosAtuais.detalhado || []).map(d => [
                d.mes_label, d.unidade,
                parseFloat(d.consumo_total).toFixed(2).replace('.', ','),
                parseFloat(d.valor_total).toFixed(2).replace('.', ','),
            ]);
            break;
        case 'alertas':
            headers = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Categoria', 'Consumo Médio (m³)', 'Consumo Atual (m³)', 'Oscilação (%)', 'Última Leitura', 'Dias sem Consumo'];
            rows = (_relDadosAtuais.alertas || []).map(a => [
                a.unidade, a.morador_nome || '', a.numero_hidrometro, a.categoria,
                a.consumo_medio ?? '', a.consumo_atual ?? '', a.oscilacao_pct ?? '', a.ultima_leitura || '', a.dias_sem_consumo ?? '',
            ]);
            break;
        case 'inativos':
            headers = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Data Instalação', 'Data Inativação', 'Motivo', 'Última Leitura', 'Tempo em Operação'];
            rows = (Array.isArray(_relDadosAtuais) ? _relDadosAtuais : []).map(h => [
                h.unidade, h.morador_nome || '', h.numero_hidrometro, h.data_instalacao_fmt,
                h.data_inativacao_fmt || '', h.motivo || '', h.ultima_leitura ?? '', h.tempo_operacao || '',
            ]);
            break;
        case 'ranking':
            headers = ['#', 'Grupo', 'Unidade', 'Morador', 'Consumo Total (m³)', 'Valor Total (R$)'];
            (_relDadosAtuais.maiores || []).forEach((h, i) => rows.push([i + 1, 'Maior Consumo', h.unidade, h.morador_nome,
                parseFloat(h.consumo_total).toFixed(2).replace('.', ','), parseFloat(h.valor_total).toFixed(2).replace('.', ',')]));
            (_relDadosAtuais.menores || []).forEach((h, i) => rows.push([i + 1, 'Menor Consumo', h.unidade, h.morador_nome,
                parseFloat(h.consumo_total).toFixed(2).replace('.', ','), parseFloat(h.valor_total).toFixed(2).replace('.', ',')]));
            break;
        case 'financeiro':
            headers = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Leituras', 'Consumo Total (m³)', 'Valor Total (R$)'];
            rows = (_relDadosAtuais.tabela || []).map(h => [
                h.unidade, h.morador_nome, h.numero_hidrometro, h.leituras,
                parseFloat(h.consumo_total).toFixed(2).replace('.', ','), parseFloat(h.valor_total).toFixed(2).replace('.', ','),
            ]);
            break;
        case 'unidade':
            headers = ['Data', 'Leitura (m³)', 'Consumo (m³)', 'Valor (R$)'];
            rows = (_relDadosAtuais.leituras || []).map(l => [
                l.data_fmt, parseFloat(l.leitura_atual).toFixed(2).replace('.', ','),
                parseFloat(l.consumo).toFixed(2).replace('.', ','), parseFloat(l.valor_total).toFixed(2).replace('.', ','),
            ]);
            break;
        case 'analitico':
            // Exporta TODOS os registros que passam pela busca rápida atual, sem paginação.
            headers = ['Unidade', 'Morador', 'Hidrômetro', 'Leitura Anterior (m³)', 'Data Anterior', 'Leitura Atual (m³)', 'Data Atual', 'Consumo (m³)', 'Valor (R$)', 'Situação'];
            rows = _relAnaliticoLinhasFiltradas.map(l => [
                l.unidade, l.morador_nome || '', l.numero_hidrometro,
                l.leitura_anterior != null ? l.leitura_anterior.toFixed(2).replace('.', ',') : '',
                l.data_anterior_fmt || '',
                l.leitura_atual != null ? l.leitura_atual.toFixed(2).replace('.', ',') : '',
                l.data_atual_fmt || '',
                l.consumo != null ? l.consumo.toFixed(2).replace('.', ',') : '',
                l.valor != null ? l.valor.toFixed(2).replace('.', ',') : '',
                l.situacao_label,
            ]);
            break;
    }

    if (!rows.length) { _toast('Nenhum dado para exportar.', 'warning'); return; }

    const csv  = [headers.join(';'), ...rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';'))].join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `relatorio_hidrometro_${_relTipoAtual}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    _toast('Relatório exportado com sucesso!', 'success');
}

// ── Exportar PDF (mesmo padrão do restante do ERP: página HTML pronta para impressão) ──
function relExportarPDF() {
    if (!_relTipoAtual) { _toast('Selecione um tipo de relatório primeiro.', 'warning'); return; }

    const dataInicial = document.getElementById('rel_data_inicial')?.value || '';
    const dataFinal   = document.getElementById('rel_data_final')?.value   || '';
    const unidade     = document.getElementById('rel_unidade')?.value      || '';
    const motivo      = document.getElementById('rel_motivo')?.value       || '';
    const status      = document.getElementById('rel_status')?.value      || '';
    const ordenacao   = document.getElementById('rel_ordenacao')?.value    || '';

    if (_relTipoAtual === 'unidade' && !unidade) { _toast('Selecione uma unidade para gerar o PDF.', 'warning'); return; }
    if (_relTipoAtual === 'analitico' && (!dataInicial || !dataFinal)) { _toast('Informe a Data Inicial e a Data Final para gerar o PDF.', 'warning'); return; }

    const params = new URLSearchParams({ tipo: _relTipoAtual });
    if (dataInicial) params.set('data_de', dataInicial);
    if (dataFinal)   params.set('data_ate', dataFinal);
    if (unidade)     params.set('unidade', unidade);
    if (motivo)      params.set('motivo', motivo);
    if (_relTipoAtual === 'analitico') {
        if (status)    params.set('status', status);
        if (ordenacao) params.set('ordenacao', ordenacao);
    }

    const base = window.location.origin + '/api/api_relatorio_hidrometro_pdf.php';
    window.open(base + '?' + params.toString(), '_blank');
}

// ============================================================
// EVIDÊNCIA FOTOGRÁFICA DAS LEITURAS
// ============================================================
// Fluxo: usuário clica em "Foto da Leitura" (individual) ou no ícone da
// coluna Foto (coletiva) → menu (Tirar Foto / Anexar Arquivo) → captura ou
// seleciona → pré-visualização → confirma → a imagem é comprimida no
// navegador (máx. 1920px, JPEG 80%) e enviada para api_leituras_fotos.php
// SEM leitura_id ainda. Só quando a leitura é efetivamente registrada
// (individual ou coletiva) o foto_id é enviado a api_leituras.php, que
// vincula a foto à leitura recém-criada. Enquanto não vinculada, a foto
// pode ser removida (botão "remover" ou ao limpar o formulário/seleção).

const API_LEITURAS_FOTOS = window.location.origin + '/api/api_leituras_fotos.php';
const API_FOTO_VIEW      = window.location.origin + '/api/visualizar_foto_leitura.php';
const FOTO_MAX_DIMENSAO  = 1920;
const FOTO_QUALIDADE     = 0.8;

let _fotoContexto           = null;  // { tipo: 'individual' } | { tipo: 'coletiva', hidrometroId }
let _fotoArquivoAtual       = null;  // Blob/File pendente de confirmação
let _fotoOrigemAtual        = 'upload';
let _fotoStream              = null; // MediaStream da câmera ativa
let _fotoPendenteIndividual  = null; // { id, previewUrl }
let _fotoGaleriaLista        = [];
let _fotoGaleriaIndice       = 0;

// ---- Menu (Tirar Foto / Anexar Arquivo) -----------------------------------
function fotoAbrirMenuIndividual() {
    _fotoContexto = { tipo: 'individual' };
    abrirModal('modalFotoMenu');
}

function fotoAbrirMenuColetiva(hidrometroId) {
    _fotoContexto = { tipo: 'coletiva', hidrometroId: parseInt(hidrometroId, 10) };
    abrirModal('modalFotoMenu');
}

function fotoFecharMenu() {
    fecharModal('modalFotoMenu');
    _fotoContexto = null;
}

function fotoEscolherOpcao(opcao) {
    fecharModal('modalFotoMenu');

    if (opcao === 'upload') {
        const input = document.getElementById('fotoInputArquivo');
        if (input) { input.value = ''; input.click(); }
        return;
    }

    // Câmera — em celulares com câmera, o navegador abre diretamente o
    // hardware de vídeo; em desktop sem câmera, exibimos o erro amigável
    // e o usuário pode usar "Anexar Arquivo" em vez disso.
    _fotoOrigemAtual = 'camera';
    abrirModal('modalFotoCaptura');
    _fotoMostrarEtapaCamera();

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        _fotoMostrarErroCamera('Este dispositivo/navegador não permite acesso à câmera. Use "Anexar Arquivo".');
        return;
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
        .then(stream => {
            _fotoStream = stream;
            const video = document.getElementById('fotoVideo');
            if (video) video.srcObject = stream;
        })
        .catch(err => {
            console.error('[Foto] Erro ao acessar câmera:', err);
            _fotoMostrarErroCamera('Não foi possível acessar a câmera. Verifique as permissões do navegador ou use "Anexar Arquivo".');
        });
}

function _fotoMostrarErroCamera(msg) {
    const erroEl = document.getElementById('fotoCapturaErro');
    if (erroEl) { erroEl.style.display = 'block'; erroEl.textContent = msg; }
}

function fotoInputArquivoChange(input) {
    const file = input.files && input.files[0];
    if (!file) return;

    const tiposAceitos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!tiposAceitos.includes(file.type)) {
        _toast('Formato não permitido. Envie JPG, PNG ou WEBP.', 'warning');
        return;
    }

    _fotoOrigemAtual  = 'upload';
    _fotoArquivoAtual = file;

    const reader = new FileReader();
    reader.onload = e => {
        abrirModal('modalFotoCaptura');
        _fotoMostrarEtapaPreview(e.target.result);
    };
    reader.readAsDataURL(file);
}

function _fotoMostrarEtapaCamera() {
    const video   = document.getElementById('fotoVideo');
    const preview = document.getElementById('fotoPreviewImg');
    const erroEl  = document.getElementById('fotoCapturaErro');
    if (video)   video.style.display = 'block';
    if (preview) preview.style.display = 'none';
    if (erroEl)  { erroEl.style.display = 'none'; erroEl.textContent = ''; }
    _fotoToggleFooter('camera');
}

function _fotoMostrarEtapaPreview(previewUrl) {
    const video   = document.getElementById('fotoVideo');
    const preview = document.getElementById('fotoPreviewImg');
    if (video)   video.style.display = 'none';
    if (preview) { preview.style.display = 'block'; preview.src = previewUrl; }
    _fotoToggleFooter('preview');
}

function _fotoToggleFooter(etapa) {
    const footerCamera  = document.getElementById('fotoCapturaFooterCamera');
    const footerPreview = document.getElementById('fotoCapturaFooterPreview');
    if (footerCamera)  footerCamera.style.display  = etapa === 'camera'  ? 'flex' : 'none';
    if (footerPreview) footerPreview.style.display = etapa === 'preview' ? 'flex' : 'none';

    const btnRefazer = document.getElementById('fotoBtnRefazer');
    if (btnRefazer) {
        btnRefazer.innerHTML = _fotoOrigemAtual === 'camera'
            ? '<i class="fas fa-redo"></i> Tirar Novamente'
            : '<i class="fas fa-folder-open"></i> Escolher Outro Arquivo';
    }
}

function fotoCapturarFoto() {
    const video  = document.getElementById('fotoVideo');
    const canvas = document.getElementById('fotoCanvas');
    if (!video || !canvas || !video.videoWidth) return;

    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(blob => {
        if (!blob) return;
        _fotoArquivoAtual = blob;
        _fotoMostrarEtapaPreview(canvas.toDataURL('image/jpeg', 0.92));
        _fotoPararCamera();
    }, 'image/jpeg', 0.92);
}

function fotoRefazer() {
    if (_fotoOrigemAtual === 'camera') {
        _fotoArquivoAtual = null;
        fotoEscolherOpcao('camera'); // reabre a câmera dentro do mesmo modal
    } else {
        const input = document.getElementById('fotoInputArquivo');
        if (input) { input.value = ''; input.click(); }
    }
}

function fotoCancelar() {
    _fotoPararCamera();
    _fotoArquivoAtual = null;
    fecharModal('modalFotoCaptura');
    _fotoContexto = null;
}

function _fotoPararCamera() {
    if (_fotoStream) {
        _fotoStream.getTracks().forEach(track => track.stop());
        _fotoStream = null;
    }
    const video = document.getElementById('fotoVideo');
    if (video) video.srcObject = null;
}

// ---- Compressão client-side (canvas) + envio ------------------------------
function _fotoComprimirImagem(origem, maxDim, qualidade) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const precisaRevogar = origem instanceof Blob;
        img.onload = () => {
            let { width, height } = img;
            if (width > maxDim || height > maxDim) {
                if (width > height) { height = Math.round(height * maxDim / width); width = maxDim; }
                else { width = Math.round(width * maxDim / height); height = maxDim; }
            }
            const canvas = document.createElement('canvas');
            canvas.width = width; canvas.height = height;
            canvas.getContext('2d').drawImage(img, 0, 0, width, height);
            canvas.toBlob(blob => {
                if (precisaRevogar) URL.revokeObjectURL(img.src);
                blob ? resolve(blob) : reject(new Error('Falha ao comprimir imagem'));
            }, 'image/jpeg', qualidade);
        };
        img.onerror = () => reject(new Error('Não foi possível processar a imagem'));
        img.src = precisaRevogar ? URL.createObjectURL(origem) : origem;
    });
}

async function fotoConfirmar() {
    if (!_fotoArquivoAtual || !_fotoContexto) { fotoCancelar(); return; }

    const hidrometroId = _fotoContexto.tipo === 'individual'
        ? document.getElementById('ind_hidrometro')?.value
        : _fotoContexto.hidrometroId;

    if (!hidrometroId) {
        _toast('Selecione o hidrômetro antes de anexar a foto.', 'warning');
        return;
    }

    const btnConfirmar = document.querySelector('#fotoCapturaFooterPreview .btn-primary');
    if (btnConfirmar) { btnConfirmar.disabled = true; btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }

    try {
        const blobComprimido = await _fotoComprimirImagem(_fotoArquivoAtual, FOTO_MAX_DIMENSAO, FOTO_QUALIDADE);

        const formData = new FormData();
        formData.append('hidrometro_id', hidrometroId);
        formData.append('origem', _fotoOrigemAtual);
        formData.append('arquivo', blobComprimido, `leitura_${Date.now()}.jpg`);

        const resp = await fetch(API_LEITURAS_FOTOS, { method: 'POST', credentials: 'include', body: formData });
        const data = await resp.json();
        if (!data.sucesso) throw new Error(data.mensagem || 'Erro ao enviar a foto');

        // Substitui uma eventual foto pendente anterior (o arquivo antigo já
        // fica órfão no servidor — "Anexar arquivo" permite trocar antes de salvar)
        const contextoAnterior = _fotoContexto;
        const previewUrl = URL.createObjectURL(blobComprimido);
        const fotoInfo   = { id: data.dados.id, previewUrl };

        if (contextoAnterior.tipo === 'individual') {
            if (_fotoPendenteIndividual) _fotoExcluirPendente(_fotoPendenteIndividual.id);
            _fotoPendenteIndividual = fotoInfo;
            _fotoAtualizarBotaoIndividual();
        } else {
            const draft = _state.leituraColetivaDraft.get(contextoAnterior.hidrometroId) || {};
            if (draft.foto) _fotoExcluirPendente(draft.foto.id);
            draft.foto = fotoInfo;
            _state.leituraColetivaDraft.set(contextoAnterior.hidrometroId, draft);
            _leituraRenderizarColetiva();
        }

        _toast('Foto anexada com sucesso!', 'success');
        _fotoArquivoAtual = null;
        fecharModal('modalFotoCaptura');
        _fotoContexto = null;
    } catch (err) {
        console.error('[Foto] Erro ao enviar:', err);
        _toast(`Erro ao enviar foto: ${err.message}`, 'error');
    } finally {
        if (btnConfirmar) { btnConfirmar.disabled = false; btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Confirmar'; }
    }
}

// ---- Remover foto ainda não vinculada --------------------------------------
async function _fotoExcluirPendente(fotoId) {
    if (!fotoId) return;
    try {
        await fetch(API_LEITURAS_FOTOS, {
            method: 'DELETE',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: fotoId }),
        });
    } catch (err) {
        console.warn('[Foto] Não foi possível remover a foto pendente:', err);
    }
}

function fotoRemoverIndividual() {
    if (_fotoPendenteIndividual) {
        _fotoExcluirPendente(_fotoPendenteIndividual.id);
        _fotoPendenteIndividual = null;
        _fotoAtualizarBotaoIndividual();
    }
}

function fotoRemoverColetiva(hidrometroId) {
    const key = parseInt(hidrometroId, 10);
    const draft = _state.leituraColetivaDraft.get(key);
    if (draft && draft.foto) {
        _fotoExcluirPendente(draft.foto.id);
        draft.foto = null;
        _state.leituraColetivaDraft.set(key, draft);
        _leituraRenderizarColetiva();
    }
}

function _fotoAtualizarBotaoIndividual() {
    const wrap = document.getElementById('indFotoWrap');
    if (!wrap) return;
    if (_fotoPendenteIndividual) {
        wrap.innerHTML = `
            <img src="${_fotoPendenteIndividual.previewUrl}" class="foto-ind-preview" alt="Foto anexada">
            <span class="foto-ind-status"><i class="fas fa-check-circle"></i> Foto anexada</span>
            <button type="button" class="btn-foto-remover" title="Remover foto" onclick="window.HidrometroPage.fotoRemoverIndividual()"><i class="fas fa-times"></i></button>
        `;
    } else {
        wrap.innerHTML = `
            <button type="button" class="btn-secondary" onclick="window.HidrometroPage.fotoAbrirMenuIndividual()">
                <i class="fas fa-camera"></i> Foto da Leitura
            </button>
        `;
    }
}

// ---- Visualizador (evidência de uma leitura / galeria de um hidrômetro) ---
async function fotoAbrirVisualizadorLeitura(leituraId) {
    try {
        const data = await _apiCall(`${API_LEITURAS_FOTOS}?leitura_id=${leituraId}`);
        if (!data.sucesso || !data.dados || data.dados.length === 0) {
            _toast('Nenhuma foto encontrada para esta leitura.', 'info');
            return;
        }
        _fotoGaleriaLista  = data.dados;
        _fotoGaleriaIndice = 0;

        // Metadados vêm do cache do Histórico já carregado na tela (mesma leitura)
        const linha = (_state.historicoCache || []).find(l => String(l.id) === String(leituraId));
        _fotoPreencherInfo(linha);
        _fotoRenderizarGaleriaAtual(false);
        abrirModal('modalFotoVisualizar');
    } catch (err) {
        _toast(`Erro ao carregar foto: ${err.message}`, 'error');
    }
}

async function fotoAbrirGaleriaHidrometro(hidrometroId) {
    try {
        const data = await _apiCall(`${API_LEITURAS_FOTOS}?hidrometro_id=${hidrometroId}`);
        if (!data.sucesso || !data.dados || data.dados.length === 0) {
            _toast('Nenhuma foto registrada para este hidrômetro ainda.', 'info');
            return;
        }
        _fotoGaleriaLista  = data.dados; // já vem com unidade/numero_hidrometro/data da leitura via JOIN
        _fotoGaleriaIndice = 0;
        _fotoPreencherInfo(_fotoGaleriaLista[0]);
        _fotoRenderizarGaleriaAtual(true);
        abrirModal('modalFotoVisualizar');
    } catch (err) {
        _toast(`Erro ao carregar galeria: ${err.message}`, 'error');
    }
}

function _fotoPreencherInfo(linha) {
    if (!linha) {
        ['fv_data', 'fv_unidade', 'fv_hidrometro', 'fv_leitura', 'fv_usuario'].forEach(id => _setEl(id, '—'));
        return;
    }
    _setEl('fv_data', linha.data_leitura_formatada || linha.data_leitura || '—');
    _setEl('fv_unidade', linha.unidade || '—');
    _setEl('fv_hidrometro', linha.numero_hidrometro || '—');
    _setEl('fv_leitura', linha.leitura_atual != null
        ? `${linha.leitura_atual} m³ (consumo: ${linha.consumo ?? '—'} m³)`
        : '—');
    _setEl('fv_usuario', linha.lancado_por_descricao || linha.lancado_por_nome || '—');
}

function _fotoRenderizarGaleriaAtual(atualizaInfoPorFoto) {
    const foto = _fotoGaleriaLista[_fotoGaleriaIndice];
    if (!foto) return;

    const img = document.getElementById('fv_imagem');
    if (img) {
        img.classList.remove('zoom');
        img.src = `${API_FOTO_VIEW}?id=${foto.id}`;
    }

    const contador = document.getElementById('fv_contador');
    if (contador) {
        contador.textContent = _fotoGaleriaLista.length > 1
            ? `Foto ${_fotoGaleriaIndice + 1} de ${_fotoGaleriaLista.length}`
            : '';
    }

    const mostrarNav = _fotoGaleriaLista.length > 1;
    const btnAnt  = document.getElementById('fv_btn_anterior');
    const btnProx = document.getElementById('fv_btn_proxima');
    if (btnAnt)  btnAnt.style.display  = mostrarNav ? 'flex' : 'none';
    if (btnProx) btnProx.style.display = mostrarNav ? 'flex' : 'none';

    // Na galeria do hidrômetro, cada foto pode ser de uma leitura diferente
    if (atualizaInfoPorFoto) _fotoPreencherInfo(foto);
}

function fotoGaleriaAnterior() {
    if (_fotoGaleriaLista.length < 2) return;
    _fotoGaleriaIndice = (_fotoGaleriaIndice - 1 + _fotoGaleriaLista.length) % _fotoGaleriaLista.length;
    _fotoRenderizarGaleriaAtual(true);
}

function fotoGaleriaProxima() {
    if (_fotoGaleriaLista.length < 2) return;
    _fotoGaleriaIndice = (_fotoGaleriaIndice + 1) % _fotoGaleriaLista.length;
    _fotoRenderizarGaleriaAtual(true);
}

function fotoAlternarZoom() {
    const img = document.getElementById('fv_imagem');
    if (img) img.classList.toggle('zoom');
}

function fotoVisualizadorDownload() {
    const img = document.getElementById('fv_imagem');
    if (!img || !img.src) return;
    const a = document.createElement('a');
    a.href = img.src;
    a.download = 'evidencia_leitura.jpg';
    a.click();
}

function fotoVisualizadorImprimir() {
    const img = document.getElementById('fv_imagem');
    if (!img || !img.src) return;
    const win = window.open('', '_blank');
    if (!win) { _toast('Permita pop-ups para imprimir a evidência.', 'warning'); return; }
    win.document.write(`<!DOCTYPE html><html><head><title>Imprimir Evidência</title></head>
        <body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff;">
        <img src="${img.src}" style="max-width:100%;" onload="window.print()">
        </body></html>`);
    win.document.close();
}

// ============================================================
// DEMONSTRATIVO DE CONSUMO DE ÁGUA
// ============================================================
/**
 * Abre o demonstrativo de água (estilo fatura) em nova aba.
 * Exibe um mini-modal para o operador selecionar o mês de referência.
 * Usa closure para evitar dependência de window.HidrometroPage._abrirDemoAgua.
 *
 * @param {number} hidrometroId — ID do hidrômetro
 */
function gerarDemonstrativo(hidrometroId) {
    if (!hidrometroId) return;

    // Remover modal anterior se existir
    const anterior = document.getElementById('modalDemoAgua');
    if (anterior) anterior.remove();

    // Função interna (closure) — não depende de window.HidrometroPage
    function abrirDemo() {
        const mesInput = document.getElementById('inputMesDemoAgua');
        const mes = mesInput ? mesInput.value.trim() : '';
        let url = '../api/api_demonstrativo_agua.php?hidrometro_id=' + hidrometroId;
        if (mes) url += '&mes=' + encodeURIComponent(mes);
        window.open(url, '_blank');
        const modal = document.getElementById('modalDemoAgua');
        if (modal) modal.remove();
    }

    // Criar overlay do mini-modal
    const overlay = document.createElement('div');
    overlay.id = 'modalDemoAgua';
    overlay.style.cssText = [
        'position:fixed',
        'inset:0',
        'background:rgba(0,0,0,.55)',
        'z-index:9999',
        'display:flex',
        'align-items:center',
        'justify-content:center'
    ].join(';');

    // Mês atual como padrão
    const hoje = new Date();
    const mesAtual = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0');

    // Construir HTML do modal sem template literals aninhados
    const card = document.createElement('div');
    card.style.cssText = [
        'background:#fff',
        'border-radius:12px',
        'padding:28px 32px',
        'width:380px',
        'max-width:95vw',
        'box-shadow:0 20px 60px rgba(0,0,0,.25)',
        'font-family:sans-serif'
    ].join(';');

    card.innerHTML = [
        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">',
        '  <div style="background:linear-gradient(135deg,#16a34a,#166534);border-radius:8px;',
        '             width:36px;height:36px;display:flex;align-items:center;justify-content:center;">',
        '    <i class="fas fa-file-invoice" style="color:#fff;font-size:16px;"></i>',
        '  </div>',
        '  <div>',
        '    <div style="font-size:15px;font-weight:800;color:#0f172a;">Demonstrativo de Água</div>',
        '    <div style="font-size:11px;color:#64748b;">Selecione o mês de referência</div>',
        '  </div>',
        '</div>',
        '<label style="display:block;font-size:11px;font-weight:700;color:#475569;',
        '              text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">',
        '  Mês / Ano de Referência',
        '</label>',
        '<input type="month" id="inputMesDemoAgua" value="' + mesAtual + '"',
        '       style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;',
        '              font-size:13px;color:#1e293b;outline:none;margin-bottom:20px;">',
        '<p style="font-size:10px;color:#94a3b8;margin-bottom:20px;line-height:1.6;">',
        '  Deixe em branco para usar a última leitura disponível.',
        '  O demonstrativo abrirá em nova aba pronto para impressão ou salvar como PDF.',
        '</p>',
        '<div style="display:flex;gap:10px;">',
        '  <button id="btnCancelarDemo"',
        '          style="flex:1;padding:10px;border:1.5px solid #e2e8f0;background:#f8fafc;',
        '                 border-radius:8px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;">',
        '    Cancelar',
        '  </button>',
        '  <button id="btnConfirmarDemo"',
        '          style="flex:2;padding:10px;border:none;',
        '                 background:linear-gradient(135deg,#16a34a,#166534);',
        '                 border-radius:8px;font-size:13px;font-weight:700;color:#fff;',
        '                 cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;">',
        '    <i class="fas fa-external-link-alt"></i> Gerar Demonstrativo',
        '  </button>',
        '</div>'
    ].join('\n');

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    // Vincular eventos via JS (sem onclick inline — evita problemas de escopo)
    document.getElementById('btnCancelarDemo').addEventListener('click', function() {
        overlay.remove();
    });
    document.getElementById('btnConfirmarDemo').addEventListener('click', function() {
        abrirDemo();
    });

    // Fechar ao clicar no fundo
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.remove();
    });

    // Focar no input de mês
    setTimeout(function() {
        var inp = document.getElementById('inputMesDemoAgua');
        if (inp) inp.focus();
    }, 80);
}
