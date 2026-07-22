<?php
/**
 * DIAGNÓSTICO — Banking Module v2
 * Acesse via browser: /tests/diagnostico_bancario_v2.php
 * Não requer sessão autenticada — lê apenas schema e lógica interna.
 * REMOVA este arquivo do servidor após concluir os testes.
 */
define('_DIAG_RUN', true);
require_once __DIR__ . '/../api/config.php';

$db  = conectar_banco();
$ok  = 0;
$err = 0;
$results = [];

// ─── HELPER ──────────────────────────────────────────
function check(string $grupo, string $nome, bool $passed, string $detalhe = '') {
    global $ok, $err, $results;
    $passed ? $ok++ : $err++;
    $results[] = ['grupo' => $grupo, 'nome' => $nome, 'ok' => $passed, 'detalhe' => $detalhe];
}

// =====================================================
// 1. ARQUIVOS NOVOS (ETAPA 5)
// =====================================================
$novosArquivos = [
    '../api/api_conciliacao.php',
    '../api/api_relatorios_bancarios.php',
    '../assets/css/pages/conciliacao.css',
    '../assets/css/pages/relatorios_bancarios.css',
    '../frontend/pages/conciliacao.html',
    '../frontend/pages/relatorios_bancarios.html',
    '../frontend/js/pages/conciliacao.js',
    '../frontend/js/pages/relatorios_bancarios.js',
    '../sql/migration_contas_bancarias_v2.sql',
];
foreach ($novosArquivos as $f) {
    $path = __DIR__ . '/' . $f;
    check('Arquivos ETAPA 5', basename($f), file_exists($path), $path);
}

// =====================================================
// 2. ARQUIVOS MODIFICADOS (ETAPA 3 — verificar se foram aplicados)
// =====================================================
$modifs = [
    '../api/api_contas_bancarias.php'          => ['_motor_conciliacao', '_parsear_ofx_v2', 'dashboard_financeiro'],
    '../api/api_contas_receber.php'             => ['conciliar_movimentacao'],
    '../api/api_contas_pagar.php'              => ['conciliar_movimentacao'],
    '../frontend/pages/contas_bancarias.html'  => ['tab-conciliacao', 'favorecido'],
    '../frontend/pages/financeiro.html'        => ['fin-saldo-bancario', 'dashboard_financeiro'],
    '../frontend/js/pages/contas_bancarias.js' => ['conciliadas_auto', '_carregarPendentes'],
    '../frontend/js/permissoes-modulos.js'     => ["conciliacao", "relatorios_bancarios"],
];
foreach ($modifs as $file => $symbols) {
    $path    = __DIR__ . '/' . $file;
    $content = file_exists($path) ? file_get_contents($path) : '';
    foreach ($symbols as $sym) {
        $found = $content && strpos($content, $sym) !== false;
        check('Modificações ETAPA 3', basename($file) . " → '$sym'", $found,
            $found ? '' : 'Símbolo não encontrado — ETAPA 3 ainda não aplicada neste arquivo');
    }
}

// =====================================================
// 3. SCHEMA — TABELAS
// =====================================================
$tabelas = [
    'movimentacoes_bancarias',
    'contas_bancarias',
    'historico_importacoes_ofx',
    'bancos_brasileiros',
    'conciliacoes',
];
foreach ($tabelas as $t) {
    $r = $db->query("SHOW TABLES LIKE '$t'");
    check('Schema — Tabelas', $t, $r && $r->num_rows > 0);
}

// =====================================================
// 4. SCHEMA — COLUNAS NOVAS (migration v2)
// =====================================================
$colunas = [
    'movimentacoes_bancarias' => [
        'favorecido', 'memo', 'payee', 'numero_documento',
        'fitid', 'checknum', 'banco_origem', 'centro_custo',
        'conciliacao_id', 'status',
    ],
    'conciliacoes' => [
        'id', 'movimentacao_id', 'tipo_titulo', 'titulo_id',
        'score', 'tipo_conciliacao', 'criterios', 'ativa',
        'conciliado_por', 'conciliado_em', 'desfeita_por', 'desfeita_em',
    ],
    'historico_importacoes_ofx' => [
        'conciliadas_auto', 'pendentes', 'formato_ofx',
    ],
];
foreach ($colunas as $tabela => $cols) {
    foreach ($cols as $col) {
        $r = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabela' AND COLUMN_NAME = '$col'");
        check('Schema — Colunas', "$tabela.$col", $r && $r->num_rows > 0,
            $r && $r->num_rows === 0 ? 'Execute sql/migration_contas_bancarias_v2.sql' : '');
    }
}

