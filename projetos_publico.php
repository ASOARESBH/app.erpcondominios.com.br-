<?php
// =====================================================
// PÁGINA PÚBLICA DE TRANSPARÊNCIA — PROJETOS E OBRAS
// =====================================================
// Sem login. Renderiza no servidor apenas as tags de SEO/OpenGraph (para
// que WhatsApp/Facebook/etc. gerem a pré-visualização correta ao
// compartilhar o link de um projeto específico) — o conteúdo interativo
// (dashboard, cards, timeline) é carregado no cliente via
// api/api_publico_projetos.php, que é somente leitura e não expõe nada
// além dos campos públicos.
//
// Consulta ao banco aqui é só leitura, com prepared statement, e aplica a
// MESMA regra de segurança da API: só projeto_publico=1 é considerado —
// um id de O.S comum ou de projeto não-público nunca aparece nem nas tags.

// Este arquivo vive na RAIZ do projeto (não em frontend/) porque
// frontend/.htaccess bloqueia a execução de qualquer .php naquele diretório
// (PHP é confinado a api/ por padrão nesta aplicação). Uma página pública
// que precisa rodar PHP no servidor (para renderizar as tags de Open Graph
// antes do JS carregar) não pode ficar lá.
require_once __DIR__ . '/api/config.php';

$idProjeto   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$origem      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
// URL amigável (/projetos ou /projetos/123), resolvida via .htaccess na raiz
// para este mesmo arquivo — usada nas tags de SEO e no botão de compartilhar.
$urlAtual    = $origem . '/projetos' . ($idProjeto ? '/' . $idProjeto : '');

$seoTitulo    = 'Projetos e Obras — Transparência';
$seoDescricao = 'Acompanhe em tempo real as obras, reformas e melhorias em andamento na associação.';
$seoImagem    = null; // sem asset de logo/favicon garantido no projeto — só define og:image se o projeto tiver capa

if ($idProjeto) {
    $conexaoSeo = @conectar_banco();
    if ($conexaoSeo) {
        $stmtSeo = $conexaoSeo->prepare(
            "SELECT projeto_nome, projeto_descricao, projeto_imagem_capa, numero
             FROM os_chamados WHERE id = ? AND projeto_publico = 1 LIMIT 1"
        );
        $stmtSeo->bind_param('i', $idProjeto);
        $stmtSeo->execute();
        $projetoSeo = $stmtSeo->get_result()->fetch_assoc();
        if ($projetoSeo) {
            $seoTitulo    = ($projetoSeo['projeto_nome'] ?: ('Projeto ' . $projetoSeo['numero'])) . ' — Transparência';
            $seoDescricao = $projetoSeo['projeto_descricao'] ? mb_substr($projetoSeo['projeto_descricao'], 0, 200) : $seoDescricao;
            // A imagem nunca é referenciada direto da pasta de uploads (bloqueada
            // por .htaccess) — sempre via api_imagem_projeto.php, que resolve a
            // mesma cascata capa → primeira foto → imagem institucional padrão,
            // então o og:image nunca fica ausente.
            $seoImagem = $origem . '/api/api_imagem_projeto.php?tipo=capa&os_id=' . $idProjeto;
        }
        $stmtSeo->close();
        fechar_conexao($conexaoSeo);
    }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($seoTitulo); ?></title>
<meta name="description" content="<?php echo h($seoDescricao); ?>">
<link rel="canonical" href="<?php echo h($urlAtual); ?>">
<meta name="robots" content="index, follow">

<!-- Open Graph / WhatsApp / Facebook -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($seoTitulo); ?>">
<meta property="og:description" content="<?php echo h($seoDescricao); ?>">
<?php if ($seoImagem): ?>
<meta property="og:image" content="<?php echo h($seoImagem); ?>">
<?php endif; ?>
<meta property="og:url" content="<?php echo h($urlAtual); ?>">
<meta property="og:locale" content="pt_BR">

<!-- Twitter Card -->
<meta name="twitter:card" content="<?php echo $seoImagem ? 'summary_large_image' : 'summary'; ?>">
<meta name="twitter:title" content="<?php echo h($seoTitulo); ?>">
<meta name="twitter:description" content="<?php echo h($seoDescricao); ?>">
<?php if ($seoImagem): ?>
<meta name="twitter:image" content="<?php echo h($seoImagem); ?>">
<?php endif; ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary: #2563eb; --primary-dark: #1e40af; --primary-light: #dbeafe;
    --bg-page: #f8fafc; --bg-card: #ffffff; --bg-subtle: #f1f5f9;
    --border: #e2e8f0; --text-primary: #1e293b; --text-secondary: #475569; --text-muted: #94a3b8;
    --radius-md: 10px; --radius-lg: 16px; --shadow-sm: 0 2px 8px rgba(0,0,0,.06);
}
* { box-sizing: border-box; }
body { margin:0; font-family: 'Segoe UI', Arial, sans-serif; background: var(--bg-page); color: var(--text-primary); }
.pp-header {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: #fff; padding: 2.5rem 1.5rem; text-align: center;
}
.pp-header h1 { margin: 0 0 .5rem; font-size: 1.7rem; }
.pp-header p { margin: 0; opacity: .9; font-size: .95rem; }
.pp-container { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
.pp-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: 1.5rem; margin-bottom: 1.25rem; }
.pp-dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px,1fr)); gap: .75rem; margin-bottom: 1.25rem; }
.pp-dash-card { background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; text-align: center; }
.pp-dash-num { font-size: 1.7rem; font-weight: 800; color: var(--primary); }
.pp-dash-label { font-size: .78rem; color: var(--text-muted); margin-top: .2rem; }
.pp-toolbar { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.pp-toolbar input, .pp-toolbar select {
    padding: .7rem 1rem; border: 2px solid var(--border); border-radius: var(--radius-md);
    font-size: .9rem; font-family: inherit; background: var(--bg-card); color: var(--text-primary);
}
.pp-toolbar input { flex: 1 1 240px; }

/* Grid responsivo: 3 no desktop, 2 no tablet, 1 no celular — igual ao Portal */
.pp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; }
@media (max-width: 992px) { .pp-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) { .pp-grid { grid-template-columns: 1fr; } }

