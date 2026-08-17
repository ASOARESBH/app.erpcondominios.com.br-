/**
 * superadmin.js — Módulo ES do Painel Super-Admin
 * =================================================
 * Exporta init() e destroy() conforme padrão do app-router.js
 * Toda a lógica do painel SA está aqui.
 *
 * @version 3.0.0 (Multi-Tenant — com navegação entre empresas)
 */

'use strict';

const API = '/api/api_superadmin.php';
const log = (...a) => console.log('[SuperAdmin v3]', ...a);

// ── Utilitários ──────────────────────────────────────────────────────────
async function req(params, method = 'GET', body = null) {
    const url = API + '?' + new URLSearchParams(params).toString();
    const opts = { method, credentials: 'include', headers: { 'Content-Type': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);

    try {
        const response = await fetch(url, opts);
        const texto = await response.text();
        let dados;
        try {
            dados = texto ? JSON.parse(texto) : null;
        } catch (_) {
            return {
                sucesso: false,
                mensagem: `Erro HTTP ${response.status}: a API não retornou uma resposta válida.`
            };
        }
        if (!response.ok) {
            return {
                sucesso: false,
                mensagem: dados?.mensagem || `Erro HTTP ${response.status} ao processar a solicitação.`
            };
        }
        return dados || { sucesso: false, mensagem: 'A API retornou uma resposta vazia.' };
    } catch (erro) {
        console.error('[SuperAdmin] Falha de comunicação:', erro);
        return { sucesso: false, mensagem: 'Não foi possível comunicar com o painel administrativo.' };
    }
}

async function monitoringReq(action, method = 'GET', body = null) {
    const query = new URLSearchParams({ action });
    const opts = { method, credentials: 'include', headers: { 'Content-Type': 'application/json' } };
    if (method === 'GET' && body) Object.entries(body).forEach(([key, value]) => query.set(key, String(value)));
    if (body && method !== 'GET') opts.body = JSON.stringify(body);
    try {
        const response = await fetch('/api/api_monitoramento.php?' + query.toString(), opts);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { sucesso: false, mensagem: 'Resposta vazia.' };
        return response.ok ? data : { sucesso: false, mensagem: data.mensagem || `Erro HTTP ${response.status}`, codigo: data.codigo };
    } catch (error) {
        console.error('[SuperAdmin][Monitoramento] Falha de comunicação:', error);
        return { sucesso: false, mensagem: 'Não foi possível comunicar com o Monitoramento.', codigo: 'NETWORK_ERROR' };
    }
}

function monitoringEsc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
}

function statusBadge(s) {
    const m = { ativo: 'Ativo', inativo: 'Inativo', suspenso: 'Suspenso' };
    const cls = { ativo: 'badge-ativo', inativo: 'badge-inativo', suspenso: 'badge-suspenso' };
    return `<span class="badge ${cls[s]||'badge-inativo'}">${m[s]||s}</span>`;
}

function planoBadge(p) {
    const m = { basico: 'Básico', profissional: 'Profissional', enterprise: 'Enterprise',
                admin: 'Admin', gerente: 'Gerente', operador: 'Operador',
                visualizador: 'Visualizador', super_admin: 'Super Admin' };
    const cls = { basico: 'badge-basico', profissional: 'badge-profissional',
                  enterprise: 'badge-enterprise', admin: 'badge-admin',
                  gerente: 'badge-gerente', operador: 'badge-operador',
                  visualizador: 'badge-visualizador', super_admin: 'badge-super-admin' };
    return `<span class="badge ${cls[p]||'badge-inativo'}">${m[p]||p}</span>`;
}

function fmt(n) { return parseInt(n || 0).toLocaleString('pt-BR'); }
function fmtDate(d) { return d ? new Date(d).toLocaleString('pt-BR') : '-'; }
function avatar(n) { return (n || '?').charAt(0).toUpperCase(); }

function toast(msg, tipo = 'success') {
    const el = document.getElementById('sa-toast');
    if (!el) { alert(msg); return; }
    el.textContent = msg;
    el.className = 'sa-toast sa-toast-' + tipo;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

// ── Estado global ────────────────────────────────────────────────────────
let _condominios  = [];
let _modalTenantId = null;
let _modalTenantData = null;
let _resetUserId  = null;
let _todosMods    = [];
let _wizardData   = {};
let _wizardStep   = 1;
let _slugTimer    = null;
let _planoSelecionado = null;
let _buscarTimer  = null;
let _timers       = [];

// ── INIT / DESTROY ───────────────────────────────────────────────────────
export function init() {
    log('init() — Painel Super-Admin v3.0');

    // Verificar se o usuário é super_admin
    const permissao = localStorage.getItem('usuario_permissao');
    if (permissao && permissao !== 'super_admin') {
        const container = document.getElementById('superadmin-page') || document.querySelector('.page-wrapper');
        if (container) container.innerHTML = '<div style="padding:2rem;text-align:center;color:#dc2626;"><i class="fas fa-lock fa-3x"></i><h2>Acesso Negado</h2><p>Esta área é exclusiva para Super Administradores.</p></div>';
        return;
    }

    // ── CRÍTICO: Mover modais para o <body> ──────────────────────────────
    // O #appContent tem overflow-y:auto, o que quebra position:fixed dos overlays.
    // A solução é mover os modais para o <body> onde position:fixed funciona corretamente.
    ['sa-modal-cond', 'sa-modal-senha'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
            log('Modal movido para body:', id);
        }
    });

    // Verificar se está navegando em outro tenant
    _verificarContextoTenant();

    // Expor API global para o HTML inline (onclick)
    window.SA = _api;
    window.SuperAdmin = _api;

    // Carregar dashboard inicial
    _carregarDashboard();
}

export function destroy() {
    log('destroy()');
    // Limpar timers
    _timers.forEach(t => clearTimeout(t));
    _timers = [];
    // Remover modais que foram movidos para o body
    ['sa-modal-cond', 'sa-modal-senha'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentElement === document.body) {
            el.remove();
            log('Modal removido do body:', id);
        }
    });
    // Remover referências globais
    delete window.SA;
    delete window.SuperAdmin;
    delete window._salvarModal;
}

