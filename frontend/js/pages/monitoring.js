'use strict';

const MONITORING_API = '/api/api_monitoring_update.php';
let monitoringReleases = [];
let monitoringToastTimer = null;

function monitoringEl(id) { return document.getElementById(id); }
function monitoringEscape(value) { return String(value ?? '').replace(/[&<>'"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[c])); }
function monitoringStatus(value) {
    const labels = { rascunho:'Rascunho', publicado:'Publicado', arquivado:'Arquivado', interno:'Interno', teste:'Teste', producao:'Produção' };
    return `<span class="monitoring-badge ${monitoringEscape(value || '')}">${monitoringEscape(labels[value] || value || '-')}</span>`;
}
function monitoringToast(message, type = 'success') {
    const box = monitoringEl('monitoring-toast'); if (!box) return;
    box.textContent = message; box.className = `monitoring-toast ${type}`;
    clearTimeout(monitoringToastTimer); monitoringToastTimer = setTimeout(() => { box.className = 'monitoring-toast'; }, 5000);
}
async function monitoringRequest(action, method = 'GET', body = null) {
    const options = { method, credentials:'include', headers:{ Accept:'application/json' } };
    if (body !== null) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
    try {
        const response = await fetch(`${MONITORING_API}?action=${encodeURIComponent(action)}`, options);
        const text = await response.text(); let data = null; try { data = text ? JSON.parse(text) : null; } catch (_) {}
        return response.ok && data ? data : { sucesso:false, mensagem:data?.mensagem || `Erro HTTP ${response.status}.` };
    } catch (error) { console.error('[Monitoring]', error); return { sucesso:false, mensagem:'Não foi possível comunicar com o servidor.' }; }
}
function monitoringRender() {
    const list = monitoringEl('monitoring-lista'); if (!list) return;
    monitoringEl('monitoring-total').textContent = monitoringReleases.length;
    monitoringEl('monitoring-publicadas').textContent = monitoringReleases.filter(r => r.status === 'publicado').length;
    monitoringEl('monitoring-obrigatorias').textContent = monitoringReleases.filter(r => Number(r.mandatory) === 1).length;
    if (!monitoringReleases.length) { list.innerHTML = '<div class="monitoring-empty">Nenhuma release cadastrada.</div>'; return; }
    list.innerHTML = `<div class="monitoring-table-wrap"><table class="monitoring-table"><thead><tr><th>Versão</th><th>Canal</th><th>Status</th><th>Obrigatória</th><th>Publicada em</th><th>Ações</th></tr></thead><tbody>${monitoringReleases.map(r => `<tr><td><strong>${monitoringEscape(r.version_name)}</strong><br><small>code ${monitoringEscape(r.version_code)}</small></td><td>${monitoringStatus(r.channel)}</td><td>${monitoringStatus(r.status)}</td><td>${Number(r.mandatory) === 1 ? '<strong class="danger">Sim</strong>' : 'Não'}</td><td>${monitoringEscape(r.published_at || '-')}</td><td><button class="monitoring-mini" data-monitoring-edit="${r.id}"><i class="fas fa-pen"></i> Editar</button>${r.status !== 'publicado' ? `<button class="monitoring-mini primary" data-monitoring-publish="${r.id}"><i class="fas fa-rocket"></i> Publicar</button>` : ''}${r.status !== 'arquivado' ? `<button class="monitoring-mini warning" data-monitoring-archive="${r.id}"><i class="fas fa-box-archive"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
}
async function monitoringLoad() {
    const list = monitoringEl('monitoring-lista'); if (list) list.innerHTML = '<div class="monitoring-loading"><i class="fas fa-spinner fa-spin"></i> Carregando releases...</div>';
    const response = await monitoringRequest('list');
    if (!response.sucesso) { if (list) list.innerHTML = `<div class="monitoring-empty">${monitoringEscape(response.mensagem)}</div>`; return; }
    monitoringReleases = response.dados?.releases || []; monitoringRender();
}
function monitoringOpen(release = null) {
    const form = monitoringEl('monitoring-form'); form.reset(); monitoringEl('monitoring-id').value = '';
    monitoringEl('monitoring-channel').value = 'interno'; monitoringEl('monitoring-status').value = 'rascunho'; monitoringEl('monitoring-minimum').value = '0';
    monitoringEl('monitoring-form-title').textContent = release ? 'Editar release' : 'Nova release';
    if (release) {
        monitoringEl('monitoring-id').value = release.id; monitoringEl('monitoring-version-name').value = release.version_name || ''; monitoringEl('monitoring-version-code').value = release.version_code || '';
        monitoringEl('monitoring-channel').value = release.channel || 'interno'; monitoringEl('monitoring-status').value = release.status || 'rascunho'; monitoringEl('monitoring-download-url').value = release.download_url || '';
        monitoringEl('monitoring-sha256').value = release.sha256 || ''; monitoringEl('monitoring-size').value = release.size_bytes || ''; monitoringEl('monitoring-minimum').value = release.minimum_version_code || 0;
        monitoringEl('monitoring-mandatory').checked = Number(release.mandatory) === 1; monitoringEl('monitoring-notes').value = release.release_notes || '';
    }
    const panel = monitoringEl('monitoring-form-panel'); panel.hidden = false; panel.scrollIntoView({ behavior:'smooth', block:'start' });
}
async function monitoringSave(event) {
    event.preventDefault();
    const payload = { id:monitoringEl('monitoring-id').value, version_name:monitoringEl('monitoring-version-name').value.trim(), version_code:monitoringEl('monitoring-version-code').value, channel:monitoringEl('monitoring-channel').value, status:monitoringEl('monitoring-status').value, download_url:monitoringEl('monitoring-download-url').value.trim(), sha256:monitoringEl('monitoring-sha256').value.trim(), size_bytes:monitoringEl('monitoring-size').value, minimum_version_code:monitoringEl('monitoring-minimum').value, mandatory:monitoringEl('monitoring-mandatory').checked, release_notes:monitoringEl('monitoring-notes').value.trim() };
    const response = await monitoringRequest('save', 'POST', payload); if (!response.sucesso) return monitoringToast(response.mensagem, 'error');
    monitoringToast(response.mensagem); monitoringEl('monitoring-form-panel').hidden = true; await monitoringLoad();
}
async function monitoringAction(action, id) {
    const label = action === 'publish' ? 'publicar' : 'arquivar'; if (!confirm(`Deseja ${label} esta release?`)) return;
    const response = await monitoringRequest(action, 'POST', { id }); if (!response.sucesso) return monitoringToast(response.mensagem, 'error');
    monitoringToast(response.mensagem); await monitoringLoad();
}
function monitoringListen() {
    monitoringEl('monitoring-nova-release')?.addEventListener('click', () => monitoringOpen());
    monitoringEl('monitoring-fechar')?.addEventListener('click', () => { monitoringEl('monitoring-form-panel').hidden = true; });
    monitoringEl('monitoring-cancelar')?.addEventListener('click', () => { monitoringEl('monitoring-form-panel').hidden = true; });
    monitoringEl('monitoring-atualizar')?.addEventListener('click', monitoringLoad);
    monitoringEl('monitoring-form')?.addEventListener('submit', monitoringSave);
    monitoringEl('monitoring-lista')?.addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button) return;
        const id = Number(button.dataset.monitoringEdit || button.dataset.monitoringPublish || button.dataset.monitoringArchive);
        if (button.dataset.monitoringEdit) monitoringOpen(monitoringReleases.find(r => Number(r.id) === id));
        if (button.dataset.monitoringPublish) monitoringAction('publish', id);
        if (button.dataset.monitoringArchive) monitoringAction('archive', id);
    });
}
export function init() {
    const permission = String(localStorage.getItem('usuario_permissao') || '').toLowerCase();
    if (permission && permission !== 'super_admin') { monitoringToast('Acesso exclusivo para Super-Admin.', 'error'); return; }
    monitoringListen(); monitoringLoad();
}
export function destroy() { clearTimeout(monitoringToastTimer); }