.pp-card-proj { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; cursor: pointer; transition: transform .15s, box-shadow .15s; }
.pp-card-proj:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(15,23,42,.12); }

.pp-card-capa-wrap { position: relative; width: 100%; height: 220px; background: var(--bg-subtle); overflow: hidden; }
.pp-card-capa { width: 100%; height: 100%; object-fit: cover; display:block; }
.pp-card-selo {
    position: absolute; top: 12px; right: 12px;
    padding: .32rem .75rem; border-radius: 20px; font-size: .68rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .04em; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.3);
}

.pp-card-body { padding: 1.1rem; }
.pp-card-nome { font-weight: 700; font-size: 1rem; margin-bottom: .65rem; line-height: 1.3; }
.pp-progresso-mini { height: 10px; border-radius: 6px; background: var(--bg-subtle); overflow: hidden; margin-bottom: .35rem; }
.pp-progresso-mini > div { height: 100%; border-radius: 6px; transition: width .4s; }
.pp-card-pct { font-size: .82rem; font-weight: 800; }
.pp-card-info { margin-top: .75rem; display: flex; flex-direction: column; gap: .35rem; font-size: .78rem; color: var(--text-secondary); }
.pp-card-info i { width: 15px; text-align: center; margin-right: .4rem; color: var(--text-muted); }
.pp-card-btn {
    display: block; width: 100%; margin-top: 1rem; text-align: center;
    padding: .65rem; border-radius: var(--radius-md); border: none;
    background: var(--primary); color: #fff; font-weight: 700; font-size: .85rem; cursor: pointer;
}
.pp-card-btn:hover { background: var(--primary-dark); }

.pp-badge { display:inline-block; padding:.2rem .6rem; border-radius:20px; font-size:.75rem; font-weight:700; }

/* Tela de detalhe — imagem grande no topo, igual ao Portal */
.pp-det-hero-wrap { position: relative; width: 100%; height: 320px; overflow: hidden; border-radius: var(--radius-lg) var(--radius-lg) 0 0; background: var(--bg-subtle); }
.pp-det-hero { width: 100%; height: 100%; object-fit: cover; display: block; }
.pp-det-hero-selo {
    position: absolute; top: 16px; right: 16px;
    padding: .4rem 1rem; border-radius: 20px; font-size: .78rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .04em; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.pp-det-body { padding: 1.5rem; }

.pp-progresso-barra { height: 18px; border-radius: 9px; background: var(--bg-subtle); overflow: hidden; margin: .5rem 0 1rem; }
.pp-progresso-barra > div { height: 100%; border-radius: 9px; transition: width .4s; }
.pp-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap: 1rem; margin: 1rem 0; }
.pp-info-item { background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: .8rem; }
.pp-info-label { font-size: .72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: .25rem; }
.pp-timeline-item { display: flex; gap: .75rem; padding: .75rem 0; border-bottom: 1px solid var(--border); }
.pp-timeline-item:last-child { border-bottom: none; }
.pp-timeline-fotos { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .5rem; }
.pp-timeline-fotos img { width: 64px; height: 64px; object-fit: cover; border-radius: var(--radius-md); cursor: pointer; border: 1px solid var(--border); }

