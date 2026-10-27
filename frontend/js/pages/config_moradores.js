const API_CONFIG_MORADORES = '../api/api_config_moradores.php';

let configuracao = { campos: { moradores: [], dependentes: [] }, opcoes: [] };
let podeConfigurar = false;
let listeners = [];

export async function init() {
    _ouvir('btnSalvarConfigMoradores', 'click', _salvar);
    _ouvir('btnVoltarSistemaMoradores', 'click', () => window.AppRouter?.loadPage('sistema'));
    document.querySelectorAll('.page-config-moradores [data-page]').forEach(elemento => {
        const fn = () => window.AppRouter?.loadPage(elemento.dataset.page);
        elemento.addEventListener('click', fn);
        listeners.push({ elemento, evento: 'click', fn });
    });
    document.querySelectorAll('[data-config-tab]').forEach(botao => {
        const fn = () => _selecionarAba(botao.dataset.configTab);
        botao.addEventListener('click', fn);
        listeners.push({ elemento: botao, evento: 'click', fn });
    });
    await _carregar();
}

export function destroy() {
    listeners.forEach(({ elemento, evento, fn }) => elemento.removeEventListener(evento, fn));
    listeners = [];
    configuracao = { campos: { moradores: [], dependentes: [] }, opcoes: [] };
    podeConfigurar = false;
}

function _ouvir(id, evento, fn) {
    const elemento = document.getElementById(id);
    if (!elemento) return;
    elemento.addEventListener(evento, fn);
    listeners.push({ elemento, evento, fn });
}