// ── VERIFICAR CONTEXTO DE TENANT ─────────────────────────────────────────
function _verificarContextoTenant() {
    // Verificar se o super_admin está navegando em outro tenant
    fetch('/api/verificar_sessao.php', { credentials: 'include' })
        .then(r => r.json())
        .then(res => {
            if (!res.sucesso) return;
            const tenantAtual = res.dados.tenant;
            const tenantOriginal = localStorage.getItem('superadmin_tenant_original');

            // Se há um tenant original salvo, significa que está navegando em outro tenant
            if (tenantOriginal && tenantOriginal !== String(tenantAtual?.id)) {
                const banner = document.getElementById('sa-banner-contexto');
                const nome   = document.getElementById('sa-banner-nome');
                if (banner) banner.style.display = 'flex';
                if (nome)   nome.textContent = tenantAtual?.nome || 'Condomínio';
            }

            // Salvar tenant atual no localStorage
            if (tenantAtual) {
                localStorage.setItem('tenant_id',   String(tenantAtual.id || ''));
                localStorage.setItem('tenant_slug', tenantAtual.slug || '');
                localStorage.setItem('tenant_nome', tenantAtual.nome || '');
            }
        })
        .catch(() => {});
}

// ── TABS ─────────────────────────────────────────────────────────────────
function _showTab(tab) {
    // Padrão do design system: .tab-content / .tab-button
    document.querySelectorAll('.page-superadmin .tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.page-superadmin .tab-button').forEach(el => el.classList.remove('active'));

    const content = document.getElementById('tab-' + tab);
    if (content) content.style.display = 'block';

    const btn = document.querySelector(`.page-superadmin .tab-button[data-tab="${tab}"]`);
    if (btn) btn.classList.add('active');

    if (tab === 'dashboard')   _carregarDashboard();
    if (tab === 'condominios') _carregarCondominios();
    if (tab === 'usuarios')    _carregarUsuariosGlobais();
    if (tab === 'onboarding')  _iniciarWizard();
    if (tab === 'auditoria')   _carregarAuditoria();
}

// ── DASHBOARD ────────────────────────────────────────────────────────────
function _carregarDashboard() {
    req({ action: 'dashboard' }).then(res => {
        if (!res.sucesso) return;
        const d = res.dados;

        // KPIs
        _set('sa-total-ativos',    fmt(d.tenants?.ativos));
        _set('sa-total-usuarios',  fmt(d.usuarios?.ativos));
        _set('sa-total-moradores', fmt(d.moradores?.total));
        _set('sa-total-tenants',   fmt(d.tenants?.total));

        // Alertas
        const alertasBox = document.getElementById('sa-alertas-box');
        if (d.alertas && d.alertas.length && alertasBox) {
            alertasBox.style.display = 'block';
            _set('sa-alertas-lista', d.alertas.map(t =>
                `<div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem 0;border-bottom:1px solid var(--border-color);">
                    <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
                    <span><strong>${t.nome_fantasia || t.razao_social}</strong> — ${statusBadge(t.status)}</span>
                    <button class="btn btn-secondary btn-sm" onclick="SA.alterarStatus(${t.id},'${t.status}')">
                        <i class="fas fa-check"></i> Ativar
                    </button>
                </div>`
            ).join(''));
        }

        // Planos
        _set('sa-planos-grid', (d.planos || []).map(p =>
            `<div class="sa-plano-item">
                <div class="num">${fmt(p.total)}</div>
                <div class="lbl">${planoBadge(p.plano)}</div>
            </div>`
        ).join('') || '<p style="color:var(--color-text-tertiary)">Nenhum dado</p>');

        // Recentes
        _set('sa-recentes-lista', (d.recentes || []).map(t =>
            `<div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0;border-bottom:1px solid var(--border-color);">
                <div>
                    <div style="font-weight:600;font-size:0.9rem;">${t.nome_fantasia || t.razao_social}</div>
                    <div style="font-size:0.75rem;color:var(--color-text-tertiary);"><code>${t.slug}</code> · ${fmtDate(t.data_criacao).split(',')[0]}</div>
                </div>
                <div style="display:flex;gap:0.4rem;align-items:center;">
                    ${planoBadge(t.plano)} ${statusBadge(t.status)}
                    <button class="btn btn-secondary btn-sm" onclick="SA.entrarTenant(${t.id},'${(t.nome_fantasia||t.razao_social).replace(/'/g,'')}')" title="Entrar como este condomínio">
                        <i class="fas fa-sign-in-alt"></i>
                    </button>
                </div>
            </div>`
        ).join('') || '<p style="color:var(--color-text-tertiary)">Nenhum cadastrado</p>');

    }).catch(e => log('Erro dashboard:', e));
}

// ── CONDOMÍNIOS ───────────────────────────────────────────────────────────
function _carregarCondominios() {
    const grid = document.getElementById('sa-condominios-grid');
    if (!grid) return;
    grid.innerHTML = '<div class="loading-inline"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    req({ action: 'tenants' }).then(res => {
        if (!res.sucesso) {
            grid.innerHTML = `<div class="loading-inline" style="color:#dc2626;">${res.mensagem}</div>`;
            return;
        }
        _condominios = res.dados.tenants || [];
        _renderCondominios(_condominios);
    }).catch(() => {
        grid.innerHTML = '<div class="loading-inline" style="color:#dc2626;">Erro ao carregar condomínios</div>';
    });
}

