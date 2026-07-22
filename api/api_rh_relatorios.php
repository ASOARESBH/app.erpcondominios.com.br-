<?php
// =====================================================
// API: RH — RELATÓRIOS
// =====================================================
// Todos os endpoints aceitam TANTO mes/ano QUANTO data_inicio/data_fim (YYYY-MM-DD)
// GET ?acao=totais_horas       &mes=N&ano=N  OU  &data_inicio=X&data_fim=X  [&departamento=X]
// GET ?acao=espelho_ponto      &colaborador_id=N  &mes=N&ano=N  OU  &data_inicio=X&data_fim=X
// GET ?acao=faltas             &mes=N&ano=N  OU  &data_inicio=X&data_fim=X  [&departamento=X]
// GET ?acao=horas_extras       &mes=N&ano=N  OU  &data_inicio=X&data_fim=X  [&departamento=X]
// GET ?acao=atrasos            &mes=N&ano=N  OU  &data_inicio=X&data_fim=X  [&departamento=X]
// GET ?acao=banco_horas        &colaborador_id=N  [&ate_mes=N&ate_ano=N  OU  &data_inicio=X&data_fim=X]
// GET ?acao=aniversariantes    &mes=N

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'error_logger.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed = ['https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) { retornar_json(false, 'Não autenticado'); }

if ($_SERVER['REQUEST_METHOD'] !== 'GET') retornar_json(false, 'Apenas GET permitido');

$acao = $_GET['acao'] ?? '';
$conn = conectar_banco();
if (!$conn) retornar_json(false, 'Erro ao conectar ao banco');

// ── Helper: resolve período (mes/ano OU data_inicio/data_fim) ────────────────
function _get_periodo_params() {
    $data_inicio = trim($_GET['data_inicio'] ?? '');
    $data_fim    = trim($_GET['data_fim']    ?? '');
    if ($data_inicio !== '' && $data_fim !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))
            retornar_json(false, 'Formato de data inválido. Use YYYY-MM-DD');
        if ($data_inicio > $data_fim)
            retornar_json(false, 'Data início não pode ser maior que data fim');
        return ['tipo' => 'personalizado', 'data_inicio' => $data_inicio, 'data_fim' => $data_fim,
                'label' => "$data_inicio a $data_fim"];
    }
    $mes = intval($_GET['mes'] ?? 0);
    $ano = intval($_GET['ano'] ?? 0);
    if ($mes < 1 || $mes > 12 || $ano < 2000) retornar_json(false, 'Mês/Ano inválido');
    $data_inicio = sprintf('%04d-%02d-01', $ano, $mes);
    $data_fim    = date('Y-m-t', strtotime($data_inicio));
    $meses_nome  = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    return ['tipo' => 'mes', 'mes' => $mes, 'ano' => $ano,
            'data_inicio' => $data_inicio, 'data_fim' => $data_fim,
            'label' => "{$meses_nome[$mes]}/$ano"];
}