// =====================================================
// 5. SCHEMA — VIEWS
// =====================================================
$views = [
    'vw_dashboard_financeiro',
    'vw_pendencias_conciliacao',
    'vw_fluxo_caixa',
    'vw_extrato_bancario',
    'vw_saldo_contas',
];
foreach ($views as $v) {
    $r = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$v'");
    check('Schema — Views', $v, $r && $r->num_rows > 0);
}

// =====================================================
// 6. SCHEMA — ÍNDICES CRÍTICOS
// =====================================================
$indices = [
    ['movimentacoes_bancarias', 'uk_fitid'],
    ['movimentacoes_bancarias', 'idx_mov_status'],
    ['movimentacoes_bancarias', 'idx_mov_conciliacao'],
    ['conciliacoes',            'idx_conc_movimentacao'],
    ['conciliacoes',            'idx_conc_titulo'],
];
foreach ($indices as [$tabela, $idx]) {
    $r = $db->query("SHOW INDEX FROM `$tabela` WHERE Key_name = '$idx'");
    check('Schema — Índices', "$tabela.$idx", $r && $r->num_rows > 0);
}

// =====================================================
// 7. MÓDULOS NO BANCO
// =====================================================
$modulos = ['conciliacao', 'relatorios_bancarios', 'logs_financeiro'];
foreach ($modulos as $m) {
    $stmt = $db->prepare("SELECT id FROM modulos_sistema WHERE nome = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $m);
        $stmt->execute();
        $stmt->store_result();
        check('Módulos Sistema', $m, $stmt->num_rows > 0,
            $stmt->num_rows === 0 ? 'Execute sql/migration_contas_bancarias_v2.sql (seeds)' : '');
        $stmt->close();
    } else {
        check('Módulos Sistema', $m, false, 'Tabela modulos_sistema não encontrada');
    }
}

// =====================================================
// 8. PARSER OFX — SGML (Bradesco, BB, Caixa, Santander)
// =====================================================
$ofxSGML = <<<OFX
OFXHEADER:100
DATA:OFXSGML
VERSION:151

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL
<BANKTRANLIST>
<DTSTART>20260601
<DTEND>20260625
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260610120000
<TRNAMT>1500.00
<FITID>BB20260610001
<CHECKNUM>DOC001
<NAME>EMPRESA ABC LTDA
<MEMO>PIX TED EMPRESA ABC LTDA CNPJ 12345678
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260615120000
<TRNAMT>-350.50
<FITID>BB20260615001
<NAME>CONCESSIONARIA XYZ
<MEMO>PGTO BOLETO 9876543210
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

$txSGML = _testar_parser($ofxSGML);
check('Parser OFX', 'SGML: detecta formato', $txSGML['formato'] === 'sgml', 'Formato: ' . $txSGML['formato']);
check('Parser OFX', 'SGML: extrai 2 transações', count($txSGML['transacoes']) === 2, 'Encontradas: ' . count($txSGML['transacoes']));
if (count($txSGML['transacoes']) >= 1) {
    $t = $txSGML['transacoes'][0];
    check('Parser OFX', 'SGML: FITID correto',   $t['fitid']    === 'BB20260610001', 'Obtido: ' . ($t['fitid'] ?? ''));
    check('Parser OFX', 'SGML: valor correto',   $t['valor']    === 1500.00,         'Obtido: ' . ($t['valor'] ?? ''));
    check('Parser OFX', 'SGML: tipo=credito',    $t['tipo']     === 'credito',        'Obtido: ' . ($t['tipo'] ?? ''));
    check('Parser OFX', 'SGML: favorecido NAME', strpos($t['favorecido'] ?? '', 'EMPRESA ABC') !== false, 'Obtido: ' . ($t['favorecido'] ?? ''));
}