function _renderCondominios(lista) {
    const grid = document.getElementById('sa-condominios-grid');
    if (!grid) return;

    if (!lista.length) {
        grid.innerHTML = '<div class="loading-inline"><i class="fas fa-building"></i> Nenhum condomínio encontrado</div>';
        return;
    }

    grid.innerHTML = lista.map(t => `
        <div class="sa-cond-card">
            <div class="sa-cond-header">
                <div class="sa-cond-avatar">${avatar(t.nome_fantasia || t.razao_social)}</div>
                <div class="sa-cond-info">
                    <h4>${t.nome_fantasia || t.razao_social || '-'}</h4>
                    <small><code>${t.slug}</code> · ${t.cidade || ''} ${t.estado || ''}</small>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;margin-bottom:0.75rem;">
                ${statusBadge(t.status)} ${planoBadge(t.plano)}
            </div>
            <div class="sa-cond-stats">
                <div class="sa-cond-stat"><div class="num">${fmt(t.total_usuarios)}</div><div class="lbl">Usuários</div></div>
                <div class="sa-cond-stat"><div class="num">${fmt(t.total_moradores)}</div><div class="lbl">Moradores</div></div>
                <div class="sa-cond-stat"><div class="num">${t.id}</div><div class="lbl">ID</div></div>
            </div>
            <div class="sa-cond-footer">
                <small style="color:var(--color-text-tertiary);font-size:0.75rem;">${t.email_principal || ''}</small>
                <div class="sa-cond-actions">
                    <button onclick="SA.abrirModal(${t.id})" title="Gerenciar">
                        <i class="fas fa-cog"></i> Gerenciar
                    </button>
                    <button onclick="SA.entrarTenant(${t.id},'${(t.nome_fantasia||t.razao_social||'').replace(/'/g,'')}')"
                            title="Entrar como este condomínio" style="color:#2563eb;">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                    <button onclick="SA.alterarStatus(${t.id},'${t.status}')"
                            class="${t.status === 'ativo' ? 'danger' : ''}"
                            title="${t.status === 'ativo' ? 'Suspender acesso' : 'Ativar acesso'}">
                        <i class="fas fa-${t.status === 'ativo' ? 'ban' : 'check'}"></i>
                        ${t.status === 'ativo' ? 'Suspender' : 'Ativar'}
                    </button>
                </div>
            </div>
        </div>`
    ).join('');
}

function _filtrar() {
    const busca  = (document.getElementById('sa-busca')?.value || '').toLowerCase();
    const status = document.getElementById('sa-filtro-status')?.value || '';
    const plano  = document.getElementById('sa-filtro-plano')?.value || '';

    _renderCondominios(_condominios.filter(t => {
        const mb = !busca ||
            (t.nome_fantasia || '').toLowerCase().includes(busca) ||
            (t.razao_social  || '').toLowerCase().includes(busca) ||
            (t.slug          || '').toLowerCase().includes(busca) ||
            (t.cnpj          || '').includes(busca);
        return mb && (!status || t.status === status) && (!plano || t.plano === plano);
    }));
}

// ── ENTRAR / SAIR DE TENANT ───────────────────────────────────────────────
function _entrarTenant(tenantId, tenantNome) {
    if (!confirm(`Deseja entrar como "${tenantNome}"?\n\nVocê verá o sistema como se fosse um usuário deste condomínio.\nPara voltar ao painel, clique em "Voltar ao Painel".`)) return;

    // Marcar o contexto global antes de entrar. A API mantém o tenant
    // original na sessão; a flag local governa exclusivamente a navegação.
    const contextoAnterior = localStorage.getItem('superadmin_tenant_original');
    const tenantAtualId = localStorage.getItem('tenant_id') || '__global__';
    if (!contextoAnterior) localStorage.setItem('superadmin_tenant_original', tenantAtualId);

    req({ action: 'entrar_tenant' }, 'POST', { tenant_id: tenantId })
        .then(res => {
            if (res.sucesso) {
                // Atualizar localStorage com o novo tenant
                if (res.dados?.tenant) {
                    localStorage.setItem('tenant_id',    String(res.dados.tenant.id || ''));
                    localStorage.setItem('tenant_slug',  res.dados.tenant.slug || '');
                    localStorage.setItem('tenant_nome',  res.dados.tenant.nome || '');
                    localStorage.setItem('tenant_plano', res.dados.tenant.plano || '');
                }
                toast(`Navegando como: ${tenantNome}`, 'success');
                // Cada unidade começa no módulo operacional definido pela API.
                const destino = typeof res.dados?.redirect === 'string'
                    ? res.dados.redirect
                    : '/frontend/layout-base.html?page=dashboard';
                setTimeout(() => {
                    window.location.href = destino.indexOf('/frontend/layout-base.html') === 0
                        ? destino
                        : '/frontend/layout-base.html?page=dashboard';
                }, 800);
            } else {
                if (!contextoAnterior) localStorage.removeItem('superadmin_tenant_original');
                toast(res.mensagem || 'Erro ao entrar no condomínio', 'error');
            }
        })
        .catch(() => {
            if (!contextoAnterior) localStorage.removeItem('superadmin_tenant_original');
            toast('Erro de comunicação com o servidor', 'error');
        });
}

function _sairTenant() {
    req({ action: 'sair_tenant' }, 'POST', {})
        .then(res => {
            if (res.sucesso) {
                // Restaurar localStorage
                localStorage.removeItem('superadmin_tenant_original');
                toast('Retornado ao painel principal', 'success');
                setTimeout(() => {
                    window.location.href = '/frontend/layout-base.html?page=superadmin';
                }, 800);
            } else {
                toast(res.mensagem || 'Erro ao sair do condomínio', 'error');
            }
        })
        .catch(() => toast('Erro de comunicação com o servidor', 'error'));
}

// ── ALTERAR STATUS DO TENANT ──────────────────────────────────────────────
function _alterarStatus(id, statusAtual) {
    const novo = statusAtual === 'ativo' ? 'suspenso' : 'ativo';
    const acao = novo === 'ativo' ? 'ativar' : 'suspender';

    let msg = `Deseja ${acao} este condomínio?`;
    if (novo === 'suspenso') {
        msg += '\n\n⚠️ ATENÇÃO: Todos os usuários deste condomínio perderão acesso imediatamente.\nApenas o Super Admin continuará com acesso.';
    }

    if (!confirm(msg)) return;

    req({ action: 'status_tenant', id }, 'POST', { status: novo }).then(res => {
        if (res.sucesso) {
            toast(res.mensagem, 'success');
            _carregarCondominios();
            _carregarDashboard();
        } else {
            toast('Erro: ' + res.mensagem, 'error');
        }
    });
}

