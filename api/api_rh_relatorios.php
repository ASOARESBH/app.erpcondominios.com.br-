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
require_once __DIR__ . '/helpers/rh_banco_horas_helper.php';
require_once __DIR__ . '/rh_ponto_core.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origensPermitidas = ['https://app.erpcondominios.com.br','http://app.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br'];
$origemLocal = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $origin) === 1;
if ($origin !== '' && (in_array($origin, $origensPermitidas, true) || $origemLocal)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
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
if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conn)) rbacExigir($conn, 'recursos_humanos', 'visualizar', ['registro_tipo'=>'rh_relatorio']);
else verificarPermissao('visualizador');

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
                LEFT JOIN rh_ponto_lancamento l ON l.tenant_id=c.tenant_id AND l.colaborador_id = c.id
                    AND l.data BETWEEN ? AND ?
                WHERE c.tenant_id=? AND c.ativo = 1";
        $params = [$periodo['data_inicio'], $periodo['data_fim'], $tenant_id];
        $types  = 'ssi';
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
                LEFT JOIN rh_ponto_periodo p ON p.tenant_id=c.tenant_id AND p.colaborador_id = c.id AND p.mes = ? AND p.ano = ?
                WHERE c.tenant_id=? AND c.ativo = 1";
        $params = [$periodo['mes'], $periodo['ano'], $tenant_id]; $types = 'iii';
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
        "SELECT c.nome, c.cargo, c.departamento, c.cpf FROM rh_colaboradores c WHERE c.tenant_id=? AND c.id=?"
    );
    $stmt->bind_param('ii', $tenant_id, $colab_id);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$header) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado'); }

    // Busca lançamentos no intervalo
    $stmt2 = $conn->prepare(
        "SELECT DATE_FORMAT(l.data,'%d/%m/%Y') as data_fmt,
                l.data,
                DAYNAME(l.data) as dia_semana,
                TIME_FORMAT(l.hora_entrada,'%H:%i')        as hora_entrada,
                TIME_FORMAT(l.hora_almoco_saida,'%H:%i')   as hora_almoco_saida,
                TIME_FORMAT(l.hora_almoco_retorno,'%H:%i') as hora_almoco_retorno,
                TIME_FORMAT(l.hora_saida,'%H:%i')          as hora_saida,
                l.tipo_dia, l.horas_trabalhadas_min, l.horas_extras_min, l.atraso_min, l.saida_antecipada_min, l.observacoes
         FROM rh_ponto_lancamento l
         WHERE l.tenant_id=? AND l.colaborador_id = ? AND l.data BETWEEN ? AND ?
         ORDER BY l.data"
    );
    $stmt2->bind_param('iiss', $tenant_id, $colab_id, $periodo['data_inicio'], $periodo['data_fim']);
    $escalaRelatorio = _buscar_escala($conn, (int)$colab_id, (int)$tenant_id);
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
        $apuraDia = bh_apurar_dia([
            'extras'=>(int)$r['horas_extras_min'], 'atraso'=>(int)$r['atraso_min'],
            'saida_antecipada'=>(int)($r['saida_antecipada_min']??0),
        ], $r, $escalaRelatorio ?? [], (string)$r['data']);
        $r['limite_extra_diario_min'] = (int)$apuraDia['limite_dia'];
        $r['alerta_extra_limite'] = in_array('EXTRA_ACIMA_LIMITE_DIARIO', $apuraDia['alertas'], true);
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
            JOIN rh_colaboradores c ON c.id = l.colaborador_id AND c.tenant_id=l.tenant_id
            WHERE l.tenant_id=? AND c.tenant_id=? AND l.data BETWEEN ? AND ? AND l.tipo_dia IN ('falta','afastamento') AND c.ativo=1";
    $params = [$tenant_id, $tenant_id, $periodo['data_inicio'], $periodo['data_fim']]; $types = 'iiss';
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
            JOIN rh_colaboradores c ON c.id = l.colaborador_id AND c.tenant_id=l.tenant_id
            WHERE l.tenant_id=? AND c.tenant_id=? AND l.data BETWEEN ? AND ? AND l.horas_extras_min > 0 AND c.ativo=1";
    $params = [$tenant_id, $tenant_id, $periodo['data_inicio'], $periodo['data_fim']]; $types = 'iiss';
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
            JOIN rh_colaboradores c ON c.id = l.colaborador_id AND c.tenant_id=l.tenant_id
            WHERE l.tenant_id=? AND c.tenant_id=? AND l.data BETWEEN ? AND ? AND l.atraso_min > 0 AND c.ativo=1";
    $params = [$tenant_id, $tenant_id, $periodo['data_inicio'], $periodo['data_fim']]; $types = 'iiss';
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
    $rawColab = $_GET['colaborador_id'] ?? '';
    $rawDepartamento = $_GET['departamento'] ?? '';
    if (!is_string($rawColab) || !is_string($rawDepartamento)) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'Filtro de colaborador ou departamento inválido.'); }
    $parsedColab = $rawColab === '' ? 0 : filter_var($rawColab, FILTER_VALIDATE_INT);
    $colab_id = $parsedColab === false ? -1 : (int)$parsedColab;
    $departamento = trim($rawDepartamento);
    if ($colab_id < 0 || bh_tamanho_texto($departamento) > 100) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'Filtro de colaborador ou departamento inválido.'); }
    if ($colab_id <= 0 && $departamento === '') { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'Selecione um colaborador ou departamento.'); }

    try {
        bh_garantir_tabela($conn);
    } catch (Throwable $e) {
        error_log('[RH Relatórios] Schema de Banco de Horas indisponível; tenant=' . (int)$tenant_id . '; erro=' . $e->getMessage());
        fechar_conexao($conn);
        http_response_code(503);
        retornar_json(false, 'Banco de Horas temporariamente indisponível.');
    }

    if ($colab_id > 0) {
        try {
            $colaboradorNoTenant = bh_colaborador_pertence_tenant($conn, (int)$tenant_id, $colab_id);
        } catch (Throwable $e) {
            error_log('[RH Relatórios] Falha ao validar colaborador Banco de Horas; tenant=' . (int)$tenant_id . '; erro=' . $e->getMessage());
            fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível validar o colaborador.');
        }
        if (!$colaboradorNoTenant) {
            fechar_conexao($conn);
            http_response_code(404);
            retornar_json(false, 'Colaborador não encontrado.');
        }
    }

    $rawInicio = $_GET['data_inicio'] ?? '';
    $rawFim = $_GET['data_fim'] ?? '';
    if (!is_string($rawInicio) || !is_string($rawFim)) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'Período de datas inválido.'); }
    $data_inicio = trim($rawInicio);
    $data_fim    = trim($rawFim);
    $dataValida = static function (string $valor): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) return false;
        $dt = DateTime::createFromFormat('!Y-m-d', $valor);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $valor;
    };
    if (($data_inicio !== '' && !$dataValida($data_inicio)) || ($data_fim !== '' && !$dataValida($data_fim)) ||
        ($data_inicio !== '' && $data_fim !== '' && $data_inicio > $data_fim)) {
        fechar_conexao($conn);
        http_response_code(422);
        retornar_json(false, 'Período de datas inválido.');
    }

    if ($colab_id <= 0) {
        if ($data_inicio === '' || $data_fim === '') { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'O relatório departamental exige período completo.'); }
        $st = $conn->prepare(
            "SELECT c.id AS colaborador_id,c.nome,c.departamento,
                    COALESCE(SUM(CASE WHEN b.tipo='credito' THEN b.minutos ELSE 0 END),0) credito_periodo,
                    COALESCE(SUM(CASE WHEN b.tipo IN ('debito','abatimento','pagamento') THEN b.minutos ELSE 0 END),0) debito_periodo,
                    COALESCE(SUM(CASE WHEN b.tipo='excedente' THEN b.minutos ELSE 0 END),0) excedente_periodo,
                    COALESCE(SUM(CASE WHEN b.tipo='credito' THEN b.minutos WHEN b.tipo IN ('debito','abatimento','pagamento') THEN -b.minutos ELSE 0 END),0) saldo_periodo,
                    COALESCE((SELECT SUM(CASE WHEN g.tipo='credito' THEN g.minutos WHEN g.tipo IN ('debito','abatimento','pagamento') THEN -g.minutos ELSE 0 END)
                              FROM rh_banco_horas g WHERE g.tenant_id=c.tenant_id AND g.colaborador_id=c.id
                              AND g.tipo IN ('credito','debito','abatimento','pagamento')),0) saldo_global
             FROM rh_colaboradores c
             LEFT JOIN rh_banco_horas b ON b.tenant_id=c.tenant_id AND b.colaborador_id=c.id AND b.data BETWEEN ? AND ?
             WHERE c.tenant_id=? AND c.ativo=1 AND c.departamento=?
               AND EXISTS (SELECT 1 FROM rh_escala e WHERE e.tenant_id=c.tenant_id AND e.colaborador_id=c.id AND e.ativo=1 AND e.banco_horas_ativo=1)
             GROUP BY c.id,c.tenant_id,c.nome,c.departamento ORDER BY c.nome,c.id"
        );
        if (!$st) { error_log('[RH Relatórios] Falha ao preparar resumo departamental; tenant=' . (int)$tenant_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível gerar o relatório de Banco de Horas.'); }
        $st->bind_param('ssis', $data_inicio, $data_fim, $tenant_id, $departamento);
        if (!$st->execute()) { error_log('[RH Relatórios] Falha no resumo departamental; tenant=' . (int)$tenant_id . '; errno=' . (int)$st->errno); $st->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível gerar o relatório de Banco de Horas.'); }
        $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();$totais=['credito_periodo'=>0,'debito_periodo'=>0,'excedente_periodo'=>0,'saldo_periodo'=>0,'saldo_global'=>0];
        foreach($rows as &$row){foreach($totais as $key=>$value)$totais[$key]+=(int)$row[$key];foreach(array_keys($totais) as $key)$row[$key.'_fmt']=bh_fmt((int)$row[$key]);}unset($row);foreach(array_keys($totais) as $key)$totais[$key.'_fmt']=bh_fmt($totais[$key]);
        fechar_conexao($conn); retornar_json(true,'OK',['modo'=>'departamento','departamento'=>$departamento,'data_inicio'=>$data_inicio,'data_fim'=>$data_fim,'colaboradores'=>$rows,'totais'=>$totais]);
    }

    // Tenta usar a tabela rh_banco_horas (ledger detalhado)
    $tabelaExiste = $conn->query("SHOW TABLES LIKE 'rh_banco_horas'")->num_rows > 0;

    if ($tabelaExiste) {
        $where  = 'WHERE b.tenant_id = ? AND b.colaborador_id = ?';
        if ($data_inicio !== '') $where .= ' AND b.data >= ?';
        if ($data_fim !== '') $where .= ' AND b.data <= ?';

        $st = $conn->prepare(
            "SELECT b.id, DATE_FORMAT(b.data,'%d/%m/%Y') AS data_fmt, b.data,
                    b.tipo, b.minutos, b.descricao, b.usuario, b.usuario_id, b.lancamento_id,
                    b.saldo_anterior_min, b.saldo_posterior_min,
                    b.saldo_negativo_confirmado, b.justificativa_saldo_negativo,
                    b.minutos_apurados,b.limite_aplicado_min,b.regra_aplicada,b.versao_calculo,b.reprocessamento_id,
                    DATE_FORMAT(b.criado_em,'%d/%m/%Y %H:%i') AS criado_em_fmt
             FROM rh_banco_horas b $where ORDER BY b.data ASC, b.id ASC"
        );
        if ($data_inicio !== '' && $data_fim !== '') $st->bind_param('iiss', $tenant_id, $colab_id, $data_inicio, $data_fim);
        elseif ($data_inicio !== '') $st->bind_param('iis', $tenant_id, $colab_id, $data_inicio);
        elseif ($data_fim !== '') $st->bind_param('iis', $tenant_id, $colab_id, $data_fim);
        else $st->bind_param('ii', $tenant_id, $colab_id);
        if (!$st->execute()) {
            error_log('[RH Relatórios] Falha ao consultar Banco de Horas; tenant=' . (int)$tenant_id . '; errno=' . (int)$st->errno);
            $st->close(); fechar_conexao($conn); http_response_code(500);
            retornar_json(false, 'Não foi possível gerar o relatório de Banco de Horas.');
        }
        $res = $st->get_result();

        $linhas = []; $saldo = 0;
        while ($r = $res->fetch_assoc()) {
            $min = intval($r['minutos']);
            $sinal = bh_sinal((string)($r['tipo'] ?? ''));
            if ($sinal !== null) $saldo += $sinal * $min;
            $r['tipo_conhecido'] = $sinal !== null;
            $r['sinal'] = $sinal === null ? '' : ($sinal > 0 ? '+' : '-');
            $r['saldo_corrente']     = $saldo;
            $r['saldo_corrente_fmt'] = bh_fmt($saldo);
            $r['minutos_fmt']        = bh_fmt($min);
            $linhas[] = $r;
        }
        $st->close();

        // Saldo global (sem filtro de data) para o card de resumo
        $totaisGlobal = bh_obter_saldo($conn, (int)$tenant_id, $colab_id);
        $cred = $totaisGlobal['credito'];
        $deb = $totaisGlobal['debito'];
        $saldoGlobal = $totaisGlobal['saldo'];

        fechar_conexao($conn);
        retornar_json(true, 'OK', [
            'modo'               => 'ledger',
            'linhas'             => $linhas,
            'saldo_periodo'      => $saldo,
            'saldo_periodo_fmt'  => bh_fmt($saldo),
            'saldo_global'       => $saldoGlobal,
            'saldo_global_fmt'   => bh_fmt($saldoGlobal),
            'total_credito'      => $cred,
            'total_debito'       => $deb,
            'total_credito_fmt'  => bh_fmt($cred),
            'total_debito_fmt'   => bh_fmt($deb),
        ]);
    } else {
        // Fallback: agrega por mês a partir de rh_ponto_periodo (comportamento legado)
        $ate_mes = intval($_GET['ate_mes'] ?? date('m'));
        $ate_ano = intval($_GET['ate_ano'] ?? date('Y'));
        $stmt = $conn->prepare(
            "SELECT mes, ano, total_horas_trabalhadas_min, total_horas_extras_min, total_atraso_min
             FROM rh_ponto_periodo
             WHERE tenant_id=? AND colaborador_id=? AND (ano < ? OR (ano=? AND mes <= ?))
             ORDER BY ano ASC, mes ASC"
        );
        $stmt->bind_param('iiiii', $tenant_id, $colab_id, $ate_ano, $ate_ano, $ate_mes);
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

// ── Aniversariantes ───────────────────────────────────────────────────────────
if ($acao === 'aniversariantes') {
    $mes = intval($_GET['mes'] ?? date('m'));
    $stmt = $conn->prepare(
        "SELECT nome, cargo, departamento, data_nascimento,
                DATE_FORMAT(data_nascimento,'%d/%m') as aniversario
         FROM rh_colaboradores
         WHERE tenant_id=? AND MONTH(data_nascimento) = ? AND ativo = 1
         ORDER BY DAY(data_nascimento), nome"
    );
    $stmt->bind_param('ii', $tenant_id, $mes);
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
