/**
 * Veiculos Page Module
 */

const API_VEICULOS = '../api/api_veiculos.php';
const API_MORADORES = '../api/api_moradores.php';
const API_DEPENDENTES = '../api/api_dependentes.php';

let veiculosCache = [];
let modoEdicao = false;
let veiculoEditId = null;
let dependentesCache = [];

// ── Estado da aba Relatórios ──────────────────────────────────────────────
let _relCarregado = false;
let _relPagina = 1;
const _relPorPagina = 20;
let _relModoTabela = 'lista'; // 'lista' | 'agregado'
let _relExtraFiltros = {};
let _relPresetAtivo = null;
let _relCharts = {};
let _relToastTimer = null;

export function init() {
    console.log('[Veiculos] Inicializando...');

    setupForm();
    setupBusca();
    setupActions();
    _relSetupTabs();

    carregarMoradores();
    resetForm();
    carregarVeiculos();

    window.VeiculosPage = {
        buscar: buscarVeiculos,
        editar: editarVeiculo,
        excluir: excluirVeiculo,
        cancelarEdicao: resetForm,
        gerarPDF: gerarPDF,

        relPesquisar: _relPesquisar,
        relLimparFiltros: _relLimparFiltros,
        relExportarCSV: _relExportarCSV,
        relGerarPDF: _relGerarPDF,
        relAplicarPreset: _relAplicarPreset,
        relIrPagina: _relBuscar,
        relLimparMorador: _relLimparMorador,
        relLimparDependente: _relLimparDependente
    };
}

export function destroy() {
    console.log('[Veiculos] Limpando...');
    delete window.VeiculosPage;
    veiculosCache = [];
    modoEdicao = false;
    veiculoEditId = null;

    Object.values(_relCharts).forEach((c) => c?.destroy?.());
    _relCharts = {};
    _relCarregado = false;
    _relExtraFiltros = {};
    _relPresetAtivo = null;
    clearTimeout(_relToastTimer);
}

function setupForm() {
    const form = document.getElementById('formVeiculo');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await salvarVeiculo();
    });
}

function setupBusca() {
    const inputBusca = document.getElementById('buscaVeiculo');
    if (!inputBusca) return;

    inputBusca.addEventListener('input', () => {
        filtrarVeiculos(inputBusca.value);
    });

    inputBusca.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarVeiculos();
        }
    });
}

function setupActions() {
    const btnBuscar = document.getElementById('btnBuscarVeiculo');
    if (btnBuscar) {
        btnBuscar.addEventListener('click', buscarVeiculos);
    }

    const btnCancelar = document.getElementById('btnCancelarEdicaoVeiculo');
    if (btnCancelar) {
        btnCancelar.addEventListener('click', resetForm);
    }

    const selectMorador = document.getElementById('selectMorador');
    if (selectMorador) {
        selectMorador.addEventListener('change', async () => {
            await onMoradorChange(selectMorador.value);
        });
    }

    const btnDependentes = document.getElementById('btnToggleDependentes');
    if (btnDependentes) {
        btnDependentes.addEventListener('click', togglePainelDependentes);
    }

    const radiosDestino = document.querySelectorAll('input[name="destinoCadastro"]');
    radiosDestino.forEach((radio) => {
        radio.addEventListener('change', aplicarModoVinculo);
    });
}

function setLoading(ativo) {
    const loading = document.getElementById('loadingVeiculos');
    if (loading) {
        loading.style.display = ativo ? 'block' : 'none';
    }
}

async function carregarMoradores() {
    const select = document.getElementById('selectMorador');
    if (!select) return;

    try {
        const response = await fetch(API_MORADORES + '?por_pagina=0');
        const data = await response.json();

        select.innerHTML = '<option value="">Selecione um morador</option>';

        // api_moradores retorna dados paginados: { itens: [...], total, ... }
        const listaMoradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        if (!data.sucesso || listaMoradores.length === 0) {
            return;
        }

        listaMoradores.forEach((morador) => {
            const id = morador.id || morador.id_morador;
            const nome = morador.nome || morador.nome_completo;
            const unidade = morador.unidade || '';
            if (!id || !nome) return;

            const option = document.createElement('option');
            option.value = String(id);
            option.textContent = unidade ? `${nome} - Unidade ${unidade}` : nome;
            select.appendChild(option);
        });
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar moradores:', error);
    }
}

async function carregarVeiculos() {
    const tbody = document.querySelector('#tabelaVeiculos tbody');
    setLoading(true);

    try {
        const response = await fetch(API_VEICULOS);
        const data = await response.json();

        if (!data.sucesso) {
            renderMensagemTabela(tbody, data.mensagem || 'Erro ao carregar veiculos.');
            return;
        }

        veiculosCache = Array.isArray(data.dados) ? data.dados : [];
        renderVeiculos(veiculosCache);
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar veiculos:', error);
        renderMensagemTabela(tbody, 'Erro de conexao ao carregar dados.');
    } finally {
        setLoading(false);
    }
}

function buscarVeiculos() {
    const termo = document.getElementById('buscaVeiculo')?.value || '';
    filtrarVeiculos(termo);
}