// ── MODAL GERENCIAR ───────────────────────────────────────────────────────
function _abrirModal(id) {
    _modalTenantId   = id;
    _modalTenantData = null;

    const modal = document.getElementById('sa-modal-cond');
    if (!modal) return;
    modal.style.display = 'flex';

    _set('sa-modal-info-body', '<div class="loading-inline"><i class="fas fa-spinner fa-spin"></i></div>');
    _showModalTab('info');

    req({ action: 'tenant', id }).then(res => {
        if (!res.sucesso) {
            _set('sa-modal-info-body', `<p style="color:#dc2626">${res.mensagem}</p>`);
            return;
        }
        _modalTenantData = res.dados;
        const t = res.dados.tenant;
        _set('sa-modal-titulo', `<i class="fas fa-building"></i> ${t.nome_fantasia || t.razao_social}`);
        _renderModalInfo(t, res.dados.usuarios);
    });
}

function _showModalTab(tab) {
    document.querySelectorAll('.modal-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.sa-modal-tab').forEach(el => el.classList.remove('active'));

    const c = document.getElementById('mtab-' + tab);
    if (c) c.style.display = 'block';

    const b = document.querySelector(`.sa-modal-tab[data-mtab="${tab}"]`);
    if (b) b.classList.add('active');

    if (tab === 'modulos' && _modalTenantId) _carregarModulosModal();
    if (tab === 'plano' && _modalTenantData) _renderPlanoModal(_modalTenantData.tenant.plano);
    if (tab === 'monitoramento' && _modalTenantId) _carregarMonitoramento();
}

function _renderModalInfo(t, usuarios) {
    _set('sa-modal-info-body', `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1.25rem;">
            <div><strong>Slug:</strong> <code>${t.slug}</code></div>
            <div><strong>CNPJ:</strong> ${t.cnpj || '-'}</div>
            <div><strong>Plano:</strong> ${planoBadge(t.plano)}</div>
            <div><strong>Status:</strong> ${statusBadge(t.status)}</div>
            <div><strong>E-mail:</strong> ${t.email_principal || '-'}</div>
            <div><strong>Telefone:</strong> ${t.telefone || '-'}</div>
            <div><strong>Cidade/UF:</strong> ${t.cidade || '-'} / ${t.estado || '-'}</div>
            <div><strong>Cadastrado:</strong> ${fmtDate(t.data_criacao)}</div>
        </div>
        <div style="display:flex;gap:1rem;margin-bottom:1.25rem;">
            <div class="sa-cond-stat" style="flex:1;"><div class="num">${fmt(t.total_usuarios)}</div><div class="lbl">Usuários</div></div>
            <div class="sa-cond-stat" style="flex:1;"><div class="num">${fmt(t.total_moradores)}</div><div class="lbl">Moradores</div></div>
            <div class="sa-cond-stat" style="flex:1;"><div class="num">${fmt(t.total_unidades)}</div><div class="lbl">Unidades</div></div>
        </div>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" onclick="SA.showModalTab('modulos')"><i class="fas fa-puzzle-piece"></i> Módulos</button>
            <button class="btn btn-secondary btn-sm" onclick="SA.showModalTab('plano')"><i class="fas fa-tags"></i> Plano</button>
            <button class="btn btn-secondary btn-sm" onclick="SA.entrarTenant(${t.id},'${(t.nome_fantasia||t.razao_social||'').replace(/'/g,'')}');SA.fecharModal();" style="color:#2563eb;">
                <i class="fas fa-sign-in-alt"></i> Entrar como este Condomínio
            </button>
            <button class="btn-sm ${t.status === 'ativo' ? 'danger' : ''}" onclick="SA.alterarStatus(${t.id},'${t.status}');SA.fecharModal()">
                <i class="fas fa-${t.status === 'ativo' ? 'ban' : 'check'}"></i>
                ${t.status === 'ativo' ? 'Suspender Acesso' : 'Ativar Acesso'}
            </button>
        </div>`);

    // Usuários no modal
    _set('sa-modal-usuarios-body', usuarios && usuarios.length ? `
        <div class="sa-table-wrapper"><table class="sa-table">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Permissão</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>${usuarios.map(u => `
                <tr>
                    <td>${u.nome}</td>
                    <td style="font-size:0.8rem;">${u.email}</td>
                    <td>${planoBadge(u.permissao_tenant || u.permissao)}</td>
                    <td>${u.vinculo_ativo ? statusBadge('ativo') : statusBadge('inativo')}</td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="SA.abrirResetSenha(${u.id},'${u.nome.replace(/'/g, '')}')">
                            <i class="fas fa-key"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="SA.desvincularUsuario(${u.id},${_modalTenantId})">
                            <i class="fas fa-unlink"></i>
                        </button>
                    </td>
                </tr>`).join('')}
            </tbody>
        </table></div>` : '<p style="color:var(--color-text-tertiary)">Nenhum usuário vinculado</p>');
}

function _fecharModal() {
    const modal = document.getElementById('sa-modal-cond');
    if (modal) modal.style.display = 'none';
}

// ── MÓDULOS ───────────────────────────────────────────────────────────────
function _carregarModulosModal() {
    const grid = document.getElementById('sa-modal-modulos-grid');
    if (!grid) return;
    grid.innerHTML = '<div class="loading-inline"><i class="fas fa-spinner fa-spin"></i></div>';

    Promise.all([
        req({ action: 'modulos_sistema' }),
        req({ action: 'modulos_tenant', id: _modalTenantId })
    ]).then(([resMods, resTenant]) => {
        if (!resMods.sucesso) { grid.innerHTML = '<p style="color:#dc2626">Erro ao carregar módulos</p>'; return; }
        _todosMods = resMods.dados.modulos;
        const habilitados = new Set(resTenant.dados?.modulos || []);
        _renderModulosGrid(grid, _todosMods, habilitados, 'modal-mod-');
    });
}

