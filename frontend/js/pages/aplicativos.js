/* Aplicativos — Portal Super-Admin */
'use strict';

const API = '/api/api_aplicativos_superadmin.php';
let _aplicativos = [];
let _toastTimer = null;

function el(id) { return document.getElementById(id); }
function escapeHtml(valor) {
    return String(valor ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[char]));
}
function statusBadge(valor) {
    const texto = { rascunho:'Rascunho', publicado:'Publicado', arquivado:'Arquivado', ativo:'Ativo', inativo:'Inativo', interno:'Interno', teste:'Teste', producao:'Produção' }[valor] || valor || '-';
    return `<span class="apps-badge ${escapeHtml(valor || '')}">${escapeHtml(texto)}</span>`;
}
function toast(mensagem, tipo = 'success') {
    const box = el('apps-toast');
    if (!box) return;
    box.textContent = mensagem;
    box.className = `apps-toast ${tipo}`;
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => { box.className = 'apps-toast'; }, 4500);
}
async function req(action, method = 'GET', body = null) {
    const opts = { method, credentials: 'include', headers: { Accept: 'application/json' } };
    if (body !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    try {
        const response = await fetch(`${API}?action=${encodeURIComponent(action)}`, opts);
        const text = await response.text();
        let data = null;
        try { data = text ? JSON.parse(text) : null; } catch (_) {}
        if (!response.ok || !data) return { sucesso:false, mensagem:data?.mensagem || `Erro HTTP ${response.status} ao comunicar com Aplicativos.` };
        return data;
    } catch (erro) {
        console.error('[Aplicativos]', erro);
        return { sucesso:false, mensagem:'Não foi possível comunicar com o serviço de Aplicativos.' };
    }
}
function abrirPainel(tipo, aberto) {
    const painel = el(tipo === 'aplicativo' ? 'apps-form-aplicativo-panel' : 'apps-form-versao-panel');
    if (painel) painel.hidden = !aberto;
    if (aberto) painel.scrollIntoView({ behavior:'smooth', block:'start' });
}
function atualizarSelectAplicativos(selecionado = '') {
    const select = el('release-aplicativo');
    if (!select) return;
    select.innerHTML = '<option value="">Selecione...</option>' + _aplicativos
        .filter(app => app.status === 'ativo')
        .map(app => `<option value="${app.id}">${escapeHtml(app.nome)} (${escapeHtml(app.plataforma)})</option>`).join('');
    select.value = String(selecionado || '');
}
function preencherKpis() {
    const releases = _aplicativos.flatMap(app => app.versoes || []);
    const publicadas = releases.filter(v => v.status === 'publicado');
    el('apps-total').textContent = _aplicativos.length;
    el('apps-publicadas').textContent = publicadas.length;
    el('apps-play').textContent = releases.filter(v => v.distribuicao === 'google_play' || v.distribuicao === 'ambos').length;
}
function renderLista() {
    const container = el('apps-lista');
    if (!container) return;
    if (!_aplicativos.length) {
        container.innerHTML = '<div class="apps-empty"><i class="fas fa-mobile-screen-button"></i><strong>Nenhum aplicativo cadastrado.</strong><br><span>Use “Novo aplicativo” para iniciar o catálogo institucional.</span></div>';
        return;
    }
    container.innerHTML = _aplicativos.map(app => {
        const releases = app.versoes || [];
        const play = app.google_play_url ? `<a class="apps-link" href="${escapeHtml(app.google_play_url)}" target="_blank" rel="noopener noreferrer"><i class="fab fa-google-play"></i> Google Play</a>` : '<span style="color:#94a3b8">Google Play não configurada</span>';
        const linhas = releases.length ? releases.map(v => `<tr>
            <td><strong>${escapeHtml(v.versao_nome)}</strong><br><small>code ${escapeHtml(v.version_code)}</small></td>
            <td>${statusBadge(v.canal)}</td><td>${statusBadge(v.status)}</td>
            <td>${escapeHtml(v.distribuicao || '-')}</td>
            <td>${v.obrigatoria == 1 ? '<strong style="color:#dc2626">Obrigatória</strong>' : 'Opcional'}</td>
            <td><div style="display:flex;gap:.35rem;flex-wrap:wrap">
                <button class="apps-mini-btn" data-apps-editar-versao="${v.id}"><i class="fas fa-pen"></i> Editar</button>
                ${v.status !== 'publicado' ? `<button class="apps-mini-btn primary" data-apps-publicar="${v.id}"><i class="fas fa-rocket"></i> Publicar</button>` : ''}
                ${v.status !== 'arquivado' ? `<button class="apps-mini-btn warning" data-apps-arquivar="${v.id}"><i class="fas fa-box-archive"></i></button>` : ''}
            </div></td>
        </tr>`).join('') : '<tr><td colspan="6" style="color:#64748b;text-align:center">Nenhuma versão registrada.</td></tr>';
        return `<article class="apps-card">
            <div class="apps-card-head"><div class="apps-card-title"><div class="apps-card-logo"><i class="${app.plataforma === 'android' ? 'fab fa-android' : 'fas fa-window-maximize'}"></i></div><div><h3>${escapeHtml(app.nome)}</h3><p>${escapeHtml(app.chave)} · ${escapeHtml(app.package_name || 'package não configurado')} · ${statusBadge(app.status)}</p></div></div>
            <div class="apps-card-actions"><button class="apps-mini-btn" data-apps-editar-app="${app.id}"><i class="fas fa-pen"></i> Editar</button><button class="apps-mini-btn primary" data-apps-nova-versao="${app.id}"><i class="fas fa-plus"></i> Nova versão</button></div></div>
            <div style="padding:.75rem 1rem;font-size:.82rem;color:#475569">${escapeHtml(app.descricao || 'Sem descrição.')}<br><span style="display:inline-block;margin-top:.35rem">${play}</span></div>
            <div class="apps-table-wrap"><table class="apps-table"><thead><tr><th>Versão</th><th>Canal</th><th>Status</th><th>Distribuição</th><th>Atualização</th><th>Ações</th></tr></thead><tbody>${linhas}</tbody></table></div>
        </article>`;
    }).join('');
}
async function carregar() {
    const container = el('apps-lista');
    if (container) container.innerHTML = '<div class="apps-loading"><i class="fas fa-spinner fa-spin"></i> Carregando catálogo...</div>';
    const res = await req('listar');
    if (!res.sucesso) {
        if (container) container.innerHTML = `<div class="apps-empty"><i class="fas fa-triangle-exclamation"></i><strong>Não foi possível carregar Aplicativos.</strong><br><span>${escapeHtml(res.mensagem)}</span><br><small>Confirme a execução da migration de Aplicativos no banco.</small></div>`;
        return;
    }
    _aplicativos = res.dados?.aplicativos || [];
    preencherKpis();
    atualizarSelectAplicativos();
    renderLista();
}
function limparFormApp() {
    el('apps-form-aplicativo').reset();
    el('app-id').value = '';
    el('app-status').value = 'ativo';
    el('apps-form-aplicativo-titulo').textContent = 'Novo aplicativo';
}
function editarApp(id) {
    const app = _aplicativos.find(item => Number(item.id) === Number(id));
    if (!app) return;
    el('app-id').value = app.id; el('app-nome').value = app.nome || ''; el('app-chave').value = app.chave || '';
    el('app-plataforma').value = app.plataforma || 'android'; el('app-status').value = app.status || 'ativo';
    el('app-package').value = app.package_name || ''; el('app-play-package').value = app.google_play_package || '';
    el('app-play-url').value = app.google_play_url || ''; el('app-descricao').value = app.descricao || '';
    el('apps-form-aplicativo-titulo').textContent = 'Editar aplicativo'; abrirPainel('aplicativo', true);
}
function limparFormVersao(appId = '') {
    el('apps-form-versao').reset(); el('release-id').value = ''; atualizarSelectAplicativos(appId);
    el('release-canal').value = 'interno'; el('release-distribuicao').value = 'apk_direto'; el('release-status').value = 'rascunho';
    el('apps-form-versao-titulo').textContent = 'Nova versão';
}
function editarVersao(id) {
    let versao = null;
    _aplicativos.some(app => { versao = (app.versoes || []).find(item => Number(item.id) === Number(id)); return !!versao; });
    if (!versao) return;
    el('release-id').value = versao.id; atualizarSelectAplicativos(versao.aplicativo_id);
    el('release-nome').value = versao.versao_nome || ''; el('release-code').value = versao.version_code || '';
    el('release-canal').value = versao.canal || 'interno'; el('release-distribuicao').value = versao.distribuicao || 'apk_direto'; el('release-status').value = versao.status || 'rascunho';
    el('release-url-apk').value = versao.url_download_apk || ''; el('release-sha256').value = versao.sha256 || ''; el('release-tamanho').value = versao.tamanho_bytes || '';
    el('release-min-sdk').value = versao.min_sdk || ''; el('release-target-sdk').value = versao.target_sdk || ''; el('release-play-track').value = versao.google_play_track || '';
    el('release-play-id').value = versao.google_play_release_id || ''; el('release-obrigatoria').checked = Number(versao.obrigatoria) === 1; el('release-notas').value = versao.notas_liberacao || '';
    el('apps-form-versao-titulo').textContent = 'Editar versão'; abrirPainel('versao', true);
}
async function salvarApp(event) {
    event.preventDefault();
    const dados = { id:el('app-id').value, nome:el('app-nome').value.trim(), chave:el('app-chave').value.trim(), plataforma:el('app-plataforma').value, status:el('app-status').value, package_name:el('app-package').value.trim(), google_play_package:el('app-play-package').value.trim(), google_play_url:el('app-play-url').value.trim(), descricao:el('app-descricao').value.trim() };
    const res = await req('salvar_aplicativo', 'POST', dados);
    if (!res.sucesso) return toast(res.mensagem, 'error');
    toast(res.mensagem); abrirPainel('aplicativo', false); await carregar();
}
async function salvarVersao(event) {
    event.preventDefault();
    const dados = { id:el('release-id').value, aplicativo_id:el('release-aplicativo').value, versao_nome:el('release-nome').value.trim(), version_code:el('release-code').value, canal:el('release-canal').value, distribuicao:el('release-distribuicao').value, status:el('release-status').value, url_download_apk:el('release-url-apk').value.trim(), sha256:el('release-sha256').value.trim(), tamanho_bytes:el('release-tamanho').value, min_sdk:el('release-min-sdk').value.trim(), target_sdk:el('release-target-sdk').value.trim(), google_play_track:el('release-play-track').value, google_play_release_id:el('release-play-id').value.trim(), obrigatoria:el('release-obrigatoria').checked, notas_liberacao:el('release-notas').value.trim() };
    const res = await req('salvar_versao', 'POST', dados);
    if (!res.sucesso) return toast(res.mensagem, 'error');
    toast(res.mensagem); abrirPainel('versao', false); await carregar();
}
async function publicarVersao(id) {
    if (!confirm('Publicar esta versão? Uma release de produção publicada anteriormente será arquivada.')) return;
    const res = await req('publicar_versao', 'POST', { id });
    if (!res.sucesso) return toast(res.mensagem, 'error');
    toast(res.mensagem); await carregar();
}
async function arquivarVersao(id) {
    if (!confirm('Arquivar esta versão? Ela permanecerá no histórico de auditoria.')) return;
    const res = await req('arquivar_versao', 'POST', { id });
    if (!res.sucesso) return toast(res.mensagem, 'error');
    toast(res.mensagem); await carregar();
}
function ouvirAcoes() {
    el('apps-novo-aplicativo')?.addEventListener('click', () => { limparFormApp(); abrirPainel('aplicativo', true); });
    el('apps-atualizar')?.addEventListener('click', carregar);
    el('apps-form-aplicativo')?.addEventListener('submit', salvarApp);
    el('apps-form-versao')?.addEventListener('submit', salvarVersao);
    document.querySelectorAll('[data-apps-fechar]').forEach(btn => btn.addEventListener('click', () => abrirPainel(btn.dataset.appsFechar, false)));
    el('apps-lista')?.addEventListener('click', event => {
        const target = event.target.closest('button'); if (!target) return;
        if (target.dataset.appsEditarApp) editarApp(target.dataset.appsEditarApp);
        if (target.dataset.appsNovaVersao) { limparFormVersao(target.dataset.appsNovaVersao); abrirPainel('versao', true); }
        if (target.dataset.appsEditarVersao) editarVersao(target.dataset.appsEditarVersao);
        if (target.dataset.appsPublicar) publicarVersao(target.dataset.appsPublicar);
        if (target.dataset.appsArquivar) arquivarVersao(target.dataset.appsArquivar);
    });
}
export function init() {
    const permissao = String(localStorage.getItem('usuario_permissao') || '').toLowerCase();
    if (permissao && permissao !== 'super_admin') { toast('Acesso exclusivo para Super-Admin.', 'error'); return; }
    console.log('[Aplicativos] Inicializado — catálogo institucional de APK/Google Play');
    ouvirAcoes(); carregar();
}
export function destroy() { clearTimeout(_toastTimer); }
