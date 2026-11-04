/**
 * Registro Manual Page Module v3
 * - Tipo Morador: cascata Unidade → Morador → (checkbox) Dependente
 * - Tipos de convidado: busca por documento, cascata Unidade → Morador destino e vínculo de Lista de Convidados
 * - Campo Entrada / Saída gravado no banco
 * - Detecção automática de veículo por placa
 */

const API_REGISTROS   = '../api/api_registros.php';
const API_VEICULOS    = '../api/api_veiculos.php';
const API_VISITANTES  = '../api/api_visitantes.php';
const API_MORADORES   = '../api/api_moradores.php';
const API_DEPENDENTES = '../api/api_dependentes.php';
const API_UNIDADES    = '../api/api_unidades.php';
const API_CONVIDADOS = '../api/api_convidados.php';
const API_ALERTAS_UNIDADE = '../api/api_alertas_unidade.php';
const REGISTRO_FEEDBACK_STORAGE_KEY = 'registro_manual_feedback';
const REGISTRO_INFO_PREFERENCE_KEY = 'registro_manual_nao_mostrar_info';

let registrosCache = [];
let veiculosCache  = [];
let salvandoReg    = false;
let ocupantesAdicionados = []; // [{ visitante_id, nome, documento, tipo_documento }]
let ultimoEnvioRegistro = null;
let moduloConvidados = null;
let convidadoAtivo = null;
let politicaVisitantes = null;
let registroLifecycleToken = 0;
let documentoBuscaToken = 0;
let convidadoSelecaoToken = 0;
let registroCienciaTimer = null;
let registroCienciaController = null;
let registroCienciaVersion = 0;
let registroCienciaState = { chave: '', contexto: null, resultado: null, token: '' };
let registroCienciaListeners = [];
let registroCienciaModalResolver = null;
let registroCienciaModalPreviousFocus = null;
let registroStepperAtual = 1;
let registroEtapasConcluidas = new Set();
let registroEtapaEmProcessamento = false;
let registroEtapaModalResolver = null;
let registroEtapaModalPreviousFocus = null;
let registroEtapaModalData = null;
let registroStepperListeners = [];
let registroAlertasEtapaVistos = new Set();
let registroBaseContextoAlertas = '';
let registroEtapaToastTimer = null;
let registroInfoTimer = null;
let registroInfoRemaining = 8000;
let registroInfoStartedAt = 0;
let registroInfoPausado = false;
let registroVisitanteCompactTimer = null;
let registroVisitanteDocumentoPendente = '';
let registroVisitanteDocumentoAnexado = null;
let registroVisitanteMensagemDetalhada = '';
let registroInformativosEtapa = new Map();
let registroAlertasEspecificosPendentes = 0;
let registroAlertaTimer = null;
let registroAlertaEtapaAtual = null;
let registroUltimoErro = '';
let registroConfirmacaoDestinoPlacaKey = '';
let registroDestinoPlacaRecusadoKey = '';

function gerarIdempotencyKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (globalThis.crypto?.getRandomValues) {
        const bytes = new Uint8Array(16);
        globalThis.crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 36);
    }
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`.slice(0, 36);
}

function garantirIdempotencyKey() {
    const campo = document.getElementById('idempotencyKeyRegistro');
    if (!campo) return '';
    if (!campo.value) campo.value = gerarIdempotencyKey();
    return campo.value;
}

function assinaturaRegistro(payload) {
    const dados = { ...payload };
    delete dados.idempotency_key;
    delete dados.ciencia_token;
    return JSON.stringify(dados);
}

function registroEstaAtivo(token = registroLifecycleToken) {
    return token === registroLifecycleToken && !!document.getElementById('registroForm');
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
export function init() {
    const lifecycleToken = ++registroLifecycleToken;
    console.log('[Registro] Inicializando v3...');

    _garantirTiposRegistro();
    _setupTipoAcesso();
    _setupModoRegistro();
    _setupForm();
    _setupCienciaRegistro();
    _setupStepperRegistro();
    _setupInfoRegistro();
    _setupBusca();
    _setupActions();
    _setupMascaraDocumento();
    _setupCascataVisitante();
    _setupCascataMorador();
    _setupCheckDependente();
    _setupOcupantes();
    _atualizarCamposTipoRegistro();
    garantirIdempotencyKey();
    _carregarUnidades();

    atualizarDataHoraAtual();
    _atualizarEstadoBotoesEtapa();
    carregarVeiculos();
    carregarRegistros();
    _mostrarFeedbackPosAtualizacao();
    _carregarModuloConvidados(lifecycleToken);

    window.RegistroPage = {
        buscar:  buscarRegistros,
        excluir: excluirRegistro,
        limpar:  limparFormulario,
        removerOcupante: removerOcupante,
        iniciarConvidado
    };
    console.log('[Registro] Módulo pronto.');
}

export function destroy() {
    console.log('[Registro] Limpando...');
    registroLifecycleToken += 1;
    documentoBuscaToken += 1;
    convidadoSelecaoToken += 1;
    _destroyCienciaRegistro();
    _resolverModalAlertaEtapa({ continuar: false, destruido: true });
    registroStepperListeners.forEach(({ elemento, evento, handler }) => elemento.removeEventListener(evento, handler));
    registroStepperListeners = [];
    if (registroEtapaToastTimer) clearTimeout(registroEtapaToastTimer);
    if (registroInfoTimer) clearTimeout(registroInfoTimer);
    if (registroVisitanteCompactTimer) clearTimeout(registroVisitanteCompactTimer);
    if (registroAlertaTimer) clearTimeout(registroAlertaTimer);
    registroEtapaToastTimer = registroInfoTimer = registroVisitanteCompactTimer = registroAlertaTimer = null;
    registroInfoRemaining = 8000;
    registroInfoPausado = false;
    if (moduloConvidados?.destroy) {
        try { moduloConvidados.destroy(); } catch (error) { console.warn('[Registro][Convidados] Falha ao destruir módulo:', error); }
    }
    moduloConvidados = null;
    delete window.RegistroPage;
    registrosCache = [];
    veiculosCache  = [];
    salvandoReg    = false;
    ocupantesAdicionados = [];
    ultimoEnvioRegistro = null;
    convidadoAtivo = null;
    politicaVisitantes = null;
    registroStepperAtual = 1;
    registroEtapasConcluidas = new Set();
    registroEtapaEmProcessamento = false;
    registroAlertasEtapaVistos = new Set();
    registroInformativosEtapa = new Map();
    registroAlertasEspecificosPendentes = 0;
    registroBaseContextoAlertas = '';
    registroVisitanteDocumentoAnexado = null;
    registroVisitanteMensagemDetalhada = '';
    registroVisitanteDocumentoPendente = '';
    registroAlertaEtapaAtual = null;
    registroUltimoErro = '';
    registroConfirmacaoDestinoPlacaKey = '';
    registroDestinoPlacaRecusadoKey = '';
}

function _carregarModuloConvidados(lifecycleToken) {
    import('./registro-convidados.js')
        .then((modulo) => {
            if (lifecycleToken !== registroLifecycleToken || !document.getElementById('tab-lista-convidados')) {
                if (typeof modulo.destroy === 'function') modulo.destroy();
                return;
            }
            moduloConvidados = modulo;
            if (typeof modulo.init === 'function') modulo.init();
            console.log('[Registro][Convidados] Módulo carregado.');
        })
        .catch((error) => {
            moduloConvidados = null;
            console.warn('[Registro][Convidados] Módulo opcional indisponível; mantendo o registro manual:', error?.message || error);
        });
}

function _garantirTiposRegistro() {
    const select = document.getElementById('tipoRegistro');
    if (!select) return;
    [
        ['Entregador Autorizado', 'Entregador Autorizado'],
        ['Familiar', 'Familiar']
    ].forEach(([value, label]) => {
        if (![...select.options].some((option) => option.value === value)) select.add(new Option(label, value));
    });
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

function _isPedestre() {
    return document.getElementById('checkPedestre')?.checked === true;
}

function _setupModoRegistro() {
    const check = document.getElementById('checkPedestre');
    if (!check) return;

    check.addEventListener('change', () => {
        if (check.checked) {
            _limparDadosVeiculoParaPedestre();
        } else {
            const vestimenta = document.getElementById('vestimentaRegistro');
            if (vestimenta) vestimenta.value = '';
        }
        _atualizarModoRegistro();
    });

    _atualizarModoRegistro();
}

function _atualizarModoRegistro() {
    const pedestre = _isPedestre();
    const campoPlaca = document.getElementById('campoPlacaRegistro');
    const campoModelo = document.getElementById('campoModeloRegistro');
    const campoCor = document.getElementById('campoCorRegistro');
    const campoVestimenta = document.getElementById('campoVestimentaRegistro');
    const placaInput = document.getElementById('placaRegistro');
    const ocupantesToggle = document.getElementById('ocupantesToggleRow');
    const ocupantesWrap = document.getElementById('ocupantesWrap');

    if (campoPlaca) campoPlaca.style.display = pedestre ? 'none' : '';
    if (campoModelo) campoModelo.style.display = pedestre ? 'none' : '';
    if (campoCor) campoCor.style.display = pedestre ? 'none' : '';
    if (campoVestimenta) campoVestimenta.style.display = pedestre ? '' : 'none';
    if (placaInput) placaInput.required = !pedestre;
    if (ocupantesToggle) ocupantesToggle.style.display = pedestre ? 'none' : '';
    if (ocupantesWrap) {
        ocupantesWrap.style.display = pedestre
            ? 'none'
            : (document.getElementById('checkOcupantes')?.checked ? 'block' : 'none');
    }
}

function _limparDadosVeiculoParaPedestre() {
    const placaInput = document.getElementById('placaRegistro');
    const modelo = document.getElementById('modeloRegistro');
    const cor = document.getElementById('corRegistro');
    const veiculoId = document.getElementById('veiculoId');
    if (modelo) modelo.value = '';
    if (cor) cor.value = '';
    if (veiculoId) veiculoId.value = '';
    _definirCamposVeiculoBloqueados(false);
    const box = document.getElementById('veiculoEncontrado');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }

    if (placaInput) {
        placaInput.value = '';
        delete placaInput.dataset.veiculoPreenchido;
    }
    _limparOcupantes();
}

// ── Carregar Unidades (ambos os selects) ──────────────────────────────────────
async function _carregarUnidades() {
    const lifecycleToken = registroLifecycleToken;
    try {
        const resp = await fetch(API_UNIDADES + '?acao=select', { credentials: 'include' });
        const data = await resp.json();
        if (!registroEstaAtivo(lifecycleToken)) return;
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
        if (!registroEstaAtivo(lifecycleToken)) return;
        console.warn('[Registro] Não foi possível carregar unidades:', e);
    }
}

// ── Cascata Unidade → Morador (bloco Morador) ─────────────────────────────────
function _setupCascataMorador() {
    const selUnidade = document.getElementById('unidadeMoradorRegistro');
    if (!selUnidade) return;

    selUnidade.addEventListener('change', async () => {
        const lifecycleToken = registroLifecycleToken;
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
            const resp = await fetch(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`, { credentials: 'include' });
            const data = await resp.json();
            if (!registroEstaAtivo(lifecycleToken) || selUnidade.value !== unidade) return;
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
            if (!registroEstaAtivo(lifecycleToken) || selUnidade.value !== unidade) return;
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
                setCampoValue('moradorId', '');
                return;
            }

            setCampoValue('moradorId', moradorId);

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
    const lifecycleToken = registroLifecycleToken;
    const sel = document.getElementById('dependenteSelecionadoRegistro');
    if (!sel) return;

    sel.innerHTML = '<option value="">Carregando...</option>';
    sel.disabled = true;

    try {
        const resp = await fetch(`${API_DEPENDENTES}?acao=listar&morador_id=${encodeURIComponent(moradorId)}&por_pagina=0`, { credentials: 'include' });
        const data = await resp.json();
        if (!registroEstaAtivo(lifecycleToken) || document.getElementById('moradorSelecionadoRegistro')?.value !== String(moradorId)) return;
        // api_dependentes.php retorna: { sucesso, dados: { dados: [...], ... } }.
        // Manter os formatos anteriores para compatibilidade com respostas legadas.
        const deps = Array.isArray(data.dados)
            ? data.dados
            : (data.dados?.dados || data.dados?.itens || []);

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
        if (!registroEstaAtivo(lifecycleToken) || document.getElementById('moradorSelecionadoRegistro')?.value !== String(moradorId)) return;
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
        const lifecycleToken = registroLifecycleToken;
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
                if (!registroEstaAtivo(lifecycleToken) || selUnidade.value !== unidade) return;
                const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
                selMorador.innerHTML = '<option value="">Selecione o morador...</option>';
                moradores.forEach(m => selMorador.add(new Option(m.nome_completo || m.nome, m.id)));
                selMorador.disabled = moradores.length === 0;
                if (moradores.length === 0) selMorador.innerHTML = '<option value="">Nenhum morador nesta unidade</option>';
            })
            .catch(() => {
                if (!registroEstaAtivo(lifecycleToken) || selUnidade.value !== unidade) return;
                selMorador.innerHTML = '<option value="">Erro ao carregar</option>';
                selMorador.disabled = true;
            });
    });
}

// ── Máscara Documento ─────────────────────────────────────────────────────────
function _aplicarMascaraDocumentoValor(tipo, valor) {
    let v = tipo === 'CPF'
        ? String(valor || '').replace(/\D/g, '')
        : String(valor || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 20);
    if (tipo === 'CPF') {
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
    return v;
}

function _setupMascaraDocumento() {
    const tipoDoc  = document.getElementById('tipoDocRegistro');
    const docInput = document.getElementById('documentoRegistro');
    if (!tipoDoc || !docInput) return;
    const ajustarTeclado = () => { docInput.inputMode = tipoDoc.value === 'CPF' ? 'numeric' : 'text'; };

    tipoDoc.addEventListener('change', () => {
        docInput.value = '';
        docInput.placeholder = tipoDoc.value === 'CPF' ? '000.000.000-00' : 'XX.XXX.XXX-X';
        ajustarTeclado();
        _invalidarPessoaSelecionadaRegistro();
    });
    ajustarTeclado();

    docInput.addEventListener('input', () => {
        docInput.value = _aplicarMascaraDocumentoValor(tipoDoc.value, docInput.value);
        _invalidarPessoaSelecionadaRegistro();
    });
    docInput.addEventListener('change', _invalidarPessoaSelecionadaRegistro);

    docInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); _buscarVisitantePorDocumento(); }
    });

    const btnBuscar = document.getElementById('btnBuscarDocRegistro');
    if (btnBuscar) btnBuscar.addEventListener('click', _buscarVisitantePorDocumento);
}

function _invalidarPessoaSelecionadaRegistro() {
    documentoBuscaToken += 1;
    convidadoSelecaoToken += 1;
    convidadoAtivo = null;
    setCampoValue('visitanteIdRegistro', '');
    setCampoValue('convidadoIdRegistro', '');
    setCampoValue('nomeVisitanteRegistro', '');
    registroVisitanteDocumentoAnexado = null;
    registroVisitanteDocumentoPendente = '';
    registroVisitanteMensagemDetalhada = '';
    if (registroVisitanteCompactTimer) clearTimeout(registroVisitanteCompactTimer);
    registroVisitanteCompactTimer = null;
    const nomeInput = document.getElementById('nomeVisitanteRegistro');
    if (nomeInput) nomeInput.readOnly = false;
    ['visitanteEncontrado', 'convidadoPrevistoRegistro'].forEach((id) => {
        const box = document.getElementById(id);
        if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    });
    document.getElementById('cadastroVisitanteNaChegada')?.remove();
    _atualizarEstadoBotoesEtapa();
}

// ── Ocupantes do Veículo (Visitante/Prestador) ────────────────────────────────
function _setupOcupantes() {
    const check = document.getElementById('checkOcupantes');
    const wrap  = document.getElementById('ocupantesWrap');
    if (check) {
        check.addEventListener('change', () => {
            if (_isPedestre()) {
                check.checked = false;
                _limparOcupantes();
                _atualizarModoRegistro();
                return;
            }
            if (wrap) wrap.style.display = check.checked ? 'block' : 'none';
            if (!check.checked) _limparOcupantes();
        });
    }

    const tipoDoc  = document.getElementById('tipoDocOcupante');
    const docInput = document.getElementById('documentoOcupante');
    if (tipoDoc && docInput) {
        tipoDoc.addEventListener('change', () => {
            docInput.value = '';
            docInput.placeholder = tipoDoc.value === 'CPF' ? '000.000.000-00' : 'XX.XXX.XXX-X';
            _resetBuscaOcupante();
        });
        docInput.addEventListener('input', () => {
            docInput.value = _aplicarMascaraDocumentoValor(tipoDoc.value, docInput.value);
            _resetBuscaOcupante();
        });
        docInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); _buscarOcupantePorDocumento(); }
        });
    }

    const btnBuscar = document.getElementById('btnBuscarDocOcupante');
    if (btnBuscar) btnBuscar.addEventListener('click', _buscarOcupantePorDocumento);

    const btnAdd = document.getElementById('btnAdicionarOcupante');
    if (btnAdd) btnAdd.addEventListener('click', _adicionarOcupante);
}

function _resetBuscaOcupante() {
    const nomeEl = document.getElementById('nomeOcupante');
    if (nomeEl) nomeEl.value = '';
    const idEl = document.getElementById('ocupanteVisitanteIdTemp');
    if (idEl) idEl.value = '';
    const btnAdd = document.getElementById('btnAdicionarOcupante');
    if (btnAdd) btnAdd.disabled = true;
    const box = document.getElementById('ocupanteEncontrado');
    if (box) { box.style.display = 'none'; box.classList.remove('visitante-documento-pendente'); box.innerHTML = ''; }
}

