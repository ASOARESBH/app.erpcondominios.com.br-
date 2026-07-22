/**
 * Módulo CRM — Relacionamentos e Tarefas Diretivas
 */
'use strict';

const API = '../api/api_crm.php';

// ── Estado ────────────────────────────────────────────────────────────────────
let _uid       = 0;
let _unome     = '';
let _usuarios  = [];
let _moradores = [];
let _relAtual  = null;      // relacionamento aberto no detalhe
let _paginaAtual = 1;

// ── Lifecycle ─────────────────────────────────────────────────────────────────
export async function init() {
    _setupTabs();

    // Dados do usuário logado
    try {
        const r = await fetch('../api/api_usuario_logado.php', { credentials: 'include' });
        const d = await r.json();
        if (d.sucesso) { _uid = d.usuario?.id || 0; _unome = d.usuario?.nome || ''; }
    } catch {}

    document.getElementById('crm-remetente-display').textContent = _unome || 'Você';

    // Listeners
    document.getElementById('btnCrmNovo')?.addEventListener('click', abrirForm);
    document.getElementById('btnCrmBuscar')?.addEventListener('click', () => { _paginaAtual = 1; _carregarLista(); });
    document.getElementById('crm-busca')?.addEventListener('keydown', e => { if (e.key === 'Enter') { _paginaAtual = 1; _carregarLista(); } });
    ['crm-filtro-status','crm-filtro-dept','crm-filtro-prio'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => { _paginaAtual = 1; _carregarLista(); });
    });
    document.getElementById('btnCrmSalvarForm')?.addEventListener('click', _salvarForm);
    document.getElementById('btnUploadEnviar')?.addEventListener('click', _enviarAnexo);

    // Pré-carregar listas de destinatários
    _carregarDestinatarios();
    _carregarLista();
    _carregarSLA();

    window.CRMPage = {
        abrirForm,
        fecharForm,
        fecharDetalhe,
        fecharUpload,
        atualizarSelectDestino,
        enviarMensagem,
        chatKeydown,
        mudarStatus,
        mudarPrioridade,
        abrirUploadAnexo,
        editarRelacionamento,
        excluirRelacionamento,
        abrirRelacionamento,
    };
}

export function destroy() {
    delete window.CRMPage;
}

