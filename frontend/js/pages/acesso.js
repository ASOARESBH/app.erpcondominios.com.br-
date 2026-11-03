/**
 * Controle de Acesso Page Module
 */

const API_RFID = '../api/api_rfid.php';
const API_ALERTAS_UNIDADE = '../api/api_alertas_unidade.php';

let autoRefreshInterval = null;
let focusInterval = null;
let acessosCache = [];
let termoFiltroLocal = '';
let audioCtx = null;
let acessoCienciaTimer = null;
let acessoCienciaController = null;
let acessoCienciaVersion = 0;
let acessoCienciaState = { chave: '', contexto: null, resultado: null, token: '', modalData: null };
let acessoCienciaListeners = [];
let acessoCienciaModalResolver = null;
let acessoCienciaPreviousFocus = null;

export function init() {
    console.log('[Acesso] Inicializando...');

    setupForm();
    setupCienciaAcesso();
    setupAcessoTabs();
    setupActions();

    window.AcessoPage = {
        visualizar: abrirModalDetalhe
    };

    verificarStatusRFID();
    carregarUltimosAcessos();
    carregarUltimosLpr();
    carregarUltimosHub();
    iniciarAutoRefresh();
    iniciarFocusAssistido();
    prepararAudioContext();
}

export function destroy() {
    console.log('[Acesso] Limpando...');

    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    if (focusInterval) clearInterval(focusInterval);

    destroyCienciaAcesso();

    autoRefreshInterval = null;
    focusInterval = null;
    acessosCache = [];
    document.removeEventListener('keydown', onAcessoKeydown);
    delete window.AcessoPage;
    fecharModalDetalhe();
}

function setupForm() {
    const form = document.getElementById('acessoForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await verificarAcesso();
    });

    const input = document.getElementById('entradaAcesso');
    if (input) {
        input.addEventListener('input', () => {
            input.value = input.value.toUpperCase().trim();
        });
    }
}

function setupCienciaAcesso() {
    const input = document.getElementById('entradaAcesso');
    const sentido = document.getElementById('sentidoAcesso');
    const onChange = () => {
        invalidarCienciaAcesso();
        if (acessoCienciaTimer) clearTimeout(acessoCienciaTimer);
        acessoCienciaTimer = setTimeout(() => {
            acessoCienciaTimer = null;
            renderBannerCienciaAcesso(null);
        }, 450);
    };
    [input, sentido].forEach((elemento) => {
        if (!elemento) return;
        const evento = elemento === input ? 'input' : 'change';
        elemento.addEventListener(evento, onChange);
        acessoCienciaListeners.push({ elemento, evento, handler: onChange });
    });

    const cancelar = document.getElementById('btnAcessoCienciaCancelar');
    const confirmar = document.getElementById('btnAcessoCienciaConfirmar');
    const modal = document.getElementById('acessoCienciaModal');
    const onCancelar = () => resolverModalCienciaAcesso({ continuar: false });
    if (cancelar) {
        cancelar.addEventListener('click', onCancelar);
        acessoCienciaListeners.push({ elemento: cancelar, evento: 'click', handler: onCancelar });
    }
    if (confirmar) {
        confirmar.addEventListener('click', confirmarModalCienciaAcesso);
        acessoCienciaListeners.push({ elemento: confirmar, evento: 'click', handler: confirmarModalCienciaAcesso });
    }
    if (modal) {
        const onOutside = (evento) => { if (evento.target === modal) evento.preventDefault(); };
        modal.addEventListener('click', onOutside);
        acessoCienciaListeners.push({ elemento: modal, evento: 'click', handler: onOutside });
    }
    document.addEventListener('keydown', onCienciaAcessoKeydown);
    acessoCienciaListeners.push({ elemento: document, evento: 'keydown', handler: onCienciaAcessoKeydown });
}

function destroyCienciaAcesso() {
    if (acessoCienciaTimer) clearTimeout(acessoCienciaTimer);
    acessoCienciaTimer = null;
    acessoCienciaController?.abort();
    acessoCienciaController = null;
    acessoCienciaListeners.forEach(({ elemento, evento, handler }) => elemento.removeEventListener(evento, handler));
    acessoCienciaListeners = [];
    resolverModalCienciaAcesso({ continuar: false });
    acessoCienciaVersion += 1;
    acessoCienciaState = { chave: '', contexto: null, resultado: null, token: '', modalData: null };
    renderBannerCienciaAcesso(null);
}

function invalidarCienciaAcesso() {
    acessoCienciaVersion += 1;
    acessoCienciaController?.abort();
    acessoCienciaController = null;
    acessoCienciaState = { chave: '', contexto: null, resultado: null, token: '', modalData: null };
    resolverModalCienciaAcesso({ continuar: false });
    renderBannerCienciaAcesso(null);
}

function dadosCienciaAcesso(data) {
    return data?.dados && typeof data.dados === 'object' ? data.dados : {};
}

function alertasCienciaAcesso(data) {
    const dados = dadosCienciaAcesso(data);
    const alertas = Array.isArray(dados.alertas_unidade) && dados.alertas_unidade.length ? dados.alertas_unidade : dados.alertas;
    return Array.isArray(alertas) ? alertas : [];
}

