<?php
/**
 * ============================================================
 * RELATORIO — VEICULOS COM MAIS ACESSOS (ULTIMOS 30 DIAS)
 * ============================================================
 * Template padrao do sistema ERP Condominio.
 * Identidade visual: azul #1e3a8a / #2563eb + logo da associacao.
 *
 * Filtros aceitos via GET:
 *   dias       — Periodo em dias (padrao: 30)
 *   tipo       — Morador | Visitante | Prestador | "" (todos)
 *   placa      — Filtro por placa (parcial)
 *   top        — Quantidade maxima de veiculos no ranking (padrao: 20)
 *   print      — Se "true", dispara window.print() automaticamente
 *
 * @version 1.0.0
 */
// ── 1. Bootstrap ──────────────────────────────────────────────
require_once 'config.php';
require_once 'auth_helper.php';

$conn    = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');

// ── 2. Configuracoes regionais ────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

// ── 3. Dados da empresa ───────────────────────────────────────
$empresa = [];
$res_emp = $conn->query("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa LIMIT 1");
if ($res_emp && $res_emp->num_rows > 0) {
    $empresa = $res_emp->fetch_assoc();
}
$nome_empresa = !empty($empresa['nome_fantasia'])  ? $empresa['nome_fantasia']
              : (!empty($empresa['razao_social'])  ? $empresa['razao_social']
              : 'ASSOCIACAO SERRA DA LIBERDADE');
$cnpj_empresa = !empty($empresa['cnpj']) ? $empresa['cnpj'] : '28.231.106/0001-15';

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
           : $protocolo . '://' . $host . '/assets/images/logo.jpeg';

// ── 4. Filtros ────────────────────────────────────────────────
$dias       = max(1, (int)($_GET['dias']  ?? 30));
$tipo_filtro = trim($_GET['tipo']  ?? '');
$placa_filtro = strtoupper(trim($_GET['placa'] ?? ''));
$top        = max(5, min(200, (int)($_GET['top'] ?? 20)));
$auto_print = ($_GET['print'] ?? '') === 'true';

$data_inicio = date('Y-m-d', strtotime("-{$dias} days"));
$data_fim    = date('Y-m-d');
$data_inicio_fmt = date('d/m/Y', strtotime("-{$dias} days"));
$data_fim_fmt    = date('d/m/Y');

// ── 5. Query — Ranking por placa ─────────────────────────────
$where  = ["DATE(r.data_hora) >= ?", "DATE(r.data_hora) <= ?"];
$params = [$data_inicio, $data_fim];
$types  = 'ss';

