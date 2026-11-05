/**
 * eventos.js — Módulo Eventos (Administrativo)
 * Cadastro, edição, cancelamento e aviso aos moradores pelo Chat-In.
 * API: ../api/api_eventos.php (RBAC módulo "eventos").
 */
'use strict';

const API = '../api/api_eventos.php';

let ouvintes = [];
let estado = { periodo: 'proximos', status: '', eventos: [], unidades: [], cancelando: null, salvando: false };

export async function init() {
    estado = { periodo: 'proximos', status: '', eventos: [], unidades: [], cancelando: null, salvando: false };
    const raiz = document.querySelector('.page-eventos');
    if (!raiz) return;
    ouvir(raiz, 'click', aoClicar);
    ouvir(document.getElementById('evFiltroStatus'), 'change', e => { estado.status = e.target.value; carregar(); });
    raiz.querySelectorAll('input[name="evPublico"]').forEach(r => ouvir(r, 'change', atualizarPublico));
    ouvir(document.getElementById('evUnidadesBusca'), 'input', filtrarUnidades);
    ouvir(document, 'keydown', e => { if (e.key === 'Escape') fecharModais(); });
    await carregar();
    carregarOpcoes();
}

export function destroy() {
    ouvintes.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    ouvintes = [];
}

function ouvir(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    ouvintes.push({ el, ev, fn });
}

