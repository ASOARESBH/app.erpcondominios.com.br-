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
$unidade_exata = ($_GET['unidade_exata'] ?? '') === '1';
$nome = trim((string)($_GET['nome'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$apenas_liberados = ($_GET['apenas_liberados'] ?? '') === '1';
$limite = intval($_GET['limite'] ?? 5000);
$limite = min(max($limite, 100), 5000);

if ($data_inicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) $data_inicio = '';
if ($data_fim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) $data_fim = '';
if ($hora_inicio !== '' && !preg_match('/^\d{2}:\d{2}$/', $hora_inicio)) $hora_inicio = '';
if ($hora_fim !== '' && !preg_match('/^\d{2}:\d{2}$/', $hora_fim)) $hora_fim = '';
if ($data_inicio === '') $data_inicio = date('Y-m-d');
if ($data_fim === '') $data_fim = date('Y-m-d');

$tipos = array_values(array_intersect(
    ['Morador', 'Visitante', 'Prestador'],
    array_filter(array_map('trim', explode(',', $tipo)))
));
$grupo_expr = 'COALESCE(r.registro_titular_id, r.id)';
$where = ['r.tenant_id = ?', "(r.papel_veiculo = 'OCUPANTE' OR r.papel_veiculo = 'TITULAR' OR r.papel_veiculo IS NULL)"];
$params = [(int)$tenant_id];
$types = 'i';
if ($data_inicio !== '') { $where[] = 'r.data_hora >= ?'; $params[] = $data_inicio . ' 00:00:00'; $types .= 's'; }
if ($data_fim !== '') {
    $data_fim_exclusiva = date('Y-m-d', strtotime($data_fim . ' +1 day')) . ' 00:00:00';
    $where[] = 'r.data_hora < ?'; $params[] = $data_fim_exclusiva; $types .= 's';
}
if ($hora_inicio !== '') { $where[] = 'TIME(r.data_hora) >= ?'; $params[] = $hora_inicio . ':00'; $types .= 's'; }
if ($hora_fim !== '') { $where[] = 'TIME(r.data_hora) <= ?'; $params[] = $hora_fim . ':59'; $types .= 's'; }
if ($placa !== '') { $where[] = 'r.placa LIKE ?'; $params[] = '%' . $placa . '%'; $types .= 's'; }
if ($modelo !== '') { $where[] = 'r.modelo LIKE ?'; $params[] = '%' . $modelo . '%'; $types .= 's'; }
if ($unidade !== '') {
    $operador_unidade = $unidade_exata ? '=' : 'LIKE';
    $valor_unidade = $unidade_exata ? $unidade : '%' . $unidade . '%';
    $where[] = "COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), '')) {$operador_unidade} ?";
    $params[] = $valor_unidade; $types .= 's';
}
if ($nome !== '') {
    $where[] = '(rt.nome_visitante LIKE ? OR rt.documento_visitante LIKE ?
        OR vt.nome_completo LIKE ? OR vt.documento LIKE ?
        OR mt.nome LIKE ? OR mt.cpf LIKE ?
        OR EXISTS (
            SELECT 1 FROM registros_acesso filtro_pessoa
            LEFT JOIN visitantes filtro_vo ON filtro_vo.id = filtro_pessoa.visitante_id
                AND filtro_vo.tenant_id = filtro_pessoa.tenant_id
            WHERE filtro_pessoa.tenant_id = r.tenant_id
              AND (filtro_pessoa.id = ' . $grupo_expr . '
                   OR filtro_pessoa.registro_titular_id = ' . $grupo_expr . ')
              AND (filtro_pessoa.nome_visitante LIKE ? OR filtro_pessoa.documento_visitante LIKE ?
                   OR filtro_vo.nome_completo LIKE ? OR filtro_vo.documento LIKE ?)
        ))';
    $buscaNome = '%' . $nome . '%';
    for ($i = 0; $i < 10; $i++) $params[] = $buscaNome;
    $types .= 'ssssssssss';
}
if ($tipo !== '' && !$tipos) {
    $where[] = '1 = 0';
} elseif ($tipos) {
    $placeholders = implode(', ', array_fill(0, count($tipos), '?'));
    $where[] = "EXISTS (
        SELECT 1 FROM registros_acesso filtro_tipo
        WHERE filtro_tipo.tenant_id = r.tenant_id
          AND (filtro_tipo.id = $grupo_expr OR filtro_tipo.registro_titular_id = $grupo_expr)
          AND filtro_tipo.tipo IN ($placeholders)
    )";
    foreach ($tipos as $tipo_filtro) { $params[] = $tipo_filtro; $types .= 's'; }
}
if ($apenas_liberados) {
    $where[] = "EXISTS (
        SELECT 1 FROM registros_acesso filtro_liberado
        WHERE filtro_liberado.tenant_id = r.tenant_id
          AND (filtro_liberado.id = $grupo_expr OR filtro_liberado.registro_titular_id = $grupo_expr)
          AND filtro_liberado.liberado = 1
    )";
}