/* Galeria — grade de fotos com lightbox, igual ao Portal */
.pp-galeria { display: grid; grid-template-columns: repeat(4, 1fr); gap: .6rem; }
@media (max-width: 600px) { .pp-galeria { grid-template-columns: repeat(3, 1fr); } }
.pp-galeria img {
    width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: var(--radius-md);
    cursor: pointer; border: 1px solid var(--border); transition: transform .15s;
}
.pp-galeria img:hover { transform: scale(1.04); }

.pp-empty { text-align: center; padding: 2.5rem 1rem; color: var(--text-muted); }
.pp-empty i { font-size: 2rem; opacity: .4; display:block; margin-bottom:.5rem; }
.pp-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.6rem 1.1rem; border:none; border-radius:var(--radius-md); background:var(--primary); color:#fff; font-weight:600; cursor:pointer; font-size:.85rem; }
.pp-btn-voltar { background: var(--bg-subtle); color: var(--text-secondary); margin-bottom: 1rem; }
.pp-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.85); display:none; align-items:center; justify-content:center; z-index:9999; padding:1rem; }
.pp-lightbox.show { display:flex; }
.pp-lightbox img { max-width:100%; max-height:90vh; border-radius:var(--radius-md); }
.pp-footer { text-align:center; padding: 2rem 1rem; color: var(--text-muted); font-size: .8rem; }
@media (max-width:600px){ .pp-header h1{font-size:1.3rem;} }
</style>
</head>
<body>

<div class="pp-header">
    <h1><i class="fas fa-hard-hat"></i> Projetos e Obras</h1>
    <p>Portal de Transparência — acompanhe a evolução das obras e melhorias da associação</p>
</div>

<div class="pp-container">

    <div id="ppVistaLista">
        <div class="pp-card">
            <div class="pp-dashboard" id="ppDashboard">
                <div class="pp-dash-card"><div class="pp-dash-num" id="ppDashExecucao">—</div><div class="pp-dash-label">Em Execução</div></div>
                <div class="pp-dash-card"><div class="pp-dash-num" id="ppDashPlanejamento">—</div><div class="pp-dash-label">Planejamento</div></div>
                <div class="pp-dash-card"><div class="pp-dash-num" id="ppDashCancelado">—</div><div class="pp-dash-label">Cancelados</div></div>
                <div class="pp-dash-card"><div class="pp-dash-num" id="ppDashFinalizado">—</div><div class="pp-dash-label">Finalizados</div></div>
                <div class="pp-dash-card"><div class="pp-dash-num" id="ppDashMedia">—</div><div class="pp-dash-label">% Médio (ativos)</div></div>
            </div>
            <div class="pp-toolbar">
                <input type="text" id="ppBusca" placeholder="Pesquisar por nome, categoria, departamento..." oninput="ppDebounceBusca()">
                <select id="ppFiltroStatus" onchange="ppCarregarLista()">
                    <option value="">Todos</option>
                    <option value="planejamento">Planejamento</option>
                    <option value="execucao">Em Execução</option>
                    <option value="paralisado">Paralisado</option>
                    <option value="finalizado">Finalizado</option>
                </select>
            </div>
            <div id="ppGrid" class="pp-grid">
                <div class="pp-empty"><i class="fas fa-spinner fa-spin"></i><p>Carregando projetos...</p></div>
            </div>
        </div>
    </div>

    <div id="ppVistaDetalhe" style="display:none;">
        <button class="pp-btn pp-btn-voltar" onclick="ppVoltarLista()"><i class="fas fa-arrow-left"></i> Voltar aos Projetos</button>

        <div class="pp-card" style="padding:0;overflow:hidden;">
            <div class="pp-det-hero-wrap">
                <img id="ppDetCapa" class="pp-det-hero" src="" alt="" loading="lazy">
                <span id="ppDetHeroSelo" class="pp-det-hero-selo"></span>
            </div>
            <div class="pp-det-body">
                <h2 id="ppDetNome" style="margin:0 0 .75rem;">—</h2>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem;">
                    <span>Progresso</span><strong id="ppDetPct" style="font-size:1.2rem;">0%</strong>
                </div>
                <div class="pp-progresso-barra"><div id="ppDetBarra" style="width:0%"></div></div>
                <p id="ppDetDescricao" style="color:var(--text-secondary);"></p>
                <!-- Departamento, Responsável e datas vêm sempre da própria O.S —
                     nunca de um cadastro duplicado do Projeto. -->
                <div class="pp-info-grid">
                    <div class="pp-info-item"><div class="pp-info-label">Departamento</div><div id="ppDetDepartamento">—</div></div>
                    <div class="pp-info-item"><div class="pp-info-label">Responsável</div><div id="ppDetResponsavel">—</div></div>
                    <div class="pp-info-item"><div class="pp-info-label">Início Previsto</div><div id="ppDetInicio">—</div></div>
                    <div class="pp-info-item"><div class="pp-info-label">Conclusão Prevista</div><div id="ppDetFim">—</div></div>
                    <div class="pp-info-item"><div class="pp-info-label">Última Atualização</div><div id="ppDetUltimaAtualizacao">—</div></div>
                </div>
                <div style="margin-top:1rem;">
                    <button class="pp-btn" onclick="ppCompartilhar()"><i class="fab fa-whatsapp"></i> Compartilhar</button>
                </div>
            </div>
        </div>

        <div class="pp-card">
            <h3><i class="fas fa-timeline"></i> Linha do Tempo</h3>
            <div id="ppDetTimeline"><div class="pp-empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>

        <div class="pp-card" id="ppDetGaleriaCard" style="display:none;">
            <h3><i class="fas fa-images"></i> Galeria de Fotos</h3>
            <div id="ppDetGaleria" class="pp-galeria"></div>
        </div>

        <div class="pp-card" id="ppDetDocsCard" style="display:none;">
            <h3><i class="fas fa-folder-open"></i> Documentos</h3>
            <div id="ppDetDocs"></div>
        </div>
    </div>

