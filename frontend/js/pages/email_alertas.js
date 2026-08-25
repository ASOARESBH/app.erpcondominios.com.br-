/**
 * email_alertas.js — Modelos de Alerta e Histórico de Envios (por condomínio)
 * Sistema ERP Condomínio
 * v4.0 — configuração de SMTP/remetente removida (agora é global, no Painel Super-Admin)
 */

// ── Constantes ────────────────────────────────────────────────────────────────
const API = window.location.origin + '/api/api_email_alertas.php';

const MODULOS_LABELS = {
    sistema:    { label: 'Sistema',            icon: 'fas fa-cog',           cor: '#64748b' },
    hidrometro: { label: 'Hidrômetro',         icon: 'fas fa-tint',          cor: '#0ea5e9' },
    financeiro: { label: 'Financeiro',          icon: 'fas fa-dollar-sign',   cor: '#22c55e' },
    acesso:     { label: 'Controle de Acesso',  icon: 'fas fa-door-open',     cor: '#f59e0b' },
    rh:         { label: 'Recursos Humanos',    icon: 'fas fa-users',         cor: '#8b5cf6' },
    moradores:  { label: 'Moradores',           icon: 'fas fa-home',          cor: '#2563eb' },
};

// ── Estado do módulo ──────────────────────────────────────────────────────────
let _alertas     = [];
let _alertaAtual = null;
let _logPagina   = 1;

// ── Lifecycle: init / destroy ─────────────────────────────────────────────────
export function init() {
    console.log('[EmailAlertas] init() v4.0');
    _setupTabs();
    carregarDashboard();
    _carregarAlertas();

    // Expor API pública para handlers inline no HTML
    window.EmailAlertasPage = {
        trocarAba,
        filtrarAlertas,
        toggleAlerta,
        abrirModalAlerta,
        fecharModalAlerta,
        toggleDestEmail,
        inserirVariavel,
        previewAlerta,
        salvarAlerta,
        carregarLogs,
        limparLogs,
        _setLogPagina,
        carregarDashboard,
    };
}

export function destroy() {
    console.log('[EmailAlertas] destroy()');
    delete window.EmailAlertasPage;
    _alertas     = [];
    _alertaAtual = null;
    _logPagina   = 1;
}

// ── Helper: fetch seguro ──────────────────────────────────────────────────────
async function _fetchJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'include', ...options });
    const raw = await response.text();

    let data = null;
    if (raw && raw.trim()) {
        try {
            data = JSON.parse(raw);
        } catch (_) {
            const preview = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
            throw new Error(preview || 'Resposta inválida do servidor.');
        }
    }

    if (!response.ok) {
        throw new Error(data?.mensagem || `Erro HTTP ${response.status}`);
    }

    return data || {};
}

// ── Abas ──────────────────────────────────────────────────────────────────────
function _setupTabs() {
    document.querySelectorAll('.page-email-alertas .tab-btn').forEach(btn => {
        btn.addEventListener('click', () => trocarAba(btn.dataset.tab));
    });
}

function trocarAba(aba) {
    document.querySelectorAll('.page-email-alertas .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.page-email-alertas .tab-content').forEach(c => c.classList.remove('active'));

    const btn     = document.querySelector(`.page-email-alertas .tab-btn[data-tab="${aba}"]`);
    const content = document.getElementById(`tab-${aba}`);
    if (btn)     btn.classList.add('active');
    if (content) content.classList.add('active');

    if (aba === 'alertas' && _alertas.length === 0) _carregarAlertas();
    if (aba === 'logs') carregarLogs();
}

// ── Alertas: Carregar ─────────────────────────────────────────────────────────
async function _carregarAlertas() {
    const container = document.getElementById('alertas-container');
    if (container) container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando alertas...</p></div>';

    try {
        const d = await _fetchJson(`${API}?acao=alertas_listar`);
        if (d.sucesso) {
            _alertas = d.dados.alertas || [];
            _renderizarFiltrosModulo(d.dados.grupos || {});
            _renderizarAlertas(d.dados.grupos || {});
        } else {
            if (container) container.innerHTML =
                `<div class="page-card" style="color:#dc2626"><i class="fas fa-exclamation-triangle"></i> ${_esc(d.mensagem)}</div>`;
        }
    } catch (e) {
        if (container) container.innerHTML =
            `<div class="page-card" style="color:#dc2626"><i class="fas fa-exclamation-triangle"></i> Erro: ${_esc(e.message)}</div>`;
    }
}