// ── Abas ──────────────────────────────────────────────────────────────────────
function _setupTabs() {
    document.querySelectorAll('.page-crm .tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.page-crm .tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.page-crm .tab-content').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab)?.classList.add('active');
            if (btn.dataset.tab === 'sla') _carregarSLA();
        });
    });
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function _toast(msg, type = 'info') {
    const colors = { success: '#16a34a', error: '#dc2626', info: '#2563eb', warning: '#d97706' };
    const t = Object.assign(document.createElement('div'), {
        textContent: msg,
        style: `position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:8px;
                color:#fff;font-size:14px;font-weight:500;background:${colors[type]||colors.info};
                box-shadow:0 4px 12px rgba(0,0,0,.2);animation:none;`,
    });
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ────────────────────────────────────────────────────────────────────────────
// LISTAR
// ────────────────────────────────────────────────────────────────────────────
async function _carregarLista() {
    const busca  = document.getElementById('crm-busca')?.value ?? '';
    const status = document.getElementById('crm-filtro-status')?.value ?? '';
    const dept   = document.getElementById('crm-filtro-dept')?.value ?? '';
    const prio   = document.getElementById('crm-filtro-prio')?.value ?? '';

    const wrap = document.getElementById('crm-lista');
    wrap.innerHTML = '<div class="crm-empty"><i class="fas fa-spinner fa-spin"></i><span>Carregando...</span></div>';

    const params = new URLSearchParams({ acao: 'listar', busca, status, departamento: dept, prioridade: prio, pagina: _paginaAtual });
    try {
        const r = await fetch(`${API}?${params}`, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        _renderLista(d.dados);
    } catch (e) {
        wrap.innerHTML = `<div class="crm-empty"><i class="fas fa-exclamation-triangle"></i><span>${_esc(e.message)}</span></div>`;
    }
}

function _renderLista(dados) {
    const wrap = document.getElementById('crm-lista');
    const pag  = document.getElementById('crm-paginacao');

    if (!dados.itens?.length) {
        wrap.innerHTML = '<div class="crm-empty"><i class="fas fa-inbox"></i><span>Nenhum relacionamento encontrado.</span></div>';
        pag.innerHTML = ''; return;
    }

    wrap.innerHTML = dados.itens.map(r => `
        <div class="crm-card prioridade-${r.prioridade}" onclick="window.CRMPage?.abrirRelacionamento(${r.id})">
            <div class="crm-card-header">
                <span class="crm-numero">${_esc(r.numero)}</span>
                <span class="crm-assunto">${_esc(r.assunto)}</span>
                <div class="crm-badges">
                    <span class="crm-badge status-${r.status}">${_labelStatus(r.status)}</span>
                    <span class="crm-badge prio-${r.prioridade}">${_labelPrio(r.prioridade)}</span>
                    <span class="crm-badge ${r.sla_status ? 'sla-' + r.sla_status : ''}">${_labelSLA(r.sla_status, r.prazo_fmt)}</span>
                    <span class="depto-badge">${_esc(r.departamento)}</span>
                </div>
            </div>
            <div class="crm-card-meta">
                <span><i class="fas fa-user"></i> ${_esc(r.remetente_nome)}</span>
                <span><i class="fas fa-arrow-right"></i> ${_esc(r.destinatario_nome)}</span>
                <span><i class="fas fa-comment"></i> ${r.total_interacoes} interaç${r.total_interacoes==1?'ão':'ões'}</span>
                ${r.total_anexos > 0 ? `<span><i class="fas fa-paperclip"></i> ${r.total_anexos} anexo${r.total_anexos>1?'s':''}</span>` : ''}
                <span><i class="fas fa-clock"></i> ${_esc(r.criado_fmt)}</span>
                ${r.prazo_fmt ? `<span><i class="fas fa-calendar-times"></i> Prazo: ${_esc(r.prazo_fmt)}</span>` : ''}
            </div>
        </div>
    `).join('');

    // Paginação
    const total = dados.total_paginas ?? 1;
    pag.innerHTML = '';
    if (total > 1) {
        for (let p = 1; p <= total; p++) {
            const btn = document.createElement('button');
            btn.textContent = p;
            btn.className = 'btn-secondary-modern' + (p === _paginaAtual ? ' active' : '');
            btn.style.cssText = 'min-width:36px;padding:6px;' + (p === _paginaAtual ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : '');
            btn.onclick = () => { _paginaAtual = p; _carregarLista(); };
            pag.appendChild(btn);
        }
    }
}

// ────────────────────────────────────────────────────────────────────────────
// SLA
// ────────────────────────────────────────────────────────────────────────────
async function _carregarSLA() {
    const wrap = document.getElementById('sla-conteudo');
    if (!wrap) return;
    wrap.innerHTML = '<div class="crm-empty"><i class="fas fa-spinner fa-spin"></i><span>Carregando...</span></div>';

    try {
        const r = await fetch(`${API}?acao=sla`, { credentials: 'include' });
        const d = await r.json();
        if (!d.sucesso) throw new Error(d.mensagem);
        _renderSLA(d.dados);

        // badge na aba
        const total_alertas = (d.dados.vencidos?.length || 0) + (d.dados.proximos?.length || 0);
        const badge = document.getElementById('sla-count-badge');
        if (badge) {
            badge.textContent = total_alertas;
            badge.style.display = total_alertas > 0 ? '' : 'none';
        }
    } catch (e) {
        wrap.innerHTML = `<div class="crm-empty"><i class="fas fa-exclamation-triangle"></i><span>${_esc(e.message)}</span></div>`;
    }
}

function _renderSLA(dados) {
    const wrap = document.getElementById('sla-conteudo');
    let html = '';

    const renderSecao = (lista, titulo, cor, icon) => {
        if (!lista?.length) return '';
        return `
            <div class="sla-section-title">
                <i class="${icon}" style="color:${cor};"></i> ${titulo}
                <span class="sla-count-badge count" style="background:${cor};color:#fff;">${lista.length}</span>
            </div>
            ${lista.map(r => `
                <div class="crm-card prioridade-${r.prioridade}" onclick="window.CRMPage?.abrirRelacionamento(${r.id})" style="cursor:pointer;">
                    <div class="crm-card-header">
                        <span class="crm-numero">${_esc(r.numero)}</span>
                        <span class="crm-assunto">${_esc(r.assunto)}</span>
                        <div class="crm-badges">
                            <span class="crm-badge sla-${r.sla_status}">${_labelSLAFull(r)}</span>
                            <span class="crm-badge status-${r.status}">${_labelStatus(r.status)}</span>
                            <span class="depto-badge">${_esc(r.departamento)}</span>
                        </div>
                    </div>
                    <div class="crm-card-meta">
                        <span><i class="fas fa-user"></i> ${_esc(r.remetente_nome)}</span>
                        <span><i class="fas fa-arrow-right"></i> ${_esc(r.destinatario_nome)}</span>
                        <span><i class="fas fa-calendar-times"></i> Prazo: ${_esc(r.prazo_fmt)}</span>
                    </div>
                </div>
            `).join('')}`;
    };

    html += renderSecao(dados.vencidos, 'Vencidos', '#ef4444', 'fas fa-times-circle');
    html += renderSecao(dados.proximos, 'Próximos do vencimento', '#f59e0b', 'fas fa-exclamation-triangle');
    html += renderSecao(dados.no_prazo, 'Dentro do prazo', '#16a34a', 'fas fa-check-circle');

    if (!html) html = '<div class="crm-empty"><i class="fas fa-check-circle" style="color:#16a34a;"></i><span>Nenhum item com prazo definido em aberto. Tudo em ordem!</span></div>';
    wrap.innerHTML = html;
}

// ────────────────────────────────────────────────────────────────────────────
// FORM: CRIAR / EDITAR
// ────────────────────────────────────────────────────────────────────────────
async function _carregarDestinatarios() {
    try {
        const [ru, rm] = await Promise.all([
            fetch(`${API}?acao=usuarios`, { credentials: 'include' }).then(r => r.json()),
            fetch(`${API}?acao=moradores`, { credentials: 'include' }).then(r => r.json()),
        ]);
        _usuarios  = ru.sucesso  ? ru.dados  : [];
        _moradores = rm.sucesso  ? rm.dados  : [];
        atualizarSelectDestino();
    } catch {}
}

function atualizarSelectDestino() {
    const tipo = document.getElementById('crm-dest-tipo')?.value ?? 'usuario';
    const sel  = document.getElementById('crm-dest-id');
    if (!sel) return;

    const lista = tipo === 'usuario' ? _usuarios : _moradores;
    sel.innerHTML = '<option value="">Selecione...</option>' +
        lista.map(i => `<option value="${i.id}">${_esc(i.nome)}${tipo==='usuario' && i.funcao ? ' — '+_esc(i.funcao) : (tipo==='morador' && i.unidade ? ' ('+_esc(i.unidade)+')' : '')}</option>`).join('');
}

function abrirForm(rel = null) {
    document.getElementById('crm-remetente-display').textContent = _unome || 'Você';
    document.getElementById('crm-edit-id').value = rel?.id || '';

    if (rel) {
        // Modo edição
        document.getElementById('crm-form-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar Relacionamento';
        document.getElementById('crm-dest-tipo').value    = rel.destinatario_tipo;
        atualizarSelectDestino();
        setTimeout(() => { document.getElementById('crm-dest-id').value = rel.destinatario_id; }, 50);
        document.getElementById('crm-departamento').value  = rel.departamento;
        document.getElementById('crm-prioridade').value    = rel.prioridade;
        document.getElementById('crm-assunto').value       = rel.assunto;
        document.getElementById('crm-descricao').value     = rel.descricao || '';
        if (rel.data_limite) {
            document.getElementById('crm-data-limite').value = rel.data_limite.replace(' ', 'T').slice(0,16);
        }
    } else {
        document.getElementById('crm-form-titulo').innerHTML = '<i class="fas fa-plus-circle"></i> Novo Relacionamento';
        document.getElementById('crm-edit-id').value = '';
        document.getElementById('crm-dest-tipo').value = 'usuario';
        atualizarSelectDestino();
        document.getElementById('crm-dest-id').value = '';
        document.getElementById('crm-departamento').value = '';
        document.getElementById('crm-prioridade').value = 'media';
        document.getElementById('crm-assunto').value = '';
        document.getElementById('crm-descricao').value = '';
        document.getElementById('crm-data-limite').value = '';
    }

    document.getElementById('crm-form-overlay').style.display = 'flex';
}

function fecharForm() {
    document.getElementById('crm-form-overlay').style.display = 'none';
}

async function _salvarForm() {
    const id      = document.getElementById('crm-edit-id').value;
    const dest_id = document.getElementById('crm-dest-id').value;
    const dest_tipo = document.getElementById('crm-dest-tipo').value;
    const dept    = document.getElementById('crm-departamento').value;
    const assunto = document.getElementById('crm-assunto').value.trim();
    const descricao = document.getElementById('crm-descricao').value.trim();
    const prio    = document.getElementById('crm-prioridade').value;
    const prazo   = document.getElementById('crm-data-limite').value;

    if (!assunto)  return _toast('Assunto é obrigatório', 'error');
    if (!dest_id)  return _toast('Selecione um destinatário', 'error');
    if (!dept)     return _toast('Selecione um departamento', 'error');

    const dest_nome_opt = document.getElementById('crm-dest-id').selectedOptions[0]?.text ?? '';

    const btn = document.getElementById('btnCrmSalvarForm');
    btn.disabled = true;

    const payload = { destinatario_tipo: dest_tipo, destinatario_id: parseInt(dest_id), destinatario_nome: dest_nome_opt.split(' —')[0].split(' (')[0], departamento: dept, assunto, descricao, prioridade: prio, data_limite: prazo || null };

    try {
        const acao = id ? `atualizar&id=${id}` : 'criar';
        const r = await fetch(`${API}?acao=${acao}`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) {
            fecharForm();
            _carregarLista();
            _carregarSLA();
            if (id) { fecharDetalhe(); }
        }
    } catch (e) { _toast(e.message, 'error'); }
    finally { btn.disabled = false; }
}

// ────────────────────────────────────────────────────────────────────────────
// DETALHE + CHAT
// ────────────────────────────────────────────────────────────────────────────
async function abrirRelacionamento(id) {
    try {
        const [rRel, rInter, rAnex] = await Promise.all([
            fetch(`${API}?acao=obter&id=${id}`, { credentials: 'include' }).then(r => r.json()),
            fetch(`${API}?acao=interacoes&id=${id}`, { credentials: 'include' }).then(r => r.json()),
            fetch(`${API}?acao=anexos&id=${id}`, { credentials: 'include' }).then(r => r.json()),
        ]);

        if (!rRel.sucesso) return _toast(rRel.mensagem, 'error');

        _relAtual = rRel.dados;
        _renderDetalhe(_relAtual, rInter.dados ?? [], rAnex.dados ?? []);
        document.getElementById('crm-detalhe-overlay').style.display = 'flex';
    } catch (e) { _toast(e.message, 'error'); }
}

function _renderDetalhe(rel, interacoes, anexos) {
    document.getElementById('det-numero').textContent  = rel.numero;
    document.getElementById('det-assunto').textContent = rel.assunto;

    const badge = document.getElementById('det-status-badge');
    badge.textContent  = _labelStatus(rel.status);
    badge.className    = `crm-badge status-${rel.status}`;

    document.getElementById('det-remetente').textContent    = rel.remetente_nome;
    document.getElementById('det-destinatario').textContent = `${rel.destinatario_nome} (${rel.destinatario_tipo === 'morador' ? 'Morador' : 'Usuário'})`;
    document.getElementById('det-departamento').textContent = rel.departamento;
    document.getElementById('det-prazo').innerHTML = rel.prazo_fmt
        ? `${rel.prazo_fmt} <span class="crm-badge sla-${rel.sla_status}" style="margin-left:4px;">${_labelSLAFull(rel)}</span>`
        : '—';
    document.getElementById('det-criado').textContent = rel.criado_fmt;

    document.getElementById('det-select-status').value = rel.status;
    document.getElementById('det-select-prio').value   = rel.prioridade;

    // Info bar
    document.getElementById('det-info-bar').innerHTML = `
        <span class="crm-badge prio-${rel.prioridade}">${_labelPrio(rel.prioridade)}</span>
        <span class="depto-badge">${_esc(rel.departamento)}</span>
        ${rel.prazo_fmt ? `<span class="crm-badge sla-${rel.sla_status}">${_labelSLAFull(rel)}</span>` : ''}
    `;

    // Bloquear input se finalizado/cancelado
    const bloqueado = rel.status === 'finalizado' || rel.status === 'cancelado';
    const inputWrap = document.getElementById('det-chat-input-wrap');
    if (inputWrap) inputWrap.style.display = bloqueado ? 'none' : '';

    _renderChat(interacoes);
    _renderAnexos(anexos);
}

function _renderChat(interacoes) {
    const container = document.getElementById('det-chat-msgs');
    if (!interacoes.length) {
        container.innerHTML = '<div style="text-align:center;color:var(--text-secondary,#94a3b8);font-size:12px;padding:20px;">Nenhuma interação ainda. Seja o primeiro a comentar!</div>';
        return;
    }

    container.innerHTML = interacoes.map(i => {
        const isMine   = i.usuario_id == _uid;
        const isSist   = i.tipo === 'sistema' || i.tipo === 'mudanca_status' || i.tipo === 'mudanca_prioridade';
        const cls      = isSist ? 'sistema' : (isMine ? 'mine' : 'other');
        const icon     = i.tipo === 'anexo' ? '<i class="fas fa-paperclip"></i> ' : '';
        return `
            <div class="crm-msg ${cls}">
                ${!isSist && !isMine ? `<div class="crm-msg-meta">${_esc(i.usuario_nome)}</div>` : ''}
                <div class="crm-msg-bubble">${icon}${_esc(i.mensagem)}</div>
                <div class="crm-msg-meta">${_esc(i.criado_fmt)}</div>
            </div>`;
    }).join('');

    // Scroll to bottom
    setTimeout(() => { container.scrollTop = container.scrollHeight; }, 50);
}

function _renderAnexos(anexos) {
    document.getElementById('det-anexos-count').textContent = anexos.length;
    const lista = document.getElementById('det-anexos-lista');
    if (!anexos.length) { lista.innerHTML = '<div style="font-size:11px;color:var(--text-secondary,#94a3b8);">Nenhum anexo</div>'; return; }

    const icones = { pdf: 'fa-file-pdf', jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image', doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel', txt: 'fa-file-alt' };
    lista.innerHTML = anexos.map(a => {
        const ext  = a.nome_original.split('.').pop().toLowerCase();
        const icon = icones[ext] || 'fa-file';
        return `
            <div class="crm-anexo-item">
                <i class="fas ${icon}"></i>
                <span class="crm-anexo-nome" title="${_esc(a.nome_original)}">${_esc(a.nome_documento)}</span>
                <a href="${API}?acao=download_anexo&id=${a.id}" target="_blank" style="color:#2563eb;font-size:12px;" title="Download"><i class="fas fa-download"></i></a>
            </div>`;
    }).join('');
}

function fecharDetalhe() {
    document.getElementById('crm-detalhe-overlay').style.display = 'none';
    _relAtual = null;
}

async function enviarMensagem() {
    if (!_relAtual) return;
    const input = document.getElementById('det-msg-input');
    const msg   = input.value.trim();
    if (!msg) return;

    input.value = '';
    try {
        const r = await fetch(`${API}?acao=interagir&id=${_relAtual.id}`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mensagem: msg }),
        });
        const d = await r.json();
        if (!d.sucesso) { _toast(d.mensagem, 'error'); return; }
        // Recarrega interações e status
        await _recarregarInteracoes();
        _carregarLista();
    } catch (e) { _toast(e.message, 'error'); }
}

function chatKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviarMensagem(); }
}

async function _recarregarInteracoes() {
    if (!_relAtual) return;
    try {
        const [ri, rRel] = await Promise.all([
            fetch(`${API}?acao=interacoes&id=${_relAtual.id}`, { credentials: 'include' }).then(r => r.json()),
            fetch(`${API}?acao=obter&id=${_relAtual.id}`, { credentials: 'include' }).then(r => r.json()),
        ]);
        if (ri.sucesso) _renderChat(ri.dados ?? []);
        if (rRel.sucesso) {
            _relAtual = rRel.dados;
            // Atualiza badge status
            const badge = document.getElementById('det-status-badge');
            badge.textContent = _labelStatus(_relAtual.status);
            badge.className = `crm-badge status-${_relAtual.status}`;
            document.getElementById('det-select-status').value = _relAtual.status;
        }
    } catch {}
}

// ── Mudar status ──────────────────────────────────────────────────────────────
async function mudarStatus() {
    if (!_relAtual) return;
    const novo = document.getElementById('det-select-status').value;
    try {
        const r = await fetch(`${API}?acao=mudar_status&id=${_relAtual.id}`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: novo }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { await _recarregarInteracoes(); _carregarLista(); _carregarSLA(); }
    } catch (e) { _toast(e.message, 'error'); }
}

