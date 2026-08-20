<?php
/**
 * ============================================================
 * RELATORIO DE VISITANTES — GERADOR DE PDF/IMPRESSAO
 * ============================================================
 * Template padrao do sistema ERP Condominio.
 * Identidade visual: azul #1e3a8a / #2563eb + logo da associacao.
 *
 * Filtros aceitos via GET:
 *   nome       — filtro por nome do visitante
 *   cpf        — filtro por CPF/documento
 *   email      — filtro por e-mail
 *   tem_foto   — "" | "sim" | "nao"
 *   tem_doc    — "" | "sim" | "nao"
 *   ativo      — "" | "1" | "0"
 *   origem     — "" | "FUNCIONARIO" | "MORADOR" | "LEGADO"
 *   print      — Se "true", dispara window.print() automaticamente
 *
 * @version 1.0.0
 */
// ── 1. Bootstrap ──────────────────────────────────────────────
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

$conn    = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');
$tenant_id = exigirTenantId();

// ── 2. Configuracoes regionais ────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

// ── 3. Dados da empresa ───────────────────────────────────────
$empresa = [];
$_tenant_id_rel = (int)$tenant_id;
$res_emp = $conn->prepare("SELECT COALESCE(NULLIF(t.razao_social, ''), e.razao_social) AS razao_social, COALESCE(NULLIF(t.nome_fantasia, ''), e.nome_fantasia) AS nome_fantasia, COALESCE(NULLIF(t.cnpj, ''), e.cnpj) AS cnpj, COALESCE(NULLIF(t.logo_url, ''), e.logo_url) AS logo_url FROM tenants t LEFT JOIN empresa e ON e.tenant_id = t.id WHERE t.id = ? AND t.status = 'ativo' LIMIT 1");
$res_emp->bind_param('i', $_tenant_id_rel);
$res_emp->execute();
$res_emp = $res_emp->get_result();
if ($res_emp && $res_emp->num_rows > 0) {
    $empresa = $res_emp->fetch_assoc();
}
$nome_empresa = !empty($empresa['nome_fantasia'])  ? $empresa['nome_fantasia']
              : (!empty($empresa['razao_social'])  ? $empresa['razao_social']
              : 'ASSOCIACAO ERP CONDOMÍNIO');
$cnpj_empresa = !empty($empresa['cnpj']) ? $empresa['cnpj'] : '';

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'app.erpcondominios.com.br';
if (!empty($empresa['logo_url'])) {
    $logo_url = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
} else {
    $logo_url = $protocolo . '://' . $host . '/assets/img/logos/logo_padrao.png';
}

// ── 4. Filtros ────────────────────────────────────────────────
$f_nome     = trim($_GET['nome']     ?? '');
$f_cpf      = trim($_GET['cpf']      ?? '');
$f_email    = trim($_GET['email']    ?? '');
$f_tem_foto = trim($_GET['tem_foto'] ?? '');
$f_tem_doc  = trim($_GET['tem_doc']  ?? '');
$f_ativo    = trim($_GET['ativo']    ?? '');
$f_origem   = strtoupper(trim($_GET['origem'] ?? ''));
$auto_print = ($_GET['print'] ?? '') === 'true';
$tipo_relatorio = ($_GET['tipo_relatorio'] ?? '') === 'analitico' ? 'analitico' : 'padrao';
$data_inicio_analitico = trim($_GET['data_inicio'] ?? date('Y-m-01'));
$data_fim_analitico = trim($_GET['data_fim'] ?? date('Y-m-d'));
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $data_inicio_analitico)) $data_inicio_analitico = date('Y-m-01');
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $data_fim_analitico)) $data_fim_analitico = date('Y-m-d');
if ($data_inicio_analitico > $data_fim_analitico) $data_inicio_analitico = $data_fim_analitico;

$titulo_relatorio = $tipo_relatorio === 'analitico' ? 'Relatorio Analitico de Acessos' : 'Relatorio de Visitantes';

// ── 5. Buscar dados ───────────────────────────────────────────
$visitantes = [];