function _renderModulosGrid(container, modulos, habilitados, prefix) {
    const grupos = {};
    modulos.forEach(m => { if (!grupos[m.grupo]) grupos[m.grupo] = []; grupos[m.grupo].push(m); });
    let html = '';
    for (const [grupo, mods] of Object.entries(grupos)) {
        html += `<div class="sa-modulo-grupo">${grupo}</div>`;
        mods.forEach(m => {
            const checked = habilitados.has(m.chave) ? 'checked' : '';
            html += `<div class="sa-modulo-item ${checked ? 'selected' : ''}" onclick="SA.toggleMod(this,'${prefix}${m.chave}')">
                <input type="checkbox" id="${prefix}${m.chave}" ${checked} onclick="event.stopPropagation()">
                <i class="${m.icone}"></i>
                <label for="${prefix}${m.chave}">${m.nome}</label>
            </div>`;
        });
    }
    container.innerHTML = html;
}

function _toggleMod(el, id) {
    const cb = document.getElementById(id);
    if (!cb) return;
    cb.checked = !cb.checked;
    el.classList.toggle('selected', cb.checked);
}

function _salvarModulos() {
    const checkboxes = document.querySelectorAll('#sa-modal-modulos-grid input[type=checkbox]');
    const modulos = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.id.replace('modal-mod-', ''));
    req({ action: 'salvar_modulos' }, 'POST', { id: _modalTenantId, modulos }).then(res => {
        toast(res.sucesso ? `${res.mensagem} (${modulos.length} módulos)` : res.mensagem, res.sucesso ? 'success' : 'error');
    });
}

// ── PLANO ─────────────────────────────────────────────────────────────────
function _renderPlanoModal(planoAtual) {
    _planoSelecionado = planoAtual;
    const planos = [
        { key: 'basico',       nome: 'Básico',       desc: 'Controle de acesso + moradores + veículos' },
        { key: 'profissional', nome: 'Profissional',  desc: 'Todos os módulos principais do sistema' },
        { key: 'enterprise',   nome: 'Enterprise',    desc: 'Acesso completo + módulos avançados' }
    ];
    _set('sa-planos-selecao', planos.map(p => `
        <div class="sa-plano-card ${p.key === planoAtual ? 'selected' : ''}" onclick="SA.selecionarPlano('${p.key}',this)">
            ${planoBadge(p.key)}
            <h4 style="margin:0.75rem 0 0.25rem;">${p.nome}</h4>
            <p>${p.desc}</p>
        </div>`
    ).join(''));
}