function _renderizarFiltrosModulo(grupos) {
    const wrap = document.getElementById('alertas-filtros');
    if (!wrap) return;
    const extra = Object.keys(grupos).map(m => {
        const info = MODULOS_LABELS[m] || { label: m, icon: 'fas fa-circle', cor: '#64748b' };
        return `<button class="btn-modulo-filtro" data-modulo="${m}" onclick="EmailAlertasPage.filtrarAlertas('${m}')">
            <i class="${info.icon}" style="color:${info.cor}"></i> ${info.label}
        </button>`;
    }).join('');
    wrap.innerHTML = `<button class="btn-modulo-filtro active" data-modulo="" onclick="EmailAlertasPage.filtrarAlertas('')">Todos</button>${extra}`;
}

function _renderizarAlertas(grupos) {
    const container = document.getElementById('alertas-container');
    if (!container) return;
    if (!grupos || Object.keys(grupos).length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-bell-slash"></i><p>Nenhum alerta configurado.</p></div>';
        return;
    }
    container.innerHTML = Object.entries(grupos).map(([modulo, lista]) => {
        const info = MODULOS_LABELS[modulo] || { label: modulo, icon: 'fas fa-circle', cor: '#64748b' };
        const ativos = lista.filter(a => a.ativo == 1).length;
        return `
        <div class="alertas-grupo" data-modulo="${modulo}">
            <div class="alertas-grupo-titulo">
                <i class="${info.icon}" style="color:${info.cor}"></i>
                ${info.label}
                <span style="margin-left:auto;font-size:12px;color:#94a3b8;font-weight:400">
                    ${ativos}/${lista.length} ativo${ativos !== 1 ? 's' : ''}
                </span>
            </div>
            ${lista.map(a => `
            <div class="alerta-card ${a.ativo == 1 ? 'ativo' : 'inativo'}" id="alerta-item-${a.id}">
                <div class="alerta-info">
                    <div class="alerta-nome">${_esc(a.nome)}</div>
                    <div class="alerta-desc">${_esc(a.descricao || '')}</div>
                    <div class="alerta-assunto">
                        <i class="fas fa-envelope"></i>
                        ${a.assunto ? _esc(a.assunto) : '<em>Assunto não configurado</em>'}
                    </div>
                </div>
                <div class="alerta-acoes">
                    <button class="btn-secondary-modern btn-sm" onclick="EmailAlertasPage.abrirModalAlerta(${a.id})" title="Configurar">
                        <i class="fas fa-edit"></i> Configurar
                    </button>
                    <label class="toggle-switch" title="${a.ativo == 1 ? 'Ativo' : 'Inativo'}">
                        <input type="checkbox" ${a.ativo == 1 ? 'checked' : ''}
                               onchange="EmailAlertasPage.toggleAlerta(${a.id}, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>`).join('')}
        </div>`;
    }).join('');
}

function filtrarAlertas(modulo) {
    document.querySelectorAll('.page-email-alertas .btn-modulo-filtro').forEach(b => {
        b.classList.toggle('active', b.dataset.modulo === modulo);
    });
    document.querySelectorAll('.alertas-grupo').forEach(g => {
        g.style.display = (!modulo || g.dataset.modulo === modulo) ? 'block' : 'none';
    });
}

// ── Alerta: Toggle ────────────────────────────────────────────────────────────
async function toggleAlerta(id, ativo) {
    const form = new FormData();
    form.append('acao',  'alerta_toggle');
    form.append('id',    id);
    form.append('ativo', ativo ? 1 : 0);
    try {
        const d = await _fetchJson(API, { method: 'POST', body: form });
        _toast(d.mensagem, d.sucesso ? 'sucesso' : 'erro');
        if (d.sucesso) {
            const a = _alertas.find(x => x.id == id);
            if (a) {
                a.ativo = ativo ? 1 : 0;
                const card = document.getElementById(`alerta-item-${id}`);
                if (card) { card.classList.toggle('ativo', !!ativo); card.classList.toggle('inativo', !ativo); }
            }
            carregarDashboard();
        }
    } catch (e) { _toast('Erro: ' + e.message, 'erro'); }
}

// ── Modal: Alerta ─────────────────────────────────────────────────────────────
function abrirModalAlerta(id) {
    _alertaAtual = _alertas.find(a => a.id == id);
    if (!_alertaAtual) return;

    _setText('modal-alerta-titulo', 'Configurar: ' + _alertaAtual.nome);
    _val('modal-alerta-id',    _alertaAtual.id);
    _val('modal-assunto',      _alertaAtual.assunto || '');
    _val('modal-corpo',        _alertaAtual.corpo_html || '');
    _val('modal-dest-email',   _alertaAtual.destinatario_email || '');
    _val('modal-cc',           _alertaAtual.cc_emails || '');
    const destTipo = document.getElementById('modal-dest-tipo');
    if (destTipo) destTipo.value = _alertaAtual.destinatario_tipo || 'admin';

    const prevWrap = document.getElementById('modal-preview-wrap');
    if (prevWrap) prevWrap.style.display = 'none';

    toggleDestEmail();

    const vars = Array.isArray(_alertaAtual.variaveis) ? _alertaAtual.variaveis : [];
    const varLista = document.getElementById('modal-variaveis-lista');
    if (varLista) {
        varLista.innerHTML = vars.map(v =>
            `<span class="variavel-tag" onclick="EmailAlertasPage.inserirVariavel('{{${v}}}')">{{${v}}}</span>`
        ).join('');
    }

    const overlay = document.getElementById('modal-alerta');
    if (overlay) { overlay.classList.add('open'); document.body.style.overflow = 'hidden'; }
}

function fecharModalAlerta() {
    const overlay = document.getElementById('modal-alerta');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
    _alertaAtual = null;
}

function toggleDestEmail() {
    const tipo = document.getElementById('modal-dest-tipo')?.value;
    const wrap = document.getElementById('modal-dest-email-wrap');
    if (wrap) wrap.style.display = tipo === 'email_fixo' ? 'block' : 'none';
}

function inserirVariavel(variavel) {
    const ta = document.getElementById('modal-corpo');
    if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + variavel + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + variavel.length;
    ta.focus();
}

function previewAlerta() {
    const corpo = _get('modal-corpo');
    const wrap  = document.getElementById('modal-preview-wrap');
    const cont  = document.getElementById('modal-preview-conteudo');
    if (!wrap || !cont) return;
    if (!corpo) {
        cont.innerHTML = '<div style="padding:16px;color:#94a3b8;font-size:13px">Corpo em branco — o sistema vai montar um e-mail automático simples com as variáveis preenchidas.</div>';
        wrap.style.display = 'block';
        return;
    }
    const exemplo = corpo
        .replace(/\{\{nome_usuario\}\}/g, 'João da Silva')
        .replace(/\{\{nome_morador\}\}/g, 'Maria Oliveira')
        .replace(/\{\{unidade\}\}/g, 'Gleba 42')
        .replace(/\{\{sistema_nome\}\}/g, 'ERP Condomínios')
        .replace(/\{\{logo_url\}\}/g, window.location.origin + '/assets/img/logo.png')
        .replace(/\{\{data_envio\}\}/g, new Date().toLocaleString('pt-BR'))
        .replace(/\{\{[\w_]+\}\}/g, '<span style="background:#fef3c7;padding:0 4px;border-radius:3px;font-size:11px">[variável]</span>');
    cont.innerHTML = exemplo;
    wrap.style.display = 'block';
}

async function salvarAlerta() {
    const id = _get('modal-alerta-id');
    if (!id) return;

    const form = new FormData();
    form.append('acao',               'alerta_salvar');
    form.append('id',                 id);
    form.append('assunto',            _get('modal-assunto'));
    form.append('corpo_html',         _get('modal-corpo'));
    form.append('destinatario_tipo',  document.getElementById('modal-dest-tipo')?.value || 'admin');
    form.append('destinatario_email', _get('modal-dest-email'));
    form.append('cc_emails',          _get('modal-cc'));

    try {
        const d = await _fetchJson(API, { method: 'POST', body: form });
        _toast(d.mensagem, d.sucesso ? 'sucesso' : 'erro');
        if (d.sucesso) { fecharModalAlerta(); _carregarAlertas(); }
    } catch (e) { _toast('Erro: ' + e.message, 'erro'); }
}

// ── Log de Envios ─────────────────────────────────────────────────────────────
async function carregarLogs() {
    const status = document.getElementById('log-filtro-status')?.value || '';
    const tipo   = document.getElementById('log-filtro-tipo')?.value   || '';
    const busca  = document.getElementById('log-busca')?.value         || '';
    const params = new URLSearchParams({ acao: 'log_listar', pagina: _logPagina, status, tipo, busca });

    try {
        const d = await _fetchJson(`${API}?${params}`);
        if (d.sucesso) _renderizarLogs(d.dados);
    } catch (e) {
        const tbody = document.getElementById('log-tbody');
        if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:24px;color:#dc2626">
            <i class="fas fa-exclamation-triangle"></i> ${_esc(e.message)}</td></tr>`;
    }
}

function _renderizarLogs(dados) {
    const tbody = document.getElementById('log-tbody');
    if (!tbody) return;

    if (!dados.logs || dados.logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Nenhum registro encontrado.</td></tr>';
        return;
    }

    tbody.innerHTML = dados.logs.map(l => {
        const statusBadge = l.status === 'enviado'
            ? '<span class="badge-log enviado"><i class="fas fa-check"></i> Enviado</span>'
            : l.status === 'erro'
            ? '<span class="badge-log erro"><i class="fas fa-times"></i> Erro</span>'
            : '<span class="badge-log pendente"><i class="fas fa-clock"></i> Pendente</span>';

        const dataFmt = l.data_envio ? new Date(l.data_envio).toLocaleString('pt-BR') : '—';

        return `<tr>
            <td style="white-space:nowrap;font-size:12px">${dataFmt}</td>
            <td>
                <span style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:12px">${_esc(l.tipo || '—')}</span>
                ${l.alerta_codigo ? `<br><small style="color:#94a3b8;font-size:11px">${_esc(l.alerta_codigo)}</small>` : ''}
            </td>
            <td style="font-size:13px">${_esc(l.destinatario)}</td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px" title="${_esc(l.assunto)}">${_esc(l.assunto)}</td>
            <td>${statusBadge}</td>
            <td>${l.erro_mensagem
                ? `<span style="color:#dc2626;font-size:12px;cursor:help" title="${_esc(l.erro_mensagem)}"><i class="fas fa-exclamation-circle"></i> Ver erro</span>`
                : '—'}</td>
        </tr>`;
    }).join('');

    const pag = document.getElementById('log-paginacao');
    if (pag) {
        if (dados.total_paginas > 1) {
            let btns = '';
            for (let i = 1; i <= dados.total_paginas; i++) {
                btns += `<button class="pag-btn ${i === dados.pagina_atual ? 'active' : ''}"
                    onclick="EmailAlertasPage._setLogPagina(${i})">${i}</button>`;
            }
            pag.innerHTML = btns;
        } else {
            pag.innerHTML = '';
        }
    }
}

function _setLogPagina(p) { _logPagina = p; carregarLogs(); }

async function limparLogs() {
    if (!confirm('Remover logs com mais de 30 dias? Esta ação não pode ser desfeita.')) return;
    const form = new FormData();
    form.append('acao', 'log_limpar');
    form.append('dias', 30);
    try {
        const d = await _fetchJson(API, { method: 'POST', body: form });
        _toast(d.mensagem, d.sucesso ? 'sucesso' : 'erro');
        if (d.sucesso) carregarLogs();
    } catch (e) { _toast('Erro: ' + e.message, 'erro'); }
}

// ── Dashboard KPI ─────────────────────────────────────────────────────────────
async function carregarDashboard() {
    try {
        const d = await _fetchJson(`${API}?acao=dashboard_stats`);
        if (!d.sucesso) return;
        const s = d.dados;

        _setText('kpi-alertas-ativos', s.alertas_ativos ?? '0');
        _setText('kpi-ultimo-envio', s.ultimo_envio ? _formatDate(s.ultimo_envio) : 'Nunca');

        _setText('kpi-enviados', s.enviados_hoje ?? '0');
        _setText('kpi-falhas',   s.falhas_hoje   ?? '0');
        _setText('kpi-taxa',     s.taxa_entrega !== null && s.taxa_entrega !== undefined ? s.taxa_entrega + '%' : '—');

    } catch (e) {
        console.warn('[EmailAlertas] Dashboard stats erro:', e.message);
    }
}

function _formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    const hoje = new Date();
    const mesmodia = d.toDateString() === hoje.toDateString();
    if (mesmodia) return 'hoje ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) +
        ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

// ── Helpers DOM ───────────────────────────────────────────────────────────────
function _get(id)       { return (document.getElementById(id)?.value || '').trim(); }
function _val(id, v)    { const el = document.getElementById(id); if (el) el.value = v; }
function _setText(id, t){ const el = document.getElementById(id); if (el) el.textContent = t; }

function _esc(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function _toast(msg, tipo) {
    const cores = { sucesso: '#22c55e', erro: '#dc2626', info: '#2563eb' };
    const icon  = tipo === 'sucesso' ? 'check-circle' : tipo === 'erro' ? 'times-circle' : 'info-circle';
    const t = document.createElement('div');
    t.style.cssText = `
        position:fixed;bottom:24px;right:24px;
        background:${cores[tipo] || '#334155'};
        color:#fff;padding:12px 20px;border-radius:10px;
        font-size:14px;font-weight:500;z-index:99999;
        box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:380px;
        line-height:1.4;display:flex;align-items:center;gap:10px`;
    t.innerHTML = `<i class="fas fa-${icon}"></i><span>${_esc(msg)}</span>`;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 4000);
}
