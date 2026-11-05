/**
 * Sistema > Controle de Acesso — canais de notificação da chegada de visitantes.
 * API: ../api/api_notificacao_canais.php (GET config / PUT {evento, canais}).
 * Canais E-mail, Aplicativo (push) e Chat-In; WhatsApp/SMS aparecem como "Em breve".
 */
const API_CANAIS = '../api/api_notificacao_canais.php';

let eventos = [];
let canais = [];
let podeConfigurar = false;
let instalado = true;
let listeners = [];

export async function init() {
    _ouvir(document.getElementById('btnSalvarConfigAcesso'), 'click', _salvar);
    _ouvir(document.getElementById('btnVoltarSistemaAcesso'), 'click', () => window.AppRouter?.loadPage('sistema'));
    document.querySelectorAll('.page-config-controle-acesso [data-page]').forEach(el =>
        _ouvir(el, 'click', () => window.AppRouter?.loadPage(el.dataset.page)));
    await _carregar();
}

export function destroy() {
    listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    listeners = []; eventos = []; canais = []; podeConfigurar = false;
}

function _ouvir(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    listeners.push({ el, ev, fn });
}

async function _carregar() {
    _loading(true);
    try {
        const resp = await fetch(API_CANAIS, { credentials: 'include' });
        const dados = await _lerJson(resp);
        if (!resp.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível carregar os canais.');
        eventos = dados.dados?.eventos || [];
        canais = dados.dados?.canais || [];
        podeConfigurar = Boolean(dados.dados?.pode_configurar);
        instalado = dados.dados?.instalado !== false;
        _renderizar();
        if (!instalado) _alerta('warning', 'Exibindo a configuração padrão. Execute sql/migration_chatin_mysql57.sql para gravar ajustes deste condomínio.');
    } catch (e) {
        console.error('[ConfigControleAcesso]', e);
        _alerta('error', e.message || 'Não foi possível carregar a configuração.');
    } finally {
        _loading(false);
    }
}

function _renderizar() {
    const alvo = document.getElementById('configAcessoEventos');
    const salvar = document.getElementById('btnSalvarConfigAcesso');
    if (!alvo) return;
    alvo.innerHTML = eventos.map(ev => `
        <section class="config-acesso-evento" data-evento="${_esc(ev.chave)}">
            <h3 class="config-acesso-evento-titulo"><i class="fas fa-user-clock"></i> ${_esc(ev.rotulo)}</h3>
            <p class="config-acesso-evento-desc">${_esc(ev.descricao)}</p>
            <div class="config-visitantes-lista" style="display:grid">
                ${canais.map(c => {
                    const ativo = Boolean(ev.canais_ativos?.[c.chave]);
                    const bloqueado = !c.disponivel || !podeConfigurar || !instalado;
                    const icone = c.icone_marca ? `fab ${_esc(c.icone)}` : `fas ${_esc(c.icone)}`;
                    const status = !c.disponivel ? 'Em breve' : (ativo ? 'Ativo' : 'Desativado');
                    return `<label class="config-campo-item ${ativo ? 'ativo' : ''} ${c.disponivel ? '' : 'indisponivel'}" ${c.disponivel ? '' : 'style="opacity:.6"'}>
                        <span class="config-campo-icone"><i class="${icone}"></i></span>
                        <span class="config-campo-texto"><strong>${_esc(c.rotulo)}</strong><small>${_esc(c.descricao)}</small></span>
                        <span class="config-campo-switch"><input type="checkbox" data-canal="${_esc(c.chave)}" ${ativo ? 'checked' : ''} ${bloqueado ? 'disabled' : ''}><span class="config-switch-slider"></span></span>
                        <span class="config-campo-status">${status}</span>
                    </label>`;
                }).join('')}
            </div>
        </section>`).join('');
    alvo.querySelectorAll('input[data-canal]').forEach(input => _ouvir(input, 'change', () => {
        const item = input.closest('.config-campo-item');
        item?.classList.toggle('ativo', input.checked);
        const st = item?.querySelector('.config-campo-status');
        if (st) st.textContent = input.checked ? 'Ativo' : 'Desativado';
    }));
    if (salvar) {
        salvar.disabled = !podeConfigurar || !instalado;
        salvar.title = !podeConfigurar ? 'Você não possui a permissão Sistema > Configurar.' : '';
    }
}

async function _salvar() {
    if (!podeConfigurar) return _alerta('error', 'Você não possui permissão para alterar os canais.');
    const botao = document.getElementById('btnSalvarConfigAcesso');
    if (botao) { botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
    try {
        for (const sec of document.querySelectorAll('.config-acesso-evento')) {
            const mapa = {};
            sec.querySelectorAll('input[data-canal]').forEach(i => { if (!i.disabled) mapa[i.dataset.canal] = i.checked; });
            const resp = await fetch(API_CANAIS, {
                method: 'PUT', credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ evento: sec.dataset.evento, canais: mapa })
            });
            const dados = await _lerJson(resp);
            if (!resp.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Não foi possível salvar.');
            const ev = eventos.find(e => e.chave === sec.dataset.evento);
            if (ev) ev.canais_ativos = dados.dados?.canais_ativos || ev.canais_ativos;
        }
        _renderizar();
        _alerta('success', 'Canais salvos. A regra vale a partir da próxima entrada registrada na portaria.');
    } catch (e) {
        _alerta('error', e.message || 'Não foi possível salvar.');
    } finally {
        if (botao) { botao.disabled = !podeConfigurar; botao.innerHTML = '<i class="fas fa-save"></i> Salvar canais'; }
    }
}

async function _lerJson(resp) {
    const t = await resp.text();
    try { return t ? JSON.parse(t) : {}; } catch (_) { return { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
}
function _loading(a) { const el = document.getElementById('configAcessoLoading'); if (el) el.style.display = a ? 'flex' : 'none'; }
function _alerta(tipo, msg) {
    const alvo = document.getElementById('configAcessoAlerta');
    if (!alvo) return;
    const classe = tipo === 'success' ? 'alert-success' : tipo === 'warning' ? 'alert-warning' : 'alert-error';
    const icone = tipo === 'success' ? 'fa-check-circle' : tipo === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    alvo.innerHTML = `<div class="alert ${classe}"><i class="fas ${icone}"></i> ${_esc(msg)}</div>`;
}
function _esc(v) { return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
