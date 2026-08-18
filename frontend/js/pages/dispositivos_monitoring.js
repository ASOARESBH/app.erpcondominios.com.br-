// ERP CONDOMÍNIOS MONITORING dentro de Dispositivos.
// Fluxo: código do agente local → habilitação no tenant → credencial única → heartbeat/configuração.

const monitoringLog = (...args) => console.debug('[DispositivosMonitoring]', ...args);

let monitoringAgents = [];
let monitoringRefreshTimer = null;
const PAIRING_CODE_PATTERN = /^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/;

export function init() {
    monitoringLog('Inicializando módulo de dispositivos Monitoring');
    document.getElementById('monitoring-refresh')?.addEventListener('click', carregarMonitoring);
    document.getElementById('monitoring-enable')?.addEventListener('click', habilitarMonitoring);
    document.getElementById('monitoring-config-form')?.addEventListener('submit', salvarConfiguracaoMonitoring);
    document.getElementById('monitoring-pending-agent')?.addEventListener('change', selecionarAgentePendente);
    document.getElementById('monitoring-agents-body')?.addEventListener('click', tratarAcaoAgente);
    document.getElementById('monitoring-secret-close')?.addEventListener('click', fecharModalMonitoringSecret);
    document.getElementById('monitoring-secret-done')?.addEventListener('click', fecharModalMonitoringSecret);
    document.getElementById('monitoring-copy-secret')?.addEventListener('click', copiarCredencialMonitoring);
    carregarMonitoring();
    monitoringRefreshTimer = window.setInterval(carregarMonitoring, 30000);
}

export function destroy() {
    if (monitoringRefreshTimer) {
        window.clearInterval(monitoringRefreshTimer);
        monitoringRefreshTimer = null;
    }
    monitoringLog('Módulo de dispositivos Monitoring encerrado');
}

