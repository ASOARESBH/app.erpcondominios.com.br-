/**
 * ============================================================
 * Central PWA — Módulo administrativo
 * Versão: 2.0.0  |  Padrão: ES Module (init/destroy)
 * ============================================================
 */

const _API = '../api/api_pwa_central.php';
let _abaAtiva   = 'dashboard';
let _dispOffset = 0;
let _dispTotal  = 0;
let _dispLimite = 25;
let _logOffset  = 0;
let _logTotal   = 0;
let _logLimite  = 50;
let _saJson     = null;  // Service Account lido do arquivo
let _qrUrl      = '';
let _refreshInterval = null;
let _debounceDispTimer = null;
let _debounceLogTimer  = null;

// QRCode library (carregada sob demanda)
let _qrLib = null;

// ── LIFECYCLE ─────────────────────────────────────────────────

export function init() {
    window.PwaCentral = _publicAPI();
    carregarDashboard();
    _carregarConfigFirebase();
    _refreshInterval = setInterval(carregarDashboard, 60000);
}

export function destroy() {
    if (_refreshInterval) { clearInterval(_refreshInterval); _refreshInterval = null; }
    delete window.PwaCentral;
}

// ── API PÚBLICA ────────────────────────────────────────────────

function _publicAPI() {
    return {
        trocarAba,
        carregarDashboard,
        executarHealthCheck,
        salvarConfigFirebase,
        salvarFlags,
        lerServiceAccount,
        salvarServiceAccount,
        publicarVersao,
        carregarDispositivos,
        paginaDispositivos,
        carregarLogs,
        paginaLogs,
        _debounceDispositivos,
        _debounceLogs,
        baixarQrCode,
        copiarUrlPortal,
        desativarDispositivo,
        ativarDispositivo,
        excluirDispositivo,
        enviarTeste,
    };
}

// ── ABAS ───────────────────────────────────────────────────────

function trocarAba(aba) {
    _abaAtiva = aba;

    document.querySelectorAll('.pwa-tab').forEach(btn => btn.classList.remove('ativo'));
    document.querySelectorAll('.pwa-painel').forEach(p => p.classList.remove('ativo'));

    const tabBtns = document.querySelectorAll('.pwa-tab');
    const tabMap  = ['dashboard','health','firebase','versao','dispositivos','estatisticas','logs'];
    const idx = tabMap.indexOf(aba);
    if (tabBtns[idx]) tabBtns[idx].classList.add('ativo');

    const painel = document.getElementById(`pwa-painel-${aba}`);
    if (painel) painel.classList.add('ativo');

    // Lazy load por aba
    if (aba === 'dispositivos')  carregarDispositivos(0);
    if (aba === 'logs')          carregarLogs(0);
    if (aba === 'estatisticas')  _carregarEstatisticas();
    if (aba === 'versao')        _carregarVersao();
    if (aba === 'health')        {} // sob demanda (botão)
}

// ── DASHBOARD ─────────────────────────────────────────────────

