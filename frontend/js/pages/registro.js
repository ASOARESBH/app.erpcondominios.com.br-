/**
 * Registro Manual Page Module v3
 * - Tipo Morador: cascata Unidade → Morador → (checkbox) Dependente
 * - Tipo Visitante/Prestador: busca por doc, cascata Unidade → Morador destino
 * - Campo Entrada / Saída gravado no banco
 * - Detecção automática de veículo por placa
 */

const API_REGISTROS   = '../api/api_registros.php';
const API_VEICULOS    = '../api/api_veiculos.php';
const API_VISITANTES  = '../api/api_visitantes.php';
const API_MORADORES   = '../api/api_moradores.php';
const API_DEPENDENTES = '../api/api_dependentes.php';
const API_UNIDADES    = '../api/api_unidades.php';

let registrosCache = [];
let veiculosCache  = [];
let salvandoReg    = false;

// ── Lifecycle ─────────────────────────────────────────────────────────────────
export function init() {
    console.log('[Registro] Inicializando v3...');

    _setupTipoAcesso();
    _setupForm();
    _setupBusca();
    _setupActions();
    _setupMascaraDocumento();
    _setupCascataVisitante();
    _setupCascataMorador();
    _setupCheckDependente();
    _carregarUnidades();

    atualizarDataHoraAtual();
    carregarVeiculos();
    carregarRegistros();

    window.RegistroPage = {
        buscar:  buscarRegistros,
        excluir: excluirRegistro,
        limpar:  limparFormulario
    };
    console.log('[Registro] Módulo pronto.');
}

export function destroy() {
    console.log('[Registro] Limpando...');
    delete window.RegistroPage;
    registrosCache = [];
    veiculosCache  = [];
    salvandoReg    = false;
}

// ── Toggle Entrada / Saída ────────────────────────────────────────────────────
function _setupTipoAcesso() {
    const radios = document.querySelectorAll('input[name="tipo_acesso"]');
    radios.forEach(r => r.addEventListener('change', _atualizarBotoesAcesso));
    _atualizarBotoesAcesso();
}

function _atualizarBotoesAcesso() {
    const val = document.querySelector('input[name="tipo_acesso"]:checked')?.value || 'Entrada';
    const btnE = document.getElementById('btnEntrada');
    const btnS = document.getElementById('btnSaida');
    if (btnE) btnE.classList.toggle('entrada-ativo', val === 'Entrada');
    if (btnS) btnS.classList.toggle('saida-ativo',   val === 'Saída');
}

function _getTipoAcesso() {
    return document.querySelector('input[name="tipo_acesso"]:checked')?.value || 'Entrada';
}

// ── Carregar Unidades (ambos os selects) ──────────────────────────────────────
async function _carregarUnidades() {
    try {
        const resp = await fetch(API_UNIDADES + '?acao=select');
        const data = await resp.json();
        const unidades = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);

        // Select de destino (visitante/prestador)
        const selDest = document.getElementById('unidadeDestinoRegistro');
        if (selDest) {
            selDest.innerHTML = '<option value="">Selecione a unidade...</option>';
            unidades.forEach(u => selDest.add(new Option(u.nome, u.nome)));
        }

        // Select de morador (tipo Morador)
        const selMor = document.getElementById('unidadeMoradorRegistro');
        if (selMor) {
            selMor.innerHTML = '<option value="">Selecione a unidade...</option>';
            unidades.forEach(u => selMor.add(new Option(u.nome, u.nome)));
        }

        console.log(`[Registro] ${unidades.length} unidades carregadas.`);
    } catch (e) {
        console.warn('[Registro] Não foi possível carregar unidades:', e);
    }
}