// ── Mudar prioridade ──────────────────────────────────────────────────────────
async function mudarPrioridade() {
    if (!_relAtual) return;
    const nova = document.getElementById('det-select-prio').value;
    try {
        const r = await fetch(`${API}?acao=mudar_prioridade&id=${_relAtual.id}`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prioridade: nova }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { await _recarregarInteracoes(); _carregarLista(); }
    } catch (e) { _toast(e.message, 'error'); }
}

// ── Editar relacionamento ─────────────────────────────────────────────────────
function editarRelacionamento() {
    if (!_relAtual) return;
    fecharDetalhe();
    abrirForm(_relAtual);
}

// ── Excluir relacionamento ────────────────────────────────────────────────────
async function excluirRelacionamento() {
    if (!_relAtual) return;
    if (!confirm(`Deseja excluir o relacionamento ${_relAtual.numero}? Esta ação não pode ser desfeita.`)) return;
    try {
        const r = await fetch(`${API}?acao=excluir`, {
            method: 'DELETE', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: _relAtual.id }),
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) { fecharDetalhe(); _carregarLista(); }
    } catch (e) { _toast(e.message, 'error'); }
}

// ────────────────────────────────────────────────────────────────────────────
// UPLOAD ANEXO
// ────────────────────────────────────────────────────────────────────────────
function abrirUploadAnexo() {
    document.getElementById('upload-nome-doc').value = '';
    document.getElementById('upload-arquivo').value  = '';
    document.getElementById('crm-upload-overlay').style.display = 'flex';
}

