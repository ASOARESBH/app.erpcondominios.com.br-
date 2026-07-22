<?php
/**
 * ============================================================
 * RELATÓRIO DE ABASTECIMENTO — GERADOR DE PDF/IMPRESSÃO
 * ============================================================
 * Gera uma página HTML otimizada para impressão/PDF com a
 * identidade visual do sistema ERP Condomínio.
 *
 * Filtros aceitos via GET:
 *   veiculo_id   — ID do veículo (opcional)
 *   data_inicio  — Data inicial no formato YYYY-MM-DD (opcional)
 *   data_fim     — Data final no formato YYYY-MM-DD (opcional)
 *   combustivel  — Tipo de combustível (opcional)
 *   print        — Se "true", dispara window.print() automaticamente
 *
 * @version 1.1.0
 */

// ── 1. Bootstrap ──────────────────────────────────────────────
require_once 'config.php';
require_once 'auth_helper.php';

// Criar conexão com o banco (config.php define a função, não cria $conn automaticamente)
$conn = conectar_banco();

// Verificar autenticação via sessão PHP
// auth_helper já chama session_start() internamente
$usuario = verificarAutenticacao(true, 'operador');
// Se não autenticado, verificarAutenticacao() já encerra com HTTP 401 + JSON

// ── 2. Configurações regionais ────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

// ── 3. Dados da empresa (cabeçalho do relatório) ──────────────
$empresa = [];
$sql_empresa = "SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa LIMIT 1";
$res_empresa = $conn->query($sql_empresa);
if ($res_empresa && $res_empresa->num_rows > 0) {
    $empresa = $res_empresa->fetch_assoc();
}

$nome_empresa  = !empty($empresa['nome_fantasia'])  ? $empresa['nome_fantasia']
               : (!empty($empresa['razao_social'])  ? $empresa['razao_social']
               : 'ASSOCIAÇÃO SERRA DA LIBERDADE');
$cnpj_empresa  = !empty($empresa['cnpj'])           ? $empresa['cnpj'] : '28.231.106/0001-15';

// Caminho da logo: relativo à raiz do site (a tag <img> usa URL absoluta)
if (!empty($empresa['logo_url'])) {
    // logo_url pode ser "uploads/logo/arquivo.png" — converter para URL absoluta
    $protocolo   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
    $logo_url    = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
} else {
    $protocolo   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
    $logo_url    = $protocolo . '://' . $host . '/assets/images/logo.jpeg';
}

// ── 4. Filtros ────────────────────────────────────────────────
$veiculo_id  = trim($_GET['veiculo_id']  ?? '');
$data_inicio = trim($_GET['data_inicio'] ?? '');
$data_fim    = trim($_GET['data_fim']    ?? '');
$combustivel = trim($_GET['combustivel'] ?? '');
$auto_print  = ($_GET['print'] ?? '') === 'true';

// ── 5. Query principal ────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($veiculo_id !== '') {
    $where[]  = "a.veiculo_id = ?";
    $params[] = (int) $veiculo_id;
    $types   .= 'i';
}
if ($data_inicio !== '') {
    $where[]  = "DATE(a.data_abastecimento) >= ?";
    $params[] = $data_inicio;
    $types   .= 's';
}
if ($data_fim !== '') {
    $where[]  = "DATE(a.data_abastecimento) <= ?";
    $params[] = $data_fim;
    $types   .= 's';
}
if ($combustivel !== '') {
    $where[]  = "a.tipo_combustivel = ?";
    $params[] = $combustivel;
    $types   .= 's';
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        a.data_abastecimento,
        a.km_abastecimento,
        a.litros,
        a.valor,
        a.tipo_combustivel,
        v.placa  AS veiculo_placa,
        v.modelo AS veiculo_modelo,
        u.nome   AS operador_nome
    FROM abastecimento_lancamentos a
    INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
    INNER JOIN usuarios              u ON a.operador_id = u.id
    $whereClause
    ORDER BY a.data_abastecimento DESC
";

$dados        = [];
$total_litros = 0.0;
$total_valor  = 0.0;

if (count($params) > 0) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        // Falha no prepare — log e resultado vazio
        error_log('[relatorio_abastecimento_pdf] prepare() falhou: ' . $conn->error);
        $result = false;
    }
} else {
    $result = $conn->query($sql);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dados[]       = $row;
        $total_litros += (float) $row['litros'];
        $total_valor  += (float) $row['valor'];
    }
}