async function _carregar() {
    _loading(true);
    try {
        const resposta = await fetch(`${API_CONFIG_MORADORES}?_ts=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
        const dados = await _lerJson(resposta);
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível carregar os parâmetros.');
        configuracao = {
            campos: {
                moradores: Array.isArray(dados.dados?.campos?.moradores) ? dados.dados.campos.moradores : [],
                dependentes: Array.isArray(dados.dados?.campos?.dependentes) ? dados.dados.campos.dependentes : [],
            },
            opcoes: Array.isArray(dados.dados?.opcoes) ? dados.dados.opcoes : [],
        };
        podeConfigurar = Boolean(dados.dados?.pode_configurar);
        _renderizar();
        if (dados.dados?.modo_compatibilidade) _alerta('warning', 'A configuração está usando a regra padrão. Execute a migration de Moradores para gravar ajustes por condomínio.');
    } catch (erro) {
        console.error('[ConfigMoradores] Falha ao carregar:', erro);
        _alerta('error', erro.message || 'Não foi possível carregar os parâmetros de moradores.');
    } finally {
        _loading(false);
    }
}

function _renderizar() {
    _renderizarCampos('moradores', 'configMoradoresListaMoradores');
    _renderizarCampos('dependentes', 'configMoradoresListaDependentes');
    const listaOpcoes = document.getElementById('configMoradoresListaOpcoes');
    if (listaOpcoes) {
        listaOpcoes.innerHTML = configuracao.opcoes.map(opcao => `
            <label class="config-moradores-opcao ${opcao.habilitado ? 'ativo' : ''}">
                <span class="config-moradores-opcao-icone"><i class="fas fa-car-side"></i></span>
                <span class="config-moradores-opcao-texto"><strong>${_esc(opcao.rotulo)}</strong><small>${_esc(opcao.descricao)}</small></span>
                <span class="config-campo-switch"><input type="checkbox" data-config-opcao="${_esc(opcao.opcao)}" ${opcao.habilitado ? 'checked' : ''} ${podeConfigurar ? '' : 'disabled'}><span class="config-switch-slider"></span></span>
                <span class="config-campo-status">${opcao.habilitado ? 'Ativado' : 'Desativado'}</span>
            </label>`).join('');
        listaOpcoes.querySelectorAll('input[data-config-opcao]').forEach(input => {
            const fn = () => _atualizarEstadoVisual(input);
            input.addEventListener('change', fn);
            listeners.push({ elemento: input, evento: 'change', fn });
        });
    }
    const salvar = document.getElementById('btnSalvarConfigMoradores');
    if (salvar) {
        salvar.disabled = !podeConfigurar;
        salvar.title = podeConfigurar ? '' : 'Você não possui a permissão Sistema > Configurar.';
    }
}

function _renderizarCampos(grupo, alvoId) {
    const lista = document.getElementById(alvoId);
    if (!lista) return;
    lista.innerHTML = (configuracao.campos[grupo] || []).map(campo => {
        const icone = campo.tipo === 'email' ? 'fa-envelope' : campo.tipo === 'telefone' ? 'fa-phone' : campo.tipo === 'senha' ? 'fa-key' : campo.tipo === 'selecao' ? 'fa-list' : 'fa-align-left';
        const bloqueado = campo.fixo || !podeConfigurar;
        return `<label class="config-campo-item ${campo.obrigatorio ? 'ativo' : ''} ${campo.fixo ? 'fixo' : ''}">
            <span class="config-campo-icone"><i class="fas ${icone}"></i></span>
            <span class="config-campo-texto"><strong>${_esc(campo.rotulo)}${campo.fixo ? ' <em>estrutural</em>' : ''}</strong><small>${_esc(campo.descricao)}</small></span>
            <span class="config-campo-switch"><input type="checkbox" data-config-grupo="${_esc(grupo)}" data-config-campo="${_esc(campo.campo)}" ${campo.obrigatorio ? 'checked' : ''} ${bloqueado ? 'disabled' : ''}><span class="config-switch-slider"></span></span>
            <span class="config-campo-status">${campo.fixo ? 'Obrigatório' : campo.obrigatorio ? 'Obrigatório' : 'Opcional'}</span>
        </label>`;
    }).join('');
    lista.querySelectorAll('input[data-config-campo]').forEach(input => {
        const fn = () => _atualizarEstadoVisual(input);
        input.addEventListener('change', fn);
        listeners.push({ elemento: input, evento: 'change', fn });
    });
}

function _selecionarAba(grupo) {
    document.querySelectorAll('[data-config-tab]').forEach(botao => {
        const ativo = botao.dataset.configTab === grupo;
        botao.classList.toggle('active', ativo);
        botao.setAttribute('aria-selected', ativo ? 'true' : 'false');
    });
    document.querySelectorAll('[data-config-panel]').forEach(panel => {
        const ativo = panel.dataset.configPanel === grupo;
        panel.classList.toggle('active', ativo);
        panel.hidden = !ativo;
    });
}

function _atualizarEstadoVisual(input) {
    const item = input.closest('.config-campo-item, .config-moradores-opcao');
    if (!item) return;
    item.classList.toggle('ativo', input.checked);
    const status = item.querySelector('.config-campo-status');
    if (status) status.textContent = input.checked ? (input.dataset.configOpcao ? 'Ativado' : 'Obrigatório') : (input.dataset.configOpcao ? 'Desativado' : 'Opcional');
}

async function _salvar() {
    if (!podeConfigurar) {
        _alerta('error', 'Você não possui permissão para alterar os parâmetros de moradores.');
        return;
    }
    const botao = document.getElementById('btnSalvarConfigMoradores');
    const campos = Array.from(document.querySelectorAll('input[data-config-campo]')).map(input => ({
        grupo: input.dataset.configGrupo,
        campo: input.dataset.configCampo,
        obrigatorio: input.checked,
    }));
    const opcoes = Array.from(document.querySelectorAll('input[data-config-opcao]')).map(input => ({ opcao: input.dataset.configOpcao, habilitado: input.checked }));
    if (botao) { botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
    try {
        const resposta = await fetch(API_CONFIG_MORADORES, {
            method: 'PUT', credentials: 'include', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ campos, opcoes }),
        });
        const dados = await _lerJson(resposta);
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível salvar a configuração.');
        configuracao.campos = dados.dados?.campos || configuracao.campos;
        configuracao.opcoes = dados.dados?.opcoes || configuracao.opcoes;
        _renderizar();
        _alerta('success', 'Parâmetros de moradores atualizados. A nova regra já vale para os próximos cadastros.');
    } catch (erro) {
        console.error('[ConfigMoradores] Falha ao salvar:', erro);
        _alerta('error', erro.message || 'Não foi possível salvar a configuração.');
    } finally {
        if (botao) { botao.disabled = !podeConfigurar; botao.innerHTML = '<i class="fas fa-save"></i> Salvar configurações'; }
    }
}

async function _lerJson(resposta) {
    const texto = await resposta.text();
    try { return texto ? JSON.parse(texto) : {}; } catch (_) { return { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
}

function _loading(ativo) {
    const el = document.getElementById('configMoradoresLoading');
    if (el) el.style.display = ativo ? 'flex' : 'none';
}

function _alerta(tipo, mensagem) {
    const alvo = document.getElementById('configMoradoresAlerta');
    if (!alvo) return;
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    const icone = tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    alvo.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${_esc(mensagem)}</div>`;
}

function _esc(valor) {
    return String(valor ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