$sql = "SELECT v.id, v.nome_completo, v.documento, v.tipo_documento,
               v.telefone_contato, v.celular, v.email,
               v.placa_veiculo, v.foto, v.documento_arquivo, v.ativo,
               CASE
                   WHEN v.cadastrado_por_tipo = 'FUNCIONARIO' OR v.cadastrado_por_usuario_id IS NOT NULL
                       THEN 'FUNCIONARIO'
                   WHEN v.cadastrado_por_tipo = 'MORADOR' OR v.cadastrado_por_morador_id IS NOT NULL OR v.morador_id IS NOT NULL
                       THEN 'MORADOR'
                   ELSE 'LEGADO'
               END AS cadastrado_por_tipo,
               CASE
                   WHEN v.cadastrado_por_tipo = 'FUNCIONARIO' OR v.cadastrado_por_usuario_id IS NOT NULL
                       THEN COALESCE(NULLIF(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado'), NULLIF(u.nome, ''), 'Usuário não identificado')
                   WHEN v.cadastrado_por_tipo = 'MORADOR' OR v.cadastrado_por_morador_id IS NOT NULL OR v.morador_id IS NOT NULL
                       THEN COALESCE(NULLIF(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado'), NULLIF(m_autor.nome, ''), NULLIF(m_legado.nome, ''), 'Morador não identificado')
                   ELSE COALESCE(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado')
               END AS cadastrado_por_nome,
               DATE_FORMAT(v.data_cadastro, '%d/%m/%Y') as data_cadastro
        FROM visitantes v
        LEFT JOIN usuarios u
               ON u.id = v.cadastrado_por_usuario_id
        LEFT JOIN moradores m_autor
               ON m_autor.id = v.cadastrado_por_morador_id
              AND m_autor.tenant_id = v.tenant_id
        LEFT JOIN moradores m_legado
               ON m_legado.id = v.morador_id
              AND m_legado.tenant_id = v.tenant_id
        WHERE v.tenant_id = ?";

$params = [$tenant_id];
$tipos  = 'i';

if ($f_nome !== '') {
    $sql .= " AND v.nome_completo LIKE ?";
    $params[] = '%' . $f_nome . '%';
    $tipos .= 's';
}
if ($f_cpf !== '') {
    $cpf_limpo = preg_replace('/[^0-9A-Za-z]/', '', $f_cpf);
    $sql .= " AND (v.documento LIKE ? OR REPLACE(REPLACE(REPLACE(v.documento,'.',''),'-',''),'/','') LIKE ?)";
    $params[] = '%' . $f_cpf . '%';
    $params[] = '%' . $cpf_limpo . '%';
    $tipos .= 'ss';
}
if ($f_email !== '') {
    $sql .= " AND v.email LIKE ?";
    $params[] = '%' . $f_email . '%';
    $tipos .= 's';
}
if ($f_tem_foto === 'sim') {
    $sql .= " AND v.foto IS NOT NULL AND v.foto != ''";
} elseif ($f_tem_foto === 'nao') {
    $sql .= " AND (v.foto IS NULL OR v.foto = '')";
}
if ($f_tem_doc === 'sim') {
    $sql .= " AND v.documento_arquivo IS NOT NULL AND v.documento_arquivo != ''";
} elseif ($f_tem_doc === 'nao') {
    $sql .= " AND (v.documento_arquivo IS NULL OR v.documento_arquivo = '')";
}
if ($f_ativo === '1') {
    $sql .= " AND v.ativo = 1";
} elseif ($f_ativo === '0') {
    $sql .= " AND v.ativo = 0";
}
if (in_array($f_origem, ['FUNCIONARIO', 'MORADOR', 'LEGADO'], true)) {
    $sql .= " AND v.cadastrado_por_tipo = ?";
    $params[] = $f_origem;
    $tipos .= 's';
}

$sql .= " ORDER BY v.nome_completo ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $visitantes[] = $row;
    }
    $stmt->close();
} else {
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $visitantes[] = $row;
        }
    }
}

// KPIs
$total       = count($visitantes);
$com_foto    = count(array_filter($visitantes, function($v) { return !empty($v['foto']); }));
$com_doc     = count(array_filter($visitantes, function($v) { return !empty($v['documento_arquivo']); }));
$ativos      = count(array_filter($visitantes, function($v) { return ($v['ativo'] ?? 1) == 1; }));

// Resumo dos filtros aplicados
$filtros_aplicados = [];
if ($f_nome    !== '') $filtros_aplicados[] = 'Nome: "' . $f_nome . '"';
if ($f_cpf     !== '') $filtros_aplicados[] = 'CPF/Doc: "' . $f_cpf . '"';
if ($f_email   !== '') $filtros_aplicados[] = 'E-mail: "' . $f_email . '"';
if ($f_tem_foto === 'sim') $filtros_aplicados[] = 'Com foto';
if ($f_tem_foto === 'nao') $filtros_aplicados[] = 'Sem foto';
if ($f_tem_doc  === 'sim') $filtros_aplicados[] = 'Com documento';
if ($f_tem_doc  === 'nao') $filtros_aplicados[] = 'Sem documento';
if ($f_ativo === '1') $filtros_aplicados[] = 'Somente ativos';
if ($f_ativo === '0') $filtros_aplicados[] = 'Somente inativos';
if (in_array($f_origem, ['FUNCIONARIO', 'MORADOR', 'LEGADO'], true)) $filtros_aplicados[] = 'Origem: ' . $f_origem;
$resumo_filtros = implode(' | ', $filtros_aplicados);

// ── Indicadores analíticos de acessos (Visitante e Prestador) ─────────────
$analitico = [
    'resumo' => ['total' => 0, 'visitantes' => 0, 'prestadores' => 0, 'entradas' => 0, 'saidas' => 0, 'liberados' => 0, 'pendentes' => 0],
    'pico' => ['rotulo' => 'Sem registros', 'total' => 0],
    'unidades' => [], 'pessoas' => [], 'tendencia' => []
];
if ($tipo_relatorio === 'analitico') {
    $where = "tenant_id = ? AND data_hora >= ? AND data_hora < DATE_ADD(?, INTERVAL 1 DAY) AND tipo IN ('Visitante','Prestador')";
    $paramsAnalitico = [$tenant_id, $data_inicio_analitico, $data_fim_analitico];
    $sqlResumoAnalitico = "SELECT COUNT(*) total, SUM(tipo='Visitante') visitantes, SUM(tipo='Prestador') prestadores, SUM(tipo_acesso='Entrada') entradas, SUM(tipo_acesso='Saída') saidas, SUM(liberado=1) liberados, SUM(liberado=0) pendentes FROM registros_acesso WHERE $where";
    $st = $conn->prepare($sqlResumoAnalitico);
    if ($st) { $st->bind_param('iss', ...$paramsAnalitico); $st->execute(); $linha = $st->get_result()->fetch_assoc() ?: []; $st->close(); foreach ($analitico['resumo'] as $campo => $_) $analitico['resumo'][$campo] = (int)($linha[$campo] ?? 0); }

    $st = $conn->prepare("SELECT HOUR(data_hora) hora, COUNT(*) total FROM registros_acesso WHERE tenant_id = ? AND data_hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND tipo IN ('Visitante','Prestador') GROUP BY HOUR(data_hora) ORDER BY total DESC, hora ASC LIMIT 1");
    if ($st) { $st->bind_param('i', $tenant_id); $st->execute(); $linha = $st->get_result()->fetch_assoc(); $st->close(); if ($linha) $analitico['pico'] = ['rotulo' => str_pad((string)$linha['hora'], 2, '0', STR_PAD_LEFT) . ':00', 'total' => (int)$linha['total']]; }

    $st = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(unidade_destino), ''), 'Não informado') unidade, COUNT(*) total, SUM(tipo='Visitante') visitantes, SUM(tipo='Prestador') prestadores FROM registros_acesso WHERE $where GROUP BY COALESCE(NULLIF(TRIM(unidade_destino), ''), 'Não informado') ORDER BY total DESC, unidade ASC LIMIT 10");
    if ($st) { $st->bind_param('iss', ...$paramsAnalitico); $st->execute(); $rs = $st->get_result(); while ($linha = $rs->fetch_assoc()) $analitico['unidades'][] = $linha; $st->close(); }

    $st = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(nome_visitante), ''), 'Não identificado') nome, tipo, COUNT(*) total FROM registros_acesso WHERE $where GROUP BY COALESCE(NULLIF(TRIM(nome_visitante), ''), 'Não identificado'), tipo ORDER BY total DESC, nome ASC LIMIT 10");
    if ($st) { $st->bind_param('iss', ...$paramsAnalitico); $st->execute(); $rs = $st->get_result(); while ($linha = $rs->fetch_assoc()) $analitico['pessoas'][] = $linha; $st->close(); }

    $st = $conn->prepare("SELECT DATE_FORMAT(data_hora, '%d/%m') data, COUNT(*) total, SUM(tipo='Visitante') visitantes, SUM(tipo='Prestador') prestadores FROM registros_acesso WHERE $where GROUP BY DATE(data_hora), DATE_FORMAT(data_hora, '%d/%m') ORDER BY DATE(data_hora) DESC LIMIT 31");
    if ($st) { $st->bind_param('iss', ...$paramsAnalitico); $st->execute(); $rs = $st->get_result(); while ($linha = $rs->fetch_assoc()) $analitico['tendencia'][] = $linha; $st->close(); }
    $resumo_filtros = 'Período: ' . $data_inicio_analitico . ' até ' . $data_fim_analitico;
}