async function carregarDashboard() {
    try {
        const d = await _get('dashboard_status');
        if (!d.sucesso) return;
        const s = d.dados;

        _setText('kpi-tokens-ativos', s.tokens_ativos);
        _setText('kpi-moradores-pwa', `${s.moradores_pwa} morador${s.moradores_pwa !== 1 ? 'es' : ''}`);
        _setText('kpi-push-hoje', s.push_hoje);
        _setText('kpi-push-total', `${s.push_total} total enviados`);
        _setText('kpi-versao', s.versao);
        _setText('kpi-cache-version', s.cache_version);
        _setText('kpi-erros-hoje', s.logs_erros_hoje);

        // KPI cores dinâmicas
        const kpiErros = document.getElementById('kpi-erros-card');
        if (kpiErros) kpiErros.className = `pwa-kpi ${s.logs_erros_hoje > 0 ? 'vermelho' : 'verde'}`;

        // Status grid
        const statusItems = [
            { nome: 'PWA Ativo', desc: s.pwa_ativo ? 'Portal acessível aos moradores' : 'Portal em manutenção', status: s.pwa_ativo ? 'ok' : 'atencao' },
            { nome: 'Firebase SDK', desc: s.firebase_ok ? 'Credenciais configuradas' : 'Configure as credenciais', status: s.firebase_ok ? 'ok' : 'erro' },
            { nome: 'Service Account', desc: s.service_account ? `Configurado — OAuth2 ${s.oauth_valido ? 'ativo' : 'renovando'}` : 'Ausente — upload necessário', status: s.service_account ? 'ok' : 'erro' },
            { nome: 'Dispositivos', desc: `${s.tokens_ativos} ativo${s.tokens_ativos !== 1 ? 's' : ''}`, status: s.tokens_ativos > 0 ? 'ok' : 'neutro' },
            { nome: 'Push Notifications', desc: s.push_hoje > 0 ? `${s.push_hoje} enviados hoje` : 'Nenhum hoje', status: s.service_account && s.firebase_ok ? 'ok' : 'erro' },
        ];

        const grid = document.getElementById('pwa-status-grid');
        if (grid) {
            grid.innerHTML = statusItems.map(it => `
                <div class="pwa-status-item ${it.status}">
                    <span class="pwa-status-dot"></span>
                    <div class="pwa-status-info">
                        <div class="pwa-status-nome">${it.nome}</div>
                        <div class="pwa-status-desc">${it.desc}</div>
                    </div>
                </div>
            `).join('');
        }

        // QR Code
        _qrUrl = `https://${window.location.hostname}${s.install_url}`;
        _setText('pwa-qr-url-display', _qrUrl);
        _renderQrCode(_qrUrl);

    } catch (e) {
        console.error('[PwaCentral] Dashboard error:', e);
    }
}

// ── HEALTH CHECK ──────────────────────────────────────────────

async function executarHealthCheck() {
    const btn = document.querySelector('#pwa-painel-health .btn-primary');
    const res = document.getElementById('pwa-health-resultado');
    const resumo = document.getElementById('pwa-health-resumo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...'; }
    if (res) res.innerHTML = '<div class="pwa-loading"><i class="fas fa-spinner"></i> Executando diagnóstico...</div>';
    if (resumo) resumo.style.display = 'none';

    try {
        const d = await _get('health_check');
        if (!d.sucesso || !d.dados) { _mostrarErro('Erro ao executar diagnóstico'); return; }
        const { checks, erros, avisos, status_geral, executado_em } = d.dados;

        // Resumo
        if (resumo) {
            resumo.style.display = 'flex';
            resumo.innerHTML = `
                <span class="pwa-health-pill ok"><i class="fas fa-check"></i> ${checks.length - erros - avisos} OK</span>
                <span class="pwa-health-pill atencao"><i class="fas fa-exclamation-triangle"></i> ${avisos} atenção</span>
                <span class="pwa-health-pill erro"><i class="fas fa-times-circle"></i> ${erros} erro${erros !== 1 ? 's' : ''}</span>
                <span style="margin-left:auto;font-size:0.78rem;color:var(--color-text-tertiary);">Executado: ${executado_em}</span>
            `;
        }

        // Badge na aba
        const badge = document.getElementById('pwa-badge-erros');
        if (badge) { badge.style.display = erros > 0 ? '' : 'none'; badge.textContent = erros; }

        // Lista de checks
        const iconMap = { ok: '🟢', atencao: '🟡', erro: '🔴' };
        if (res) {
            res.innerHTML = `<div class="pwa-health-lista">${checks.map(c => `
                <div class="pwa-health-item">
                    <span class="pwa-health-icon">${iconMap[c.status] || '⚪'}</span>
                    <span class="pwa-health-nome">${_esc(c.item)}</span>
                    <span class="pwa-health-det" title="${_esc(c.detalhe)}">${_esc(c.detalhe)}</span>
                </div>
            `).join('')}</div>`;
        }

    } catch (e) {
        console.error('[PwaCentral] Health check error:', e);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-heartbeat"></i> Executar Diagnóstico'; }
    }
}

