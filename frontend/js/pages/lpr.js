'use strict';

const MONITORING_API = '/api/api_monitoramento.php';
let lprTimer = null;

async function lprRequest(action, method = 'GET', payload = null, query = {}) {
    const params = new URLSearchParams({ action });
    Object.entries(query).forEach(([key, value]) => params.set(key, String(value)));
    const url = `${MONITORING_API}?${params.toString()}`;
    const options = { method, credentials: 'include', headers: { 'Content-Type': 'application/json' } };
    if (payload && method !== 'GET') options.body = JSON.stringify(payload);
    const response = await fetch(url, options);
    const text = await response.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (_) { data = { sucesso: false, mensagem: 'Resposta inválida da API.' }; }
    if (!response.ok) throw new Error(data.mensagem || `HTTP ${response.status}`);
    return data;
}

function lprLog(message, data = null) {
    if (data) console.debug('[LPR]', message, data);
    else console.debug('[LPR]', message);
}

function lprEsc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
}

function lprStatusBadge(status) {
    const labels = { ativo: 'Ativo', online: 'Online', offline: 'Offline', bloqueado: 'Bloqueado', revogado: 'Revogado', pendente_ativacao: 'Pendente' };
    const cls = { ativo: 'lpr-status-ok', online: 'lpr-status-ok', offline: 'lpr-status-offline', bloqueado: 'lpr-status-warn', revogado: 'lpr-status-offline', pendente_ativacao: 'lpr-status-warn' };
    return `<span class="lpr-status ${cls[status] || 'lpr-status-warn'}">${labels[status] || lprEsc(status || 'Desconhecido')}</span>`;
}

function lprShowAlert(message, type = 'success') {
    const alert = document.getElementById('lprAlert');
    if (!alert) return;
    alert.textContent = message;
    alert.className = `lpr-alert visible ${type}`;
    window.setTimeout(() => alert.classList.remove('visible'), 5000);
}

function renderAgents(agents) {
    const body = document.getElementById('lprAgentsBody');
    if (!body) return;
    if (!agents.length) {
        body.innerHTML = '<tr><td colspan="6" class="lpr-empty">Nenhuma máquina habilitada neste tenant.</td></tr>';
        return;
    }
    body.innerHTML = agents.map(agent => `<tr>
        <td><strong>${lprEsc(agent.nome || 'Sem nome')}</strong><small>${lprEsc(agent.local || '-')} · #${Number(agent.id)}</small></td>
        <td>${lprStatusBadge(agent.status)}</td>
        <td>${lprEsc(agent.lpr_engine || '-')}<small>${lprEsc(agent.onnx_backend || '-')}</small></td>
        <td>${lprEsc(agent.last_heartbeat_at || 'Nunca')}</td>
        <td>${lprEsc(agent.agent_version || '-')}</td>
        <td>${lprEsc(agent.last_error_code || 'Nenhum')}</td>
    </tr>`).join('');
}

function renderCameras(cameras) {
    const body = document.getElementById('lprCamerasBody');
    if (!body) return;
    if (!cameras.length) {
        body.innerHTML = '<tr><td colspan="6" class="lpr-empty">Nenhuma câmera reportada por heartbeat.</td></tr>';
        return;
    }
    body.innerHTML = cameras.map(camera => `<tr>
        <td><strong>${lprEsc(camera.nome || camera.external_key || '-')}</strong><small>${lprEsc(camera.external_key || '-')}</small></td>
        <td>${lprEsc(camera.agente_nome || `#${Number(camera.agente_id || 0)}`)}</td>
        <td>${lprStatusBadge(camera.ultimo_status || 'offline')}</td>
        <td>${lprEsc(camera.ultimo_frame_at || 'Nunca')}</td>
        <td>${Number(camera.frames_perdidos || 0)}</td>
        <td>${lprEsc(camera.ultimo_erro_code || 'Nenhum')}</td>
    </tr>`).join('');
}

async function loadLpr() {
    const header = document.getElementById('lprHeaderStatus');
    try {
        const [agentsResponse, configResponse, eventsResponse] = await Promise.all([
            lprRequest('listar_agentes'),
            lprRequest('configuracao'),
            lprRequest('acessos_recentes', 'GET', null, { limite: 100 })
        ]);
        const agents = agentsResponse.dados?.agentes || [];
        const cameras = agentsResponse.dados?.cameras || [];
        const config = configResponse.dados || agentsResponse.dados?.configuracao || {};
        const events = eventsResponse.dados?.eventos || [];
        renderAgents(agents);
        renderCameras(cameras);
        document.getElementById('lprEngine').value = config.lpr_engine || 'fastalpr';
        document.getElementById('lprBackend').value = config.onnx_backend || 'cpu';
        document.getElementById('lprConfidence').value = Number(config.confidence_min || 0.8);
        document.getElementById('lprDedup').value = Number(config.dedup_seconds || 20);
        document.getElementById('lprKpiAgents').textContent = agents.filter(item => item.status === 'ativo').length;
        document.getElementById('lprKpiCameras').textContent = cameras.length;
        document.getElementById('lprKpiRetention').textContent = Number(config.retencao_dias || 30);
        document.getElementById('lprKpiEvents').textContent = events.length;
        document.getElementById('lprRetentionLarge').textContent = `${Number(config.retencao_dias || 30)} dias`;
        if (header) header.innerHTML = '<i class="fas fa-circle lpr-online-dot"></i> API conectada';
        lprLog('Dados carregados', { agents: agents.length, cameras: cameras.length, events: events.length });
    } catch (error) {
        if (header) header.innerHTML = '<i class="fas fa-triangle-exclamation"></i> API indisponível';
        lprShowAlert(error.message || 'Não foi possível carregar o Monitoramento.', 'error');
        lprLog('Erro ao carregar LPR', error);
    }
}

async function saveLpr() {
    const payload = {
        retencao_dias: Number(document.getElementById('lprRetentionLarge')?.textContent.replace(/\D/g, '') || 30),
        modulo_ativo: 1,
        lpr_engine: document.getElementById('lprEngine')?.value || 'fastalpr',
        onnx_backend: document.getElementById('lprBackend')?.value || 'cpu',
        confidence_min: Number(document.getElementById('lprConfidence')?.value || 0.8),
        dedup_seconds: Number(document.getElementById('lprDedup')?.value || 20),
        versao_minima_agente: '0.1.0'
    };
    try {
        await lprRequest('salvar_configuracao', 'POST', payload);
        lprShowAlert('Configuração do Monitoring salva.', 'success');
        await loadLpr();
    } catch (error) {
        lprShowAlert(error.message || 'Não foi possível salvar a configuração.', 'error');
    }
}

export function init() {
    lprLog('init()');
    loadLpr();
    lprTimer = window.setInterval(loadLpr, 30000);
    document.getElementById('btnAtualizarLpr')?.addEventListener('click', loadLpr);
    document.getElementById('btnSalvarLpr')?.addEventListener('click', saveLpr);
}

export function destroy() {
    lprLog('destroy()');
    if (lprTimer) window.clearInterval(lprTimer);
    lprTimer = null;
}