function fecharUpload() {
    document.getElementById('crm-upload-overlay').style.display = 'none';
}

async function _enviarAnexo() {
    if (!_relAtual) return;
    const nome = document.getElementById('upload-nome-doc').value.trim();
    const file = document.getElementById('upload-arquivo').files[0];

    if (!nome)  return _toast('Nome do documento é obrigatório', 'error');
    if (!file)  return _toast('Selecione um arquivo', 'error');

    const fd = new FormData();
    fd.set('nome_documento', nome);
    fd.set('arquivo', file);

    const btn = document.getElementById('btnUploadEnviar');
    btn.disabled = true;

    try {
        const r = await fetch(`${API}?acao=upload_anexo&id=${_relAtual.id}`, {
            method: 'POST', credentials: 'include', body: fd,
        });
        const d = await r.json();
        _toast(d.mensagem, d.sucesso ? 'success' : 'error');
        if (d.sucesso) {
            fecharUpload();
            // Recarregar anexos + chat
            const [ra, ri] = await Promise.all([
                fetch(`${API}?acao=anexos&id=${_relAtual.id}`, { credentials: 'include' }).then(r => r.json()),
                fetch(`${API}?acao=interacoes&id=${_relAtual.id}`, { credentials: 'include' }).then(r => r.json()),
            ]);
            if (ra.sucesso) _renderAnexos(ra.dados ?? []);
            if (ri.sucesso) _renderChat(ri.dados ?? []);
        }
    } catch (e) { _toast(e.message, 'error'); }
    finally { btn.disabled = false; }
}