function filtrarVeiculos(termo) {
    if (!termo || !termo.trim()) {
        renderVeiculos(veiculosCache);
        return;
    }

    const termoNormalizado = termo.toLowerCase().trim();
    const filtrados = veiculosCache.filter((veiculo) => {
        const morador = (veiculo.morador_nome || '').toLowerCase();
        const modelo = (veiculo.modelo || '').toLowerCase();
        const placa = (veiculo.placa || '').toLowerCase();
        const tag = (veiculo.tag || '').toLowerCase();
        const cor = (veiculo.cor || '').toLowerCase();
        const tipo = (veiculo.tipo || '').toLowerCase();

        return (
            morador.includes(termoNormalizado) ||
            modelo.includes(termoNormalizado) ||
            placa.includes(termoNormalizado) ||
            tag.includes(termoNormalizado) ||
            cor.includes(termoNormalizado) ||
            tipo.includes(termoNormalizado)
        );
    });

    renderVeiculos(filtrados);
}

function renderVeiculos(veiculos) {
    const tbody = document.querySelector('#tabelaVeiculos tbody');
    if (!tbody) return;

    if (!veiculos || veiculos.length === 0) {
        renderMensagemTabela(tbody, 'Nenhum veiculo encontrado.');
        return;
    }

    tbody.innerHTML = veiculos.map((v) => {
        const id = v.id || '-';
        const morador = escapeHtml(v.morador_nome || '-');
        const modelo = escapeHtml(v.modelo || '-');
        const placa = escapeHtml(v.placa || '-');
        const tag = escapeHtml(v.tag || '-');
        const dependenteNome = escapeHtml(v.dependente_nome || '-');
        const cor = escapeHtml(v.cor || '-');
        const tipo = escapeHtml(v.tipo || '-');

        return `
            <tr>
                <td>${id}</td>
                <td>${morador}</td>
                <td>${modelo}</td>
                <td><span class="plate-badge">${placa}</span></td>
                <td><span class="tag-code">${tag}</span></td>
                <td>${dependenteNome}</td>
                <td>${cor}</td>
                <td>${tipo}</td>
                <td>
                    <button class="action-btn edit" type="button" onclick="window.VeiculosPage.editar(${id})" title="Editar veiculo">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btn delete" type="button" onclick="window.VeiculosPage.excluir(${id})" title="Excluir veiculo">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function renderMensagemTabela(tbody, mensagem) {
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="9" class="empty-state">${escapeHtml(mensagem)}</td></tr>`;
}

async function salvarVeiculo() {
    const btnSalvar = document.getElementById('btnSalvarVeiculo');
    if (btnSalvar) btnSalvar.disabled = true;

    try {
        const moradorId = Number(document.getElementById('selectMorador')?.value || 0);
        const modelo = (document.getElementById('modelo')?.value || '').trim();
        const placa = normalizarPlaca(document.getElementById('placa')?.value || '');
        const tag = (document.getElementById('tag')?.value || '').trim();
        const cor = (document.getElementById('cor')?.value || '').trim();
        const tipo = (document.getElementById('tipo')?.value || '').trim();
        const destinoCadastro = getDestinoCadastro();
        const dependenteId = Number(document.getElementById('selectDependente')?.value || 0);

        if (!moradorId || !modelo || !placa || !tag) {
            alert('Morador, modelo, placa e TAG RFID sao obrigatorios.');
            return;
        }

        if (!modoEdicao && destinoCadastro === 'dependente' && !dependenteId) {
            alert('Selecione o dependente para vincular o veiculo.');
            return;
        }

        const payload = {
            morador_id: moradorId,
            modelo: modelo,
            placa: placa,
            tag: tag,
            cor: cor,
            tipo: tipo
        };

        let method = 'POST';
        if (modoEdicao && veiculoEditId) {
            method = 'PUT';
            payload.id = veiculoEditId;
        } else if (destinoCadastro === 'dependente' && dependenteId > 0) {
            payload.dependente_id = dependenteId;
        }

        const response = await fetch(API_VEICULOS, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!data.sucesso) {
            alert(`Erro: ${data.mensagem || 'Falha ao salvar veiculo.'}`);
            return;
        }

        alert(modoEdicao ? 'Veiculo atualizado com sucesso.' : 'Veiculo cadastrado com sucesso.');
        resetForm();
        await carregarVeiculos();
    } catch (error) {
        console.error('[Veiculos] Erro ao salvar:', error);
        alert('Erro interno ao salvar veiculo.');
    } finally {
        if (btnSalvar) btnSalvar.disabled = false;
    }
}

function editarVeiculo(id) {
    const veiculo = veiculosCache.find((item) => Number(item.id) === Number(id));
    if (!veiculo) return;

    modoEdicao = true;
    veiculoEditId = Number(id);

    document.getElementById('veiculoId').value = String(veiculoEditId);
    document.getElementById('selectMorador').value = String(veiculo.morador_id || '');
    document.getElementById('modelo').value = veiculo.modelo || '';
    document.getElementById('placa').value = veiculo.placa || '';
    document.getElementById('tag').value = veiculo.tag || '';
    document.getElementById('cor').value = veiculo.cor || '';
    document.getElementById('tipo').value = veiculo.tipo || '';
    preencherPainelDependentesEdicao(veiculo);

    const btnSalvar = document.getElementById('btnSalvarVeiculo');
    if (btnSalvar) {
        btnSalvar.innerHTML = '<i class="fas fa-sync"></i> Atualizar Veiculo';
    }

    const btnCancelar = document.getElementById('btnCancelarEdicaoVeiculo');
    if (btnCancelar) {
        btnCancelar.style.display = 'inline-flex';
    }

    setControlesVinculoEditando(true);

    document.getElementById('formVeiculo')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function excluirVeiculo(id) {
    if (!confirm('Deseja realmente excluir este veiculo?')) {
        return;
    }

    try {
        const response = await fetch(API_VEICULOS, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(id) })
        });

        const data = await response.json();

        if (!data.sucesso) {
            alert(`Erro: ${data.mensagem || 'Falha ao excluir veiculo.'}`);
            return;
        }

        if (modoEdicao && Number(veiculoEditId) === Number(id)) {
            resetForm();
        }

        await carregarVeiculos();
    } catch (error) {
        console.error('[Veiculos] Erro ao excluir:', error);
        alert('Erro de conexao ao excluir veiculo.');
    }
}

