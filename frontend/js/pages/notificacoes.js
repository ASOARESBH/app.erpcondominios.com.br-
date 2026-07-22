/**
 * notificacoes.js — Módulo de Configuração de Notificações + FCM
 * Versão: 2.0 | 2026-06-28
 */
'use strict';

const _API_NOTIF  = '/api/api_notificacoes_os.php';
const _API_FCM    = '/api/api_pwa_push.php';
let _listeners    = [];
let _usuarios     = [];
let _regraAtual   = null;
let _histPagina   = 1;

// ─── Lifecycle ────────────────────────────────────────────────────────────────
export function init() {
    console.log('[Notificacoes] Inicializando módulo v2.0...');
    _bindTabs();
    _carregarRegrasOS();
    _carregarUsuarios();
    _bindModal();
    _bindHistorico();
    _iniciarFCMTab();
}

export function destroy() {
    _listeners.forEach(({ el, ev, fn }) => el.removeEventListener(ev, fn));
    _listeners = [];
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function _on(el, ev, fn) {
    if (!el) return;
    el.addEventListener(ev, fn);
    _listeners.push({ el, ev, fn });
}

async function _get(api, acao, params = {}) {
    const qs = new URLSearchParams({ acao, ...params }).toString();
    const r  = await fetch(`${api}?${qs}`, { credentials: 'include' });
    return r.json();
}

async function _post(api, acao, body = {}) {
    const r = await fetch(`${api}?acao=${acao}`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao, ...body })
    });
    return r.json();
}