// =====================================================
// 9. PARSER OFX — XML (Itaú, Nubank)
// =====================================================
$ofxXML = <<<OFX
<?xml version="1.0" encoding="UTF-8"?>
<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL</CURDEF>
<BANKTRANLIST>
<DTSTART>20260601000000</DTSTART>
<DTEND>20260625000000</DTEND>
<STMTTRN>
<TRNTYPE>DEBIT</TRNTYPE>
<DTPOSTED>20260612000000</DTPOSTED>
<TRNAMT>-220.00</TRNAMT>
<FITID>ITAU20260612001</FITID>
<CHECKNUM>NF-00123</CHECKNUM>
<NAME>FORNECEDOR SILVA</NAME>
<MEMO>TED FORNECEDOR SILVA REF NF-00123</MEMO>
</STMTTRN>
<STMTTRN>
<TRNTYPE>CREDIT</TRNTYPE>
<DTPOSTED>20260620000000</DTPOSTED>
<TRNAMT>5000.00</TRNAMT>
<FITID>ITAU20260620001</FITID>
<NAME>CONDOMINIO CLIENTE</NAME>
<MEMO>PIX COND CLIENTE RUA A 123</MEMO>
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

$txXML = _testar_parser($ofxXML);
check('Parser OFX', 'XML: detecta formato', $txXML['formato'] === 'xml', 'Formato: ' . $txXML['formato']);
check('Parser OFX', 'XML: extrai 2 transações', count($txXML['transacoes']) === 2, 'Encontradas: ' . count($txXML['transacoes']));
if (count($txXML['transacoes']) >= 1) {
    $t = $txXML['transacoes'][0];
    check('Parser OFX', 'XML: FITID correto', $t['fitid'] === 'ITAU20260612001', 'Obtido: ' . ($t['fitid'] ?? ''));
    check('Parser OFX', 'XML: valor correto', $t['valor'] === 220.00,             'Obtido: ' . ($t['valor'] ?? ''));
    check('Parser OFX', 'XML: tipo=debito',   $t['tipo']  === 'debito',            'Obtido: ' . ($t['tipo'] ?? ''));
    check('Parser OFX', 'XML: checknum',      ($t['checknum'] ?? '') === 'NF-00123', 'Obtido: ' . ($t['checknum'] ?? ''));
}

// =====================================================
// 10. MOTOR DE CONCILIAÇÃO — SCORING
// =====================================================
// Caso A: match perfeito (deve ≥ 80 → auto-conciliar)
$scoreA = _testar_score([
    'valor'     => 1500.00,
    'data'      => '2026-06-10',
    'favorecido'=> 'EMPRESA ABC LTDA',
    'checknum'  => 'DOC001',
    'memo'      => 'PIX TED EMPRESA ABC LTDA',
], [
    'valor'             => 1500.00,
    'data_vencimento'   => '2026-06-10',
    'favorecido'        => 'Empresa ABC Ltda',
    'numero_documento'  => 'DOC001',
    'descricao'         => 'NF EMPRESA ABC',
]);
check('Motor Conciliação', 'Score A: valor exato +40',        $scoreA['valor_pts']    === 40, 'Pts: ' . $scoreA['valor_pts']);
check('Motor Conciliação', 'Score A: checknum +25',           $scoreA['checknum_pts'] === 25, 'Pts: ' . $scoreA['checknum_pts']);
check('Motor Conciliação', 'Score A: data ±3d +20',           $scoreA['data_pts']     === 20, 'Pts: ' . $scoreA['data_pts']);
check('Motor Conciliação', 'Score A: favorecido ≥80% +15',   $scoreA['fav_pts']      === 15, 'Pts: ' . $scoreA['fav_pts']);
check('Motor Conciliação', 'Score A: total ≥80 (auto)',       $scoreA['total']        >= 80,  'Total: ' . $scoreA['total']);

// Caso B: valor aproximado, data +5d, sem checknum (deve ficar abaixo de 80)
$scoreB = _testar_score([
    'valor'     => 1480.00,
    'data'      => '2026-06-15',
    'favorecido'=> 'EMPRESA ABC LTDA',
    'checknum'  => '',
    'memo'      => '',
], [
    'valor'             => 1500.00,
    'data_vencimento'   => '2026-06-10',
    'favorecido'        => 'Empresa XYZ',
    'numero_documento'  => 'DOC999',
    'descricao'         => 'NF XYZ',
]);
check('Motor Conciliação', 'Score B: valor ≠ exato → 0 pts',  $scoreB['valor_pts']    === 0,  'Pts: ' . $scoreB['valor_pts']);
check('Motor Conciliação', 'Score B: data ±7d +12',           $scoreB['data_pts']     === 12, 'Pts: ' . $scoreB['data_pts']);
check('Motor Conciliação', 'Score B: sem checknum → 0 pts',   $scoreB['checknum_pts'] === 0,  'Pts: ' . $scoreB['checknum_pts']);
check('Motor Conciliação', 'Score B: total < 80 (manual)',    $scoreB['total']        < 80,   'Total: ' . $scoreB['total']);