async function _buscarOcupantePorDocumento() {
    const docInput = document.getElementById('documentoOcupante');
    const doc = docInput?.value.trim() || '';
    const tipoDocumento = document.getElementById('tipoDocOcupante')?.value || 'CPF';
    if (!doc) { mostrarAlerta('error', 'Informe o documento do ocupante para buscar.'); return; }
    if (tipoDocumento === 'CPF' && !_cpfValidoRegistro(doc)) { mostrarAlerta('error', 'CPF do ocupante inválido.'); return; }
    const lifecycleToken = registroLifecycleToken;
    const searchToken = ++documentoBuscaToken;
    const isCurrent = () => registroEstaAtivo(lifecycleToken) && searchToken === documentoBuscaToken
        && document.getElementById('tipoDocOcupante')?.value === tipoDocumento
        && _normalizarDocumentoComparacao(document.getElementById('documentoOcupante')?.value || '') === _normalizarDocumentoComparacao(doc);

    const btnBuscar = document.getElementById('btnBuscarDocOcupante');
    if (btnBuscar) { btnBuscar.disabled = true; btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    try {
        const parametros = new URLSearchParams({ documento: doc, tipo_documento: tipoDocumento });
        const resp = await fetch(`${API_VISITANTES}?${parametros.toString()}`, { credentials: 'include' });
        const data = await resp.json();
        if (!isCurrent()) return;
        const box    = document.getElementById('ocupanteEncontrado');
        const btnAdd = document.getElementById('btnAdicionarOcupante');

        if (data.sucesso && data.dados
            && String(data.dados.tipo_documento || '').toUpperCase() === tipoDocumento
            && _normalizarDocumentoComparacao(data.dados.documento) === _normalizarDocumentoComparacao(doc)) {
            const v = data.dados;
            const idTitular      = document.getElementById('visitanteIdRegistro')?.value || '';
            const docTitular      = _normalizarDocumentoComparacao(document.getElementById('documentoRegistro')?.value || '');
            const docEncontrado   = _normalizarDocumentoComparacao(v.documento || '');
            const ehTitular       = (idTitular && String(v.id) === String(idTitular)) || (docTitular && docTitular === docEncontrado);
            const jaAdicionado    = ocupantesAdicionados.some(o => String(o.visitante_id) === String(v.id) || _normalizarDocumentoComparacao(o.documento) === docEncontrado);
            const documentoAnexado = Boolean(v.documento_arquivo);
            // A exigência de documento digitalizado é configurável por condomínio
            // (Configurações > Sistema > Visitantes) e é confirmada pelo servidor
            // ao salvar — aqui só bloqueamos conflitos de identidade (titular
            // repetido / ocupante duplicado), nunca a falta do anexo.
            const podeAdicionar = !ehTitular && !jaAdicionado;

            setCampoValue('nomeOcupante', v.nome_completo || '');
            setCampoValue('ocupanteVisitanteIdTemp', podeAdicionar ? (v.id || '') : '');

            if (box) {
                box.style.display = 'flex';
                box.classList.toggle('visitante-documento-pendente', !podeAdicionar || !documentoAnexado);
                if (ehTitular) {
                    box.innerHTML = `<i class="fas fa-exclamation-triangle"></i>
                        <span>Esta pessoa já é a <strong>titular</strong> deste acesso — selecione outro documento.</span>`;
                } else if (jaAdicionado) {
                    box.innerHTML = `<i class="fas fa-exclamation-triangle"></i>
                        <span><strong>${_esc(v.nome_completo)}</strong> já foi adicionado à lista de ocupantes.</span>`;
                } else if (!documentoAnexado) {
                    box.innerHTML = `<i class="fas fa-info-circle"></i>
                        <span>Cadastro encontrado: <strong>${_esc(v.nome_completo)}</strong>
                        — sem documento digitalizado anexado. Se este condomínio exigir o anexo,
                        o servidor recusará ao salvar; caso contrário, pode adicionar normalmente.</span>`;
                } else {
                    box.innerHTML = `<i class="fas fa-check-circle"></i>
                        <span>Cadastro encontrado: <strong>${_esc(v.nome_completo)}</strong>
                        — ${_esc(v.tipo_documento)}: ${_esc(v.documento)}
                        <strong>— pronto para adicionar.</strong></span>`;
                }
            }
            if (btnAdd) btnAdd.disabled = !podeAdicionar;
        } else {
            setCampoValue('nomeOcupante', '');
            setCampoValue('ocupanteVisitanteIdTemp', '');
            if (btnAdd) btnAdd.disabled = true;
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            mostrarAlerta('error', 'Ocupante não encontrado no cadastro de Visitantes. Cadastre-o no módulo Visitantes antes de adicioná-lo aqui.');
        }
    } catch (error) {
        if (!isCurrent()) return;
        console.error('[Registro] Erro ao buscar ocupante:', error);
        mostrarAlerta('error', 'Erro ao buscar cadastro do ocupante.');
    } finally {
        if (isCurrent() && btnBuscar?.isConnected) { btnBuscar.disabled = false; btnBuscar.innerHTML = '<i class="fas fa-search"></i>'; }
    }
}

function _normalizarDocumentoComparacao(documento) {
    return String(documento || '').replace(/[^A-Za-z0-9]/g, '');
}

function _adicionarOcupante() {
    const visitanteId = document.getElementById('ocupanteVisitanteIdTemp')?.value || '';
    const nome         = document.getElementById('nomeOcupante')?.value.trim() || '';
    const documento    = document.getElementById('documentoOcupante')?.value.trim() || '';
    const tipoDoc      = document.getElementById('tipoDocOcupante')?.value || 'CPF';

    if (!visitanteId || !nome) {
        mostrarAlerta('error', 'Busque um cadastro válido antes de adicionar o ocupante.');
        return;
    }

    // Última barreira antes de gravar na lista: nenhum documento duplicado
    // (nem repetindo o titular, nem repetindo outro ocupante já adicionado).
    const docNormalizado = _normalizarDocumentoComparacao(documento);
    const docTitular = _normalizarDocumentoComparacao(document.getElementById('documentoRegistro')?.value || '');
    if (docNormalizado && docNormalizado === docTitular) {
        mostrarAlerta('error', 'Este documento já é o titular deste acesso — não é permitido lançá-lo também como ocupante.');
        return;
    }
    if (ocupantesAdicionados.some(o => _normalizarDocumentoComparacao(o.documento) === docNormalizado)) {
        mostrarAlerta('error', 'Este documento já foi adicionado à lista de ocupantes.');
        return;
    }

    ocupantesAdicionados.push({ visitante_id: visitanteId, nome, documento, tipo_documento: tipoDoc });
    _renderizarListaOcupantes();

    // Limpa a busca para permitir adicionar o próximo ocupante em seguida.
    const docInput = document.getElementById('documentoOcupante');
    if (docInput) docInput.value = '';
    _resetBuscaOcupante();
    docInput?.focus();
}

function removerOcupante(visitanteId) {
    ocupantesAdicionados = ocupantesAdicionados.filter(o => String(o.visitante_id) !== String(visitanteId));
    _renderizarListaOcupantes();
}

function _renderizarListaOcupantes() {
    const lista = document.getElementById('listaOcupantes');
    if (!lista) return;
    lista.innerHTML = ocupantesAdicionados.map(o => `
        <span class="ocupante-chip">
            <i class="fas fa-user"></i> ${_esc(o.nome)} <small>(${_esc(o.tipo_documento)}: ${_esc(o.documento)})</small>
            <button type="button" class="btn-remover-ocupante" title="Remover ocupante" onclick="window.RegistroPage.removerOcupante('${_esc(o.visitante_id)}')">
                <i class="fas fa-times"></i>
            </button>
        </span>`).join('');
}

function _limparOcupantes() {
    ocupantesAdicionados = [];
    _renderizarListaOcupantes();
    _resetBuscaOcupante();
    const docInput = document.getElementById('documentoOcupante');
    if (docInput) docInput.value = '';
    const check = document.getElementById('checkOcupantes');
    if (check) check.checked = false;
    const wrap = document.getElementById('ocupantesWrap');
    if (wrap) wrap.style.display = 'none';
}

// ── Buscar Visitante por Documento ────────────────────────────────────────────
function _dataHojeRegistroSaoPaulo() {
    const partes = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date());
    const valores = Object.fromEntries(partes.filter(parte => parte.type !== 'literal').map(parte => [parte.type, parte.value]));
    return `${valores.year}-${valores.month}-${valores.day}`;
}

function _obterBoxConvidadoPrevisto() {
    let box = document.getElementById('convidadoPrevistoRegistro');
    if (box) return box;
    const referencia = document.getElementById('visitanteEncontrado');
    if (!referencia?.parentNode) return null;
    box = document.createElement('div');
    box.id = 'convidadoPrevistoRegistro';
    box.className = 'convidado-previsto-registro';
    box.style.display = 'none';
    referencia.insertAdjacentElement('afterend', box);
    return box;
}

async function _buscarConvidadosPrevistos(doc, tipoDocumento, isCurrent = () => true) {
    const box = _obterBoxConvidadoPrevisto();
    if (!box) return false;
    box.style.display = 'none';
    box.innerHTML = '';
    const parametros = new URLSearchParams({
        acao: 'buscar_documento', tipo_documento: tipoDocumento,
        documento: doc, data: _dataHojeRegistroSaoPaulo()
    });
    const response = await fetch(`${API_CONVIDADOS}?${parametros.toString()}`, { credentials: 'include' });
    const data = await response.json();
    if (!isCurrent()) return false;
    if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Não foi possível buscar convites para este documento.');
    const convites = Array.isArray(data.dados?.convidados) ? data.dados.convidados : [];
    if (!convites.length) return false;
    box.innerHTML = `<strong><i class="fas fa-calendar-check"></i> Convidado previsto para hoje</strong>
        ${convites.map(convite => {
            const entradas = convite.limite_entradas === null
                ? `${Number(convite.entradas_utilizadas || 0)} usadas · Indeterminado`
                : `${Number(convite.entradas_utilizadas || 0)}/${Number(convite.limite_entradas || 0)} entradas`;
            const anfitriao = convite.anfitriao?.nome || convite.morador_nome || 'Anfitrião';
            return `<div class="convidado-previsto-item">
                <span><b>${_esc(convite.nome || 'Convidado')}</b> — ${_esc(convite.unidade || '')}, convidado de ${_esc(anfitriao)} · ${_esc(entradas)}</span>
                <button type="button" class="btn-vincular-convidado" data-convidado-id="${Number(convite.id)}">Vincular</button>
            </div>`;
        }).join('')}`;
    box.style.display = 'block';
    box.querySelectorAll('[data-convidado-id]').forEach(button => {
        button.addEventListener('click', async () => {
            const id = Number(button.dataset.convidadoId);
            if (!Number.isInteger(id) || id <= 0) return;
            if (!isCurrent()) return;
            const selectionToken = ++convidadoSelecaoToken;
            box.querySelectorAll('[data-convidado-id]').forEach((item) => { item.disabled = true; });
            try {
                const detalheParams = new URLSearchParams({ acao: 'obter', id: String(id), data: _dataHojeRegistroSaoPaulo() });
                const detalheResponse = await fetch(`${API_CONVIDADOS}?${detalheParams.toString()}`, { credentials: 'include' });
                const detalhe = await detalheResponse.json();
                if (!isCurrent() || selectionToken !== convidadoSelecaoToken) return;
                if (!detalheResponse.ok || !detalhe.sucesso) throw new Error(detalhe.mensagem || 'Não foi possível obter os dados do convite.');
                await iniciarConvidado(detalhe.dados, 'Entrada');
                box.style.display = 'none';
                box.innerHTML = '';
            } catch (error) {
                if (!isCurrent() || selectionToken !== convidadoSelecaoToken) return;
                console.error('[Registro][Convidados] Falha ao vincular pelo documento:', error);
                mostrarAlerta('error', error?.message || 'Não foi possível vincular o convidado.');
            } finally {
                if (isCurrent() && selectionToken === convidadoSelecaoToken) {
                    box.querySelectorAll('[data-convidado-id]').forEach((item) => { item.disabled = false; });
                }
            }
        });
    });
    return true;
}