// ─── Abas de navegação ────────────────────────────────────────────────────────
function _bindTabs() {
    const btns   = document.querySelectorAll('.notif-tab-btn');
    const panels = document.querySelectorAll('.notif-tab-panel');

    btns.forEach(btn => {
        _on(btn, 'click', () => {
            const tab = btn.dataset.tab;
            btns.forEach(b => b.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            const panel = document.getElementById(`tab-${tab}`);
            if (panel) panel.classList.add('active');
            if (tab === 'fcm')      _carregarConfigFCM();
            if (tab === 'historico') _carregarHistorico(1);
        });
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ABA 1 — REGRAS DE NOTIFICAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

async function _carregarRegrasOS() {
    const lista = document.getElementById('regras-os');
    const badge = document.getElementById('badge-os');
    if (!lista) return;

    lista.innerHTML = '<div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    const json = await _get(_API_NOTIF, 'listar_regras', { modulo: 'os' });
    if (!json.sucesso || !json.dados) {
        lista.innerHTML = '<div class="notif-loading">Erro ao carregar regras.</div>';
        return;
    }

    const regras = json.dados;
    const ativas = regras.filter(r => r.ativo == 1).length;

    if (badge) {
        badge.textContent = ativas > 0 ? `${ativas} ativa${ativas > 1 ? 's' : ''}` : 'Nenhuma ativa';
        badge.className   = 'notif-modulo-badge' + (ativas > 0 ? ' ativas' : '');
    }

    if (!regras.length) {
        lista.innerHTML = '<div class="notif-loading">Nenhuma regra encontrada.</div>';
        return;
    }

    const _labelEvento = {
        'os_criada':             'Notificar sempre que uma O.S for criada',
        'os_aberta_horas':       'O.S em aberto por mais de X horas',
        'os_prioridade_urgente': 'O.S com prioridade Urgente criada',
        'os_prioridade_alta':    'O.S com prioridade Alta criada',
    };

    lista.innerHTML = regras.map(r => {
        const canais    = (r.canais || 'sistema').split(',').map(c => c.trim()).filter(Boolean);
        const canalTags = canais.map(c => {
            const iconMap = { sistema: 'fas fa-desktop', email: 'fas fa-envelope', whatsapp: 'fab fa-whatsapp', telegram: 'fab fa-telegram' };
            const labels  = { sistema: 'Sistema', email: 'E-mail', whatsapp: 'WhatsApp', telegram: 'Telegram' };
            return `<span class="notif-canal-tag ${c}"><i class="${iconMap[c] || 'fas fa-bell'}"></i>${labels[c] || c}</span>`;
        }).join('');

        let desc = _labelEvento[r.evento] || r.evento;
        if (r.evento === 'os_aberta_horas' && r.horas_limite)
            desc = `Alertar quando aberta há mais de <strong>${r.horas_limite}h</strong>`;
        else if (r.evento.startsWith('os_prioridade') && r.prioridade)
            desc = `Alertar quando prioridade for <strong>${r.prioridade}</strong>`;

        return `<div class="notif-regra-item ${r.ativo == 1 ? '' : 'inativa'}" data-id="${r.id}">
            <div class="notif-regra-status-dot ${r.ativo == 1 ? 'ativa' : ''}"></div>
            <div class="notif-regra-info">
                <p class="notif-regra-titulo">${_labelEvento[r.evento] || r.evento}</p>
                <p class="notif-regra-desc">${desc}</p>
            </div>
            <div class="notif-regra-canais">${canalTags}</div>
            <button class="notif-regra-btn-editar" onclick="window._notifEditarRegra(${r.id})">
                <i class="fas fa-pencil-alt"></i> Configurar
            </button>
        </div>`;
    }).join('');
}

async function _carregarUsuarios() {
    try {
        const r   = await fetch('/api/api_usuarios.php', { credentials: 'include' });
        const json = await r.json();
        _usuarios  = Array.isArray(json.dados) ? json.dados : [];
    } catch (e) {
        _usuarios = [];
    }
}

function _bindModal() {
    const overlay    = document.getElementById('notif-modal-overlay');
    const btnClose   = document.getElementById('notif-modal-close');
    const btnCancel  = document.getElementById('notif-btn-cancelar');
    const btnSalvar  = document.getElementById('notif-btn-salvar');
    const canalEmail = document.getElementById('canal-email');

    _on(btnClose,   'click', _fecharModal);
    _on(btnCancel,  'click', _fecharModal);
    _on(overlay,    'click', (e) => { if (e.target === overlay) _fecharModal(); });
    _on(btnSalvar,  'click', _salvarRegra);
    _on(canalEmail, 'change', () => {
        const grp = document.getElementById('notif-emails-group');
        if (grp) grp.style.display = canalEmail.checked ? '' : 'none';
    });
}

function _fecharModal() {
    const overlay = document.getElementById('notif-modal-overlay');
    if (overlay) overlay.style.display = 'none';
    _regraAtual = null;
}

window._notifEditarRegra = async function(id) {
    const json = await _get(_API_NOTIF, 'listar_regras', { modulo: 'os' });
    if (!json.sucesso) return;
    const regra = json.dados.find(r => r.id == id);
    if (!regra) return;
    _regraAtual = regra;

    const overlay = document.getElementById('notif-modal-overlay');
    if (!overlay) return;

    document.getElementById('notif-regra-id').value    = regra.id;
    document.getElementById('notif-regra-evento').value = regra.evento;
    document.getElementById('notif-ativo').checked      = regra.ativo == 1;
    document.getElementById('notif-titulo-tpl').value   = regra.titulo_tpl || '';
    document.getElementById('notif-corpo-tpl').value    = regra.corpo_tpl  || '';

    const canais = (regra.canais || 'sistema').split(',').map(c => c.trim());
    ['sistema','email','whatsapp','telegram'].forEach(c => {
        const el = document.getElementById(`canal-${c}`);
        if (el) el.checked = canais.includes(c);
    });

    const grpHoras  = document.getElementById('notif-horas-group');
    const grpPrior  = document.getElementById('notif-prioridade-group');
    const grpEmails = document.getElementById('notif-emails-group');

    if (grpHoras)  grpHoras.style.display  = regra.evento === 'os_aberta_horas' ? '' : 'none';
    if (grpPrior)  grpPrior.style.display  = regra.evento.startsWith('os_prioridade') ? '' : 'none';
    if (grpEmails) grpEmails.style.display = canais.includes('email') ? '' : 'none';

    const horasEl = document.getElementById('notif-horas-limite');
    if (horasEl) horasEl.value = regra.horas_limite || '';

    const priorEl = document.getElementById('notif-prioridade');
    if (priorEl) priorEl.value = regra.prioridade || '';

    const emailEl = document.getElementById('notif-emails');
    if (emailEl) emailEl.value = regra.emails || '';

    _renderizarUsuarios(regra.usuarios_ids || '');

    const _labels = {
        'os_criada':             'O.S Criada',
        'os_aberta_horas':       'O.S em Aberto por X Horas',
        'os_prioridade_urgente': 'O.S Urgente',
        'os_prioridade_alta':    'O.S Alta Prioridade',
    };
    const titulo = document.getElementById('notif-modal-titulo');
    if (titulo) titulo.innerHTML = `<i class="fas fa-bell"></i> Configurar: ${_labels[regra.evento] || regra.evento}`;

    overlay.style.display = 'flex';
};

function _renderizarUsuarios(uidsSelecionados) {
    const lista = document.getElementById('notif-usuarios-lista');
    if (!lista) return;
    const selecionados = String(uidsSelecionados).split(',').map(s => s.trim()).filter(Boolean);

    if (!_usuarios.length) {
        lista.innerHTML = '<span style="font-size:.8rem;color:#94a3b8;">Nenhum usuário encontrado.</span>';
        return;
    }

    lista.innerHTML = _usuarios.map(u => {
        const sel = selecionados.includes(String(u.id));
        return `<div class="notif-usuario-item ${sel ? 'selecionado' : ''}" data-uid="${u.id}" onclick="this.classList.toggle('selecionado')">
            <i class="fas fa-user"></i> ${u.nome || u.login}
        </div>`;
    }).join('');
}

async function _salvarRegra() {
    const id = parseInt(document.getElementById('notif-regra-id').value || '0');
    if (!id) return;

    const canaisSelecionados = ['sistema','email','whatsapp','telegram']
        .filter(c => document.getElementById(`canal-${c}`)?.checked)
        .join(',') || 'sistema';

    const uidsSelecionados = Array.from(
        document.querySelectorAll('#notif-usuarios-lista .notif-usuario-item.selecionado')
    ).map(el => el.dataset.uid).join(',');

    const body = {
        id,
        ativo:        document.getElementById('notif-ativo').checked ? 1 : 0,
        canais:       canaisSelecionados,
        emails:       document.getElementById('notif-emails')?.value || '',
        usuarios_ids: uidsSelecionados,
        horas_limite: document.getElementById('notif-horas-limite')?.value || '',
        prioridade:   document.getElementById('notif-prioridade')?.value || '',
        titulo_tpl:   document.getElementById('notif-titulo-tpl').value,
        corpo_tpl:    document.getElementById('notif-corpo-tpl').value,
    };

    const btn = document.getElementById('notif-btn-salvar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    const json = await _post(_API_NOTIF, 'salvar_regra', body);

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Configuração'; }

    if (json.sucesso) {
        _fecharModal();
        _carregarRegrasOS();
        _toast('Configuração salva com sucesso!', 'success');
    } else {
        _toast(json.mensagem || 'Erro ao salvar.', 'error');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ABA 2 — CONFIGURAR FCM
// ═══════════════════════════════════════════════════════════════════════════════

function _iniciarFCMTab() {
    // Guia toggle
    const guiaToggle = document.getElementById('fcm-guia-toggle');
    const guiaBody   = document.getElementById('fcm-guia-body');
    _on(guiaToggle, 'click', () => {
        const aberto = guiaBody.style.display !== 'none';
        guiaBody.style.display = aberto ? 'none' : 'block';
        guiaToggle.innerHTML = aberto
            ? '<i class="fas fa-chevron-down"></i> Ver instruções'
            : '<i class="fas fa-chevron-up"></i> Ocultar instruções';
    });

    // Botão salvar
    _on(document.getElementById('fcm-btn-salvar'), 'click', _salvarConfigFCM);

    // Botão testar
    _on(document.getElementById('fcm-btn-testar'), 'click', _abrirModalTeste);

    // Modal de teste
    _on(document.getElementById('fcm-modal-teste-close'),   'click', _fecharModalTeste);
    _on(document.getElementById('fcm-modal-teste-cancelar'), 'click', _fecharModalTeste);
    _on(document.getElementById('fcm-modal-teste-enviar'),   'click', _enviarTeste);
    _on(document.getElementById('fcm-modal-teste'), 'click', (e) => {
        if (e.target === document.getElementById('fcm-modal-teste')) _fecharModalTeste();
    });
}

async function _carregarConfigFCM() {
    const statusBar  = document.getElementById('fcm-status-bar');
    const statusInd  = document.getElementById('fcm-status-indicator');
    const statusTxt  = document.getElementById('fcm-status-texto');
    const statsDiv   = document.getElementById('fcm-status-stats');

    try {
        const json = await _get(_API_FCM, 'obter_config');

        if (!json.sucesso) {
            _setStatusFCM('erro', 'Erro ao carregar configurações do Firebase.');
            return;
        }

        const cfg = json.dados || {};

        // Preencher campos
        _setVal('fcm-project-id',  cfg.fcm_project_id  || '');
        _setVal('fcm-api-key',     cfg.fcm_api_key      || '');
        _setVal('fcm-auth-domain', cfg.fcm_auth_domain  || '');
        _setVal('fcm-sender-id',   cfg.fcm_messaging_sender_id || '');
        _setVal('fcm-app-id',      cfg.fcm_app_id       || '');
        _setVal('fcm-vapid-key',   cfg.fcm_vapid_key    || '');
        _setVal('fcm-server-key',  cfg.fcm_server_key   || '');

        // Toggles
        _setCheck('fcm-toggle-pwa',          cfg.pwa_ativo               !== '0');
        _setCheck('fcm-toggle-visitante',     cfg.push_visitante_ativo    !== '0');
        _setCheck('fcm-toggle-inadimplencia', cfg.push_inadimplencia_ativo !== '0');
        _setCheck('fcm-toggle-comunicado',    cfg.push_comunicado_ativo   !== '0');
        _setCheck('fcm-toggle-os',            cfg.push_os_ativo           !== '0');

        // Status
        const configurado = cfg.fcm_project_id && cfg.fcm_vapid_key;
        if (configurado) {
            _setStatusFCM('ok', 'Firebase configurado e ativo');
            if (statsDiv) statsDiv.style.display = 'flex';
        } else {
            _setStatusFCM('aviso', 'Firebase não configurado — preencha as credenciais abaixo');
        }

        // Carregar estatísticas
        _carregarEstatisticasFCM();

    } catch (e) {
        console.error('[FCM] Erro ao carregar config:', e);
        _setStatusFCM('erro', 'Falha de comunicação com a API.');
    }
}

async function _carregarEstatisticasFCM() {
    try {
        const json = await _get(_API_FCM, 'estatisticas');
        if (json.sucesso && json.dados) {
            const el1 = document.getElementById('fcm-total-tokens');
            const el2 = document.getElementById('fcm-total-enviadas');
            if (el1) el1.textContent = json.dados.tokens_ativos || 0;
            if (el2) el2.textContent = json.dados.notificacoes_enviadas || 0;
            const statsDiv = document.getElementById('fcm-status-stats');
            if (statsDiv) statsDiv.style.display = 'flex';
        }
    } catch (e) {
        console.warn('[FCM] Estatísticas não disponíveis:', e);
    }
}

async function _salvarConfigFCM() {
    const btn = document.getElementById('fcm-btn-salvar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }

    const body = {
        fcm_project_id:            _getVal('fcm-project-id'),
        fcm_api_key:               _getVal('fcm-api-key'),
        fcm_auth_domain:           _getVal('fcm-auth-domain'),
        fcm_messaging_sender_id:   _getVal('fcm-sender-id'),
        fcm_app_id:                _getVal('fcm-app-id'),
        fcm_vapid_key:             _getVal('fcm-vapid-key'),
        fcm_server_key:            _getVal('fcm-server-key'),
        pwa_ativo:                 _getCheck('fcm-toggle-pwa')          ? '1' : '0',
        push_visitante_ativo:      _getCheck('fcm-toggle-visitante')    ? '1' : '0',
        push_inadimplencia_ativo:  _getCheck('fcm-toggle-inadimplencia')? '1' : '0',
        push_comunicado_ativo:     _getCheck('fcm-toggle-comunicado')   ? '1' : '0',
        push_os_ativo:             _getCheck('fcm-toggle-os')           ? '1' : '0',
    };

    // Validação básica
    if (!body.fcm_project_id || !body.fcm_api_key || !body.fcm_vapid_key) {
        _toast('Preencha ao menos o Project ID, API Key e VAPID Key.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Configurações'; }
        return;
    }

    try {
        const json = await _post(_API_FCM, 'salvar_config', body);
        if (json.sucesso) {
            _toast('Configurações do Firebase salvas com sucesso!', 'success');
            _setStatusFCM('ok', 'Firebase configurado e ativo');
            _carregarEstatisticasFCM();
        } else {
            _toast(json.mensagem || 'Erro ao salvar configurações.', 'error');
        }
    } catch (e) {
        _toast('Falha de comunicação com a API.', 'error');
    }

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Configurações'; }
}

function _abrirModalTeste() {
    const modal = document.getElementById('fcm-modal-teste');
    if (modal) modal.style.display = 'flex';
}

function _fecharModalTeste() {
    const modal = document.getElementById('fcm-modal-teste');
    if (modal) modal.style.display = 'none';
}

async function _enviarTeste() {
    const btn = document.getElementById('fcm-modal-teste-enviar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }

    const titulo  = document.getElementById('fcm-teste-titulo')?.value || 'Teste de Notificação';
    const corpo   = document.getElementById('fcm-teste-corpo')?.value  || 'Notificação de teste.';

    try {
        const json = await _post(_API_FCM, 'enviar', {
            titulo,
            corpo,
            tipo:       'geral',
            destino:    'todos',
            morador_id: null,
            unidade_id: null,
        });

        if (json.sucesso) {
            _fecharModalTeste();
            _toast(`Notificação enviada para ${json.dados?.enviadas || 0} dispositivo(s)!`, 'success');
        } else {
            _toast(json.mensagem || 'Erro ao enviar notificação de teste.', 'error');
        }
    } catch (e) {
        _toast('Falha de comunicação com a API.', 'error');
    }

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Teste'; }
}

// ─── Utilitários FCM ──────────────────────────────────────────────────────────
function _setStatusFCM(tipo, texto) {
    const ind = document.getElementById('fcm-status-indicator');
    const txt = document.getElementById('fcm-status-texto');
    if (!ind || !txt) return;

    const icones = { ok: 'fas fa-check-circle', aviso: 'fas fa-exclamation-triangle', erro: 'fas fa-times-circle' };
    const cores  = { ok: '#22c55e', aviso: '#f59e0b', erro: '#ef4444' };

    ind.style.color = cores[tipo] || '#94a3b8';
    ind.innerHTML   = `<i class="${icones[tipo] || 'fas fa-circle'}"></i> <span id="fcm-status-texto">${texto}</span>`;
}

function _setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

function _getVal(id) {
    return document.getElementById(id)?.value?.trim() || '';
}

function _setCheck(id, val) {
    const el = document.getElementById(id);
    if (el) el.checked = !!val;
}

function _getCheck(id) {
    return document.getElementById(id)?.checked || false;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ABA 2 — MINHAS NOTIFICAÇÕES (histórico)
// ═══════════════════════════════════════════════════════════════════════════════

const _ICONES_EVENTO = {
    'os_criada':             'fa-plus-circle',
    'os_prioridade_urgente': 'fa-exclamation-triangle',
    'os_prioridade_alta':    'fa-exclamation-circle',
    'os_aberta_horas':       'fa-clock',
    'os_finalizada':         'fa-check-circle',
    'os_previsao_definida':  'fa-calendar-check',
};
const _CORES_NOTIF = { blue: '#3b82f6', green: '#22c55e', red: '#ef4444', orange: '#f97316', amber: '#f59e0b' };

async function _carregarHistorico(pag = 1) {
    const lista = document.getElementById('hist-lista');
    if (!lista) return;
    _histPagina = pag;

    const evento = document.getElementById('hist-filtro-evento')?.value || '';
    const lido   = document.getElementById('hist-filtro-lido')?.value   ?? '';
    const params = { pag };
    if (evento) params.evento = evento;
    if (lido !== '') params.lido = lido;

    lista.innerHTML = '<div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    document.getElementById('hist-paginacao').innerHTML = '';

    const json = await _get(_API_NOTIF, 'historico', params);
    if (!json.sucesso) {
        lista.innerHTML = '<div class="notif-loading">Erro ao carregar notificações.</div>';
        return;
    }

    const { alertas, total, paginas, pagina } = json.dados;
    const statsEl = document.getElementById('hist-stats');
    if (statsEl) statsEl.textContent = `${total} notificaç${total === 1 ? 'ão' : 'ões'}`;

    if (!alertas.length) {
        lista.innerHTML = '<div class="notif-loading"><i class="fas fa-inbox"></i><br>Nenhuma notificação encontrada.</div>';
        return;
    }

    lista.innerHTML = alertas.map(a => {
        const cor  = _CORES_NOTIF[a.cor] || '#3b82f6';
        const lido = a.lido == 1;
        return `<div class="notif-hist-item${lido ? ' lido' : ''}">
            <div class="notif-hist-icone" style="color:${cor}">
                <i class="fas ${_ICONES_EVENTO[a.evento] || 'fa-bell'}"></i>
            </div>
            <div class="notif-hist-info">
                <p class="notif-hist-titulo">${a.titulo}</p>
                ${a.corpo ? `<p class="notif-hist-corpo">${a.corpo}</p>` : ''}
                <div class="notif-hist-meta">
                    <span><i class="fas fa-clock"></i> ${a.criado_fmt}</span>
                    ${a.link_id ? `<span class="notif-hist-link" onclick="window._notifAbrirOS(${a.link_id})"><i class="fas fa-external-link-alt"></i> Ver O.S</span>` : ''}
                </div>
            </div>
            <div class="notif-hist-acoes">
                ${!lido
                    ? `<button type="button" class="notif-hist-btn-lido" title="Marcar como lido"
                          onclick="window._notifMarcarLido(${a.dest_id},this)">
                          <i class="fas fa-check"></i>
                       </button>`
                    : `<span class="notif-hist-badge-lido" title="Lida"><i class="fas fa-check-double"></i></span>`
                }
            </div>
        </div>`;
    }).join('');

    // Paginação
    const pagEl = document.getElementById('hist-paginacao');
    if (pagEl && paginas > 1) {
        let html = '';
        if (pagina > 1)     html += `<button type="button" onclick="window._notifHistPage(${pagina-1})"><i class="fas fa-chevron-left"></i> Anterior</button>`;
        html += `<span>Página ${pagina} de ${paginas}</span>`;
        if (pagina < paginas) html += `<button type="button" onclick="window._notifHistPage(${pagina+1})">Próxima <i class="fas fa-chevron-right"></i></button>`;
        pagEl.innerHTML = html;
    }
}

function _bindHistorico() {
    _on(document.getElementById('hist-btn-filtrar'), 'click', () => _carregarHistorico(1));
    _on(document.getElementById('hist-btn-marcar-todos'), 'click', async () => {
        await _post(_API_NOTIF, 'marcar_todos_lidos', {});
        _carregarHistorico(_histPagina);
    });
}

window._notifAbrirOS = function(osId) {
    if (window.AppRouter?.navigate) window.AppRouter.navigate('ordens_servico');
};

window._notifMarcarLido = async function(destId, btn) {
    btn.disabled = true;
    await _post(_API_NOTIF, 'marcar_lido', { dest_id: destId });
    _carregarHistorico(_histPagina);
};

window._notifHistPage = function(pag) { _carregarHistorico(pag); };

// ─── Toast ────────────────────────────────────────────────────────────────────
function _toast(msg, tipo = 'success') {
    let t = document.getElementById('notif-page-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'notif-page-toast';
        t.style.cssText = 'position:fixed;bottom:2rem;right:2rem;z-index:999999;padding:.875rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;color:#fff;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .3s;';
        document.body.appendChild(t);
    }
    t.style.background = tipo === 'success' ? '#22c55e' : '#ef4444';
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._to);
    t._to = setTimeout(() => { t.style.opacity = '0'; }, 3500);
}
