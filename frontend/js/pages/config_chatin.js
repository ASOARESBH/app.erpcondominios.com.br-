const API_CONFIG_CHATIN = '/api/api_chatin_config.php';

const CAMPOS_BOOLEANOS = [
    'ativo',
    'usuario_usuario',
    'usuario_morador',
    'morador_inicia_com_equipe',
    'morador_morador',
    'recurso_sons',
    'recurso_cutucar',
    'recurso_digitando',
    'recurso_emojis',
    'recurso_push_app',
];

let estado = null;
let listeners = [];
let controlador = null;
let podeConfigurar = false;
let instalado = false;

export function init() {
    controlador = new AbortController();
    podeConfigurar = false;
    instalado = false;
    _ouvir(document.getElementById('btnSalvarConfigChatin'), 'click', _salvar);
    _ouvir(document.getElementById('configChatinConteudo'), 'change', _aoMudarControle);
    document.querySelectorAll('.page-config-chatin [data-page]').forEach((elemento) => {
        if (elemento instanceof HTMLAnchorElement && elemento.hasAttribute('href')) return;
        _ouvir(elemento, 'click', () => window.AppRouter?.loadPage(elemento.dataset.page));
    });
    return _carregar();
}

export function destroy() {
    controlador?.abort();
    controlador = null;
    listeners.forEach(({ elemento, evento, fn }) => elemento.removeEventListener(evento, fn));
    listeners = [];
    estado = null;
    podeConfigurar = false;
    instalado = false;
}

function _ouvir(elemento, evento, fn) {
    if (!elemento) return;
    elemento.addEventListener(evento, fn);
    listeners.push({ elemento, evento, fn });
}

async function _carregar() {
    const requestController = controlador;
    _loading(true);
    _limparAlerta();
    try {
        const resposta = await fetch(`${API_CONFIG_CHATIN}?_ts=${Date.now()}`, {
            credentials: 'include',
            cache: 'no-store',
            signal: requestController?.signal,
        });
        const dados = await _lerJson(resposta);
        if (dados.codigo === 'NAO_INSTALADO') {
            instalado = false;
            podeConfigurar = Boolean(dados.dados?.pode_configurar);
            estado = _normalizarResposta(dados.dados);
            _renderizar();
            _alerta('warning', dados.mensagem || 'A configuração Chat-In ainda não foi instalada.');
            return;
        }
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível carregar a configuração Chat-In.');
        instalado = dados.dados?.instalado !== false;
        podeConfigurar = Boolean(dados.dados?.pode_configurar);
        estado = _normalizarResposta(dados.dados);
        _renderizar();
    } catch (erro) {
        if (erro.name === 'AbortError') return;
        console.error('[ConfigChatIn] Falha ao carregar:', erro);
        _alerta('error', erro.message || 'Não foi possível carregar a configuração Chat-In.');
    } finally {
        if (controlador === requestController) _loading(false);
    }
}

function _normalizarResposta(dados) {
    const recebido = dados?.config && typeof dados.config === 'object' ? dados.config : {};
    const config = {};
    CAMPOS_BOOLEANOS.forEach((campo) => { config[campo] = Boolean(recebido[campo]); });
    const ids = Array.isArray(recebido.grupos_visiveis_moradores) ? recebido.grupos_visiveis_moradores : [];
    const gruposVisiveis = [...new Set(ids.map((id) => Number(id)).filter((id) => Number.isSafeInteger(id) && id > 0))];
    return {
        config,
        grupos: Array.isArray(dados?.grupos) ? dados.grupos : [],
        gruposVisiveis,
        filtroMoradores: [],
    };
}

function _renderizar() {
    if (!estado) return;
    const conteudo = document.getElementById('configChatinConteudo');
    const grupos = document.getElementById('configChatinGrupos');
    const salvar = document.getElementById('btnSalvarConfigChatin');
    if (!conteudo || !grupos) return;

    conteudo.hidden = false;
    const desabilitado = !podeConfigurar || !instalado;
    conteudo.querySelectorAll('input[data-config-flag]').forEach((input) => {
        input.checked = Boolean(estado.config[input.dataset.configFlag]);
        input.disabled = desabilitado;
    });
    _renderizarGrupos(grupos, desabilitado);

    const avisoPermissao = document.getElementById('configChatinPermissao');
    if (avisoPermissao) avisoPermissao.hidden = podeConfigurar;
    if (salvar) {
        salvar.disabled = desabilitado;
        salvar.title = !podeConfigurar ? 'Você não possui permissão para configurar o Chat-In.' : (!instalado ? 'A migration do Chat-In ainda não foi instalada.' : '');
    }
}