// ── Cascata Unidade → Morador (bloco Morador) ─────────────────────────────────
function _setupCascataMorador() {
    const selUnidade = document.getElementById('unidadeMoradorRegistro');
    if (!selUnidade) return;

    selUnidade.addEventListener('change', async () => {
        const unidade = selUnidade.value;
        const selMorador = document.getElementById('moradorSelecionadoRegistro');
        const infoWrap   = document.getElementById('moradorInfoWrap');
        const infoBox    = document.getElementById('moradorInfoBox');

        // Resetar dependente
        _resetarDependente();

        if (!unidade) {
            if (selMorador) { selMorador.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selMorador.disabled = true; }
            if (infoWrap) infoWrap.style.display = 'none';
            return;
        }

        if (selMorador) { selMorador.innerHTML = '<option value="">Carregando...</option>'; selMorador.disabled = true; }

        try {
            const resp = await fetch(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`);
            const data = await resp.json();
            const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);

            if (selMorador) {
                selMorador.innerHTML = '<option value="">Selecione o morador...</option>';
                moradores.forEach(m => {
                    const opt = new Option(m.nome_completo || m.nome, m.id);
                    opt.dataset.nome    = m.nome_completo || m.nome;
                    opt.dataset.unidade = m.unidade || unidade;
                    selMorador.add(opt);
                });
                selMorador.disabled = moradores.length === 0;
                if (moradores.length === 0) selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
            }
            if (infoWrap) infoWrap.style.display = 'none';
        } catch (e) {
            if (selMorador) { selMorador.innerHTML = '<option value="">Erro ao carregar</option>'; selMorador.disabled = true; }
        }
    });

    // Ao selecionar morador → mostrar info e carregar dependentes
    const selMorador = document.getElementById('moradorSelecionadoRegistro');
    if (selMorador) {
        selMorador.addEventListener('change', () => {
            const opt     = selMorador.options[selMorador.selectedIndex];
            const infoWrap = document.getElementById('moradorInfoWrap');
            const infoBox  = document.getElementById('moradorInfoBox');
            const moradorId = selMorador.value;

            _resetarDependente();

            if (!moradorId) {
                if (infoWrap) infoWrap.style.display = 'none';
                document.getElementById('moradorId').value = '';
                return;
            }

            document.getElementById('moradorId').value = moradorId;

            if (infoBox) {
                infoBox.innerHTML = `<i class="fas fa-home" style="margin-right:6px"></i>
                    <strong>${_esc(opt.dataset.nome || opt.text)}</strong>
                    &nbsp;—&nbsp; Unidade: <strong>${_esc(opt.dataset.unidade || selMorador.closest('.form-grid')?.querySelector('select')?.value || '')}</strong>`;
            }
            if (infoWrap) infoWrap.style.display = 'block';

            // Carregar dependentes deste morador
            _carregarDependentesMorador(moradorId);
        });
    }
}

// ── Carregar Dependentes ──────────────────────────────────────────────────────
async function _carregarDependentesMorador(moradorId) {
    const sel = document.getElementById('dependenteSelecionadoRegistro');
    if (!sel) return;

    sel.innerHTML = '<option value="">Carregando...</option>';
    sel.disabled = true;

    try {
        const resp = await fetch(`${API_DEPENDENTES}?morador_id=${moradorId}`);
        const data = await resp.json();
        const deps = Array.isArray(data.dados) ? data.dados : (data.dados?.itens || []);

        sel.innerHTML = '<option value="">Selecione o dependente...</option>';
        deps.forEach(d => {
            const opt = new Option(`${d.nome_completo || d.nome} (${d.parentesco || 'Familiar'})`, d.id);
            opt.dataset.nome       = d.nome_completo || d.nome;
            opt.dataset.parentesco = d.parentesco || '';
            sel.add(opt);
        });
        sel.disabled = deps.length === 0;
        if (deps.length === 0) sel.innerHTML = '<option value="">Nenhum dependente cadastrado</option>';

        console.log(`[Registro] ${deps.length} dependentes carregados para morador ${moradorId}.`);
    } catch (e) {
        sel.innerHTML = '<option value="">Erro ao carregar dependentes</option>';
        sel.disabled = true;
        console.warn('[Registro] Erro ao carregar dependentes:', e);
    }
}

function _resetarDependente() {
    const check = document.getElementById('checkDependente');
    if (check) check.checked = false;
    const wrap = document.getElementById('dependenteWrap');
    if (wrap) wrap.style.display = 'none';
    const sel = document.getElementById('dependenteSelecionadoRegistro');
    if (sel) { sel.innerHTML = '<option value="">Selecione o morador primeiro</option>'; sel.disabled = true; }
    const infoWrap = document.getElementById('dependenteInfoWrap');
    if (infoWrap) infoWrap.style.display = 'none';
}

// ── Checkbox Dependente ───────────────────────────────────────────────────────
function _setupCheckDependente() {
    const check = document.getElementById('checkDependente');
    if (!check) return;

    check.addEventListener('change', () => {
        const wrap = document.getElementById('dependenteWrap');
        if (wrap) wrap.style.display = check.checked ? 'block' : 'none';
    });

    // Ao selecionar dependente → mostrar info
    const selDep = document.getElementById('dependenteSelecionadoRegistro');
    if (selDep) {
        selDep.addEventListener('change', () => {
            const opt      = selDep.options[selDep.selectedIndex];
            const infoWrap = document.getElementById('dependenteInfoWrap');
            const infoBox  = document.getElementById('dependenteInfoBox');

            if (!selDep.value) {
                if (infoWrap) infoWrap.style.display = 'none';
                return;
            }
            if (infoBox) {
                infoBox.innerHTML = `<i class="fas fa-user-friends" style="margin-right:6px"></i>
                    <strong>${_esc(opt.dataset.nome || opt.text)}</strong>
                    ${opt.dataset.parentesco ? `&nbsp;—&nbsp; ${_esc(opt.dataset.parentesco)}` : ''}`;
            }
            if (infoWrap) infoWrap.style.display = 'block';
        });
    }
}

// ── Cascata Unidade → Morador (bloco Visitante/Prestador) ─────────────────────
function _setupCascataVisitante() {
    const selUnidade = document.getElementById('unidadeDestinoRegistro');
    if (!selUnidade) return;

    selUnidade.addEventListener('change', () => {
        const unidade   = selUnidade.value;
        const selMorador = document.getElementById('moradorDestinoRegistro');
        if (!selMorador) return;

        if (!unidade) {
            selMorador.innerHTML = '<option value="">Selecione a unidade primeiro</option>';
            selMorador.disabled = true;
            return;
        }

        selMorador.innerHTML = '<option value="">Carregando...</option>';
        selMorador.disabled = true;

        fetch(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`)
            .then(r => r.json())
            .then(data => {
                const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
                selMorador.innerHTML = '<option value="">Selecione o morador...</option>';
                moradores.forEach(m => selMorador.add(new Option(m.nome_completo || m.nome, m.id)));
                selMorador.disabled = moradores.length === 0;
                if (moradores.length === 0) selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
            })
            .catch(() => { selMorador.innerHTML = '<option value="">Erro ao carregar</option>'; selMorador.disabled = true; });
    });
}

// ── Máscara Documento ─────────────────────────────────────────────────────────
function _setupMascaraDocumento() {
    const tipoDoc  = document.getElementById('tipoDocRegistro');
    const docInput = document.getElementById('documentoRegistro');
    if (!tipoDoc || !docInput) return;

    tipoDoc.addEventListener('change', () => {
        docInput.value = '';
        docInput.placeholder = tipoDoc.value === 'CPF' ? '000.000.000-00' : 'XX.XXX.XXX-X';
    });

    docInput.addEventListener('input', () => {
        let v = docInput.value.replace(/\D/g, '');
        if (tipoDoc.value === 'CPF') {
            v = v.slice(0, 11);
            if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        } else {
            v = v.slice(0, 9);
            if (v.length > 8)      v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1})/, '$1.$2.$3-$4');
            else if (v.length > 5) v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
            else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
        }
        docInput.value = v;
    });

    docInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); _buscarVisitantePorDocumento(); }
    });

    const btnBuscar = document.getElementById('btnBuscarDocRegistro');
    if (btnBuscar) btnBuscar.addEventListener('click', _buscarVisitantePorDocumento);
}