// ── Totais de horas ──────────────────────────────────────────────────────────
if ($acao === 'totais_horas') {
    $periodo = _get_periodo_params();
    $dept    = trim($_GET['departamento'] ?? '');

    if ($periodo['tipo'] === 'personalizado') {
        // Agrega lançamentos no intervalo de datas
        $sql = "SELECT c.id, c.nome, c.cargo, c.departamento, c.tipo_contrato,
                       COALESCE(SUM(l.horas_trabalhadas_min),0)  as total_horas_trabalhadas_min,
                       COALESCE(SUM(l.horas_extras_min),0)        as total_horas_extras_min,
                       COALESCE(SUM(l.atraso_min),0)              as total_atraso_min,
                       COALESCE(SUM(l.tipo_dia='falta'),0)        as total_faltas,
                       COALESCE(SUM(l.tipo_dia='folga'),0)        as total_folgas,
                       'personalizado' as periodo_status
                FROM rh_colaboradores c
                LEFT JOIN rh_ponto_lancamento l ON l.colaborador_id = c.id
                    AND l.data BETWEEN ? AND ?
                WHERE c.ativo = 1";
        $params = [$periodo['data_inicio'], $periodo['data_fim']];
        $types  = 'ss';
        if ($dept !== '') { $sql .= ' AND c.departamento = ?'; $params[] = $dept; $types .= 's'; }
        $sql .= ' GROUP BY c.id ORDER BY c.departamento, c.nome';
    } else {
        $sql = "SELECT c.id, c.nome, c.cargo, c.departamento, c.tipo_contrato,
                       p.total_horas_trabalhadas_min,
                       p.total_horas_extras_min,
                       p.total_atraso_min,
                       p.total_faltas,
                       p.total_folgas,
                       p.status as periodo_status
                FROM rh_colaboradores c
                LEFT JOIN rh_ponto_periodo p ON p.colaborador_id = c.id AND p.mes = ? AND p.ano = ?
                WHERE c.ativo = 1";
        $params = [$periodo['mes'], $periodo['ano']]; $types = 'ii';
        if ($dept !== '') { $sql .= ' AND c.departamento = ?'; $params[] = $dept; $types .= 's'; }
        $sql .= ' ORDER BY c.departamento, c.nome';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['total_horas_trabalhadas_fmt'] = _min_para_horas((int)$r['total_horas_trabalhadas_min']);
        $r['total_horas_extras_fmt']      = _min_para_horas((int)$r['total_horas_extras_min']);
        $r['total_atraso_fmt']            = _min_para_horas((int)$r['total_atraso_min']);
        $list[] = $r;
    }
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── Espelho de ponto ──────────────────────────────────────────────────────────
if ($acao === 'espelho_ponto') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) retornar_json(false, 'colaborador_id obrigatório');

    $periodo = _get_periodo_params();

    // Busca dados do colaborador
    $stmt = $conn->prepare(
        "SELECT c.nome, c.cargo, c.departamento, c.cpf FROM rh_colaboradores c WHERE c.id=?"
    );
    $stmt->bind_param('i', $colab_id);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$header) { fechar_conexao($conn); retornar_json(false, 'Colaborador não encontrado'); }

    // Busca lançamentos no intervalo
    $stmt2 = $conn->prepare(
        "SELECT DATE_FORMAT(l.data,'%d/%m/%Y') as data_fmt,
                l.data,
                DAYNAME(l.data) as dia_semana,
                TIME_FORMAT(l.hora_entrada,'%H:%i')        as hora_entrada,
                TIME_FORMAT(l.hora_almoco_saida,'%H:%i')   as hora_almoco_saida,
                TIME_FORMAT(l.hora_almoco_retorno,'%H:%i') as hora_almoco_retorno,
                TIME_FORMAT(l.hora_saida,'%H:%i')          as hora_saida,
                l.tipo_dia, l.horas_trabalhadas_min, l.horas_extras_min, l.atraso_min, l.observacoes
         FROM rh_ponto_lancamento l
         WHERE l.colaborador_id = ? AND l.data BETWEEN ? AND ?
         ORDER BY l.data"
    );
    $stmt2->bind_param('iss', $colab_id, $periodo['data_inicio'], $periodo['data_fim']);
    $stmt2->execute();
    $diasPt = ['Monday'=>'Segunda','Tuesday'=>'Terça','Wednesday'=>'Quarta',
               'Thursday'=>'Quinta','Friday'=>'Sexta','Saturday'=>'Sábado','Sunday'=>'Domingo'];

    $lancamentos = [];
    $tot_trab = $tot_extra = $tot_atraso = $tot_faltas = $tot_folgas = 0;
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) {
        $r['dia_semana']            = $diasPt[$r['dia_semana']] ?? ($r['dia_semana'] ?? '—');
        $r['he']                    = $r['hora_entrada'];
        $r['has']                   = $r['hora_almoco_saida'];
        $r['har']                   = $r['hora_almoco_retorno'];
        $r['hs']                    = $r['hora_saida'];
        $r['horas_trab_fmt']        = _min_para_horas((int)$r['horas_trabalhadas_min']);
        $r['horas_extra_fmt']       = _min_para_horas((int)$r['horas_extras_min']);
        $r['horas_trabalhadas_fmt'] = $r['horas_trab_fmt'];
        $r['horas_extras_fmt']      = $r['horas_extra_fmt'];
        $r['atraso_fmt']            = _min_para_horas((int)$r['atraso_min']);
        $tot_trab   += (int)$r['horas_trabalhadas_min'];
        $tot_extra  += (int)$r['horas_extras_min'];
        $tot_atraso += (int)$r['atraso_min'];
        if ($r['tipo_dia'] === 'falta') $tot_faltas++;
        if ($r['tipo_dia'] === 'folga') $tot_folgas++;
        $lancamentos[] = $r;
    }
    $stmt2->close();

    $header['total_horas_trabalhadas_min'] = $tot_trab;
    $header['total_horas_extras_min']      = $tot_extra;
    $header['total_atraso_min']            = $tot_atraso;
    $header['total_faltas']                = $tot_faltas;
    $header['total_folgas']                = $tot_folgas;
    $header['total_horas_trabalhadas_fmt'] = _min_para_horas($tot_trab);
    $header['total_horas_extras_fmt']      = _min_para_horas($tot_extra);
    $header['total_atraso_fmt']            = _min_para_horas($tot_atraso);
    $header['periodo_label']               = $periodo['label'];

    fechar_conexao($conn);
    retornar_json(true, 'OK', ['cabecalho' => $header, 'lancamentos' => $lancamentos]);
}