function acaoAlertaAcesso(alerta) {
    return String(alerta?.acao || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_');
}

function exigeDecisaoAcesso(alerta) {
    return ['EXIGIR_CIENTE', 'CONTATAR_MORADOR', 'BLOQUEAR'].includes(acaoAlertaAcesso(alerta));
}

function resultadoExigeCienciaAcesso(data) {
    return alertasCienciaAcesso(data).some(exigeDecisaoAcesso);
}

function renderBannerCienciaAcesso(data) {
    const banner = document.getElementById('acessoCienciaBanner');
    if (!banner) return;
    const alertas = alertasCienciaAcesso(data);
    if (!alertas.length) { banner.hidden = true; banner.innerHTML = ''; return; }
    banner.innerHTML = `<div class="ciencia-banner__heading"><i class="fas fa-shield-alt"></i><span>Pré-validação do acesso</span></div>
        ${alertas.map((alerta) => `<div class="ciencia-banner__item"><strong>${escapeHtml(alerta.titulo || 'Alerta de acesso')}</strong>
            <span> · Nível: ${escapeHtml(alerta.nivel || 'AVISO')} · Ação: ${escapeHtml(alerta.acao || 'APENAS_AVISAR')}</span>
            <div>${escapeHtml(alerta.mensagem || '')}</div>
            ${alerta.instrucao_operador ? `<div><strong>Orientação:</strong> ${escapeHtml(alerta.instrucao_operador)}</div>` : ''}</div>`).join('')}`;
    banner.hidden = false;
}

function contextoSeguroRFID(data, payload) {
    const dados = dadosCienciaAcesso(data);
    const origem = dados.contexto_seguro && typeof dados.contexto_seguro === 'object'
        ? dados.contexto_seguro
        : (dados.contexto && typeof dados.contexto === 'object' ? dados.contexto : dados);
    if (!origem || String(origem.contexto || '').toUpperCase() !== 'CONTROLE_ACESSO') return null;
    const tipos = ['MORADOR', 'DEPENDENTE', 'VISITANTE', 'PRESTADOR', 'ENTREGADOR AUTORIZADO', 'FAMILIAR'];
    const tipoPessoa = String(origem.tipo_pessoa || '').trim();
    const identidade = origem.morador_id || origem.dependente_id || origem.visitante_id || origem.documento || origem.placa;
    const unidade = String(origem.unidade || '').trim();
    if (!unidade || !identidade || !tipos.some((tipo) => tipo === tipoPessoa.toUpperCase())) return null;
    return {
        contexto: 'CONTROLE_ACESSO',
        unidade,
        morador_id: origem.morador_id ?? null,
        dependente_id: origem.dependente_id ?? null,
        tipo_pessoa: tipoPessoa,
        visitante_id: origem.visitante_id ?? null,
        documento: origem.documento ?? null,
        tipo_documento: origem.tipo_documento ?? null,
        placa: origem.placa ?? null,
        tipo_acesso: origem.tipo_acesso || payload.tipo_acesso,
        data_hora: origem.data_hora || null
    };
}

async function resolverAlertaPendenteAcesso(data, payload) {
    const contexto = contextoSeguroRFID(data, payload);
    const alertas = alertasCienciaAcesso(data);
    if (!contexto || !alertas.length) {
        mostrarMensagem('error', 'A API sinalizou um alerta pendente sem contexto seguro suficiente. Acesso bloqueado.');
        return { continuar: false };
    }
    if (contexto.data_hora) payload.data_hora = contexto.data_hora;
    const pendente = {
        sucesso: false,
        dados: {
            ...(data.dados || {}),
            alertas,
            precisa_ciencia: true,
            pode_override: data.dados?.pode_override === true
        }
    };
    acessoCienciaState = {
        chave: JSON.stringify(contexto), contexto, resultado: pendente, token: '', modalData: pendente
    };
    renderBannerCienciaAcesso(pendente);
    const decisao = await abrirModalCienciaAcesso(pendente);
    if (!decisao.continuar) {
        if (decisao.negado) mostrarMensagem('error', 'O morador negou a liberação. Acesso não liberado.');
        return { continuar: false };
    }
    return { continuar: true, token: decisao.token };
}

function renderModalCienciaAcesso(data) {
    const modal = document.getElementById('acessoCienciaModal');
    const lista = document.getElementById('acessoCienciaAlertas');
    const descricao = document.getElementById('acessoCienciaDescricao');
    const erro = document.getElementById('acessoCienciaErro');
    const confirmar = document.getElementById('btnAcessoCienciaConfirmar');
    const alertas = alertasCienciaAcesso(data);
    const semOverride = alertas.some((alerta) => acaoAlertaAcesso(alerta) === 'BLOQUEAR') && !dadosCienciaAcesso(data).pode_override;
    if (!modal || !lista || !descricao || !confirmar) return false;
    descricao.textContent = semOverride
        ? 'Há um bloqueio de acesso e este operador não possui permissão para override. A entrada não poderá continuar.'
        : 'Leia os alertas e registre uma decisão explícita para continuar. Alertas informativos não exigem decisão.';
    lista.innerHTML = alertas.map((alerta, index) => {
        const acao = acaoAlertaAcesso(alerta);
        const classe = acao === 'BLOQUEAR' ? 'ciencia-alert--bloquear' : acao === 'CONTATAR_MORADOR' ? 'ciencia-alert--contatar' : '';
        let decisao = '';
        if (acao === 'EXIGIR_CIENTE') {
            decisao = `<div class="ciencia-decision"><label><input type="checkbox" data-acesso-ciencia-decisao="ciente" data-acesso-ciencia-index="${index}"> Confirmo que li este alerta e estou ciente das condições para liberar.</label>${String(alerta.nivel || '').toUpperCase() === 'CRITICO' ? `<textarea class="ciencia-justificativa" data-acesso-ciencia-justificativa="${index}" minlength="10" maxlength="500" placeholder="Justificativa obrigatória (mínimo de 10 caracteres)"></textarea>` : ''}</div>`;
        } else if (acao === 'CONTATAR_MORADOR') {
            decisao = `<div class="ciencia-decision"><strong>Após contatar o morador:</strong>
                <label><input type="radio" name="acesso-ciencia-contato-${index}" value="autoriza"> Morador autorizou a liberação</label>
                <label><input type="radio" name="acesso-ciencia-contato-${index}" value="nega"> Morador negou a liberação</label></div>`;
        } else if (acao === 'BLOQUEAR' && dadosCienciaAcesso(data).pode_override) {
            decisao = `<div class="ciencia-decision"><strong>Override de supervisor</strong>
                <label><input type="checkbox" data-acesso-ciencia-decisao="override" data-acesso-ciencia-index="${index}"> Confirmo que tenho permissão de supervisor para sobrescrever o bloqueio.</label>
                <textarea class="ciencia-justificativa" data-acesso-ciencia-justificativa="${index}" minlength="10" maxlength="1000" placeholder="Justificativa obrigatória (mínimo de 10 caracteres)"></textarea></div>`;
        }
        return `<article class="ciencia-alert ${classe}"><div class="ciencia-alert__meta"><span>Nível: ${escapeHtml(alerta.nivel || 'AVISO')}</span><span>Ação: ${escapeHtml(alerta.acao || 'APENAS_AVISAR')}</span></div>
            <strong class="ciencia-alert__title">${escapeHtml(alerta.titulo || 'Alerta de acesso')}</strong><p class="ciencia-alert__message">${escapeHtml(alerta.mensagem || '')}</p>
            ${alerta.instrucao_operador ? `<p class="ciencia-alert__instruction">Orientação: ${escapeHtml(alerta.instrucao_operador)}</p>` : ''}
            ${alerta.telefone_contato ? `<p class="ciencia-alert__instruction">Contato: ${escapeHtml(alerta.telefone_contato)}</p>` : ''}${decisao}</article>`;
    }).join('') + (alertas.some((alerta) => acaoAlertaAcesso(alerta) === 'BLOQUEAR') && dadosCienciaAcesso(data).pode_override
        ? '<div class="ciencia-decision"><label for="acessoCienciaSenhaSupervisor">Confirme sua senha de supervisor</label><input id="acessoCienciaSenhaSupervisor" type="password" autocomplete="current-password" maxlength="1024" required></div>'
        : '');
    if (erro) { erro.hidden = true; erro.textContent = ''; }
    confirmar.disabled = semOverride;
    return true;
}

function abrirModalCienciaAcesso(data) {
    const modal = document.getElementById('acessoCienciaModal');
    if (!modal || !renderModalCienciaAcesso(data)) return Promise.resolve({ continuar: false });
    acessoCienciaPreviousFocus = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('btnAcessoCienciaConfirmar')?.focus();
    return new Promise((resolve) => { acessoCienciaModalResolver = resolve; });
}

function resolverModalCienciaAcesso(resultado) {
    const resolver = acessoCienciaModalResolver;
    acessoCienciaModalResolver = null;
    const modal = document.getElementById('acessoCienciaModal');
    if (modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
    const senha = document.getElementById('acessoCienciaSenhaSupervisor');
    if (senha) senha.value = '';
    if (resolver) resolver(resultado || { continuar: false });
    if (acessoCienciaPreviousFocus?.isConnected) acessoCienciaPreviousFocus.focus();
    acessoCienciaPreviousFocus = null;
}

function onCienciaAcessoKeydown(evento) {
    const modal = document.getElementById('acessoCienciaModal');
    if (!modal || modal.hidden) return;
    if (evento.key === 'Escape') { evento.preventDefault(); evento.stopPropagation(); return; }
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

function erroModalCienciaAcesso(mensagem) {
    const erro = document.getElementById('acessoCienciaErro');
    if (erro) { erro.textContent = mensagem; erro.hidden = false; }
}

async function solicitarCienciaAcesso(acao, corpo, signal) {
    const endpoint = acao === 'verificar' ? 'verificar' : 'registrar_ciencia';
    const response = await fetch(`${API_ALERTAS_UNIDADE}?acao=${endpoint}`, {
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

async function confirmarModalCienciaAcesso() {
    const data = acessoCienciaState.modalData;
    if (!data) return;
    const decisoes = [];
    let negado = false;
    let senhaSupervisor = '';
    const alertas = alertasCienciaAcesso(data);
    for (let index = 0; index < alertas.length; index += 1) {
        const alerta = alertas[index];
        const acao = acaoAlertaAcesso(alerta);
        if (!exigeDecisaoAcesso(alerta)) continue;
        if (!alerta.id) { erroModalCienciaAcesso('O alerta não possui identificador para registrar a ciência. Acesso bloqueado por segurança.'); return; }
        if (acao === 'EXIGIR_CIENTE') {
            if (!document.querySelector(`[data-acesso-ciencia-decisao="ciente"][data-acesso-ciencia-index="${index}"]`)?.checked) { erroModalCienciaAcesso('Marque a confirmação explícita para cada alerta que exige ciência.'); return; }
            const justificativa = document.querySelector(`[data-acesso-ciencia-justificativa="${index}"]`)?.value.trim() || '';
            if (String(alerta.nivel || '').toUpperCase() === 'CRITICO' && justificativa.length < 10) { erroModalCienciaAcesso('A justificativa para liberar um alerta crítico deve ter pelo menos 10 caracteres.'); return; }
            decisoes.push({ alerta_id: alerta.id, decisao: 'CIENTE_LIBERADO', ...(justificativa ? { justificativa } : {}) });
        } else if (acao === 'CONTATAR_MORADOR') {
            const escolha = document.querySelector(`input[name="acesso-ciencia-contato-${index}"]:checked`)?.value;
            if (!escolha) { erroModalCienciaAcesso('Informe se o morador autorizou ou negou a liberação.'); return; }
            const decisao = escolha === 'autoriza' ? 'CONTATO_MORADOR_AUTORIZOU' : 'CONTATO_MORADOR_NEGOU';
            decisoes.push({ alerta_id: alerta.id, decisao });
            negado = negado || decisao === 'CONTATO_MORADOR_NEGOU';
        } else if (acao === 'BLOQUEAR') {
            if (!dadosCienciaAcesso(data).pode_override) { erroModalCienciaAcesso('Este bloqueio não permite override para o operador atual.'); return; }
            if (!document.querySelector(`[data-acesso-ciencia-decisao="override"][data-acesso-ciencia-index="${index}"]`)?.checked) { erroModalCienciaAcesso('Confirme a permissão de supervisor para sobrescrever o bloqueio.'); return; }
            const justificativa = document.querySelector(`[data-acesso-ciencia-justificativa="${index}"]`)?.value.trim() || '';
            if (justificativa.length < 10) { erroModalCienciaAcesso('A justificativa do override deve ter pelo menos 10 caracteres.'); return; }
            senhaSupervisor = document.getElementById('acessoCienciaSenhaSupervisor')?.value || '';
            if (!senhaSupervisor) { erroModalCienciaAcesso('Informe a senha do supervisor para confirmar o override.'); return; }
            decisoes.push({ alerta_id: alerta.id, decisao: 'OVERRIDE_SUPERVISOR', justificativa });
        }
    }
    const botao = document.getElementById('btnAcessoCienciaConfirmar');
    if (botao) { botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando ciência...'; }
    try {
        const resposta = await solicitarCienciaAcesso('registrar_ciencia', { ...acessoCienciaState.contexto, decisoes, ...(senhaSupervisor ? { senha_supervisor: senhaSupervisor } : {}) });
        if (negado && resposta.data?.codigo === 'ACESSO_NEGADO_ALERTA') {
            resolverModalCienciaAcesso({ continuar: false, negado: true });
            return;
        }
        const token = dadosCienciaAcesso(resposta.data).ciencia_token;
        if (!token || typeof token !== 'string') throw new Error('A API não retornou um token de ciência válido. Acesso bloqueado.');
        resolverModalCienciaAcesso(negado ? { continuar: false, negado: true } : { continuar: true, token });
    } catch (error) {
        erroModalCienciaAcesso(error?.message || 'Não foi possível registrar a ciência. Acesso bloqueado.');
        if (botao) { botao.disabled = false; botao.innerHTML = '<i class="fas fa-check"></i> Registrar ciência e continuar'; }
    }
}

function setupAcessoTabs() {
    const tabs = document.querySelectorAll('[data-acesso-tab]');
    const paineis = document.querySelectorAll('[data-acesso-painel]');
    tabs.forEach(tab => tab.addEventListener('click', () => {
        const alvo = tab.dataset.acessoTab;
        tabs.forEach(item => {
            const ativo = item === tab;
            item.classList.toggle('ativo', ativo);
            item.setAttribute('aria-selected', ativo ? 'true' : 'false');
        });
        paineis.forEach(painel => { painel.hidden = painel.dataset.acessoPainel !== alvo; });
        if (alvo === 'hub') carregarUltimosHub();
        if (alvo === 'lpr') carregarUltimosLpr();
        if (alvo === 'verificacao') document.getElementById('entradaAcesso')?.focus();
    }));
}

function setupActions() {
    const btnAtualizar = document.getElementById('btnAtualizarAcessos');
    if (btnAtualizar) {
        btnAtualizar.addEventListener('click', carregarUltimosAcessos);
    }
    const btnAtualizarLpr = document.getElementById('btnAtualizarLprAcesso');
    if (btnAtualizarLpr) btnAtualizarLpr.addEventListener('click', carregarUltimosLpr);
    const btnAtualizarHub = document.getElementById('btnAtualizarHubAcesso');
    if (btnAtualizarHub) btnAtualizarHub.addEventListener('click', carregarUltimosHub);

    const autoRefresh = document.getElementById('autoRefreshAcesso');
    if (autoRefresh) {
        autoRefresh.addEventListener('change', () => {
            if (autoRefresh.checked) {
                iniciarAutoRefresh();
            } else if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        });
    }

    const filtroLocal = document.getElementById('filtroAcessosLocal');
    if (filtroLocal) {
        filtroLocal.addEventListener('input', () => {
            termoFiltroLocal = filtroLocal.value || '';
            aplicarFiltroTabela();
        });
    }

    ['btnFecharModalAcesso', 'btnFecharModalAcessoFooter'].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', fecharModalDetalhe);
    });

    const modal = document.getElementById('modalDetalheAcesso');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) fecharModalDetalhe();
        });
    }

    document.addEventListener('keydown', onAcessoKeydown);
}

function iniciarAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);

    const autoRefresh = document.getElementById('autoRefreshAcesso');
    if (!autoRefresh || !autoRefresh.checked) return;

    autoRefreshInterval = setInterval(() => {
        carregarUltimosAcessos();
        carregarUltimosLpr();
        carregarUltimosHub();
    }, 5000);
}