// ── CONFIG FIREBASE ────────────────────────────────────────────

async function _carregarConfigFirebase() {
    try {
        const d = await _get('obter_config');
        if (!d.sucesso || !d.dados) return;
        const c = d.dados;

        _setVal('cfg-api-key',       c.fcm_api_key           || '');
        _setVal('cfg-auth-domain',   c.fcm_auth_domain       || '');
        _setVal('cfg-project-id',    c.fcm_project_id        || '');
        _setVal('cfg-storage-bucket',c.fcm_storage_bucket    || '');
        _setVal('cfg-sender-id',     c.fcm_messaging_sender_id|| '');
        _setVal('cfg-app-id',        c.fcm_app_id            || '');
        _setVal('cfg-vapid-key',     c.fcm_vapid_key         || '');

        // Flags
        _setCheck('flag-pwa-ativo',           c.pwa_ativo               === '1');
        _setCheck('flag-push-visitante',      c.push_visitante_ativo    === '1');
        _setCheck('flag-push-inadimplencia',  c.push_inadimplencia_ativo=== '1');
        _setCheck('flag-push-comunicado',     c.push_comunicado_ativo   === '1');
        _setCheck('flag-push-os',             c.push_os_ativo           === '1');
        _setCheck('flag-push-urgente',        c.push_urgente_ativo      === '1');

        // Service Account status
        _atualizarStatusSA(c.service_account_existe, c.service_account_email, c.service_account_project);
    } catch (e) {
        console.error('[PwaCentral] Erro ao carregar config:', e);
    }
}

function _atualizarStatusSA(existe, email, project) {
    const area    = document.getElementById('pwa-sa-area');
    const titulo  = document.getElementById('pwa-sa-titulo');
    const desc    = document.getElementById('pwa-sa-desc');
    if (!area) return;
    if (existe) {
        area.className = 'pwa-sa-area ok';
        if (titulo) titulo.textContent = '✅ Service Account configurado';
        if (desc) desc.innerHTML = `<strong>${email || '?'}</strong><br>${project || '?'}<br><span style="color:#16a34a;font-size:0.78rem;">Clique para substituir</span>`;
    } else {
        area.className = 'pwa-sa-area';
        if (titulo) titulo.textContent = 'Clique para enviar o Service Account JSON';
        if (desc) desc.textContent = 'Firebase Console → Configurações do projeto → Contas de serviço → Gerar nova chave privada';
    }
}

function lerServiceAccount(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            _saJson = JSON.parse(e.target.result);
            if (!_saJson.client_email || !_saJson.project_id || !_saJson.private_key) {
                _toast('JSON inválido — campos obrigatórios ausentes', 'erro');
                _saJson = null;
                return;
            }
            const prev = document.getElementById('pwa-sa-preview');
            if (prev) {
                prev.style.display = 'block';
                prev.innerHTML = `
                    <div class="pwa-alert sucesso">
                        <span class="pwa-alert-icon">✅</span>
                        <div>
                            <strong>Arquivo válido</strong><br>
                            Projeto: <code>${_esc(_saJson.project_id)}</code><br>
                            Email: <code>${_esc(_saJson.client_email)}</code>
                        </div>
                    </div>
                `;
            }
            const btnSalvar = document.getElementById('pwa-sa-btn-salvar');
            if (btnSalvar) btnSalvar.style.display = '';
        } catch {
            _toast('Arquivo não é um JSON válido', 'erro');
            _saJson = null;
        }
    };
    reader.readAsText(file);
}