function resetForm() {
    const form = document.getElementById('formVeiculo');
    if (form) {
        form.reset();
    }

    document.getElementById('veiculoId').value = '';

    modoEdicao = false;
    veiculoEditId = null;
    dependentesCache = [];

    const btnSalvar = document.getElementById('btnSalvarVeiculo');
    if (btnSalvar) {
        btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar Veiculo';
        btnSalvar.disabled = false;
    }

    const btnCancelar = document.getElementById('btnCancelarEdicaoVeiculo');
    if (btnCancelar) {
        btnCancelar.style.display = 'none';
    }

    resetPainelDependentes();
    setControlesVinculoEditando(false);
}

function normalizarPlaca(placa) {
    return String(placa).trim().toUpperCase().replace(/\s+/g, '');
}

async function onMoradorChange(moradorId) {
    if (!moradorId) {
        resetPainelDependentes();
        return;
    }

    await carregarDependentesDoMorador(Number(moradorId));
}

async function carregarDependentesDoMorador(moradorId) {
    const status = document.getElementById('dependentesStatus');
    const btnDependentes = document.getElementById('btnToggleDependentes');
    const selectDependente = document.getElementById('selectDependente');

    dependentesCache = [];
    if (selectDependente) {
        selectDependente.innerHTML = '<option value="">Selecione um dependente</option>';
    }

    try {
        const response = await fetch(`${API_DEPENDENTES}?morador_id=${moradorId}`);
        const data = await response.json();

        if (!data.sucesso || !Array.isArray(data.dados) || data.dados.length === 0) {
            if (status) status.textContent = 'Este morador nao possui dependentes cadastrados';
            if (btnDependentes) btnDependentes.disabled = true;
            esconderPainelDependentes();
            return;
        }

        dependentesCache = data.dados;
        if (status) status.textContent = `${dependentesCache.length} dependente(s) encontrado(s)`;
        if (btnDependentes) btnDependentes.disabled = false;

        dependentesCache.forEach((dep) => {
            const nome = dep.nome_completo || dep.nome || '';
            if (!nome || !selectDependente) return;
            const option = document.createElement('option');
            option.value = String(dep.id);
            option.textContent = nome;
            selectDependente.appendChild(option);
        });
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar dependentes:', error);
        if (status) status.textContent = 'Falha ao carregar dependentes';
        if (btnDependentes) btnDependentes.disabled = true;
        esconderPainelDependentes();
    }
}

function togglePainelDependentes() {
    const panel = document.getElementById('dependentesPanel');
    if (!panel || dependentesCache.length === 0) return;

    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    aplicarModoVinculo();
}

function esconderPainelDependentes() {
    const panel = document.getElementById('dependentesPanel');
    if (panel) panel.style.display = 'none';
    selecionarDestinoCadastro('morador');
    aplicarModoVinculo();
}

function resetPainelDependentes() {
    const status = document.getElementById('dependentesStatus');
    const btnDependentes = document.getElementById('btnToggleDependentes');
    const selectDependente = document.getElementById('selectDependente');

    if (status) status.textContent = 'Selecione um morador';
    if (btnDependentes) btnDependentes.disabled = true;
    if (selectDependente) selectDependente.innerHTML = '<option value="">Selecione um dependente</option>';

    esconderPainelDependentes();
}

function getDestinoCadastro() {
    const selected = document.querySelector('input[name="destinoCadastro"]:checked');
    return selected ? selected.value : 'morador';
}

function selecionarDestinoCadastro(valor) {
    const radio = document.querySelector(`input[name="destinoCadastro"][value="${valor}"]`);
    if (radio) radio.checked = true;
}

function aplicarModoVinculo() {
    const wrap = document.getElementById('dependenteSelectWrap');
    const selectDependente = document.getElementById('selectDependente');
    const modo = getDestinoCadastro();

    const mostrarDependente = modo === 'dependente';
    if (wrap) wrap.style.display = mostrarDependente ? 'block' : 'none';
    if (selectDependente) {
        selectDependente.required = mostrarDependente;
        if (!mostrarDependente) selectDependente.value = '';
    }
}