function _selecionarPlano(plano, el) {
    _planoSelecionado = plano;
    document.querySelectorAll('.sa-plano-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
}

function _salvarPlano() {
    if (!_planoSelecionado) { toast('Selecione um plano', 'error'); return; }
    req({ action: 'salvar_plano' }, 'POST', { id: _modalTenantId, plano: _planoSelecionado }).then(res => {
        toast(res.sucesso ? res.mensagem : res.mensagem, res.sucesso ? 'success' : 'error');
        if (res.sucesso) _carregarCondominios();
    });
}

// ── MONITORAMENTO / AGENTES ────────────────────────────────────────────────
function _carregarMonitoramento() {
    const container = document.getElementById('sa-monitoramento-agentes');
    if (!container || !_modalTenantId) return;
    container.innerHTML = '<div class="loading-inline"><i class="fas fa-spinner fa-spin"></i> Carregando agentes...</div>';
    monitoringReq('listar_agentes', 'GET', { tenant_id: _modalTenantId }).then(res => {
        if (!res.sucesso) {
            container.innerHTML = `<p style="color:#dc2626">${monitoringEsc(res.mensagem)}</p>`;
            return;
        }
        _renderMonitoramentoConfig(res.dados?.configuracao || {});
        _renderAgentesMonitoramento(res.dados?.agentes || []);
    });
}

function _renderMonitoramentoConfig(config) {
    _set('sa-monitoramento-config', `
        <div><span>Retenção</span><strong><input id="sa-monitoramento-retencao" type="number" min="1" max="3650" value="${Number(config.retencao_dias || 30)}"> dias</strong></div>
        <div><span>Módulo</span><strong>${config.modulo_ativo ? 'Ativo' : 'Inativo'}</strong></div>
        <div><span>Versão mínima</span><strong>${monitoringEsc(config.versao_minima_agente || '0.1.0')}</strong></div>
        <button class="btn btn-primary btn-sm" onclick="SA.salvarConfiguracaoMonitoramento()"><i class="fas fa-save"></i> Salvar política</button>`);
}

function _renderAgentesMonitoramento(agentes) {
    const container = document.getElementById('sa-monitoramento-agentes');
    if (!container) return;
    const header = `
        <div class="monitoring-pairing-form">
            <div><strong>Adicionar máquina Windows</strong><small>Gere o código no painel local e informe-o aqui.</small></div>
            <input id="sa-monitoring-pairing-code" maxlength="9" placeholder="ABCD-EFGH" autocomplete="off">
            <input id="sa-monitoring-agent-name" maxlength="120" placeholder="Nome da máquina / portaria">
            <input id="sa-monitoring-agent-local" maxlength="160" placeholder="Local">
            <button class="btn btn-primary btn-sm" onclick="SA.habilitarAgente()"><i class="fas fa-link"></i> Habilitar</button>
        </div>`;
    const table = agentes.length ? `
        <table class="sa-table monitoring-agents-table">
            <thead><tr><th>Máquina</th><th>Status</th><th>Motor</th><th>Heartbeat</th><th>Versão</th><th>Ações</th></tr></thead>
            <tbody>${agentes.map(agent => `
                <tr>
                    <td><strong>${monitoringEsc(agent.nome || 'Sem nome')}</strong><br><small>${monitoringEsc(agent.local || '-')} · ID ${Number(agent.id)}</small></td>
                    <td>${statusBadge(agent.status)}<br><small>${monitoringEsc(agent.agent_secret_last4 ? '••••' + agent.agent_secret_last4 : '')}</small></td>
                    <td>${monitoringEsc(agent.lpr_engine || '-')}<br><small>${monitoringEsc(agent.onnx_backend || '-')}</small></td>
                    <td>${monitoringEsc(agent.last_heartbeat_at || 'Nunca')}<br><small>${monitoringEsc(agent.last_error_code || '')}</small></td>
                    <td>${monitoringEsc(agent.agent_version || '-')}<br><small>Contrato ${monitoringEsc(agent.api_contract_version || '-')}</small></td>
                    <td>${agent.status === 'ativo' ? `<button class="btn btn-danger btn-sm" onclick="SA.revogarAgente(${Number(agent.id)})"><i class="fas fa-ban"></i> Revogar</button>` : '-'}</td>
                </tr>`).join('')}</tbody>
        </table>` : '<p style="color:var(--color-text-tertiary)">Nenhuma máquina habilitada neste tenant.</p>';
    container.innerHTML = header + table;
}

function _showMonitoringAlert(message, type = 'success') {
    const el = document.getElementById('sa-monitoramento-alert');
    if (!el) return;
    el.className = 'alert alert-' + type;
    el.textContent = message;
    el.style.display = 'block';
}

function _habilitarAgente() {
    const pairingCode = (document.getElementById('sa-monitoring-pairing-code')?.value || '').trim().toUpperCase();
    const nome = document.getElementById('sa-monitoring-agent-name')?.value.trim() || 'Agente Monitoring';
    const local = document.getElementById('sa-monitoring-agent-local')?.value.trim() || '';
    if (!/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/.test(pairingCode)) {
        _showMonitoringAlert('Informe o código no formato ABCD-EFGH.', 'error');
        return;
    }
    monitoringReq('habilitar_agente', 'POST', { tenant_id: _modalTenantId, pairing_code: pairingCode, nome, local }).then(res => {
        if (!res.sucesso) { _showMonitoringAlert(res.mensagem || 'Não foi possível habilitar.', 'error'); return; }
        const secret = res.dados?.activation_secret || '';
        _showMonitoringAlert('Máquina habilitada. Guarde esta credencial agora: ' + secret, 'success');
        _carregarMonitoramento();
    });
}

function _revogarAgente(agentId) {
    if (!confirm('Revogar esta máquina? Ela perderá a comunicação com o ERP imediatamente.')) return;
    monitoringReq('revogar_agente', 'POST', { tenant_id: _modalTenantId, agent_id: agentId }).then(res => {
        _showMonitoringAlert(res.mensagem || 'Operação concluída.', res.sucesso ? 'success' : 'error');
        if (res.sucesso) _carregarMonitoramento();
    });
}

function _salvarConfiguracaoMonitoramento() {
    const retention = Number(document.getElementById('sa-monitoramento-retencao')?.value || 30);
    monitoringReq('salvar_configuracao', 'POST', { tenant_id: _modalTenantId, retencao_dias: retention, modulo_ativo: 1, versao_minima_agente: '0.1.0' }).then(res => {
        _showMonitoringAlert(res.mensagem || 'Configuração concluída.', res.sucesso ? 'success' : 'error');
        if (res.sucesso) _renderMonitoramentoConfig(res.dados || {});
    });
}

// ── DESVINCULAR USUÁRIO ───────────────────────────────────────────────────
function _desvincularUsuario(uid, tid) {
    if (!confirm('Remover vínculo deste usuário com o condomínio?')) return;
    req({ action: 'desvincular_usuario' }, 'POST', { usuario_id: uid, tenant_id: tid }).then(res => {
        toast(res.sucesso ? res.mensagem : res.mensagem, res.sucesso ? 'success' : 'error');
        if (res.sucesso && _modalTenantId) _abrirModal(_modalTenantId);
    });
}

// ── RESET SENHA ───────────────────────────────────────────────────────────
function _abrirResetSenha(uid, nome) {
    _resetUserId = uid;
    const nomeEl = document.getElementById('sa-senha-usuario-nome');
    if (nomeEl) nomeEl.textContent = 'Usuário: ' + nome;
    const s1 = document.getElementById('sa-nova-senha');
    const s2 = document.getElementById('sa-nova-senha2');
    const al = document.getElementById('sa-senha-alert');
    if (s1) s1.value = '';
    if (s2) s2.value = '';
    if (al) al.style.display = 'none';
    const modal = document.getElementById('sa-modal-senha');
    if (modal) modal.style.display = 'flex';
}

function _fecharModalSenha() {
    const modal = document.getElementById('sa-modal-senha');
    if (modal) modal.style.display = 'none';
}

function _confirmarResetSenha() {
    const s1 = document.getElementById('sa-nova-senha')?.value || '';
    const s2 = document.getElementById('sa-nova-senha2')?.value || '';
    const al = document.getElementById('sa-senha-alert');

    if (s1.length < 6) { if (al) { al.className = 'alert alert-error'; al.innerHTML = 'Senha deve ter pelo menos 6 caracteres'; al.style.display = 'block'; } return; }
    if (s1 !== s2)     { if (al) { al.className = 'alert alert-error'; al.innerHTML = 'As senhas não coincidem'; al.style.display = 'block'; } return; }

    req({ action: 'resetar_senha' }, 'POST', { usuario_id: _resetUserId, nova_senha: s1 }).then(res => {
        if (res.sucesso) { _fecharModalSenha(); toast(res.mensagem, 'success'); }
        else if (al) { al.className = 'alert alert-error'; al.innerHTML = res.mensagem; al.style.display = 'block'; }
    });
}

// ── USUÁRIOS GLOBAIS ──────────────────────────────────────────────────────
function _carregarUsuariosGlobais(busca = '') {
    const tbody = document.getElementById('sa-tbody-usuarios');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    req({ action: 'usuarios_globais', busca }).then(res => {
        if (!res.sucesso) { tbody.innerHTML = `<tr><td colspan="6" style="color:#dc2626;">${res.mensagem}</td></tr>`; return; }
        const lista = res.dados.usuarios || [];
        if (!lista.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center">Nenhum usuário encontrado</td></tr>'; return; }
        tbody.innerHTML = lista.map(u => `
            <tr>
                <td><strong>${u.nome}</strong></td>
                <td style="font-size:0.8rem;">${u.email}</td>
                <td>${planoBadge(u.permissao)}</td>
                <td style="font-size:0.8rem;">${u.condominios || '—'}</td>
                <td>${u.ativo ? statusBadge('ativo') : statusBadge('inativo')}</td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick="SA.abrirResetSenha(${u.id},'${u.nome.replace(/'/g, '')}')">
                        <i class="fas fa-key"></i> Senha
                    </button>
                    <button class="btn-sm ${u.ativo ? 'danger' : ''}" onclick="SA.toggleUsuario(${u.id},${u.ativo ? 0 : 1},this)">
                        <i class="fas fa-${u.ativo ? 'ban' : 'check'}"></i> ${u.ativo ? 'Inativar' : 'Ativar'}
                    </button>
                </td>
            </tr>`
        ).join('');
    });
}

function _buscarUsuarios() {
    clearTimeout(_buscarTimer);
    _buscarTimer = setTimeout(() => {
        const busca = document.getElementById('sa-busca-user')?.value || '';
        _carregarUsuariosGlobais(busca);
    }, 400);
}

function _toggleUsuario(uid, novoAtivo) {
    const label = novoAtivo ? 'ativar' : 'inativar';
    if (!confirm(`Deseja ${label} este usuário?`)) return;
    req({ action: 'toggle_usuario' }, 'POST', { usuario_id: uid, ativo: novoAtivo }).then(res => {
        if (res.sucesso) _carregarUsuariosGlobais(document.getElementById('sa-busca-user')?.value || '');
        else toast('Erro: ' + res.mensagem, 'error');
    });
}

// ── AUDITORIA ─────────────────────────────────────────────────────────────
function _carregarAuditoria() {
    const tbody = document.getElementById('sa-tbody-logs');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';

    req({ action: 'logs_auditoria', limite: 100 }).then(res => {
        if (!res.sucesso) { tbody.innerHTML = `<tr><td colspan="5" style="color:#dc2626;">${res.mensagem}</td></tr>`; return; }
        const logs = res.dados.logs || [];
        if (!logs.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-center">Nenhum log registrado</td></tr>'; return; }
        tbody.innerHTML = logs.map(l => `
            <tr>
                <td style="font-size:0.8rem;white-space:nowrap;">${fmtDate(l.created_at)}</td>
                <td>${l.usuario_nome || '-'}</td>
                <td><code style="font-size:0.75rem;background:var(--color-background-tertiary);padding:2px 6px;border-radius:4px;">${l.acao}</code></td>
                <td style="font-size:0.85rem;">${l.descricao || '-'}</td>
                <td style="font-size:0.75rem;color:var(--color-text-tertiary);">${l.ip || '-'}</td>
            </tr>`
        ).join('');
    });
}

// ── WIZARD ONBOARDING ─────────────────────────────────────────────────────
function _iniciarWizard() {
    _wizardStep = 1;
    _wizardData = {};
    for (let i = 1; i <= 4; i++) {
        const el = document.getElementById('wizard-step-' + i);
        if (el) el.style.display = i === 1 ? 'block' : 'none';
        const ind = document.getElementById('step-ind-' + i);
        if (ind) { ind.classList.remove('active', 'done'); if (i === 1) ind.classList.add('active'); }
    }
    const al = document.getElementById('sa-onboarding-alert');
    if (al) al.style.display = 'none';
    if (_todosMods.length === 0) {
        req({ action: 'modulos_sistema' }).then(res => { if (res.sucesso) _todosMods = res.dados.modulos; });
    }
}

function _wizardNext(step) {
    const al = document.getElementById('sa-onboarding-alert');
    const showErr = (msg) => { if (al) { al.className = 'alert alert-error'; al.innerHTML = msg; al.style.display = 'block'; } };

    if (step === 1) {
        const slug  = document.getElementById('ob-slug')?.value.trim() || '';
        const razao = document.getElementById('ob-razao')?.value.trim() || '';
        const cnpj  = (document.getElementById('ob-cnpj')?.value || '').replace(/\D/g, '');
        const email = document.getElementById('ob-email-cond')?.value.trim() || '';
        if (!slug || !razao || cnpj.length < 14 || !email) { showErr('Preencha todos os campos obrigatórios.'); return; }
        _wizardData.slug           = slug;
        _wizardData.razao_social   = razao;
        _wizardData.nome_fantasia  = document.getElementById('ob-fantasia')?.value.trim() || razao;
        _wizardData.cnpj           = cnpj;
        _wizardData.email_condominio = email;
        _wizardData.telefone       = document.getElementById('ob-telefone')?.value.trim() || '';
        _wizardData.cidade         = document.getElementById('ob-cidade')?.value.trim() || '';
        _wizardData.estado         = document.getElementById('ob-estado')?.value || '';
        _wizardData.plano          = document.getElementById('ob-plano')?.value || 'profissional';
    }
    if (step === 2) {
        const nome  = document.getElementById('ob-admin-nome')?.value.trim() || '';
        const email = document.getElementById('ob-admin-email')?.value.trim() || '';
        const s1    = document.getElementById('ob-admin-senha')?.value || '';
        const s2    = document.getElementById('ob-admin-senha2')?.value || '';
        if (!nome || !email || !s1) { showErr('Preencha todos os campos do administrador.'); return; }
        if (s1 !== s2) { showErr('As senhas não coincidem.'); return; }
        _wizardData.admin_nome  = nome;
        _wizardData.admin_email = email;
        _wizardData.admin_senha = s1;
    }
    if (step === 3) {
        const cbs = document.querySelectorAll('#ob-modulos-grid input[type=checkbox]');
        _wizardData.modulos = Array.from(cbs).filter(cb => cb.checked).map(cb => cb.id.replace('ob-mod-', ''));
    }

    if (al) al.style.display = 'none';
    _goToWizardStep(step + 1);
}

function _wizardBack(step) { _goToWizardStep(step - 1); }

function _goToWizardStep(step) {
    for (let i = 1; i <= 4; i++) {
        const el = document.getElementById('wizard-step-' + i);
        if (el) el.style.display = i === step ? 'block' : 'none';
        const ind = document.getElementById('step-ind-' + i);
        if (ind) {
            ind.classList.remove('active', 'done');
            if (i < step) ind.classList.add('done');
            if (i === step) ind.classList.add('active');
        }
    }
    _wizardStep = step;

    if (step === 3) {
        const grid = document.getElementById('ob-modulos-grid');
        if (grid && _todosMods.length) {
            const padrao = {
                basico:       new Set(['dashboard', 'moradores', 'veiculos', 'visitantes', 'registro', 'acesso']),
                profissional: new Set(['dashboard', 'moradores', 'veiculos', 'visitantes', 'registro', 'acesso', 'relatorios', 'financeiro', 'contas_pagar', 'contas_receber', 'manutencao', 'hidrometro', 'leitura', 'estoque', 'contratos', 'protocolos', 'notificacoes', 'documentos', 'rh', 'configuracao']),
                enterprise:   new Set(_todosMods.map(m => m.chave))
            };
            const habilitados = _wizardData.modulos ? new Set(_wizardData.modulos) : (padrao[_wizardData.plano] || padrao.basico);
            _renderModulosGrid(grid, _todosMods, habilitados, 'ob-mod-');
        }
    }

    if (step === 4) {
        const cbs = document.querySelectorAll('#ob-modulos-grid input[type=checkbox]');
        _wizardData.modulos = Array.from(cbs).filter(cb => cb.checked).map(cb => cb.id.replace('ob-mod-', ''));
        const resumo = document.getElementById('ob-resumo');
        if (resumo) resumo.innerHTML = `
            <div class="sa-resumo-row"><strong>Condomínio</strong><span>${_wizardData.nome_fantasia}</span></div>
            <div class="sa-resumo-row"><strong>Slug / URL</strong><span><code>${_wizardData.slug}</code> → https://${_wizardData.slug}.erpcondominios.com.br</span></div>
            <div class="sa-resumo-row"><strong>CNPJ</strong><span>${_wizardData.cnpj}</span></div>
            <div class="sa-resumo-row"><strong>Plano</strong><span>${planoBadge(_wizardData.plano)}</span></div>
            <div class="sa-resumo-row"><strong>Administrador</strong><span>${_wizardData.admin_nome} (${_wizardData.admin_email})</span></div>
            <div class="sa-resumo-row"><strong>Módulos</strong><span>${_wizardData.modulos.length} módulos selecionados</span></div>`;
    }
}

function _onboarding() {
    const al  = document.getElementById('sa-onboarding-alert');
    const btn = document.getElementById('btn-onboarding-final');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Criando...'; }

    req({ action: 'onboarding' }, 'POST', _wizardData).then(res => {
        if (res.sucesso) {
            if (al) { al.className = 'alert alert-success'; al.innerHTML = `✅ Condomínio <strong>${res.dados.tenant_slug}</strong> criado! Admin: <strong>${res.dados.admin_email}</strong> | <a href="${res.dados.url_acesso}" target="_blank">${res.dados.url_acesso}</a>`; al.style.display = 'block'; }
            setTimeout(() => _iniciarWizard(), 1000);
            _carregarCondominios();
        } else {
            if (al) { al.className = 'alert alert-error'; al.innerHTML = res.mensagem; al.style.display = 'block'; }
            _goToWizardStep(1);
        }
    }).catch(() => {
        if (al) { al.className = 'alert alert-error'; al.innerHTML = 'Erro de comunicação com o servidor.'; al.style.display = 'block'; }
    }).finally(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-rocket"></i> Criar Condomínio'; }
    });
}

function _onSlugInput() {
    const input = document.getElementById('ob-slug');
    if (!input) return;
    const slug = input.value.toLowerCase().replace(/[^a-z0-9\-]/g, '');
    input.value = slug;
    const preview = document.getElementById('ob-slug-preview');
    const status  = document.getElementById('ob-slug-status');
    if (preview) preview.textContent = slug ? `URL: https://${slug}.erpcondominios.com.br` : '';
    if (!slug) { if (status) status.textContent = ''; return; }
    if (status) status.textContent = '⏳';
    clearTimeout(_slugTimer);
    _slugTimer = setTimeout(() => {
        req({ action: 'verificar_slug', slug }).then(res => {
            if (res.sucesso && status) {
                status.textContent = res.dados.disponivel ? '✅' : '❌ Já em uso';
                status.style.color = res.dados.disponivel ? '#10b981' : '#dc2626';
            }
        });
    }, 500);
}

function _mascaraCnpj(input) {
    let v = input.value.replace(/\D/g, '').substring(0, 14);
    v = v.replace(/^(\d{2})(\d)/, '$1.$2')
         .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
         .replace(/\.(\d{3})(\d)/, '.$1/$2')
         .replace(/(\d{4})(\d)/, '$1-$2');
    input.value = v;
}

// ── HELPER ────────────────────────────────────────────────────────────────
function _set(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

// ── API PÚBLICA (exposta como window.SA e window.SuperAdmin) ──────────────
const _api = {
    showTab:              _showTab,
    carregarCondominios:  _carregarCondominios,
    filtrar:              _filtrar,
    abrirModal:           _abrirModal,
    showModalTab:         _showModalTab,
    fecharModal:          _fecharModal,
    salvarModulos:        _salvarModulos,
    salvarPlano:          _salvarPlano,
    selecionarPlano:      _selecionarPlano,
    alterarStatus:        _alterarStatus,
    entrarTenant:         _entrarTenant,
    sairTenant:           _sairTenant,
    desvincularUsuario:   _desvincularUsuario,
    abrirResetSenha:      _abrirResetSenha,
    fecharModalSenha:     _fecharModalSenha,
    confirmarResetSenha:  _confirmarResetSenha,
    carregarUsuariosGlobais: _carregarUsuariosGlobais,
    buscarUsuarios:       _buscarUsuarios,
    toggleUsuario:        _toggleUsuario,
    wizardNext:           _wizardNext,
    wizardBack:           _wizardBack,
    onboarding:           _onboarding,
    onSlugInput:          _onSlugInput,
    mascaraCnpj:          _mascaraCnpj,
    toggleMod:            _toggleMod,
    carregarMonitoramento: _carregarMonitoramento,
    habilitarAgente:       _habilitarAgente,
    revogarAgente:         _revogarAgente,
    salvarConfiguracaoMonitoramento: _salvarConfiguracaoMonitoramento,
};
