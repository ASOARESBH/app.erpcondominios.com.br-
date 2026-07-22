/**
 * Leitura Page Module
 * 
 * Gerencia o registro de leituras de hidrômetros:
 *  - Leitura individual (unidade → morador → hidrômetro)
 *  - Leitura coletiva com paginação
 *  - Histórico de leituras com filtros e KPIs
 *  - Configuração do período de lançamento
 * 
 * @module leitura
 * @version 2.0.0
 */

'use strict';

// ============================================================
// CONSTANTES
// ============================================================
const API_LEITURAS       = window.location.origin + '/api/api_leituras.php';
const API_HIDROMETROS    = window.location.origin + '/api/api_hidrometros.php';
const API_UNIDADES       = window.location.origin + '/api/api_unidades.php';
const API_MORADORES      = window.location.origin + '/api/api_moradores.php';
const API_CONFIG_PERIODO = window.location.origin + '/api/api_config_periodo_leitura.php';

const VALOR_M3       = 6.16;
const VALOR_MINIMO   = 61.60;
const CONSUMO_MINIMO = 10;
const ITENS_POR_PAG  = 20;

// ============================================================
// ESTADO DO MÓDULO
// ============================================================
let _state = {
    unidades         : [],
    hidrometrosAtivos: [],
    paginaAtual      : 1,
    totalPaginas     : 1,
    currentTab       : 'individual',
};

// ============================================================
// LIFECYCLE
// ============================================================

export function init() {
    console.log('[Leitura] Inicializando módulo v2.0...');

    _setupTabs();
    _setupForms();
    _setDataAtual('ind_data_leitura');
    _setDataAtual('col_data_leitura');
    _carregarUnidades();
    carregarConfigPeriodo();

    window.LeituraPage = {
        calcularPreview     : calcularPreview,
        limparIndividual    : limparIndividual,
        carregarColetiva    : carregarHidrometrosAtivos,
        selecionarTodos     : selecionarTodos,
        lancarSelecionados  : lancarSelecionados,
        limparSelecao       : limparSelecao,
        mudarPagina         : mudarPagina,
        buscarHistorico     : buscarHistorico,
        carregarConfigPeriodo: carregarConfigPeriodo,
    };

    console.log('[Leitura] Módulo pronto.');
}

export function destroy() {
    console.log('[Leitura] Destruindo módulo...');
    delete window.LeituraPage;
    _state = {
        unidades: [], hidrometrosAtivos: [],
        paginaAtual: 1, totalPaginas: 1, currentTab: 'individual',
    };
}

// ============================================================
// TABS
// ============================================================

function _setupTabs() {
    document.querySelectorAll('.page-hidrometro .tab-button').forEach(btn => {
        btn.addEventListener('click', () => _switchTab(btn.dataset.tab));
    });
}

function _switchTab(tabName) {
    document.querySelectorAll('.page-hidrometro .tab-button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    document.querySelectorAll('.page-hidrometro .tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `tab-${tabName}`);
    });
    _state.currentTab = tabName;

    if (tabName === 'coletiva' && _state.hidrometrosAtivos.length === 0) {
        carregarHidrometrosAtivos();
    }
    if (tabName === 'configuracoes') {
        carregarConfigPeriodo();
    }
}

// ============================================================
// FORMULÁRIOS
// ============================================================

function _setupForms() {
    // Individual
    const formInd = document.getElementById('formIndividual');
    if (formInd) {
        formInd.addEventListener('submit', e => {
            e.preventDefault();
            _salvarLeituraIndividual();
        });
    }

    // Config período
    const formConfig = document.getElementById('formConfigPeriodo');
    if (formConfig) {
        formConfig.addEventListener('submit', e => {
            e.preventDefault();
            _salvarConfigPeriodo();
        });
    }

    // Cascata: unidade → morador
    const selUnidade = document.getElementById('ind_unidade');
    if (selUnidade) {
        selUnidade.addEventListener('change', _carregarMoradoresPorUnidade);
    }

    // Cascata: morador → hidrômetro
    const selMorador = document.getElementById('ind_morador');
    if (selMorador) {
        selMorador.addEventListener('change', _carregarHidrometrosPorMorador);
    }

    // Hidrômetro → última leitura
    const selHidro = document.getElementById('ind_hidrometro');
    if (selHidro) {
        selHidro.addEventListener('change', _carregarUltimaLeitura);
    }
}