function setControlesVinculoEditando(editando) {
    const selectMorador = document.getElementById('selectMorador');
    const btnDependentes = document.getElementById('btnToggleDependentes');
    const radios = document.querySelectorAll('input[name="destinoCadastro"]');
    const selectDependente = document.getElementById('selectDependente');

    if (selectMorador) selectMorador.disabled = editando;
    if (btnDependentes) btnDependentes.disabled = editando || dependentesCache.length === 0;
    radios.forEach((radio) => {
        radio.disabled = editando;
    });
    if (selectDependente) selectDependente.disabled = editando;
}

function preencherPainelDependentesEdicao(veiculo) {
    const panel = document.getElementById('dependentesPanel');
    const status = document.getElementById('dependentesStatus');
    const selectDependente = document.getElementById('selectDependente');

    if (!panel || !status || !selectDependente) return;

    panel.style.display = 'block';
    status.textContent = veiculo.dependente_id
        ? 'Veiculo vinculado a dependente (somente leitura na edicao)'
        : 'Veiculo vinculado ao morador (somente leitura na edicao)';

    selectDependente.innerHTML = '<option value="">Selecione um dependente</option>';

    if (veiculo.dependente_id) {
        const option = document.createElement('option');
        option.value = String(veiculo.dependente_id);
        option.textContent = veiculo.dependente_nome || `Dependente #${veiculo.dependente_id}`;
        selectDependente.appendChild(option);
        selectDependente.value = String(veiculo.dependente_id);
        selecionarDestinoCadastro('dependente');
    } else {
        selecionarDestinoCadastro('morador');
    }

    aplicarModoVinculo();
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function gerarPDF() {
    var filtro = document.getElementById('buscaVeiculo')?.value || '';
    var base = window.location.origin + '/api/api_relatorio_veiculos_pdf.php';
    var url  = base + '?filtro=' + encodeURIComponent(filtro);
    window.open(url, '_blank');
}

// ══════════════════════════════════════════════════════════════════════════
// ABA RELATÓRIOS
// ══════════════════════════════════════════════════════════════════════════

function _relSetupTabs() {
    document.querySelectorAll('.page-veiculos .tab-button').forEach((btn) => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.page-veiculos .tab-button').forEach((b) =>
                b.classList.toggle('active', b === btn));
            document.querySelectorAll('.page-veiculos .tab-content').forEach((c) =>
                c.classList.toggle('active', c.id === `tab-${tab}`));

            if (tab === 'relatorios' && !_relCarregado) {
                _relCarregado = true;
                _relInicializar();
            }
        });
    });
}

async function _relInicializar() {
    await Promise.all([_relCarregarUnidades(), _relCarregarCorOptions()]);
    _relSetupAutocompleteMorador();
    _relSetupAutocompleteDependente();
    await _relCarregarDashboard();
    await _relBuscar(1);
    _relCarregarGraficos();
}

function _relToast(msg, tipo = 'info') {
    const el = document.getElementById('veic-toast');
    if (!el) { console.warn('[Veiculos]', msg); return; }

    const cores = {
        success: { bg: '#f0fdf4', border: '#86efac', color: '#166534' },
        error:   { bg: '#fef2f2', border: '#fca5a5', color: '#991b1b' },
        info:    { bg: '#eff6ff', border: '#93c5fd', color: '#1e40af' }
    };
    const c = cores[tipo] || cores.info;
    el.style.cssText = `display:block;position:fixed;top:20px;right:20px;z-index:9999;
        padding:14px 20px;border-radius:8px;font-size:.9rem;min-width:260px;
        box-shadow:0 4px 16px rgba(0,0,0,.15);
        background:${c.bg};border:1px solid ${c.border};color:${c.color};`;
    el.textContent = msg;
    clearTimeout(_relToastTimer);
    _relToastTimer = setTimeout(() => { el.style.display = 'none'; }, 3500);
}

function _relSetContador(texto) {
    const el = document.getElementById('veic-rel-contador-texto');
    if (el) el.textContent = texto;
}

function _relSetLoadingResultados(ativo) {
    const el = document.getElementById('veic-rel-loading');
    if (el) el.style.display = ativo ? 'block' : 'none';
}

// ── Unidades e cores para os selects de filtro ────────────────────────────
async function _relCarregarUnidades() {
    const select = document.getElementById('veic-f-unidade');
    if (!select) return;

    try {
        const resp = await fetch(`${API_VEICULOS}?acao=relatorio_unidades`);
        const data = await resp.json();
        if (!data.sucesso) return;

        select.innerHTML = '<option value="">Todas</option>' +
            (data.dados || []).map((u) => `<option value="${escapeHtml(u.nome)}">${escapeHtml(u.nome)}</option>`).join('');
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar unidades:', error);
    }
}

async function _relCarregarCorOptions() {
    const select = document.getElementById('veic-f-cor');
    if (!select) return;

    try {
        const resp = await fetch(`${API_VEICULOS}?acao=relatorio_agregado&tipo_agregado=por_cor`);
        const data = await resp.json();
        if (!data.sucesso) return;

        const cores = (data.dados || [])
            .map((l) => l.chave)
            .filter((c) => c && c !== 'Não informada');

        select.innerHTML = '<option value="">Todas</option>' +
            cores.map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar cores:', error);
    }
}

