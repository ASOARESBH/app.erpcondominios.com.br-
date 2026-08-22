const API_CONFIG_VISITANTES = '../api/api_config_visitantes.php';

let camposConfig = [];
let podeConfigurar = false;
let listeners = [];

export async function init() {
    console.debug('[ConfigVisitantes] Inicializando parâmetros de cadastro.');
    _ouvir('btnSalvarConfigVisitantes', 'click', _salvar);
    _ouvir('btnVoltarSistemaVisitantes', 'click', () => window.AppRouter?.loadPage('sistema'));
    document.querySelectorAll('.page-config-visitantes [data-page]').forEach(elemento => {
        const fn = () => window.AppRouter?.loadPage(elemento.dataset.page);
        elemento.addEventListener('click', fn);
        listeners.push({ elemento, evento: 'click', fn });
    });
    await _carregar();
}

export function destroy() {
    listeners.forEach(({ elemento, evento, fn }) => elemento.removeEventListener(evento, fn));
    listeners = [];
    camposConfig = [];
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
        const resposta = await fetch(API_CONFIG_VISITANTES, { credentials: 'include' });
        const dados = await _lerJson(resposta);
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível carregar os parâmetros.');
        camposConfig = Array.isArray(dados.dados?.campos) ? dados.dados.campos : [];
        podeConfigurar = Boolean(dados.dados?.pode_configurar);
        _renderizar();
        if (dados.dados?.modo_compatibilidade) {
            _alerta('warning', 'A configuração está usando a regra padrão. Execute a migration para gravar ajustes por condomínio.');
        }
    } catch (erro) {
        console.error('[ConfigVisitantes] Falha ao carregar:', erro);
        _alerta('error', erro.message || 'Não foi possível carregar a configuração de visitantes.');
    } finally {
        _loading(false);
    }
}

function _renderizar() {
    const lista = document.getElementById('configVisitantesLista');
    const salvar = document.getElementById('btnSalvarConfigVisitantes');
    if (!lista) return;
    lista.style.display = 'grid';
    lista.innerHTML = camposConfig.map(campo => {
        const marcado = campo.obrigatorio ? 'checked' : '';
        const desabilitado = podeConfigurar ? '' : 'disabled';
        const icone = campo.tipo === 'anexo' || campo.tipo === 'regra_anexo' ? 'fa-paperclip' : campo.tipo === 'email' ? 'fa-envelope' : campo.tipo === 'telefone' ? 'fa-phone' : 'fa-align-left';
        return `<label class="config-campo-item ${campo.obrigatorio ? 'ativo' : ''}">
            <span class="config-campo-icone"><i class="fas ${icone}"></i></span>
            <span class="config-campo-texto"><strong>${_esc(campo.rotulo)}</strong><small>${_esc(campo.descricao)}</small></span>
            <span class="config-campo-switch"><input type="checkbox" data-campo="${_esc(campo.campo)}" ${marcado} ${desabilitado}><span class="config-switch-slider"></span></span>
            <span class="config-campo-status">${campo.obrigatorio ? 'Obrigatório' : 'Opcional'}</span>
        </label>`;
    }).join('');
    lista.querySelectorAll('input[data-campo]').forEach(input => {
        const fn = () => _atualizarEstadoVisual(input);
        input.addEventListener('change', fn);
        listeners.push({ elemento: input, evento: 'change', fn });
    });
    if (salvar) {
        salvar.disabled = !podeConfigurar;
        salvar.title = podeConfigurar ? '' : 'Você não possui a permissão Sistema > Configurar.';
    }
}

function _atualizarEstadoVisual(input) {
    const item = input.closest('.config-campo-item');
    if (!item) return;
    item.classList.toggle('ativo', input.checked);
    const status = item.querySelector('.config-campo-status');
    if (status) status.textContent = input.checked ? 'Obrigatório' : 'Opcional';
}

async function _salvar() {
    if (!podeConfigurar) {
        _alerta('error', 'Você não possui permissão para alterar os campos obrigatórios.');
        return;
    }
    const botao = document.getElementById('btnSalvarConfigVisitantes');
    const campos = Array.from(document.querySelectorAll('input[data-campo]')).map(input => ({ campo: input.dataset.campo, obrigatorio: input.checked }));
    if (botao) { botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
    try {
        const resposta = await fetch(API_CONFIG_VISITANTES, {
            method: 'PUT',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ campos })
        });
        const dados = await _lerJson(resposta);
        if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível salvar a configuração.');
        camposConfig = Array.isArray(dados.dados?.campos) ? dados.dados.campos : camposConfig;
        _renderizar();
        _alerta('success', 'Campos obrigatórios atualizados para este condomínio. A nova regra já vale para os próximos cadastros.');
    } catch (erro) {
        console.error('[ConfigVisitantes] Falha ao salvar:', erro);
        _alerta('error', erro.message || 'Não foi possível salvar a configuração.');
    } finally {
        if (botao) { botao.disabled = !podeConfigurar; botao.innerHTML = '<i class="fas fa-save"></i> Salvar configurações'; }
    }
}

async function _lerJson(resposta) {
    const texto = await resposta.text();
    try { return texto ? JSON.parse(texto) : {}; }
    catch (_) { return { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
}

function _loading(ativo) {
    const el = document.getElementById('configVisitantesLoading');
    if (el) el.style.display = ativo ? 'flex' : 'none';
}

function _alerta(tipo, mensagem) {
    const alvo = document.getElementById('configVisitantesAlerta');
    if (!alvo) return;
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    const icone = tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    alvo.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${_esc(mensagem)}</div>`;
}

function _esc(valor) {
    return String(valor ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