// Data/hora
$data_geracao  = date('d/m/Y \a\s H:i');
$operador_nome = $usuario ? ($usuario['nome'] ?? 'Sistema') : 'Sistema';

// ── 6. Funcoes auxiliares ─────────────────────────────────────
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt_doc($doc, $tipo) {
    $d = preg_replace('/\D/', '', $doc ?? '');
    if ($tipo === 'CPF' && strlen($d) === 11) {
        return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
    }
    return $doc ?: '—';
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($titulo_relatorio) ?> — <?= esc($nome_empresa) ?></title>
<style>
/* ── Reset e base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a2e; background: #f0f4f8; }

/* ── Botao de impressao ── */
.btn-print {
    position: fixed; top: 16px; right: 16px; z-index: 9999;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #fff; border: none; border-radius: 8px;
    padding: 10px 22px; font-size: 13px; font-weight: 600;
    cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,.4);
    display: flex; align-items: center; gap: 8px;
    transition: transform .15s;
}
.btn-print:hover { transform: translateY(-1px); }

/* ── Container principal ── */
.relatorio {
    max-width: 960px; margin: 20px auto; background: #fff;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 32px rgba(30,58,138,.12);
}

/* ── Cabecalho ── */
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
    padding: 24px 32px; display: flex; align-items: center; gap: 20px; color: #fff;
}
.header-logo {
    width: 72px; height: 72px; border-radius: 10px; object-fit: contain;
    background: #fff; padding: 4px; flex-shrink: 0;
}
.header-info { flex: 1; }
.header-info h1 { font-size: 18px; font-weight: 700; letter-spacing: .5px; }
.header-info p  { font-size: 11px; opacity: .85; margin-top: 2px; }
.header-meta { text-align: right; font-size: 10px; opacity: .8; line-height: 1.7; }
.header-meta strong { font-size: 13px; opacity: 1; display: block; margin-bottom: 2px; }