function iniciarFocusAssistido() {
    const input = document.getElementById('entradaAcesso');
    if (!input) return;

    if (focusInterval) clearInterval(focusInterval);
    focusInterval = setInterval(() => {
        const modalCiencia = document.getElementById('acessoCienciaModal');
        if (modalCiencia && !modalCiencia.hidden) return;
        if (document.activeElement !== input) {
            input.focus();
        }
    }, 2000);
}

async function verificarStatusRFID() {
    const statusDiv = document.getElementById('rfidStatus');
    if (!statusDiv) return;

    try {
        const response = await fetch(`${API_RFID}?acao=testar_conexao`);
        const data = await response.json();

        if (data.sucesso) {
            statusDiv.className = 'rfid-status rfid-online';
            statusDiv.innerHTML = '<i class="fas fa-wifi"></i> RFID Control iD iDUHF: online';
        } else {
            statusDiv.className = 'rfid-status rfid-offline';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> RFID offline ou nao configurado';
        }
    } catch (error) {
        statusDiv.className = 'rfid-status rfid-offline';
        statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Erro ao verificar RFID';
        console.error('[Acesso] Erro status RFID:', error);
    }
}

async function verificarAcesso() {
    const input = document.getElementById('entradaAcesso');
    const btn = document.getElementById('btnValidarAcesso');
    if (!input || !btn) return;

    const entrada = input.value.trim().toUpperCase();
    const sentido = document.getElementById('sentidoAcesso')?.value || 'Entrada';
    if (!entrada) return;

    btn.disabled = true;
    limparFeedback();

    try {
        const payload = { tag: entrada, identificador: entrada, tipo_acesso: sentido };
        let retryAlertaPendente = false;
        let response = await fetch(`${API_RFID}?acao=verificar_tag`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload)
        });
        let texto = await response.text();
        let data;
        try { data = texto ? JSON.parse(texto) : {}; } catch (_) { throw new Error(`A API RFID não retornou JSON válido (HTTP ${response.status}).`); }

        const codigo = data?.codigo || data?.dados?.codigo;
        if (response.status === 409 && codigo === 'ALERTA_PENDENTE') {
            retryAlertaPendente = true;
            const ciencia = await resolverAlertaPendenteAcesso(data, payload);
            if (!ciencia.continuar) return;
            payload.ciencia_token = ciencia.token;
            response = await fetch(`${API_RFID}?acao=verificar_tag`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            texto = await response.text();
            try { data = texto ? JSON.parse(texto) : {}; } catch (_) { throw new Error(`A API RFID não retornou JSON válido no retry (HTTP ${response.status}).`); }
        } else if (Array.isArray(data?.dados?.alertas) && data.dados.alertas.length) {
            // HTTP 200 com alertas apenas informativos não abre modal nem
            // dispara uma segunda verificação; o aviso fica persistente.
            renderBannerCienciaAcesso(data);
            if (resultadoExigeCienciaAcesso(data)) {
                const ciencia = await resolverAlertaPendenteAcesso(data, payload);
                if (!ciencia.continuar) return;
                payload.ciencia_token = ciencia.token;
                retryAlertaPendente = true;
                response = await fetch(`${API_RFID}?acao=verificar_tag`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(payload)
                });
                texto = await response.text();
                try { data = texto ? JSON.parse(texto) : {}; } catch (_) { throw new Error(`A API RFID não retornou JSON válido no retry (HTTP ${response.status}).`); }
            }
        }

        if (response.status === 409 && (data?.codigo === 'ALERTA_PENDENTE' || data?.dados?.codigo === 'ALERTA_PENDENTE')) {
            mostrarMensagem('error', retryAlertaPendente
                ? 'Os alertas ainda estão pendentes após a ciência. Acesso bloqueado sem novo retry.'
                : 'Acesso pendente de ciência.');
            tocarSom('error');
        } else if (data.sucesso && data?.dados?.liberado) {
            mostrarMensagem('success', data.dados.mensagem || 'ACESSO LIBERADO');
            mostrarInfoAcesso(data.dados);
            tocarSom('success');

            setTimeout(() => {
                carregarUltimosAcessos();
            }, 800);
        } else {
            const mensagem = data?.dados?.mensagem || data?.mensagem || 'Acesso negado';
            mostrarMensagem('error', mensagem);
            tocarSom('error');
        }

        setTimeout(() => {
            input.value = '';
            input.focus();
        }, 2500);
    } catch (error) {
        console.error('[Acesso] Erro ao verificar acesso:', error);
        mostrarMensagem('error', 'Erro de comunicacao ao verificar acesso');
        tocarSom('error');
    } finally {
        btn.disabled = false;
    }
}