// ── Autocomplete: Morador e Dependente (filtros da aba Relatórios) ────────
function _relSetupAutocompleteMorador() {
    const input = document.getElementById('veic-f-morador-busca');
    const lista = document.getElementById('veic-f-morador-lista');
    const hiddenId = document.getElementById('veic-f-morador-id');
    const tag = document.getElementById('veic-f-morador-tag');
    if (!input || !lista) return;

    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (hiddenId) hiddenId.value = '';
        if (q.length < 2) { lista.classList.remove('visible'); return; }

        timer = setTimeout(async () => {
            try {
                const resp = await fetch(`${API_MORADORES}?nome=${encodeURIComponent(q)}&por_pagina=10`);
                const json = await resp.json();
                const moradores = json.dados?.itens || (Array.isArray(json.dados) ? json.dados : []);
                if (!moradores.length) { lista.innerHTML = ''; lista.classList.remove('visible'); return; }

                lista.innerHTML = moradores.map((m) => `
                    <div class="veic-autocomplete-item" data-id="${m.id}" data-nome="${escapeHtml(m.nome)}">
                        ${escapeHtml(m.nome)}
                        <div class="veic-ac-sub">Unidade: ${escapeHtml(m.unidade || '—')}</div>
                    </div>
                `).join('');
                lista.classList.add('visible');
            } catch (error) {
                console.error('[Veiculos] Erro no autocomplete de morador:', error);
            }
        }, 300);
    });

    lista.addEventListener('click', (e) => {
        const item = e.target.closest('.veic-autocomplete-item');
        if (!item) return;
        if (hiddenId) hiddenId.value = item.dataset.id;
        input.value = item.dataset.nome;
        if (tag) {
            tag.innerHTML = `<i class="fas fa-user"></i> ${escapeHtml(item.dataset.nome)} <button type="button" onclick="window.VeiculosPage?.relLimparMorador()">&times;</button>`;
            tag.style.display = 'inline-flex';
        }
        lista.classList.remove('visible');
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#veic-f-morador-busca') && !e.target.closest('#veic-f-morador-lista')) {
            lista.classList.remove('visible');
        }
    });
}

function _relSetupAutocompleteDependente() {
    const input = document.getElementById('veic-f-dependente-busca');
    const lista = document.getElementById('veic-f-dependente-lista');
    const hiddenId = document.getElementById('veic-f-dependente-id');
    const tag = document.getElementById('veic-f-dependente-tag');
    if (!input || !lista) return;

    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (hiddenId) hiddenId.value = '';
        if (q.length < 2) { lista.classList.remove('visible'); return; }

        timer = setTimeout(async () => {
            try {
                const resp = await fetch(`${API_VEICULOS}?acao=relatorio_buscar_dependente&q=${encodeURIComponent(q)}`);
                const json = await resp.json();
                const deps = Array.isArray(json.dados) ? json.dados : [];
                if (!deps.length) { lista.innerHTML = ''; lista.classList.remove('visible'); return; }

                lista.innerHTML = deps.map((d) => `
                    <div class="veic-autocomplete-item" data-id="${d.id}" data-nome="${escapeHtml(d.nome_completo)}">
                        ${escapeHtml(d.nome_completo)}
                        <div class="veic-ac-sub">Unidade: ${escapeHtml(d.unidade || '—')}</div>
                    </div>
                `).join('');
                lista.classList.add('visible');
            } catch (error) {
                console.error('[Veiculos] Erro no autocomplete de dependente:', error);
            }
        }, 300);
    });

    lista.addEventListener('click', (e) => {
        const item = e.target.closest('.veic-autocomplete-item');
        if (!item) return;
        if (hiddenId) hiddenId.value = item.dataset.id;
        input.value = item.dataset.nome;
        if (tag) {
            tag.innerHTML = `<i class="fas fa-user-friends"></i> ${escapeHtml(item.dataset.nome)} <button type="button" onclick="window.VeiculosPage?.relLimparDependente()">&times;</button>`;
            tag.style.display = 'inline-flex';
        }
        lista.classList.remove('visible');
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#veic-f-dependente-busca') && !e.target.closest('#veic-f-dependente-lista')) {
            lista.classList.remove('visible');
        }
    });
}

function _relLimparMorador() {
    const input = document.getElementById('veic-f-morador-busca');
    const hidden = document.getElementById('veic-f-morador-id');
    const tag = document.getElementById('veic-f-morador-tag');
    if (input) input.value = '';
    if (hidden) hidden.value = '';
    if (tag) { tag.style.display = 'none'; tag.innerHTML = ''; }
}

function _relLimparDependente() {
    const input = document.getElementById('veic-f-dependente-busca');
    const hidden = document.getElementById('veic-f-dependente-id');
    const tag = document.getElementById('veic-f-dependente-tag');
    if (input) input.value = '';
    if (hidden) hidden.value = '';
    if (tag) { tag.style.display = 'none'; tag.innerHTML = ''; }
}

