/**
 * ============================================================
 * Painel de Notificações Push — Sistema Interno ERP
 * Arquivo: frontend/js/pages/notificacoes_push.js
 * ============================================================
 */

const API_PUSH = '../api/api_pwa_push.php';
const API_MORADORES = '../api/api_moradores.php';

// ── Lifecycle ────────────────────────────────────────────────
export function init() {
    console.log('[NotifPush] Inicializando painel de notificações push...');
    _carregarEstatisticas();
    _carregarHistorico();
    _bindEventos();
    _carregarSelectsMoradores();
}

export function destroy() {
    console.log('[NotifPush] Destruindo painel de notificações push.');
}

// ── Bind de eventos ──────────────────────────────────────────
function _bindEventos() {
    document.getElementById('btnNovaNotif')?.addEventListener('click', () => {
        pushAbrirModal('modalNovaNotif');
    });

    document.getElementById('btnConfigFCM')?.addEventListener('click', () => {
        _carregarConfigFCM();
        pushAbrirModal('modalConfigFCM');
    });

    // Fechar modal clicando no overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) pushFecharModal(overlay.id);
        });
    });
}

// ── Carregar estatísticas ────────────────────────────────────
async function _carregarEstatisticas() {
    try {
        const response = await fetch(`${API_PUSH}?action=estatisticas`);
        const data = await response.json();
        if (!data.sucesso) return;

        const s = data.dados;
        document.getElementById('statTokensAtivos').textContent = s.total_tokens_ativos ?? '—';
        document.getElementById('statMoradoresPWA').textContent = s.total_moradores_pwa ?? '—';
        document.getElementById('statTotalNotif').textContent   = s.total_notificacoes ?? '—';
        document.getElementById('statHoje').textContent         = s.notificacoes_hoje ?? '—';
    } catch (err) {
        console.error('[NotifPush] Erro ao carregar estatísticas:', err);
    }
}

// ── Carregar histórico de notificações ───────────────────────
async function _carregarHistorico() {
    const tbody = document.getElementById('tbodyNotifPush');
    if (!tbody) return;

    try {
        const response = await fetch(`${API_PUSH}?action=listar_enviadas&limite=50`);
        const data = await response.json();

        if (!data.sucesso || !data.dados?.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:40px;color:#94a3b8">Nenhuma notificação enviada ainda.</td></tr>';
            return;
        }

        const TIPOS = {
            visitante:     { label: '🚶 Visitante',     cor: '#22c55e' },
            inadimplencia: { label: '💰 Inadimplência', cor: '#ef4444' },
            comunicado:    { label: '📢 Comunicado',    cor: '#2563eb' },
            aviso:         { label: '⚠️ Aviso',         cor: '#f59e0b' },
            os:            { label: '🔧 Chamado OS',    cor: '#f97316' },
            urgente:       { label: '🚨 Urgente',       cor: '#dc2626' },
            geral:         { label: '🔔 Geral',         cor: '#6366f1' }
        };

        const STATUS = {
            pendente:  { label: 'Pendente',  cor: '#94a3b8' },
            enviando:  { label: 'Enviando',  cor: '#f59e0b' },
            concluido: { label: 'Concluído', cor: '#22c55e' },
            erro:      { label: 'Erro',      cor: '#ef4444' }
        };

        tbody.innerHTML = data.dados.map(n => {
            const tipo   = TIPOS[n.tipo]   || TIPOS.geral;
            const status = STATUS[n.status] || STATUS.pendente;
            const taxa   = n.total_tokens > 0 ? Math.round((n.total_sucesso / n.total_tokens) * 100) : 0;
            const data_fmt = n.criado_em ? new Date(n.criado_em).toLocaleString('pt-BR') : '—';

            return `<tr>
                <td style="font-weight:600;color:#6366f1">#${n.id}</td>
                <td>
                    <div style="font-weight:600;font-size:13px;color:#1e293b">${escHtml(n.titulo)}</div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px">${escHtml(n.corpo.substring(0, 60))}${n.corpo.length > 60 ? '...' : ''}</div>
                </td>
                <td><span style="background:${tipo.cor}20;color:${tipo.cor};padding:3px 8px;border-radius:12px;font-size:12px;white-space:nowrap">${tipo.label}</span></td>
                <td style="font-size:13px">${_labelDestinatario(n.destinatario)}</td>
                <td style="font-size:13px;color:#475569">${escHtml(n.enviado_por_nome || 'Sistema')}</td>
                <td>
                    <div style="font-size:13px;font-weight:600">${n.total_sucesso}/${n.total_tokens}</div>
                    <div style="font-size:11px;color:#94a3b8">${taxa}% entregue</div>
                </td>
                <td><span style="background:${status.cor}20;color:${status.cor};padding:3px 8px;border-radius:12px;font-size:12px">${status.label}</span></td>
                <td style="font-size:12px;color:#64748b;white-space:nowrap">${data_fmt}</td>
            </tr>`;
        }).join('');

    } catch (err) {
        console.error('[NotifPush] Erro ao carregar histórico:', err);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:40px;color:#ef4444">Erro ao carregar histórico.</td></tr>';
    }
}