if ($tipo_filtro !== '' && in_array($tipo_filtro, ['Morador','Visitante','Prestador'])) {
    $where[]  = "r.tipo = ?";
    $params[] = $tipo_filtro;
    $types   .= 's';
}
if ($placa_filtro !== '') {
    $where[]  = "r.placa LIKE ?";
    $params[] = '%' . $placa_filtro . '%';
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

$sql_ranking = "
    SELECT
        r.placa,
        MAX(r.modelo) AS modelo,
        MAX(r.cor)    AS cor,
        r.tipo,
        COALESCE(MAX(m.nome), MAX(r.nome_visitante), r.placa) AS nome_responsavel,
        COALESCE(MAX(m.unidade), MAX(r.unidade_destino), '—') AS unidade,
        COUNT(*)                                               AS total_acessos,
        SUM(r.liberado)                                        AS acessos_liberados,
        MIN(DATE_FORMAT(r.data_hora, '%d/%m/%Y'))              AS primeiro_acesso,
        MAX(DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i'))        AS ultimo_acesso
    FROM registros_acesso r
    LEFT JOIN moradores m ON r.morador_id = m.id
    WHERE {$where_sql}
    GROUP BY r.placa, r.tipo
    ORDER BY total_acessos DESC
    LIMIT {$top}
";

$ranking = [];
$stmt = $conn->prepare($sql_ranking);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $ranking[] = $row;
}
$stmt->close();

// ── 6. KPIs gerais do periodo ─────────────────────────────────
$sql_kpi = "
    SELECT
        COUNT(*)                                    AS total_acessos,
        COUNT(DISTINCT r.placa)                     AS placas_unicas,
        SUM(CASE WHEN r.tipo='Morador'    THEN 1 ELSE 0 END) AS moradores,
        SUM(CASE WHEN r.tipo='Visitante'  THEN 1 ELSE 0 END) AS visitantes,
        SUM(CASE WHEN r.tipo='Prestador'  THEN 1 ELSE 0 END) AS prestadores,
        SUM(r.liberado)                             AS liberados
    FROM registros_acesso r
    WHERE {$where_sql}
";
$stmt2 = $conn->prepare($sql_kpi);
$stmt2->bind_param($types, ...$params);
$stmt2->execute();
$kpi = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

// ── 7. Distribuicao por tipo (para grafico de barras) ─────────
$max_acessos = !empty($ranking) ? (int)$ranking[0]['total_acessos'] : 1;

// ── 8. Resumo dos filtros ─────────────────────────────────────
$filtros_txt = ["Periodo: {$data_inicio_fmt} a {$data_fim_fmt} ({$dias} dias)"];
if ($tipo_filtro)  $filtros_txt[] = "Tipo: {$tipo_filtro}";
if ($placa_filtro) $filtros_txt[] = "Placa: {$placa_filtro}";
$filtros_txt[] = "Top {$top} veiculos";

// Data/hora de geracao
$data_geracao  = date('d/m/Y \a\s H:i');
$operador_nome = $usuario ? ($usuario['nome'] ?? 'Sistema') : 'Sistema';

// ── 9. Funcoes auxiliares ─────────────────────────────────────
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function pct($v, $total) { return $total > 0 ? round(($v / $total) * 100) : 0; }

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Veiculos com Mais Acessos — <?= esc($nome_empresa) ?></title>
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
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;
}
.titulo-relatorio .filtro-info {
    font-size: 10px; font-weight: 400; opacity: .8;
    text-transform: none; letter-spacing: 0;
}