// ── Coleta dos filtros visíveis no painel ─────────────────────────────────
function _relColetarFiltros() {
    const f = {};
    const dataInicio = document.getElementById('veic-f-data-inicio')?.value;
    const dataFim = document.getElementById('veic-f-data-fim')?.value;
    const unidade = document.getElementById('veic-f-unidade')?.value;
    const moradorId = document.getElementById('veic-f-morador-id')?.value;
    const dependenteId = document.getElementById('veic-f-dependente-id')?.value;
    const modelo = (document.getElementById('veic-f-modelo')?.value || '').trim();
    const cor = document.getElementById('veic-f-cor')?.value;
    const tipo = document.getElementById('veic-f-tipo')?.value;
    const placa = (document.getElementById('veic-f-placa')?.value || '').trim();
    const tag = (document.getElementById('veic-f-tag')?.value || '').trim();
    const busca = (document.getElementById('veic-f-busca')?.value || '').trim();

    if (dataInicio) f.data_inicio = dataInicio;
    if (dataFim) f.data_fim = dataFim;
    if (unidade) f.unidade = unidade;
    if (moradorId) f.morador_id = moradorId;
    if (dependenteId) f.dependente_id = dependenteId;
    if (modelo) f.modelo = modelo;
    if (cor) f.cor = cor;
    if (tipo) f.tipo = tipo;
    if (placa) f.placa = placa;
    if (tag) f.tag = tag;
    if (busca) f.busca = busca;

    return { ..._relExtraFiltros, ...f };
}

// ── KPIs (respeitam os filtros aplicados) ─────────────────────────────────
async function _relCarregarDashboard(filtrosOverride) {
    const filtros = filtrosOverride || _relColetarFiltros();
    const params = new URLSearchParams({ acao: 'relatorio_dashboard', ...filtros });

    try {
        const resp = await fetch(`${API_VEICULOS}?${params.toString()}`);
        const data = await resp.json();
        if (!data.sucesso) return;

        const k = data.dados;
        document.getElementById('veic-rel-kpi-total').textContent = k.total ?? 0;
        document.getElementById('veic-rel-kpi-ativos').textContent = k.ativos ?? 0;
        document.getElementById('veic-rel-kpi-dependentes').textContent = k.dependentes ?? 0;
        document.getElementById('veic-rel-kpi-tags').textContent = k.com_tag ?? 0;
        document.getElementById('veic-rel-kpi-tipos').textContent = (k.tipos && k.tipos.length) ? k.tipos.join(' • ') : '—';
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar dashboard:', error);
    }
}

// ── Busca paginada (tabela padrão de resultados) ──────────────────────────
async function _relBuscar(pagina = 1) {
    const filtros = _relColetarFiltros();
    const params = new URLSearchParams({
        acao: 'relatorio_listar',
        pagina: String(pagina),
        por_pagina: String(_relPorPagina),
        ...filtros
    });

    _relSetLoadingResultados(true);
    try {
        const resp = await fetch(`${API_VEICULOS}?${params.toString()}`);
        const data = await resp.json();

        if (!data.sucesso) {
            _relToast(data.mensagem || 'Erro ao carregar resultados.', 'error');
            return;
        }

        const { itens, total, pagina: paginaAtual, total_paginas } = data.dados;
        _relModoTabela = 'lista';
        _relPagina = paginaAtual;
        _relRenderTabelaLista(itens);
        _relRenderPaginacao(paginaAtual, total_paginas);
        _relSetContador(`${total} veículo(s) encontrado(s).`);
    } catch (error) {
        console.error('[Veiculos] Erro ao buscar relatório:', error);
        _relSetContador('Erro ao carregar dados.');
    } finally {
        _relSetLoadingResultados(false);
    }

    _relCarregarDashboard(filtros);
}

function _relRestaurarTabelaHeadPadrao() {
    const thead = document.querySelector('#veic-rel-tabela thead tr');
    if (!thead) return;
    thead.innerHTML = `
        <th>Unidade</th>
        <th>Morador</th>
        <th>Dependente</th>
        <th>Modelo</th>
        <th>Placa</th>
        <th>TAG RFID</th>
        <th>Tipo</th>
        <th>Cor</th>
        <th>Data Cadastro</th>
    `;
}

function _relRenderTabelaLista(itens) {
    const tbody = document.querySelector('#veic-rel-tabela tbody');
    if (!tbody) return;

    if (!itens || !itens.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="empty-state">Nenhum veiculo encontrado para os filtros aplicados.</td></tr>';
        return;
    }

    tbody.innerHTML = itens.map((v) => `
        <tr>
            <td><span class="rel-badge-unidade">${escapeHtml(v.morador_unidade || '-')}</span></td>
            <td>${escapeHtml(v.morador_nome || '-')}</td>
            <td>${escapeHtml(v.dependente_nome || '-')}</td>
            <td>${escapeHtml(v.modelo || '-')}</td>
            <td><span class="plate-badge">${escapeHtml(v.placa || '-')}</span></td>
            <td><span class="tag-code">${escapeHtml(v.tag || '-')}</span></td>
            <td>${escapeHtml(v.tipo || '-')}</td>
            <td>${escapeHtml(v.cor || '-')}</td>
            <td>${escapeHtml(v.data_cadastro || '-')}</td>
        </tr>
    `).join('');
}

