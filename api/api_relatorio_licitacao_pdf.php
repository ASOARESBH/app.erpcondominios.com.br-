<?php
/**
 * ============================================================
 * RELATORIO DE LICITACAO — RESULTADO COMPARATIVO DE ORCAMENTOS
 * ============================================================
 * Parâmetros GET:
 *   contrato_id  — ID do contrato (obrigatório)
 *   responsavel  — Nome do responsável pela assinatura
 *   cargo        — Cargo do responsável
 *
 * Gera página HTML pronta para impressão / salvar como PDF.
 * @version 1.0.0
 */
require_once 'config.php';
require_once 'auth_helper.php';

$conn    = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');

date_default_timezone_set('America/Sao_Paulo');

// ── Parâmetros ────────────────────────────────────────────────
$contrato_id = intval($_GET['contrato_id'] ?? 0);
$responsavel = trim($_GET['responsavel'] ?? '');
$cargo       = trim($_GET['cargo'] ?? 'Presidente da Associação');

if ($contrato_id <= 0) {
    die('<p style="color:red;font-family:sans-serif;padding:2rem;">Contrato inválido.</p>');
}

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
$end_empresa  = '';
if (!empty($empresa['endereco_rua'])) {
    $end_empresa = $empresa['endereco_rua'];
    if (!empty($empresa['endereco_numero'])) $end_empresa .= ', ' . $empresa['endereco_numero'];
    if (!empty($empresa['endereco_cidade'])) $end_empresa .= ' — ' . $empresa['endereco_cidade'];
    if (!empty($empresa['endereco_estado'])) $end_empresa .= '/' . $empresa['endereco_estado'];
}

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
    ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
    : $protocolo . '://' . $host . '/assets/images/logo.jpeg';