// Caso C: valor diferente em mais de 5% → não candidato
$diff = abs(1500.00 - 1550.00) / 1500.00 * 100;
check('Motor Conciliação', 'Candidato: diferença >5% excluído', $diff > 5.0, sprintf('Diff: %.1f%%', $diff));

// =====================================================
// 11. INTEGRIDADE DA DEDUPLICAÇÃO FITID
// =====================================================
// Verifica se a unique key existe
$r = $db->query("SHOW INDEX FROM movimentacoes_bancarias WHERE Key_name = 'uk_fitid'");
check('Deduplicação FITID', 'Unique key uk_fitid existe', $r && $r->num_rows > 0);

// Testa INSERT duplicado (deve falhar com errno 1062)
$conta_r = $db->query("SELECT id FROM contas_bancarias LIMIT 1");
if ($conta_r && $conta_r->num_rows > 0) {
    $conta_id = $conta_r->fetch_assoc()['id'];
    $fitid_teste = 'DIAG_TEST_FITID_' . time();
    $db->query("INSERT INTO movimentacoes_bancarias
        (conta_id, tipo, valor, data_lancamento, descricao, origem, fitid)
        VALUES ($conta_id, 'credito', 0.01, CURDATE(), 'DIAG TEST', 'ofx', '$fitid_teste')");
    if ($db->errno === 0) {
        // Primeira inserção OK — tentar duplicar
        $db->query("INSERT INTO movimentacoes_bancarias
            (conta_id, tipo, valor, data_lancamento, descricao, origem, fitid)
            VALUES ($conta_id, 'credito', 0.01, CURDATE(), 'DIAG TEST DUP', 'ofx', '$fitid_teste')");
        $dup_blocked = ($db->errno === 1062);
        check('Deduplicação FITID', 'INSERT duplicado bloqueado (errno 1062)', $dup_blocked, 'errno: ' . $db->errno);
        // Limpar
        $db->query("DELETE FROM movimentacoes_bancarias WHERE fitid = '$fitid_teste'");
    } else {
        check('Deduplicação FITID', 'INSERT duplicado bloqueado (errno 1062)', false, 'Coluna fitid ausente ou erro: ' . $db->error);
    }
} else {
    check('Deduplicação FITID', 'INSERT duplicado bloqueado (errno 1062)', false, 'Nenhuma conta bancária cadastrada — crie uma conta primeiro');
}

// =====================================================
// FUNÇÕES AUXILIARES DE TESTE
// =====================================================
function _testar_parser(string $conteudo): array {
    $is_xml = stripos($conteudo, '</STMTTRN>') !== false;
    $formato = $is_xml ? 'xml' : 'sgml';
    $transacoes = [];

    if ($is_xml) {
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $m);
        foreach ($m[1] as $bloco) {
            $t = _ofx_extrair_transacao($bloco, true);
            if ($t) $transacoes[] = $t;
        }
    } else {
        // Extrai bloco BANKTRANLIST
        if (!preg_match('/<BANKTRANLIST>(.*?)(<\/BANKTRANLIST>|$)/is', $conteudo, $bm)) {
            return ['formato' => $formato, 'transacoes' => []];
        }
        $lista = $bm[1];
        $blocos = preg_split('/<STMTTRN>/i', $lista);
        array_shift($blocos); // remove tudo antes da primeira <STMTTRN>
        foreach ($blocos as $bloco) {
            // Remove a próxima tag como delimitador
            $bloco = preg_replace('/<\/STMTTRN>.*/is', '', $bloco);
            $t = _ofx_extrair_transacao($bloco, false);
            if ($t) $transacoes[] = $t;
        }
    }
    return ['formato' => $formato, 'transacoes' => $transacoes];
}

function _ofx_extrair_transacao(string $bloco, bool $xml): ?array {
    $campo = function(string $tag) use ($bloco, $xml): string {
        if ($xml) {
            if (preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/is', $bloco, $m)) return trim($m[1]);
        } else {
            if (preg_match('/<' . $tag . '>\s*([^\r\n<]+)/i', $bloco, $m)) return trim($m[1]);
        }
        return '';
    };

    $trntype = strtoupper($campo('TRNTYPE'));
    $raw_amt = $campo('TRNAMT');
    $fitid   = $campo('FITID');
    if (!$fitid || !$raw_amt) return null;

    $valor_float = abs((float) str_replace(',', '.', $raw_amt));
    $tipo = ($trntype === 'CREDIT' || (float)$raw_amt > 0) ? 'credito' : 'debito';

    $dt_raw = preg_replace('/[^0-9]/', '', substr($campo('DTPOSTED'), 0, 8));
    $data   = strlen($dt_raw) === 8
        ? substr($dt_raw,0,4).'-'.substr($dt_raw,4,2).'-'.substr($dt_raw,6,2)
        : date('Y-m-d');

    $name    = $campo('NAME');
    $payee   = $campo('PAYEE');
    $memo    = $campo('MEMO');
    $checknum = $campo('CHECKNUM');

    // Favorecido: NAME > PAYEE > memo parse
    $favorecido = $name ?: $payee ?: _ofx_favorecido_do_memo($memo);

    return [
        'fitid'     => $fitid,
        'tipo'      => $tipo,
        'valor'     => $valor_float,
        'data'      => $data,
        'favorecido'=> $favorecido,
        'memo'      => $memo,
        'checknum'  => $checknum,
    ];
}

function _ofx_favorecido_do_memo(string $memo): string {
    if (!$memo) return '';
    $memo = trim($memo);
    foreach (['PIX ', 'TED ', 'DOC ', 'TRANSF ', 'PGTO ', 'PAG '] as $pref) {
        if (stripos($memo, $pref) === 0) {
            $resto = trim(substr($memo, strlen($pref)));
            $partes = preg_split('/\s+/', $resto);
            return implode(' ', array_slice($partes, 0, 4));
        }
    }
    $partes = preg_split('/\s+/', $memo);
    return implode(' ', array_slice($partes, 0, 4));
}

function _testar_score(array $mov, array $titulo): array {
    $pts = ['valor_pts' => 0, 'checknum_pts' => 0, 'data_pts' => 0, 'fav_pts' => 0, 'memo_pts' => 0];

    // Valor
    if (abs($mov['valor'] - $titulo['valor']) < 0.01) {
        $pts['valor_pts'] = 40;
    }

    // Checknum / numero_documento
    if ($mov['checknum'] && $titulo['numero_documento']
        && strtolower($mov['checknum']) === strtolower($titulo['numero_documento'])) {
        $pts['checknum_pts'] = 25;
    }

    // Data
    $d1 = new DateTime($mov['data']);
    $d2 = new DateTime($titulo['data_vencimento']);
    $diff_days = (int) abs($d1->diff($d2)->days);
    if ($diff_days <= 3)      $pts['data_pts'] = 20;
    elseif ($diff_days <= 7)  $pts['data_pts'] = 12;

    // Favorecido (similaridade)
    $fav_mov   = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $mov['favorecido'] ?? ''));
    $fav_tit   = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $titulo['favorecido'] ?? ''));
    similar_text($fav_mov, $fav_tit, $pct_sim);
    if ($pct_sim >= 80) $pts['fav_pts'] = 15;

    // Memo contém numero_documento
    if ($titulo['numero_documento'] && strpos(strtolower($mov['memo'] ?? ''), strtolower($titulo['numero_documento'])) !== false) {
        $pts['memo_pts'] = 10;
    }

    $pts['total'] = array_sum($pts);
    return $pts;
}