function _relRenderPaginacao(paginaAtual, totalPaginas) {
    const el = document.getElementById('veic-rel-paginacao');
    if (!el) return;

    if (_relModoTabela !== 'lista' || !totalPaginas || totalPaginas <= 1) {
        el.innerHTML = '';
        return;
    }

    const botoes = [];
    botoes.push(`<button type="button" ${paginaAtual <= 1 ? 'disabled' : ''} onclick="window.VeiculosPage?.relIrPagina(${paginaAtual - 1})"><i class="fas fa-chevron-left"></i></button>`);

    const inicio = Math.max(1, paginaAtual - 2);
    const fim = Math.min(totalPaginas, paginaAtual + 2);
    for (let p = inicio; p <= fim; p++) {
        botoes.push(`<button type="button" class="${p === paginaAtual ? 'ativo' : ''}" onclick="window.VeiculosPage?.relIrPagina(${p})">${p}</button>`);
    }

    botoes.push(`<button type="button" ${paginaAtual >= totalPaginas ? 'disabled' : ''} onclick="window.VeiculosPage?.relIrPagina(${paginaAtual + 1})"><i class="fas fa-chevron-right"></i></button>`);
    el.innerHTML = botoes.join('');
}

// ── Ações do painel de filtros ────────────────────────────────────────────
function _relPesquisar() {
    _relExtraFiltros = {};
    _relPresetAtivo = null;
    document.querySelectorAll('.page-veiculos .rel-tipo-card').forEach((c) => c.classList.remove('ativo'));
    _relRestaurarTabelaHeadPadrao();
    _relBuscar(1);
}

