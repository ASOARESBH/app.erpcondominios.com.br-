<?php
/**
 * ============================================================
 * DEMONSTRATIVO DE CONSUMO DE ÁGUA — ERP CONDOMÍNIO
 * ============================================================
 * Gera uma página HTML otimizada para impressão/PDF no estilo
 * de fatura de água, com identidade visual da associação.
 *
 * Parâmetros GET:
 *   hidrometro_id  — ID do hidrômetro (obrigatório)
 *   mes            — Mês de referência YYYY-MM (opcional; padrão = último mês com leitura)
 *   print          — "true" para disparar window.print() automaticamente
 *
 * @version 1.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

$conn    = conectar_banco();
// Autenticação opcional: não bloqueia a abertura em nova aba
// (sessão PHP pode não estar disponível em nova aba/janela)
$usuario = verificarAutenticacao(false);
$tenant_id = exigirTenantId();
if ($usuario === false) {
    $usuario = ['nome' => 'Sistema', 'id' => null];
}

date_default_timezone_set('America/Sao_Paulo');

// ── Parâmetros ────────────────────────────────────────────────
$hidrometro_id = intval($_GET['hidrometro_id'] ?? 0);
$mes_ref       = trim($_GET['mes']             ?? '');   // YYYY-MM
$auto_print    = ($_GET['print']               ?? '') === 'true';

if ($hidrometro_id <= 0) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;padding:40px;color:#dc2626;">Parâmetro <strong>hidrometro_id</strong> é obrigatório.</p>';
    exit;
}

// ── 1. Dados da empresa / associação ─────────────────────────
$empresa = [];
$r = $conn->query("SELECT razao_social, nome_fantasia, cnpj, telefone,
    endereco_rua AS endereco, endereco_cidade AS cidade,
    endereco_estado AS estado, endereco_cep AS cep,
    logo_url FROM empresa LIMIT 1");
if ($r && $r->num_rows > 0) {
    $empresa = $r->fetch_assoc();
}
$assoc_nome     = !empty($empresa['nome_fantasia']) ? $empresa['nome_fantasia'] : (!empty($empresa['razao_social']) ? $empresa['razao_social'] : 'ERP CONDOMÍNIO');
$assoc_cnpj     = $empresa['cnpj']     ?? '';
$assoc_tel      = $empresa['telefone'] ?? '';
$assoc_end      = $empresa['endereco'] ?? '';
$assoc_cidade   = $empresa['cidade']   ?? '';
$assoc_estado   = $empresa['estado']   ?? '';
$assoc_cep      = $empresa['cep']      ?? '';

// URL da logo
$proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'app.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
    ? $proto . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
    : $proto . '://' . $host . '/assets/images/logo.jpeg';

// ── 2. Dados do hidrômetro + morador ─────────────────────────
$sql_h = "
    SELECT
        h.id,
        h.numero_hidrometro,
        h.numero_lacre,
        h.unidade,
        h.ativo,
        DATE_FORMAT(h.data_instalacao, '%d/%m/%Y') AS data_instalacao,
        m.id   AS morador_id,
        m.nome AS morador_nome,
        m.cpf  AS morador_cpf,
        m.telefone AS morador_tel,
        m.email    AS morador_email
    FROM hidrometros h
    INNER JOIN moradores m ON h.morador_id = m.id
    WHERE h.id = ?
    LIMIT 1
";
$stmt_h = $conn->prepare($sql_h);
$stmt_h->bind_param('i', $hidrometro_id);
$stmt_h->execute();
$hidro = $stmt_h->get_result()->fetch_assoc();
$stmt_h->close();

if (!$hidro) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:40px;color:#dc2626;">Hidrômetro não encontrado.</p>';
    exit;
}

// ── 2.1 Segurança — Portal do Morador ──────────────────────────
// Quando a requisição chega com uma sessão de morador ativa (Portal),
// o hidrômetro solicitado precisa pertencer a esse morador. Sem sessão
// de morador (uso administrativo), o comportamento permanece inalterado.
if (isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
    $morador_sessao_id = (int) ($_SESSION['morador_id'] ?? 0);
    if ($morador_sessao_id <= 0 || (int) $hidro['morador_id'] !== $morador_sessao_id) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Acesso negado</title>
        <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc;}
        .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);}
        h2{color:#dc2626;margin-bottom:8px;}p{color:#64748b;}</style></head>
        <body><div class="box"><h2>403 — Acesso não autorizado</h2>
        <p>Este demonstrativo pertence a outra unidade e não pode ser visualizado com o seu login.</p></div></body></html>';
        exit;
    }
}

// ── 3. Leitura de referência (mês selecionado ou última) ──────
if ($mes_ref !== '') {
    // Busca a leitura mais recente dentro do mês informado
    $ano_mes = $mes_ref;   // YYYY-MM
    $sql_ref = "
        SELECT *
        FROM leituras
        WHERE hidrometro_id = ?
          AND DATE_FORMAT(data_leitura, '%Y-%m') = ?
        ORDER BY data_leitura DESC
        LIMIT 1
    ";
    $stmt_ref = $conn->prepare($sql_ref);
    $stmt_ref->bind_param('is', $hidrometro_id, $ano_mes);
} else {
    // Última leitura disponível
    $sql_ref = "
        SELECT *
        FROM leituras
        WHERE hidrometro_id = ?
        ORDER BY data_leitura DESC
        LIMIT 1
    ";
    $stmt_ref = $conn->prepare($sql_ref);
    $stmt_ref->bind_param('i', $hidrometro_id);
}
$stmt_ref->execute();
$leitura_ref = $stmt_ref->get_result()->fetch_assoc();
$stmt_ref->close();

// Se não há leitura, exibir aviso amigável
if (!$leitura_ref) {
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
    <title>Sem leitura</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc;}
    .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);}
    h2{color:#1e3a8a;margin-bottom:8px;}p{color:#64748b;}</style></head>
    <body><div class="box">
    <h2>Sem leitura registrada</h2>
    <p>Não há leituras para o hidrômetro <strong>' . htmlspecialchars($hidro['numero_hidrometro']) . '</strong>
    ' . ($mes_ref ? 'no mês ' . htmlspecialchars($mes_ref) : '') . '.</p>
    <p style="margin-top:16px;"><a href="javascript:history.back()" style="color:#2563eb;">← Voltar</a></p>
    </div></body></html>';
    exit;
}

// ── 4. Histórico dos últimos 13 meses (para o gráfico/tabela) ─
$sql_hist = "
    SELECT
        DATE_FORMAT(data_leitura, '%m/%Y')  AS mes_ano,
        DATE_FORMAT(data_leitura, '%Y-%m')  AS mes_chave,
        consumo,
        leitura_atual,
        leitura_anterior,
        valor_total,
        valor_metro_cubico,
        valor_minimo,
        data_leitura
    FROM leituras
    WHERE hidrometro_id = ?
    ORDER BY data_leitura DESC
    LIMIT 13
";
$stmt_hist = $conn->prepare($sql_hist);
$stmt_hist->bind_param('i', $hidrometro_id);
$stmt_hist->execute();
$res_hist = $stmt_hist->get_result();
$historico = [];
while ($row = $res_hist->fetch_assoc()) {
    $row['consumo']           = (float) $row['consumo'];
    $row['leitura_atual']     = (float) $row['leitura_atual'];
    $row['leitura_anterior']  = (float) $row['leitura_anterior'];
    $row['valor_total']       = (float) $row['valor_total'];
    $row['valor_metro_cubico']= (float) $row['valor_metro_cubico'];
    $row['valor_minimo']      = (float) $row['valor_minimo'];
    $historico[] = $row;
}
$stmt_hist->close();
fechar_conexao($conn);

// ── 5. Cálculos derivados ─────────────────────────────────────
$consumo_ref    = (float) $leitura_ref['consumo'];
$leit_atual     = (float) $leitura_ref['leitura_atual'];
$leit_anterior  = (float) $leitura_ref['leitura_anterior'];
$valor_m3       = (float) $leitura_ref['valor_metro_cubico'];
$valor_minimo   = (float) $leitura_ref['valor_minimo'];
$valor_total    = (float) $leitura_ref['valor_total'];
$mes_referencia = date('m/Y', strtotime($leitura_ref['data_leitura']));
$data_leitura   = date('d/m/Y', strtotime($leitura_ref['data_leitura']));

// Média diária (considera 30 dias se não houver leitura anterior com data)
$media_diaria = $consumo_ref > 0 ? round($consumo_ref / 30, 3) : 0;

// Número sequencial do demonstrativo (baseado no ID da leitura)
$num_demonstrativo = str_pad($leitura_ref['id'], 6, '0', STR_PAD_LEFT);

// ── 6. Helpers ────────────────────────────────────────────────
function fmtN($n, $dec = 2) {
    return number_format((float)$n, $dec, ',', '.');
}
function fmtD($dt) {
    return date('d/m/Y', strtotime($dt));
}
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Máximo consumo para barra de progresso do histórico
$max_consumo = 1;
foreach ($historico as $h) {
    if ($h['consumo'] > $max_consumo) $max_consumo = $h['consumo'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demonstrativo de Água — <?= esc($hidro['morador_nome']) ?> — <?= $mes_referencia ?></title>
    <style>
        /* ── Reset ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green:        #166534;
            --green-mid:    #16a34a;
            --green-light:  #dcfce7;
            --green-border: #86efac;
            --blue-dark:    #0f172a;
            --blue:         #1e40af;
            --blue-light:   #eff6ff;
            --text:         #1e293b;
            --muted:        #64748b;
            --border:       #e2e8f0;
            --bg:           #f8fafc;
            --white:        #ffffff;
        }

        @page {
            size: A4 portrait;
            margin: 10mm 10mm;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: var(--text);
            background: #e5e7eb;
            padding: 20px;
        }

        /* ── Botão de impressão ── */
        .print-bar {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: linear-gradient(135deg, var(--green-mid), var(--green));
            color: #fff;
            border: none;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(22,163,74,.35);
            transition: all .2s;
        }
        .btn-print:hover { filter: brightness(1.08); transform: translateY(-1px); }
        @media print { .print-bar { display: none !important; } }

        /* ── Folha A4 ── */
        .fatura {
            background: var(--white);
            max-width: 794px;
            margin: 0 auto;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
        }

        /* ── Cabeçalho ── */
        .fatura-header {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-mid) 100%);
            color: #fff;
            padding: 18px 22px 14px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .fatura-header .logo-wrap {
            background: #fff;
            border-radius: 8px;
            padding: 6px;
            flex-shrink: 0;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .fatura-header .logo-wrap img {
            max-width: 68px;
            max-height: 68px;
            object-fit: contain;
        }
        .fatura-header .assoc-info { flex: 1; }
        .fatura-header .assoc-nome {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: .3px;
            margin-bottom: 3px;
        }
        .fatura-header .assoc-meta {
            font-size: 10px;
            opacity: .85;
            line-height: 1.7;
        }
        .fatura-header .doc-info {
            text-align: right;
            flex-shrink: 0;
        }
        .fatura-header .doc-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .5px;
            opacity: .8;
        }
        .fatura-header .doc-num {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 1px;
        }
        .fatura-header .doc-tipo {
            font-size: 10px;
            background: rgba(255,255,255,.2);
            border-radius: 4px;
            padding: 2px 8px;
            margin-top: 4px;
            display: inline-block;
        }

        /* ── Faixa do morador ── */
        .fatura-morador {
            background: var(--blue-dark);
            color: #fff;
            padding: 12px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .morador-nome {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .2px;
        }
        .morador-meta {
            font-size: 10px;
            opacity: .75;
            margin-top: 2px;
        }
        .morador-unidade {
            background: var(--green-mid);
            color: #fff;
            border-radius: 6px;
            padding: 6px 14px;
            text-align: center;
            flex-shrink: 0;
        }
        .morador-unidade .u-label { font-size: 9px; text-transform: uppercase; opacity: .8; }
        .morador-unidade .u-val   { font-size: 14px; font-weight: 800; }

        /* ── Seção de identificação ── */
        .fatura-body { padding: 0 22px 18px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 14px;
        }
        .info-cell {
            padding: 10px 12px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .info-cell:last-child { border-right: none; }
        .info-cell.span2 { grid-column: span 2; }
        .info-cell.span3 { grid-column: span 3; }
        .info-cell.highlight {
            background: var(--green-light);
            border-color: var(--green-border);
        }
        .info-cell .ic-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .info-cell .ic-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }
        .info-cell.highlight .ic-val {
            color: var(--green);
            font-size: 15px;
        }

        /* ── Leituras ── */
        .leituras-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            background: var(--blue-dark);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 14px;
        }
        .lb-cell {
            padding: 12px;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,.1);
        }
        .lb-cell:last-child { border-right: none; }
        .lb-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: rgba(255,255,255,.6);
            margin-bottom: 5px;
        }
        .lb-val {
            font-size: 16px;
            font-weight: 800;
            color: #fff;
        }
        .lb-val.green { color: #4ade80; }
        .lb-unit {
            font-size: 9px;
            color: rgba(255,255,255,.5);
            margin-top: 2px;
        }

        /* ── Seção de dois painéis ── */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 14px;
        }

        /* ── Painel histórico ── */
        .panel {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }
        .panel-header {
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            padding: 8px 12px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--blue);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .panel-header svg { flex-shrink: 0; }

        table.hist {
            width: 100%;
            border-collapse: collapse;
        }
        table.hist th {
            background: var(--bg);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            color: var(--muted);
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        table.hist th.r { text-align: right; }
        table.hist td {
            padding: 6px 8px;
            font-size: 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        table.hist td.r { text-align: right; }
        table.hist tr:last-child td { border-bottom: none; }
        table.hist tr.ref-row td { background: var(--green-light); font-weight: 700; }

        /* Barra de consumo inline */
        .bar-wrap { display: flex; align-items: center; gap: 6px; }
        .bar-bg {
            flex: 1;
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--green-mid), var(--green));
            border-radius: 3px;
        }

        /* ── Painel de valores ── */
        .valores-list { padding: 10px 12px; }
        .vl-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px dashed var(--border);
            font-size: 11px;
        }
        .vl-row:last-child { border-bottom: none; }
        .vl-desc { color: var(--text); }
        .vl-val  { font-weight: 700; color: var(--blue); }
        .vl-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--green-light);
            border: 1px solid var(--green-border);
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 10px;
        }
        .vl-total .t-label { font-size: 11px; font-weight: 700; color: var(--green); }
        .vl-total .t-val   { font-size: 20px; font-weight: 900; color: var(--green); }

        /* ── Informações adicionais ── */
        .info-extra {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 14px;
        }
        .info-box {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
        }
        .info-box h4 {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--blue);
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        .info-box p {
            font-size: 10px;
            color: var(--muted);
            line-height: 1.7;
        }
        .info-box p strong { color: var(--text); }

        /* ── Rodapé ── */
        .fatura-footer {
            background: var(--blue-dark);
            color: rgba(255,255,255,.7);
            padding: 10px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px;
            margin-top: 14px;
        }
        .fatura-footer .f-logo { opacity: .5; font-size: 10px; font-weight: 700; }

        /* ── Canhoto ── */
        .canhoto {
            border: 2px dashed var(--border);
            border-radius: 8px;
            margin-top: 18px;
            overflow: hidden;
        }
        .canhoto-header {
            background: var(--green-mid);
            color: #fff;
            padding: 6px 14px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .canhoto-body {
            display: grid;
            grid-template-columns: 80px repeat(5, 1fr);
            align-items: center;
        }
        .canhoto-logo {
            padding: 8px;
            border-right: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .canhoto-logo img { max-width: 56px; max-height: 56px; object-fit: contain; }
        .canhoto-cell {
            padding: 10px 10px;
            border-right: 1px solid var(--border);
            text-align: center;
        }
        .canhoto-cell:last-child { border-right: none; }
        .cc-label { font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .3px; margin-bottom: 3px; }
        .cc-val   { font-size: 12px; font-weight: 800; color: var(--text); }
        .cc-val.green { color: var(--green); font-size: 14px; }

        /* ── Selo DEMONSTRATIVO ── */
        .selo-demo {
            position: absolute;
            top: 50%;
            right: 22px;
            transform: translateY(-50%) rotate(-8deg);
            border: 3px solid rgba(22,163,74,.3);
            color: rgba(22,163,74,.35);
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 18px;
            font-weight: 900;
            letter-spacing: 2px;
            pointer-events: none;
        }
        .fatura-morador { position: relative; }

        @media print {
            body { background: #fff; padding: 0; }
            .fatura { box-shadow: none; max-width: 100%; }
            .canhoto { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <!-- Botão de impressão -->
    <div class="print-bar">
        <button class="btn-print" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Imprimir / Salvar como PDF
        </button>
    </div>

    <div class="fatura">

        <!-- ── CABEÇALHO ── -->
        <div class="fatura-header">
            <div class="logo-wrap">
                <img src="<?= esc($logo_url) ?>"
                     alt="Logo <?= esc($assoc_nome) ?>"
                     onerror="this.style.display='none'">
            </div>
            <div class="assoc-info">
                <div class="assoc-nome"><?= esc($assoc_nome) ?></div>
                <div class="assoc-meta">
                    <?php if ($assoc_cnpj): ?>CNPJ: <?= esc($assoc_cnpj) ?><br><?php endif; ?>
                    <?php if ($assoc_end):  ?>Endereço: <?= esc($assoc_end) ?><?php if ($assoc_cidade): ?>, <?= esc($assoc_cidade) ?><?php endif; ?><?php if ($assoc_estado): ?>/<?= esc($assoc_estado) ?><?php endif; ?><br><?php endif; ?>
                    <?php if ($assoc_tel):  ?>Telefone: <?= esc($assoc_tel) ?><?php endif; ?>
                </div>
            </div>
            <div class="doc-info">
                <div class="doc-label">Demonstrativo Nº</div>
                <div class="doc-num"><?= $num_demonstrativo ?></div>
                <div class="doc-tipo">CONSUMO DE ÁGUA</div>
            </div>
        </div>

        <!-- ── FAIXA DO MORADOR ── -->
        <div class="fatura-morador">
            <div>
                <div class="morador-nome"><?= esc(mb_strtoupper($hidro['morador_nome'], 'UTF-8')) ?></div>
                <div class="morador-meta">
                    <?php if ($hidro['morador_cpf']): ?>CPF: <?= esc($hidro['morador_cpf']) ?> &nbsp;|&nbsp; <?php endif; ?>
                    <?php if ($hidro['morador_tel']): ?>Tel: <?= esc($hidro['morador_tel']) ?> &nbsp;|&nbsp; <?php endif; ?>
                    Emitido em: <?= date('d/m/Y \à\s H:i') ?>
                </div>
            </div>
            <div class="morador-unidade">
                <div class="u-label">Unidade</div>
                <div class="u-val"><?= esc($hidro['unidade']) ?></div>
            </div>
            <div class="selo-demo">DEMONSTRATIVO</div>
        </div>

        <!-- ── CORPO ── -->
        <div class="fatura-body">

            <!-- Grade de identificação -->
            <div class="info-grid">
                <div class="info-cell">
                    <div class="ic-label">Nº do Hidrômetro</div>
                    <div class="ic-val"><?= esc($hidro['numero_hidrometro']) ?></div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Nº do Lacre</div>
                    <div class="ic-val"><?= $hidro['numero_lacre'] ? esc($hidro['numero_lacre']) : '<span style="color:#94a3b8;">N/A</span>' ?></div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Data de Instalação</div>
                    <div class="ic-val"><?= esc($hidro['data_instalacao']) ?></div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Status</div>
                    <div class="ic-val" style="color:<?= $hidro['ativo'] ? '#16a34a' : '#dc2626' ?>">
                        <?= $hidro['ativo'] ? '● Ativo' : '● Inativo' ?>
                    </div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Mês / Referência</div>
                    <div class="ic-val"><?= $mes_referencia ?></div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Data da Leitura</div>
                    <div class="ic-val"><?= $data_leitura ?></div>
                </div>
                <div class="info-cell">
                    <div class="ic-label">Tarifa (m³)</div>
                    <div class="ic-val">R$ <?= fmtN($valor_m3) ?></div>
                </div>
                <div class="info-cell highlight">
                    <div class="ic-label">Total do Demonstrativo</div>
                    <div class="ic-val">R$ <?= fmtN($valor_total) ?></div>
                </div>
            </div>

            <!-- Barra de leituras -->
            <div class="leituras-bar">
                <div class="lb-cell">
                    <div class="lb-label">Leitura Anterior</div>
                    <div class="lb-val"><?= fmtN($leit_anterior, 0) ?></div>
                    <div class="lb-unit">m³</div>
                </div>
                <div class="lb-cell">
                    <div class="lb-label">Leitura Atual</div>
                    <div class="lb-val"><?= fmtN($leit_atual, 0) ?></div>
                    <div class="lb-unit">m³</div>
                </div>
                <div class="lb-cell">
                    <div class="lb-label">Consumo do Mês</div>
                    <div class="lb-val green"><?= fmtN($consumo_ref, 0) ?></div>
                    <div class="lb-unit">m³</div>
                </div>
                <div class="lb-cell">
                    <div class="lb-label">Média Diária</div>
                    <div class="lb-val"><?= fmtN($media_diaria, 3) ?></div>
                    <div class="lb-unit">m³/dia</div>
                </div>
                <div class="lb-cell">
                    <div class="lb-label">Consumo Mínimo</div>
                    <div class="lb-val"><?= fmtN($valor_minimo) ?></div>
                    <div class="lb-unit">R$ (mínimo)</div>
                </div>
            </div>

            <!-- Dois painéis: histórico + valores -->
            <div class="two-col">

                <!-- Painel histórico -->
                <div class="panel">
                    <div class="panel-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Histórico de Consumo (últimos 12 meses)
                    </div>
                    <table class="hist">
                        <thead>
                            <tr>
                                <th>Mês/Ano</th>
                                <th class="r">Consumo</th>
                                <th>Variação</th>
                                <th class="r">Valor (R$)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $ref_chave = date('Y-m', strtotime($leitura_ref['data_leitura']));
                            foreach (array_slice($historico, 0, 12) as $h):
                                $is_ref = ($h['mes_chave'] === $ref_chave);
                                $pct    = $max_consumo > 0 ? round(($h['consumo'] / $max_consumo) * 100) : 0;
                            ?>
                            <tr <?= $is_ref ? 'class="ref-row"' : '' ?>>
                                <td><?= esc($h['mes_ano']) ?><?= $is_ref ? ' ★' : '' ?></td>
                                <td class="r"><?= fmtN($h['consumo'], 0) ?> m³</td>
                                <td>
                                    <div class="bar-wrap">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <span style="font-size:9px;color:#64748b;width:26px;text-align:right"><?= $pct ?>%</span>
                                    </div>
                                </td>
                                <td class="r">R$ <?= fmtN($h['valor_total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Painel de valores -->
                <div class="panel">
                    <div class="panel-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        Composição do Valor
                    </div>
                    <div class="valores-list">
                        <?php
                        // Calcular faixas de consumo (simulando a estrutura da fatura AMSJA)
                        $consumo_restante = $consumo_ref;
                        $faixas = [];

                        // Faixa 1: 0–10 m³ (tarifa básica)
                        if ($consumo_restante > 0) {
                            $qtd = min($consumo_restante, 10);
                            $faixas[] = ['desc' => "Consumo até 10 m³ ({$qtd} m³ × R$ " . fmtN($valor_m3) . ")", 'val' => $qtd * $valor_m3];
                            $consumo_restante -= $qtd;
                        }
                        // Faixa 2: 11–20 m³ (tarifa intermediária +15%)
                        if ($consumo_restante > 0) {
                            $qtd = min($consumo_restante, 10);
                            $tar = round($valor_m3 * 1.15, 4);
                            $faixas[] = ['desc' => "Consumo 11–20 m³ ({$qtd} m³ × R$ " . fmtN($tar) . ")", 'val' => $qtd * $tar];
                            $consumo_restante -= $qtd;
                        }
                        // Faixa 3: acima de 20 m³ (tarifa excedente +30%)
                        if ($consumo_restante > 0) {
                            $qtd = $consumo_restante;
                            $tar = round($valor_m3 * 1.30, 4);
                            $faixas[] = ['desc' => "Consumo acima de 20 m³ ({$qtd} m³ × R$ " . fmtN($tar) . ")", 'val' => $qtd * $tar];
                        }

                        // Se não houver faixas (consumo = 0), mostrar mínimo
                        if (empty($faixas) || $consumo_ref == 0) {
                            $faixas = [['desc' => 'Consumo mínimo mensal', 'val' => $valor_minimo]];
                        }

                        foreach ($faixas as $f):
                        ?>
                        <div class="vl-row">
                            <span class="vl-desc"><?= esc($f['desc']) ?></span>
                            <span class="vl-val">R$ <?= fmtN($f['val']) ?></span>
                        </div>
                        <?php endforeach; ?>

                        <?php if (!empty($leitura_ref['observacao'])): ?>
                        <div class="vl-row">
                            <span class="vl-desc" style="color:#64748b;font-style:italic;">Obs: <?= esc($leitura_ref['observacao']) ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="vl-total">
                            <span class="t-label">TOTAL DO DEMONSTRATIVO</span>
                            <span class="t-val">R$ <?= fmtN($valor_total) ?></span>
                        </div>

                        <p style="font-size:9px;color:#94a3b8;margin-top:8px;text-align:center;">
                            Este é um demonstrativo informativo. Não é um boleto de cobrança.
                        </p>
                    </div>
                </div>

            </div><!-- /two-col -->

            <!-- Informações adicionais -->
            <div class="info-extra">
                <div class="info-box">
                    <h4>Informações sobre a Qualidade da Água</h4>
                    <p>Água fornecida de acordo com os padrões físico-químicos e microbiológicos estabelecidos pela Portaria GM/MS Nº 888, de 04 de Maio de 2021, do Ministério da Saúde. Parâmetros monitorados: Cloro Residual Livre, Cor Aparente, pH, Turbidez, Coliformes Totais e <em>Escherichia Coli</em>.</p>
                </div>
                <div class="info-box">
                    <h4>Informações Gerais</h4>
                    <p>
                        <strong>Associação:</strong> <?= esc($assoc_nome) ?><br>
                        <?php if ($assoc_cnpj): ?><strong>CNPJ:</strong> <?= esc($assoc_cnpj) ?><br><?php endif; ?>
                        <?php if ($assoc_tel):  ?><strong>Telefone:</strong> <?= esc($assoc_tel) ?><br><?php endif; ?>
                        <strong>Hidrômetro:</strong> <?= esc($hidro['numero_hidrometro']) ?><br>
                        <?php if ($hidro['numero_lacre']): ?><strong>Lacre:</strong> <?= esc($hidro['numero_lacre']) ?><br><?php endif; ?>
                        <strong>Gerado por:</strong> <?= esc($usuario['nome'] ?? 'Sistema') ?> em <?= date('d/m/Y H:i') ?>
                    </p>
                </div>
            </div>

        </div><!-- /fatura-body -->

        <!-- ── RODAPÉ ── -->
        <div class="fatura-footer">
            <span>Sistema ERP Condomínio — Módulo de Hidrômetros</span>
            <span class="f-logo"><?= esc($assoc_nome) ?></span>
            <span>Demonstrativo Nº <?= $num_demonstrativo ?> — <?= $mes_referencia ?></span>
        </div>

        <!-- ── CANHOTO ── -->
        <div class="canhoto" style="margin: 14px 22px 22px;">
            <div class="canhoto-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                </svg>
                Recibo / Canhoto — Guardar para controle
            </div>
            <div class="canhoto-body">
                <div class="canhoto-logo">
                    <img src="<?= esc($logo_url) ?>" alt="Logo" onerror="this.style.display='none'">
                </div>
                <div class="canhoto-cell">
                    <div class="cc-label">Morador</div>
                    <div class="cc-val" style="font-size:10px;"><?= esc($hidro['morador_nome']) ?></div>
                </div>
                <div class="canhoto-cell">
                    <div class="cc-label">Hidrômetro / Lacre</div>
                    <div class="cc-val" style="font-size:10px;"><?= esc($hidro['numero_hidrometro']) ?> / <?= $hidro['numero_lacre'] ? esc($hidro['numero_lacre']) : 'N/A' ?></div>
                </div>
                <div class="canhoto-cell">
                    <div class="cc-label">Mês Ref.</div>
                    <div class="cc-val"><?= $mes_referencia ?></div>
                </div>
                <div class="canhoto-cell">
                    <div class="cc-label">Consumo</div>
                    <div class="cc-val"><?= fmtN($consumo_ref, 0) ?> m³</div>
                </div>
                <div class="canhoto-cell">
                    <div class="cc-label">Total</div>
                    <div class="cc-val green">R$ <?= fmtN($valor_total) ?></div>
                </div>
            </div>
        </div>

    </div><!-- /fatura -->

    <?php if ($auto_print): ?>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 700);
        });
    </script>
    <?php endif; ?>

</body>
</html>