// ── API ──────────────────────────────────────────────────────────
async function chamar(acao, { metodo = 'GET', params = {}, corpo = null } = {}) {
    const qs = new URLSearchParams({ acao, ...params }).toString();
    const resp = await fetch(`${API}?${qs}`, {
        method: metodo, credentials: 'include', cache: 'no-store',
        headers: corpo ? { 'Content-Type': 'application/json' } : {},
        body: corpo ? JSON.stringify({ acao, ...corpo }) : undefined,
    });
    let dados = {};
    try { dados = await resp.json(); } catch (_) { dados = { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }
    if (!resp.ok || !dados.sucesso) {
        const e = new Error(dados.mensagem || (resp.status === 403 ? 'Você não tem permissão para esta ação em Eventos.' : 'Falha ao processar a solicitação.'));
        e.status = resp.status;
        throw e;
    }
    return dados;
}

async function carregar() {
    const alvo = document.getElementById('evLista');
    if (!alvo) return;
    alvo.innerHTML = '<div class="ev-vazio"><i class="fas fa-spinner fa-spin"></i> Carregando eventos…</div>';
    try {
        const r = await chamar('listar', { params: { periodo: estado.periodo, status: estado.status } });
        estado.eventos = r.dados?.eventos || [];
        renderLista();
    } catch (e) {
        alvo.innerHTML = `<div class="ev-vazio ev-erro"><i class="fas fa-exclamation-circle"></i> ${esc(e.message)}</div>`;
    }
}

async function carregarOpcoes() {
    try {
        const r = await chamar('opcoes');
        estado.unidades = r.dados?.unidades || [];
        if (r.dados && r.dados.chatin_instalado === false) {
            const chk = document.getElementById('evNotificar');
            if (chk) { chk.checked = false; chk.disabled = true; }
        }
        renderUnidades([]);
    } catch (e) { console.warn('[Eventos] opções:', e.message); }
}

// ── renderização ─────────────────────────────────────────────────
function renderLista() {
    const alvo = document.getElementById('evLista');
    if (!estado.eventos.length) {
        alvo.innerHTML = `<div class="ev-vazio"><i class="far fa-calendar"></i><p>${estado.periodo === 'proximos' ? 'Nenhum evento agendado.' : 'Nenhum evento encontrado.'}</p></div>`;
        return;
    }
    alvo.innerHTML = estado.eventos.map(ev => {
        const ini = data(ev.data_inicio);
        const cancelado = ev.status === 'CANCELADO';
        const publico = ev.publico === 'UNIDADES' ? `${ev.unidades.length} unidade(s): ${ev.unidades.slice(0, 6).join(', ')}${ev.unidades.length > 6 ? '…' : ''}` : 'Todos os moradores';
        const aviso = ev.notificado_chatin_em
            ? `<span class="ev-tag ev-tag-ok" title="Último aviso em ${esc(dataHora(ev.notificado_chatin_em))}"><i class="fas fa-comments"></i> Avisado (${ev.notificados_total})</span>`
            : '<span class="ev-tag"><i class="far fa-comments"></i> Sem aviso</span>';
        return `<article class="ev-card ${cancelado ? 'cancelado' : ''}">
            <div class="ev-data"><strong>${ini ? String(ini.getDate()).padStart(2, '0') : '--'}</strong><span>${ini ? ini.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '') : ''}</span><small>${ini ? ini.getFullYear() : ''}</small></div>
            <div class="ev-info">
                <h3>${esc(ev.titulo)} ${cancelado ? '<span class="ev-tag ev-tag-cancelado">Cancelado</span>' : ''}</h3>
                <p class="ev-meta"><i class="far fa-clock"></i> ${esc(periodo(ev))}${ev.local ? ` &nbsp;·&nbsp; <i class="fas fa-map-marker-alt"></i> ${esc(ev.local)}` : ''}</p>
                <p class="ev-meta"><i class="fas fa-users"></i> ${esc(publico)} &nbsp; ${aviso}</p>
                ${ev.descricao ? `<p class="ev-desc">${esc(ev.descricao)}</p>` : ''}
            </div>
            <div class="ev-acoes">
                ${cancelado ? '' : `
                <button type="button" class="ev-acao" data-ev-acao="editar" data-id="${ev.id}" title="Editar"><i class="fas fa-edit"></i></button>
                <button type="button" class="ev-acao" data-ev-acao="notificar" data-id="${ev.id}" title="Enviar aviso pelo Chat-In"><i class="fas fa-paper-plane"></i></button>
                <button type="button" class="ev-acao ev-acao-perigo" data-ev-acao="cancelar" data-id="${ev.id}" title="Cancelar evento"><i class="fas fa-ban"></i></button>`}
            </div>
        </article>`;
    }).join('');
}

function renderUnidades(selecionadas) {
    const alvo = document.getElementById('evUnidades');
    if (!alvo) return;
    const marcadas = new Set(selecionadas);
    alvo.innerHTML = estado.unidades.length
        ? estado.unidades.map(u => `<label class="ev-unidade" data-nome="${esc(String(u).toLowerCase())}"><input type="checkbox" value="${esc(u)}" ${marcadas.has(u) ? 'checked' : ''}> ${esc(u)}</label>`).join('')
        : '<small class="ev-ajuda">Nenhuma unidade com morador ativo.</small>';
}

function filtrarUnidades(e) {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#evUnidades .ev-unidade').forEach(l => { l.hidden = q !== '' && !l.dataset.nome.includes(q); });
}

function atualizarPublico() {
    const unidades = document.querySelector('input[name="evPublico"]:checked')?.value === 'UNIDADES';
    const wrap = document.getElementById('evUnidadesWrap');
    if (wrap) wrap.hidden = !unidades;
}

// ── ações ────────────────────────────────────────────────────────
function aoClicar(e) {
    const nav = e.target.closest('[data-ev-nav]');
    if (nav) { window.AppRouter?.loadPage(nav.dataset.evNav); return; }
    if (e.target.closest('[data-ev-fechar]') || e.target.classList.contains('ev-overlay')) { fecharModais(); return; }
    const seg = e.target.closest('[data-periodo]');
    if (seg) {
        document.querySelectorAll('.ev-segmento button').forEach(b => b.classList.toggle('ativo', b === seg));
        estado.periodo = seg.dataset.periodo;
        carregar();
        return;
    }
    if (e.target.closest('#evBtnNovo')) { abrirModal(null); return; }
    if (e.target.closest('#evSalvar')) { salvar(); return; }
    if (e.target.closest('#evConfirmarCancelar')) { confirmarCancelamento(); return; }
    const acao = e.target.closest('[data-ev-acao]');
    if (!acao) return;
    const ev = estado.eventos.find(x => x.id === Number(acao.dataset.id));
    if (!ev) return;
    if (acao.dataset.evAcao === 'editar') abrirModal(ev);
    else if (acao.dataset.evAcao === 'cancelar') abrirCancelamento(ev);
    else if (acao.dataset.evAcao === 'notificar') notificar(ev, acao);
}

function abrirModal(ev) {
    feedback('evModal');
    document.getElementById('evModalTitulo').innerHTML = ev ? '<i class="fas fa-edit"></i> Editar evento' : '<i class="fas fa-calendar-plus"></i> Novo evento';
    document.getElementById('evId').value = ev ? ev.id : '';
    document.getElementById('evTitulo').value = ev ? ev.titulo : '';
    document.getElementById('evInicio').value = ev ? paraInput(ev.data_inicio) : '';
    document.getElementById('evFim').value = ev && ev.data_fim ? paraInput(ev.data_fim) : '';
    document.getElementById('evLocal').value = ev ? (ev.local || '') : '';
    document.getElementById('evDescricao').value = ev ? (ev.descricao || '') : '';
    const publico = ev ? ev.publico : 'TODOS';
    document.querySelectorAll('input[name="evPublico"]').forEach(r => { r.checked = r.value === publico; });
    document.getElementById('evUnidadesBusca').value = '';
    renderUnidades(ev ? ev.unidades : []);
    atualizarPublico();
    const chk = document.getElementById('evNotificar');
    if (!chk.disabled) chk.checked = !ev;
    document.getElementById('evNotificarTexto').textContent = ev ? 'Enviar aviso atualizado pelo Chat-In' : 'Notificar pelo Chat-In';
    document.getElementById('evModal').hidden = false;
    setTimeout(() => document.getElementById('evTitulo').focus(), 30);
}

async function salvar() {
    if (estado.salvando) return;
    const titulo = document.getElementById('evTitulo').value.trim();
    const inicio = document.getElementById('evInicio').value;
    const fim = document.getElementById('evFim').value;
    const publico = document.querySelector('input[name="evPublico"]:checked')?.value || 'TODOS';
    const unidades = Array.from(document.querySelectorAll('#evUnidades input:checked')).map(i => i.value);
    if (!titulo) return feedback('evModal', 'Informe o título do evento.');
    if (!inicio) return feedback('evModal', 'Informe a data e hora de início.');
    if (fim && fim < inicio) return feedback('evModal', 'O término deve ser depois do início.');
    if (publico === 'UNIDADES' && !unidades.length) return feedback('evModal', 'Selecione ao menos uma unidade.');
    const botao = document.getElementById('evSalvar');
    estado.salvando = true;
    botao.disabled = true; botao.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando…';
    try {
        const r = await chamar('salvar', {
            metodo: 'POST',
            corpo: {
                id: Number(document.getElementById('evId').value) || 0,
                titulo, descricao: document.getElementById('evDescricao').value.trim(),
                local: document.getElementById('evLocal').value.trim(),
                data_inicio: inicio, data_fim: fim, publico, unidades,
                notificar_chatin: document.getElementById('evNotificar').checked,
            },
        });
        fecharModais();
        alerta('success', r.mensagem);
        carregar();
    } catch (e) {
        feedback('evModal', e.message);
    } finally {
        estado.salvando = false;
        botao.disabled = false; botao.innerHTML = '<i class="fas fa-save"></i> Salvar';
    }
}

function abrirCancelamento(ev) {
    estado.cancelando = ev;
    feedback('evModalCancelar');
    document.getElementById('evCancelarNome').textContent = `${ev.titulo} — ${periodo(ev)}`;
    document.getElementById('evMotivo').value = '';
    document.getElementById('evCancelarNotificar').checked = Boolean(ev.notificado_chatin_em);
    document.getElementById('evModalCancelar').hidden = false;
}

async function confirmarCancelamento() {
    const ev = estado.cancelando;
    if (!ev) return;
    const botao = document.getElementById('evConfirmarCancelar');
    botao.disabled = true;
    try {
        const r = await chamar('cancelar', {
            metodo: 'POST',
            corpo: { id: ev.id, motivo: document.getElementById('evMotivo').value.trim(), notificar_chatin: document.getElementById('evCancelarNotificar').checked },
        });
        fecharModais();
        alerta('success', r.mensagem);
        carregar();
    } catch (e) {
        feedback('evModalCancelar', e.message);
    } finally { botao.disabled = false; }
}

async function notificar(ev, botao) {
    const jaAvisado = ev.notificado_chatin_em ? `\nÚltimo aviso: ${dataHora(ev.notificado_chatin_em)} (${ev.notificados_total} morador(es)).` : '';
    if (!confirm(`Enviar o aviso de "${ev.titulo}" pelo Chat-In para ${ev.publico === 'UNIDADES' ? 'as unidades selecionadas' : 'todos os moradores'}?${jaAvisado}`)) return;
    botao.disabled = true;
    try {
        const r = await chamar('notificar', { metodo: 'POST', corpo: { id: ev.id } });
        alerta('success', r.mensagem);
        carregar();
    } catch (e) { alerta('error', e.message); }
    finally { botao.disabled = false; }
}

function fecharModais() {
    document.querySelectorAll('.page-eventos .ev-overlay').forEach(m => { m.hidden = true; });
    estado.cancelando = null;
}

// ── utilitários ──────────────────────────────────────────────────
function feedback(modalId, msg) {
    const alvo = document.querySelector(`#${modalId} [data-modal-feedback]`);
    if (alvo) alvo.innerHTML = msg ? `<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ${esc(msg)}</div>` : '';
}
function alerta(tipo, msg) {
    const alvo = document.getElementById('evAlerta');
    if (!alvo) return;
    alvo.innerHTML = `<div class="alert ${tipo === 'success' ? 'alert-success' : 'alert-error'}"><i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${esc(msg)}</div>`;
    clearTimeout(alerta._t);
    alerta._t = setTimeout(() => { alvo.innerHTML = ''; }, 7000);
}
function data(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
function dataHora(s) { const d = data(s); return d ? d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ''; }
function periodo(ev) {
    const i = data(ev.data_inicio), f = data(ev.data_fim);
    if (!i) return '';
    let txt = dataHora(ev.data_inicio);
    if (f) txt += f.toDateString() === i.toDateString() ? ' – ' + f.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ' até ' + dataHora(ev.data_fim);
    return txt;
}
function paraInput(s) { return String(s || '').replace(' ', 'T').slice(0, 16); }
function esc(v) { return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