// ── Buscar Visitante por Documento ────────────────────────────────────────────
async function _buscarVisitantePorDocumento() {
    const docInput = document.getElementById('documentoRegistro');
    const doc = docInput?.value.trim() || '';
    if (!doc) { mostrarAlerta('error', 'Informe o documento para buscar.'); return; }

    const btnBuscar = document.getElementById('btnBuscarDocRegistro');
    if (btnBuscar) { btnBuscar.disabled = true; btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    try {
        const resp = await fetch(`${API_VISITANTES}?documento=${encodeURIComponent(doc)}`);
        const data = await resp.json();
        const box  = document.getElementById('visitanteEncontrado');

        if (data.sucesso && data.dados) {
            const v = data.dados;
            document.getElementById('nomeVisitanteRegistro').value = v.nome_completo || '';
            document.getElementById('visitanteIdRegistro').value   = v.id || '';
            const placaInput = document.getElementById('placaRegistro');
            if (placaInput && !placaInput.value && v.placa_veiculo) placaInput.value = v.placa_veiculo;
            if (box) {
                box.style.display = 'flex';
                box.innerHTML = `<i class="fas fa-check-circle"></i>
                    <span>Cadastro encontrado: <strong>${_esc(v.nome_completo)}</strong>
                    — ${_esc(v.tipo_documento)}: ${_esc(v.documento)}
                    ${v.telefone_contato ? '— Tel: ' + _esc(v.telefone_contato) : ''}</span>`;
            }
        } else {
            document.getElementById('nomeVisitanteRegistro').value = '';
            document.getElementById('visitanteIdRegistro').value   = '';
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            mostrarAlerta('warning', 'Visitante não encontrado. Preencha o nome manualmente ou cadastre-o no módulo Visitantes.');
        }
    } catch (error) {
        console.error('[Registro] Erro ao buscar visitante:', error);
        mostrarAlerta('error', 'Erro ao buscar cadastro do visitante.');
    } finally {
        if (btnBuscar) { btnBuscar.disabled = false; btnBuscar.innerHTML = '<i class="fas fa-search"></i>'; }
    }
}

// ── Form Setup ────────────────────────────────────────────────────────────────
function _setupForm() {
    const form = document.getElementById('registroForm');
    if (!form) return;
    form.addEventListener('submit', async e => { e.preventDefault(); await salvarRegistro(); });

    const placaInput = document.getElementById('placaRegistro');
    if (placaInput) {
        placaInput.addEventListener('input', formatarPlacaInput);
        placaInput.addEventListener('blur', detectarVeiculoPorPlaca);
    }

    const tipo = document.getElementById('tipoRegistro');
    if (tipo) tipo.addEventListener('change', onTipoChange);
}

function _setupBusca() {
    const input = document.getElementById('buscaRegistro');
    if (!input) return;
    input.addEventListener('input', () => filtrarRegistros(input.value));
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); buscarRegistros(); } });
}

