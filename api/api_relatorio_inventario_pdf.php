<?php
/**
 * ============================================================
 * RELATÓRIO DE INVENTÁRIO — GERADOR DE IMPRESSÃO / PDF
 * ============================================================
 * Parâmetros GET:
 *   tipo        — geral | grupo | situacao | status | responsavel | baixas
 *   situacao    — imobilizado | circulante | (vazio = todos)
 *   status      — ativo | inativo | (vazio = todos)
 *   grupo_id    — ID do grupo (0 = todos)
 *   responsavel — ID do usuário responsável (0 = todos)
 *
 * Gera página HTML pronta para impressão / salvar como PDF.
 * @version 1.0.0
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

$conn    = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');
$tenant_id = exigirTenantId();

date_default_timezone_set('America/Sao_Paulo');

// ── Parâmetros ────────────────────────────────────────────────
$tipo        = trim($_GET['tipo']        ?? 'geral');
$filtro_sit  = trim($_GET['situacao']    ?? '');
$filtro_sta  = trim($_GET['status']      ?? '');
$filtro_grp  = intval($_GET['grupo_id']  ?? 0);
$filtro_resp = intval($_GET['responsavel'] ?? 0);

// ── Dados da empresa ──────────────────────────────────────────
$empresa = [];
$res_emp = $conn->query("SELECT razao_social, nome_fantasia, cnpj, logo_url,
                         endereco_rua, endereco_numero, endereco_cidade, endereco_estado
                         FROM empresa LIMIT 1");
if ($res_emp && $res_emp->num_rows > 0) {
    $empresa = $res_emp->fetch_assoc();
}
$nome_empresa = !empty($empresa['nome_fantasia'])
    ? $empresa['nome_fantasia']
    : (!empty($empresa['razao_social']) ? $empresa['razao_social'] : 'ASSOCIAÇÃO SERRA DA LIBERDADE');
$cnpj_empresa = !empty($empresa['cnpj']) ? $empresa['cnpj'] : '';

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
    ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
    : $protocolo . '://' . $host . '/assets/images/logo.jpeg';

// ── Buscar itens com filtros ──────────────────────────────────
$where   = ['1=1'];
$params  = [];
$types   = '';

if ($filtro_sit) {
    $where[]  = 'i.situacao = ?';
    $params[] = $filtro_sit;
    $types   .= 's';
}
if ($filtro_sta) {
    $where[]  = 'i.status = ?';
    $params[] = $filtro_sta;
    $types   .= 's';
}
if ($filtro_grp > 0) {
    $where[]  = 'i.grupo_id = ?';
    $params[] = $filtro_grp;
    $types   .= 'i';
}
if ($filtro_resp > 0) {
    $where[]  = 'i.tutela_usuario_id = ?';
    $params[] = $filtro_resp;
    $types   .= 'i';
}
if ($tipo === 'baixas') {
    $where[] = "i.status = 'inativo'";
}

$sql = "SELECT i.*,
               u.nome  AS tutela_nome,
               g.nome  AS grupo_nome,
               DATE_FORMAT(i.data_compra, '%d/%m/%Y') AS data_compra_fmt,
               DATE_FORMAT(i.data_baixa,  '%d/%m/%Y') AS data_baixa_fmt
        FROM inventario i
        LEFT JOIN usuarios          u ON i.tutela_usuario_id = u.id
        LEFT JOIN grupos_inventario g ON i.grupo_id          = g.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.numero_patrimonio ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$itens  = [];
while ($row = $result->fetch_assoc()) {
    $row['valor'] = (float)($row['valor'] ?? 0);
    $itens[] = $row;
}
$stmt->close();

// ── KPIs ──────────────────────────────────────────────────────
$total        = count($itens);
$total_ativos = count(array_filter($itens, fn($i) => $i['status'] === 'ativo'));
$total_inativ = count(array_filter($itens, fn($i) => $i['status'] === 'inativo'));
$valor_total  = array_sum(array_column($itens, 'valor'));
$valor_imob   = array_sum(array_map(fn($i) => $i['situacao'] === 'imobilizado' ? $i['valor'] : 0, $itens));

// ── Título do relatório ───────────────────────────────────────
$titulos = [
    'geral'       => 'Relatório Geral de Inventário',
    'grupo'       => 'Relatório de Inventário por Grupo',
    'situacao'    => 'Relatório de Inventário por Situação',
    'status'      => 'Relatório de Inventário por Status',
    'responsavel' => 'Relatório de Inventário por Responsável',
    'baixas'      => 'Relatório de Itens Baixados',
];
$titulo_relatorio = $titulos[$tipo] ?? 'Relatório de Inventário';

// ── Helpers ───────────────────────────────────────────────────
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

$data_hoje  = date('d/m/Y');
$hora_agora = date('H:i');

// Filtro descritivo para o subtítulo
$filtro_desc = [];
if ($filtro_sit)  $filtro_desc[] = 'Situação: ' . ucfirst($filtro_sit);
if ($filtro_sta)  $filtro_desc[] = 'Status: ' . ucfirst($filtro_sta);
if ($filtro_grp > 0) {
    $rg = $conn->query("SELECT nome FROM grupos_inventario WHERE id = $filtro_grp LIMIT 1");
    if ($rg && $rg->num_rows > 0) $filtro_desc[] = 'Grupo: ' . $rg->fetch_assoc()['nome'];
}
if ($filtro_resp > 0) {
    $ru = $conn->query("SELECT nome FROM usuarios WHERE id = $filtro_resp LIMIT 1");
    if ($ru && $ru->num_rows > 0) $filtro_desc[] = 'Responsável: ' . $ru->fetch_assoc()['nome'];
}
$filtro_texto = !empty($filtro_desc) ? implode(' | ', $filtro_desc) : 'Sem filtros aplicados';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($titulo_relatorio) ?> — <?= $data_hoje ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 11px; color: #1e293b;
    background: #f1f5f9;
}
.relatorio {
    max-width: 960px; margin: 20px auto;
    background: #fff; border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
    overflow: hidden;
}
/* ── Botão de impressão ── */
.btn-print {
    display: block; margin: 16px auto;
    background: #2563eb; color: #fff; border: none;
    padding: 10px 28px; border-radius: 8px; font-size: 13px;
    font-weight: 600; cursor: pointer; letter-spacing: .3px;
}
.btn-print:hover { background: #1d4ed8; }
/* ── Cabeçalho ── */
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
    padding: 24px 32px; display: flex; align-items: center; gap: 20px; color: #fff;
}
.header-logo {
    width: 72px; height: 72px; border-radius: 10px; object-fit: contain;
    background: #fff; padding: 4px; flex-shrink: 0;
}
.header-logo-placeholder {
    width: 72px; height: 72px; border-radius: 10px;
    background: rgba(255,255,255,.2); display: flex; align-items: center;
    justify-content: center; font-size: 28px; flex-shrink: 0;
}
.header-info { flex: 1; }
.header-info h1 { font-size: 18px; font-weight: 700; letter-spacing: .5px; }
.header-info p  { font-size: 11px; opacity: .85; margin-top: 2px; }
.header-meta { text-align: right; font-size: 10px; opacity: .8; line-height: 1.7; }
.header-meta strong { font-size: 13px; opacity: 1; display: block; margin-bottom: 2px; }
/* ── Faixa do título ── */
.titulo-relatorio {
    background: #1e3a8a; color: #fff;
    padding: 10px 32px; font-size: 13px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between;
}
.titulo-relatorio .filtro-info {
    font-size: 10px; font-weight: 400; opacity: .8;
    text-transform: none; letter-spacing: 0;
}
/* ── KPIs ── */
.kpis {
    display: grid; grid-template-columns: repeat(5, 1fr);
    gap: 0; border-bottom: 2px solid #e2e8f0;
}
.kpi { padding: 16px 20px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 22px; font-weight: 800; color: #1e3a8a; line-height: 1; }
.kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin-top: 4px; }
/* ── Seção ── */
.secao { padding: 0 32px 28px; }
.secao-titulo {
    font-size: 12px; font-weight: 700; color: #1e3a8a;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 16px 0 10px; border-bottom: 2px solid #2563eb;
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.secao-titulo::before {
    content: ''; display: inline-block; width: 4px; height: 16px;
    background: linear-gradient(180deg, #2563eb, #1e3a8a); border-radius: 2px;
}
/* ── Tabelas ── */
table { width: 100%; border-collapse: collapse; font-size: 10px; }
thead tr {
    background: linear-gradient(90deg, #1e3a8a, #2563eb);
    color: #fff;
}
thead th {
    padding: 9px 10px; text-align: left; font-weight: 700;
    font-size: 9px; text-transform: uppercase; letter-spacing: .6px;
    white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody td { padding: 8px 10px; vertical-align: middle; }
/* ── Badges ── */
.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.badge-ativo       { background: #dcfce7; color: #166534; }
.badge-inativo     { background: #fee2e2; color: #991b1b; }
.badge-imobilizado { background: #dbeafe; color: #1d4ed8; }
.badge-circulante  { background: #fef3c7; color: #92400e; }
.sem-dados { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; }
/* ── Bloco de baixa ── */
.baixa-bloco {
    background: #fff5f5; border-left: 4px solid #ef4444;
    padding: 12px 16px; margin-bottom: 10px;
    border-radius: 0 8px 8px 0;
}
.baixa-bloco h4 { font-size: 12px; color: #991b1b; margin-bottom: 6px; }
.baixa-bloco p  { font-size: 10px; color: #475569; margin: 2px 0; }
/* ── Rodapé ── */
.rodape {
    background: #1e3a8a; color: rgba(255,255,255,.75);
    padding: 12px 32px; font-size: 9px;
    display: flex; justify-content: space-between; align-items: center;
}
.rodape strong { color: #fff; }
/* ── Impressão ── */
@media print {
    html, body { background: #fff; font-size: 10px; }
    .btn-print { display: none !important; }
    .relatorio { box-shadow: none; border-radius: 0; max-width: 100%; margin: 0; }
    @page { margin: 10mm 8mm; size: A4 landscape; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .baixa-bloco { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">
    &#128438; Imprimir / Salvar PDF
</button>

<div class="relatorio">

    <!-- CABEÇALHO -->
    <div class="header">
        <?php if ($logo_url): ?>
        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="header-logo-placeholder" style="display:none;">&#127968;</div>
        <?php else: ?>
        <div class="header-logo-placeholder">&#127968;</div>
        <?php endif; ?>
        <div class="header-info">
            <h1><?= esc($nome_empresa) ?></h1>
            <?php if ($cnpj_empresa): ?><p>CNPJ: <?= esc($cnpj_empresa) ?></p><?php endif; ?>
        </div>
        <div class="header-meta">
            <strong>RELATÓRIO DE INVENTÁRIO</strong>
            Emitido em: <?= $data_hoje ?> às <?= $hora_agora ?>
        </div>
    </div>

    <!-- FAIXA TÍTULO -->
    <div class="titulo-relatorio">
        <span><?= esc($titulo_relatorio) ?></span>
        <span class="filtro-info"><?= esc($filtro_texto) ?></span>
    </div>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-valor"><?= $total ?></div>
            <div class="kpi-label">Total de Itens</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor" style="color:#16a34a;"><?= $total_ativos ?></div>
            <div class="kpi-label">Ativos</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor" style="color:#dc2626;"><?= $total_inativ ?></div>
            <div class="kpi-label">Baixados</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor" style="font-size:14px;"><?= money($valor_total) ?></div>
            <div class="kpi-label">Valor Total</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor" style="font-size:14px;"><?= money($valor_imob) ?></div>
            <div class="kpi-label">Valor Imobilizado</div>
        </div>
    </div>

    <!-- TABELA PRINCIPAL -->
    <div class="secao" style="padding-top:24px;">
        <div class="secao-titulo">&#128230; Itens do Inventário</div>
        <?php if (empty($itens)): ?>
        <p class="sem-dados">Nenhum item encontrado com os filtros aplicados.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Patrimônio</th>
                    <th>Nome do Item</th>
                    <th>Grupo</th>
                    <th>Fabricante</th>
                    <th>Modelo</th>
                    <th>Situação</th>
                    <th>Status</th>
                    <th>Valor</th>
                    <th>Responsável</th>
                    <th>Data Compra</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                <tr>
                    <td><strong><?= esc($item['numero_patrimonio']) ?></strong></td>
                    <td><?= esc($item['nome_item']) ?></td>
                    <td><?= esc($item['grupo_nome'] ?? '—') ?></td>
                    <td><?= esc($item['fabricante'] ?? '—') ?></td>
                    <td><?= esc($item['modelo'] ?? '—') ?></td>
                    <td>
                        <span class="badge <?= $item['situacao'] === 'imobilizado' ? 'badge-imobilizado' : 'badge-circulante' ?>">
                            <?= $item['situacao'] === 'imobilizado' ? 'Imobilizado' : 'Circulante' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $item['status'] === 'ativo' ? 'badge-ativo' : 'badge-inativo' ?>">
                            <?= $item['status'] === 'ativo' ? 'Ativo' : 'Baixado' ?>
                        </span>
                    </td>
                    <td><?= money($item['valor']) ?></td>
                    <td><?= esc($item['tutela_nome'] ?? '—') ?></td>
                    <td><?= esc($item['data_compra_fmt'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- SEÇÃO DE BAIXAS (somente no tipo baixas) -->
    <?php if ($tipo === 'baixas' && !empty($itens)): ?>
    <div class="secao">
        <div class="secao-titulo">&#9888; Detalhamento das Baixas</div>
        <?php foreach ($itens as $item): ?>
        <div class="baixa-bloco">
            <h4><?= esc($item['numero_patrimonio']) ?> — <?= esc($item['nome_item']) ?></h4>
            <p><strong>Data da Baixa:</strong> <?= esc($item['data_baixa_fmt'] ?? 'Não informada') ?></p>
            <p><strong>Motivo:</strong> <?= esc($item['motivo_baixa'] ?? 'Não informado') ?></p>
            <?php if ($item['tutela_nome']): ?>
            <p><strong>Responsável:</strong> <?= esc($item['tutela_nome']) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- RODAPÉ -->
    <div class="rodape">
        <span>Documento gerado pelo <strong>Sistema ERP Condomínio</strong></span>
        <span><?= $data_hoje ?> às <?= $hora_agora ?> | <?= esc($nome_empresa) ?></span>
    </div>

</div><!-- /relatorio -->
</body>
</html>