function mostrarMensagem(tipo, mensagem) {
    const box = document.getElementById('mensagemAcesso');
    if (!box) return;

    const classe = tipo === 'success' ? 'mensagem-sucesso' : 'mensagem-erro';
    const icone = tipo === 'success' ? 'fa-check-circle' : 'fa-times-circle';

    box.className = `mensagem-box ${classe}`;
    box.innerHTML = `<i class="fas ${icone}"></i> ${escapeHtml(mensagem)}`;
    box.style.display = 'block';
}

function mostrarInfoAcesso(dados) {
    const box = document.getElementById('infoAcesso');
    if (!box) return;

    const morador = escapeHtml(dados.morador || '-');
    const dependente = escapeHtml(dados.dependente || '');
    const unidade = escapeHtml(dados.unidade || '-');
    const tag = formatarTag(dados.tag_rfid || dados.tag);
    const placa = escapeHtml(dados.placa || '-');
    const modelo = escapeHtml(dados.modelo || '-');
    const tipo = escapeHtml(dados.tipo || '-');
    const sentido = escapeHtml(dados.tipo_acesso || dados.sentido || 'Entrada');
    const ultimoAcesso = escapeHtml(dados.ultimo_acesso || dados.data_hora || '-');

    let extra = '';
    if (tipo.toLowerCase() === 'dependente' && dependente) {
        extra = `<p><strong>Dependente:</strong> ${dependente}</p>`;
    }

    box.innerHTML = `
        <p><strong>Tipo:</strong> ${tipo}</p>
        <p><strong>Morador:</strong> ${morador}</p>
        ${extra}
        <p><strong>Unidade:</strong> ${unidade}</p>
        ${dependente ? `<p><strong>Dependente:</strong> ${dependente}</p>` : ''}
        <p><strong>Sentido:</strong> <span class="sentido-${sentido.toLowerCase()}">${sentido}</span></p>
        <p><strong>Último acesso:</strong> ${ultimoAcesso}</p>
        <p><strong>TAG RFID:</strong> ${tag}</p>
        <p><strong>Placa:</strong> ${placa}</p>
        <p><strong>Modelo:</strong> ${modelo}</p>
    `;

    box.style.display = 'block';
}

