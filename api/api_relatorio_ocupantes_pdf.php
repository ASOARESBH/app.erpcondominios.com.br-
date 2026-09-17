<?php
/** Relatório de ocupantes: impressão/PDF, sempre limitado ao tenant da sessão. */
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';

$conn = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');
$tenant_id = exigirTenantId();
date_default_timezone_set('America/Sao_Paulo');

function ocupante_pdf_esc($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
function ocupante_pdf_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [&$types];
    foreach ($params as &$param) $refs[] = &$param;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$data_inicio = trim((string)($_GET['data_inicio'] ?? ''));
$data_fim = trim((string)($_GET['data_fim'] ?? ''));
$hora_inicio = trim((string)($_GET['hora_inicio'] ?? ''));
$hora_fim = trim((string)($_GET['hora_fim'] ?? ''));
$placa = strtoupper(trim((string)($_GET['placa'] ?? '')));
$modelo = trim((string)($_GET['modelo'] ?? ''));
$unidade = trim((string)($_GET['unidade'] ?? ''));
$nome = trim((string)($_GET['nome'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$apenas_liberados = ($_GET['apenas_liberados'] ?? '') === '1';

if ($data_inicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) $data_inicio = '';
if ($data_fim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) $data_fim = '';
if ($hora_inicio !== '' && !preg_match('/^\d{2}:\d{2}$/', $hora_inicio)) $hora_inicio = '';
if ($hora_fim !== '' && !preg_match('/^\d{2}:\d{2}$/', $hora_fim)) $hora_fim = '';
if (!in_array($tipo, ['', 'Visitante', 'Prestador'], true)) $tipo = '';
if ($data_inicio === '') $data_inicio = date('Y-m-d', strtotime('-30 days'));
if ($data_fim === '') $data_fim = date('Y-m-d');

$where = ['r.tenant_id = ?', "r.papel_veiculo = 'OCUPANTE'"];
$params = [(int)$tenant_id];
$types = 'i';
if ($data_inicio !== '') { $where[] = 'DATE(r.data_hora) >= ?'; $params[] = $data_inicio; $types .= 's'; }
if ($data_fim !== '') { $where[] = 'DATE(r.data_hora) <= ?'; $params[] = $data_fim; $types .= 's'; }
if ($hora_inicio !== '') { $where[] = 'TIME(r.data_hora) >= ?'; $params[] = $hora_inicio . ':00'; $types .= 's'; }
if ($hora_fim !== '') { $where[] = 'TIME(r.data_hora) <= ?'; $params[] = $hora_fim . ':59'; $types .= 's'; }
if ($placa !== '') { $where[] = 'r.placa LIKE ?'; $params[] = '%' . $placa . '%'; $types .= 's'; }
if ($modelo !== '') { $where[] = 'r.modelo LIKE ?'; $params[] = '%' . $modelo . '%'; $types .= 's'; }
if ($unidade !== '') {
    $where[] = '(r.unidade_destino LIKE ? OR rt.unidade_destino LIKE ? OR mt.unidade LIKE ?)';
    $params[] = '%' . $unidade . '%'; $params[] = '%' . $unidade . '%'; $params[] = '%' . $unidade . '%'; $types .= 'sss';
}
if ($nome !== '') {
    $where[] = '(r.nome_visitante LIKE ? OR rt.nome_visitante LIKE ? OR mt.nome LIKE ?)';
    $params[] = '%' . $nome . '%'; $params[] = '%' . $nome . '%'; $params[] = '%' . $nome . '%'; $types .= 'sss';
}
if ($tipo !== '') { $where[] = 'r.tipo = ?'; $params[] = $tipo; $types .= 's'; }
if ($apenas_liberados) $where[] = 'r.liberado = 1';

$sql = "SELECT DATE_FORMAT(r.data_hora, '%d/%m/%Y') data_fmt,
               DATE_FORMAT(r.data_hora, '%H:%i:%s') hora_fmt,
               r.placa, r.modelo, r.tipo_acesso, r.status, r.liberado, r.observacao,
               COALESCE(NULLIF(TRIM(rt.nome_visitante), ''), NULLIF(TRIM(mt.nome), ''), 'Não identificado') titular_nome,
               COALESCE(NULLIF(TRIM(rt.tipo), ''), 'Não informado') titular_tipo,
               COALESCE(NULLIF(TRIM(r.nome_visitante), ''), 'Não identificado') ocupante_nome,
               COALESCE(NULLIF(TRIM(r.tipo), ''), 'Não informado') ocupante_tipo,
               COALESCE(NULLIF(TRIM(r.unidade_destino), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(mt.unidade), ''), 'Não informado') unidade
        FROM registros_acesso r
        LEFT JOIN registros_acesso rt ON rt.id = r.registro_titular_id
            AND rt.tenant_id = r.tenant_id AND rt.papel_veiculo = 'TITULAR'
        LEFT JOIN moradores mt ON mt.id = rt.morador_id AND mt.tenant_id = r.tenant_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.data_hora DESC, r.id DESC LIMIT 10000";
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit('Não foi possível preparar o relatório de ocupantes.'); }
ocupante_pdf_bind($stmt, $types, $params);
$stmt->execute();
$resultado = $stmt->get_result();
$registros = [];
while ($row = $resultado->fetch_assoc()) $registros[] = $row;
$stmt->close();

$empresa = [];
$empresaStmt = $conn->prepare('SELECT razao_social, nome_fantasia, cnpj FROM tenants WHERE id = ? LIMIT 1');
if ($empresaStmt) {
    $empresaStmt->bind_param('i', $tenant_id);
    $empresaStmt->execute();
    $empresa = $empresaStmt->get_result()->fetch_assoc() ?: [];
    $empresaStmt->close();
}
$nome_empresa = $empresa['nome_fantasia'] ?? ($empresa['razao_social'] ?? 'ERP Condomínio');
$cnpj = $empresa['cnpj'] ?? '';
$operador = $usuario['nome'] ?? 'Sistema';
$total = count($registros);
$titulares = [];
$visitantes = 0;
$prestadores = 0;
foreach ($registros as $registro) {
    $titulares[($registro['titular_nome'] ?? '') . '|' . ($registro['unidade'] ?? '') . '|' . ($registro['placa'] ?? '')] = true;
    if (($registro['ocupante_tipo'] ?? '') === 'Visitante') $visitantes++;
    if (($registro['ocupante_tipo'] ?? '') === 'Prestador') $prestadores++;
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relatório de Ocupantes — <?= ocupante_pdf_esc($nome_empresa) ?></title>
<style>
*{box-sizing:border-box}body{font:11px Arial,sans-serif;margin:0;background:#f1f5f9;color:#172033}.report{max-width:1260px;margin:20px auto;background:#fff;box-shadow:0 8px 30px #1e3a8a1f}.header{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;padding:24px 30px;display:flex;justify-content:space-between;gap:20px}.header h1{font-size:18px;margin:0 0 5px}.header p{margin:3px 0;opacity:.85}.meta{text-align:right;line-height:1.6}.title{background:#172f73;color:#fff;padding:10px 30px;font-weight:bold;font-size:13px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:2px solid #e2e8f0}.kpi{text-align:center;padding:14px;border-right:1px solid #e2e8f0}.kpi strong{display:block;color:#1e3a8a;font-size:22px}.kpi span{color:#64748b;font-size:9px;text-transform:uppercase}.section{padding:0 24px 24px}.section h2{color:#1e3a8a;font-size:12px;text-transform:uppercase;border-bottom:2px solid #2563eb;padding:16px 0 9px}table{width:100%;border-collapse:collapse;font-size:9px}th{background:#1e3a8a;color:#fff;padding:8px 5px;text-align:left;text-transform:uppercase;font-size:8px}td{padding:7px 5px;border-bottom:1px solid #e2e8f0}tr:nth-child(even){background:#f8fafc}.empty{text-align:center;padding:24px;color:#64748b}.footer{background:#172f73;color:#fff;padding:12px 30px;font-size:9px;display:flex;justify-content:space-between}.print{position:fixed;right:16px;top:16px;background:#2563eb;color:#fff;border:0;padding:10px 18px;border-radius:7px;cursor:pointer}@media print{body{background:#fff}.report{margin:0;box-shadow:none}.print{display:none}@page{size:A4 landscape;margin:8mm}thead{display:table-header-group}tr{page-break-inside:avoid}}
</style></head><body><button class="print" onclick="window.print()">Imprimir / Salvar PDF</button>
<div class="report"><header class="header"><div><h1><?= ocupante_pdf_esc($nome_empresa) ?></h1><p>CNPJ: <?= ocupante_pdf_esc($cnpj) ?></p><p>Relatório detalhado de ocupantes de veículos</p></div><div class="meta"><strong>Gerado em <?= date('d/m/Y H:i') ?></strong><br>Operador: <?= ocupante_pdf_esc($operador) ?><br>Período: <?= date('d/m/Y', strtotime($data_inicio)) ?> a <?= date('d/m/Y', strtotime($data_fim)) ?></div></header>
<div class="title">Ocupantes lançados nos veículos</div><div class="kpis"><div class="kpi"><strong><?= $total ?></strong><span>Total de ocupantes</span></div><div class="kpi"><strong><?= count($titulares) ?></strong><span>Titulares relacionados</span></div><div class="kpi"><strong><?= $visitantes ?></strong><span>Ocupantes visitantes</span></div><div class="kpi"><strong><?= $prestadores ?></strong><span>Ocupantes prestadores</span></div></div>
<section class="section"><h2><?= $total ?> ocupante(s) encontrado(s)</h2><table><thead><tr><th>Data</th><th>Hora</th><th>Placa</th><th>Modelo</th><th>Unidade</th><th>Titular</th><th>Classificação titular</th><th>Ocupante</th><th>Classificação ocupante</th><th>Entrada/Saída</th><th>Status</th><th>Observação</th></tr></thead><tbody>
<?php if (!$registros): ?><tr><td colspan="12" class="empty">Nenhum ocupante encontrado com os filtros aplicados.</td></tr><?php else: foreach ($registros as $r): ?><tr><td><?= ocupante_pdf_esc($r['data_fmt']) ?></td><td><?= ocupante_pdf_esc($r['hora_fmt']) ?></td><td><?= ocupante_pdf_esc($r['placa'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['modelo'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['unidade']) ?></td><td><strong><?= ocupante_pdf_esc($r['titular_nome']) ?></strong></td><td><?= ocupante_pdf_esc($r['titular_tipo']) ?></td><td><strong><?= ocupante_pdf_esc($r['ocupante_nome']) ?></strong></td><td><?= ocupante_pdf_esc($r['ocupante_tipo']) ?></td><td><?= ocupante_pdf_esc($r['tipo_acesso'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['status'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['observacao'] ?: '-') ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
<footer class="footer"><span><?= ocupante_pdf_esc($nome_empresa) ?> — ERP Condomínio</span><span>Relatório gerado em <?= date('d/m/Y H:i') ?></span></footer></div></body></html>