async function _buscarVisitantePorDocumento() {
    const docInput = document.getElementById('documentoRegistro');
    const doc = docInput?.value.trim() || '';
    if (!doc) { mostrarAlerta('error', 'Informe o documento para buscar.'); return; }
    const tipoDocumento = document.getElementById('tipoDocRegistro')?.value || 'CPF';
    if (tipoDocumento === 'CPF' && !_cpfValidoRegistro(doc)) {
        mostrarAlerta('error', 'CPF inválido. Confira os dígitos informados.');
        return;
    }

    const searchToken = ++documentoBuscaToken;
    const isCurrent = () => searchToken === documentoBuscaToken
        && document.getElementById('documentoRegistro')?.isConnected
        && document.getElementById('tipoDocRegistro')?.value === tipoDocumento
        && _normalizarDocumentoComparacao(document.getElementById('documentoRegistro')?.value || '') === _normalizarDocumentoComparacao(doc);

    const btnBuscar = document.getElementById('btnBuscarDocRegistro');
    if (btnBuscar) { btnBuscar.disabled = true; btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    try {
        const politica = await _carregarPoliticaVisitantes();
        if (!isCurrent()) return;
        const parametrosVisitante = new URLSearchParams({ documento: doc, tipo_documento: tipoDocumento });
        const resp = await fetch(`${API_VISITANTES}?${parametrosVisitante.toString()}`, { credentials: 'include' });
        const data = await resp.json();
        if (!isCurrent()) return;
        const box  = document.getElementById('visitanteEncontrado');

        if (data.sucesso && data.dados
            && String(data.dados.tipo_documento || '').toUpperCase() === tipoDocumento
            && _normalizarDocumentoComparacao(data.dados.documento) === _normalizarDocumentoComparacao(doc)) {
            const v = data.dados;
            setCampoValue('nomeVisitanteRegistro', v.nome_completo || '');
            setCampoValue('visitanteIdRegistro', v.id || '');
            registroVisitanteDocumentoAnexado = Boolean(v.documento_arquivo);
            registroVisitanteDocumentoPendente = Boolean(politica?.documento_digitalizado?.obrigatorio && !v.documento_arquivo)
                ? 'Este condomínio exige documento digitalizado para liberar o acesso.' : '';
            registroVisitanteMensagemDetalhada = `Cadastro encontrado: ${v.nome_completo || 'Visitante'} — ${v.tipo_documento}: ${v.documento}${v.telefone_contato ? ` — Tel: ${v.telefone_contato}` : ''}${v.documento_arquivo ? ' — Documento digitalizado confirmado.' : ' — Sem documento digitalizado anexado.'}`;
            const nomeInput = document.getElementById('nomeVisitanteRegistro');
            if (nomeInput) nomeInput.readOnly = true;
            const placaInput = document.getElementById('placaRegistro');
            if (placaInput && !placaInput.value && v.placa_veiculo) placaInput.value = v.placa_veiculo;
            if (box) {
                const documentoAnexado = Boolean(v.documento_arquivo);
                box.style.display = 'flex';
                box.classList.toggle('visitante-documento-pendente', !documentoAnexado);
                box.innerHTML = documentoAnexado
                    ? `<i class="fas fa-check-circle"></i>
                        <span>Cadastro encontrado: <strong>${_esc(v.nome_completo)}</strong>
                        — ${_esc(v.tipo_documento)}: ${_esc(v.documento)}
                        ${v.telefone_contato ? '— Tel: ' + _esc(v.telefone_contato) : ''}
                        <strong>— Documento digitalizado confirmado.</strong></span>`
                    : `<i class="fas fa-info-circle"></i>
                        <span>Cadastro encontrado: <strong>${_esc(v.nome_completo)}</strong>
                        — sem documento digitalizado anexado. Se este condomínio exigir o anexo,
                        o servidor recusará ao salvar; caso contrário, o acesso pode ser registrado normalmente.</span>`;
                box.dataset.detalhe = registroVisitanteMensagemDetalhada;
                _compactarVisitanteEncontrado(6000);
            }
            _atualizarEstadoBotoesEtapa();
            if (registroVisitanteDocumentoPendente) _mostrarErroEtapaRegistro(registroVisitanteDocumentoPendente);
        } else {
            setCampoValue('nomeVisitanteRegistro', '');
            setCampoValue('visitanteIdRegistro', '');
            registroVisitanteDocumentoAnexado = null;
            registroVisitanteDocumentoPendente = '';
            registroVisitanteMensagemDetalhada = '';
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            const temConvite = await _buscarConvidadosPrevistos(doc, tipoDocumento, isCurrent);
            if (!isCurrent()) return;
            if (!temConvite) mostrarAlerta('error', 'Visitante não encontrado. Preencha o nome manualmente ou cadastre-o no módulo Visitantes.');
            _atualizarEstadoBotoesEtapa();
            return;
        }
        await _buscarConvidadosPrevistos(doc, tipoDocumento, isCurrent);
    } catch (error) {
        if (!isCurrent()) return;
        console.error('[Registro] Erro ao buscar visitante:', error);
        mostrarAlerta('error', error?.message || 'Erro ao buscar cadastro do visitante.');
    } finally {
        if (isCurrent() && btnBuscar?.isConnected) { btnBuscar.disabled = false; btnBuscar.innerHTML = '<i class="fas fa-search"></i>'; }
    }
}

function _cpfValidoRegistro(valor) {
    const cpf = String(valor || '').replace(/\D/g, '');
    if (cpf.length !== 11 || /^([0-9])\1{10}$/.test(cpf)) return false;
    for (let tamanho = 9; tamanho <= 10; tamanho++) {
        let soma = 0;
        for (let i = 0; i < tamanho; i++) soma += Number(cpf[i]) * (tamanho + 1 - i);
        const digito = (soma * 10) % 11 % 10;
        if (Number(cpf[tamanho]) !== digito) return false;
    }
    return true;
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

function _registrarListenerStepper(elemento, evento, handler) {
    if (!elemento) return;
    elemento.addEventListener(evento, handler);
    registroStepperListeners.push({ elemento, evento, handler });
}

function _setupStepperRegistro() {
    const form = document.getElementById('registroForm');
    if (!form) return;
    registroStepperAtual = 1;
    registroEtapasConcluidas = new Set();
    registroAlertasEtapaVistos = new Set();
    registroInformativosEtapa = new Map();
    _registrarListenerStepper(document.getElementById('btnEtapa1Avancar'), 'click', () => { void _avancarEtapaRegistro(1); });
    _registrarListenerStepper(document.getElementById('btnEtapa2Avancar'), 'click', () => { void _avancarEtapaRegistro(2); });
    _registrarListenerStepper(document.getElementById('btnEtapa3Avancar'), 'click', () => { void _avancarEtapaRegistro(3); });
    _registrarListenerStepper(document.getElementById('btnEtapa4Voltar'), 'click', () => {
        const tipo = document.getElementById('tipoRegistro')?.value || '';
        _abrirEtapaRegistro(_tipoExigeDocumento(tipo) ? 3 : 2);
    });
    _registrarListenerStepper(form, 'click', (evento) => {
        const editar = evento.target.closest('[data-step-edit]');
        const voltar = evento.target.closest('[data-step-back]');
        if (editar) _editarEtapaRegistro(Number(editar.dataset.stepEdit));
        else if (voltar) _abrirEtapaRegistro(Number(voltar.dataset.stepBack));
    });
    const atualizarValidacoes = (evento) => {
        if (evento?.target?.matches('#placaRegistro, #tipoRegistro, #unidadeDestinoRegistro, #moradorDestinoRegistro, #unidadeMoradorRegistro, #moradorSelecionadoRegistro')) {
            registroConfirmacaoDestinoPlacaKey = '';
            registroDestinoPlacaRecusadoKey = '';
        }
        if (evento?.target && evento.target.matches('input, select, textarea')
            && (registroUltimoErro || document.getElementById('alertBox')?.querySelector('.alert-error'))) _limparErroRegistro();
        _atualizarEstadoBotoesEtapa();
    };
    _registrarListenerStepper(form, 'input', atualizarValidacoes);
    _registrarListenerStepper(form, 'change', atualizarValidacoes);
    _registrarListenerStepper(form, 'keydown', (evento) => {
        if (evento.key !== 'Enter' || evento.shiftKey || evento.isComposing || registroEtapaEmProcessamento) return;
        const alvo = evento.target;
        if (alvo?.tagName === 'TEXTAREA' || alvo?.tagName === 'BUTTON' || alvo?.type === 'submit') return;
        if (registroStepperAtual < 4) {
            evento.preventDefault();
            void _avancarEtapaRegistro(registroStepperAtual);
        }
    });
    _registrarListenerStepper(document.getElementById('registroAlertasChip'), 'click', () => {
        const alertas = [...registroInformativosEtapa.values()].map((item) => item.alerta);
        if (alertas.length) _abrirModalAlertaEtapa({ alertas }, { somenteLeitura: true });
    });
    _registrarListenerStepper(document.getElementById('btnRegistroAlertaEtapaConfirmar'), 'click', _confirmarAlertaEtapa);
    _registrarListenerStepper(document.getElementById('btnRegistroAlertaEtapaCancelar'), 'click', () => _cancelarAlertaEtapa(true));
    _registrarListenerStepper(document.getElementById('registroAlertaEtapaModal'), 'keydown', (evento) => {
        if (evento.key === 'Escape') {
            evento.preventDefault();
            _cancelarAlertaEtapa(false);
            return;
        }
        if (evento.key !== 'Tab') return;
        const modal = document.getElementById('registroAlertaEtapaModal');
        const focaveis = [...(modal?.querySelectorAll('button:not([disabled]):not([hidden]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])') || [])]
            .filter((elemento) => elemento.getClientRects().length > 0 && getComputedStyle(elemento).visibility !== 'hidden');
        if (!focaveis.length) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (evento.shiftKey && document.activeElement === primeiro) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    });
    _registrarListenerStepper(document.getElementById('btnRegistroComoFunciona'), 'click', () => _alternarInfoRegistro(true));
    _registrarListenerStepper(document.getElementById('btnFecharRegistroInfo'), 'click', () => _recolherInfoRegistro());
    const info = document.getElementById('registroInfoBox');
    ['mouseenter', 'focusin'].forEach((evento) => _registrarListenerStepper(info, evento, _pausarTimerInfoRegistro));
    ['mouseleave', 'focusout'].forEach((evento) => _registrarListenerStepper(info, evento, _retomarTimerInfoRegistro));
    _renderStepperRegistro();
    _atualizarEstadoBotoesEtapa();
}

function _validarEtapa1Registro() {
    return Boolean(document.getElementById('dataHoraRegistro')?.value
        && document.getElementById('tipoRegistro')?.value
        && document.querySelector('input[name="tipo_acesso"]:checked')?.value);
}

function _validarEtapa2Registro() {
    const tipo = document.getElementById('tipoRegistro')?.value || '';
    if (!_validarEtapa1Registro() || !tipo) return false;
    if (tipo === 'Morador') {
        return Boolean(document.getElementById('unidadeMoradorRegistro')?.value
            && document.getElementById('moradorSelecionadoRegistro')?.value
            && (!document.getElementById('checkDependente')?.checked || document.getElementById('dependenteSelecionadoRegistro')?.value));
    }
    return Boolean(document.getElementById('unidadeDestinoRegistro')?.value && document.getElementById('moradorDestinoRegistro')?.value);
}

function _validarEtapa3BasicaRegistro() {
    const tipo = document.getElementById('tipoRegistro')?.value || '';
    if (!_tipoExigeDocumento(tipo)) return tipo === 'Morador';
    const documento = document.getElementById('documentoRegistro')?.value.trim() || '';
    const tipoDocumento = document.getElementById('tipoDocRegistro')?.value || 'CPF';
    const nome = document.getElementById('nomeVisitanteRegistro')?.value.trim() || '';
    return Boolean(document.getElementById('visitanteIdRegistro')?.value && documento && nome
        && !registroVisitanteDocumentoPendente
        && (tipoDocumento !== 'CPF' || _cpfValidoRegistro(documento)));
}

function _validarEtapa4BasicaRegistro() {
    if (!_validarEtapa2Registro()) return false;
    const tipo = document.getElementById('tipoRegistro')?.value || '';
    if (_tipoExigeDocumento(tipo) && (!_validarEtapa3BasicaRegistro() || registroVisitanteDocumentoPendente)) return false;
    if (_isPedestre()) return true;
    const placa = normalizarPlaca(document.getElementById('placaRegistro')?.value || '');
    const chaveDestino = _chaveDestinoPlacaAtual(placa);
    return /^[A-Z]{3}(?:[0-9]{4}|[0-9][A-Z][0-9]{2})$/.test(placa)
        && document.getElementById('placaRegistro')?.dataset.consultando !== '1'
        && registroDestinoPlacaRecusadoKey !== chaveDestino;
}

function _chaveDestinoPlacaAtual(placa = normalizarPlaca(document.getElementById('placaRegistro')?.value || '')) {
    const tipo = document.getElementById('tipoRegistro')?.value || '';
    const eVisitante = _tipoExigeDocumento(tipo);
    const morador = String(document.getElementById(eVisitante ? 'moradorDestinoRegistro' : 'moradorSelecionadoRegistro')?.value
        || (!eVisitante ? document.getElementById('moradorId')?.value : '') || '');
    const unidade = String(document.getElementById(eVisitante ? 'unidadeDestinoRegistro' : 'unidadeMoradorRegistro')?.value || '');
    return `${placa}|${tipo}|${morador}|${unidade}`;
}

function _atualizarEstadoBotoesEtapa() {
    const b1 = document.getElementById('btnEtapa1Avancar');
    const b2 = document.getElementById('btnEtapa2Avancar');
    const b3 = document.getElementById('btnEtapa3Avancar');
    const b4 = document.getElementById('btnSalvarRegistro');
    if (b1) b1.disabled = !(_validarEtapa1Registro() && !registroEtapaEmProcessamento);
    if (b2) b2.disabled = !(_validarEtapa2Registro() && !registroEtapaEmProcessamento);
    if (b3) b3.disabled = !(_validarEtapa3BasicaRegistro() && !registroEtapaEmProcessamento);
    if (b4) b4.disabled = !(_validarEtapa4BasicaRegistro() && !registroEtapaEmProcessamento && !salvandoReg);
}

function _resumoEtapaRegistro(etapa) {
    const tipo = document.getElementById('tipoRegistro')?.value || '-';
    if (etapa === 1) {
        const acesso = _getTipoAcesso();
        const data = document.getElementById('dataHoraRegistro')?.value || '';
        return [acesso, tipo, data ? data.replace('T', ' ') : ''].filter(Boolean).join(' · ');
    }
    if (etapa === 2) {
        if (tipo === 'Morador') {
            const unidade = document.getElementById('unidadeMoradorRegistro')?.value || '';
            const morador = document.getElementById('moradorSelecionadoRegistro');
            const pessoa = morador?.selectedOptions?.[0]?.textContent || '';
            const dependente = document.getElementById('checkDependente')?.checked
                ? (document.getElementById('dependenteSelecionadoRegistro')?.selectedOptions?.[0]?.textContent || '')
                : '';
            return [unidade, dependente || pessoa].filter(Boolean).join(' · ');
        }
        const unidade = document.getElementById('unidadeDestinoRegistro')?.value || '';
        const morador = document.getElementById('moradorDestinoRegistro')?.selectedOptions?.[0]?.textContent || '';
        const dias = document.getElementById('diasPermanenciaRegistro')?.value || '';
        return [unidade, morador, dias ? `${dias} dia${Number(dias) === 1 ? '' : 's'}` : ''].filter(Boolean).join(' · ');
    }
    if (etapa === 3) {
        if (!_tipoExigeDocumento(tipo)) return 'Identificação de visitante não se aplica';
        const nome = document.getElementById('nomeVisitanteRegistro')?.value.trim() || '';
        const documento = document.getElementById('documentoRegistro')?.value.trim() || '';
        return [nome, documento ? `Documento ${documento}` : '', document.getElementById('convidadoIdRegistro')?.value ? 'Convidado vinculado' : ''].filter(Boolean).join(' · ');
    }
    return _isPedestre() ? 'Pedestre' : [document.getElementById('placaRegistro')?.value.trim(), document.getElementById('modeloRegistro')?.value.trim()].filter(Boolean).join(' · ');
}

function _renderStepperRegistro() {
    const tipo = document.getElementById('tipoRegistro')?.value || '';
    const etapa3Pulada = !_tipoExigeDocumento(tipo);
    document.querySelectorAll('.registro-step-card[data-step]').forEach((card) => {
        const etapa = Number(card.dataset.step);
        const ativa = etapa === registroStepperAtual;
        const etapaOmitida = etapa === 3 && etapa3Pulada;
        const colapsada = !ativa && !etapaOmitida && registroEtapasConcluidas.has(etapa);
        card.hidden = !ativa && !colapsada;
        card.classList.toggle('is-active', ativa);
        card.classList.toggle('is-collapsed', colapsada);
        card.classList.toggle('is-complete', registroEtapasConcluidas.has(etapa));
        card.classList.toggle('is-skipped', etapa === 3 && etapa3Pulada);
        const conteudo = card.querySelector('[data-step-content]');
        if (conteudo) conteudo.hidden = colapsada;
        const summary = document.getElementById(`registroResumoEtapa${etapa}`);
        if (summary) {
            summary.textContent = registroEtapasConcluidas.has(etapa) ? _resumoEtapaRegistro(etapa) : '';
            summary.hidden = !colapsada;
        }
        const edit = card.querySelector('[data-step-edit]');
        if (edit) edit.hidden = !colapsada;
    });
    document.querySelectorAll('[data-step-indicator]').forEach((indicador) => {
        const etapa = Number(indicador.dataset.stepIndicator);
        indicador.classList.toggle('is-current', etapa === registroStepperAtual);
        indicador.classList.toggle('is-complete', registroEtapasConcluidas.has(etapa));
        indicador.classList.toggle('is-skipped', etapa === 3 && etapa3Pulada);
        if (etapa === 3 && etapa3Pulada) indicador.setAttribute('aria-label', 'Etapa 3 Identificação pulada para Morador');
        else indicador.removeAttribute('aria-label');
    });
    const status = document.getElementById('registroEtapaStatus');
    if (status) status.textContent = `Etapa ${registroStepperAtual} de 4${etapa3Pulada ? ' · Identificação do visitante não se aplica' : ''}`;
    _atualizarEstadoBotoesEtapa();
}

function _abrirEtapaRegistro(etapa, focar = true) {
    const alvo = Number(etapa);
    if (![1, 2, 3, 4].includes(alvo)) return;
    if (alvo === 3 && !_tipoExigeDocumento(document.getElementById('tipoRegistro')?.value || '')) return;
    registroStepperAtual = alvo;
    _renderStepperRegistro();
    if (focar) {
        const card = document.getElementById(`registroEtapa${alvo}`);
        const campo = [...(card?.querySelectorAll('input:not([type="hidden"]):not([disabled]):not([hidden]), select:not([disabled]):not([hidden]), button:not([disabled]):not([hidden])') || [])]
            .find((elemento) => elemento.getClientRects().length > 0 && getComputedStyle(elemento).visibility !== 'hidden');
        requestAnimationFrame(() => campo?.focus({ preventScroll: true }));
        card?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
}

function _editarEtapaRegistro(etapa) {
    registroEtapasConcluidas.forEach((concluida) => { if (concluida >= etapa) registroEtapasConcluidas.delete(concluida); });
    _invalidarCienciaRegistro();
    _abrirEtapaRegistro(etapa);
    if (etapa === 2) _agendarPreflightCienciaRegistro('DESTINO');
}

async function _avancarEtapaRegistro(etapa) {
    if (registroEtapaEmProcessamento || Number(etapa) !== registroStepperAtual) return;
    if (etapa === 1) {
        if (!_validarEtapa1Registro()) { _mostrarErroEtapaRegistro('Preencha data e hora, tipo e Entrada/Saída para avançar.'); return; }
        registroEtapasConcluidas.add(1);
        onTipoChange();
        _abrirEtapaRegistro(2);
        return;
    }
    if (etapa === 2) {
        if (!_validarEtapa2Registro()) { _mostrarErroEtapaRegistro('Selecione a unidade e o morador de destino. Se marcou Dependente, escolha também o dependente.'); return; }
        const tipoAcesso = _getTipoAcesso();
        if (tipoAcesso === 'Entrada') {
            const resultado = await _executarPreflightEtapaRegistro('DESTINO');
            if (!resultado?.continuar) return;
        }
        registroEtapasConcluidas.add(2);
        const proxima = _tipoExigeDocumento(document.getElementById('tipoRegistro')?.value || '') ? 3 : 4;
        if (proxima === 4) registroEtapasConcluidas.add(3);
        _abrirEtapaRegistro(proxima);
        return;
    }
    if (etapa === 3) {
        if (!_validarEtapa3BasicaRegistro()) { _mostrarErroEtapaRegistro('Busque e confirme o visitante pelo documento antes de avançar.'); return; }
        if (!await _validarDocumentoVisitanteRegistro()) return;
        if (_getTipoAcesso() === 'Entrada') {
            const resultado = await _executarPreflightEtapaRegistro('IDENTIFICACAO');
            if (!resultado?.continuar) return;
        }
        registroEtapasConcluidas.add(3);
        _abrirEtapaRegistro(4);
    }
}

function _contextoBaseAlertaRegistro(contexto) {
    return JSON.stringify({ contexto: contexto?.contexto || '', unidade: contexto?.unidade || '', morador_id: contexto?.morador_id || '', dependente_id: contexto?.dependente_id || '', tipo_pessoa: contexto?.tipo_pessoa || '', tipo_acesso: contexto?.tipo_acesso || '', data_hora: contexto?.data_hora || '' });
}

function _chaveAlertaEtapa(alerta, contexto) {
    const alvo = String(alerta?.tipo_alvo_correspondente || '').toUpperCase();
    let identificador = '';
    if (alvo === 'PLACA') identificador = normalizarPlaca(contexto?.placa || '');
    else if (alvo === 'DOCUMENTO') identificador = String(contexto?.documento_normalizado || contexto?.documento || '').replace(/\W/g, '').toUpperCase();
    else if (alvo === 'VISITANTE') identificador = String(contexto?.visitante_id || '');
    const conteudo = JSON.stringify([alerta?.titulo || '', alerta?.mensagem || '', alerta?.nivel || '', alerta?.acao || '', alerta?.instrucao_operador || '', alerta?.telefone_contato || '']);
    return `${Number(alerta?.id || 0)}|${_contextoBaseAlertaRegistro(contexto)}|${alvo}|${identificador}|${conteudo}`;
}

function _alertaAbrePopupEtapa(alerta) {
    return _alertaExigeDecisao(alerta) || ['ATENCAO', 'CRITICO'].includes(String(alerta?.nivel || '').toUpperCase());
}

function _mostrarErroEtapaRegistro(mensagem) {
    const box = document.getElementById('registroEtapaFeedback');
    if (!box) { mostrarAlerta('error', mensagem); return; }
    box.className = 'registro-etapa-feedback is-error';
    box.innerHTML = `<span>${_esc(mensagem)}</span><button type="button" data-registro-feedback-close aria-label="Fechar mensagem"><i class="fas fa-times"></i></button>`;
    box.hidden = false;
    registroUltimoErro = String(mensagem || '');
    box.querySelector('[data-registro-feedback-close]')?.addEventListener('click', _limparErroRegistro);
    box.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
}

function _limparErroRegistro() {
    registroUltimoErro = '';
    if (registroAlertaTimer) clearTimeout(registroAlertaTimer);
    registroAlertaTimer = null;
    const box = document.getElementById('registroEtapaFeedback');
    if (box) { box.hidden = true; box.textContent = ''; }
    const global = document.getElementById('alertBox');
    if (global?.querySelector('.alert-error')) global.innerHTML = '';
}

function _mostrarToastEtapaRegistro(mensagem) {
    const box = document.getElementById('registroEtapaFeedback');
    if (!box) return;
    if (registroEtapaToastTimer) clearTimeout(registroEtapaToastTimer);
    box.className = 'registro-etapa-feedback is-info';
    box.innerHTML = `<span>${_esc(mensagem)}</span><button type="button" data-registro-feedback-close aria-label="Fechar aviso"><i class="fas fa-times"></i></button>`;
    box.hidden = false;
    box.querySelector('[data-registro-feedback-close]')?.addEventListener('click', () => { if (registroEtapaToastTimer) clearTimeout(registroEtapaToastTimer); box.hidden = true; });
    registroEtapaToastTimer = setTimeout(() => { if (box.isConnected) box.hidden = true; }, 7500);
}

async function _executarPreflightEtapaRegistro(etapa) {
    if (_getTipoAcesso() === 'Saída') return { continuar: true, alertas: [] };
    if (etapa === 'DESTINO' && !_validarEtapa2Registro()) return { continuar: false };
    if (etapa === 'IDENTIFICACAO' && !_validarEtapa3BasicaRegistro()) return { continuar: false };
    const contexto = _obterContextoCienciaRegistro(etapa);
    if (!contexto) return { continuar: false };
    const lifecycleToken = registroLifecycleToken;
    const versao = registroCienciaVersion;
    const chaveAntes = _chaveContextoCiencia(contexto);
    registroCienciaController?.abort();
    const controller = new AbortController();
    registroCienciaController = controller;
    registroEtapaEmProcessamento = true;
    _atualizarEstadoBotoesEtapa();
    try {
        const resposta = await _solicitarCienciaRegistro('verificar', { ...contexto, parcial: true, etapa, modo_registro: _isPedestre() ? 'PEDESTRE' : 'VEICULO' }, controller.signal);
        if (!registroEstaAtivo(lifecycleToken) || controller.signal.aborted || versao !== registroCienciaVersion
            || chaveAntes !== _chaveContextoCiencia(_obterContextoCienciaRegistro(etapa))) return { continuar: false };
        if (!resposta.data?.sucesso || resposta.data?.dados?.parcial !== true) throw new Error(resposta.data?.mensagem || 'A pré-validação não foi concluída.');
        const dados = resposta.data.dados || {};
        const alertas = Array.isArray(dados.alertas) ? dados.alertas : [];
        registroCienciaState = { chave: chaveAntes, contexto, resultado: resposta.data, token: '' };
        registroBaseContextoAlertas = _contextoBaseAlertaRegistro(contexto);
        registroAlertasEspecificosPendentes = Number(dados.alertas_especificos_pendentes || 0);
        const novos = [];
        alertas.forEach((alerta) => {
            const chave = _chaveAlertaEtapa(alerta, contexto);
            if (!_alertaAbrePopupEtapa(alerta)) registroInformativosEtapa.set(chave, { alerta, contexto, etapa });
            if (!registroAlertasEtapaVistos.has(chave)) novos.push({ alerta, chave });
        });
        _renderBannerCienciaRegistro(resposta.data);
        if (novos.length) _anunciarAlertasEtapa(novos, contexto, etapa);
        const popupsNovos = novos.filter((item) => _alertaAbrePopupEtapa(item.alerta));
        if (popupsNovos.length) {
            const respostaPopup = { ...resposta.data, dados: { ...dados, alertas: popupsNovos.map((item) => item.alerta) } };
            const decisao = await _abrirModalAlertaEtapa(respostaPopup, { etapa, contexto });
            if (!registroEstaAtivo(lifecycleToken) || !decisao.continuar) return { continuar: false };
            novos.forEach((item) => registroAlertasEtapaVistos.add(item.chave));
            _atualizarChipAlertasEtapa();
            return { continuar: true, overrideSolicitado: Boolean(decisao.overrideSolicitado), alertas };
        }
        novos.forEach((item) => registroAlertasEtapaVistos.add(item.chave));
        if (novos.length) _mostrarToastEtapaRegistro(`${novos.length} alerta${novos.length === 1 ? '' : 's'} informativo${novos.length === 1 ? '' : 's'} da unidade. Toque no resumo fixo para rever.`);
        else if (registroAlertasEspecificosPendentes > 0 && etapa !== 'VEICULO') _mostrarToastEtapaRegistro(`${registroAlertasEspecificosPendentes} alerta${registroAlertasEspecificosPendentes === 1 ? '' : 's'} específico${registroAlertasEspecificosPendentes === 1 ? '' : 's'} será(ão) conferido(s) adiante. Nenhum dado de terceiros foi exibido.`);
        _atualizarChipAlertasEtapa();
        return { continuar: true, alertas };
    } catch (error) {
        if (error?.name === 'AbortError' || controller.signal.aborted) return { continuar: false };
        console.warn('[Registro][Alertas] Falha na pré-validação da etapa:', error);
        _mostrarErroEtapaRegistro(error?.message || 'Não foi possível validar alertas; tente novamente antes de avançar.');
        return { continuar: false };
    } finally {
        if (registroCienciaController === controller) registroCienciaController = null;
        if (registroEstaAtivo(lifecycleToken)) { registroEtapaEmProcessamento = false; _atualizarEstadoBotoesEtapa(); }
    }
}

function _anunciarAlertasEtapa(itens, contexto, etapa) {
    const banner = document.getElementById('registroCienciaBanner');
    if (!banner) return;
    const critico = itens.some(({ alerta }) => String(alerta.nivel || '').toUpperCase() === 'CRITICO' || _acaoAlertaCiencia(alerta) === 'BLOQUEAR');
    banner.setAttribute('role', critico ? 'alert' : 'status');
    banner.setAttribute('aria-live', critico ? 'assertive' : 'polite');
    itens.forEach(({ alerta, chave }) => { if (!_alertaAbrePopupEtapa(alerta)) registroInformativosEtapa.set(chave, { alerta, contexto, etapa }); });
    _atualizarChipAlertasEtapa();
    if (!itens.some(({ alerta }) => !_alertaAbrePopupEtapa(alerta))) return;
    requestAnimationFrame(() => {
        if (!registroEstaAtivo() || !document.getElementById('registroAlertaEtapaModal')?.hidden) return;
        banner.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
        banner.focus({ preventScroll: true });
    });
}

function _atualizarChipAlertasEtapa() {
    const chip = document.getElementById('registroAlertasChip');
    if (!chip) return;
    const informativos = registroInformativosEtapa.size;
    const pendentes = Math.max(0, Number(registroAlertasEspecificosPendentes || 0));
    chip.hidden = informativos === 0 && pendentes === 0;
    chip.innerHTML = `<i class="fas fa-bell"></i> ${informativos ? `${informativos} alerta${informativos === 1 ? '' : 's'} da unidade` : ''}${informativos && pendentes ? ' · ' : ''}${pendentes ? `${pendentes} específico${pendentes === 1 ? '' : 's'} adiante` : ''}`;
    chip.setAttribute('aria-label', `${informativos} alertas informativos e ${pendentes} alertas específicos ainda serão verificados`);
}

function _abrirModalAlertaEtapa(data, opcoes = {}) {
    const modal = document.getElementById('registroAlertaEtapaModal');
    const lista = document.getElementById('registroAlertaEtapaLista');
    const descricao = document.getElementById('registroAlertaEtapaDescricao');
    const confirmar = document.getElementById('btnRegistroAlertaEtapaConfirmar');
    const cancelar = document.getElementById('btnRegistroAlertaEtapaCancelar');
    const dados = _dadosCiencia(data);
    const alertas = Array.isArray(dados.alertas) ? dados.alertas : [];
    if (!modal || !lista || !descricao || !confirmar || !cancelar) return Promise.resolve({ continuar: false });
    const bloqueio = alertas.some((alerta) => _acaoAlertaCiencia(alerta) === 'BLOQUEAR');
    const somenteLeitura = Boolean(opcoes.somenteLeitura);
    const podeOverride = dados.pode_override === true;
    descricao.textContent = somenteLeitura
        ? (alertas.length ? 'Alertas informativos avaliados neste contexto:' : `${registroAlertasEspecificosPendentes} alertas específicos serão conferidos adiante; nenhum dado de terceiros é exibido.`)
        : bloqueio
            ? (podeOverride ? 'Acesso bloqueado. Só será possível continuar pela revisão final de override, que exige moradores.aprovar e reautenticação.' : 'Acesso bloqueado. Este operador não possui permissão de override; o registro não pode continuar.')
            : 'Revise o alerta antes de avançar. Esta confirmação não substitui a ciência formal exigida na gravação.';
    lista.innerHTML = alertas.length ? alertas.map((alerta) => {
        const nivel = String(alerta.nivel || '').toUpperCase();
        const classe = _acaoAlertaCiencia(alerta) === 'BLOQUEAR' ? 'ciencia-alert--bloquear' : _acaoAlertaCiencia(alerta) === 'CONTATAR_MORADOR' ? 'ciencia-alert--contatar' : '';
        return `<article class="ciencia-alert ${classe} ${nivel === 'CRITICO' ? 'ciencia-alert--critico' : nivel === 'ATENCAO' ? 'ciencia-alert--atencao' : ''}">
            <div class="ciencia-alert__meta"><span>Nível: ${_esc(alerta.nivel || 'INFORMATIVO')}</span><span>Ação: ${_esc(alerta.acao || 'APENAS_AVISAR')}</span></div><strong class="ciencia-alert__title">${_esc(alerta.titulo || 'Alerta da unidade')}</strong><p class="ciencia-alert__message">${_esc(alerta.mensagem || '')}</p>
            ${alerta.instrucao_operador ? `<p class="ciencia-alert__instruction">Orientação: ${_esc(alerta.instrucao_operador)}</p>` : ''}
            ${alerta.telefone_contato ? `<p class="ciencia-alert__instruction">Contato: ${_telefoneAlertaHtml(alerta.telefone_contato)}</p>` : ''}</article>`;
    }).join('') : '<p>Nenhum detalhe de alerta foi exibido nesta etapa.</p>';
    registroEtapaModalData = { data, opcoes, somenteLeitura, bloqueio, podeOverride };
    registroEtapaModalPreviousFocus = document.activeElement;
    cancelar.textContent = somenteLeitura ? 'Fechar' : 'Cancelar registro';
    cancelar.hidden = false;
    confirmar.hidden = somenteLeitura || (bloqueio && !podeOverride);
    confirmar.disabled = false;
    confirmar.innerHTML = bloqueio && podeOverride ? 'Ir à revisão de override <i class="fas fa-arrow-right"></i>' : 'Ciente, prosseguir';
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    (confirmar.hidden ? cancelar : confirmar).focus();
    return new Promise((resolve) => { registroEtapaModalResolver = resolve; });
}

function _resolverModalAlertaEtapa(resultado) {
    const resolver = registroEtapaModalResolver;
    registroEtapaModalResolver = null;
    const modal = document.getElementById('registroAlertaEtapaModal');
    if (modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
    const foco = registroEtapaModalPreviousFocus;
    registroEtapaModalPreviousFocus = null;
    registroEtapaModalData = null;
    if (resolver) resolver(resultado || { continuar: false });
    if (foco?.isConnected) foco.focus({ preventScroll: true });
}

function _confirmarAlertaEtapa() {
    const dados = registroEtapaModalData;
    if (!dados || dados.somenteLeitura) return;
    const continuar = !dados.bloqueio || dados.podeOverride;
    _resolverModalAlertaEtapa({ continuar, overrideSolicitado: dados.bloqueio && dados.podeOverride });
}

function _cancelarAlertaEtapa(limpar) {
    if (!registroEtapaModalData) return;
    const somenteLeitura = registroEtapaModalData.somenteLeitura;
    _resolverModalAlertaEtapa({ continuar: false, somenteLeitura });
    if (limpar && !somenteLeitura) limparFormulario();
}

async function _validarDocumentoVisitanteRegistro() {
    const visitanteId = Number(document.getElementById('visitanteIdRegistro')?.value || 0);
    const documentoDigitado = document.getElementById('documentoRegistro')?.value.trim() || '';
    const tipoDocumento = document.getElementById('tipoDocRegistro')?.value || 'CPF';
    if (visitanteId <= 0 || !documentoDigitado) { _mostrarErroEtapaRegistro('Busque e selecione um visitante cadastrado antes de avançar.'); return false; }
    if (tipoDocumento === 'CPF' && !_cpfValidoRegistro(documentoDigitado)) { _mostrarErroEtapaRegistro('CPF inválido. Confira os dígitos informados.'); document.getElementById('documentoRegistro')?.focus(); return false; }
    try {
        const politica = await _carregarPoliticaVisitantes();
        if (!politica) throw new Error('Política de documentos indisponível.');
        const parametros = new URLSearchParams({ documento: documentoDigitado, tipo_documento: tipoDocumento });
        const response = await fetch(`${API_VISITANTES}?${parametros.toString()}`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok || !data.sucesso || Number(data.dados?.id || 0) !== visitanteId) throw new Error(data.mensagem || 'Não foi possível confirmar o cadastro do visitante.');
        const visitante = data.dados;
        if (_normalizarDocumentoComparacao(visitante.documento || '') !== _normalizarDocumentoComparacao(documentoDigitado)
            || String(visitante.tipo_documento || '').toUpperCase() !== String(tipoDocumento).toUpperCase()) {
            _mostrarErroEtapaRegistro('O documento não corresponde ao cadastro selecionado. Busque o visitante novamente.');
            return false;
        }
        const possuiDocumento = Boolean(visitante.documento_arquivo);
        registroVisitanteDocumentoAnexado = possuiDocumento;
        registroVisitanteDocumentoPendente = Boolean(politica.documento_digitalizado?.obrigatorio && !possuiDocumento)
            ? 'Este condomínio exige documento digitalizado para liberar o acesso.' : '';
        registroVisitanteMensagemDetalhada = `Cadastro encontrado: ${visitante.nome_completo || 'Visitante'} — ${tipoDocumento}: ${documentoDigitado}${visitante.telefone_contato ? ` — Tel: ${visitante.telefone_contato}` : ''}${possuiDocumento ? ' — Documento digitalizado confirmado.' : ' — Sem documento digitalizado anexado.'}`;
        _renderStatusVisitanteCompacto(visitante, !possuiDocumento);
        _atualizarEstadoBotoesEtapa();
        if (registroVisitanteDocumentoPendente) { _mostrarErroEtapaRegistro(registroVisitanteDocumentoPendente); return false; }
        return true;
    } catch (error) { _mostrarErroEtapaRegistro(error?.message || 'Não foi possível validar o documento do visitante.'); return false; }
}

function _renderStatusVisitanteCompacto(visitante, semDocumento) {
    const box = document.getElementById('visitanteEncontrado');
    if (!box) return;
    box.dataset.detalhe = registroVisitanteMensagemDetalhada || `Cadastro de ${visitante?.nome_completo || 'visitante'}.`;
    box.classList.add('is-compact');
    box.classList.toggle('visitante-documento-pendente', semDocumento);
    const rotulo = registroVisitanteDocumentoPendente ? 'Documento obrigatório pendente' : semDocumento ? 'Sem documento' : 'Cadastro encontrado';
    box.innerHTML = `<button type="button" class="registro-visitante-badge" title="${_esc(box.dataset.detalhe)}"><span>${semDocumento ? '⚠' : '✓'}</span> ${rotulo}</button>`;
    box.style.display = 'flex';
    box.querySelector('button')?.addEventListener('click', _mostrarDetalheVisitante);
}

function _mostrarDetalheVisitante() {
    const box = document.getElementById('visitanteEncontrado');
    if (!box) return;
    box.classList.remove('is-compact');
    box.textContent = box.dataset.detalhe || registroVisitanteMensagemDetalhada || 'Dados do cadastro de visitante.';
    if (registroVisitanteCompactTimer) clearTimeout(registroVisitanteCompactTimer);
}

function _compactarVisitanteEncontrado(tempo = 6000) {
    if (registroVisitanteCompactTimer) clearTimeout(registroVisitanteCompactTimer);
    registroVisitanteCompactTimer = setTimeout(() => {
        if (!Number(document.getElementById('visitanteIdRegistro')?.value || 0) || !registroEstaAtivo()) return;
        _renderStatusVisitanteCompacto({ nome_completo: document.getElementById('nomeVisitanteRegistro')?.value }, registroVisitanteDocumentoAnexado === false);
    }, tempo);
}

function _setupInfoRegistro() {
    const box = document.getElementById('registroInfoBox');
    const toggle = document.getElementById('btnRegistroComoFunciona');
    if (!box || !toggle) return;
    let naoMostrar = false;
    try { naoMostrar = localStorage.getItem(REGISTRO_INFO_PREFERENCE_KEY) === '1'; } catch (_) {}
    const checkbox = document.getElementById('registroInfoNaoMostrar');
    if (checkbox) checkbox.checked = naoMostrar;
    _registrarListenerStepper(checkbox, 'change', () => {
        try {
            if (checkbox.checked) localStorage.setItem(REGISTRO_INFO_PREFERENCE_KEY, '1');
            else localStorage.removeItem(REGISTRO_INFO_PREFERENCE_KEY);
        } catch (_) {}
    });
    if (naoMostrar) { _recolherInfoRegistro(false); return; }
    _agendarTimerInfoRegistro();
}

function _agendarTimerInfoRegistro() {
    if (registroInfoTimer) clearTimeout(registroInfoTimer);
    const box = document.getElementById('registroInfoBox');
    if (registroInfoPausado || !box || box.hidden) return;
    registroInfoStartedAt = Date.now();
    registroInfoTimer = setTimeout(() => _recolherInfoRegistro(true), Math.max(0, registroInfoRemaining));
}

function _pausarTimerInfoRegistro() {
    if (registroInfoTimer) { clearTimeout(registroInfoTimer); registroInfoTimer = null; registroInfoRemaining = Math.max(0, registroInfoRemaining - (Date.now() - registroInfoStartedAt)); }
    registroInfoPausado = true;
}

function _retomarTimerInfoRegistro() { registroInfoPausado = false; _agendarTimerInfoRegistro(); }

function _recolherInfoRegistro(animar = true) {
    const box = document.getElementById('registroInfoBox');
    const toggle = document.getElementById('btnRegistroComoFunciona');
    if (!box || !toggle) return;
    if (registroInfoTimer) clearTimeout(registroInfoTimer);
    registroInfoTimer = null;
    box.classList.toggle('is-collapsed', animar);
    if (!animar) box.hidden = true;
    else setTimeout(() => { if (box.classList.contains('is-collapsed')) box.hidden = true; }, 260);
    toggle.setAttribute('aria-expanded', 'false');
    if (document.getElementById('registroInfoNaoMostrar')?.checked) { try { localStorage.setItem(REGISTRO_INFO_PREFERENCE_KEY, '1'); } catch (_) {} }
}

function _alternarInfoRegistro(usuarioIniciou = false) {
    const box = document.getElementById('registroInfoBox');
    const toggle = document.getElementById('btnRegistroComoFunciona');
    if (!box || !toggle) return;
    if (!box.hidden && !box.classList.contains('is-collapsed')) { _recolherInfoRegistro(); return; }
    box.hidden = false;
    box.classList.remove('is-collapsed');
    toggle.setAttribute('aria-expanded', 'true');
    registroInfoRemaining = usuarioIniciou ? 8000 : 8000;
    _agendarTimerInfoRegistro();
}

// ── Pré-validação e ciência de alertas ────────────────────────────────────────
function _setupCienciaRegistro() {
    const ids = [
        'tipoRegistro', 'tipoDocRegistro', 'documentoRegistro', 'placaRegistro',
        'unidadeMoradorRegistro', 'moradorSelecionadoRegistro', 'checkDependente',
        'dependenteSelecionadoRegistro', 'unidadeDestinoRegistro', 'moradorDestinoRegistro', 'dataHoraRegistro'
    ];
    ids.forEach((id) => {
        const elemento = document.getElementById(id);
        if (!elemento) return;
        const evento = elemento.tagName === 'INPUT' && ['text', 'checkbox'].includes(elemento.type) ? 'input' : 'change';
        const handler = () => { _invalidarCienciaRegistro(); };
        elemento.addEventListener(evento, handler);
        registroCienciaListeners.push({ elemento, evento, handler });
    });

    document.querySelectorAll('input[name="tipo_acesso"]').forEach((elemento) => {
        const handler = () => {
            _invalidarCienciaRegistro();
        };
        elemento.addEventListener('change', handler);
        registroCienciaListeners.push({ elemento, evento: 'change', handler });
    });

    const modal = document.getElementById('registroCienciaModal');
    const cancelar = document.getElementById('btnRegistroCienciaCancelar');
    const confirmar = document.getElementById('btnRegistroCienciaConfirmar');
    const onCancelar = () => _resolverModalCiencia({ continuar: false });
    if (cancelar) {
        cancelar.addEventListener('click', onCancelar);
        registroCienciaListeners.push({ elemento: cancelar, evento: 'click', handler: onCancelar });
    }
    if (confirmar) {
        confirmar.addEventListener('click', _confirmarModalCiencia);
        registroCienciaListeners.push({ elemento: confirmar, evento: 'click', handler: _confirmarModalCiencia });
    }
    if (modal) {
        const handler = (evento) => {
            if (evento.target === modal) evento.preventDefault();
        };
        modal.addEventListener('click', handler);
        registroCienciaListeners.push({ elemento: modal, evento: 'click', handler });
    }
    document.addEventListener('keydown', _onCienciaRegistroKeydown);
    registroCienciaListeners.push({ elemento: document, evento: 'keydown', handler: _onCienciaRegistroKeydown });
}

function _destroyCienciaRegistro() {
    if (registroCienciaTimer) clearTimeout(registroCienciaTimer);
    registroCienciaTimer = null;
    registroCienciaController?.abort();
    registroCienciaController = null;
    registroCienciaListeners.forEach(({ elemento, evento, handler }) => elemento.removeEventListener(evento, handler));
    registroCienciaListeners = [];
    _resolverModalCiencia({ continuar: false });
    registroCienciaVersion += 1;
    registroCienciaState = { chave: '', contexto: null, resultado: null, token: '' };
    _renderBannerCienciaRegistro(null);
}

function _invalidarCienciaRegistro() {
    registroCienciaVersion += 1;
    if (registroCienciaTimer) clearTimeout(registroCienciaTimer);
    registroCienciaTimer = null;
    registroCienciaController?.abort();
    registroCienciaController = null;
    registroCienciaState = { chave: '', contexto: null, resultado: null, token: '' };
    registroInformativosEtapa = new Map();
    registroAlertasEspecificosPendentes = 0;
    _atualizarChipAlertasEtapa();
    _resolverModalCiencia({ continuar: false });
    _renderBannerCienciaRegistro(null);
}

function _agendarPreflightCienciaRegistro(etapa = '') {
    if (registroCienciaTimer) clearTimeout(registroCienciaTimer);
    if (!etapa) return;
    const versao = registroCienciaVersion;
    registroCienciaTimer = setTimeout(() => {
        registroCienciaTimer = null;
        if (versao === registroCienciaVersion && registroStepperAtual === 2) void _executarPreflightEtapaRegistro(etapa);
    }, 350);
}

function _obterContextoCienciaRegistro(etapa = 'COMPLETO') {
    const tipo = String(document.getElementById('tipoRegistro')?.value || '').trim();
    const tipoAcesso = _getTipoAcesso();
    if (!tipo) return null;

    const eVisitante = _tipoExigeDocumento(tipo);
    const unidade = eVisitante
        ? (document.getElementById('unidadeDestinoRegistro')?.value || '').trim()
        : (document.getElementById('unidadeMoradorRegistro')?.value || '').trim();
    const moradorId = eVisitante
        ? (document.getElementById('moradorDestinoRegistro')?.value || '')
        : (document.getElementById('moradorId')?.value || document.getElementById('moradorSelecionadoRegistro')?.value || '');
    const dependenteEl = document.getElementById('dependenteSelecionadoRegistro');
    const dependenteId = !eVisitante && document.getElementById('checkDependente')?.checked
        ? (dependenteEl?.value || '')
        : '';
    const dataHoraRegistro = document.getElementById('dataHoraRegistro')?.value || '';
    const contexto = {
        contexto: 'REGISTRO_MANUAL',
        unidade,
        morador_id: moradorId ? Number(moradorId) || moradorId : null,
        dependente_id: dependenteId ? Number(dependenteId) || dependenteId : null,
        tipo_pessoa: !eVisitante && dependenteId ? 'Dependente' : tipo,
        visitante_id: eVisitante && etapa !== 'DESTINO' ? ((document.getElementById('visitanteIdRegistro')?.value || '').trim() || null) : null,
        documento: eVisitante && etapa !== 'DESTINO' ? ((document.getElementById('documentoRegistro')?.value || '').trim() || null) : null,
        tipo_documento: eVisitante && etapa !== 'DESTINO' ? (document.getElementById('tipoDocRegistro')?.value || null) : null,
        placa: !_isPedestre() && ['VEICULO', 'COMPLETO'].includes(etapa) ? (normalizarPlaca(document.getElementById('placaRegistro')?.value || '') || null) : null,
        tipo_acesso: tipoAcesso,
        data_hora: dataHoraRegistro ? dataHoraRegistro.replace('T', ' ') + ':00' : null
    };
    if (!contexto.unidade || !contexto.morador_id) return null;
    if (eVisitante && etapa !== 'DESTINO' && !contexto.visitante_id) return null;
    if (!eVisitante && contexto.dependente_id && !document.getElementById('dependenteSelecionadoRegistro')?.value) return null;
    if (tipoAcesso === 'Saída') contexto.tipo_acesso = 'Saída';
    return contexto;
}

function _chaveContextoCiencia(contexto) {
    return contexto ? JSON.stringify(contexto) : '';
}

async function _executarPreflightCienciaRegistro(versao = registroCienciaVersion) {
    if (versao !== registroCienciaVersion || !registroEstaAtivo()) return null;
    return _executarPreflightEtapaRegistro('VEICULO');
}

async function _solicitarCienciaRegistro(acao, corpo, signal) {
    const url = `${API_ALERTAS_UNIDADE}?acao=${encodeURIComponent(acao === 'verificar' ? 'verificar' : 'registrar_ciencia')}`;
    const response = await fetch(url, {
        method: 'POST', credentials: 'include', signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(corpo)
    });
    const texto = await response.text();
    let data;
    try { data = texto ? JSON.parse(texto) : {}; } catch (_) { throw new Error(`A API de alertas não retornou JSON válido (HTTP ${response.status}).`); }
    if (!response.ok && response.status !== 409) throw new Error(data.mensagem || `Não foi possível validar alertas (HTTP ${response.status}).`);
    return { response, data };
}

function _dadosCiencia(data) {
    return data?.dados && typeof data.dados === 'object' ? data.dados : {};
}

function _alertasCiencia(data) {
    const alertas = _dadosCiencia(data).alertas;
    return Array.isArray(alertas) ? alertas : [];
}

function _acaoAlertaCiencia(alerta) {
    return String(alerta?.acao || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_');
}

function _alertaExigeDecisao(alerta) {
    return ['EXIGIR_CIENTE', 'CONTATAR_MORADOR', 'BLOQUEAR'].includes(_acaoAlertaCiencia(alerta));
}

function _telefoneAlertaHtml(valor) {
    const exibicao = String(valor || '').trim();
    const discagem = exibicao.replace(/[^0-9+]/g, '');
    if (!exibicao) return '';
    if (!/^\+?\d{7,15}$/.test(discagem)) return _esc(exibicao);
    return `<a href="tel:${_esc(discagem)}">${_esc(exibicao)}</a>`;
}

function _resultadoExigeCiencia(data) {
    return _alertasCiencia(data).some(_alertaExigeDecisao);
}

function _renderBannerCienciaRegistro(data) {
    const banner = document.getElementById('registroCienciaBanner');
    if (!banner) return;
    const alertas = _alertasCiencia(data).filter(Boolean);
    if (!alertas.length) { banner.hidden = true; banner.innerHTML = ''; return; }
    banner.innerHTML = `<div class="ciencia-banner__heading"><i class="fas fa-shield-alt"></i><span>Pré-validação do acesso</span></div>
        ${alertas.map((alerta) => `<div class="ciencia-banner__item"><strong>${_esc(alerta.titulo || 'Alerta de acesso')}</strong>
            <span> · Nível: ${_esc(alerta.nivel || 'AVISO')} · Ação: ${_esc(alerta.acao || 'APENAS_AVISAR')}</span>
            <div>${_esc(alerta.mensagem || '')}</div>
            ${alerta.instrucao_operador ? `<div><strong>Orientação:</strong> ${_esc(alerta.instrucao_operador)}</div>` : ''}
            ${alerta.telefone_contato ? `<div><strong>Contato:</strong> ${_telefoneAlertaHtml(alerta.telefone_contato)}</div>` : ''}</div>`).join('')}`;
    banner.hidden = false;
}

function _renderModalCienciaRegistro(data) {
    const alertas = _alertasCiencia(data);
    const modal = document.getElementById('registroCienciaModal');
    const lista = document.getElementById('registroCienciaAlertas');
    const descricao = document.getElementById('registroCienciaDescricao');
    const erro = document.getElementById('registroCienciaErro');
    const confirmar = document.getElementById('btnRegistroCienciaConfirmar');
    const decisivos = alertas.filter(_alertaExigeDecisao);
    const bloqueioSemOverride = decisivos.some((alerta) => _acaoAlertaCiencia(alerta) === 'BLOQUEAR') && !_dadosCiencia(data).pode_override;
    if (!modal || !lista || !descricao || !confirmar) return false;
    descricao.textContent = bloqueioSemOverride
        ? 'Há um bloqueio de acesso e este operador não possui permissão para override. A entrada não poderá continuar.'
        : 'Leia os alertas e registre uma decisão explícita para continuar. Alertas informativos não exigem decisão.';
    lista.innerHTML = alertas.map((alerta, index) => {
        const acao = _acaoAlertaCiencia(alerta);
        const classe = acao === 'BLOQUEAR' ? 'ciencia-alert--bloquear' : acao === 'CONTATAR_MORADOR' ? 'ciencia-alert--contatar' : '';
        let decisao = '';
        if (acao === 'EXIGIR_CIENTE') {
            decisao = `<div class="ciencia-decision"><label><input type="checkbox" data-ciencia-decisao="ciente" data-ciencia-index="${index}"> Confirmo que li este alerta e estou ciente das condições para liberar.</label>${String(alerta.nivel || '').toUpperCase() === 'CRITICO' ? `<textarea class="ciencia-justificativa" data-ciencia-justificativa="${index}" minlength="10" maxlength="500" placeholder="Justificativa obrigatória (mínimo de 10 caracteres)"></textarea>` : ''}</div>`;
        } else if (acao === 'CONTATAR_MORADOR') {
            decisao = `<div class="ciencia-decision"><strong>Após contatar o morador:</strong>
                <label><input type="radio" name="ciencia-contato-${index}" value="autoriza" data-ciencia-decisao="contato" data-ciencia-index="${index}"> Morador autorizou a liberação</label>
                <label><input type="radio" name="ciencia-contato-${index}" value="nega" data-ciencia-decisao="contato" data-ciencia-index="${index}"> Morador negou a liberação</label></div>`;
        } else if (acao === 'BLOQUEAR' && _dadosCiencia(data).pode_override) {
            decisao = `<div class="ciencia-decision"><strong>Override de supervisor</strong>
                <label><input type="checkbox" data-ciencia-decisao="override" data-ciencia-index="${index}"> Confirmo que tenho permissão de supervisor para sobrescrever o bloqueio.</label>
                <textarea class="ciencia-justificativa" data-ciencia-justificativa="${index}" minlength="10" maxlength="1000" placeholder="Justificativa obrigatória (mínimo de 10 caracteres)"></textarea></div>`;
        }
        return `<article class="ciencia-alert ${classe}"><div class="ciencia-alert__meta"><span>Nível: ${_esc(alerta.nivel || 'AVISO')}</span><span>Ação: ${_esc(alerta.acao || 'APENAS_AVISAR')}</span></div>
            <strong class="ciencia-alert__title">${_esc(alerta.titulo || 'Alerta de acesso')}</strong><p class="ciencia-alert__message">${_esc(alerta.mensagem || '')}</p>
            ${alerta.instrucao_operador ? `<p class="ciencia-alert__instruction">Orientação: ${_esc(alerta.instrucao_operador)}</p>` : ''}
            ${alerta.telefone_contato ? `<p class="ciencia-alert__instruction">Contato: ${_telefoneAlertaHtml(alerta.telefone_contato)}</p>` : ''}${decisao}</article>`;
    }).join('') + (decisivos.some((alerta) => _acaoAlertaCiencia(alerta) === 'BLOQUEAR') && _dadosCiencia(data).pode_override
        ? '<div class="ciencia-decision"><label for="registroCienciaSenhaSupervisor">Confirme sua senha de supervisor</label><input id="registroCienciaSenhaSupervisor" type="password" autocomplete="current-password" maxlength="1024" required></div>'
        : '');
    if (erro) { erro.hidden = true; erro.textContent = ''; }
    confirmar.disabled = bloqueioSemOverride;
    return true;
}

function _onCienciaRegistroKeydown(evento) {
    const modal = document.getElementById('registroCienciaModal');
    if (!modal || modal.hidden) return;
    if (evento.key === 'Escape') {
        evento.preventDefault();
        evento.stopPropagation();
        _resolverModalCiencia({ continuar: false, cancelado: true });
        return;
    }
    if (evento.key !== 'Tab') return;
    const focaveis = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [href], select:not([disabled])')]
        .filter((elemento) => elemento.offsetParent !== null);
    if (!focaveis.length) return;
    const primeiro = focaveis[0];
    const ultimo = focaveis[focaveis.length - 1];
    if (evento.shiftKey && document.activeElement === primeiro) {
        evento.preventDefault();
        ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
        evento.preventDefault();
        primeiro.focus();
    }
}

function _abrirModalCienciaRegistro(data) {
    const modal = document.getElementById('registroCienciaModal');
    if (!modal || !_renderModalCienciaRegistro(data)) return Promise.resolve({ continuar: false });
    registroCienciaState.modalData = data;
    registroCienciaModalPreviousFocus = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('btnRegistroCienciaConfirmar')?.focus();
    return new Promise((resolve) => { registroCienciaModalResolver = resolve; });
}

function _resolverModalCiencia(resultado) {
    const resolver = registroCienciaModalResolver;
    registroCienciaModalResolver = null;
    const modal = document.getElementById('registroCienciaModal');
    if (modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
    const senha = document.getElementById('registroCienciaSenhaSupervisor');
    if (senha) senha.value = '';
    if (resolver) resolver(resultado || { continuar: false });
    if (registroCienciaModalPreviousFocus?.isConnected) registroCienciaModalPreviousFocus.focus();
    registroCienciaModalPreviousFocus = null;
}

function _mostrarErroModalCiencia(mensagem) {
    const erro = document.getElementById('registroCienciaErro');
    if (erro) { erro.textContent = mensagem; erro.hidden = false; }
}

async function _confirmarModalCiencia() {
    const data = registroCienciaState.modalData;
    if (!data) return;
    const alertas = _alertasCiencia(data);
    const decisoes = [];
    let negado = false;
    let senhaSupervisor = '';
    for (let index = 0; index < alertas.length; index += 1) {
        const alerta = alertas[index];
        const acao = _acaoAlertaCiencia(alerta);
        if (!_alertaExigeDecisao(alerta)) continue;
        if (!alerta.id) { _mostrarErroModalCiencia('O alerta não possui identificador para registrar a ciência. Entrada bloqueada por segurança.'); return; }
        if (acao === 'EXIGIR_CIENTE') {
            if (!document.querySelector(`[data-ciencia-decisao="ciente"][data-ciencia-index="${index}"]`)?.checked) { _mostrarErroModalCiencia('Marque a confirmação explícita para cada alerta que exige ciência.'); return; }
            const justificativa = document.querySelector(`[data-ciencia-justificativa="${index}"]`)?.value.trim() || '';
            if (String(alerta.nivel || '').toUpperCase() === 'CRITICO' && justificativa.length < 10) { _mostrarErroModalCiencia('A justificativa para liberar um alerta crítico deve ter pelo menos 10 caracteres.'); return; }
            decisoes.push({ alerta_id: alerta.id, decisao: 'CIENTE_LIBERADO', ...(justificativa ? { justificativa } : {}) });
        } else if (acao === 'CONTATAR_MORADOR') {
            const escolha = document.querySelector(`input[name="ciencia-contato-${index}"]:checked`)?.value;
            if (!escolha) { _mostrarErroModalCiencia('Informe se o morador autorizou ou negou a liberação.'); return; }
            const decisao = escolha === 'autoriza' ? 'CONTATO_MORADOR_AUTORIZOU' : 'CONTATO_MORADOR_NEGOU';
            decisoes.push({ alerta_id: alerta.id, decisao });
            negado = negado || decisao === 'CONTATO_MORADOR_NEGOU';
        } else if (acao === 'BLOQUEAR') {
            if (!_dadosCiencia(data).pode_override) { _mostrarErroModalCiencia('Este bloqueio não permite override para o operador atual.'); return; }
            if (!document.querySelector(`[data-ciencia-decisao="override"][data-ciencia-index="${index}"]`)?.checked) { _mostrarErroModalCiencia('Confirme a permissão de supervisor para sobrescrever o bloqueio.'); return; }
            const justificativa = document.querySelector(`[data-ciencia-justificativa="${index}"]`)?.value.trim() || '';
            if (justificativa.length < 10) { _mostrarErroModalCiencia('A justificativa do override deve ter pelo menos 10 caracteres.'); return; }
            senhaSupervisor = document.getElementById('registroCienciaSenhaSupervisor')?.value || '';
            if (!senhaSupervisor) { _mostrarErroModalCiencia('Informe a senha do supervisor para confirmar o override.'); return; }
            decisoes.push({ alerta_id: alerta.id, decisao: 'OVERRIDE_SUPERVISOR', justificativa });
        }
    }
    const botao = document.getElementById('btnRegistroCienciaConfirmar');
    if (botao) { botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando ciência...'; }
    try {
        const resposta = await _solicitarCienciaRegistro('registrar', { ...registroCienciaState.contexto, decisoes, ...(senhaSupervisor ? { senha_supervisor: senhaSupervisor } : {}) });
        if (negado && resposta.data?.codigo === 'ACESSO_NEGADO_ALERTA') {
            _resolverModalCiencia({ continuar: false, negado: true });
            return;
        }
        const token = _dadosCiencia(resposta.data).ciencia_token;
        if (!token || typeof token !== 'string') throw new Error('A API não retornou um token de ciência válido. Entrada bloqueada.');
        const resultado = negado ? { continuar: false, negado: true } : { continuar: true, token };
        _resolverModalCiencia(resultado);
    } catch (error) {
        _mostrarErroModalCiencia(error?.message || 'Não foi possível registrar a ciência. Entrada bloqueada.');
        if (botao) { botao.disabled = false; botao.innerHTML = '<i class="fas fa-check"></i> Registrar ciência e continuar'; }
    }
}

async function _garantirCienciaRegistro(contexto, lifecycleToken) {
    if (!contexto || contexto.tipo_acesso === 'Saída') return { continuar: true, token: '' };
    const versao = registroCienciaVersion;
    let resposta;
    try {
        resposta = await _solicitarCienciaRegistro('verificar', contexto);
    } catch (error) {
        if (registroEstaAtivo(lifecycleToken)) mostrarAlerta('error', error?.message || 'Não foi possível validar os alertas do acesso. Entrada bloqueada.');
        return { continuar: false };
    }
    if (!registroEstaAtivo(lifecycleToken) || versao !== registroCienciaVersion) return { continuar: false };
    const data = resposta.data;
    const chave = _chaveContextoCiencia(contexto);
    registroCienciaState = { chave, contexto, resultado: data, token: '', modalData: data };
    _renderBannerCienciaRegistro(data);
    if (resposta.response.status === 409) {
        mostrarAlerta('error', 'Os alertas do acesso mudaram. Revise a ciência antes de continuar.');
        return { continuar: false };
    }
    if (_dadosCiencia(data).bloqueado && !_resultadoExigeCiencia(data)) {
        mostrarAlerta('error', 'A entrada está bloqueada e não há fluxo de override disponível.');
        return { continuar: false };
    }
    if (!_resultadoExigeCiencia(data)) return { continuar: true, token: '' };
    const decisao = await _abrirModalCienciaRegistro(data);
    if (!decisao.continuar) {
        if (decisao.negado) mostrarAlerta('warning', 'O morador negou a liberação. A entrada não foi registrada.');
        return { continuar: false };
    }
    return { continuar: true, token: decisao.token };
}

async function _resolverAlertaPendenteRegistro(data, contexto, lifecycleToken) {
    const alertas = Array.isArray(data?.dados?.alertas) ? data.dados.alertas : [];
    if (!contexto || !alertas.length || !registroEstaAtivo(lifecycleToken)) {
        mostrarAlerta('error', 'A API sinalizou um alerta pendente sem contexto suficiente. Entrada bloqueada por segurança.');
        return { continuar: false };
    }
    const pendente = {
        sucesso: false,
        dados: { ...(data.dados || {}), alertas, precisa_ciencia: true, pode_override: data.dados?.pode_override === true }
    };
    registroCienciaState = { chave: _chaveContextoCiencia(contexto), contexto, resultado: pendente, token: '', modalData: pendente };
    _renderBannerCienciaRegistro(pendente);
    const decisao = await _abrirModalCienciaRegistro(pendente);
    if (!decisao.continuar) {
        if (decisao.negado) mostrarAlerta('warning', 'O morador negou a liberação. A entrada não foi registrada.');
        return { continuar: false };
    }
    return { continuar: true, token: decisao.token };
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
    const camposMorador = document.getElementById('camposMorador');
    const camposDestinoConvidado = document.getElementById('camposDestinoConvidado');
    const camposIdentificacaoVisitante = document.getElementById('camposIdentificacaoVisitante');
    const camposOcupantesVisitante = document.getElementById('camposOcupantesVisitante');

    if (convidadoAtivo && _normalizarTipoConvidado(convidadoAtivo.tipo_convidado) !== tipo) {
        convidadoAtivo = null;
        setCampoValue('convidadoIdRegistro', '');
    }

    if (camposMorador) camposMorador.style.display = tipo === 'Morador' ? 'block' : 'none';
    if (camposDestinoConvidado) camposDestinoConvidado.style.display = _tipoExigeDocumento(tipo) ? 'block' : 'none';
    if (camposIdentificacaoVisitante) camposIdentificacaoVisitante.style.display = _tipoExigeDocumento(tipo) ? 'block' : 'none';
    if (camposOcupantesVisitante) camposOcupantesVisitante.style.display = _tipoExigeDocumento(tipo) ? 'block' : 'none';

    if (!_tipoExigeDocumento(tipo)) {
        ['nomeVisitanteRegistro', 'documentoRegistro', 'diasPermanenciaRegistro'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        const selU = document.getElementById('unidadeDestinoRegistro'); if (selU) selU.value = '';
        const selM = document.getElementById('moradorDestinoRegistro');
        if (selM) { selM.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selM.disabled = true; }
        setCampoValue('visitanteIdRegistro', '');
        const boxV = document.getElementById('visitanteEncontrado');
        if (boxV) { boxV.style.display = 'none'; boxV.innerHTML = ''; }
        registroVisitanteMensagemDetalhada = '';
        registroVisitanteDocumentoAnexado = null;
        registroVisitanteDocumentoPendente = '';
        _limparOcupantes();
    }

    if (tipo !== 'Morador') {
        const selU = document.getElementById('unidadeMoradorRegistro'); if (selU) selU.value = '';
        const selM = document.getElementById('moradorSelecionadoRegistro');
        if (selM) { selM.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selM.disabled = true; }
        setCampoValue('moradorId', '');
        const iW = document.getElementById('moradorInfoWrap'); if (iW) iW.style.display = 'none';
        _resetarDependente();
    }

    _atualizarModoRegistro();
    _renderStepperRegistro();
    _atualizarEstadoBotoesEtapa();
}

function _atualizarCamposTipoRegistro() { onTipoChange(); }

function _tipoExigeDocumento(tipo) {
    return ['Visitante', 'Prestador', 'Entregador Autorizado', 'Familiar'].includes(tipo);
}

function _normalizarTipoConvidado(tipo) {
    const mapa = {
        VISITANTE: 'Visitante',
        PRESTADOR: 'Prestador',
        ENTREGADOR_AUTORIZADO: 'Entregador Autorizado',
        FAMILIAR: 'Familiar'
    };
    const chave = String(tipo || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_');
    return mapa[chave] || String(tipo || 'Visitante');
}

function _garantirCampoConvidado() {
    let campo = document.getElementById('convidadoIdRegistro');
    if (!campo) {
        campo = document.createElement('input');
        campo.type = 'hidden';
        campo.id = 'convidadoIdRegistro';
        campo.name = 'convidado_id';
        document.getElementById('registroForm')?.appendChild(campo);
    }
    return campo;
}

function _ativarAbaManual() {
    const botao = document.querySelector('.page-registro .tab-button[data-tab="manual"]');
    if (botao) botao.click();
    const painel = document.getElementById('tab-registro-manual');
    if (painel) painel.style.display = '';
    document.getElementById('registroForm')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function iniciarConvidado(guest, tipoAcesso = 'Entrada') {
    if (!guest || !Number.isInteger(Number(guest.id)) || Number(guest.id) <= 0) {
        throw new Error('O convite selecionado é inválido. Atualize a lista e tente novamente.');
    }
    const tipo = _normalizarTipoConvidado(guest.tipo_convidado);
    if (!_tipoExigeDocumento(tipo)) throw new Error('O tipo deste convidado não pode ser registrado pelo fluxo de visitantes.');
    const lifecycleToken = registroLifecycleToken;
    const selectionToken = ++convidadoSelecaoToken;
    registroVisitanteDocumentoAnexado = null;
    registroVisitanteDocumentoPendente = '';
    registroVisitanteMensagemDetalhada = '';
    if (registroVisitanteCompactTimer) clearTimeout(registroVisitanteCompactTimer);
    registroVisitanteCompactTimer = null;
    document.getElementById('cadastroVisitanteNaChegada')?.remove();
    const convitesPendentes = document.getElementById('convidadoPrevistoRegistro');
    if (convitesPendentes) { convitesPendentes.style.display = 'none'; convitesPendentes.innerHTML = ''; }

    _ativarAbaManual();
    setCampoValue('tipoRegistro', tipo);
    onTipoChange();
    registroStepperAtual = 1;
    registroEtapasConcluidas = new Set();
    convidadoAtivo = { ...guest };
    _garantirCampoConvidado().value = String(guest.id);
    setCampoValue('tipoDocRegistro', ['CPF', 'RG'].includes(String(guest.tipo_documento || '').toUpperCase())
        ? String(guest.tipo_documento).toUpperCase()
        : 'CPF');
    setCampoValue('documentoRegistro', guest.documento || guest.documento_completo || '');
    setCampoValue('nomeVisitanteRegistro', guest.nome || guest.nome_completo || '');
    setCampoValue('visitanteIdRegistro', guest.visitante_id || '');
    const nomeInput = document.getElementById('nomeVisitanteRegistro');
    if (nomeInput) nomeInput.readOnly = Boolean(guest.visitante_id);
    setCampoValue('diasPermanenciaRegistro', guest.dias_permanencia || 1);

    const radio = document.querySelector(`input[name="tipo_acesso"][value="${tipoAcesso === 'Saída' ? 'Saída' : 'Entrada'}"]`);
    if (radio) radio.checked = true;
    _atualizarBotoesAcesso();
    await _selecionarDestinoConvidado(guest, selectionToken, lifecycleToken);
    if (!registroEstaAtivo(lifecycleToken) || selectionToken !== convidadoSelecaoToken || Number(convidadoAtivo?.id) !== Number(guest.id)) return false;

    if (_getTipoAcesso() === 'Entrada') {
        const preflightDestino = await _executarPreflightEtapaRegistro('DESTINO');
        if (!registroEstaAtivo(lifecycleToken) || !preflightDestino?.continuar) return false;
    }
    registroEtapasConcluidas.add(1);
    registroEtapasConcluidas.add(2);

    const box = document.getElementById('visitanteEncontrado');
    if (box) {
        box.style.display = 'flex';
        box.classList.toggle('visitante-documento-pendente', !guest.visitante_id);
        box.innerHTML = guest.visitante_id
            ? `<i class="fas fa-check-circle"></i><span>Convidado vinculado: <strong>${_esc(guest.nome || guest.nome_completo || 'Visitante')}</strong> — ${_esc(tipoAcesso)}. Confira os dados e confirme o registro.</span>`
            : `<i class="fas fa-info-circle"></i><span>Convidado sem cadastro-base de visitante. Complete o cadastro abaixo antes de confirmar a entrada.</span>`;
    }
    if (!guest.visitante_id) _mostrarCadastroVisitanteNaChegada(guest);
    else document.getElementById('cadastroVisitanteNaChegada')?.remove();
    if (guest.visitante_id) {
        if (!await _validarDocumentoVisitanteRegistro()) { _abrirEtapaRegistro(3); return false; }
        if (_getTipoAcesso() === 'Entrada') {
            const preflightIdentificacao = await _executarPreflightEtapaRegistro('IDENTIFICACAO');
            if (!registroEstaAtivo(lifecycleToken) || !preflightIdentificacao?.continuar) return false;
        }
        registroEtapasConcluidas.add(3);
        registroStepperAtual = 4;
        _abrirEtapaRegistro(4);
    } else {
        registroStepperAtual = 3;
        _abrirEtapaRegistro(3);
    }
    _ativarAbaManual();
    if (guest.visitante_id) document.getElementById('placaRegistro')?.focus();
    else document.getElementById('btnBuscarDocRegistro')?.focus();
    _renderStepperRegistro();
    _atualizarEstadoBotoesEtapa();
    return true;
}

async function _selecionarDestinoConvidado(guest, selectionToken = convidadoSelecaoToken, lifecycleToken = registroLifecycleToken) {
    const unidade = String(guest?.unidade || guest?.unidade_destino || '');
    const moradorId = String(guest?.morador_id || guest?.anfitriao_morador_id || '');
    const unidadeEl = document.getElementById('unidadeDestinoRegistro');
    const moradorEl = document.getElementById('moradorDestinoRegistro');
    if (!unidadeEl || !moradorEl) return;
    unidadeEl.value = unidade;
    moradorEl.innerHTML = '<option value="">Carregando...</option>';
    moradorEl.disabled = true;
    try {
        const resp = await fetch(`${API_MORADORES}?unidade=${encodeURIComponent(unidade)}&ativo=1&por_pagina=0`, { credentials: 'include' });
        const data = await resp.json();
        if (!registroEstaAtivo(lifecycleToken) || selectionToken !== convidadoSelecaoToken || Number(convidadoAtivo?.id) !== Number(guest?.id) || unidadeEl.value !== unidade) return false;
        const moradores = data.dados?.itens || (Array.isArray(data.dados) ? data.dados : []);
        moradorEl.innerHTML = '<option value="">Selecione o morador...</option>';
        moradores.forEach((morador) => moradorEl.add(new Option(morador.nome_completo || morador.nome, morador.id)));
        moradorEl.value = moradorId;
        moradorEl.disabled = moradores.length === 0;
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken) || selectionToken !== convidadoSelecaoToken || Number(convidadoAtivo?.id) !== Number(guest?.id)) return false;
        console.warn('[Registro][Convidados] Erro ao carregar destino:', error);
        moradorEl.innerHTML = '<option value="">Não foi possível carregar o morador</option>';
    } finally {
        if (registroEstaAtivo(lifecycleToken) && selectionToken === convidadoSelecaoToken && Number(convidadoAtivo?.id) === Number(guest?.id)) moradorEl.disabled = false;
    }
    return registroEstaAtivo(lifecycleToken) && selectionToken === convidadoSelecaoToken && Number(convidadoAtivo?.id) === Number(guest?.id);
}

async function _carregarPoliticaVisitantes() {
    if (politicaVisitantes) return politicaVisitantes;
    const lifecycleToken = registroLifecycleToken;
    try {
        const resp = await fetch(`${API_CONVIDADOS}?acao=politica_visitantes`, { credentials: 'include' });
        const data = await resp.json();
        if (!registroEstaAtivo(lifecycleToken)) return null;
        if (!resp.ok || !data.sucesso) throw new Error(data.mensagem || `HTTP ${resp.status}`);
        politicaVisitantes = {};
        (data.dados?.campos || []).forEach((campo) => { politicaVisitantes[campo.campo] = campo; });
        console.log('[Registro][Convidados] Política de visitantes carregada.');
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken)) return null;
        console.warn('[Registro][Convidados] Não foi possível carregar a política de visitantes:', error);
        politicaVisitantes = null;
        throw new Error('Não foi possível carregar a política de cadastro deste condomínio. Atualize a tela ou solicite suporte antes de cadastrar o convidado.');
    }
    _atualizarCamposCadastroChegada();
    return politicaVisitantes;
}

function _atualizarCamposCadastroChegada() {
    const mapa = {
        convidadoChegadaNome: 'nome_completo',
        convidadoChegadaTipoDocumento: 'tipo_documento',
        convidadoChegadaDocumento: 'documento',
        convidadoChegadaTelefone: 'telefone_contato',
        convidadoChegadaCelular: 'celular',
        convidadoChegadaEmail: 'email',
        convidadoChegadaObservacao: 'observacao'
    };
    Object.entries(mapa).forEach(([id, campo]) => {
        const elemento = document.getElementById(id);
        if (elemento) elemento.required = Boolean(politicaVisitantes?.[campo]?.obrigatorio);
    });
}

function _mostrarCadastroVisitanteNaChegada(guest) {
    const extra = document.getElementById('camposIdentificacaoVisitante');
    if (!extra || document.getElementById('cadastroVisitanteNaChegada')) return;
    const lifecycleToken = registroLifecycleToken;
    const box = document.createElement('div');
    box.id = 'cadastroVisitanteNaChegada';
    box.className = 'visitante-encontrado visitante-documento-pendente';
    box.innerHTML = `
        <strong>Visitante ainda não cadastrado.</strong>
        <span>Preencha os dados abaixo. A política do condomínio será validada antes de liberar o cadastro.</span>
        <div class="form-grid reg-grid-3" style="width:100%;margin-top:10px;">
            <label>Nome completo *<input id="convidadoChegadaNome" type="text"></label>
            <label>Tipo de documento *<select id="convidadoChegadaTipoDocumento"><option value="CPF">CPF</option><option value="RG">RG</option></select></label>
            <label>Número do documento *<input id="convidadoChegadaDocumento" type="text" inputmode="text"></label>
            <label>Telefone de contato<input id="convidadoChegadaTelefone" type="text"></label>
            <label>Celular<input id="convidadoChegadaCelular" type="text"></label>
            <label>E-mail<input id="convidadoChegadaEmail" type="email"></label>
            <label>Observação<input id="convidadoChegadaObservacao" type="text" maxlength="500"></label>
            <label>Foto<input id="convidadoChegadaFoto" type="file" accept="image/jpeg,image/png,image/webp"></label>
            <label>Documento digitalizado<input id="convidadoChegadaDocumentoArquivo" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
        </div>
        <button type="button" class="btn-secondary-modern" id="btnCriarVisitanteChegada" disabled>Carregando política...</button>
        <small id="convidadoChegadaPolitica" style="display:block;margin-top:8px;"></small>`;
    extra.appendChild(box);
    setCampoValue('convidadoChegadaNome', guest?.nome || guest?.nome_completo || '');
    setCampoValue('convidadoChegadaTipoDocumento', guest?.tipo_documento || 'CPF');
    setCampoValue('convidadoChegadaDocumento', guest?.documento || '');
    setCampoValue('convidadoChegadaTelefone', guest?.telefone || guest?.telefone_contato || '');
    setCampoValue('convidadoChegadaCelular', guest?.celular || '');
    setCampoValue('convidadoChegadaEmail', guest?.email || '');
    setCampoValue('convidadoChegadaObservacao', guest?.observacao || '');
    const tipoDocumentoEl = document.getElementById('convidadoChegadaTipoDocumento');
    const documentoEl = document.getElementById('convidadoChegadaDocumento');
    tipoDocumentoEl?.addEventListener('change', () => {
        if (documentoEl) documentoEl.value = '';
        _atualizarCamposCadastroChegada();
    });
    documentoEl?.addEventListener('input', () => {
        if (documentoEl && tipoDocumentoEl) documentoEl.value = _aplicarMascaraDocumentoValor(tipoDocumentoEl.value, documentoEl.value);
    });
    document.getElementById('btnCriarVisitanteChegada')?.addEventListener('click', () => _criarVisitanteNaChegada());
    _carregarPoliticaVisitantes().then(() => {
        if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== Number(guest?.id)) return;
        if (!politicaVisitantes) throw new Error('Política de cadastro indisponível.');
        const obrigatorios = Object.values(politicaVisitantes || {}).filter((campo) => campo.obrigatorio).map((campo) => campo.rotulo);
        const politicaEl = document.getElementById('convidadoChegadaPolitica');
        if (politicaEl) politicaEl.textContent = obrigatorios.length ? `Obrigatórios neste condomínio: ${obrigatorios.join(', ')}.` : 'Nenhum campo adicional obrigatório configurado.';
        const botao = document.getElementById('btnCriarVisitanteChegada');
        if (botao) { botao.disabled = false; botao.textContent = 'Cadastrar visitante e continuar'; }
    }).catch((error) => {
        if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== Number(guest?.id)) return;
        const politicaEl = document.getElementById('convidadoChegadaPolitica');
        if (politicaEl) politicaEl.textContent = error.message;
        const botao = document.getElementById('btnCriarVisitanteChegada');
        if (botao) { botao.disabled = true; botao.textContent = 'Política indisponível'; }
    });
}

async function _criarVisitanteNaChegada() {
    const lifecycleToken = registroLifecycleToken;
    const guestId = Number(convidadoAtivo?.id || 0);
    const button = document.getElementById('btnCriarVisitanteChegada');
    if (!convidadoAtivo?.id) {
        mostrarAlerta('error', 'Selecione novamente o convidado antes de concluir o cadastro.');
        return;
    }
    const campos = {
        nome_completo: (document.getElementById('convidadoChegadaNome')?.value || '').trim(),
        tipo_documento: document.getElementById('convidadoChegadaTipoDocumento')?.value || 'CPF',
        documento: (document.getElementById('convidadoChegadaDocumento')?.value || '').trim(),
        telefone_contato: (document.getElementById('convidadoChegadaTelefone')?.value || '').trim(),
        telefone: (document.getElementById('convidadoChegadaTelefone')?.value || '').trim(),
        celular: (document.getElementById('convidadoChegadaCelular')?.value || '').trim(),
        email: (document.getElementById('convidadoChegadaEmail')?.value || '').trim(),
        observacao: (document.getElementById('convidadoChegadaObservacao')?.value || '').trim()
    };
    const documentoConvidado = _normalizarDocumentoComparacao(convidadoAtivo?.documento_normalizado || convidadoAtivo?.documento || '');
    if (!registroEstaAtivo(lifecycleToken) || !guestId || Number(convidadoAtivo?.id) !== guestId) return;
    if (campos.tipo_documento !== String(convidadoAtivo?.tipo_documento || '').toUpperCase()
        || _normalizarDocumentoComparacao(campos.documento) !== documentoConvidado) {
        mostrarAlerta('error', 'O documento e o tipo informado precisam coincidir com os dados do convite.');
        return;
    }
    if (campos.tipo_documento === 'CPF' && !_cpfValidoRegistro(campos.documento)) {
        mostrarAlerta('error', 'CPF inválido. Confira os dígitos informados.');
        document.getElementById('convidadoChegadaDocumento')?.focus();
        return;
    }
    if (!campos.nome_completo || !campos.documento) {
        mostrarAlerta('error', 'Informe o nome e o documento do visitante.');
        return;
    }

    let config;
    try {
        config = await _carregarPoliticaVisitantes();
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== guestId) return;
        mostrarAlerta('error', error?.message || 'Não foi possível validar a política de cadastro.');
        return;
    }
    if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== guestId) return;
    if (!config) {
        mostrarAlerta('error', 'A política de cadastro deste condomínio não está disponível.');
        return;
    }
    const inputIds = {
        nome_completo: 'convidadoChegadaNome',
        tipo_documento: 'convidadoChegadaTipoDocumento',
        documento: 'convidadoChegadaDocumento',
        telefone_contato: 'convidadoChegadaTelefone',
        celular: 'convidadoChegadaCelular',
        email: 'convidadoChegadaEmail',
        observacao: 'convidadoChegadaObservacao'
    };
    for (const [campo, id] of Object.entries(inputIds)) {
        if (config?.[campo]?.obrigatorio && !String(campos[campo] || '').trim()) {
            mostrarAlerta('error', `O campo "${config[campo].rotulo}" é obrigatório neste condomínio.`);
            document.getElementById(id)?.focus();
            return;
        }
    }

    const foto = document.getElementById('convidadoChegadaFoto')?.files?.[0] || null;
    const documentoArquivo = document.getElementById('convidadoChegadaDocumentoArquivo')?.files?.[0] || null;
    if (config?.foto?.obrigatorio && !foto) {
        mostrarAlerta('error', 'A foto do visitante é obrigatória para este condomínio.');
        document.getElementById('convidadoChegadaFoto')?.focus();
        return;
    }
    if (config?.documento_digitalizado?.obrigatorio && !documentoArquivo) {
        mostrarAlerta('error', 'O documento digitalizado é obrigatório para este condomínio.');
        document.getElementById('convidadoChegadaDocumentoArquivo')?.focus();
        return;
    }
    if (config?.anexo_evidencia?.obrigatorio && !foto && !documentoArquivo) {
        mostrarAlerta('error', 'Anexe uma foto ou o documento digitalizado, conforme a configuração deste condomínio.');
        document.getElementById('convidadoChegadaFoto')?.focus();
        return;
    }

    if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...'; }
    try {
        const form = new FormData();
        form.append('dados', JSON.stringify(campos));
        if (foto) form.append('foto', foto);
        if (documentoArquivo) form.append('documento', documentoArquivo);
        const response = await fetch(API_VISITANTES, { method: 'POST', credentials: 'include', body: form });
        const data = await response.json();
        if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== guestId) return;
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Não foi possível cadastrar o visitante.');
        const visitanteId = Number(data.dados?.id || 0);
        if (visitanteId <= 0) throw new Error('O cadastro não retornou o identificador do visitante. Atualize a lista antes de continuar.');

        convidadoAtivo.visitante_id = visitanteId;
        convidadoAtivo.nome = campos.nome_completo;
        registroVisitanteDocumentoAnexado = Boolean(documentoArquivo);
        registroVisitanteDocumentoPendente = Boolean(config.documento_digitalizado?.obrigatorio && !documentoArquivo)
            ? 'Este condomínio exige documento digitalizado para liberar o acesso.' : '';
        registroVisitanteMensagemDetalhada = `Cadastro de ${campos.nome_completo} concluído e vinculado ao convite. ${documentoArquivo ? 'Documento digitalizado confirmado.' : 'Sem documento digitalizado anexado.'}`;
        setCampoValue('visitanteIdRegistro', visitanteId);
        setCampoValue('nomeVisitanteRegistro', campos.nome_completo);
        const nomeInput = document.getElementById('nomeVisitanteRegistro');
        if (nomeInput) nomeInput.readOnly = true;
        setCampoValue('tipoDocRegistro', campos.tipo_documento);
        setCampoValue('documentoRegistro', campos.documento);
        document.getElementById('cadastroVisitanteNaChegada')?.remove();
        const box = document.getElementById('visitanteEncontrado');
        if (box) {
            box.style.display = 'flex';
            box.classList.remove('visitante-documento-pendente');
            box.innerHTML = `<i class="fas fa-check-circle"></i><span>${_esc(registroVisitanteMensagemDetalhada)}</span>`;
            box.dataset.detalhe = registroVisitanteMensagemDetalhada;
            _compactarVisitanteEncontrado(6000);
        }
        _atualizarEstadoBotoesEtapa();
        mostrarAlerta('success', 'Visitante cadastrado e vinculado. Confirme o registro de acesso.');
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken) || Number(convidadoAtivo?.id) !== guestId) return;
        console.error('[Registro][Convidados] Erro ao cadastrar visitante na chegada:', error);
        mostrarAlerta('error', error?.message || 'Não foi possível cadastrar o visitante.');
    } finally {
        if (registroEstaAtivo(lifecycleToken) && Number(convidadoAtivo?.id) === guestId && button?.isConnected) { button.disabled = false; button.innerHTML = 'Cadastrar visitante e continuar'; }
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
    const lifecycleToken = registroLifecycleToken;
    try {
        const response = await fetch(API_VEICULOS, { credentials: 'include' });
        const data = await response.json();
        if (!registroEstaAtivo(lifecycleToken)) return;
        veiculosCache = (data.sucesso && Array.isArray(data.dados)) ? data.dados : [];
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken)) return;
        console.error('[Registro] Erro ao carregar veículos:', error);
        veiculosCache = [];
    }
}