function _setupActions() {
    const btnBuscar = document.getElementById('btnBuscarRegistro');
    if (btnBuscar) btnBuscar.addEventListener('click', buscarRegistros);
    const btnLimpar = document.getElementById('btnLimparRegistro');
    if (btnLimpar) btnLimpar.addEventListener('click', limparFormulario);
}

// ── Tipo Change ───────────────────────────────────────────────────────────────
function onTipoChange() {
    const tipo         = document.getElementById('tipoRegistro')?.value || '';
    const extraCampos  = document.getElementById('extraCampos');
    const camposMorador = document.getElementById('camposMorador');

    if (extraCampos)  extraCampos.style.display  = (tipo === 'Visitante' || tipo === 'Prestador') ? 'block' : 'none';
    if (camposMorador) camposMorador.style.display = tipo === 'Morador' ? 'block' : 'none';

    if (tipo !== 'Visitante' && tipo !== 'Prestador') {
        ['nomeVisitanteRegistro', 'documentoRegistro', 'diasPermanenciaRegistro'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        const selU = document.getElementById('unidadeDestinoRegistro'); if (selU) selU.value = '';
        const selM = document.getElementById('moradorDestinoRegistro');
        if (selM) { selM.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selM.disabled = true; }
        document.getElementById('visitanteIdRegistro').value = '';
        const boxV = document.getElementById('visitanteEncontrado');
        if (boxV) { boxV.style.display = 'none'; boxV.innerHTML = ''; }
    }

    if (tipo !== 'Morador') {
        const selU = document.getElementById('unidadeMoradorRegistro'); if (selU) selU.value = '';
        const selM = document.getElementById('moradorSelecionadoRegistro');
        if (selM) { selM.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selM.disabled = true; }
        document.getElementById('moradorId').value = '';
        const iW = document.getElementById('moradorInfoWrap'); if (iW) iW.style.display = 'none';
        _resetarDependente();
    }
}

// ── Data/Hora ─────────────────────────────────────────────────────────────────
function atualizarDataHoraAtual() {
    const input = document.getElementById('dataHoraRegistro');
    if (!input) return;
    const agora = new Date();
    agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset());
    input.value = agora.toISOString().slice(0, 16);
}