$sql = "SELECT DATE_FORMAT(r.data_hora, '%d/%m/%Y') data_fmt,
               DATE_FORMAT(r.data_hora, '%H:%i:%s') hora_fmt,
               r.placa, r.modelo, r.tipo_acesso, r.status, r.liberado, r.observacao,
               $grupo_expr registro_titular_id,
               COALESCE(r.papel_veiculo, 'TITULAR') linha_papel,
               COALESCE(NULLIF(TRIM(mt.nome), ''), 'Não identificado') destino_morador_nome,
               COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), ''), 'Não informado') destino_unidade,
               COALESCE(NULLIF(TRIM(rt.nome_visitante), ''), NULLIF(TRIM(vt.nome_completo), ''), NULLIF(TRIM(mt.nome), ''), 'Não identificado') condutor_nome,
               COALESCE(NULLIF(TRIM(vt.documento), ''), NULLIF(TRIM(rt.documento_visitante), ''), NULLIF(TRIM(mt.cpf), ''), 'Não informado') condutor_cpf,
               COALESCE(NULLIF(TRIM(rt.tipo), ''), 'Não informado') condutor_tipo,
               CASE WHEN r.papel_veiculo = 'OCUPANTE'
                    THEN COALESCE(NULLIF(TRIM(vo.documento), ''), NULLIF(TRIM(r.documento_visitante), ''), 'Não informado')
                    ELSE 'Não informado'
               END ocupante_cpf,
               COALESCE(NULLIF(TRIM(vt.documento), ''), NULLIF(TRIM(rt.documento_visitante), ''), NULLIF(TRIM(mt.cpf), ''), 'Não informado') titular_cpf,
               COALESCE(NULLIF(TRIM(rt.nome_visitante), ''), NULLIF(TRIM(vt.nome_completo), ''), NULLIF(TRIM(mt.nome), ''), 'Não identificado') titular_nome,
               COALESCE(NULLIF(TRIM(rt.tipo), ''), 'Não informado') titular_tipo,
               CASE WHEN r.papel_veiculo = 'OCUPANTE' THEN COALESCE(NULLIF(TRIM(r.nome_visitante), ''), NULLIF(TRIM(vo.nome_completo), ''), 'Não identificado') END ocupante_nome,
               CASE WHEN r.papel_veiculo = 'OCUPANTE' THEN COALESCE(NULLIF(TRIM(r.tipo), ''), 'Não informado') END ocupante_tipo,
               COALESCE(NULLIF(TRIM(mt.unidade), ''), NULLIF(TRIM(rt.unidade_destino), ''), NULLIF(TRIM(r.unidade_destino), ''), 'Não informado') unidade
        FROM registros_acesso r
        LEFT JOIN registros_acesso rt ON rt.id = $grupo_expr
            AND rt.tenant_id = r.tenant_id AND (rt.papel_veiculo = 'TITULAR' OR rt.papel_veiculo IS NULL)
        LEFT JOIN moradores mt ON mt.id = rt.morador_id AND mt.tenant_id = r.tenant_id
        LEFT JOIN visitantes vo ON vo.id = r.visitante_id AND vo.tenant_id = r.tenant_id
        LEFT JOIN visitantes vt ON vt.id = rt.visitante_id AND vt.tenant_id = rt.tenant_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $grupo_expr DESC, r.papel_veiculo DESC, r.id ASC LIMIT ?";
$params[] = $limite;
$types .= 'i';
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
$ocupantes_total = count(array_filter($registros, static fn($registro) => ($registro['linha_papel'] ?? '') === 'OCUPANTE'));
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
<div class="title">Acessos com condutor e ocupantes</div><div class="kpis"><div class="kpi"><strong><?= $ocupantes_total ?></strong><span>Ocupantes secundários</span></div><div class="kpi"><strong><?= count($titulares) ?></strong><span>Grupos/veículos</span></div><div class="kpi"><strong><?= $visitantes ?></strong><span>Ocupantes visitantes</span></div><div class="kpi"><strong><?= $prestadores ?></strong><span>Ocupantes prestadores</span></div></div>
<section class="section"><h2><?= $total ?> linha(s) de rastreamento encontrada(s)</h2><table><thead><tr><th>Data</th><th>Hora</th><th>Placa</th><th>Modelo</th><th>Morador destino</th><th>Unidade</th><th>Pessoa</th><th>CPF/documento</th><th>Relação</th><th>Tipo</th><th>Entrada/Saída</th><th>Status</th><th>Observação</th></tr></thead><tbody>
<?php if (!$registros): ?><tr><td colspan="13" class="empty">Nenhum acesso encontrado com os filtros aplicados.</td></tr><?php else: foreach ($registros as $r): $ehOcupante = ($r['linha_papel'] ?? '') === 'OCUPANTE'; ?><tr class="<?= $ehOcupante ? 'ocupante' : 'condutor' ?>"><td><?= ocupante_pdf_esc($r['data_fmt']) ?></td><td><?= ocupante_pdf_esc($r['hora_fmt']) ?></td><td><?= ocupante_pdf_esc($r['placa'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['modelo'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['destino_morador_nome'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['destino_unidade'] ?: $r['unidade']) ?></td><td><strong><?= ocupante_pdf_esc($ehOcupante ? $r['ocupante_nome'] : $r['condutor_nome']) ?></strong></td><td><?= ocupante_pdf_esc($ehOcupante ? $r['ocupante_cpf'] : $r['condutor_cpf']) ?></td><td><?= ocupante_pdf_esc($ehOcupante ? 'Ocupante secundário' : 'Condutor principal') ?></td><td><?= ocupante_pdf_esc($ehOcupante ? $r['ocupante_tipo'] : $r['condutor_tipo']) ?></td><td><?= ocupante_pdf_esc($r['tipo_acesso'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['status'] ?: '-') ?></td><td><?= ocupante_pdf_esc($r['observacao'] ?: '-') ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
<footer class="footer"><span><?= ocupante_pdf_esc($nome_empresa) ?> — ERP Condomínio</span><span>Relatório gerado em <?= date('d/m/Y H:i') ?></span></footer></div></body></html>