// ============================================================
// DATA ATUAL
// ============================================================

function _setDataAtual(campoId) {
    const campo = document.getElementById(campoId);
    if (!campo) return;
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    campo.value = agora.toISOString().slice(0, 16);
}

// ============================================================
// CARREGAMENTO DE UNIDADES
// ============================================================

async function _carregarUnidades() {
    console.log('[Leitura] Carregando unidades...');
    try {
        const data = await _apiCall(API_UNIDADES + '?acao=select');
        if (!data.sucesso) throw new Error(data.mensagem);

        _state.unidades = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);

        // Ordenação numérica: ADMINISTRATIVO primeiro, depois Gleba 1, 2, 3...
        const _extrairNumero = (str) => {
            const m = String(str).match(/(\d+)/);
            return m ? parseInt(m[1], 10) : 0;
        };
        const unidadesOrdenadas = [..._state.unidades].sort((a, b) => {
            const nomeA = String(a.unidade || a.nome || a).trim();
            const nomeB = String(b.unidade || b.nome || b).trim();
            const isAdmA = /adm/i.test(nomeA);
            const isAdmB = /adm/i.test(nomeB);
            if (isAdmA && !isAdmB) return -1;
            if (!isAdmA && isAdmB) return  1;
            return _extrairNumero(nomeA) - _extrairNumero(nomeB);
        });

        ['ind_unidade', 'hist_unidade'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const temTodas = id === 'hist_unidade';
            sel.innerHTML = temTodas
                ? '<option value="">Todas as unidades</option>'
                : '<option value="">Selecione uma unidade...</option>';
            unidadesOrdenadas.forEach(u => {
                const val = u.unidade || u.nome || u;
                sel.add(new Option(val, val));
            });
        });

        console.log(`[Leitura] ${_state.unidades.length} unidades carregadas.`);
    } catch (err) {
        console.error('[Leitura] Erro ao carregar unidades:', err);
    }
}

// ============================================================
// CASCATA: UNIDADE → MORADORES
// ============================================================