// ── 6. Dados do veículo selecionado (para o filtro info) ──────
$veiculo_label = 'Todos os veículos';
if ($veiculo_id !== '') {
    $stmt_v = $conn->prepare("SELECT placa, modelo FROM abastecimento_veiculos WHERE id = ?");
    if ($stmt_v) {
        $vid = (int) $veiculo_id;
        $stmt_v->bind_param('i', $vid);
        $stmt_v->execute();
        $res_v = $stmt_v->get_result()->fetch_assoc();
        if ($res_v) {
            $veiculo_label = htmlspecialchars($res_v['placa'] . ' — ' . $res_v['modelo']);
        }
    }
}

// ── 7. Fechar conexão ─────────────────────────────────────────
fechar_conexao($conn);

// ── 8. Helpers ────────────────────────────────────────────────
function badge_combustivel($tipo) {
    $t = mb_strtolower($tipo, 'UTF-8');
    if (strpos($t, 'gasolina') !== false)    return 'badge-gasolina';
    if (strpos($t, 'lcool')    !== false)    return 'badge-alcool';   // álcool / alcool
    if (strpos($t, 'diesel')   !== false)    return 'badge-diesel';
    if (strpos($t, 'gnv')      !== false)    return 'badge-gnv';
    return '';
}

function fmt_data($dt) {
    return date('d/m/Y H:i', strtotime($dt));
}
function fmt_num($n, $dec = 2) {
    return number_format((float)$n, $dec, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Abastecimento — <?= htmlspecialchars($nome_empresa) ?></title>
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue-dark:  #0f172a;
            --blue-mid:   #1e3a8a;
            --blue:       #2563eb;
            --blue-light: #eff6ff;
            --text:       #334155;
            --muted:      #64748b;
            --border:     #e2e8f0;
            --bg-alt:     #f8fafc;
        }

        @page { size: A4 portrait; margin: 14mm 12mm; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: var(--text);
            background: #fff;
            padding: 20px;
        }

        /* ── Botão de impressão (some no PDF) ── */
        .print-bar {
            text-align: center;
            margin-bottom: 24px;
        }
        .btn-print {
            background: linear-gradient(135deg, var(--blue), #1d4ed8);
            color: #fff;
            border: none;
            padding: 11px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(37,99,235,.3);
            transition: all .2s;
        }
        .btn-print:hover { filter: brightness(1.1); transform: translateY(-1px); }

        @media print { .print-bar { display: none !important; } }

        /* ── Cabeçalho ── */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--blue);
            padding-bottom: 14px;
            margin-bottom: 18px;
            gap: 16px;
        }
        .report-header .logo {
            max-width: 140px;
            max-height: 70px;
            object-fit: contain;
        }
        .report-header .company-info { text-align: right; }
        .report-header .company-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--blue-dark);
            margin-bottom: 3px;
        }
        .report-header .company-meta {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.6;
        }

        /* ── Título do relatório ── */
        .report-title {
            text-align: center;
            margin-bottom: 14px;
        }
        .report-title h2 {
            font-size: 18px;
            font-weight: 800;
            color: var(--blue-dark);
            margin-bottom: 4px;
        }
        .report-title .period {
            font-size: 12px;
            color: var(--muted);
        }

        /* ── Barra de filtros ── */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            background: var(--bg-alt);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 11px;
        }
        .filter-bar .fi { display: flex; gap: 4px; }
        .filter-bar .fi strong { color: var(--blue-dark); }

        /* ── Tabela ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }

        thead tr {
            background: linear-gradient(90deg, var(--blue-mid), var(--blue));
        }
        th {
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 10px 8px;
            text-align: left;
            white-space: nowrap;
        }
        th.r { text-align: right; }

        td {
            padding: 8px;
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            vertical-align: middle;
        }
        td.r { text-align: right; }

        tbody tr:nth-child(even) { background: var(--bg-alt); }
        tbody tr:hover           { background: #eff6ff; }

        .empty-row td {
            text-align: center;
            padding: 28px;
            color: var(--muted);
            font-style: italic;
        }

        /* ── Badges de combustível ── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            background: #e2e8f0;
            color: #475569;
        }
        .badge-gasolina { background: #fee2e2; color: #b91c1c; }
        .badge-alcool   { background: #dcfce7; color: #15803d; }
        .badge-diesel   { background: #fef3c7; color: #b45309; }
        .badge-gnv      { background: #e0f2fe; color: #0369a1; }

        /* ── Totalizadores ── */
        .summary {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            margin-bottom: 24px;
            page-break-inside: avoid;
        }
        .summary-card {
            background: var(--blue-light);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 22px;
            text-align: center;
            min-width: 160px;
        }
        .summary-card .s-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }
        .summary-card .s-value {
            display: block;
            font-size: 20px;
            font-weight: 800;
            color: var(--blue);
        }

        /* ── Rodapé ── */
        .report-footer {
            border-top: 1px solid var(--border);
            padding-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #94a3b8;
            page-break-inside: avoid;
        }
    </style>