// ── Carregar Veículos ─────────────────────────────────────────────────────────
async function carregarVeiculos() {
    try {
        const response = await fetch(API_VEICULOS);
        const data = await response.json();
        veiculosCache = (data.sucesso && Array.isArray(data.dados)) ? data.dados : [];
    } catch (error) {
        console.error('[Registro] Erro ao carregar veículos:', error);
        veiculosCache = [];
    }
}

// ── Carregar Registros ────────────────────────────────────────────────────────
async function carregarRegistros() {
    const tbody = document.querySelector('#tabelaRegistros tbody');
    setLoading(true);
    try {
        const response = await fetch(`${API_REGISTROS}?limite=100`);
        const data = await response.json();
        if (!data.sucesso) { renderMensagemTabela(tbody, data.mensagem || 'Erro ao carregar registros.'); return; }
        registrosCache = data.dados || [];
        renderRegistros(registrosCache);
    } catch (error) {
        console.error('[Registro] Erro ao carregar registros:', error);
        renderMensagemTabela(tbody, 'Erro de conexão ao carregar registros.');
    } finally {
        setLoading(false);
    }
}

// ── Buscar / Filtrar ──────────────────────────────────────────────────────────
function buscarRegistros() { filtrarRegistros(document.getElementById('buscaRegistro')?.value || ''); }

function filtrarRegistros(termo) {
    if (!termo?.trim()) { renderRegistros(registrosCache); return; }
    const q = termo.toLowerCase().trim();
    const filtrados = registrosCache.filter(r =>
        (r.morador_nome || r.nome_visitante || '').toLowerCase().includes(q)
        || (r.placa || '').toLowerCase().includes(q)
        || (r.tipo || '').toLowerCase().includes(q)
        || (r.morador_unidade || r.unidade_destino || '').toLowerCase().includes(q)
        || (r.status || '').toLowerCase().includes(q)
        || (r.modelo || '').toLowerCase().includes(q)
        || (r.tipo_acesso || '').toLowerCase().includes(q)
    );
    renderRegistros(filtrados);
}

