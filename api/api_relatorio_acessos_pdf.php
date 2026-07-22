<?php
/**
 * ============================================================
 * RELATORIO DE ACESSOS — GERADOR DE PDF/IMPRESSAO
 * ============================================================
 * Template padrao do sistema ERP Condominio.
 * Identidade visual: azul #1e3a8a / #2563eb + logo da associacao.
 *
 * Filtros aceitos via GET:
 *   data_inicio  — Data inicial YYYY-MM-DD
 *   data_fim     — Data final YYYY-MM-DD
 *   hora_inicio  — Hora inicial HH:MM
 *   hora_fim     — Hora final HH:MM
 *   placa        — Filtro por placa (parcial)
 *   modelo       — Filtro por modelo (parcial)
 *   unidade      — Filtro por unidade (parcial)
 *   nome         — Filtro por nome (parcial)
 *   tipo         — Morador | Visitante | Prestador | "" (todos)
 *   print        — Se "true", dispara window.print() automaticamente
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
$res_emp = $conn->query("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa LIMIT 1");
if ($res_emp && $res_emp->num_rows > 0) {
    $empresa = $res_emp->fetch_assoc();
}
$nome_empresa = !empty($empresa['nome_fantasia'])  ? $empresa['nome_fantasia']
              : (!empty($empresa['razao_social'])  ? $empresa['razao_social']
              : 'ASSOCIACAO ERP CONDOMÍNIO');
$cnpj_empresa = !empty($empresa['cnpj']) ? $empresa['cnpj'] : '28.231.106/0001-15';

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'app.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
           : $protocolo . '://' . $host . '/assets/images/logo.jpeg';

// ── 4. Filtros ────────────────────────────────────────────────
$data_inicio  = trim($_GET['data_inicio']  ?? '');
$data_fim     = trim($_GET['data_fim']     ?? '');
$hora_inicio  = trim($_GET['hora_inicio']  ?? '');
$hora_fim     = trim($_GET['hora_fim']     ?? '');
$filtro_placa = strtoupper(trim($_GET['placa']   ?? ''));
$filtro_modelo= trim($_GET['modelo']  ?? '');
$filtro_unid  = trim($_GET['unidade'] ?? '');
$filtro_nome  = trim($_GET['nome']    ?? '');
$filtro_tipo  = trim($_GET['tipo']    ?? '');
$auto_print   = ($_GET['print'] ?? '') === 'true';

// Datas padrao: ultimos 30 dias
if ($data_inicio === '') $data_inicio = date('Y-m-d', strtotime('-30 days'));
if ($data_fim    === '') $data_fim    = date('Y-m-d');

$data_inicio_fmt = date('d/m/Y', strtotime($data_inicio));
$data_fim_fmt    = date('d/m/Y', strtotime($data_fim));

// ── 5. Query principal ────────────────────────────────────────
$where  = ["DATE(r.data_hora) >= ?", "DATE(r.data_hora) <= ?"];
$params = [$data_inicio, $data_fim];
$types  = 'ss';

if ($hora_inicio !== '') {
    $where[]  = "TIME(r.data_hora) >= ?";
    $params[] = $hora_inicio . ':00';
    $types   .= 's';
}
if ($hora_fim !== '') {
    $where[]  = "TIME(r.data_hora) <= ?";
    $params[] = $hora_fim . ':59';
    $types   .= 's';
}
if ($filtro_placa !== '') {
    $where[]  = "r.placa LIKE ?";
    $params[] = '%' . $filtro_placa . '%';
    $types   .= 's';
}
if ($filtro_modelo !== '') {
    $where[]  = "r.modelo LIKE ?";
    $params[] = '%' . $filtro_modelo . '%';
    $types   .= 's';
}
if ($filtro_unid !== '') {
    $where[]  = "(r.unidade_destino LIKE ? OR m.unidade LIKE ?)";
    $params[] = '%' . $filtro_unid . '%';
    $params[] = '%' . $filtro_unid . '%';
    $types   .= 'ss';
}
if ($filtro_nome !== '') {
    $where[]  = "(r.nome_visitante LIKE ? OR m.nome LIKE ?)";
    $params[] = '%' . $filtro_nome . '%';
    $params[] = '%' . $filtro_nome . '%';
    $types   .= 'ss';
}
if ($filtro_tipo !== '' && in_array($filtro_tipo, ['Morador','Visitante','Prestador'])) {
    $where[]  = "r.tipo = ?";
    $params[] = $filtro_tipo;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

$sql = "
    SELECT
        DATE_FORMAT(r.data_hora, '%d/%m/%Y') AS data_fmt,
        DATE_FORMAT(r.data_hora, '%H:%i')    AS hora_fmt,
        r.placa, r.modelo, r.cor, r.tag, r.tipo,
        COALESCE(m.nome, r.nome_visitante, '—') AS nome,
        COALESCE(m.unidade, r.unidade_destino, '—') AS unidade,
        r.dias_permanencia, r.status, r.liberado, r.observacao
    FROM registros_acesso r
    LEFT JOIN moradores m ON r.morador_id = m.id
    WHERE {$where_sql}
    ORDER BY r.data_hora DESC
    LIMIT 2000
";

$registros = [];
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $registros[] = $row;
}
$stmt->close();

// ── 6. KPIs ───────────────────────────────────────────────────
$total      = count($registros);
$moradores  = count(array_filter($registros, fn($r) => $r['tipo'] === 'Morador'));
$visitantes = count(array_filter($registros, fn($r) => $r['tipo'] === 'Visitante'));
$prestadores= count(array_filter($registros, fn($r) => $r['tipo'] === 'Prestador'));
$liberados  = count(array_filter($registros, fn($r) => $r['liberado'] == 1));

// ── 7. Resumo dos filtros ─────────────────────────────────────
$filtros_txt = ["Periodo: {$data_inicio_fmt} a {$data_fim_fmt}"];
if ($hora_inicio || $hora_fim)  $filtros_txt[] = "Hora: " . ($hora_inicio ?: '00:00') . " - " . ($hora_fim ?: '23:59');
if ($filtro_placa)  $filtros_txt[] = "Placa: {$filtro_placa}";
if ($filtro_modelo) $filtros_txt[] = "Modelo: {$filtro_modelo}";
if ($filtro_unid)   $filtros_txt[] = "Unidade: {$filtro_unid}";
if ($filtro_nome)   $filtros_txt[] = "Nome: {$filtro_nome}";
if ($filtro_tipo)   $filtros_txt[] = "Tipo: {$filtro_tipo}";

$data_geracao  = date('d/m/Y \a\s H:i');
$operador_nome = $usuario ? ($usuario['nome'] ?? 'Sistema') : 'Sistema';

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatorio de Acessos — <?= esc($nome_empresa) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a2e; background: #f0f4f8; }

.btn-print {
    position: fixed; top: 16px; right: 16px; z-index: 9999;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #fff; border: none; border-radius: 8px;
    padding: 10px 22px; font-size: 13px; font-weight: 600;
    cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,.4);
    display: flex; align-items: center; gap: 8px;
}
.btn-print:hover { transform: translateY(-1px); }

.relatorio {
    max-width: 1100px; margin: 20px auto; background: #fff;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 32px rgba(30,58,138,.12);
}

.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
    padding: 24px 32px; display: flex; align-items: center; gap: 20px; color: #fff;
}
.header-logo { width: 72px; height: 72px; border-radius: 10px; object-fit: contain; background: #fff; padding: 4px; flex-shrink: 0; }
.header-info { flex: 1; }
.header-info h1 { font-size: 18px; font-weight: 700; }
.header-info p  { font-size: 11px; opacity: .85; margin-top: 2px; }
.header-meta { text-align: right; font-size: 10px; opacity: .8; line-height: 1.7; }
.header-meta strong { font-size: 13px; opacity: 1; display: block; margin-bottom: 2px; }

.titulo-relatorio {
    background: #1e3a8a; color: #fff;
    padding: 10px 32px; font-size: 13px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;
}
.titulo-relatorio .filtro-info { font-size: 10px; font-weight: 400; opacity: .8; text-transform: none; letter-spacing: 0; }

.kpis { display: grid; grid-template-columns: repeat(5, 1fr); border-bottom: 2px solid #e2e8f0; }
.kpi { padding: 14px 12px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 22px; font-weight: 800; color: #1e3a8a; line-height: 1; }
.kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin-top: 4px; }

.secao { padding: 0 24px 24px; }
.secao-titulo {
    font-size: 12px; font-weight: 700; color: #1e3a8a;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 16px 0 10px; border-bottom: 2px solid #2563eb; margin-bottom: 12px;
    display: flex; align-items: center; gap: 8px;
}
.secao-titulo::before {
    content: ''; display: inline-block; width: 4px; height: 16px;
    background: linear-gradient(180deg, #2563eb, #1e3a8a); border-radius: 2px;
}

table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
thead tr { background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff; }
thead th { padding: 8px 6px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody td { padding: 6px 6px; vertical-align: middle; }

.plate-badge { background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; padding: 1px 5px; border-radius: 3px; font-weight: 700; font-family: monospace; font-size: 10px; letter-spacing: 1px; }
.badge { display: inline-block; padding: 1px 6px; border-radius: 20px; font-size: 8px; font-weight: 700; text-transform: uppercase; }
.badge-morador   { background: #dbeafe; color: #1d4ed8; }
.badge-visitante { background: #fef3c7; color: #92400e; }
.badge-prestador { background: #f3e8ff; color: #6b21a8; }
.badge-lib  { background: #dcfce7; color: #166534; }
.badge-nlib { background: #fee2e2; color: #991b1b; }

.sem-dados { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; }

.rodape {
    background: #1e3a8a; color: rgba(255,255,255,.75);
    padding: 12px 32px; font-size: 9px;
    display: flex; justify-content: space-between; align-items: center;
}
.rodape strong { color: #fff; }

@media print {
    html, body { background: #fff; }
    .btn-print { display: none !important; }
    .relatorio { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    @page { margin: 8mm 6mm; size: A4 landscape; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">&#128438; Imprimir / Salvar PDF</button>

<div class="relatorio">

    <div class="header">
        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-info">
            <h1><?= esc($nome_empresa) ?></h1>
            <p>CNPJ: <?= esc($cnpj_empresa) ?></p>
            <p>Sistema ERP Condominio — Modulo de Relatorios</p>
        </div>
        <div class="header-meta">
            <strong>Relatorio de Acessos</strong>
            Gerado em: <?= $data_geracao ?><br>
            Operador: <?= esc($operador_nome) ?><br>
            Periodo: <?= $data_inicio_fmt ?> a <?= $data_fim_fmt ?>
        </div>
    </div>

    <div class="titulo-relatorio">
        <span>&#128663; Registro de Acessos</span>
        <span class="filtro-info"><?= esc(implode(' | ', $filtros_txt)) ?></span>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="kpi-valor"><?= $total ?></div><div class="kpi-label">Total Registros</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $moradores ?></div><div class="kpi-label">Moradores</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $visitantes ?></div><div class="kpi-label">Visitantes</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $prestadores ?></div><div class="kpi-label">Prestadores</div></div>
        <div class="kpi"><div class="kpi-valor"><?= $liberados ?></div><div class="kpi-label">Liberados</div></div>
    </div>

    <div class="secao">
        <div class="secao-titulo">Registros de Acesso — <?= $total ?> registros encontrados</div>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Hora</th>
                    <th>Placa</th>
                    <th>Modelo</th>
                    <th>Cor</th>
                    <th>TAG RFID</th>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Unidade</th>
                    <th>Dias Perm.</th>
                    <th>Liberado</th>
                    <th>Observacao</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($registros)): ?>
                <tr><td colspan="12" class="sem-dados">Nenhum registro encontrado com os filtros aplicados</td></tr>
            <?php else: ?>
                <?php foreach ($registros as $r):
                    $tipo  = $r['tipo'] ?? 'Morador';
                    $badge = strtolower($tipo);
                ?>
                <tr>
                    <td><?= esc($r['data_fmt'] ?? '—') ?></td>
                    <td><?= esc($r['hora_fmt'] ?? '—') ?></td>
                    <td><span class="plate-badge"><?= esc($r['placa'] ?? '—') ?></span></td>
                    <td><?= esc($r['modelo'] ?? '—') ?></td>
                    <td><?= esc($r['cor'] ?? '—') ?></td>
                    <td style="font-size:8px;color:#64748b;"><?= esc($r['tag'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= $badge ?>"><?= esc($tipo) ?></span></td>
                    <td><?= esc($r['nome'] ?? '—') ?></td>
                    <td><?= esc($r['unidade'] ?? '—') ?></td>
                    <td style="text-align:center;"><?= $r['dias_permanencia'] ?? '—' ?></td>
                    <td style="text-align:center;">
                        <span class="badge <?= $r['liberado'] ? 'badge-lib' : 'badge-nlib' ?>">
                            <?= $r['liberado'] ? 'Sim' : 'Nao' ?>
                        </span>
                    </td>
                    <td style="font-size:8.5px;color:#64748b;"><?= esc($r['observacao'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($total >= 2000): ?>
        <p style="margin-top:8px;font-size:9px;color:#e74c3c;text-align:right;">
            Exibindo os primeiros 2.000 registros. Refine os filtros para resultados mais especificos.
        </p>
        <?php else: ?>
        <p style="margin-top:8px;font-size:9px;color:#64748b;text-align:right;">
            Total: <?= $total ?> registros | Periodo: <?= $data_inicio_fmt ?> a <?= $data_fim_fmt ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="rodape">
        <span><strong><?= esc($nome_empresa) ?></strong> — Sistema ERP Condominio</span>
        <span>Relatorio gerado em <?= $data_geracao ?> por <?= esc($operador_nome) ?></span>
    </div>

</div>

<?php if ($auto_print): ?>
<script>window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 600); });</script>
<?php endif; ?>

</body>
</html>