function _relLimparFiltros() {
    ['veic-f-data-inicio', 'veic-f-data-fim', 'veic-f-unidade', 'veic-f-modelo',
        'veic-f-cor', 'veic-f-tipo', 'veic-f-placa', 'veic-f-tag', 'veic-f-busca'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    _relLimparMorador();
    _relLimparDependente();

    _relExtraFiltros = {};
    _relPresetAtivo = null;
    document.querySelectorAll('.page-veiculos .rel-tipo-card').forEach((c) => c.classList.remove('ativo'));
    _relRestaurarTabelaHeadPadrao();
    _relBuscar(1);
}

// ── Exportar CSV (busca todos os registros filtrados, sem paginação) ─────
async function _relExportarCSV() {
    const filtros = _relColetarFiltros();
    const params = new URLSearchParams({ acao: 'relatorio_listar', por_pagina: '0', ...filtros });

    try {
        const resp = await fetch(`${API_VEICULOS}?${params.toString()}`);
        const data = await resp.json();

        if (!data.sucesso) {
            _relToast(data.mensagem || 'Erro ao exportar CSV.', 'error');
            return;
        }

        const itens = data.dados?.itens || [];
        if (!itens.length) {
            _relToast('Nenhum dado para exportar.', 'info');
            return;
        }

        const rows = [['Unidade', 'Morador', 'Dependente', 'Modelo', 'Placa', 'TAG RFID', 'Tipo', 'Cor', 'Data Cadastro']];
        itens.forEach((v) => rows.push([
            v.morador_unidade || '', v.morador_nome || '', v.dependente_nome || '',
            v.modelo || '', v.placa || '', v.tag || '', v.tipo || '', v.cor || '', v.data_cadastro || ''
        ]));

        const csv = rows.map((r) => r.map((val) => `"${String(val).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `relatorio_veiculos_${_relDataArquivo()}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('[Veiculos] Erro ao exportar CSV:', error);
        _relToast('Erro de conexao ao exportar CSV.', 'error');
    }
}

function _relDataArquivo() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}`;
}

// ── Gerar PDF (abre a API de impressão com o conjunto completo de filtros) ─
function _relGerarPDF() {
    const filtros = _relColetarFiltros();
    const params = new URLSearchParams(filtros);
    const base = window.location.origin + '/api/api_relatorio_veiculos_pdf.php';
    window.open(`${base}?${params.toString()}`, '_blank');
}

// ── Relatórios Prontos (presets) ───────────────────────────────────────────
const _REL_PRESETS_AGREGADOS = ['por_unidade', 'por_tipo', 'por_cor', 'tags_duplicadas', 'placas_duplicadas'];

function _relAplicarPreset(tipo) {
    document.querySelectorAll('.page-veiculos .rel-tipo-card').forEach((c) =>
        c.classList.toggle('ativo', c.dataset.tipo === tipo));
    _relPresetAtivo = tipo;

    if (_REL_PRESETS_AGREGADOS.includes(tipo)) {
        _relCarregarAgregado(tipo);
        return;
    }

    _relRestaurarTabelaHeadPadrao();

    if (tipo === 'sem_tag') { _relExtraFiltros = { sem_tag: '1' }; _relBuscar(1); return; }
    if (tipo === 'dependentes') { _relExtraFiltros = { dependentes_apenas: '1' }; _relBuscar(1); return; }
    if (tipo === 'inativos') { _relExtraFiltros = { ativo: '0' }; _relBuscar(1); return; }

    if (tipo === 'por_periodo') {
        const dataInicio = document.getElementById('veic-f-data-inicio');
        const dataFim = document.getElementById('veic-f-data-fim');
        if (!dataInicio?.value && !dataFim?.value) {
            _relToast('Selecione a Data Inicial e/ou Final para este relatorio.', 'info');
            dataInicio?.focus();
            return;
        }
        _relExtraFiltros = {};
        _relBuscar(1);
    }
}

async function _relCarregarAgregado(tipo) {
    _relSetLoadingResultados(true);
    try {
        const resp = await fetch(`${API_VEICULOS}?acao=relatorio_agregado&tipo_agregado=${encodeURIComponent(tipo)}`);
        const data = await resp.json();

        if (!data.sucesso) {
            _relToast(data.mensagem || 'Erro ao carregar relatorio.', 'error');
            return;
        }

        _relModoTabela = 'agregado';
        _relRenderTabelaAgregada(tipo, data.dados || []);
        _relRenderPaginacao(1, 1);

        const nomes = {
            por_unidade: 'Veiculos por Unidade',
            por_tipo: 'Veiculos por Tipo',
            por_cor: 'Veiculos por Cor',
            tags_duplicadas: 'TAGs Duplicadas',
            placas_duplicadas: 'Placas Duplicadas'
        };
        _relSetContador(`${nomes[tipo] || 'Relatorio'} — ${(data.dados || []).length} registro(s).`);
    } catch (error) {
        console.error('[Veiculos] Erro ao carregar relatorio agregado:', error);
    } finally {
        _relSetLoadingResultados(false);
    }
}

function _relRenderTabelaAgregada(tipo, linhas) {
    const thead = document.querySelector('#veic-rel-tabela thead tr');
    const tbody = document.querySelector('#veic-rel-tabela tbody');
    if (!thead || !tbody) return;

    const config = {
        por_unidade: { colChave: 'Unidade', extra: [] },
        por_tipo: { colChave: 'Tipo', extra: [] },
        por_cor: { colChave: 'Cor', extra: [] },
        tags_duplicadas: { colChave: 'TAG RFID', extra: [{ campo: 'placas', label: 'Placas Vinculadas' }] },
        placas_duplicadas: { colChave: 'Placa', extra: [{ campo: 'tags', label: 'TAGs Vinculadas' }] }
    };
    const cfg = config[tipo] || { colChave: 'Chave', extra: [] };

    thead.innerHTML = `<th>${cfg.colChave}</th><th>Quantidade</th>` +
        cfg.extra.map((e) => `<th>${e.label}</th>`).join('');

    if (!linhas.length) {
        tbody.innerHTML = `<tr><td colspan="${2 + cfg.extra.length}" class="empty-state">Nenhum registro encontrado.</td></tr>`;
        return;
    }

    tbody.innerHTML = linhas.map((l) => `
        <tr>
            <td>${escapeHtml(l.chave || '-')}</td>
            <td><span class="rel-badge-count">${l.total}</span></td>
            ${cfg.extra.map((e) => `<td>${escapeHtml(l[e.campo] || '-')}</td>`).join('')}
        </tr>
    `).join('');
}

// ── Dashboard Grafico (Chart.js, carregado sob demanda via CDN) ──────────
function _relCarregarGraficos() {
    const render = async () => {
        try {
            const [tipoRes, corRes, mesRes] = await Promise.all([
                fetch(`${API_VEICULOS}?acao=relatorio_agregado&tipo_agregado=por_tipo`).then((r) => r.json()),
                fetch(`${API_VEICULOS}?acao=relatorio_agregado&tipo_agregado=por_cor`).then((r) => r.json()),
                fetch(`${API_VEICULOS}?acao=relatorio_agregado&tipo_agregado=por_mes`).then((r) => r.json())
            ]);
            _relRenderGraficoTipo(tipoRes.dados || []);
            _relRenderGraficoCor(corRes.dados || []);
            _relRenderGraficoMes(mesRes.dados || []);
        } catch (error) {
            console.error('[Veiculos] Erro ao carregar graficos:', error);
        }
    };

    if (typeof Chart !== 'undefined') { render(); return; }

    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    script.onload = render;
    document.head.appendChild(script);
}

function _relRenderGraficoTipo(linhas) {
    const canvas = document.getElementById('veic-grafico-tipo');
    if (!canvas) return;
    if (_relCharts.tipo) _relCharts.tipo.destroy();

    _relCharts.tipo = new Chart(canvas, {
        type: 'pie',
        data: {
            labels: linhas.map((l) => l.chave),
            datasets: [{
                data: linhas.map((l) => l.total),
                backgroundColor: ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0ea5e9', '#ef4444']
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
}

function _relRenderGraficoCor(linhas) {
    const canvas = document.getElementById('veic-grafico-cor');
    if (!canvas) return;
    if (_relCharts.cor) _relCharts.cor.destroy();

    _relCharts.cor = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: linhas.map((l) => l.chave),
            datasets: [{ label: 'Veiculos', data: linhas.map((l) => l.total), backgroundColor: '#2563eb', borderRadius: 6 }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}

function _relRenderGraficoMes(linhas) {
    const canvas = document.getElementById('veic-grafico-mes');
    if (!canvas) return;
    if (_relCharts.mes) _relCharts.mes.destroy();

    _relCharts.mes = new Chart(canvas, {
        type: 'line',
        data: {
            labels: linhas.map((l) => l.chave),
            datasets: [{
                label: 'Cadastros',
                data: linhas.map((l) => l.total),
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,.15)',
                tension: .3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}