function limparFeedback() {
    const mensagem = document.getElementById('mensagemAcesso');
    const info = document.getElementById('infoAcesso');

    if (mensagem) {
        mensagem.style.display = 'none';
        mensagem.innerHTML = '';
        mensagem.className = 'mensagem-box';
    }

    if (info) {
        info.style.display = 'none';
        info.innerHTML = '';
    }
}

async function carregarUltimosAcessos() {
    const tbody = document.querySelector('#tabelaAcessos tbody');
    const loading = document.getElementById('loadingAcessos');
    if (!tbody || !loading) return;

    loading.style.display = 'block';

    try {
        const response = await fetch(`${API_RFID}?acao=ultimos_acessos&limite=20`);
        const data = await response.json();

        if (!data.sucesso) {
            renderMensagemTabela('Erro ao carregar acessos recentes.');
            return;
        }

        acessosCache = Array.isArray(data.dados) ? data.dados : [];
        aplicarFiltroTabela();
    } catch (error) {
        console.error('[Acesso] Erro ao carregar ultimos acessos:', error);
        renderMensagemTabela('Erro de conexao ao carregar acessos.');
    } finally {
        loading.style.display = 'none';
    }
}

async function carregarUltimosLpr() {
    const tbody = document.querySelector('#tabelaAcessosLpr tbody');
    const status = document.getElementById('lprAcessoStatus');
    if (!tbody) return;
    try {
        const response = await fetch('../api/api_monitoramento.php?action=acessos_recentes&limite=10', { credentials: 'include', cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'API LPR indisponível');
        const eventos = data.dados?.eventos || [];
        if (!eventos.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Nenhum veículo capturado no período.</td></tr>';
        } else {
            tbody.innerHTML = eventos.slice(0, 10).map(evento => {
                const confidence = evento.ocr_confidence != null ? `${Math.round(Number(evento.ocr_confidence) * 100)}%` : '—';
                const statusLabel = evento.match_status === 'veiculo_encontrado' ? 'Cadastrado' : 'Não cadastrado';
                const statusClass = evento.match_status === 'veiculo_encontrado' ? 'status-ok' : 'status-warn';
                return `<tr>
                    <td>${escapeHtml(evento.capturado_em || '—')}</td>
                    <td><span class="plate-badge">${escapeHtml(evento.placa_normalizada || evento.placa_raw || '—')}</span></td>
                    <td>${escapeHtml(evento.camera_external_key || '—')}</td>
                    <td><span class="tag-code">${escapeHtml(evento.tipo_evento || 'LPR')}</span></td>
                    <td>${evento.veiculo_id ? `Veículo #${Number(evento.veiculo_id)} / morador #${Number(evento.morador_id || 0)}` : 'Sem correlação cadastral'}</td>
                    <td>${confidence}</td>
                    <td><span class="status-pill ${statusClass}">${statusLabel}</span></td>
                </tr>`;
            }).join('');
        }
        if (status) status.innerHTML = `<i class="fas fa-circle" style="color:#10b981"></i> ${eventos.length} evento(s) na janela atual · retenção ${Number(data.dados?.retencao_dias || 30)} dias`;
        console.debug('[Acesso][LPR] Eventos carregados:', eventos.length);
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Não foi possível carregar os eventos LPR.</td></tr>';
        if (status) status.innerHTML = '<i class="fas fa-exclamation-triangle"></i> API LPR indisponível';
        console.error('[Acesso][LPR] Erro:', error);
    }
}

async function carregarUltimosHub() {
    const tbody = document.querySelector('#tabelaAcessosHub tbody');
    const status = document.getElementById('hubAcessoStatus');
    if (!tbody) return;
    try {
        const response = await fetch('../api/api_monitoramento.php?action=acessos_hub_recentes&limite=20', { credentials: 'include', cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error(data.mensagem || 'Hub multimodal indisponível');
        const eventos = data.dados?.eventos || [];
        if (!eventos.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Nenhum evento do hub nos últimos 30 dias.</td></tr>';
        } else {
            tbody.innerHTML = eventos.map(evento => {
                const confidence = evento.confianca != null ? `${Math.round(Number(evento.confianca) * 100)}%` : '—';
                const origem = ({ uhf: 'TAG/UHF', rfid: 'RFID', face_id: 'Face ID', lpr: 'LPR', frigate: 'Frigate' })[evento.source_type] || evento.source_type || 'Outro';
                return `<tr>
                    <td>${escapeHtml(evento.capturado_em || '—')}</td>
                    <td>${escapeHtml(evento.dispositivo_nome || evento.device_external_key || '—')}</td>
                    <td><span class="tag-code">${escapeHtml(origem)}</span></td>
                    <td><strong>${escapeHtml(evento.identifier_value || evento.placa_normalizada || 'Não identificado')}</strong></td>
                    <td>${escapeHtml(evento.direcao || 'indeterminado')}</td>
                    <td><span class="status-pill ${evento.decisao === 'denied' ? 'status-warn' : 'status-ok'}">${escapeHtml(evento.decisao || 'unknown')}</span></td>
                    <td>${confidence}</td>
                </tr>`;
            }).join('');
        }
        if (status) status.innerHTML = `<i class="fas fa-circle" style="color:#10b981"></i> ${eventos.length} evento(s) multimodal(is) · retenção ${Number(data.dados?.retencao_dias || 30)} dias`;
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Não foi possível carregar os eventos do hub.</td></tr>';
        if (status) status.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${escapeHtml(error.message)}`;
        console.error('[Acesso][Hub] Erro:', error);
    }
}

function aplicarFiltroTabela() {

    const termo = String(termoFiltroLocal || '').toLowerCase().trim();

    if (!termo) {
        renderTabela(acessosCache);
        return;
    }

    const filtrados = acessosCache.filter((a) => {
        const data = String(a.data || '').toLowerCase();
        const hora = String(a.hora || '').toLowerCase();
        const tag = String(a.tag_rfid || a.tag || '').toLowerCase();
        const placa = String(a.placa || '').toLowerCase();
        const modelo = String(a.modelo || '').toLowerCase();
        const unidade = String(a.unidade || '').toLowerCase();
        const morador = String(a.morador || '').toLowerCase();
        const status = String(a.status || '').toLowerCase();

        return (
            data.includes(termo) ||
            hora.includes(termo) ||
            tag.includes(termo) ||
            placa.includes(termo) ||
            modelo.includes(termo) ||
            unidade.includes(termo) ||
            morador.includes(termo) ||
            status.includes(termo)
        );
    });

    renderTabela(filtrados);
}

function renderTabela(acessos) {
    const tbody = document.querySelector('#tabelaAcessos tbody');
    if (!tbody) return;

    if (!acessos || acessos.length === 0) {
        renderMensagemTabela('Nenhum acesso registrado.');
        return;
    }

    tbody.innerHTML = acessos.map((a) => {
        const data = escapeHtml(a.data || '-');
        const hora = escapeHtml(a.hora || '-');
        const tag = formatarTag(a.tag_rfid || a.tag);
        const placa = escapeHtml(a.placa || '-');
        const modelo = escapeHtml(a.modelo || '-');
        const unidade = escapeHtml(a.unidade || '-');
        const morador = escapeHtml(a.morador || '-');
        const dependente = escapeHtml(a.dependente || '-');
        const sentido = escapeHtml(a.tipo_acesso || a.sentido || '-');
        const status = escapeHtml(a.status || '-');
        const statusClass = classificarStatus(a.status, a.liberado);
        const id = Number(a.id || 0);

        return `
            <tr>
                <td>${data}</td>
                <td><strong>${hora}</strong></td>
                <td>${tag}</td>
                <td><span class="plate-badge">${placa}</span></td>
                <td>${modelo}</td>
                <td>${unidade}</td>
                <td>${morador}</td>
                <td>${dependente}</td>
                <td><span class="sentido-badge sentido-${sentido.toLowerCase()} ">${sentido}</span></td>
                <td><span class="status-pill ${statusClass}">${status}</span></td>
                <td>
                    <button class="action-btn view" type="button" onclick="window.AcessoPage?.visualizar(${id})" title="Visualizar">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function renderMensagemTabela(mensagem) {
    const tbody = document.querySelector('#tabelaAcessos tbody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="9" class="empty-state">${escapeHtml(mensagem)}</td></tr>`;
}

function classificarStatus(status, liberado) {
    const s = String(status || '').toLowerCase();
    if (liberado === 1 || s.includes('liberado') || s.includes('permitido')) return 'status-ok';
    if (s.includes('negado') || s.includes('erro')) return 'status-deny';
    return 'status-warn';
}

function formatarTag(tag) {
    const valor = String(tag || '').trim();
    if (!valor) return '<span class="sem-tag">—</span>';
    return `<span class="tag-code">${escapeHtml(valor)}</span>`;
}

function abrirModalDetalhe(id) {
    const acesso = acessosCache.find((item) => Number(item.id) === Number(id));
    if (!acesso) return;

    const conteudo = document.getElementById('detalheAcessoConteudo');
    const modal = document.getElementById('modalDetalheAcesso');
    if (!conteudo || !modal) return;

    const tag = acesso.tag_rfid || acesso.tag || '';
    const campos = [
        ['Data', acesso.data || '—'],
        ['Hora', acesso.hora || '—'],
        ['TAG RFID', tag ? formatarTag(tag) : '<span class="sem-tag">—</span>', true],
        ['Placa', acesso.placa ? `<span class="plate-badge">${escapeHtml(acesso.placa)}</span>` : '<span class="sem-tag">—</span>', true],
        ['Modelo', acesso.modelo || '—'],
        ['Cor', acesso.cor || '—'],
        ['Tipo', acesso.tipo || '—'],
        ['Morador', acesso.morador || '—'],
        ['Dependente', acesso.dependente || '—'],
        ['Unidade', acesso.unidade || '—'],
        ['Origem da liberacao', acesso.origem_liberacao || acesso.origem || origemPorStatus(acesso) || '—'],
        ['Status', acesso.status || '—'],
        ['Usuario que liberou', acesso.usuario_liberou || '—'],
        ['Data/Hora', acesso.data_hora_formatada || acesso.data_hora || `${acesso.data || '—'} ${acesso.hora || ''}`.trim()]
    ];

    conteudo.innerHTML = campos.map(([label, value, raw]) => `
        <div class="detalhe-item">
            <label>${escapeHtml(label)}</label>
            <span>${raw ? value : escapeHtml(value)}</span>
        </div>
    `).join('');

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function fecharModalDetalhe() {
    const modal = document.getElementById('modalDetalheAcesso');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

function onAcessoKeydown(e) {
    if (e.key === 'Escape') fecharModalDetalhe();
}

function origemPorStatus(acesso) {
    const origem = String(acesso.origem || acesso.tipo || acesso.status || '').toLowerCase();
    if (origem.includes('controlid') || origem.includes('control id')) return 'ControleID';
    if (origem.includes('manual')) return 'Registro Manual';
    if (origem.includes('visitante')) return 'Visitante';
    if (origem.includes('rfid') || acesso.tag_rfid || acesso.tag) return 'TAG RFID';
    return '';
}

function somHabilitado() {
    const checkbox = document.getElementById('habilitarSomAcesso');
    return !!checkbox && checkbox.checked;
}

function prepararAudioContext() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    const ativar = () => {
        if (!audioCtx) {
            audioCtx = new AudioContextClass();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => {});
        }
        document.removeEventListener('pointerdown', ativar);
        document.removeEventListener('keydown', ativar);
    };

    document.addEventListener('pointerdown', ativar);
    document.addEventListener('keydown', ativar);
}

function tocarSom(tipo) {
    if (!somHabilitado()) return;

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    if (!audioCtx) {
        audioCtx = new AudioContextClass();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }

    if (tipo === 'success') {
        tocarBeep(820, 0.08, 0);
        tocarBeep(1080, 0.1, 0.1);
    } else {
        tocarBeep(320, 0.12, 0);
        tocarBeep(240, 0.16, 0.14);
    }
}

function tocarBeep(freq, duracao, atraso = 0) {
    if (!audioCtx) return;

    const inicio = audioCtx.currentTime + atraso;
    const fim = inicio + duracao;

    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(freq, inicio);

    gain.gain.setValueAtTime(0.0001, inicio);
    gain.gain.exponentialRampToValueAtTime(0.1, inicio + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, fim);

    osc.connect(gain);
    gain.connect(audioCtx.destination);

    osc.start(inicio);
    osc.stop(fim);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}