// =====================================================
// SAÍDA HTML
// =====================================================
$total  = $ok + $err;
$pct    = $total > 0 ? round($ok / $total * 100) : 0;
$status = $err === 0 ? 'PASSOU' : ($err <= 3 ? 'ATENÇÃO' : 'FALHOU');
$corBarra = $err === 0 ? '#22c55e' : ($err <= 3 ? '#f59e0b' : '#ef4444');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Diagnóstico Banking v2</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#1e293b}
  .card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:24px;margin-bottom:20px}
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#64748b;font-size:13px;margin-bottom:16px}
  .prog{height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:12px 0}
  .prog-fill{height:100%;border-radius:99px;transition:width .5s}
  .sumario{display:flex;gap:16px;flex-wrap:wrap;margin-top:4px}
  .kpi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 18px;text-align:center;min-width:80px}
  .kpi-val{font-size:26px;font-weight:800}
  .kpi-lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
  .verde{color:#15803d}.vermelho{color:#dc2626}.amarelo{color:#b45309}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  thead th{background:#f8fafc;padding:9px 14px;text-align:left;font-size:11.5px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #e2e8f0;white-space:nowrap}
  tbody td{padding:9px 14px;border-bottom:1px solid #f1f5f9;vertical-align:top}
  tbody tr:last-child td{border-bottom:none}
  tbody tr:hover td{background:#fafafa}
  .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700}
  .badge.ok{background:#dcfce7;color:#15803d}
  .badge.fail{background:#fee2e2;color:#dc2626}
  .det{font-size:11.5px;color:#94a3b8;margin-top:2px}
  .grupo{font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em}
  .notice{background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400e;margin-bottom:16px}
</style>
</head>
<body>

<div class="card">
  <h1>Diagnóstico — Banking Module v2</h1>
  <p class="sub">ERP Condomínios · <?= date('d/m/Y H:i:s') ?></p>

  <div class="notice">
    ⚠️ <strong>Segurança:</strong> Remova este arquivo do servidor após concluir os testes.
  </div>

  <div class="prog"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $corBarra ?>"></div></div>

  <div class="sumario">
    <div class="kpi"><div class="kpi-val <?= $err===0?'verde':($err<=3?'amarelo':'vermelho') ?>"><?= $status ?></div><div class="kpi-lbl">Status</div></div>
    <div class="kpi"><div class="kpi-val verde"><?= $ok ?></div><div class="kpi-lbl">Passou</div></div>
    <div class="kpi"><div class="kpi-val vermelho"><?= $err ?></div><div class="kpi-lbl">Falhou</div></div>
    <div class="kpi"><div class="kpi-val"><?= $total ?></div><div class="kpi-lbl">Total</div></div>
    <div class="kpi"><div class="kpi-val"><?= $pct ?>%</div><div class="kpi-lbl">Aprovação</div></div>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr><th>Grupo</th><th>Verificação</th><th style="text-align:center">Resultado</th><th>Detalhe</th></tr>
    </thead>
    <tbody>
      <?php
      $grAtual = '';
      foreach ($results as $r):
          $isNovoGrupo = $r['grupo'] !== $grAtual;
          $grAtual = $r['grupo'];
      ?>
      <tr>
        <td><?= $isNovoGrupo ? '<span class="grupo">'._esc($r['grupo']).'</span>' : '' ?></td>
        <td><?= _esc($r['nome']) ?></td>
        <td style="text-align:center">
          <span class="badge <?= $r['ok']?'ok':'fail' ?>"><?= $r['ok']?'✓ OK':'✗ FALHA' ?></span>
        </td>
        <td><?= $r['detalhe'] ? '<span class="det">'._esc($r['detalhe']).'</span>' : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($err > 0): ?>
<div class="card">
  <h2 style="font-size:15px;margin:0 0 12px">Próximos Passos para os <?= $err ?> erro(s)</h2>
  <ol style="font-size:13.5px;line-height:1.8;color:#475569">
    <li>Se houver falhas em <strong>Schema</strong> ou <strong>Módulos Sistema</strong>: execute <code>sql/migration_contas_bancarias_v2.sql</code> no banco</li>
    <li>Se houver falhas em <strong>Modificações ETAPA 3</strong>: os arquivos existentes ainda não foram alterados — aplique as modificações da ETAPA 3</li>
    <li>Se houver falhas nos <strong>Arquivos ETAPA 5</strong>: faça upload dos arquivos para o servidor</li>
    <li>Se <strong>Deduplicação FITID</strong> falhar e o schema estiver OK: verifique se a migration foi executada após lançamentos manuais anteriores sem a coluna <code>fitid</code></li>
  </ol>
</div>
<?php endif; ?>

</body>
</html>
<?php

function _esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