async function _carregarMoradoresPorUnidade() {
    const unidade    = document.getElementById('ind_unidade')?.value;
    const selMorador = document.getElementById('ind_morador');
    const selHidro   = document.getElementById('ind_hidrometro');

    if (selMorador) { selMorador.innerHTML = '<option value="">Carregando...</option>'; selMorador.disabled = true; }
    if (selHidro)   { selHidro.innerHTML   = '<option value="">Primeiro selecione o morador</option>'; selHidro.disabled = true; }
    _ocultarInfoHidrometro();

    if (!unidade) {
        if (selMorador) selMorador.innerHTML = '<option value="">Primeiro selecione a unidade</option>';
        return;
    }

    try {
        const data = await _apiCall(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`);
        if (!selMorador) return;
        selMorador.innerHTML = '<option value="">Selecione um morador...</option>';

        // api_moradores retorna dados paginados: { itens: [...], total, ... }
        const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        console.log(`[Leitura] Moradores carregados para unidade "${unidade}":`, moradores.length, moradores);
        if (data.sucesso && moradores.length > 0) {
            moradores.forEach(m => selMorador.add(new Option(m.nome, m.id)));
            selMorador.disabled = false;
        } else {
            selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
        }
    } catch (err) {
        console.error('[Leitura] Erro ao carregar moradores:', err);
        if (selMorador) selMorador.innerHTML = '<option value="">Erro ao carregar</option>';
    }
}

// ============================================================
// CASCATA: MORADOR → HIDRÔMETROS
// ============================================================

async function _carregarHidrometrosPorMorador() {
    const moradorId = document.getElementById('ind_morador')?.value;
    const selHidro  = document.getElementById('ind_hidrometro');

    if (selHidro) { selHidro.innerHTML = '<option value="">Carregando...</option>'; selHidro.disabled = true; }
    _ocultarInfoHidrometro();

    if (!moradorId) {
        if (selHidro) selHidro.innerHTML = '<option value="">Primeiro selecione o morador</option>';
        return;
    }

    try {
        // Filtra diretamente por morador_id na API para evitar carregar todos
        const data = await _apiCall(`${API_HIDROMETROS}?morador_id=${encodeURIComponent(moradorId)}&ativos=1`);
        if (!selHidro) return;
        selHidro.innerHTML = '<option value="">Selecione um hidrômetro...</option>';

        console.log('[Leitura] Hidrômetros do morador:', moradorId, data);

        if (data.sucesso) {
            // A API pode retornar array direto ou objeto paginado
            const lista = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);
            // Filtro de segurança no JS caso a API não filtre por morador_id
            const filtrada = lista.filter(h => String(h.morador_id) === String(moradorId));
            const exibir = filtrada.length > 0 ? filtrada : lista;
            if (exibir.length > 0) {
                exibir.forEach(h => {
                    const label = `${h.numero_hidrometro} (Lacre: ${h.numero_lacre || 'N/A'})`;
                    selHidro.add(new Option(label, h.id));
                });
                selHidro.disabled = false;
            } else {
                selHidro.innerHTML = '<option value="">Nenhum hidrômetro para este morador</option>';
            }
        }
    } catch (err) {
        console.error('[Leitura] Erro ao carregar hidrômetros:', err);
        if (selHidro) selHidro.innerHTML = '<option value="">Erro ao carregar</option>';
    }
}

// ============================================================
// ÚLTIMA LEITURA
// ============================================================

async function _carregarUltimaLeitura() {
    const hidrometroId = document.getElementById('ind_hidrometro')?.value;
    _ocultarInfoHidrometro();

    if (!hidrometroId) return;

    try {
        // Buscar última leitura e dados do hidrômetro em paralelo
        // Busca o hidrômetro por ID específico para evitar carregar todos
        const [dataLeitura, dataHidro] = await Promise.all([
            _apiCall(`${API_LEITURAS}?ultima_leitura=${hidrometroId}`),
            _apiCall(`${API_HIDROMETROS}?id=${hidrometroId}`),
        ]);

        const leituraAnterior = dataLeitura.sucesso ? (dataLeitura.dados?.leitura_atual || 0) : 0;
        const dataAnterior    = dataLeitura.sucesso ? (dataLeitura.dados?.data_leitura_formatada || 'Primeira leitura') : 'Primeira leitura';

        console.log('[Leitura] Última leitura:', dataLeitura.dados, '| Hidrômetro:', dataHidro.dados);

        _setEl('ind_leitura_anterior', leituraAnterior, 'value');

        // Suporta tanto objeto único (GET por id) quanto array
        const hidro = dataHidro.sucesso
            ? (Array.isArray(dataHidro.dados)
                ? dataHidro.dados.find(h => h.id == hidrometroId)
                : dataHidro.dados)
            : null;

        if (hidro) {
            _setEl('info_numero',           hidro.numero_hidrometro);
            _setEl('info_lacre',            hidro.numero_lacre || 'N/A');
            _setEl('info_leitura_anterior', leituraAnterior + ' m³');
            _setEl('info_data_anterior',    dataAnterior);
            _mostrarInfoHidrometro();
        }

        // Recalcular preview se já houver leitura atual
        calcularPreview();
    } catch (err) {
        console.error('[Leitura] Erro ao carregar última leitura:', err);
    }
}

function _mostrarInfoHidrometro() {
    const box = document.getElementById('ind_info_hidrometro');
    if (box) box.style.display = 'block';
}

function _ocultarInfoHidrometro() {
    const box = document.getElementById('ind_info_hidrometro');
    if (box) box.style.display = 'none';
    const calc = document.getElementById('ind_calculo');
    if (calc) calc.classList.remove('show');
}

// ============================================================
// CÁLCULO PREVIEW
// ============================================================

function calcularPreview() {
    const leituraAnterior = parseFloat(document.getElementById('ind_leitura_anterior')?.value) || 0;
    const leituraAtual    = parseFloat(document.getElementById('ind_leitura_atual')?.value)    || 0;
    const calc            = document.getElementById('ind_calculo');

    if (leituraAtual <= 0) {
        if (calc) calc.classList.remove('show');
        return;
    }

    const consumo = Math.max(0, leituraAtual - leituraAnterior);
    const valor   = consumo <= CONSUMO_MINIMO ? VALOR_MINIMO : consumo * VALOR_M3;

    _setEl('calc_consumo', consumo.toFixed(2) + ' m³');
    _setEl('calc_valor',   'R$ ' + valor.toFixed(2).replace('.', ','));

    if (calc) calc.classList.add('show');
}

// ============================================================
// SALVAR LEITURA INDIVIDUAL
// ============================================================

async function _salvarLeituraIndividual() {
    const hidrometroId  = document.getElementById('ind_hidrometro')?.value;
    const leituraAtual  = parseFloat(document.getElementById('ind_leitura_atual')?.value);
    const dataLeitura   = document.getElementById('ind_data_leitura')?.value;
    const observacao    = document.getElementById('ind_observacao')?.value?.trim() || '';

    const leituraAnteriorRef = parseFloat(document.getElementById('ind_leitura_anterior')?.value) || 0;
    const leituraAtualStr     = document.getElementById('ind_leitura_atual')?.value;

    if (!hidrometroId || leituraAtualStr === '' || leituraAtualStr === null || leituraAtualStr === undefined || !dataLeitura) {
        _toast('Preencha todos os campos obrigatórios.', 'warning');
        return;
    }
    if (isNaN(leituraAtual)) {
        _toast('Leitura atual inválida.', 'warning');
        return;
    }
    if (leituraAtual < leituraAnteriorRef) {
        _toast(`Leitura atual (${leituraAtual}) não pode ser menor que a leitura anterior (${leituraAnteriorRef} m³).`, 'warning');
        return;
    }

    const payload = {
        hidrometro_id   : parseInt(hidrometroId),
        leitura_atual   : leituraAtual,
        data_leitura    : dataLeitura,
        observacao      : observacao,
        lancado_por_tipo: 'usuario',
    };

    console.log('[Leitura] Salvando leitura individual:', payload);

    try {
        const data = await _apiCall(API_LEITURAS, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(payload),
        });

        if (!data.sucesso) throw new Error(data.mensagem);

        const consumo = data.dados?.consumo?.toFixed(2) || '—';
        const valor   = data.dados?.valor_total?.toFixed(2).replace('.', ',') || '—';
        _toast(`Leitura registrada! Consumo: ${consumo} m³ | Valor: R$ ${valor}`, 'success');
        limparIndividual();
    } catch (err) {
        console.error('[Leitura] Erro ao salvar leitura:', err);
        _toast('Erro ao registrar leitura: ' + err.message, 'error');
    }
}

function limparIndividual() {
    document.getElementById('formIndividual')?.reset();
    const selMorador = document.getElementById('ind_morador');
    const selHidro   = document.getElementById('ind_hidrometro');
    if (selMorador) { selMorador.innerHTML = '<option value="">Primeiro selecione a unidade</option>'; selMorador.disabled = true; }
    if (selHidro)   { selHidro.innerHTML   = '<option value="">Primeiro selecione o morador</option>'; selHidro.disabled = true; }
    _ocultarInfoHidrometro();
    _setDataAtual('ind_data_leitura');
}

// ============================================================
// LEITURA COLETIVA
// ============================================================

async function carregarHidrometrosAtivos() {
    console.log('[Leitura] Carregando hidrômetros ativos para leitura coletiva...');
    const loading = document.getElementById('loadingColetiva');
    if (loading) loading.style.display = 'block';

    try {
        const data = await _apiCall(`${API_HIDROMETROS}?ativos=1`);
        if (!data.sucesso) throw new Error(data.mensagem);

        _state.hidrometrosAtivos = data.dados || [];
        _state.paginaAtual       = 1;
        _state.totalPaginas      = Math.ceil(_state.hidrometrosAtivos.length / ITENS_POR_PAG);

        _renderColetiva();
        console.log(`[Leitura] ${_state.hidrometrosAtivos.length} hidrômetros ativos carregados.`);
    } catch (err) {
        console.error('[Leitura] Erro ao carregar hidrômetros ativos:', err);
        _toast('Erro ao carregar hidrômetros: ' + err.message, 'error');
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

function _renderColetiva() {
    const tbody = document.getElementById('listaColetiva');
    if (!tbody) return;

    const inicio = (_state.paginaAtual - 1) * ITENS_POR_PAG;
    const fim    = inicio + ITENS_POR_PAG;
    const pagina = _state.hidrometrosAtivos.slice(inicio, fim);

    if (pagina.length === 0) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="6">
                    <i class="fas fa-tint"></i>
                    <p>Nenhum hidrômetro ativo encontrado</p>
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = pagina.map((h, idx) => {
        const indexGlobal = inicio + idx;
        const leituraAnt  = h.ultima_leitura != null ? parseFloat(h.ultima_leitura).toFixed(2) : '0.00';
        return `
            <tr>
                <td>
                    <input type="checkbox" class="check-hidrometro" data-index="${indexGlobal}">
                </td>
                <td>${_esc(h.unidade)}</td>
                <td>${_esc(h.morador_nome)}</td>
                <td><strong>${_esc(h.numero_hidrometro)}</strong></td>
                <td>${leituraAnt}</td>
                <td>
                    <input type="number" class="leitura-atual" data-index="${indexGlobal}"
                        step="0.01" min="${leituraAnt}" placeholder="m³">
                </td>
            </tr>`;
    }).join('');

    // Paginação
    const pag = document.getElementById('paginacaoColetiva');
    if (pag) pag.style.display = _state.totalPaginas > 1 ? 'flex' : 'none';
    _atualizarPaginacao();
}

function selecionarTodos() {
    const checkAll = document.getElementById('selectAll');
    document.querySelectorAll('.check-hidrometro').forEach(c => c.checked = checkAll.checked);
}

function limparSelecao() {
    const checkAll = document.getElementById('selectAll');
    if (checkAll) checkAll.checked = false;
    document.querySelectorAll('.check-hidrometro').forEach(c => c.checked = false);
    document.querySelectorAll('.leitura-atual').forEach(i => i.value = '');
}

async function lancarSelecionados() {
    const dataLeitura = document.getElementById('col_data_leitura')?.value;
    if (!dataLeitura) {
        _toast('Informe a data e hora da leitura.', 'warning');
        return;
    }

    const leituras = [];
    document.querySelectorAll('.check-hidrometro:checked').forEach(check => {
        const idx          = check.dataset.index;
        const inputEl     = document.querySelector(`.leitura-atual[data-index="${idx}"]`);
        const inputStr     = inputEl?.value;
        const leituraAtual = (inputStr !== '' && inputStr !== null && inputStr !== undefined) ? parseFloat(inputStr) : null;
        const leituraAnt   = _state.hidrometrosAtivos[idx]?.ultima_leitura != null ? parseFloat(_state.hidrometrosAtivos[idx].ultima_leitura) : 0;
        // Aceita leitura >= anterior (inclusive igual, para quando não houve consumo)
        if (leituraAtual !== null && !isNaN(leituraAtual) && leituraAtual >= leituraAnt) {
            leituras.push({
                hidrometro_id : _state.hidrometrosAtivos[idx].id,
                leitura_atual : leituraAtual,
                data_leitura  : dataLeitura,
            });
        }
    });

    if (leituras.length === 0) {
        _toast('Selecione ao menos um hidrômetro e informe a leitura.', 'warning');
        return;
    }

    console.log(`[Leitura] Lançando ${leituras.length} leituras coletivas...`);

    try {
        const data = await _apiCall(API_LEITURAS, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify({ leituras }),
        });

        if (!data.sucesso) throw new Error(data.mensagem);

        const sucesso = data.dados?.sucesso || leituras.length;
        const erros   = data.dados?.erros   || 0;
        _toast(`${sucesso} leitura(s) registrada(s) com sucesso!${erros > 0 ? ` (${erros} com erro)` : ''}`, 'success');
        limparSelecao();
        carregarHidrometrosAtivos();
    } catch (err) {
        console.error('[Leitura] Erro ao lançar leituras coletivas:', err);
        _toast('Erro ao registrar leituras: ' + err.message, 'error');
    }
}

function mudarPagina(direcao) {
    const nova = _state.paginaAtual + direcao;
    if (nova >= 1 && nova <= _state.totalPaginas) {
        _state.paginaAtual = nova;
        _renderColetiva();
    }
}

function _atualizarPaginacao() {
    _setEl('infoPagina',   `Página ${_state.paginaAtual} de ${_state.totalPaginas}`);
    const btnAnt = document.getElementById('btnAnterior');
    const btnPro = document.getElementById('btnProximo');
    if (btnAnt) btnAnt.disabled = _state.paginaAtual === 1;
    if (btnPro) btnPro.disabled = _state.paginaAtual === _state.totalPaginas;
}

// ============================================================
// HISTÓRICO DE LEITURAS
// ============================================================

async function buscarHistorico() {
    const dataInicial = document.getElementById('hist_data_inicial')?.value || '';
    const dataFinal   = document.getElementById('hist_data_final')?.value   || '';
    const unidade     = document.getElementById('hist_unidade')?.value      || '';

    const loading = document.getElementById('loadingHistorico');
    const tbody   = document.getElementById('listaHistorico');

    if (loading) loading.style.display = 'block';
    if (tbody)   tbody.innerHTML = '';

    try {
        let url = API_LEITURAS;
        const params = new URLSearchParams();
        if (dataInicial) params.append('data_inicial', dataInicial);
        if (dataFinal)   params.append('data_final',   dataFinal);
        if (unidade)     params.append('unidade',      unidade);
        if (params.toString()) url += '?' + params.toString();

        const data = await _apiCall(url);
        if (!data.sucesso) throw new Error(data.mensagem);

        const leituras = data.dados || [];
        _renderHistorico(leituras);
        _atualizarKPIsHistorico(leituras);
    } catch (err) {
        console.error('[Leitura] Erro ao buscar histórico:', err);
        _toast('Erro ao buscar histórico: ' + err.message, 'error');
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

function _renderHistorico(leituras) {
    const tbody = document.getElementById('listaHistorico');
    if (!tbody) return;

    if (leituras.length === 0) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="9">
                    <i class="fas fa-search"></i>
                    <p>Nenhuma leitura encontrada para os filtros informados</p>
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = leituras.map(l => `
        <tr>
            <td>${_esc(l.data_leitura_formatada)}</td>
            <td>${_esc(l.unidade)}</td>
            <td>${_esc(l.morador_nome)}</td>
            <td><strong>${_esc(l.numero_hidrometro)}</strong></td>
            <td>${parseFloat(l.leitura_anterior).toFixed(2)}</td>
            <td>${parseFloat(l.leitura_atual).toFixed(2)}</td>
            <td><strong>${parseFloat(l.consumo).toFixed(2)}</strong></td>
            <td><strong>R$ ${parseFloat(l.valor_total).toFixed(2).replace('.', ',')}</strong></td>
            <td><span class="badge badge-info">${_esc(l.lancado_por_descricao || l.lancado_por_nome || '—')}</span></td>
        </tr>`
    ).join('');
}

function _atualizarKPIsHistorico(leituras) {
    const kpiBox = document.getElementById('kpiHistorico');
    if (!kpiBox) return;

    if (leituras.length === 0) {
        kpiBox.style.display = 'none';
        return;
    }

    kpiBox.style.display = 'grid';

    const total   = leituras.length;
    const consumo = leituras.reduce((s, l) => s + parseFloat(l.consumo || 0), 0);
    const valor   = leituras.reduce((s, l) => s + parseFloat(l.valor_total || 0), 0);
    const media   = total > 0 ? consumo / total : 0;

    _setEl('hist_kpi_total',   total);
    _setEl('hist_kpi_consumo', consumo.toFixed(2) + ' m³');
    _setEl('hist_kpi_valor',   'R$ ' + valor.toFixed(2).replace('.', ','));
    _setEl('hist_kpi_media',   media.toFixed(2) + ' m³');
}

// ============================================================
// CONFIGURAÇÃO DE PERÍODO
// ============================================================

async function carregarConfigPeriodo() {
    console.log('[Leitura] Carregando configuração de período...');
    try {
        const data = await _apiCall(API_CONFIG_PERIODO);
        if (!data.sucesso) return;

        const cfg = data.dados;
        _setEl('config_dia_inicio', cfg.dia_inicio, 'value');
        _setEl('config_dia_fim',    cfg.dia_fim,    'value');

        const chk = document.getElementById('config_morador_pode');
        if (chk) chk.checked = cfg.morador_pode_lancar == 1;

        // Status
        const statusBox = document.getElementById('config_status_box');
        if (statusBox) statusBox.style.display = 'block';

        _setEl('config_dia_atual',     cfg.dia_atual || new Date().getDate());
        _setEl('config_periodo_atual', `Dia ${cfg.dia_inicio} ao dia ${cfg.dia_fim}`);

        const statusEl = document.getElementById('config_status');
        if (statusEl) {
            if (cfg.esta_no_periodo) {
                statusEl.innerHTML = '<span class="badge badge-active">Período Aberto</span>';
            } else {
                statusEl.innerHTML = '<span class="badge badge-inactive">Período Fechado</span>';
            }
        }
    } catch (err) {
        console.error('[Leitura] Erro ao carregar configuração:', err);
    }
}

async function _salvarConfigPeriodo() {
    const diaInicio    = parseInt(document.getElementById('config_dia_inicio')?.value);
    const diaFim       = parseInt(document.getElementById('config_dia_fim')?.value);
    const moradorPode  = document.getElementById('config_morador_pode')?.checked ? 1 : 0;

    if (!diaInicio || !diaFim || diaInicio > diaFim) {
        _toast('Período inválido. O dia inicial deve ser menor ou igual ao dia final.', 'warning');
        return;
    }

    const payload = {
        dia_inicio          : diaInicio,
        dia_fim             : diaFim,
        morador_pode_lancar : moradorPode,
    };

    console.log('[Leitura] Salvando configuração de período:', payload);

    try {
        const data = await _apiCall(API_CONFIG_PERIODO, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(payload),
        });

        if (!data.sucesso) throw new Error(data.mensagem);

        _toast('Configuração salva com sucesso!', 'success');
        carregarConfigPeriodo();
    } catch (err) {
        console.error('[Leitura] Erro ao salvar configuração:', err);
        _toast('Erro ao salvar configuração: ' + err.message, 'error');
    }
}

// ============================================================
// API HELPER
// ============================================================

async function _apiCall(url, options = {}) {
    const defaultOptions = {
        credentials : 'include',
        headers     : { 'Accept': 'application/json', ...(options.headers || {}) },
    };

    const response = await fetch(url, { ...defaultOptions, ...options });

    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        const text = await response.text();
        console.error('[Leitura] Resposta não-JSON:', text.slice(0, 200));
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
    }, 5000);
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