// ────────────────────────────────────────────────────────────────────────────
// UTILITÁRIOS
// ────────────────────────────────────────────────────────────────────────────
function _esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _labelStatus(s) {
    return { aberto:'Aberto', em_andamento:'Em Andamento', aguardando_retorno:'Aguardando Retorno', finalizado:'Finalizado', cancelado:'Cancelado' }[s] ?? s;
}

function _labelPrio(p) {
    return { baixa:'Baixa', media:'Média', alta:'Alta', urgente:'🔴 Urgente' }[p] ?? p;
}

function _labelSLA(sla, prazo) {
    if (!sla || sla === 'sem_prazo') return 'Sem prazo';
    if (sla === 'concluido') return 'Concluído';
    if (sla === 'vencido')   return '⚠ Vencido';
    if (sla === 'critico')   return '🔴 Crítico';
    if (sla === 'atencao')   return '⚡ Atenção';
    return prazo ? `Prazo: ${prazo}` : 'OK';
}

function _labelSLAFull(r) {
    const min = parseInt(r.minutos_restantes ?? 0);
    if (r.sla_status === 'vencido') {
        const dias = Math.abs(Math.floor(min / 1440));
        return `Vencido há ${dias > 0 ? dias + 'd' : Math.abs(Math.floor(min/60)) + 'h'}`;
    }
    if (r.sla_status === 'critico') return `Vence em ${min}min`;
    if (r.sla_status === 'atencao') return `Vence em ${Math.floor(min/60)}h`;
    return r.prazo_fmt ? `Prazo: ${r.prazo_fmt}` : 'OK';
}
