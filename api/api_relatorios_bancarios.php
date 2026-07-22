<?php
/**
 * api_relatorios_bancarios.php
 * Ações: extrato, fluxo_caixa, dre, exportar_csv
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';

verificarAutenticacao();

$db     = conectar_banco();
$acao   = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

switch ($acao) {
    case 'extrato':      _extrato($db);      break;
    case 'fluxo_caixa':  _fluxo_caixa($db);  break;
    case 'dre':          _dre($db);           break;
    case 'exportar_csv': _exportar_csv($db);  break;
    default:
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ação não reconhecida']);
        exit;
}

function _json($ok, $msg, $dados = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['sucesso' => (bool)$ok, 'mensagem' => $msg, 'dados' => $dados],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// EXTRATO BANCÁRIO
// ─────────────────────────────────────────────────────────────────
function _extrato($db) {
    $conta_id  = intval($_GET['conta_id'] ?? 0);
    $tipo      = $_GET['tipo']   ?? '';
    $status    = $_GET['status'] ?? '';
    $dt_ini    = $_GET['dt_ini'] ?? date('Y-m-01');
    $dt_fim    = $_GET['dt_fim'] ?? date('Y-m-d');
    $busca     = trim($_GET['busca'] ?? '');
    $categoria = trim($_GET['categoria'] ?? '');
    $limite    = min(intval($_GET['limite'] ?? 100), 500);
    $offset    = intval($_GET['offset'] ?? 0);

    $conds = ['mb.conta_id > 0'];
    if ($conta_id) $conds[] = "mb.conta_id = $conta_id";
    if ($tipo && in_array($tipo, ['credito','debito'], true)) {
        $conds[] = "mb.tipo = '" . $db->real_escape_string($tipo) . "'";
    }
    if ($status && in_array($status, ['pendente','conciliado','ignorado','divergente'], true)) {
        $conds[] = "mb.status = '" . $db->real_escape_string($status) . "'";
    }
    $conds[] = "mb.data_lancamento >= '" . $db->real_escape_string($dt_ini) . "'";
    $conds[] = "mb.data_lancamento <= '" . $db->real_escape_string($dt_fim) . "'";
    if ($busca) {
        $b = $db->real_escape_string($busca);
        $conds[] = "(mb.descricao LIKE '%$b%' OR mb.favorecido LIKE '%$b%' OR mb.checknum LIKE '%$b%')";
    }
    if ($categoria) {
        $conds[] = "mb.categoria = '" . $db->real_escape_string($categoria) . "'";
    }
    $where = implode(' AND ', $conds);

    // Totalizadores
    $tots = $db->query("SELECT
        COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0) AS total_credito,
        COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS total_debito,
        COUNT(*) AS total_registros
        FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where")->fetch_assoc();

    $sql = "SELECT
                mb.id, mb.conta_id, mb.data_lancamento, mb.tipo, mb.valor,
                CASE WHEN mb.tipo='credito' THEN mb.valor ELSE -mb.valor END AS valor_sinal,
                mb.descricao, mb.favorecido, mb.checknum, mb.numero_documento,
                mb.categoria, mb.centro_custo, mb.status, mb.origem,
                mb.conta_receber_id, mb.conta_pagar_id,
                cb.nome AS conta_nome, cb.banco_nome, cb.banco_codigo,
                COALESCE(cr.numero_documento, cp.numero_documento) AS doc_titulo,
                COALESCE(cr.morador_nome, cp.fornecedor_nome)      AS nome_titulo
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
            LEFT JOIN contas_receber cr ON cr.id = mb.conta_receber_id
            LEFT JOIN contas_pagar   cp ON cp.id = mb.conta_pagar_id
            WHERE $where
            ORDER BY mb.data_lancamento DESC, mb.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    _json(true, 'OK', [
        'movimentacoes'    => $rows,
        'total'            => (int)$tots['total_registros'],
        'total_credito'    => (float)$tots['total_credito'],
        'total_debito'     => (float)$tots['total_debito'],
        'saldo_periodo'    => (float)$tots['total_credito'] - (float)$tots['total_debito'],
        'filtros'          => ['dt_ini' => $dt_ini, 'dt_fim' => $dt_fim],
    ]);
}

// ─────────────────────────────────────────────────────────────────
// FLUXO DE CAIXA MENSAL
// ─────────────────────────────────────────────────────────────────
function _fluxo_caixa($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $meses    = min(intval($_GET['meses'] ?? 12), 24);

    $where_cb = $conta_id ? "AND mb.conta_id = $conta_id" : '';

    // Entradas e saídas realizadas mês a mês
    $sql = "SELECT
        DATE_FORMAT(mb.data_lancamento, '%Y-%m') AS mes,
        DATE_FORMAT(mb.data_lancamento, '%b/%Y') AS mes_label,
        COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0) AS entradas,
        COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS saidas
        FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND mb.data_lancamento >= DATE_FORMAT(
            DATE_SUB(CURDATE(), INTERVAL ($meses - 1) MONTH), '%Y-%m-01')
          $where_cb
        GROUP BY DATE_FORMAT(mb.data_lancamento,'%Y-%m')
        ORDER BY mes ASC";

    $realizado = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

    // Previsão mês a mês (contas a receber/pagar não liquidadas)
    $previsao = [];
    for ($i = 0; $i < $meses; $i++) {
        $mes_dt = date('Y-m', strtotime("-" . ($meses - 1 - $i) . " months"));
        $cr = (float)$db->query("SELECT COALESCE(SUM(valor_original),0) AS v
            FROM contas_receber WHERE tenant_id = $tenant_id AND ativo=1 AND status IN('PENDENTE','PARCIAL')
            AND DATE_FORMAT(data_vencimento,'%Y-%m') = '$mes_dt'")->fetch_assoc()['v'];
        $cp = (float)$db->query("SELECT COALESCE(SUM(valor_original),0) AS v
            FROM contas_pagar WHERE tenant_id = $tenant_id AND ativo=1 AND status IN('PENDENTE','PARCIAL')
            AND DATE_FORMAT(data_vencimento,'%Y-%m') = '$mes_dt'")->fetch_assoc()['v'];
        $previsao[] = [
            'mes'               => $mes_dt,
            'a_receber_previsto'=> $cr,
            'a_pagar_previsto'  => $cp,
        ];
    }

    // Saldo acumulado (bancário)
    $saldo_inicial = $conta_id
        ? (float)$db->query("SELECT saldo_inicial FROM contas_bancarias WHERE tenant_id = $tenant_id AND id=$conta_id")->fetch_assoc()['saldo_inicial']
        : (float)$db->query("SELECT COALESCE(SUM(saldo_inicial),0) AS s FROM contas_bancarias WHERE tenant_id = $tenant_id AND ativo=1")->fetch_assoc()['s'];

    _json(true, 'OK', [
        'realizado'     => $realizado,
        'previsao'      => $previsao,
        'saldo_inicial' => $saldo_inicial,
    ]);
}

// ─────────────────────────────────────────────────────────────────
// DRE SIMPLIFICADO (realizado bancário por categoria)
// ─────────────────────────────────────────────────────────────────
function _dre($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $dt_ini   = $_GET['dt_ini'] ?? date('Y-01-01');
    $dt_fim   = $_GET['dt_fim'] ?? date('Y-12-31');

    $where = "mb.data_lancamento BETWEEN '" . $db->real_escape_string($dt_ini)
           . "' AND '" . $db->real_escape_string($dt_fim) . "'";
    if ($conta_id) $where .= " AND mb.conta_id = $conta_id";

    // Receitas por categoria
    $sql_rec = "SELECT
        COALESCE(mb.categoria, 'Sem Categoria') AS categoria,
        COALESCE(SUM(mb.valor),0) AS total
        FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where AND mb.tipo = 'credito'
        GROUP BY mb.categoria
        ORDER BY total DESC";
    $receitas = $db->query($sql_rec)->fetch_all(MYSQLI_ASSOC);

    // Despesas por categoria
    $sql_des = "SELECT
        COALESCE(mb.categoria, 'Sem Categoria') AS categoria,
        COALESCE(SUM(mb.valor),0) AS total
        FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where AND mb.tipo = 'debito'
        GROUP BY mb.categoria
        ORDER BY total DESC";
    $despesas = $db->query($sql_des)->fetch_all(MYSQLI_ASSOC);

    $total_rec = array_sum(array_column($receitas, 'total'));
    $total_des = array_sum(array_column($despesas, 'total'));

    _json(true, 'OK', [
        'receitas'         => $receitas,
        'despesas'         => $despesas,
        'total_receitas'   => (float)$total_rec,
        'total_despesas'   => (float)$total_des,
        'resultado_liquido' => (float)$total_rec - (float)$total_des,
        'periodo'          => ['dt_ini' => $dt_ini, 'dt_fim' => $dt_fim],
    ]);
}

// ─────────────────────────────────────────────────────────────────
// EXPORTAR CSV
// ─────────────────────────────────────────────────────────────────
function _exportar_csv($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo     = $_GET['tipo']   ?? '';
    $status   = $_GET['status'] ?? '';
    $dt_ini   = $_GET['dt_ini'] ?? date('Y-m-01');
    $dt_fim   = $_GET['dt_fim'] ?? date('Y-m-d');

    $conds = ['mb.conta_id > 0'];
    if ($conta_id) $conds[] = "mb.conta_id = $conta_id";
    if ($tipo && in_array($tipo, ['credito','debito'], true)) {
        $conds[] = "mb.tipo = '" . $db->real_escape_string($tipo) . "'";
    }
    if ($status && in_array($status, ['pendente','conciliado','ignorado','divergente'], true)) {
        $conds[] = "mb.status = '" . $db->real_escape_string($status) . "'";
    }
    $conds[] = "mb.data_lancamento >= '" . $db->real_escape_string($dt_ini) . "'";
    $conds[] = "mb.data_lancamento <= '" . $db->real_escape_string($dt_fim) . "'";
    $where   = implode(' AND ', $conds);

    $rows = $db->query("SELECT
        mb.data_lancamento, cb.nome AS conta, cb.banco_nome,
        mb.tipo, mb.valor,
        CASE WHEN mb.tipo='credito' THEN mb.valor ELSE -mb.valor END AS valor_sinal,
        mb.descricao, mb.favorecido, mb.checknum, mb.numero_documento,
        mb.categoria, mb.centro_custo, mb.status, mb.origem
        FROM movimentacoes_bancarias mb
        JOIN contas_bancarias cb ON cb.id = mb.conta_id
        WHERE $where
        ORDER BY mb.data_lancamento DESC, mb.id DESC
        LIMIT 10000")->fetch_all(MYSQLI_ASSOC);

    $nome = 'extrato_' . $dt_ini . '_' . $dt_fim . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel

    fputcsv($out, [
        'Data', 'Conta', 'Banco', 'Tipo', 'Valor Absoluto', 'Valor com Sinal',
        'Descrição', 'Favorecido', 'Nº Cheque/Doc', 'Nº Documento', 'Categoria',
        'Centro de Custo', 'Status Conciliação', 'Origem'
    ], ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['data_lancamento'],
            $r['conta'],
            $r['banco_nome'],
            $r['tipo'] === 'credito' ? 'Crédito' : 'Débito',
            number_format((float)$r['valor'], 2, ',', '.'),
            number_format((float)$r['valor_sinal'], 2, ',', '.'),
            $r['descricao'],
            $r['favorecido'],
            $r['checknum'],
            $r['numero_documento'],
            $r['categoria'],
            $r['centro_custo'],
            ucfirst($r['status']),
            $r['origem'] === 'ofx' ? 'OFX' : 'Manual',
        ], ';');
    }
    fclose($out);
    exit;
}
