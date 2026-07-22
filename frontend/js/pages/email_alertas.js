/**
 * email_alertas.js — Módulo de E-mail e Alertas
 * Sistema ERP Serra da Liberdade
 * v3.0 — ES Module com export init/destroy para AppRouter
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
let _provedores          = [];
let _alertas             = [];
let _alertaAtual         = null;
let _provedorSelecionado = 'custom';
let _emailProvider       = 'brevo';
let _logPagina           = 1;

// ── Lifecycle: init / destroy ─────────────────────────────────────────────────
export function init() {
    console.log('[EmailAlertas] init() v3.0');
    _setupTabs();
    _carregarProvedores();
    _carregarSMTP();
    carregarDashboard();

    // Expor API pública para handlers inline no HTML
    window.EmailAlertasPage = {
        trocarAba,
        selecionarEmailProvider,
        selecionarProvedor,
        salvarSMTP,
        testarSMTP,
        recarregarConfig,
        toggleSenha,
        toggleApiKey,
        trocarDocTab,
        filtrarAlertas,
        toggleAlerta,
        abrirModalAlerta,
        fecharModalAlerta,
        toggleDestEmail,
        inserirVariavel,
        previewAlerta,
        resetarTemplate,
        salvarAlerta,
        carregarLogs,
        limparLogs,
        _setLogPagina,
        carregarDashboard,
        executarMonitoramento,
    };
}

export function destroy() {
    console.log('[EmailAlertas] destroy()');
    delete window.EmailAlertasPage;
    _provedores          = [];
    _alertas             = [];
    _alertaAtual         = null;
    _provedorSelecionado = 'custom';
    _emailProvider       = 'brevo';
    _logPagina           = 1;
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
    if (aba === 'logs')          carregarLogs();
    if (aba === 'teste')         _renderizarResumoSMTP();
    if (aba === 'monitoramento') _renderizarMonitoramentoVazio();
}

// ── Provedores ────────────────────────────────────────────────────────────────
async function _carregarProvedores() {
    try {
        const d = await _fetchJson(`${API}?acao=provedores_listar`);
        if (d.sucesso) {
            _provedores = d.dados.provedores || [];
            _renderizarProvedores();
        }
    } catch (e) {
        console.error('[EmailAlertas] Erro ao carregar provedores:', e.message);
    }
}

function _renderizarProvedores() {
    const grid = document.getElementById('provedores-grid');
    if (!grid) return;
    grid.innerHTML = _provedores.map(p => `
        <div class="provedor-card ${p.id === _provedorSelecionado ? 'active' : ''}"
             onclick="EmailAlertasPage.selecionarProvedor('${p.id}')" data-id="${p.id}">
            <div class="provedor-card-icon" style="color:${p.cor || '#64748b'}">
                <i class="${p.icone || 'fas fa-server'}"></i>
            </div>
            <div class="provedor-card-nome">${_esc(p.nome)}</div>
        </div>
    `).join('');
}

function selecionarProvedor(id) {
    _provedorSelecionado = id;
    document.querySelectorAll('.provedor-card').forEach(c => {
        c.classList.toggle('active', c.dataset.id === id);
    });

    const p = _provedores.find(x => x.id === id);
    if (!p) return;

    if (p.smtp_host) _val('smtp-host', p.smtp_host);
    if (p.smtp_port) _val('smtp-port', p.smtp_port);
    const seg = document.getElementById('smtp-seguranca');
    if (seg && p.smtp_seguranca) seg.value = p.smtp_seguranca;

    const ajudaDiv  = document.getElementById('provedor-ajuda');
    const ajudaTxt  = document.getElementById('provedor-ajuda-texto');
    const ajudaLink = document.getElementById('provedor-ajuda-link');
    if (p.ajuda && ajudaDiv) {
        ajudaDiv.style.display = 'flex';
        if (ajudaTxt) ajudaTxt.textContent = p.ajuda;
        if (ajudaLink) {
            ajudaLink.href = p.link_ajuda || '#';
            ajudaLink.style.display = p.link_ajuda ? 'inline-flex' : 'none';
        }
    } else if (ajudaDiv) {
        ajudaDiv.style.display = 'none';
    }
}

// ── Seleção de tipo de provedor (Brevo / Resend / SMTP) ──────────────────────
function selecionarEmailProvider(provider) {
    _emailProvider = provider;

    document.querySelectorAll('.email-provider-card').forEach(c => {
        c.classList.toggle('active', c.dataset.emailProvider === provider);
    });

    const camposApi   = document.getElementById('campos-api');
    const camposSmtp  = document.getElementById('campos-smtp');
    const smtpPresets = document.getElementById('smtp-presets-wrap');
    const isApi = provider === 'brevo' || provider === 'resend';

    if (camposApi)   camposApi.style.display   = isApi ? 'block' : 'none';
    if (camposSmtp)  camposSmtp.style.display  = isApi ? 'none'  : 'block';
    if (smtpPresets) smtpPresets.style.display = isApi ? 'none'  : 'block';

    const docBrevo  = document.getElementById('api-docs-brevo');
    const docResend = document.getElementById('api-docs-resend');
    if (docBrevo)  docBrevo.style.display  = provider === 'brevo'  ? 'block' : 'none';
    if (docResend) docResend.style.display = provider === 'resend' ? 'block' : 'none';
}

function toggleApiKey() {
    const inp  = document.getElementById('api-key');
    const icon = document.getElementById('api-key-icon');
    if (!inp) return;
    if (inp.type === 'password') { inp.type = 'text';     if (icon) icon.className = 'fas fa-eye-slash'; }
    else                         { inp.type = 'password'; if (icon) icon.className = 'fas fa-eye'; }
}

// ── SMTP: Carregar ────────────────────────────────────────────────────────────
async function _carregarSMTP() {
    try {
        const d = await _fetchJson(`${API}?acao=smtp_carregar`);
        if (d.sucesso && d.dados) {
            const c = d.dados;
            const providerLabels = { brevo: 'Brevo API', resend: 'Resend API', smtp: 'SMTP' };

            // Selecionar tipo de provedor e mostrar/ocultar seções
            _emailProvider = c.email_provider || 'brevo';
            selecionarEmailProvider(_emailProvider);

            // Campos API (Brevo / Resend)
            _val('api-key',      ''); // nunca pré-preencher
            _val('sender-email', c.sender_email || '');
            _val('sender-name',  c.sender_name  || '');
            const maskEl = document.getElementById('api-key-mask');
            if (maskEl) {
                maskEl.textContent = c.api_key_mask
                    ? `Chave atual: ${c.api_key_mask} — deixe em branco para manter`
                    : 'Deixe em branco para manter a chave atual';
            }

            // Campos SMTP
            _val('smtp-host',     c.smtp_host     || '');
            _val('smtp-port',     c.smtp_port     || 587);
            _val('smtp-usuario',  c.smtp_usuario  || '');
            _val('smtp-de-email', c.smtp_de_email || '');
            _val('smtp-de-nome',  c.smtp_de_nome  || 'Sistema ERP');
            _val('smtp-timeout',  c.timeout       || 30);
            const seg = document.getElementById('smtp-seguranca');
            if (seg) seg.value = c.smtp_seguranca || 'tls';

            // Status ativo/inativo
            const ativoSel = document.getElementById('smtp-ativo');
            if (ativoSel) ativoSel.value = c.smtp_ativo !== undefined ? String(c.smtp_ativo) : '1';

            _provedorSelecionado = c.provedor || 'custom';
            _renderizarProvedores();

            // Status card
            const icon = document.getElementById('smtp-status-icon');
            if (icon) { icon.classList.add('configured'); icon.innerHTML = '<i class="fas fa-check-circle"></i>'; }
            const badge = document.getElementById('smtp-status-badge');
            if (badge) badge.style.display = 'flex';
            const badgeTxt = document.getElementById('smtp-status-badge-texto');
            if (badgeTxt) badgeTxt.textContent = (providerLabels[_emailProvider] || 'E-mail') + ' Configurado';

            // Indicador da configuração atual
            const configRow   = document.getElementById('provider-config-atual');
            const configBadge = document.getElementById('provider-config-badge');
            const configStatus = document.getElementById('provider-config-status');
            if (configRow) configRow.style.display = 'flex';
            if (configBadge) configBadge.textContent = providerLabels[_emailProvider] || _emailProvider;
            if (configStatus) configStatus.textContent = c.smtp_ativo == 0 ? '— Inativo' : '';

            if (_emailProvider === 'brevo' || _emailProvider === 'resend') {
                const label = providerLabels[_emailProvider];
                _setText('smtp-status-titulo',  `${label}: ${c.sender_email || '—'}`);
                _setText('smtp-status-detalhe', `Provedor: ${label} | Remetente: ${c.sender_name || '—'}`);
            } else {
                _setText('smtp-status-titulo',  `SMTP: ${c.smtp_host}:${c.smtp_port}`);
                _setText('smtp-status-detalhe', `Usuário: ${c.smtp_usuario} | De: ${c.smtp_de_nome} <${c.smtp_de_email}>`);
            }
            console.log('[EmailAlertas] Provider carregado:', _emailProvider);
        } else {
            selecionarEmailProvider('brevo');
            console.log('[EmailAlertas] Nenhuma configuração encontrada.');
        }
    } catch (e) {
        console.error('[EmailAlertas] Erro ao carregar SMTP:', e.message);
        _toast('Erro ao carregar configuração: ' + e.message, 'erro');
    }
}

// ── SMTP: Salvar ──────────────────────────────────────────────────────────────
async function salvarSMTP() {
    const isApi = _emailProvider === 'brevo' || _emailProvider === 'resend';

    if (isApi) {
        if (!_get('sender-email')) { _toast('Informe o e-mail remetente.', 'erro'); return; }
        if (!_get('sender-name'))  { _toast('Informe o nome do remetente.', 'erro'); return; }
    } else {
        if (!_get('smtp-host') || !_get('smtp-usuario') || !_get('smtp-de-email')) {
            _toast('Preencha os campos obrigatórios: Host, Usuário e E-mail de Envio.', 'erro');
            return;
        }
    }

    const form = new FormData();
    form.append('acao',           'smtp_salvar');
    form.append('email_provider', _emailProvider);
    form.append('provedor',       _provedorSelecionado);
    form.append('api_key',        _get('api-key'));
    form.append('sender_email',   _get('sender-email'));
    form.append('sender_name',    _get('sender-name'));
    form.append('smtp_host',      _get('smtp-host'));
    form.append('smtp_port',      _get('smtp-port'));
    form.append('smtp_usuario',   _get('smtp-usuario'));
    form.append('smtp_senha',     _get('smtp-senha'));
    form.append('smtp_de_email',  _get('smtp-de-email'));
    form.append('smtp_de_nome',   _get('smtp-de-nome'));
    form.append('smtp_seguranca', document.getElementById('smtp-seguranca')?.value || 'tls');
    form.append('timeout',        _get('smtp-timeout'));
    form.append('smtp_ativo',     document.getElementById('smtp-ativo')?.value ?? '1');

    try {
        const d = await _fetchJson(API, { method: 'POST', body: form });
        _toast(d.mensagem, d.sucesso ? 'sucesso' : 'erro');
        if (d.sucesso) _carregarSMTP();
    } catch (e) {
        _toast('Erro de comunicação: ' + e.message, 'erro');
    }
}

// ── SMTP: Testar ──────────────────────────────────────────────────────────────
async function testarSMTP() {
    const email = _get('teste-email');
    if (!email) { _toast('Informe um e-mail para o teste.', 'erro'); return; }

    const btn = document.getElementById('btn-testar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }

    const res = document.getElementById('teste-resultado');
    if (res) res.style.display = 'none';

    const form = new FormData();
    form.append('acao', 'smtp_testar');
    form.append('email_teste', email);

    try {
        const d = await _fetchJson(API, { method: 'POST', body: form });
        if (res) {
            res.style.display = 'flex';
            if (d.sucesso) {
                res.className = 'teste-resultado sucesso';
                const providerInfo = d.dados?.provider
                    ? `Provedor: <strong>${_esc(d.dados.provider)}</strong>`
                    : (d.dados?.host ? `Host: ${_esc(d.dados.host)} | Porta: ${_esc(String(d.dados?.porta || ''))}` : '');
                res.innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <div class="teste-resultado-titulo">E-mail enviado com sucesso!</div>
                        <div class="teste-resultado-detalhe">
                            Verifique a caixa de entrada de <strong>${_esc(email)}</strong>.
                            ${providerInfo ? `<br>${providerInfo}` : ''}
                        </div>
                    </div>`;
            } else {
                res.className = 'teste-resultado erro';
                res.innerHTML = `
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <div class="teste-resultado-titulo">Falha no envio</div>
                        <div class="teste-resultado-detalhe">
                            ${_esc(d.mensagem)}
                            ${d.dados?.erro ? `<pre style="margin:8px 0 0;font-size:11px;background:#fef2f2;padding:8px;border-radius:4px;overflow:auto;white-space:pre-wrap">${_esc(d.dados.erro)}</pre>` : ''}
                        </div>
                    </div>`;
            }
        }
    } catch (e) {
        if (res) {
            res.style.display = 'flex';
            res.className = 'teste-resultado erro';
            res.innerHTML = `
                <i class="fas fa-times-circle"></i>
                <div>
                    <div class="teste-resultado-titulo">Erro de comunicação</div>
                    <div class="teste-resultado-detalhe">${_esc(e.message)}</div>
                </div>`;
        }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar E-mail de Teste'; }
    }
}

async function _renderizarResumoSMTP() {
    const el = document.getElementById('smtp-resumo');
    if (!el) return;
    try {
        const d = await _fetchJson(`${API}?acao=smtp_carregar`);
        if (d.sucesso && d.dados) {
            const c = d.dados;
            const providerLabels = { brevo: 'Brevo API', resend: 'Resend API', smtp: 'SMTP Personalizado' };
            const provider = c.email_provider || 'smtp';
            const isApi    = provider === 'brevo' || provider === 'resend';

            if (isApi) {
                el.innerHTML = `
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Provedor</div>
                        <div class="smtp-resumo-valor">${_esc(providerLabels[provider] || provider)}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">E-mail Remetente</div>
                        <div class="smtp-resumo-valor">${_esc(c.sender_email || '—')}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Nome Remetente</div>
                        <div class="smtp-resumo-valor">${_esc(c.sender_name || '—')}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">API Key</div>
                        <div class="smtp-resumo-valor">${_esc(c.api_key_mask || '— não configurada —')}</div>
                    </div>`;
            } else {
                el.innerHTML = `
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Provedor</div>
                        <div class="smtp-resumo-valor">${_esc(c.provedor || 'custom')}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Host / Porta</div>
                        <div class="smtp-resumo-valor">${_esc(c.smtp_host)}:${_esc(String(c.smtp_port))}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Usuário</div>
                        <div class="smtp-resumo-valor">${_esc(c.smtp_usuario)}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Remetente</div>
                        <div class="smtp-resumo-valor">${_esc(c.smtp_de_nome)} &lt;${_esc(c.smtp_de_email)}&gt;</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Segurança</div>
                        <div class="smtp-resumo-valor">${_esc((c.smtp_seguranca || 'tls').toUpperCase())}</div>
                    </div>
                    <div class="smtp-resumo-item">
                        <div class="smtp-resumo-label">Timeout</div>
                        <div class="smtp-resumo-valor">${_esc(String(c.timeout || 30))}s</div>
                    </div>`;
            }
        } else {
            el.innerHTML = `<div class="smtp-resumo-item" style="grid-column:1/-1">
                <div class="smtp-resumo-valor" style="color:#dc2626">
                    <i class="fas fa-exclamation-triangle"></i> Nenhuma configuração encontrada.
                </div></div>`;
        }
    } catch (e) {
        el.innerHTML = `<div style="color:#dc2626;font-size:14px"><i class="fas fa-exclamation-triangle"></i> ${_esc(e.message)}</div>`;
    }
}

function recarregarConfig() {
    _carregarSMTP();
    _toast('Configuração recarregada.', 'info');
}

function trocarDocTab(doc, el) {
    document.querySelectorAll('.page-email-alertas .docs-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.page-email-alertas .docs-panel').forEach(p => p.classList.remove('active'));
    if (el) el.classList.add('active');
    const panel = document.getElementById(`doc-panel-${doc}`);
    if (panel) panel.classList.add('active');
}

function toggleSenha() {
    const inp  = document.getElementById('smtp-senha');
    const icon = document.getElementById('smtp-senha-icon');
    if (!inp) return;
    if (inp.type === 'password') { inp.type = 'text';     if (icon) icon.className = 'fas fa-eye-slash'; }
    else                         { inp.type = 'password'; if (icon) icon.className = 'fas fa-eye'; }
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
    const exemplo = corpo
        .replace(/\{\{nome_usuario\}\}/g, 'João da Silva')
        .replace(/\{\{nome_morador\}\}/g, 'Maria Oliveira')
        .replace(/\{\{unidade\}\}/g, 'Gleba 42')
        .replace(/\{\{sistema_nome\}\}/g, 'Serra da Liberdade')
        .replace(/\{\{logo_url\}\}/g, window.location.origin + '/assets/img/logo.png')
        .replace(/\{\{data_envio\}\}/g, new Date().toLocaleString('pt-BR'))
        .replace(/\{\{[\w_]+\}\}/g, '<span style="background:#fef3c7;padding:0 4px;border-radius:3px;font-size:11px">[variável]</span>');
    cont.innerHTML = exemplo;
    wrap.style.display = 'block';
}

async function resetarTemplate() {
    if (!_alertaAtual) return;
    if (!confirm('Restaurar o template padrão? Suas edições serão perdidas.')) return;
    try {
        const d = await _fetchJson(`${API}?acao=alertas_listar`);
        if (d.sucesso) {
            const original = (d.dados.alertas || []).find(a => a.id == _alertaAtual.id);
            if (original) { _val('modal-corpo', original.corpo_html || ''); _toast('Template restaurado.', 'sucesso'); }
        }
    } catch (e) { _toast('Erro ao restaurar: ' + e.message, 'erro'); }
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

        // Provedor ativo
        const nomeProvider = { brevo: 'Brevo', resend: 'Resend', smtp: 'SMTP' };
        _setText('kpi-provedor-nome', nomeProvider[s.provedor_ativo] || s.provedor_ativo || '—');
        _setText('kpi-provedor-remetente',
            s.sender_email ? `${s.sender_name || ''} <${s.sender_email}>`.trim() : (s.smtp_host || '—'));

        const badgeEl = document.getElementById('kpi-provedor-badge');
        if (badgeEl) {
            const st = s.status_teste;
            badgeEl.textContent = st === 'ok' ? 'Testado OK' : st === 'erro' ? 'Com Erro' : 'Não testado';
            badgeEl.className = 'email-kpi-badge' +
                (st === 'ok' ? ' email-kpi-badge--ok' : st === 'erro' ? ' email-kpi-badge--erro' : ' email-kpi-badge--inativo');
        }

        _setText('kpi-provedor-ultimo-teste',
            s.ultimo_teste ? _formatDate(s.ultimo_teste) : '');

        // Saúde: último envio
        const ultimoEnvio = s.ultimo_envio ? _formatDate(s.ultimo_envio) : 'Nunca';
        _setText('kpi-saude-ultimo-envio', ultimoEnvio);

        const saude = document.getElementById('kpi-saude-status');
        if (saude) {
            const temErros = (s.falhas_hoje || 0) > 0;
            const semDados = (s.enviados_hoje || 0) === 0 && (s.falhas_hoje || 0) === 0;
            saude.textContent = semDados ? '—' : (temErros ? 'Com Erros' : 'Operacional');
            saude.style.color = semDados ? '#94a3b8' : (temErros ? '#dc2626' : '#16a34a');
        }

        // Estatísticas
        _setText('kpi-enviados', s.enviados_hoje ?? '0');
        _setText('kpi-falhas',   s.falhas_hoje   ?? '0');
        _setText('kpi-taxa',     s.taxa_entrega !== null && s.taxa_entrega !== undefined ? s.taxa_entrega + '%' : '—');
        _setText('kpi-tempo',    s.tempo_medio_s !== null && s.tempo_medio_s !== undefined ? s.tempo_medio_s + 's' : '—');

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

// ── Monitoramento ─────────────────────────────────────────────────────────────
function _renderizarMonitoramentoVazio() {
    const res  = document.getElementById('monitoramento-resultado');
    const vazio = document.getElementById('monitoramento-vazio');
    if (res)   res.style.display = 'none';
    if (vazio) vazio.style.display = 'block';
}

async function executarMonitoramento() {
    const btn   = document.getElementById('btn-monitorar');
    const res   = document.getElementById('monitoramento-resultado');
    const vazio = document.getElementById('monitoramento-vazio');
    const cards = document.getElementById('monitor-cards');

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...'; }
    if (vazio) vazio.style.display = 'none';
    if (res)   res.style.display = 'none';
    if (cards) cards.innerHTML = '';

    try {
        const d = await _fetchJson(`${API}?acao=monitorar`);
        if (!d.sucesso) throw new Error(d.mensagem || 'Erro no monitoramento');

        const { resultados, resumo, executado_em } = d.dados;

        // Resumo numérico
        _setText('monitor-ok',     resumo.ok      ?? 0);
        _setText('monitor-avisos', resumo.avisos   ?? 0);
        _setText('monitor-erros',  resumo.erros    ?? 0);
        _setText('monitor-timestamp', executado_em ? 'Verificado em: ' + executado_em : '');

        // Cards de resultado
        if (cards) {
            cards.innerHTML = (resultados || []).map(item => {
                const statusClass = item.status === 'ok' ? 'ok' : item.status === 'aviso' ? 'aviso' : 'erro';
                const icon = item.status === 'ok'
                    ? 'fas fa-check-circle'
                    : item.status === 'aviso' ? 'fas fa-exclamation-triangle' : 'fas fa-times-circle';
                const lat = item.latencia !== undefined ? ` (${item.latencia}s)` : '';
                return `<div class="monitor-card monitor-card--${statusClass}">
                    <div class="monitor-card-icon"><i class="${icon}"></i></div>
                    <div class="monitor-card-body">
                        <div class="monitor-card-servico">${_esc(item.servico)}</div>
                        <div class="monitor-card-detalhe">${_esc(item.detalhe || '')}</div>
                        <div class="monitor-card-latencia">${lat}</div>
                    </div>
                </div>`;
            }).join('');
        }

        if (res) res.style.display = 'block';

    } catch (e) {
        _toast('Erro no monitoramento: ' + e.message, 'erro');
        if (vazio) vazio.style.display = 'block';
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play-circle"></i> Verificar Ambiente'; }
    }
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