// ── Carregar selects de moradores/unidades ───────────────────
async function _carregarSelectsMoradores() {
    try {
        // Carregar unidades
        const respUnidades = await fetch('../api/api_unidades.php?action=listar');
        const dataUnidades = await respUnidades.json();
        const selectUnidade = document.getElementById('pushUnidadeId');
        if (selectUnidade && dataUnidades.sucesso) {
            selectUnidade.innerHTML = '<option value="">Selecione a unidade...</option>' +
                (dataUnidades.dados || []).map(u => `<option value="${u.id}">${escHtml(u.identificacao || u.numero || u.nome)}</option>`).join('');
        }

        // Carregar moradores
        const respMoradores = await fetch('../api/api_moradores.php?action=listar&limite=500');
        const dataMoradores = await respMoradores.json();
        const selectMorador = document.getElementById('pushMoradorId');
        if (selectMorador && dataMoradores.sucesso) {
            selectMorador.innerHTML = '<option value="">Selecione o morador...</option>' +
                (dataMoradores.dados || []).map(m => `<option value="${m.id}">${escHtml(m.nome)}</option>`).join('');
        }
    } catch (err) {
        console.warn('[NotifPush] Erro ao carregar selects:', err);
    }
}

// ── Carregar configurações FCM ───────────────────────────────
async function _carregarConfigFCM() {
    try {
        const response = await fetch(`${API_PUSH}?action=obter_config`);
        const data = await response.json();
        if (!data.sucesso) return;

        const c = data.dados;
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
        const setChk = (id, val) => { const el = document.getElementById(id); if (el) el.checked = val === '1'; };

        set('cfgServerKey',  c.fcm_server_key);
        set('cfgProjectId',  c.fcm_project_id);
        set('cfgApiKey',     c.fcm_api_key);
        set('cfgAuthDomain', c.fcm_auth_domain);
        set('cfgSenderId',   c.fcm_messaging_sender_id);
        set('cfgAppId',      c.fcm_app_id);
        set('cfgVapidKey',   c.fcm_vapid_key);
        setChk('cfgPushVisitante',    c.push_visitante_ativo);
        setChk('cfgPushInadimplencia', c.push_inadimplencia_ativo);
        setChk('cfgPushComunicado',   c.push_comunicado_ativo);
        setChk('cfgPushOS',           c.push_os_ativo);
    } catch (err) {
        console.error('[NotifPush] Erro ao carregar config FCM:', err);
    }
}

// ── Funções globais (chamadas pelo HTML) ─────────────────────
window.pushAbrirModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'flex';
};

window.pushFecharModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
};

window.pushAlternarDestinatario = function() {
    const dest = document.getElementById('pushDestinatario')?.value;
    document.getElementById('pushCampoUnidade').style.display = dest === 'unidade' ? 'block' : 'none';
    document.getElementById('pushCampoMorador').style.display = dest === 'morador' ? 'block' : 'none';
};

window.pushEnviarNotificacao = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnEnviarNotif');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

    const dest = document.getElementById('pushDestinatario').value;
    const payload = {
        titulo:       document.getElementById('pushTitulo').value.trim(),
        corpo:        document.getElementById('pushCorpo').value.trim(),
        tipo:         document.getElementById('pushTipo').value,
        url_destino:  document.getElementById('pushUrl').value.trim() || '/frontend/portal_morador.html',
        destinatario: dest,
        morador_id:   dest === 'morador' ? (document.getElementById('pushMoradorId')?.value || null) : null,
        unidade_id:   dest === 'unidade' ? (document.getElementById('pushUnidadeId')?.value || null) : null
    };

    try {
        const response = await fetch(`${API_PUSH}?action=enviar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (data.sucesso) {
            mostrarAlerta(`✅ ${data.mensagem}`, 'success');
            pushFecharModal('modalNovaNotif');
            document.getElementById('formNovaNotif').reset();
            _carregarHistorico();
            _carregarEstatisticas();
        } else {
            mostrarAlerta(`❌ ${data.mensagem}`, 'error');
        }
    } catch (err) {
        mostrarAlerta('Erro de conexão ao enviar notificação.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Notificação';
    }
};

window.pushSalvarConfig = async function(e) {
    e.preventDefault();
    const get = (id) => document.getElementById(id)?.value?.trim() || '';
    const getChk = (id) => document.getElementById(id)?.checked ? '1' : '0';

    const config = {
        fcm_server_key:           get('cfgServerKey'),
        fcm_project_id:           get('cfgProjectId'),
        fcm_api_key:              get('cfgApiKey'),
        fcm_auth_domain:          get('cfgAuthDomain'),
        fcm_messaging_sender_id:  get('cfgSenderId'),
        fcm_app_id:               get('cfgAppId'),
        fcm_vapid_key:            get('cfgVapidKey'),
        push_visitante_ativo:     getChk('cfgPushVisitante'),
        push_inadimplencia_ativo: getChk('cfgPushInadimplencia'),
        push_comunicado_ativo:    getChk('cfgPushComunicado'),
        push_os_ativo:            getChk('cfgPushOS')
    };

    try {
        const response = await fetch(`${API_PUSH}?action=salvar_config`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(config)
        });
        const data = await response.json();
        if (data.sucesso) {
            mostrarAlerta('✅ Configurações salvas! Atualize o pwa-portal.js com as mesmas credenciais.', 'success');
            pushFecharModal('modalConfigFCM');
        } else {
            mostrarAlerta(`❌ ${data.mensagem}`, 'error');
        }
    } catch (err) {
        mostrarAlerta('Erro de conexão ao salvar configurações.', 'error');
    }
};

// ── Helpers ──────────────────────────────────────────────────
function _labelDestinatario(dest) {
    const labels = { todos: '🌐 Todos', unidade: '🏠 Unidade', morador: '👤 Morador' };
    return labels[dest] || dest;
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