// ── Carregar Registros ────────────────────────────────────────────────────────
async function carregarRegistros() {
    const lifecycleToken = registroLifecycleToken;
    const tbody = document.querySelector('#tabelaRegistros tbody');
    const paramsUrl = new URLSearchParams(window.location.search);
    const registroIdDireto = Number(paramsUrl.get('registro_id'));
    const query = new URLSearchParams({ limite: '100' });
    if (Number.isSafeInteger(registroIdDireto) && registroIdDireto > 0) {
        query.set('registro_id', String(registroIdDireto));
        query.set('limite', '1');
    }
    setLoading(true);
    try {
        const response = await fetch(`${API_REGISTROS}?${query.toString()}`, { credentials: 'include' });
        const data = await response.json();
        if (!registroEstaAtivo(lifecycleToken)) return;
        if (!data.sucesso) { renderMensagemTabela(tbody, data.mensagem || 'Erro ao carregar registros.'); return; }
        registrosCache = data.dados || [];
        renderRegistros(registrosCache);
        if (Number.isSafeInteger(registroIdDireto) && registroIdDireto > 0) {
            const linha = document.getElementById(`registro-${registroIdDireto}`);
            if (linha) {
                linha.classList.add('registro-destacado');
                linha.scrollIntoView({ behavior: 'smooth', block: 'center' });
                linha.focus({ preventScroll: true });
            }
        }
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken)) return;
        console.error('[Registro] Erro ao carregar registros:', error);
        renderMensagemTabela(tbody, 'Erro de conexão ao carregar registros.');
    } finally {
        if (registroEstaAtivo(lifecycleToken)) setLoading(false);
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
        || (r.vestimenta || '').toLowerCase().includes(q)
        || (r.tipo_acesso || '').toLowerCase().includes(q)
        || (r.usuario_liberou || '').toLowerCase().includes(q)
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
        const tipo      = _esc(r.tipo || '-')
            + (r.modo_registro === 'PEDESTRE' ? '<span class="badge-pedestre"><i class="fas fa-person-walking"></i> Pedestre</span>' : '');
        const nome      = _esc(r.morador_nome || r.nome_visitante || r.tipo || '-')
            + (r.papel_veiculo === 'OCUPANTE' ? '<span class="badge-ocupante"><i class="fas fa-user-friends"></i> Ocupante</span>' : '');
        const unidade   = _esc(r.morador_unidade || r.unidade_destino || '-');
        const usuarioLiberou = _esc(r.usuario_liberou || '—');
        const status    = _esc(r.status || '-');
        const statusClass = classificarStatus(r.status, r.liberado);

        const tipoAcesso = r.tipo_acesso || '';
        const badgeAcesso = tipoAcesso === 'Saída'
            ? `<span class="badge-saida"><i class="fas fa-sign-out-alt"></i> Saída</span>`
            : tipoAcesso === 'Entrada'
            ? `<span class="badge-entrada"><i class="fas fa-sign-in-alt"></i> Entrada</span>`
            : '—';

        return `
            <tr id="registro-${Number.isSafeInteger(Number(id)) && Number(id) > 0 ? Number(id) : ''}" tabindex="-1">
                <td data-label="Data/Hora">${dataHora}</td>
                <td data-label="Placa"><span class="plate-badge">${placa}</span></td>
                <td data-label="Modelo">${modelo}</td>
                <td data-label="Cor">${cor}</td>
                <td data-label="Tipo">${tipo}</td>
                <td data-label="Nome">${nome}</td>
                <td data-label="Unidade">${unidade}</td>
                <td data-label="Usuário que liberou">${usuarioLiberou}</td>
                <td data-label="Entrada/Saída">${badgeAcesso}</td>
                <td data-label="Status"><span class="status-pill ${statusClass}">${status}</span></td>
                <td data-label="Ações">
                    <button class="action-btn delete" type="button" onclick="window.RegistroPage.excluir(${id})" title="Excluir registro">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
    }).join('');
}

function renderMensagemTabela(tbody, msg) {
    if (tbody) tbody.innerHTML = `<tr><td colspan="11" class="empty-state">${_esc(msg)}</td></tr>`;
}

// ── Salvar ────────────────────────────────────────────────────────────────────
async function salvarRegistro() {
    if (salvandoReg || registroEtapaEmProcessamento) return;
    const lifecycleToken = registroLifecycleToken;
    if (!_validarEtapa1Registro()) { _abrirEtapaRegistro(1); _mostrarErroEtapaRegistro('Complete data, tipo e Entrada/Saída antes de registrar.'); return; }
    if (!_validarEtapa2Registro()) { _abrirEtapaRegistro(2); _mostrarErroEtapaRegistro('Complete o destino antes de registrar.'); return; }
    const tipoAtual = document.getElementById('tipoRegistro')?.value || '';
    if (_tipoExigeDocumento(tipoAtual) && !_validarEtapa3BasicaRegistro()) { _abrirEtapaRegistro(3); _mostrarErroEtapaRegistro('Identifique o visitante e conclua o cadastro antes de registrar.'); return; }
    if (!_validarEtapa4BasicaRegistro()) { _abrirEtapaRegistro(4); _mostrarErroEtapaRegistro('Informe uma placa válida ou selecione Pedestre.'); return; }
    if (_getTipoAcesso() === 'Entrada') {
        const contextoFinal = _obterContextoCienciaRegistro('VEICULO');
        const chaveFinal = contextoFinal ? _chaveContextoCiencia(contextoFinal) : '';
        if (!contextoFinal || registroCienciaState?.chave !== chaveFinal || registroCienciaState?.resultado?.dados?.parcial !== true) {
            const preflightFinal = await _executarPreflightEtapaRegistro('VEICULO');
            if (!registroEstaAtivo(lifecycleToken) || !preflightFinal?.continuar) return;
        }
    }
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
        const pedestre      = _isPedestre();
        const vestimenta    = (document.getElementById('vestimentaRegistro')?.value || '').trim();

        if (!dataHoraInput || !tipo || (!pedestre && !placa)) {
            mostrarAlerta('error', pedestre
                ? 'Data/hora e tipo são obrigatórios.'
                : 'Data/hora, placa e tipo são obrigatórios.');
            return;
        }

        let payload = {
            data_hora:   `${dataHoraInput.replace('T', ' ')}:00`,
            placa,
            modelo,
            cor,
            tipo,
            observacao,
            tipo_acesso: tipoAcesso,
            modo_registro: pedestre ? 'PEDESTRE' : 'VEICULO'
        };
        if (pedestre) payload.vestimenta = vestimenta;
        const convidadoId = Number(document.getElementById('convidadoIdRegistro')?.value || 0);
        if (convidadoId > 0) payload.convidado_id = convidadoId;

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

        // ── Visitante, Prestador, Entregador Autorizado ou Familiar ──
        if (_tipoExigeDocumento(tipo)) {
            const nomeVisitante   = (document.getElementById('nomeVisitanteRegistro')?.value || '').trim();
            const unidadeDestino  = document.getElementById('unidadeDestinoRegistro')?.value || '';
            const moradorDestino  = document.getElementById('moradorDestinoRegistro')?.value || '';
            const diasPermanencia = Number(document.getElementById('diasPermanenciaRegistro')?.value || 1);
            const visitanteId     = document.getElementById('visitanteIdRegistro')?.value || '';
            const documento       = document.getElementById('documentoRegistro')?.value.trim() || '';

            if (!nomeVisitante)  { mostrarAlerta('error', 'Informe o nome do visitante/convidado.'); return; }
            if (!unidadeDestino) { mostrarAlerta('error', 'Selecione a unidade de destino.'); return; }
            if (!moradorDestino) { mostrarAlerta('error', 'Selecione o morador que será visitado.'); return; }
            if (!visitanteId) {
                mostrarAlerta('error', 'Cadastre o visitante pelo formulário de chegada antes de confirmar o acesso.');
                document.getElementById('documentoRegistro')?.focus();
                return;
            }

            payload.nome_visitante   = nomeVisitante;
            payload.unidade_destino  = unidadeDestino;
            payload.morador_id       = moradorDestino || null;
            payload.dias_permanencia = diasPermanencia;
            if (visitanteId) payload.visitante_id = visitanteId;
            if (documento)   payload.documento     = documento;
            const tipoDocumento = document.getElementById('tipoDocRegistro')?.value || '';
            if (tipoDocumento) payload.tipo_documento = tipoDocumento;

            // Ocupantes: cada um vira um registro de acesso próprio (papel_veiculo=OCUPANTE),
            // vinculado ao registro titular, para que o mesmo veículo/horário fique
            // rastreável mesmo que algum ocupante já tenha registro como titular em outro veículo.
            const checkOcup = document.getElementById('checkOcupantes');
            if (checkOcup?.checked && ocupantesAdicionados.length > 0) {
                payload.ocupantes = ocupantesAdicionados.map(o => ({
                    visitante_id:   o.visitante_id,
                    documento:      o.documento,
                    tipo_documento: o.tipo_documento
                }));
            }
        }

        if (_tipoExigeDocumento(tipo) && !await _validarDocumentoVisitanteRegistro()) {
            _abrirEtapaRegistro(3);
            return;
        }
        if (tipoAcesso === 'Entrada') {
            const preflightVeiculo = await _executarPreflightEtapaRegistro('VEICULO');
            if (!registroEstaAtivo(lifecycleToken) || !preflightVeiculo?.continuar) return;
        }

        const contextoCiencia = _obterContextoCienciaRegistro('COMPLETO');
        const cienciaInicial = await _garantirCienciaRegistro(contextoCiencia, lifecycleToken);
        if (!registroEstaAtivo(lifecycleToken) || !cienciaInicial.continuar) return;
        if (cienciaInicial.token) payload.ciencia_token = cienciaInicial.token;

        const assinaturaAtual = assinaturaRegistro(payload);
        const campoIdempotency = document.getElementById('idempotencyKeyRegistro');
        if (ultimoEnvioRegistro
            && campoIdempotency?.value === ultimoEnvioRegistro.chave
            && ultimoEnvioRegistro.assinatura !== assinaturaAtual) {
            campoIdempotency.value = gerarIdempotencyKey();
            console.log('[Registro] Novo lançamento detectado; chave de idempotência renovada.');
        }
        const idempotencyKey = garantirIdempotencyKey();
        payload.idempotency_key = idempotencyKey;
        ultimoEnvioRegistro = { chave: idempotencyKey, assinatura: assinaturaAtual };

        console.log('[Registro] Enviando acesso manual.', {
            tipo, tipo_acesso: tipoAcesso, modo_registro: payload.modo_registro,
            vinculado_a_convite: convidadoId > 0,
            ocupantes: Array.isArray(payload.ocupantes) ? payload.ocupantes.length : 0
        });

        let retryAlertaPendente = false;
        let response = await fetch(API_REGISTROS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(payload)
        });
        if (!registroEstaAtivo(lifecycleToken)) return;
        let data = await response.json();
        if (!registroEstaAtivo(lifecycleToken)) return;

        if (!data.sucesso && (data.codigo === 'ALERTA_PENDENTE' || data.dados?.codigo === 'ALERTA_PENDENTE')) {
            retryAlertaPendente = true;
            const novaCiencia = await _resolverAlertaPendenteRegistro(data, contextoCiencia, lifecycleToken);
            if (!registroEstaAtivo(lifecycleToken) || !novaCiencia.continuar) return;
            if (novaCiencia.token) payload.ciencia_token = novaCiencia.token;
            response = await fetch(API_REGISTROS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            if (!registroEstaAtivo(lifecycleToken)) return;
            data = await response.json();
            if (!registroEstaAtivo(lifecycleToken)) return;
        }

        if (!retryAlertaPendente && !data.sucesso && data.dados?.codigo === 'IDEMPOTENCY_CONFLICT') {
            const campo = document.getElementById('idempotencyKeyRegistro');
            const novaChave = gerarIdempotencyKey();
            if (campo) campo.value = novaChave;
            payload.idempotency_key = novaChave;
            ultimoEnvioRegistro = { chave: novaChave, assinatura: assinaturaRegistro(payload) };
            console.log('[Registro] Colisão de chave detectada; repetindo o novo lançamento com chave renovada.');

            response = await fetch(API_REGISTROS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            if (!registroEstaAtivo(lifecycleToken)) return;
            data = await response.json();
            if (!registroEstaAtivo(lifecycleToken)) return;
        }

        if (!data.sucesso) { mostrarAlerta('error', data.mensagem || 'Falha ao registrar acesso.'); return; }

        const qtdOcupantes = Number(data.dados?.ocupantes_registrados?.length || 0);
        const mensagemSucesso = qtdOcupantes > 0
            ? `${data.mensagem || 'Registro salvo com sucesso.'} + ${qtdOcupantes} ocupante${qtdOcupantes > 1 ? 's' : ''} registrado${qtdOcupantes > 1 ? 's' : ''}.`
            : (data.mensagem || 'Registro salvo com sucesso.');
        limparFormulario();
        try {
            sessionStorage.setItem(REGISTRO_FEEDBACK_STORAGE_KEY, JSON.stringify({
                mensagem: mensagemSucesso,
                criado_em: Date.now()
            }));
        } catch (storageError) {
            console.warn('[Registro] Não foi possível preservar o aviso após atualização:', storageError);
        }
        window.location.reload();
        return;

    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken)) return;
        console.error('[Registro] Erro ao salvar registro:', error);
        mostrarAlerta('error', 'Erro interno ao salvar registro.');
    } finally {
        if (registroEstaAtivo(lifecycleToken)) {
            salvandoReg = false;
            if (btn?.isConnected) btn.innerHTML = '<i class="fas fa-save"></i> Registrar Acesso';
            _atualizarEstadoBotoesEtapa();
        }
    }
}

function _mostrarFeedbackPosAtualizacao() {
    try {
        const bruto = sessionStorage.getItem(REGISTRO_FEEDBACK_STORAGE_KEY);
        if (!bruto) return;
        sessionStorage.removeItem(REGISTRO_FEEDBACK_STORAGE_KEY);
        const feedback = JSON.parse(bruto);
        if (feedback?.mensagem && Date.now() - Number(feedback.criado_em || 0) < 60000) {
            mostrarAlerta('success', feedback.mensagem);
        }
    } catch (error) {
        console.warn('[Registro] Não foi possível restaurar o aviso após atualização:', error);
    }
}

// ── Excluir ───────────────────────────────────────────────────────────────────
async function excluirRegistro(id) {
    if (!confirm('Deseja realmente excluir este registro?')) return;
    const lifecycleToken = registroLifecycleToken;
    try {
        const response = await fetch(API_REGISTROS, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ id: Number(id) })
        });
        if (!registroEstaAtivo(lifecycleToken)) return;
        const data = await response.json();
        if (!registroEstaAtivo(lifecycleToken)) return;
        if (!data.sucesso) { mostrarAlerta('error', data.mensagem || 'Falha ao excluir registro.'); return; }
        mostrarAlerta('success', data.mensagem || 'Registro excluído com sucesso.');
        await carregarRegistros();
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken)) return;
        console.error('[Registro] Erro ao excluir registro:', error);
        mostrarAlerta('error', 'Erro de conexão ao excluir registro.');
    }
}

// ── Limpar Formulário ─────────────────────────────────────────────────────────
function limparFormulario() {
    ultimoEnvioRegistro = null;
    convidadoAtivo = null;
    registroConfirmacaoDestinoPlacaKey = '';
    registroDestinoPlacaRecusadoKey = '';
    _invalidarCienciaRegistro();
    _resolverModalAlertaEtapa({ continuar: false, cancelado: true });
    registroStepperAtual = 1;
    registroEtapasConcluidas = new Set();
    registroAlertasEtapaVistos = new Set();
    registroInformativosEtapa = new Map();
    registroAlertasEspecificosPendentes = 0;
    registroBaseContextoAlertas = '';
    registroVisitanteDocumentoAnexado = null;
    registroVisitanteDocumentoPendente = '';
    registroVisitanteMensagemDetalhada = '';
    registroUltimoErro = '';
    if (registroEtapaToastTimer) clearTimeout(registroEtapaToastTimer);
    registroEtapaToastTimer = null;
    const form = document.getElementById('registroForm');
    if (form) form.reset();

    ['moradorId', 'veiculoId', 'visitanteIdRegistro'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    const campoIdempotency = document.getElementById('idempotencyKeyRegistro');
    if (campoIdempotency) campoIdempotency.value = gerarIdempotencyKey();
    ultimoEnvioRegistro = null;

    document.getElementById('camposMorador')?.style && (document.getElementById('camposMorador').style.display = 'none');
    document.getElementById('camposDestinoConvidado')?.style && (document.getElementById('camposDestinoConvidado').style.display = 'none');
    document.getElementById('camposIdentificacaoVisitante')?.style && (document.getElementById('camposIdentificacaoVisitante').style.display = 'none');
    document.getElementById('camposOcupantesVisitante')?.style && (document.getElementById('camposOcupantesVisitante').style.display = 'none');

    const boxV = document.getElementById('veiculoEncontrado');
    if (boxV) { boxV.style.display = 'none'; boxV.innerHTML = ''; }
    _definirCamposVeiculoBloqueados(false);
    const boxVis = document.getElementById('visitanteEncontrado');
    if (boxVis) { boxVis.style.display = 'none'; boxVis.classList.remove('visitante-documento-pendente'); boxVis.innerHTML = ''; }
    _limparOcupantes();
    document.getElementById('cadastroVisitanteNaChegada')?.remove();
    setCampoValue('convidadoIdRegistro', '');

    const selMorDest = document.getElementById('moradorDestinoRegistro');
    if (selMorDest) { selMorDest.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selMorDest.disabled = true; }

    const selMorSel = document.getElementById('moradorSelecionadoRegistro');
    if (selMorSel) { selMorSel.innerHTML = '<option value="">Selecione a unidade primeiro</option>'; selMorSel.disabled = true; }

    const iW = document.getElementById('moradorInfoWrap'); if (iW) iW.style.display = 'none';
    _resetarDependente();
    _atualizarBotoesAcesso();
    _atualizarModoRegistro();
    atualizarDataHoraAtual();
    _limparErroRegistro();
    _atualizarChipAlertasEtapa();
    _renderStepperRegistro();
    _atualizarEstadoBotoesEtapa();
}

// ── Placa ─────────────────────────────────────────────────────────────────────
function formatarPlacaInput(e) {
    let v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (v.length > 7) v = v.slice(0, 7);
    if (v.length > 3) v = `${v.slice(0, 3)}-${v.slice(3)}`;
    e.target.value = v;
}

function normalizarPlaca(placa) { return String(placa).toUpperCase().replace(/[^A-Z0-9]/g, ''); }

async function detectarVeiculoPorPlaca() {
    const placaInput = document.getElementById('placaRegistro');
    if (!placaInput) return;
    if (_isPedestre()) return;
    const placa = normalizarPlaca(placaInput.value);
    if (placa.length < 7) { esconderVeiculoEncontrado(); return; }
    const lifecycleToken = registroLifecycleToken;
    let respostaCompleta = false;

    placaInput.dataset.consultando = '1';
    _atualizarEstadoBotoesEtapa();
    try {
        const resp = await fetch(`${API_VEICULOS}?acao=consultar_placa&placa=${encodeURIComponent(placa)}`, { credentials: 'include' });
        if (!resp.ok) {
            throw new Error(`Consulta de veículo falhou (HTTP ${resp.status})`);
        }
        const data = await resp.json();
        if (!registroEstaAtivo(lifecycleToken) || !placaInput.isConnected || normalizarPlaca(placaInput.value) !== placa) return;
        if (!data || typeof data !== 'object') {
            throw new Error('Resposta inválida da consulta de veículo.');
        }
        if (_isPedestre()) return;
        const veiculo = data.sucesso && data.dados?.existe ? data.dados : null;
        if (!veiculo) {
            esconderVeiculoEncontrado();
            respostaCompleta = true;
            return;
        }

        const tipoAtual = document.getElementById('tipoRegistro')?.value || '';
        const eVisitante = _tipoExigeDocumento(tipoAtual);
        const destinoMorador = String(document.getElementById(eVisitante ? 'moradorDestinoRegistro' : 'moradorSelecionadoRegistro')?.value
            || (!eVisitante ? document.getElementById('moradorId')?.value : '') || '');
        const destinoUnidade = String(document.getElementById(eVisitante ? 'unidadeDestinoRegistro' : 'unidadeMoradorRegistro')?.value || '');
        const mesmoMorador = destinoMorador && String(veiculo.morador_id || '') === destinoMorador;
        const mesmaUnidade = destinoUnidade && veiculo.morador_unidade
            && destinoUnidade.trim().toLocaleLowerCase('pt-BR') === String(veiculo.morador_unidade).trim().toLocaleLowerCase('pt-BR');
        const contextoConfirmacao = _chaveDestinoPlacaAtual(placa);
        if (destinoMorador && (!mesmoMorador || !mesmaUnidade) && registroConfirmacaoDestinoPlacaKey !== contextoConfirmacao) {
            const manterDestino = window.confirm(`Esta placa está cadastrada para ${veiculo.morador_nome || 'outro morador'} da unidade ${veiculo.morador_unidade || 'não informada'}, mas o destino selecionado é ${destinoUnidade || 'não informado'}. Deseja continuar mantendo o destino atual?`);
            if (!registroEstaAtivo(lifecycleToken) || normalizarPlaca(placaInput.value) !== placa) return;
            if (!manterDestino) {
                registroDestinoPlacaRecusadoKey = contextoConfirmacao;
                _mostrarErroEtapaRegistro('Destino preservado. Revise a unidade/morador ou altere a placa antes de continuar.');
                _atualizarEstadoBotoesEtapa();
                return;
            }
            registroDestinoPlacaRecusadoKey = '';
            registroConfirmacaoDestinoPlacaKey = contextoConfirmacao;
        }

        setCampoValue('modeloRegistro', veiculo.modelo || '');
        setCampoValue('corRegistro', veiculo.cor || '');
        setCampoValue('veiculoId', veiculo.id || '');
        placaInput.dataset.veiculoPreenchido = '1';
        _definirCamposVeiculoBloqueados(true);
        const box = document.getElementById('veiculoEncontrado');
        if (box) {
            const depInfo = veiculo.dependente_id && veiculo.dependente_nome
                ? `${_esc(veiculo.dependente_nome)}, dependente de ${_esc(veiculo.morador_nome || '-')}`
                : _esc(veiculo.morador_nome || '-');
            box.innerHTML = `<i class="fas fa-check-circle"></i> Veículo cadastrado: ${_esc(veiculo.modelo || '-')}, ${depInfo} (Unidade ${_esc(veiculo.morador_unidade || '-')}). Dados do veículo preenchidos; destino atual preservado.`;
            box.style.display = 'block';
        }
        respostaCompleta = true;
    } catch (error) {
        if (!registroEstaAtivo(lifecycleToken) || !placaInput.isConnected || normalizarPlaca(placaInput.value) !== placa) return;
        console.error('[Registro] Erro ao consultar veículo por placa:', error);
        esconderVeiculoEncontrado();
    } finally {
        if (placaInput.isConnected) delete placaInput.dataset.consultando;
        if (registroEstaAtivo(lifecycleToken)) _atualizarEstadoBotoesEtapa();
        if (respostaCompleta) void _prevalidarVeiculoSePronto(lifecycleToken, placa);
    }
}

function _definirCamposVeiculoBloqueados(bloqueado) {
    ['modeloRegistro', 'corRegistro'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = bloqueado;
        el.classList.toggle('campo-veiculo-bloqueado', bloqueado);
    });
}

async function _prevalidarVeiculoSePronto(lifecycleToken, placa) {
    if (!registroEstaAtivo(lifecycleToken) || registroStepperAtual !== 4 || _isPedestre()
        || normalizarPlaca(document.getElementById('placaRegistro')?.value || '') !== placa
        || !_validarEtapa4BasicaRegistro() || _getTipoAcesso() !== 'Entrada') return;
    await _executarPreflightEtapaRegistro('VEICULO');
}

function esconderVeiculoEncontrado() {
    const placaInput = document.getElementById('placaRegistro');
    const eraPreenchido = placaInput?.dataset.veiculoPreenchido === '1';
    setCampoValue('veiculoId', '');
    if (eraPreenchido) {
        setCampoValue('modeloRegistro', '');
        setCampoValue('corRegistro', '');
    }
    _definirCamposVeiculoBloqueados(false);
    if (placaInput) delete placaInput.dataset.veiculoPreenchido;
    const box = document.getElementById('veiculoEncontrado');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    _atualizarEstadoBotoesEtapa();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setCampoValue(id, value) {
    const campo = document.getElementById(id);
    if (campo) campo.value = value ?? '';
}

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
    if (registroAlertaTimer) clearTimeout(registroAlertaTimer);
    registroAlertaTimer = null;
    const cores = {
        success: { bg: '#ecfdf5', cor: '#166534', borda: '#86efac', icone: 'fa-check-circle' },
        warning: { bg: '#fffbeb', cor: '#92400e', borda: '#fbbf24', icone: 'fa-exclamation-triangle' },
        error:   { bg: '#fef2f2', cor: '#991b1b', borda: '#fecaca', icone: 'fa-exclamation-circle' }
    };
    const c = cores[tipo] || cores.error;
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    // Cores aplicadas também inline (não só via classe CSS) para o aviso não
    // ficar sem cor caso o CSS da página esteja com cache desatualizado.
    box.innerHTML = `<div class="alert ${classe}" style="background:${c.bg};color:${c.cor};border:1px solid ${c.borda};" role="${tipo === 'error' ? 'alert' : 'status'}">
        <i class="fas ${c.icone}" style="color:${c.cor};"></i> ${_esc(mensagem)}${tipo === 'error' ? '<button type="button" class="registro-alert-close" aria-label="Fechar mensagem">&times;</button>' : ''}</div>`;
    if (tipo === 'error') {
        box.querySelector('.registro-alert-close')?.addEventListener('click', _limparErroRegistro);
    } else {
        registroAlertaTimer = setTimeout(() => { if (box.isConnected) box.innerHTML = ''; }, 6000);
    }
}

function _esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