</div>

<div class="pp-footer">Sistema ERP Condomínios — Portal de Transparência</div>

<div class="pp-lightbox" id="ppLightbox" onclick="ppFecharLightbox()">
    <img id="ppLightboxImg" src="" alt="">
</div>

<script>
const PP = {
    API: window.location.origin + '/api/api_publico_projetos.php',
    API_IMG: window.location.origin + '/api/api_imagem_projeto.php',
    buscaTimer: null,
    idInicial: <?php echo (int)$idProjeto; ?>,
};

function escHtmlPP(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ppApi(params) {
    const url = PP.API + '?' + new URLSearchParams(params).toString();
    return fetch(url).then(r => r.json());
}

// Status público é sempre derivado do status real da O.S pelo backend
// (aberto→planejamento, andamento→execução, finalizado→finalizado,
// cancelado→cancelado) — nunca cadastrado manualmente.
function ppStatusLabel(s) { return { planejamento:'Planejamento', execucao:'Em Execução', cancelado:'Cancelado', finalizado:'Finalizado' }[s] || s || '—'; }
function ppStatusCor(s)   { return { planejamento:'#64748b', execucao:'#2563eb', cancelado:'#dc2626', finalizado:'#16a34a' }[s] || '#64748b'; }
function ppCorPorPercentual(pct) {
    if (pct >= 80) return '#16a34a';
    if (pct >= 40) return '#2563eb';
    return '#f97316';
}

function ppCarregarDashboard() {
    ppApi({ acao: 'dashboard' }).then(data => {
        if (!data.sucesso) return;
        document.getElementById('ppDashExecucao').textContent     = data.dados.execucao;
        document.getElementById('ppDashPlanejamento').textContent = data.dados.planejamento;
        document.getElementById('ppDashCancelado').textContent    = data.dados.cancelado;
        document.getElementById('ppDashFinalizado').textContent   = data.dados.finalizado;
        document.getElementById('ppDashMedia').textContent        = data.dados.percentual_medio + '%';
    }).catch(() => {});
}

function ppDebounceBusca() {
    clearTimeout(PP.buscaTimer);
    PP.buscaTimer = setTimeout(ppCarregarLista, 350);
}

function ppCarregarLista() {
    const grid = document.getElementById('ppGrid');
    grid.innerHTML = '<div class="pp-empty"><i class="fas fa-spinner fa-spin"></i><p>Carregando projetos...</p></div>';
    const busca  = document.getElementById('ppBusca').value.trim();
    const status = document.getElementById('ppFiltroStatus').value;
    ppApi({ acao: 'listar', busca, status }).then(data => {
        if (!data.sucesso) { grid.innerHTML = `<div class="pp-empty"><i class="fas fa-hard-hat"></i><p>${escHtmlPP(data.mensagem)}</p></div>`; return; }
        const lista = data.dados?.projetos || [];
        if (!lista.length) { grid.innerHTML = '<div class="pp-empty"><i class="fas fa-hard-hat"></i><p>Nenhum projeto encontrado.</p></div>'; return; }
        grid.innerHTML = lista.map(ppRenderCard).join('');
    }).catch(() => { grid.innerHTML = '<div class="pp-empty"><i class="fas fa-exclamation-triangle"></i><p>Erro ao carregar projetos.</p></div>'; });
}

function ppRenderCard(p) {
    const pct = Math.max(0, Math.min(100, parseInt(p.projeto_percentual, 10) || 0));
    const cor = ppCorPorPercentual(pct);
    // Capa sempre resolvida pelo backend (capa definida > primeira foto publicada
    // > imagem institucional padrão) — o card nunca fica vazio.
    const capaUrl = `${PP.API_IMG}?tipo=capa&os_id=${p.id}&thumb=1`;
    return `
    <div class="pp-card-proj" onclick="ppAbrirDetalhe(${p.id})">
        <div class="pp-card-capa-wrap">
            <img class="pp-card-capa" src="${capaUrl}" alt="${escHtmlPP(p.projeto_nome)}" loading="lazy">
            <span class="pp-card-selo" style="background:${ppStatusCor(p.projeto_status)}">${ppStatusLabel(p.projeto_status)}</span>
        </div>
        <div class="pp-card-body">
            <div class="pp-card-nome">${escHtmlPP(p.projeto_nome)}</div>
            <div class="pp-progresso-mini"><div style="width:${pct}%;background:${cor}"></div></div>
            <span class="pp-card-pct" style="color:${cor}">${pct}%</span>
            <div class="pp-card-info">
                ${p.departamento_nome ? `<span><i class="fas fa-building"></i>${escHtmlPP(p.departamento_nome)}</span>` : ''}
                ${p.projeto_etapa_nome ? `<span><i class="fas fa-list-ol"></i>Etapa atual: ${escHtmlPP(p.projeto_etapa_nome)}</span>` : ''}
                ${p.projeto_data_fim_prevista ? `<span><i class="fas fa-calendar-check"></i>Previsão: ${p.projeto_data_fim_prevista}</span>` : ''}
                ${p.ultima_atualizacao ? `<span><i class="fas fa-clock"></i>Atualizado em ${p.ultima_atualizacao}</span>` : ''}
            </div>
            <button class="pp-card-btn"><i class="fas fa-arrow-right"></i> Acompanhar Projeto</button>
        </div>
    </div>`;
}

function ppVoltarLista() {
    document.getElementById('ppVistaDetalhe').style.display = 'none';
    document.getElementById('ppVistaLista').style.display = 'block';
    history.replaceState(null, '', window.location.pathname);
}

function ppAbrirDetalhe(id) {
    document.getElementById('ppVistaLista').style.display = 'none';
    document.getElementById('ppVistaDetalhe').style.display = 'block';
    document.getElementById('ppDetTimeline').innerHTML = '<div class="pp-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    document.getElementById('ppDetDocsCard').style.display = 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    history.replaceState(null, '', '?id=' + id);

    ppApi({ acao: 'detalhe', id }).then(data => {
        if (!data.sucesso) { alert(data.mensagem || 'Projeto não encontrado.'); ppVoltarLista(); return; }
        PP.projetoAtual = data.dados.projeto;
        ppRenderDetalhe(data.dados.projeto, data.dados.timeline || [], data.dados.documentos || []);
    }).catch(() => { alert('Erro ao carregar projeto.'); ppVoltarLista(); });
}

function ppRenderDetalhe(p, timeline, documentos) {
    const pct = Math.max(0, Math.min(100, parseInt(p.projeto_percentual, 10) || 0));
    const cor = ppCorPorPercentual(pct);

    document.getElementById('ppDetCapa').src = `${PP.API_IMG}?tipo=capa&os_id=${p.id}`;
    const selo = document.getElementById('ppDetHeroSelo');
    selo.textContent = ppStatusLabel(p.projeto_status);
    selo.style.background = ppStatusCor(p.projeto_status);

    document.title = (p.projeto_nome || 'Projeto') + ' — Transparência';
    document.getElementById('ppDetNome').textContent = p.projeto_nome;
    document.getElementById('ppDetPct').textContent = pct + '%';
    document.getElementById('ppDetPct').style.color = cor;
    document.getElementById('ppDetBarra').style.width = pct + '%';
    document.getElementById('ppDetBarra').style.background = cor;
    document.getElementById('ppDetDescricao').textContent    = p.projeto_descricao || '';
    // Departamento/Responsável/datas vêm sempre da própria O.S — nunca de
    // um cadastro duplicado do Projeto.
    document.getElementById('ppDetDepartamento').textContent = p.departamento_nome || '—';
    document.getElementById('ppDetResponsavel').textContent  = p.projeto_responsavel || '—';
    document.getElementById('ppDetInicio').textContent = p.projeto_data_inicio_prevista || '—';
    document.getElementById('ppDetFim').textContent    = p.projeto_data_fim_prevista || '—';
    document.getElementById('ppDetUltimaAtualizacao').textContent = p.ultima_atualizacao || '—';

    const tlEl = document.getElementById('ppDetTimeline');
    const todasFotos = []; // flatten para a Galeria
    if (!timeline.length) {
        tlEl.innerHTML = '<div class="pp-empty"><i class="fas fa-timeline"></i><p>Nenhuma atualização pública registrada ainda.</p></div>';
    } else {
        tlEl.innerHTML = timeline.map((t) => {
            const nomeEtapa = t.etapa_nome || 'Atualização';
            const fotos = (t.fotos || []).map(fotoId => {
                const urlFoto = `${PP.API_IMG}?tipo=foto&id=${fotoId}`;
                todasFotos.push(urlFoto);
                return `<img src="${urlFoto}" loading="lazy" onclick="ppAbrirLightbox('${urlFoto}')">`;
            }).join('');
            return `
            <div class="pp-timeline-item">
                <i class="fas fa-check-circle" style="color:#16a34a;margin-top:.15rem;"></i>
                <div>
                    <strong>${escHtmlPP(nomeEtapa)}</strong> ${t.percentual !== null && t.percentual !== undefined ? `— ${t.percentual}%` : ''}
                    <div style="font-size:.85rem;color:var(--text-secondary);margin-top:.2rem;">${t.mensagem || ''}</div>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem;">${t.data || ''}</div>
                    ${fotos ? `<div class="pp-timeline-fotos">${fotos}</div>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    const galeriaCard = document.getElementById('ppDetGaleriaCard');
    const galeriaEl   = document.getElementById('ppDetGaleria');
    if (todasFotos.length) {
        galeriaCard.style.display = 'block';
        galeriaEl.innerHTML = todasFotos.map(url => `<img src="${url}" loading="lazy" onclick="ppAbrirLightbox('${url}')">`).join('');
    } else {
        galeriaCard.style.display = 'none';
    }

    const docsCard = document.getElementById('ppDetDocsCard');
    const docsEl   = document.getElementById('ppDetDocs');
    if (documentos.length) {
        docsCard.style.display = 'block';
        docsEl.innerHTML = documentos.map(d => `
            <div style="display:flex;align-items:center;gap:.6rem;padding:.6rem 0;border-bottom:1px solid var(--border);">
                <i class="fas fa-file-alt"></i>
                <span style="flex:1;">${escHtmlPP(d.nome)}</span>
                <a class="pp-btn" style="padding:.4rem .8rem;font-size:.8rem;" href="${PP.API}?acao=documento&id=${d.id}"><i class="fas fa-download"></i></a>
            </div>
        `).join('');
    } else docsCard.style.display = 'none';
}

function ppAbrirLightbox(url) {
    document.getElementById('ppLightboxImg').src = url;
    document.getElementById('ppLightbox').classList.add('show');
}
function ppFecharLightbox() {
    document.getElementById('ppLightbox').classList.remove('show');
    document.getElementById('ppLightboxImg').src = '';
}

function ppCompartilhar() {
    const texto = encodeURIComponent((PP.projetoAtual?.projeto_nome || 'Projeto') + ' — acompanhe o andamento: ' + window.location.href);
    window.open('https://wa.me/?text=' + texto, '_blank');
}

// Inicialização
ppCarregarDashboard();
if (PP.idInicial) ppAbrirDetalhe(PP.idInicial);
else ppCarregarLista();
</script>
</body>
</html>