</head>
<body>

    <!-- Botão de impressão (visível apenas na tela) -->
    <div class="print-bar no-print">
        <button class="btn-print" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Imprimir / Salvar como PDF
        </button>
    </div>

    <!-- Cabeçalho -->
    <div class="report-header">
        <img src="<?= htmlspecialchars($logo_url) ?>"
             alt="Logo <?= htmlspecialchars($nome_empresa) ?>"
             class="logo"
             onerror="this.style.display='none'">
        <div class="company-info">
            <div class="company-name"><?= htmlspecialchars($nome_empresa) ?></div>
            <div class="company-meta">
                CNPJ: <?= htmlspecialchars($cnpj_empresa) ?><br>
                Relatório gerado em: <?= date('d/m/Y \à\s H:i:s') ?>
            </div>
        </div>
    </div>

    <!-- Título -->
    <div class="report-title">
        <h2>Relatório de Abastecimento de Veículos</h2>
        <div class="period">
            <?php
            if ($data_inicio !== '' && $data_fim !== '') {
                echo 'Período: ' . date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim));
            } elseif ($data_inicio !== '') {
                echo 'A partir de: ' . date('d/m/Y', strtotime($data_inicio));
            } elseif ($data_fim !== '') {
                echo 'Até: ' . date('d/m/Y', strtotime($data_fim));
            } else {
                echo 'Período: Completo (todos os registros)';
            }
            ?>
        </div>
    </div>

    <!-- Barra de filtros aplicados -->
    <div class="filter-bar">
        <div class="fi"><strong>Veículo:</strong> <?= $veiculo_label ?></div>
        <div class="fi"><strong>Combustível:</strong> <?= $combustivel !== '' ? htmlspecialchars($combustivel) : 'Todos' ?></div>
        <div class="fi"><strong>Registros encontrados:</strong> <?= count($dados) ?></div>
        <div class="fi"><strong>Gerado por:</strong> <?= htmlspecialchars($usuario['nome'] ?? 'Sistema') ?></div>
    </div>

    <!-- Tabela de dados -->
    <table>
        <thead>
            <tr>
                <th>Data / Hora</th>
                <th>Modelo</th>
                <th>Placa</th>
                <th>KM</th>
                <th>Combustível</th>
                <th>Operador</th>
                <th class="r">Litros</th>
                <th class="r">Valor (R$)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($dados) > 0): ?>
                <?php foreach ($dados as $row): ?>
                    <tr>
                        <td><?= fmt_data($row['data_abastecimento']) ?></td>
                        <td><?= htmlspecialchars($row['veiculo_modelo']) ?></td>
                        <td><strong><?= htmlspecialchars($row['veiculo_placa']) ?></strong></td>
                        <td><?= fmt_num((float)$row['km_abastecimento'], 0) ?> km</td>
                        <td>
                            <span class="badge <?= badge_combustivel($row['tipo_combustivel']) ?>">
                                <?= htmlspecialchars($row['tipo_combustivel']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['operador_nome']) ?></td>
                        <td class="r"><?= fmt_num((float)$row['litros']) ?> L</td>
                        <td class="r">R$ <?= fmt_num((float)$row['valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="empty-row">
                    <td colspan="8">Nenhum registro encontrado para os filtros selecionados.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totalizadores -->
    <?php if (count($dados) > 0): ?>
    <div class="summary">
        <div class="summary-card">
            <span class="s-label">Total de Abastecimentos</span>
            <span class="s-value"><?= count($dados) ?></span>
        </div>
        <div class="summary-card">
            <span class="s-label">Total de Litros</span>
            <span class="s-value"><?= fmt_num($total_litros) ?> L</span>
        </div>
        <div class="summary-card">
            <span class="s-label">Valor Total</span>
            <span class="s-value">R$ <?= fmt_num($total_valor) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rodapé -->
    <div class="report-footer">
        <span>Sistema ERP Condomínio — Módulo de Abastecimento</span>
        <span>Impresso em: <?= date('d/m/Y H:i') ?></span>
    </div>

    <?php if ($auto_print): ?>
    <script>
        // Aguarda o carregamento completo da logo antes de imprimir
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 600);
        });
    </script>
    <?php endif; ?>

</body>
</html>