// ── Dados do contrato ─────────────────────────────────────────
$stmt_c = $conn->prepare("
    SELECT c.*, pc.codigo as plano_codigo, pc.nome as plano_nome
    FROM contratos c
    LEFT JOIN planos_contas pc ON pc.id = c.plano_conta_id
    WHERE c.id = ? AND c.ativo = 1
    LIMIT 1
");
$stmt_c->bind_param('i', $contrato_id);
$stmt_c->execute();
$contrato = $stmt_c->get_result()->fetch_assoc();
$stmt_c->close();

if (!$contrato) {
    die('<p style="color:red;font-family:sans-serif;padding:2rem;">Contrato não encontrado.</p>');
}

// ── Orçamentos (ordenados por valor ASC) ──────────────────────
$stmt_o = $conn->prepare("
    SELECT * FROM contrato_orcamentos
    WHERE contrato_id = ?
    ORDER BY valor ASC
");
$stmt_o->bind_param('i', $contrato_id);
$stmt_o->execute();
$res_o = $stmt_o->get_result();
$orcamentos = [];
while ($row = $res_o->fetch_assoc()) {
    $row['valor'] = (float)$row['valor'];
    $orcamentos[] = $row;
}
$stmt_o->close();

// ── Vencedor = menor valor ────────────────────────────────────
$vencedor = !empty($orcamentos) ? $orcamentos[0] : null;

// ── Helpers ───────────────────────────────────────────────────
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function dateBR($d) {
    if (!$d || $d === '0000-00-00') return '—';
    $p = explode('-', $d);
    return count($p) === 3 ? "{$p[2]}/{$p[1]}/{$p[0]}" : $d;
}

$data_hoje   = date('d/m/Y');
$hora_agora  = date('H:i');
$local_data  = ($end_empresa ? $empresa['endereco_cidade'] . '/' . $empresa['endereco_estado'] : 'Brumadinho/MG')
             . ', ' . $data_hoje;

$tipo_label = $contrato['tipo_servico'] === 'venda' ? 'Venda de Produto' : 'Prestação de Serviço';
$vigencia   = dateBR($contrato['data_inicio']) . ' a ' . dateBR($contrato['data_fim']);
$total_orc  = count($orcamentos);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relatório de Licitação — <?= esc($contrato['numero_contrato']) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 11px; color: #1e293b;
    background: #f1f5f9;
}
.relatorio {
    max-width: 900px; margin: 20px auto;
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
.header-info h1 { font-size: 17px; font-weight: 700; letter-spacing: .5px; }
.header-info p  { font-size: 10px; opacity: .85; margin-top: 3px; }
.header-meta { text-align: right; font-size: 10px; opacity: .8; line-height: 1.7; }
.header-meta strong { font-size: 13px; opacity: 1; display: block; margin-bottom: 2px; }
/* ── Faixa do título ── */
.titulo-faixa {
    background: #1e3a8a; color: #fff;
    padding: 10px 32px; font-size: 12px; font-weight: 700;
    letter-spacing: 1.2px; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between;
}
.titulo-faixa .sub { font-size: 10px; font-weight: 400; opacity: .75; text-transform: none; letter-spacing: 0; }
/* ── KPIs ── */
.kpis {
    display: grid; grid-template-columns: repeat(4, 1fr);
    border-bottom: 2px solid #e2e8f0;
}
.kpi { padding: 14px 20px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 22px; font-weight: 800; color: #1e3a8a; line-height: 1; }
.kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin-top: 4px; }
/* ── Seções ── */
.secao { padding: 0 32px 24px; }
.secao-titulo {
    font-size: 11px; font-weight: 700; color: #1e3a8a;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 16px 0 10px; border-bottom: 2px solid #2563eb;
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.secao-titulo::before {
    content: ''; display: inline-block; width: 4px; height: 16px;
    background: linear-gradient(180deg, #2563eb, #1e3a8a); border-radius: 2px;
}
/* ── Grid de dados ── */
.dados-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
}
.dados-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
.campo { display: flex; flex-direction: column; gap: 2px; }
.campo-label { font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; font-weight: 600; }
.campo-valor { font-size: 12px; color: #1e293b; font-weight: 500; }
.campo-valor.destaque { font-size: 14px; font-weight: 800; color: #1e3a8a; }
/* ── Card vencedora ── */
.vencedora-card {
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border: 2px solid #16a34a; border-radius: 10px;
    padding: 18px 22px; position: relative; overflow: hidden;
}
.vencedora-card::before {
    content: ''; position: absolute; top: 0; left: 0;
    width: 6px; height: 100%; background: #16a34a;
}
.vencedora-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #16a34a; color: #fff; padding: 4px 14px;
    border-radius: 20px; font-size: 10px; font-weight: 700;
    letter-spacing: .5px; margin-bottom: 14px;
    text-transform: uppercase;
}
.vencedora-nome { font-size: 16px; font-weight: 800; color: #14532d; }
.vencedora-valor { font-size: 20px; font-weight: 900; color: #16a34a; }
/* ── Tabela de orçamentos ── */
table { width: 100%; border-collapse: collapse; font-size: 10px; }
thead tr {
    background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff;
}
thead th {
    padding: 9px 10px; text-align: left; font-weight: 700;
    font-size: 9px; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody td { padding: 8px 10px; vertical-align: middle; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.badge-vencedor { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.badge-outros   { background: #f1f5f9; color: #475569; }
.valor-venc { font-weight: 800; color: #16a34a; }
/* ── Justificativa ── */
.justificativa-box {
    background: #fffbeb; border-left: 4px solid #f59e0b;
    padding: 12px 16px; border-radius: 0 8px 8px 0;
    font-size: 11px; color: #78350f; line-height: 1.6;
}
/* ── Assinatura ── */
.assinatura-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 40px;
    margin-top: 8px;
}
.assinatura-bloco { text-align: center; }
.assinatura-linha {
    border-bottom: 1.5px solid #334155; margin-bottom: 6px; height: 40px;
}
.assinatura-nome  { font-size: 11px; font-weight: 700; color: #1e293b; }
.assinatura-cargo { font-size: 10px; color: #64748b; margin-top: 2px; }
.local-data { text-align: right; font-size: 10px; color: #64748b; margin-top: 20px; font-style: italic; }
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
    @page { margin: 10mm 8mm; size: A4; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .vencedora-card { page-break-inside: avoid; }
    .assinatura-grid { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">
    &#128438; Imprimir / Salvar como PDF
</button>

<div class="relatorio">

    <!-- ── CABEÇALHO ── -->
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
            <?php if ($end_empresa): ?><p><?= esc($end_empresa) ?></p><?php endif; ?>
        </div>
        <div class="header-meta">
            <strong>RELATÓRIO DE LICITAÇÃO</strong>
            <?= esc($contrato['numero_contrato']) ?><br>
            Emitido em: <?= $data_hoje ?> às <?= $hora_agora ?>
        </div>
    </div>

    <!-- ── FAIXA TÍTULO ── -->
    <div class="titulo-faixa">
        <span>Resultado do Processo de Cotação / Licitação</span>
        <span class="sub"><?= esc($contrato['nome_contrato'] ?? $contrato['nome'] ?? '') ?></span>
    </div>

    <!-- ── KPIs ── -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-valor"><?= $total_orc ?></div>
            <div class="kpi-label">Orçamentos Recebidos</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor" style="color:#16a34a;"><?= $vencedor ? money($vencedor['valor']) : '—' ?></div>
            <div class="kpi-label">Menor Proposta</div>
        </div>
        <div class="kpi">
            <div class="kpi-valor"><?= money($contrato['valor_total']) ?></div>
            <div class="kpi-label">Valor Contratado</div>
        </div>
        <div class="kpi">
            <?php
            $economia = 0;
            if (!empty($orcamentos) && count($orcamentos) > 1) {
                $maior = end($orcamentos);
                $economia = $maior['valor'] - $orcamentos[0]['valor'];
            }
            ?>
            <div class="kpi-valor" style="color:#2563eb;"><?= money($economia) ?></div>
            <div class="kpi-label">Economia vs. Maior Proposta</div>
        </div>
    </div>

    <!-- ── OBJETO DA LICITAÇÃO ── -->
    <div class="secao" style="padding-top:24px;">
        <div class="secao-titulo">&#128196; Objeto da Licitação</div>
        <div class="dados-grid cols-4">
            <div class="campo">
                <span class="campo-label">Número do Contrato</span>
                <span class="campo-valor"><?= esc($contrato['numero_contrato']) ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Nome / Objeto</span>
                <span class="campo-valor"><?= esc($contrato['nome_contrato'] ?? $contrato['nome'] ?? '—') ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Tipo de Serviço</span>
                <span class="campo-valor"><?= esc($tipo_label) ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Vigência</span>
                <span class="campo-valor"><?= esc($vigencia) ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Fornecedor Contratado</span>
                <span class="campo-valor"><?= esc($contrato['fornecedor_nome']) ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">CNPJ / CPF</span>
                <span class="campo-valor"><?= esc($contrato['fornecedor_cnpj'] ?? '—') ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Valor Contratado</span>
                <span class="campo-valor destaque"><?= money($contrato['valor_total']) ?></span>
            </div>
            <div class="campo">
                <span class="campo-label">Plano de Contas</span>
                <span class="campo-valor"><?= esc(($contrato['plano_codigo'] ?? '') . ($contrato['plano_nome'] ? ' — ' . $contrato['plano_nome'] : '')) ?></span>
            </div>
        </div>
    </div>

    <!-- ── EMPRESA VENCEDORA ── -->
    <?php if ($vencedor): ?>
    <div class="secao">
        <div class="secao-titulo">&#127942; Empresa Vencedora — Melhor Proposta</div>
        <div class="vencedora-card">
            <div class="vencedora-badge">&#10003; Melhor Proposta Selecionada</div>
            <div class="dados-grid">
                <div class="campo">
                    <span class="campo-label">Empresa / Fornecedor</span>
                    <span class="campo-valor vencedora-nome"><?= esc($vencedor['fornecedor']) ?></span>
                </div>
                <div class="campo">
                    <span class="campo-label">Valor Proposto</span>
                    <span class="campo-valor vencedora-valor"><?= money($vencedor['valor']) ?></span>
                </div>
                <div class="campo" style="grid-column:1/-1;">
                    <span class="campo-label">Descrição do Serviço / Produto</span>
                    <span class="campo-valor"><?= esc($vencedor['descricao']) ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── QUADRO COMPARATIVO ── -->
    <div class="secao">
        <div class="secao-titulo">&#9878; Quadro Comparativo de Orçamentos</div>
        <?php if (empty($orcamentos)): ?>
        <p style="text-align:center;color:#94a3b8;padding:20px;font-style:italic;">Nenhum orçamento cadastrado para este contrato.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fornecedor / Empresa</th>
                    <th>Descrição do Serviço</th>
                    <th>Valor Proposto</th>
                    <th>Data</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orcamentos as $i => $orc): ?>
                <?php $is_venc = ($i === 0); ?>
                <tr <?= $is_venc ? 'style="background:#f0fdf4;"' : '' ?>>
                    <td><?= ($i + 1) ?></td>
                    <td style="font-weight:<?= $is_venc ? '700' : '400' ?>;"><?= esc($orc['fornecedor']) ?></td>
                    <td><?= esc($orc['descricao']) ?></td>
                    <td class="<?= $is_venc ? 'valor-venc' : '' ?>"><?= money($orc['valor']) ?></td>
                    <td><?= esc($orc['data_criacao'] ? date('d/m/Y', strtotime($orc['data_criacao'])) : '—') ?></td>
                    <td>
                        <?php if ($is_venc): ?>
                        <span class="badge badge-vencedor">&#10003; Vencedor</span>
                        <?php else: ?>
                        <span class="badge badge-outros">Cotado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($orc['justificativa'])): ?>
                <tr>
                    <td colspan="6" style="padding:4px 10px 10px 30px;background:<?= $is_venc ? '#f0fdf4' : '#fff' ?>;">
                        <div class="justificativa-box">
                            <strong>Justificativa:</strong> <?= esc($orc['justificativa']) ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ── ASSINATURA ── -->
    <div class="secao">
        <div class="secao-titulo">&#9997; Aprovação e Assinatura</div>
        <div class="assinatura-grid">
            <div class="assinatura-bloco">
                <div class="assinatura-linha"></div>
                <div class="assinatura-nome"><?= $responsavel ? esc($responsavel) : '________________________________' ?></div>
                <div class="assinatura-cargo"><?= esc($cargo) ?></div>
            </div>
            <div class="assinatura-bloco">
                <div class="assinatura-linha"></div>
                <div class="assinatura-nome">________________________________</div>
                <div class="assinatura-cargo">Testemunha / Fiscal do Contrato</div>
            </div>
        </div>
        <div class="local-data"><?= esc($local_data) ?></div>
    </div>

    <!-- ── RODAPÉ ── -->
    <div class="rodape">
        <span>Documento gerado pelo <strong>Sistema ERP Condomínio</strong></span>
        <span><?= $data_hoje ?> às <?= $hora_agora ?> | <?= esc($nome_empresa) ?></span>
    </div>

</div><!-- /relatorio -->
</body>
</html>