async function salvarServiceAccount() {
    if (!_saJson) { _toast('Nenhum arquivo selecionado', 'erro'); return; }
    const btn = document.getElementById('pwa-sa-btn-salvar');
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
    try {
        const d = await _post('upload_service_account', { service_account_json: JSON.stringify(_saJson) });
        if (d.sucesso) {
            _toast('Service Account salvo com sucesso!', 'sucesso');
            _atualizarStatusSA(true, d.dados?.client_email, d.dados?.project_id);
            _saJson = null;
            const prev = document.getElementById('pwa-sa-preview');
            if (prev) prev.style.display = 'none';
            if (btn) btn.style.display = 'none';
        } else {
            _toast('Erro: ' + d.mensagem, 'erro');
        }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Service Account'; }
    }
}

async function salvarConfigFirebase() {
    const body = {
        fcm_api_key:            _getVal('cfg-api-key'),
        fcm_auth_domain:        _getVal('cfg-auth-domain'),
        fcm_project_id:         _getVal('cfg-project-id'),
        fcm_storage_bucket:     _getVal('cfg-storage-bucket'),
        fcm_messaging_sender_id:_getVal('cfg-sender-id'),
        fcm_app_id:             _getVal('cfg-app-id'),
        fcm_vapid_key:          _getVal('cfg-vapid-key'),
    };
    const d = await _post('salvar_config', body);
    _toast(d.sucesso ? 'Configuração Firebase salva!' : 'Erro: ' + d.mensagem, d.sucesso ? 'sucesso' : 'erro');
}

async function salvarFlags() {
    const body = {
        pwa_ativo:               _isChecked('flag-pwa-ativo')           ? '1' : '0',
        push_visitante_ativo:    _isChecked('flag-push-visitante')      ? '1' : '0',
        push_inadimplencia_ativo:_isChecked('flag-push-inadimplencia')  ? '1' : '0',
        push_comunicado_ativo:   _isChecked('flag-push-comunicado')     ? '1' : '0',
        push_os_ativo:           _isChecked('flag-push-os')             ? '1' : '0',
        push_urgente_ativo:      _isChecked('flag-push-urgente')        ? '1' : '0',
    };
    const d = await _post('salvar_config', body);
    _toast(d.sucesso ? 'Configurações salvas!' : 'Erro: ' + d.mensagem, d.sucesso ? 'sucesso' : 'erro');
}

// ── VERSIONAMENTO ─────────────────────────────────────────────

async function _carregarVersao() {
    const d = await _get('versao_atual');
    if (!d.sucesso || !d.dados) return;
    const { atual, historico } = d.dados;
    if (atual) {
        _setText('versao-num-display', atual.versao);
        _setText('versao-cache-display', atual.cache_version);
        _setText('versao-data-display', `Publicado em: ${atual.publicado_em || '—'}`);
    }
    const tbody = document.getElementById('versao-historico-tbody');
    if (tbody) {
        const tipoBadge = { major: '#fee2e2;color:#991b1b', minor: '#fef3c7;color:#92400e', patch: '#dbeafe;color:#1d4ed8', build: '#f3f4f6;color:#374151' };
        tbody.innerHTML = historico.map(h => `
            <tr>
                <td><strong>${_esc(h.versao)}</strong></td>
                <td><span style="background:${tipoBadge[h.tipo] || '#f3f4f6'};border-radius:999px;padding:2px 8px;font-size:0.72rem;font-weight:700;">${h.tipo}</span></td>
                <td style="font-family:monospace;font-size:0.78rem;color:var(--color-text-secondary);">${_esc(h.cache_version || '—')}</td>
                <td style="color:var(--color-text-secondary);font-size:0.82rem;">${_esc(h.changelog || '—')}</td>
                <td style="color:var(--color-text-secondary);">${h.publicado_em || '—'}</td>
            </tr>
        `).join('') || '<tr><td colspan="5">Nenhum histórico</td></tr>';
    }
}