/* ── KPIs ── */
.kpis {
    display: grid; grid-template-columns: repeat(6, 1fr);
    gap: 0; border-bottom: 2px solid #e2e8f0;
}
.kpi { padding: 14px 12px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 22px; font-weight: 800; color: #1e3a8a; line-height: 1; }
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

/* ── Tabela de ranking ── */
table { width: 100%; border-collapse: collapse; font-size: 10px; }
thead tr { background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff; }
thead th {
    padding: 9px 8px; text-align: left; font-weight: 700;
    font-size: 9px; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap;
}
thead th.num { text-align: center; }
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody tr:hover { background: #eff6ff; }
tbody td { padding: 8px 8px; vertical-align: middle; }
tbody td.num { text-align: center; }

/* ── Posicao / medalha ── */
.pos { font-size: 14px; font-weight: 800; }
.pos-1 { color: #d97706; }
.pos-2 { color: #64748b; }
.pos-3 { color: #92400e; }
.pos-n { color: #1e3a8a; }

/* ── Barra de progresso ── */
.barra-wrap { display: flex; align-items: center; gap: 8px; }
.barra-bg { flex: 1; background: #e2e8f0; border-radius: 20px; height: 10px; overflow: hidden; }
.barra-fill { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #1e3a8a, #2563eb); transition: width .3s; }
.barra-val { font-size: 11px; font-weight: 800; color: #1e3a8a; min-width: 28px; text-align: right; }

/* ── Badges ── */
.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.badge-morador   { background: #dbeafe; color: #1d4ed8; }
.badge-visitante { background: #fef3c7; color: #92400e; }
.badge-prestador { background: #f3e8ff; color: #6b21a8; }
.plate-badge {
    background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1;
    padding: 2px 6px; border-radius: 4px; font-weight: 700;
    font-family: monospace; font-size: 11px; letter-spacing: 1px;
}
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
        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-info">
            <h1><?= esc($nome_empresa) ?></h1>
            <p>CNPJ: <?= esc($cnpj_empresa) ?></p>
            <p>Sistema ERP Condominio — Modulo de Relatorios</p>
        </div>
        <div class="header-meta">
            <strong>Veiculos com Mais Acessos</strong>
            Gerado em: <?= $data_geracao ?><br>
            Operador: <?= esc($operador_nome) ?><br>
            Periodo: <?= $data_inicio_fmt ?> a <?= $data_fim_fmt ?>
        </div>
    </div>

    <!-- TITULO -->
    <div class="titulo-relatorio">
        <span>&#128663; Ranking de Veiculos — Ultimos <?= $dias ?> Dias</span>
        <span class="filtro-info"><?= esc(implode(' | ', $filtros_txt)) ?></span>
    </div>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-valor"><?= number_format((int)($kpi['total_acessos'] ?? 0)) ?></div>
            <div class="kpi-label">Total Acessos</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= (int)($kpi['placas_unicas'] ?? 0) ?></div>
            <div class="kpi-label">Placas Unicas</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= (int)($kpi['moradores'] ?? 0) ?></div>
            <div class="kpi-label">Moradores</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= (int)($kpi['visitantes'] ?? 0) ?></div>
            <div class="kpi-label">Visitantes</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= (int)($kpi['prestadores'] ?? 0) ?></div>
            <div class="kpi-label">Prestadores</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= (int)($kpi['liberados'] ?? 0) ?></div>
            <div class="kpi-label">Liberados</div>
        </div>
    </div>

    <!-- RANKING -->
    <div class="secao">
        <div class="secao-titulo">Ranking — Top <?= $top ?> Veiculos com Mais Acessos</div>
        <table>
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Placa</th>
                    <th>Modelo / Cor</th>
                    <th>Tipo</th>
                    <th>Nome / Responsavel</th>
                    <th>Unidade</th>
                    <th>Acessos (barra)</th>
                    <th class="num">Total</th>
                    <th class="num">Liberados</th>
                    <th>Ultimo Acesso</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($ranking)): ?>
                <tr><td colspan="10" class="sem-dados">Nenhum registro encontrado no periodo</td></tr>
            <?php else: ?>
                <?php foreach ($ranking as $i => $v):
                    $pos   = $i + 1;
                    $pct   = $max_acessos > 0 ? round(($v['total_acessos'] / $max_acessos) * 100) : 0;
                    $tipo  = $v['tipo'] ?? 'Morador';
                    $badge = strtolower($tipo);
                    if ($pos === 1)      $medalha = '&#127947;';
                    elseif ($pos === 2)  $medalha = '&#129352;';
                    elseif ($pos === 3)  $medalha = '&#129353;';
                    else                $medalha = '';
                    $pos_class = $pos <= 3 ? "pos-{$pos}" : 'pos-n';
                ?>
                <tr>
                    <td class="num">
                        <span class="pos <?= $pos_class ?>"><?= $medalha ?: $pos ?></span>
                    </td>
                    <td><span class="plate-badge"><?= esc($v['placa'] ?? '—') ?></span></td>
                    <td>
                        <strong><?= esc($v['modelo'] ?? '—') ?></strong>
                        <?php if (!empty($v['cor'])): ?>
                        <br><span style="color:#64748b;font-size:9px;"><?= esc($v['cor']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $badge ?>"><?= esc($tipo) ?></span></td>
                    <td><?= esc($v['nome_responsavel'] ?? '—') ?></td>
                    <td><?= esc($v['unidade'] ?? '—') ?></td>
                    <td>
                        <div class="barra-wrap">
                            <div class="barra-bg">
                                <div class="barra-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="barra-val"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td class="num"><strong style="color:#1e3a8a;font-size:13px;"><?= (int)$v['total_acessos'] ?></strong></td>
                    <td class="num"><?= (int)$v['acessos_liberados'] ?></td>
                    <td style="white-space:nowrap;font-size:9px;"><?= esc($v['ultimo_acesso'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;font-size:9px;color:#64748b;text-align:right;">
            Exibindo <?= count($ranking) ?> veiculos | Periodo: <?= $data_inicio_fmt ?> a <?= $data_fim_fmt ?>
        </p>
    </div>

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