/* ── Faixa titulo ── */
.titulo-relatorio {
    background: #1e3a8a; color: #fff;
    padding: 10px 32px; font-size: 13px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between;
}
.titulo-relatorio .filtro-info {
    font-size: 10px; font-weight: 400; opacity: .8;
    text-transform: none; letter-spacing: 0; max-width: 55%;
    text-align: right; line-height: 1.5;
}

/* ── KPIs ── */
.kpis {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 0; border-bottom: 2px solid #e2e8f0;
}
.kpi { padding: 16px 20px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 24px; font-weight: 800; color: #1e3a8a; line-height: 1; }
.kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin-top: 4px; }

/* ── Secao ── */
.secao { padding: 0 32px 24px; }
.secao-titulo {
    font-size: 12px; font-weight: 700; color: #1e3a8a;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 16px 0 10px; border-bottom: 2px solid #2563eb;
    margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
}
.secao-titulo::before {
    content: ''; display: inline-block; width: 4px; height: 16px;
    background: linear-gradient(180deg, #2563eb, #1e3a8a); border-radius: 2px;
}

/* ── Tabela ── */
table { width: 100%; border-collapse: collapse; font-size: 10px; }
thead tr { background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff; }
thead th {
    padding: 9px 8px; text-align: left; font-weight: 700;
    font-size: 9px; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody tr:hover { background: #eff6ff; }
tbody td { padding: 7px 8px; vertical-align: middle; }

.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.badge-ativo   { background: #dcfce7; color: #166534; }
.badge-inativo { background: #fee2e2; color: #991b1b; }
.badge-sim     { background: #dbeafe; color: #1d4ed8; }
.badge-nao     { background: #f1f5f9; color: #64748b; }
.badge-cpf     { background: #fef3c7; color: #92400e; }
.badge-rg      { background: #ede9fe; color: #5b21b6; }

.sem-dados { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; }

/* ── Rodape ── */
.rodape {
    background: #1e3a8a; color: rgba(255,255,255,.75);
    padding: 12px 32px; font-size: 9px;
    display: flex; justify-content: space-between; align-items: center;
}
.rodape strong { color: #fff; }

/* ── Impressao ── */
@media print {
    html, body { background: #fff; }
    .btn-print { display: none !important; }
    .relatorio { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    @page { margin: 10mm 8mm; size: A4; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">
    &#128438; Imprimir / Salvar PDF
</button>

<div class="relatorio">

    <!-- CABECALHO -->
    <div class="header">
        <?php if ($logo_url): ?>
        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <?php endif; ?>
        <div class="header-info">
            <h1><?= esc($nome_empresa) ?></h1>
            <p>CNPJ: <?= esc($cnpj_empresa) ?></p>
            <p>Sistema ERP Condominio — Modulo de Visitantes</p>
        </div>
        <div class="header-meta">
            <strong><?= esc($titulo_relatorio) ?></strong>
            Gerado em: <?= $data_geracao ?><br>
            Operador: <?= esc($operador_nome) ?>
        </div>
    </div>

    <!-- TITULO DO RELATORIO -->
    <div class="titulo-relatorio">
        <span>&#128100; <?= esc($titulo_relatorio) ?></span>
        <?php if ($resumo_filtros): ?>
        <span class="filtro-info">Filtros: <?= esc($resumo_filtros) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($tipo_relatorio === 'analitico'): ?>
    <?php $r = $analitico['resumo']; $taxa = $r['total'] ? round(($r['liberados'] / $r['total']) * 100) : 0; ?>
    <div class="kpis">
        <div class="kpi"><div class="kpi-valor"><?= $r['total'] ?></div><div class="kpi-label">Acessos no Período</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $r['visitantes'] ?></div><div class="kpi-label">Visitantes</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $r['prestadores'] ?></div><div class="kpi-label">Prestadores</div></div>
        <div class="kpi"><div class="kpi-valor"><?= esc($analitico['pico']['rotulo']) ?></div><div class="kpi-label">Pico 24h (<?= $analitico['pico']['total'] ?>)</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $taxa ?>%</div><div class="kpi-label">Taxa de Liberação</div></div>
    </div>

    <div class="secao">
        <div class="secao-titulo">Ranking de Glebas / Unidades por Acessos</div>
        <table><thead><tr><th>#</th><th>Gleba / Unidade</th><th>Total</th><th>Visitantes</th><th>Prestadores</th></tr></thead><tbody>
        <?php if (empty($analitico['unidades'])): ?>
            <tr><td colspan="5" class="sem-dados">Não há acessos de visitantes ou prestadores no período selecionado</td></tr>
        <?php else: foreach ($analitico['unidades'] as $pos => $linha): ?>
            <tr><td><?= $pos + 1 ?></td><td><strong><?= esc($linha['unidade']) ?></strong></td><td><?= (int)$linha['total'] ?></td><td><?= (int)$linha['visitantes'] ?></td><td><?= (int)$linha['prestadores'] ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>

    <div class="secao">
        <div class="secao-titulo">Pessoas Mais Registradas</div>
        <table><thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Total de Acessos</th></tr></thead><tbody>
        <?php if (empty($analitico['pessoas'])): ?>
            <tr><td colspan="4" class="sem-dados">Sem recorrências no período selecionado</td></tr>
        <?php else: foreach ($analitico['pessoas'] as $pos => $linha): ?>
            <tr><td><?= $pos + 1 ?></td><td><?= esc($linha['nome']) ?></td><td><?= esc($linha['tipo']) ?></td><td><?= (int)$linha['total'] ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>

    <div class="secao">
        <div class="secao-titulo">Tendência Diária de Acessos</div>
        <table><thead><tr><th>Data</th><th>Total</th><th>Visitantes</th><th>Prestadores</th></tr></thead><tbody>
        <?php if (empty($analitico['tendencia'])): ?>
            <tr><td colspan="4" class="sem-dados">Sem movimentos no período selecionado</td></tr>
        <?php else: foreach ($analitico['tendencia'] as $linha): ?>
            <tr><td><?= esc($linha['data']) ?></td><td><?= (int)$linha['total'] ?></td><td><?= (int)$linha['visitantes'] ?></td><td><?= (int)$linha['prestadores'] ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
        <p style="margin-top:10px;font-size:9px;color:#64748b;">Entradas: <?= $r['entradas'] ?> &nbsp;|&nbsp; Saídas: <?= $r['saidas'] ?> &nbsp;|&nbsp; Liberados: <?= $r['liberados'] ?> &nbsp;|&nbsp; Pendentes: <?= $r['pendentes'] ?></p>
    </div>

    <?php else: ?>
    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-valor"><?= $total ?></div>
            <div class="kpi-label">Total de Visitantes</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= $ativos ?></div>
            <div class="kpi-label">Visitantes Ativos</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= $com_foto ?></div>
            <div class="kpi-label">Com Foto</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= $com_doc ?></div>
            <div class="kpi-label">Com Documento</div>
        </div>
    </div>

    <!-- TABELA DE VISITANTES -->
    <div class="secao">
        <div class="secao-titulo">Lista de Visitantes</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nome Completo</th>
                    <th>Tipo Doc.</th>
                    <th>Documento (CPF/RG)</th>
                    <th>E-mail</th>
                    <th>Telefone</th>
                    <th>Placa Veiculo</th>
                    <th>Foto</th>
                    <th>Documento</th>
                    <th>Status</th>
                    <th>Cadastrado por</th>
                    <th>Tipo</th>
                    <th>Cadastro</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($visitantes)): ?>
                <tr><td colspan="13" class="sem-dados">Nenhum visitante encontrado com os filtros aplicados</td></tr>
            <?php else: ?>
                <?php foreach ($visitantes as $v): ?>
                <tr>
                    <td style="color:#94a3b8"><?= (int)$v['id'] ?></td>
                    <td><strong><?= esc($v['nome_completo'] ?? '—') ?></strong></td>
                    <td>
                        <?php $td = strtoupper($v['tipo_documento'] ?? 'CPF'); ?>
                        <span class="badge <?= $td === 'CPF' ? 'badge-cpf' : 'badge-rg' ?>"><?= esc($td) ?></span>
                    </td>
                    <td><?= fmt_doc($v['documento'], $v['tipo_documento'] ?? 'CPF') ?></td>
                    <td><?= esc($v['email'] ?? '—') ?></td>
                    <td><?= esc($v['telefone_contato'] ?: ($v['celular'] ?? '—')) ?></td>
                    <td><?= esc($v['placa_veiculo'] ?? '—') ?></td>
                    <td style="text-align:center">
                        <?php if (!empty($v['foto'])): ?>
                        <span class="badge badge-sim">Sim</span>
                        <?php else: ?>
                        <span class="badge badge-nao">Nao</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if (!empty($v['documento_arquivo'])): ?>
                        <span class="badge badge-sim">Sim</span>
                        <?php else: ?>
                        <span class="badge badge-nao">Nao</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($v['ativo'] ?? 1) == 1): ?>
                        <span class="badge badge-ativo">Ativo</span>
                        <?php else: ?>
                        <span class="badge badge-inativo">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= esc($v['cadastrado_por_nome'] ?? 'Cadastro legado') ?></td>
                    <td><?= esc($v['cadastrado_por_tipo'] ?? 'LEGADO') ?></td>
                    <td style="white-space:nowrap"><?= esc($v['data_cadastro'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;font-size:9px;color:#64748b;text-align:right;">
            Total: <?= count($visitantes) ?> visitante(s) encontrado(s)
        </p>
    </div>

    <?php endif; ?>

    <!-- RODAPE -->
    <div class="rodape">
        <span><strong><?= esc($nome_empresa) ?></strong> — Sistema ERP Condominio</span>
        <span>Relatorio gerado em <?= $data_geracao ?> por <?= esc($operador_nome) ?></span>
    </div>

</div>

<?php if ($auto_print): ?>
<script>
window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 600); });
</script>
<?php endif; ?>

</body>
</html>