async function monitoringApi(action, options = {}) {
    const method = options.method || 'GET';
    const response = await fetch(`../api/api_monitoramento.php?action=${encodeURIComponent(action)}`, {
        method,
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await response.json().catch(() => ({ sucesso: false, mensagem: `Resposta inválida (${response.status})` }));
    if (!response.ok || !data.sucesso) {
        const error = new Error(data.mensagem || `Falha HTTP ${response.status}`);
        error.code = data.codigo || `HTTP_${response.status}`;
        throw error;
    }
    return data;
}

async function carregarMonitoring() {
    const refresh = document.getElementById('monitoring-refresh');
    if (refresh) refresh.disabled = true;
    try {
        const data = await monitoringApi('listar_agentes');
        monitoringAgents = data.dados?.agentes || [];
        renderizarAgentes(monitoringAgents);
        renderizarPendentes(monitoringAgents);
        renderizarConfiguracao(data.dados?.configuracao || {});
        monitoringLog('Agentes Monitoring carregados', { total: monitoringAgents.length });
    } catch (error) {
        monitoringLog('Falha ao carregar Monitoring', error);
        setMonitoringMessage('monitoring-pairing-message', error.message, true);
        renderizarAgentes([]);
    } finally {
        if (refresh) refresh.disabled = false;
    }
}

function renderizarPendentes(agentes) {
    const select = document.getElementById('monitoring-pending-agent');
    if (!select) return;
    const current = select.value;
    const pendentes = agentes.filter((agent) => ['pendente_ativacao', 'solicitado'].includes(agent.status));
    select.innerHTML = '<option value="">Selecione uma máquina registrada</option>';
    pendentes.forEach((agent) => {
        const option = document.createElement('option');
        option.value = String(agent.id);
        option.textContent = `${agent.nome || 'Máquina Monitoring'} · ${agent.pairing_code_preview || 'código pendente'}`;
        select.appendChild(option);
    });
    if (current && pendentes.some((agent) => String(agent.id) === current)) select.value = current;
}

function selecionarAgentePendente(event) {
    const agent = monitoringAgents.find((item) => String(item.id) === String(event.target.value));
    if (!agent) return;
    document.getElementById('monitoring-agent-id').value = agent.id || '';
    document.getElementById('monitoring-agent-name').value = agent.nome || '';
    document.getElementById('monitoring-agent-local').value = agent.local || '';
    document.getElementById('monitoring-agent-responsible').value = agent.responsavel || '';
    document.getElementById('monitoring-agent-note').value = agent.observacao || '';
    setMonitoringMessage('monitoring-pairing-message', `Máquina selecionada. Cole o código completo exibido no painel local.`, false);
}

function renderizarConfiguracao(config) {
    const setValue = (id, value) => {
        const element = document.getElementById(id);
        if (element && value !== undefined && value !== null) element.value = value;
    };
    const active = document.getElementById('monitoring-module-active');
    if (active) active.checked = Boolean(Number(config.modulo_ativo ?? 1));
    setValue('monitoring-retention', config.retencao_dias ?? 30);
    setValue('monitoring-engine', config.lpr_engine ?? 'fastalpr');
    setValue('monitoring-backend', config.onnx_backend ?? 'cpu');
    setValue('monitoring-confidence', config.confidence_min ?? 0.8);
    setValue('monitoring-dedup', config.dedup_seconds ?? 20);
}

function renderizarAgentes(agentes) {
    const body = document.getElementById('monitoring-agents-body');
    const count = document.getElementById('monitoring-agent-count');
    if (!body) return;
    if (count) count.textContent = `${agentes.length} agente${agentes.length === 1 ? '' : 's'}`;
    if (!agentes.length) {
        body.innerHTML = '<tr><td colspan="6" class="monitoring-empty">Nenhuma máquina Monitoring vinculada a este tenant.</td></tr>';
        return;
    }
    body.innerHTML = agentes.map((agent) => {
        const status = String(agent.status || 'novo');
        const action = status === 'ativo'
            ? `<button type="button" class="monitoring-table-action monitoring-action-revoke" data-agent-action="revogar" data-agent-id="${escapeHtml(agent.id)}">Revogar</button>`
            : `<button type="button" class="monitoring-table-action monitoring-action-enable" data-agent-action="selecionar" data-agent-id="${escapeHtml(agent.id)}">Selecionar</button>`;
        return `<tr>
            <td><strong>${escapeHtml(agent.nome || 'Máquina Monitoring')}</strong><small>${escapeHtml(agent.local || 'Local não informado')}</small></td>
            <td><code>${escapeHtml(agent.install_id || '—').slice(0, 14)}…</code></td>
            <td><span class="monitoring-status monitoring-status-${escapeHtml(status)}">${escapeHtml(status)}</span></td>
            <td>${escapeHtml(agent.agent_version || '—')}</td>
            <td>${escapeHtml(agent.last_heartbeat_at ? new Date(agent.last_heartbeat_at).toLocaleString('pt-BR') : 'Aguardando')}</td>
            <td>${action}</td>
        </tr>`;
    }).join('');
}

async function habilitarMonitoring() {
    const button = document.getElementById('monitoring-enable');
    const rawCode = document.getElementById('monitoring-pairing-code')?.value || '';
    const code = rawCode.trim().toUpperCase().replace(/\s+/g, '');
    const agentId = Number(document.getElementById('monitoring-agent-id')?.value || 0);
    if (!code) {
        setMonitoringMessage('monitoring-pairing-message', 'Cole o código completo exibido no painel local.', true);
        return;
    }
    if (!PAIRING_CODE_PATTERN.test(code)) {
        setMonitoringMessage('monitoring-pairing-message', 'Código inválido. Use o formato exibido no painel local, por exemplo FLLY-4CQQ.', true);
        return;
    }
    if (button) button.disabled = true;
    try {
        const data = await monitoringApi('habilitar_agente', {
            method: 'POST',
            body: {
                agent_id: agentId,
                pairing_code: code,
                nome: document.getElementById('monitoring-agent-name')?.value.trim() || 'Máquina Monitoring',
                local: document.getElementById('monitoring-agent-local')?.value.trim() || '',
                responsavel: document.getElementById('monitoring-agent-responsible')?.value.trim() || '',
                observacao: document.getElementById('monitoring-agent-note')?.value.trim() || '',
            },
        });
        const secret = data.dados?.activation_secret || '';
        document.getElementById('monitoring-generated-secret').textContent = secret || 'Credencial não retornada';
        document.getElementById('monitoring-secret-overlay')?.classList.add('active');
        setMonitoringMessage('monitoring-pairing-message', 'Máquina habilitada. Guarde a credencial exibida.', false);
        document.getElementById('monitoring-pairing-code').value = '';
        document.getElementById('monitoring-agent-id').value = '';
        document.getElementById('monitoring-pending-agent').value = '';
        await carregarMonitoring();
    } catch (error) {
        monitoringLog('Falha ao habilitar máquina', { code: error.code || 'UNKNOWN', message: error.message });
        const message = error.code === 'PAIRING_REQUEST_NOT_FOUND'
            ? 'Não há solicitação pendente para este código. No computador Windows, abra o painel local do Monitoring e gere ou envie um novo código antes de tentar novamente.'
            : error.message;
        setMonitoringMessage('monitoring-pairing-message', message, true);
    } finally {
        if (button) button.disabled = false;
    }
}

async function salvarConfiguracaoMonitoring(event) {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
        await monitoringApi('salvar_configuracao', {
            method: 'POST',
            body: {
                modulo_ativo: document.getElementById('monitoring-module-active')?.checked ? 1 : 0,
                retencao_dias: Number(document.getElementById('monitoring-retention')?.value || 30),
                lpr_engine: document.getElementById('monitoring-engine')?.value || 'fastalpr',
                onnx_backend: document.getElementById('monitoring-backend')?.value || 'cpu',
                confidence_min: Number(document.getElementById('monitoring-confidence')?.value || 0.8),
                dedup_seconds: Number(document.getElementById('monitoring-dedup')?.value || 20),
            },
        });
        setMonitoringMessage('monitoring-config-message', 'Configuração salva e será aplicada no próximo heartbeat.', false);
    } catch (error) {
        monitoringLog('Falha ao salvar configuração Monitoring', error);
        setMonitoringMessage('monitoring-config-message', error.message, true);
    } finally {
        if (button) button.disabled = false;
    }
}

async function revogarAgente(agentId) {
    if (!window.confirm('Revogar esta máquina? A sincronização será bloqueada no próximo heartbeat.')) return;
    try {
        await monitoringApi('revogar_agente', { method: 'POST', body: { agent_id: Number(agentId) } });
        await carregarMonitoring();
    } catch (error) {
        setMonitoringMessage('monitoring-pairing-message', error.message, true);
    }
}

function tratarAcaoAgente(event) {
    const button = event.target.closest('[data-agent-action]');
    if (!button) return;
    const id = button.dataset.agentId;
    if (button.dataset.agentAction === 'revogar') revogarAgente(id);
    if (button.dataset.agentAction === 'selecionar') {
        const select = document.getElementById('monitoring-pending-agent');
        if (select) {
            select.value = id;
            select.dispatchEvent(new Event('change'));
            document.getElementById('monitoring-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

function setMonitoringMessage(id, message, isError) {
    const element = document.getElementById(id);
    if (!element) return;
    element.textContent = message || '';
    element.classList.toggle('monitoring-message-error', Boolean(isError));
    element.classList.toggle('monitoring-message-success', !isError && Boolean(message));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}

function fecharModalMonitoringSecret() {
    document.getElementById('monitoring-secret-overlay')?.classList.remove('active');
}

async function copiarCredencialMonitoring() {
    const secret = document.getElementById('monitoring-generated-secret')?.textContent || '';
    if (!secret || secret === 'Credencial não retornada') return;
    try {
        await navigator.clipboard.writeText(secret);
        setMonitoringMessage('monitoring-pairing-message', 'Credencial copiada. Cole-a no painel local do Windows.', false);
    } catch (error) {
        monitoringLog('Clipboard indisponível', error);
    }
}

window.fecharModalMonitoringSecret = fecharModalMonitoringSecret;
window.copiarCredencialMonitoring = copiarCredencialMonitoring;