// ── Render Tabela ─────────────────────────────────────────────────────────────
function renderRegistros(registros) {
    const tbody = document.querySelector('#tabelaRegistros tbody');
    if (!tbody) return;
    if (!registros?.length) { renderMensagemTabela(tbody, 'Nenhum registro encontrado.'); return; }

    tbody.innerHTML = registros.map(r => {
        const id        = r.id || 0;
        const dataHora  = _esc(r.data_hora_formatada || formatDateTime(r.data_hora) || '-');
        const placa     = _esc(r.placa || '-');
        const modelo    = _esc(r.modelo || '-');
        const cor       = _esc(r.cor || '-');
        const tipo      = _esc(r.tipo || '-');
        const nome      = _esc(r.morador_nome || r.nome_visitante || r.tipo || '-');
        const unidade   = _esc(r.morador_unidade || r.unidade_destino || '-');
        const status    = _esc(r.status || '-');
        const statusClass = classificarStatus(r.status, r.liberado);

        const tipoAcesso = r.tipo_acesso || '';
        const badgeAcesso = tipoAcesso === 'Saída'
            ? `<span class="badge-saida"><i class="fas fa-sign-out-alt"></i> Saída</span>`
            : tipoAcesso === 'Entrada'
            ? `<span class="badge-entrada"><i class="fas fa-sign-in-alt"></i> Entrada</span>`
            : '—';

        return `
            <tr>
                <td>${dataHora}</td>
                <td><span class="plate-badge">${placa}</span></td>
                <td>${modelo}</td>
                <td>${cor}</td>
                <td>${tipo}</td>
                <td>${nome}</td>
                <td>${unidade}</td>
                <td>${badgeAcesso}</td>
                <td><span class="status-pill ${statusClass}">${status}</span></td>
                <td>
                    <button class="action-btn delete" type="button" onclick="window.RegistroPage.excluir(${id})" title="Excluir registro">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
    }).join('');
}

function renderMensagemTabela(tbody, msg) {
    if (tbody) tbody.innerHTML = `<tr><td colspan="10" class="empty-state">${_esc(msg)}</td></tr>`;
}

// ── Salvar ────────────────────────────────────────────────────────────────────
async function salvarRegistro() {
    if (salvandoReg) return;
    salvandoReg = true;
    const btn = document.getElementById('btnSalvarRegistro');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    try {
        const dataHoraInput = document.getElementById('dataHoraRegistro')?.value || '';
        const placa         = normalizarPlaca(document.getElementById('placaRegistro')?.value || '');
        const modelo        = (document.getElementById('modeloRegistro')?.value || '').trim();
        const cor           = (document.getElementById('corRegistro')?.value || '').trim();
        const tipo          = (document.getElementById('tipoRegistro')?.value || '').trim();
        const observacao    = (document.getElementById('observacaoRegistro')?.value || '').trim();
        const tipoAcesso    = _getTipoAcesso();

        if (!dataHoraInput || !placa || !tipo) {
            mostrarAlerta('error', 'Data/hora, placa e tipo são obrigatórios.');
            return;
        }

        let payload = {
            data_hora:   `${dataHoraInput.replace('T', ' ')}:00`,
            placa,
            modelo,
            cor,
            tipo,
            observacao,
            tipo_acesso: tipoAcesso
        };

        // ── Morador ──
        if (tipo === 'Morador') {
            const moradorId = document.getElementById('moradorId')?.value || '';
            const unidade   = document.getElementById('unidadeMoradorRegistro')?.value || '';

            if (!unidade)   { mostrarAlerta('error', 'Selecione a unidade do morador.'); return; }
            if (!moradorId) { mostrarAlerta('error', 'Selecione o morador.'); return; }

            payload.morador_id      = moradorId;
            payload.unidade_destino = unidade;

            // Dependente
            const checkDep = document.getElementById('checkDependente');
            if (checkDep?.checked) {
                const depId = document.getElementById('dependenteSelecionadoRegistro')?.value || '';
                if (!depId) { mostrarAlerta('error', 'Selecione o dependente ou desmarque a opção.'); return; }
                payload.dependente_id = depId;
            }
        }

        // ── Visitante / Prestador ──
        if (tipo === 'Visitante' || tipo === 'Prestador') {
            const nomeVisitante   = (document.getElementById('nomeVisitanteRegistro')?.value || '').trim();
            const unidadeDestino  = document.getElementById('unidadeDestinoRegistro')?.value || '';
            const moradorDestino  = document.getElementById('moradorDestinoRegistro')?.value || '';
            const diasPermanencia = Number(document.getElementById('diasPermanenciaRegistro')?.value || 1);
            const visitanteId     = document.getElementById('visitanteIdRegistro')?.value || '';
            const documento       = document.getElementById('documentoRegistro')?.value.trim() || '';

            if (!nomeVisitante)  { mostrarAlerta('error', 'Informe o nome do visitante/prestador.'); return; }
            if (!unidadeDestino) { mostrarAlerta('error', 'Selecione a unidade de destino.'); return; }
            if (!moradorDestino) { mostrarAlerta('error', 'Selecione o morador que será visitado.'); return; }

            payload.nome_visitante   = nomeVisitante;
            payload.unidade_destino  = unidadeDestino;
            payload.morador_id       = moradorDestino || null;
            payload.dias_permanencia = diasPermanencia;
            if (visitanteId) payload.visitante_id = visitanteId;
            if (documento)   payload.documento     = documento;
        }

        console.log('[Registro] Payload:', payload);

        const response = await fetch(API_REGISTROS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (!data.sucesso) { mostrarAlerta('error', data.mensagem || 'Falha ao registrar acesso.'); return; }

        mostrarAlerta('success', data.mensagem || 'Registro salvo com sucesso.');
        limparFormulario();
        await carregarRegistros();

    } catch (error) {
        console.error('[Registro] Erro ao salvar registro:', error);
        mostrarAlerta('error', 'Erro interno ao salvar registro.');
    } finally {
        salvandoReg = false;
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Registrar Acesso'; }
    }
}

// ── Excluir ───────────────────────────────────────────────────────────────────
async function excluirRegistro(id) {
    if (!confirm('Deseja realmente excluir este registro?')) return;
    try {
        const response = await fetch(API_REGISTROS, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(id) })
        });
        const data = await response.json();
        if (!data.sucesso) { mostrarAlerta('error', data.mensagem || 'Falha ao excluir registro.'); return; }
        mostrarAlerta('success', data.mensagem || 'Registro excluído com sucesso.');
        await carregarRegistros();
    } catch (error) {
        console.error('[Registro] Erro ao excluir registro:', error);
        mostrarAlerta('error', 'Erro de conexão ao excluir registro.');
    }
}

// ── Limpar Formulário ─────────────────────────────────────────────────────────
function limparFormulario() {
    const form = document.getElementById('registroForm');
    if (form) form.reset();

    ['moradorId', 'veiculoId', 'visitanteIdRegistro'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });

    document.getElementById('extraCampos')?.style && (document.getElementById('extraCampos').style.display = 'none');
    document.getElementById('camposMorador')?.style && (document.getElementById('camposMorador').style.display = 'none');

    const boxV = document.getElementById('veiculoEncontrado');
    if (boxV) { boxV.style.display = 'none'; boxV.innerHTML = ''; }
    const boxVis = document.getElementById('visitanteEncontrado');
    if (boxVis) { boxVis.style.display = 'none'; boxVis.innerHTML = ''; }

    const selMorDest = document.getElementById('moradorDestinoRegistro');
    if (selMorDest) { selMorDest.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selMorDest.disabled = true; }

    const selMorSel = document.getElementById('moradorSelecionadoRegistro');
    if (selMorSel) { selMorSel.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selMorSel.disabled = true; }

    const iW = document.getElementById('moradorInfoWrap'); if (iW) iW.style.display = 'none';
    _resetarDependente();
    _atualizarBotoesAcesso();
    atualizarDataHoraAtual();
}

// ── Placa ─────────────────────────────────────────────────────────────────────
function formatarPlacaInput(e) {
    let v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (v.length > 7) v = v.slice(0, 7);
    if (v.length > 3) v = `${v.slice(0, 3)}-${v.slice(3)}`;
    e.target.value = v;
}

function normalizarPlaca(placa) { return String(placa).toUpperCase().replace(/[^A-Z0-9]/g, ''); }

function detectarVeiculoPorPlaca() {
    const placaInput = document.getElementById('placaRegistro');
    if (!placaInput) return;
    const placa = normalizarPlaca(placaInput.value);
    if (placa.length < 7) { esconderVeiculoEncontrado(); return; }

    const veiculo = veiculosCache.find(v => normalizarPlaca(v.placa || '') === placa);
    if (!veiculo) { esconderVeiculoEncontrado(); return; }

    document.getElementById('modeloRegistro').value = veiculo.modelo || '';
    document.getElementById('corRegistro').value    = veiculo.cor    || '';
    document.getElementById('moradorId').value      = veiculo.morador_id || '';
    document.getElementById('veiculoId').value      = veiculo.id || '';
    document.getElementById('tipoRegistro').value   = 'Morador';

    const box = document.getElementById('veiculoEncontrado');
    if (box) {
        const depInfo = veiculo.dependente_id && veiculo.dependente_nome
            ? `${_esc(veiculo.dependente_nome)}, dependente de ${_esc(veiculo.morador_nome || '-')}`
            : _esc(veiculo.morador_nome || '-');
        box.innerHTML = `<i class="fas fa-check-circle"></i> Veículo cadastrado: ${_esc(veiculo.modelo || '-')}, ${depInfo} (Unidade ${_esc(veiculo.morador_unidade || '-')})`;
        box.style.display = 'block';
    }

    onTipoChange();

    // Pré-selecionar unidade e morador se disponível
    const unidade = veiculo.morador_unidade || '';
    if (unidade) {
        const selU = document.getElementById('unidadeMoradorRegistro');
        if (selU) {
            selU.value = unidade;
            selU.dispatchEvent(new Event('change'));
            // Aguardar carregamento dos moradores e selecionar
            setTimeout(() => {
                const selM = document.getElementById('moradorSelecionadoRegistro');
                if (selM && veiculo.morador_id) {
                    selM.value = veiculo.morador_id;
                    selM.dispatchEvent(new Event('change'));
                }
            }, 600);
        }
    }
}

function esconderVeiculoEncontrado() {
    document.getElementById('moradorId').value = '';
    document.getElementById('veiculoId').value = '';
    const box = document.getElementById('veiculoEncontrado');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setLoading(ativo) {
    const el = document.getElementById('loadingRegistros');
    if (el) el.style.display = ativo ? 'block' : 'none';
}

function classificarStatus(status, liberado) {
    const s = String(status || '').toLowerCase();
    if (liberado === 1 || s.includes('liberado') || s.includes('permitido')) return 'status-ok';
    if (s.includes('negado')) return 'status-deny';
    return 'status-warn';
}

function formatDateTime(dateTimeRaw) {
    if (!dateTimeRaw) return '-';
    const date = new Date(dateTimeRaw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(dateTimeRaw);
    const dd = String(date.getDate()).padStart(2, '0');
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const yyyy = date.getFullYear();
    const hh = String(date.getHours()).padStart(2, '0');
    const mi = String(date.getMinutes()).padStart(2, '0');
    const ss = String(date.getSeconds()).padStart(2, '0');
    return `${dd}/${mm}/${yyyy} ${hh}:${mi}:${ss}`;
}

function mostrarAlerta(tipo, mensagem) {
    const box = document.getElementById('alertBox');
    if (!box) { alert(mensagem); return; }
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    const icone  = tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    box.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${_esc(mensagem)}</div>`;
    setTimeout(() => { box.innerHTML = ''; }, 6000);
}

function _esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