async function publicarVersao(tipo) {
    const changelog = _getVal('versao-changelog');
    if (!confirm(`Confirma publicar nova versão (${tipo})?\n\nIsso invalidará o cache em todos os dispositivos.`)) return;
    const d = await _post('atualizar_versao', { tipo, changelog });
    if (d.sucesso) {
        _toast(`Versão ${d.dados.nova_versao} publicada! Cache invalidado.`, 'sucesso');
        document.getElementById('versao-changelog').value = '';
        await _carregarVersao();
        await carregarDashboard();
    } else {
        _toast('Erro: ' + d.mensagem, 'erro');
    }
}

// ── DISPOSITIVOS ──────────────────────────────────────────────

async function carregarDispositivos(offset = 0) {
    _dispOffset = offset;
    const tbody = document.getElementById('disp-tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="pwa-loading"><i class="fas fa-spinner"></i> Carregando...</td></tr>';

    const params = new URLSearchParams({
        acao: 'listar_dispositivos',
        limite: _dispLimite,
        offset: _dispOffset,
        status: _getVal('disp-filtro-status'),
        plataforma: _getVal('disp-filtro-plat'),
        busca: _getVal('disp-busca'),
    });
    const d = await _get('listar_dispositivos', params);
    if (!d.sucesso || !d.dados) return;
    const { items, total } = d.dados;
    _dispTotal = total;

    _setText('disp-total-info', `${total} dispositivo${total !== 1 ? 's' : ''} encontrado${total !== 1 ? 's' : ''}`);

    const platIcon = { android: '<i class="fab fa-android" style="color:#3ddc84"></i>', ios: '<i class="fab fa-apple"></i>', web: '<i class="fas fa-globe" style="color:#2563eb"></i>' };

    if (tbody) {
        tbody.innerHTML = items.length ? items.map(d => `
            <tr>
                <td><strong>${_esc(d.morador_nome || '—')}</strong><br><span style="font-size:0.75rem;color:var(--color-text-secondary);">Unidade ${_esc(d.unidade_numero || '—')}</span></td>
                <td><span class="pwa-plat-badge ${d.plataforma}">${platIcon[d.plataforma] || ''} ${d.plataforma}</span></td>
                <td>${_esc(d.device_os || '—')}<br><span style="font-size:0.75rem;color:var(--color-text-secondary);">${_esc(d.device_browser || '—')}</span></td>
                <td class="pwa-token-preview">${_esc(d.token_preview || '—')}...</td>
                <td style="font-size:0.78rem;color:var(--color-text-secondary);">${d.ultimo_uso || d.criado_em || '—'}</td>
                <td>${d.ativo ? '<span class="pwa-ativo-badge">Ativo</span>' : '<span class="pwa-inativo-badge">Inativo</span>'}</td>
                <td><div class="pwa-acoes-btn">
                    <button class="pwa-btn-sm success" title="Enviar push de teste" onclick="PwaCentral.enviarTeste(${d.id})"><i class="fas fa-paper-plane"></i></button>
                    ${d.ativo
                        ? `<button class="pwa-btn-sm" title="Desativar" onclick="PwaCentral.desativarDispositivo(${d.id})"><i class="fas fa-ban"></i></button>`
                        : `<button class="pwa-btn-sm success" title="Reativar" onclick="PwaCentral.ativarDispositivo(${d.id})"><i class="fas fa-check"></i></button>`
                    }
                    <button class="pwa-btn-sm danger" title="Excluir" onclick="PwaCentral.excluirDispositivo(${d.id})"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        `).join('') : '<tr><td colspan="7"><div class="pwa-empty"><i class="fas fa-mobile-alt"></i><p>Nenhum dispositivo encontrado</p></div></td></tr>';
    }

    _atualizarPaginacao('disp', _dispOffset, _dispLimite, _dispTotal);
}

function paginaDispositivos(dir) {
    const novoOffset = _dispOffset + dir * _dispLimite;
    if (novoOffset < 0 || novoOffset >= _dispTotal) return;
    carregarDispositivos(novoOffset);
}

function _debounceDispositivos() {
    clearTimeout(_debounceDispTimer);
    _debounceDispTimer = setTimeout(() => carregarDispositivos(0), 400);
}

async function desativarDispositivo(id) {
    const d = await _post('desativar_dispositivo', { id });
    if (d.sucesso) { _toast('Dispositivo desativado', 'aviso'); carregarDispositivos(_dispOffset); } else _toast(d.mensagem, 'erro');
}

async function ativarDispositivo(id) {
    const d = await _post('ativar_dispositivo', { id });
    if (d.sucesso) { _toast('Dispositivo reativado', 'sucesso'); carregarDispositivos(_dispOffset); } else _toast(d.mensagem, 'erro');
}

async function excluirDispositivo(id) {
    if (!confirm('Excluir este dispositivo permanentemente?')) return;
    const d = await _post('excluir_dispositivo', { id });
    if (d.sucesso) { _toast('Dispositivo excluído', 'aviso'); carregarDispositivos(_dispOffset); } else _toast(d.mensagem, 'erro');
}

async function enviarTeste(tokenId) {
    const btn = document.querySelector(`button[onclick="PwaCentral.enviarTeste(${tokenId})"]`);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    const d = await _post('enviar_teste', { token_id: tokenId });
    _toast(d.sucesso ? '✅ Push de teste enviado!' : '❌ Erro: ' + d.mensagem, d.sucesso ? 'sucesso' : 'erro');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i>'; }
}

// ── LOGS ──────────────────────────────────────────────────────

async function carregarLogs(offset = 0) {
    _logOffset = offset;
    const tbody = document.getElementById('log-tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="pwa-loading"><i class="fas fa-spinner"></i> Carregando...</td></tr>';

    const params = new URLSearchParams({
        acao: 'listar_logs',
        limite: _logLimite,
        offset: _logOffset,
        tipo: _getVal('log-filtro-tipo'),
        nivel: _getVal('log-filtro-nivel'),
        busca: _getVal('log-busca'),
        data_de: _getVal('log-filtro-de'),
        data_ate: _getVal('log-filtro-ate'),
    });
    const d = await _get('listar_logs', params);
    if (!d.sucesso || !d.dados) return;
    const { items, total } = d.dados;
    _logTotal = total;

    _setText('log-pag-info', `${total} registro${total !== 1 ? 's' : ''}`);

    const nivelBadge = {
        info:  'pwa-log-nivel-info',
        aviso: 'pwa-log-nivel-aviso',
        erro:  'pwa-log-nivel-erro',
    };

    if (tbody) {
        tbody.innerHTML = items.length ? items.map(l => `
            <tr>
                <td style="font-size:0.78rem;white-space:nowrap;">${l.criado_em}</td>
                <td><span class="${nivelBadge[l.nivel] || ''}">${l.nivel}</span></td>
                <td style="font-size:0.78rem;">${_esc(l.tipo)}</td>
                <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${_esc(l.descricao)}">${_esc(l.descricao)}</td>
                <td style="font-size:0.78rem;">${_esc(l.morador_nome || '—')}</td>
                <td style="font-size:0.75rem;font-family:monospace;">${_esc(l.ip || '—')}</td>
            </tr>
        `).join('') : '<tr><td colspan="6"><div class="pwa-empty"><i class="fas fa-list-alt"></i><p>Nenhum log encontrado</p></div></td></tr>';
    }

    _atualizarPaginacao('log', _logOffset, _logLimite, _logTotal);
}

function paginaLogs(dir) {
    const novoOffset = _logOffset + dir * _logLimite;
    if (novoOffset < 0 || novoOffset >= _logTotal) return;
    carregarLogs(novoOffset);
}

function _debounceLogs() {
    clearTimeout(_debounceLogTimer);
    _debounceLogTimer = setTimeout(() => carregarLogs(0), 400);
}

// ── ESTATÍSTICAS ──────────────────────────────────────────────

async function _carregarEstatisticas() {
    const d = await _get('estatisticas');
    if (!d.sucesso || !d.dados) return;
    const { por_plataforma, por_browser, por_os, novos_30d, ultimos, timeline } = d.dados;

    _setText('stats-novos-30d', `${novos_30d} novo${novos_30d !== 1 ? 's' : ''} dispositivo${novos_30d !== 1 ? 's' : ''} nos últimos 30 dias`);

    const _renderBarras = (containerId, dados, labelKey, valueKey, total) => {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = dados.length ? dados.map(r => {
            const pct = total > 0 ? Math.round((r[valueKey] / total) * 100) : 0;
            return `<div class="pwa-stat-bar-row">
                <span class="pwa-stat-bar-label" title="${_esc(r[labelKey])}">${_esc((r[labelKey] || '?').substring(0, 14))}</span>
                <div class="pwa-stat-bar-track"><div class="pwa-stat-bar-fill" style="width:${pct}%"></div></div>
                <span class="pwa-stat-bar-num">${r[valueKey]}</span>
            </div>`;
        }).join('') : '<div class="pwa-empty" style="padding:1rem"><p>Sem dados</p></div>';
    };

    const totalPlat  = Object.values(por_plataforma).reduce((a, b) => a + b, 0);
    const platDados  = Object.entries(por_plataforma).map(([plataforma, total]) => ({ plataforma, total }));
    _renderBarras('stats-plataforma', platDados, 'plataforma', 'total', totalPlat);

    const totalBrow = por_browser.reduce((a, r) => a + parseInt(r.total), 0);
    _renderBarras('stats-browsers', por_browser, 'browser', 'total', totalBrow);

    const totalOs = por_os.reduce((a, r) => a + parseInt(r.total), 0);
    _renderBarras('stats-os', por_os, 'os', 'total', totalOs);

    // Timeline
    const tlEl = document.getElementById('stats-timeline');
    if (tlEl && timeline.length) {
        const maxVal = Math.max(...timeline.map(t => parseInt(t.total)));
        tlEl.innerHTML = timeline.map(t => {
            const pct = maxVal > 0 ? Math.round((parseInt(t.total) / maxVal) * 100) : 4;
            return `<div class="pwa-timeline-bar" style="height:${Math.max(pct, 4)}%" data-tip="${t.dia}: ${t.total}"></div>`;
        }).join('');
    } else if (tlEl) {
        tlEl.innerHTML = '<p style="color:var(--color-text-tertiary);font-size:0.82rem;padding:0.5rem">Sem dados nos últimos 30 dias</p>';
    }

    // Últimos acessos
    const ulEl = document.getElementById('stats-ultimos');
    if (ulEl) {
        ulEl.innerHTML = ultimos.length ? ultimos.map(u => `
            <div class="pwa-stat-bar-row" style="gap:0.5rem;align-items:flex-start;margin-bottom:0.6rem">
                <span style="font-size:0.8rem;font-weight:600;min-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${_esc(u.nome || '—')}</span>
                <span style="font-size:0.75rem;color:var(--color-text-secondary);flex:1">${_esc(u.device_browser || u.plataforma || '—')}</span>
                <span style="font-size:0.72rem;color:var(--color-text-tertiary);white-space:nowrap">${(u.ultimo_uso || '').substring(0, 16)}</span>
            </div>
        `).join('') : '<p style="color:var(--color-text-tertiary);font-size:0.82rem;">Sem acessos recentes</p>';
    }
}

// ── QR CODE ───────────────────────────────────────────────────

async function _renderQrCode(url) {
    const canvas = document.getElementById('pwa-qr-canvas');
    if (!canvas) return;
    await _carregarQrLib();
    if (!_qrLib) { canvas.innerHTML = `<a href="${url}" style="font-size:0.8rem;word-break:break-all;">${url}</a>`; return; }
    canvas.innerHTML = '';
    _qrLib.toCanvas ? _qrLib.toCanvas(document.createElement('canvas'), url, { width: 200 }, (err, cvs) => { if (!err && cvs) canvas.appendChild(cvs); })
                    : (canvas.innerHTML = `<img src="${_qrLib.createDataURL ? _qrLib.createDataURL(url) : ''}" style="width:200px">`);
    // Usar QRCode object se disponível (qrcodejs)
    try {
        canvas.innerHTML = '';
        new window.QRCode(canvas, { text: url, width: 200, height: 200, correctLevel: window.QRCode.CorrectLevel.M });
    } catch {}
}

async function _carregarQrLib() {
    if (window.QRCode) return;
    await new Promise((res, rej) => {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
        s.onload = res; s.onerror = rej;
        document.head.appendChild(s);
    }).catch(() => {});
}

function baixarQrCode() {
    const canvas = document.querySelector('#pwa-qr-canvas canvas');
    if (!canvas) { _toast('QR Code ainda não gerado', 'aviso'); return; }
    const a = document.createElement('a');
    a.download = 'portal-morador-qrcode.png';
    a.href = canvas.toDataURL('image/png');
    a.click();
}

function copiarUrlPortal() {
    const url = _qrUrl || window.location.origin + '/frontend/portal_morador.html';
    navigator.clipboard.writeText(url).then(() => _toast('URL copiada!', 'sucesso')).catch(() => _toast('Não foi possível copiar', 'erro'));
}

// ── HELPERS ───────────────────────────────────────────────────

async function _get(acao, extraParams = null) {
    const params = extraParams || new URLSearchParams({ acao });
    if (!extraParams) params.set('acao', acao);
    const r = await fetch(`${_API}?${params.toString()}`);
    return r.json();
}

async function _post(acao, body) {
    const r = await fetch(`${_API}?acao=${acao}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    return r.json();
}

function _atualizarPaginacao(prefix, offset, limite, total) {
    const info = document.getElementById(`${prefix}-pag-info`);
    const btnAnt  = document.getElementById(`${prefix}-btn-ant`);
    const btnProx = document.getElementById(`${prefix}-btn-prox`);
    const inicio = offset + 1;
    const fim    = Math.min(offset + limite, total);
    if (info) info.textContent = total > 0 ? `${inicio}–${fim} de ${total}` : '0 registros';
    if (btnAnt)  btnAnt.disabled  = offset <= 0;
    if (btnProx) btnProx.disabled = offset + limite >= total;
}

function _toast(msg, tipo = 'info') {
    const cores = { sucesso: '#22c55e', erro: '#ef4444', aviso: '#f59e0b', info: '#2563eb' };
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:99999;background:#1e293b;color:#fff;padding:12px 18px;border-radius:10px;font-size:0.875rem;border-left:4px solid ${cores[tipo]||'#2563eb'};box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:340px;animation:pwa-spin 0s;`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function _mostrarErro(msg) {
    const res = document.getElementById('pwa-health-resultado');
    if (res) res.innerHTML = `<div class="pwa-alert erro"><span class="pwa-alert-icon">❌</span>${_esc(msg)}</div>`;
}

function _setText(id, val) { const e = document.getElementById(id); if (e) e.textContent = val ?? '—'; }
function _getVal(id)       { const e = document.getElementById(id); return e ? e.value.trim() : ''; }
function _setVal(id, val)  { const e = document.getElementById(id); if (e) e.value = val; }
function _setCheck(id, v)  { const e = document.getElementById(id); if (e) e.checked = !!v; }
function _isChecked(id)    { const e = document.getElementById(id); return e ? e.checked : false; }
function _esc(s)           { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