// ── Faltas ────────────────────────────────────────────────────────────────────
if ($acao === 'faltas') {
    $periodo = _get_periodo_params();
    $dept    = trim($_GET['departamento'] ?? '');

    $sql = "SELECT c.nome, c.cargo, c.departamento,
                   l.data, DATE_FORMAT(l.data,'%d/%m/%Y') as data_fmt,
                   l.tipo_dia, l.observacoes
            FROM rh_ponto_lancamento l
            JOIN rh_colaboradores c ON c.id = l.colaborador_id
            WHERE l.data BETWEEN ? AND ? AND l.tipo_dia IN ('falta','afastamento') AND c.ativo=1";
    $params = [$periodo['data_inicio'], $periodo['data_fim']]; $types = 'ss';
    if ($dept !== '') { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
    $sql .= ' ORDER BY c.nome, l.data';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── Horas extras ─────────────────────────────────────────────────────────────
if ($acao === 'horas_extras') {
    $periodo = _get_periodo_params();
    $dept    = trim($_GET['departamento'] ?? '');

    $sql = "SELECT c.nome, c.cargo, c.departamento,
                   SUM(l.horas_extras_min)       as total_horas_extras_min,
                   SUM(l.horas_trabalhadas_min)   as total_horas_trabalhadas_min
            FROM rh_ponto_lancamento l
            JOIN rh_colaboradores c ON c.id = l.colaborador_id
            WHERE l.data BETWEEN ? AND ? AND l.horas_extras_min > 0 AND c.ativo=1";
    $params = [$periodo['data_inicio'], $periodo['data_fim']]; $types = 'ss';
    if ($dept !== '') { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
    $sql .= ' GROUP BY c.id ORDER BY total_horas_extras_min DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['extras_fmt']      = _min_para_horas((int)$r['total_horas_extras_min']);
        $r['trabalhadas_fmt'] = _min_para_horas((int)$r['total_horas_trabalhadas_min']);
        $list[] = $r;
    }
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── Atrasos ───────────────────────────────────────────────────────────────────
if ($acao === 'atrasos') {
    $periodo = _get_periodo_params();
    $dept    = trim($_GET['departamento'] ?? '');

    $sql = "SELECT c.nome, c.cargo, c.departamento,
                   l.data, DATE_FORMAT(l.data,'%d/%m/%Y') as data_fmt,
                   l.atraso_min,
                   TIME_FORMAT(l.hora_entrada,'%H:%i') as hora_entrada
            FROM rh_ponto_lancamento l
            JOIN rh_colaboradores c ON c.id = l.colaborador_id
            WHERE l.data BETWEEN ? AND ? AND l.atraso_min > 0 AND c.ativo=1";
    $params = [$periodo['data_inicio'], $periodo['data_fim']]; $types = 'ss';
    if ($dept !== '') { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
    $sql .= ' ORDER BY c.nome, l.data';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['atraso_fmt'] = _min_para_horas((int)$r['atraso_min']);
        $list[] = $r;
    }
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── Banco de horas — extrato ledger ──────────────────────────────────────────
if ($acao === 'banco_horas') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) retornar_json(false, 'colaborador_id obrigatório');

    $data_inicio = trim($_GET['data_inicio'] ?? '');
    $data_fim    = trim($_GET['data_fim']    ?? '');

    // Tenta usar a tabela rh_banco_horas (ledger detalhado)
    $tabelaExiste = $conn->query("SHOW TABLES LIKE 'rh_banco_horas'")->num_rows > 0;

    if ($tabelaExiste) {
        $where  = 'WHERE b.colaborador_id = ?';
        $types  = 'i';
        $params = [$colab_id];
        if ($data_inicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) {
            $where .= ' AND b.data >= ?'; $types .= 's'; $params[] = $data_inicio;
        }
        if ($data_fim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
            $where .= ' AND b.data <= ?'; $types .= 's'; $params[] = $data_fim;
        }

        $st = $conn->prepare(
            "SELECT b.id, DATE_FORMAT(b.data,'%d/%m/%Y') AS data_fmt, b.data,
                    b.tipo, b.minutos, b.descricao, b.usuario, b.lancamento_id,
                    DATE_FORMAT(b.criado_em,'%d/%m/%Y %H:%i') AS criado_em_fmt
             FROM rh_banco_horas b $where ORDER BY b.data ASC, b.id ASC"
        );
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();

        $linhas = []; $saldo = 0;
        while ($r = $res->fetch_assoc()) {
            $min = intval($r['minutos']);
            if ($r['tipo'] === 'credito') { $saldo += $min; $r['sinal'] = '+'; }
            else                          { $saldo -= $min; $r['sinal'] = '-'; }
            $r['saldo_corrente']     = $saldo;
            $r['saldo_corrente_fmt'] = _bh_fmt_rel($saldo);
            $r['minutos_fmt']        = _bh_fmt_rel($min);
            $linhas[] = $r;
        }
        $st->close();

        // Saldo global (sem filtro de data) para o card de resumo
        $stSaldo = $conn->prepare(
            "SELECT tipo, SUM(minutos) AS total FROM rh_banco_horas WHERE colaborador_id=? GROUP BY tipo"
        );
        $stSaldo->bind_param('i', $colab_id);
        $stSaldo->execute();
        $resSaldo = $stSaldo->get_result();
        $cred = 0; $deb = 0;
        while ($rs = $resSaldo->fetch_assoc()) {
            if ($rs['tipo'] === 'credito') $cred += intval($rs['total']);
            else                           $deb  += intval($rs['total']);
        }
        $stSaldo->close();
        $saldoGlobal = $cred - $deb;

        fechar_conexao($conn);
        retornar_json(true, 'OK', [
            'modo'               => 'ledger',
            'linhas'             => $linhas,
            'saldo_periodo'      => $saldo,
            'saldo_periodo_fmt'  => _bh_fmt_rel($saldo),
            'saldo_global'       => $saldoGlobal,
            'saldo_global_fmt'   => _bh_fmt_rel($saldoGlobal),
            'total_credito'      => $cred,
            'total_debito'       => $deb,
            'total_credito_fmt'  => _bh_fmt_rel($cred),
            'total_debito_fmt'   => _bh_fmt_rel($deb),
        ]);
    } else {
        // Fallback: agrega por mês a partir de rh_ponto_periodo (comportamento legado)
        $ate_mes = intval($_GET['ate_mes'] ?? date('m'));
        $ate_ano = intval($_GET['ate_ano'] ?? date('Y'));
        $stmt = $conn->prepare(
            "SELECT mes, ano, total_horas_trabalhadas_min, total_horas_extras_min, total_atraso_min
             FROM rh_ponto_periodo
             WHERE colaborador_id=? AND (ano < ? OR (ano=? AND mes <= ?))
             ORDER BY ano ASC, mes ASC"
        );
        $stmt->bind_param('iiii', $colab_id, $ate_ano, $ate_ano, $ate_mes);
        $stmt->execute();
        $list = []; $total = 0;
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $saldo = (int)$r['total_horas_extras_min'] - (int)$r['total_atraso_min'];
            $total += $saldo;
            $r['saldo_min']     = $saldo;
            $r['saldo_fmt']     = ($saldo < 0 ? '-' : '') . _min_para_horas(abs($saldo));
            $r['acumulado_min'] = $total;
            $r['acumulado_fmt'] = ($total < 0 ? '-' : '') . _min_para_horas(abs($total));
            $list[] = $r;
        }
        $stmt->close(); fechar_conexao($conn);
        retornar_json(true, 'OK', [
            'modo'                 => 'agregado',
            'meses'                => $list,
            'total_acumulado_min'  => $total,
            'total_acumulado_fmt'  => ($total < 0 ? '-' : '') . _min_para_horas(abs($total)),
        ]);
    }
}

function _bh_fmt_rel(int $minutos): string {
    $neg = $minutos < 0;
    $abs = abs($minutos);
    return ($neg ? '-' : '') . sprintf('%02d:%02d', intdiv($abs, 60), $abs % 60);
}

// ── Aniversariantes ───────────────────────────────────────────────────────────
if ($acao === 'aniversariantes') {
    $mes = intval($_GET['mes'] ?? date('m'));
    $stmt = $conn->prepare(
        "SELECT nome, cargo, departamento, data_nascimento,
                DATE_FORMAT(data_nascimento,'%d/%m') as aniversario
         FROM rh_colaboradores
         WHERE MONTH(data_nascimento) = ? AND ativo = 1
         ORDER BY DAY(data_nascimento), nome"
    );
    $stmt->bind_param('i', $mes);
    $stmt->execute();
    $list = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida');

function _min_para_horas(?int $min): string {
    if (!$min || $min <= 0) return '00:00';
    $h = intdiv($min, 60);
    $m = $min % 60;
    return sprintf('%02d:%02d', $h, $m);
}