function _renderizarGrupos(alvo, desabilitado) {
    alvo.replaceChildren();
    if (!estado.grupos.length) {
        const vazio = document.createElement('p');
        vazio.className = 'config-chatin-empty';
        vazio.textContent = 'Não há grupos ativos disponíveis para seleção neste condomínio.';
        alvo.appendChild(vazio);
        return;
    }
    estado.grupos.forEach((grupo) => {
        const label = document.createElement('label');
        label.className = 'config-chatin-group';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.grupoId = String(grupo.id);
        input.checked = estado.gruposVisiveis.includes(Number(grupo.id));
        input.disabled = desabilitado;
        input.setAttribute('aria-label', `Exibir ${String(grupo.nome ?? '')} para moradores`);
        const nome = document.createElement('span');
        nome.textContent = String(grupo.nome ?? 'Grupo');
        label.append(input, nome);
        alvo.appendChild(label);
    });
}

function _aoMudarControle(evento) {
    if (!estado || !evento.target) return;
    const flag = evento.target.dataset?.configFlag;
    if (CAMPOS_BOOLEANOS.includes(flag)) {
        estado.config[flag] = evento.target.checked;
        return;
    }
    if (evento.target.matches('[data-grupo-id]')) {
        const id = Number(evento.target.dataset.grupoId);
        if (evento.target.checked && !estado.gruposVisiveis.includes(id)) estado.gruposVisiveis.push(id);
        if (!evento.target.checked) estado.gruposVisiveis = estado.gruposVisiveis.filter((item) => item !== id);
    }
}

async function _salvar() {
    if (!podeConfigurar || !instalado || !estado) {
        _alerta('error', 'Você não possui permissão para alterar esta configuração, ou ela ainda não foi instalada.');
        return;
    }
    const botao = document.getElementById('btnSalvarConfigChatin');
    if (botao) {
        botao.disabled = true;
        botao.classList.add('is-loading');
        botao.textContent = 'Salvando…';
    }
    try {
        const config = {};
        CAMPOS_BOOLEANOS.forEach((campo) => { config[campo] = Boolean(estado.config[campo]); });
        const resposta = await fetch(API_CONFIG_CHATIN, {
            method: 'PUT',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ config, grupos_visiveis_moradores: estado.gruposVisiveis }),
            signal: controlador?.signal,
        });
        const dados = await _lerJson(resposta);
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível salvar a configuração Chat-In.');
        estado = _normalizarResposta(dados.dados);
        _renderizar();
        _alerta('success', 'Configuração Chat-In salva. As novas opções se aplicam sem encerrar conversas existentes.');
    } catch (erro) {
        if (erro.name === 'AbortError') return;
        console.error('[ConfigChatIn] Falha ao salvar:', erro);
        _alerta('error', erro.message || 'Não foi possível salvar a configuração Chat-In.');
    } finally {
        if (botao) {
            botao.classList.remove('is-loading');
            botao.replaceChildren();
            const icone = document.createElement('i');
            icone.className = 'fas fa-save';
            icone.setAttribute('aria-hidden', 'true');
            botao.append(icone, document.createTextNode(' Salvar configuração'));
            botao.disabled = !podeConfigurar || !instalado;
        }
    }
}

async function _lerJson(resposta) {
    const texto = await resposta.text();
    try { return texto ? JSON.parse(texto) : {}; }
    catch (_) { return { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
}

function _loading(ativo) {
    const elemento = document.getElementById('configChatinLoading');
    if (elemento) elemento.hidden = !ativo;
}

function _limparAlerta() {
    const alvo = document.getElementById('configChatinAlerta');
    if (alvo) alvo.replaceChildren();
}

function _alerta(tipo, mensagem) {
    const alvo = document.getElementById('configChatinAlerta');
    if (!alvo) return;
    alvo.replaceChildren();
    const caixa = document.createElement('div');
    caixa.className = `config-chatin-alert config-chatin-alert-${tipo}`;
    caixa.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    const icone = document.createElement('i');
    icone.className = `fas ${tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle'}`;
    icone.setAttribute('aria-hidden', 'true');
    const texto = document.createElement('span');
    texto.textContent = String(mensagem ?? '');
    caixa.append(icone, texto);
    alvo.appendChild(caixa);
}
