<?php
// =====================================================
// API: RH — REGISTRO DE PONTO
// =====================================================
// Períodos:
//   GET  ?acao=listar_periodos&colaborador_id=N
//   GET  ?acao=obter_periodo&id=N
//   POST ?acao=criar_periodo  {colaborador_id, mes, ano}
//   POST ?acao=fechar_periodo&id=N
//   POST ?acao=reabrir_periodo&id=N
//   DELETE ?acao=excluir_periodo&id=N
//
// Lançamentos:
//   GET  ?acao=listar_lancamentos&periodo_id=N
//   POST ?acao=salvar_lancamento  {periodo_id, colaborador_id, data, hora_*, tipo_dia, obs}
//   DELETE ?acao=excluir_lancamento&id=N

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'error_logger.php';
require_once 'rh_ponto_core.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed = ['https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

try { verificarAutenticacao(true, 'operador'); }
catch (Exception $e) { retornar_json(false, 'Não autenticado'); }

$metodo = $_SERVER['REQUEST_METHOD'];
$body   = ($metodo !== 'GET') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$acao   = $_GET['acao'] ?? $body['acao'] ?? '';
$conn   = conectar_banco();
if (!$conn) retornar_json(false, 'Erro ao conectar ao banco');

// Auto-migração aditiva: coluna de saída antecipada (lançamento + agregado do período)
_ponto_garantir_colunas($conn);

// ── PERÍODOS ──────────────────────────────────────────────────────────────────

if ($acao === 'listar_periodos') {
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    if ($colab_id <= 0) retornar_json(false, 'colaborador_id obrigatório');

    $stmt = $conn->prepare(
        "SELECT p.*, c.nome as colaborador_nome
         FROM rh_ponto_periodo p
         JOIN rh_colaboradores c ON c.id = p.colaborador_id
         WHERE p.colaborador_id = ?
         ORDER BY p.ano DESC, p.mes DESC"
    );
    $stmt->bind_param('i', $colab_id);
    $stmt->execute();
    $list = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

if ($acao === 'obter_periodo') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare(
        "SELECT p.*, c.nome as colaborador_nome, c.cargo, c.departamento
         FROM rh_ponto_periodo p
         JOIN rh_colaboradores c ON c.id = p.colaborador_id
         WHERE p.id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { fechar_conexao($conn); retornar_json(false, 'Período não encontrado'); }
    fechar_conexao($conn);
    retornar_json(true, 'OK', $row);
}

if ($acao === 'criar_periodo' && $metodo === 'POST') {
    $colab_id = intval($body['colaborador_id'] ?? 0);
    $mes      = intval($body['mes'] ?? 0);
    $ano      = intval($body['ano'] ?? 0);
    if ($colab_id <= 0 || $mes < 1 || $mes > 12 || $ano < 2000) retornar_json(false, 'Dados inválidos');

    // Verifica se já existe
    $chk = $conn->prepare("SELECT id FROM rh_ponto_periodo WHERE colaborador_id=? AND mes=? AND ano=?");
    $chk->bind_param('iii', $colab_id, $mes, $ano); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); fechar_conexao($conn); retornar_json(false, 'Período já existe para este colaborador'); }
    $chk->close();

    $stmt = $conn->prepare("INSERT INTO rh_ponto_periodo (colaborador_id, mes, ano) VALUES (?,?,?)");
    $stmt->bind_param('iii', $colab_id, $mes, $ano);
    if (!$stmt->execute()) { $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao criar período'); }
    $novo_id = $conn->insert_id;
    $stmt->close();

    // Pré-preenche dias úteis do mês com tipo 'normal' (sem horários)
    _gerar_dias_mes($conn, $novo_id, $colab_id, $mes, $ano);

    fechar_conexao($conn);
    retornar_json(true, 'Período criado', ['id' => $novo_id]);
}

if ($acao === 'fechar_periodo' && $metodo === 'POST') {
    $id = intval($_GET['id'] ?? $body['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    // Recalcular totais antes de fechar
    _recalcular_totais($conn, $id);

    $stmt = $conn->prepare("UPDATE rh_ponto_periodo SET status='fechado' WHERE id=?");
    $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
    fechar_conexao($conn);
    retornar_json(true, 'Período fechado');
}

if ($acao === 'reabrir_periodo' && $metodo === 'POST') {
    $id = intval($_GET['id'] ?? $body['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("UPDATE rh_ponto_periodo SET status='aberto' WHERE id=?");
    $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
    fechar_conexao($conn);
    retornar_json(true, 'Período reaberto');
}

if ($acao === 'excluir_periodo' && $metodo === 'DELETE') {
    $body2 = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = intval($body2['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("DELETE FROM rh_ponto_periodo WHERE id=?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Período excluído' : 'Erro ao excluir');
}

// ── LANÇAMENTOS ───────────────────────────────────────────────────────────────

// Endpoint para período personalizado que pode cruzar múltiplos meses:
// GET ?acao=listar_lancamentos_por_colaborador&colaborador_id=N&data_inicio=YYYY-MM-DD&data_fim=YYYY-MM-DD
if ($acao === 'listar_lancamentos_por_colaborador') {
    $colab_id    = intval($_GET['colaborador_id'] ?? 0);
    $data_inicio = trim($_GET['data_inicio'] ?? '');
    $data_fim    = trim($_GET['data_fim']    ?? '');

    if ($colab_id <= 0)  retornar_json(false, 'colaborador_id obrigatório');
    if (!$data_inicio || !$data_fim) retornar_json(false, 'data_inicio e data_fim são obrigatórios');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
        retornar_json(false, 'Formato de data inválido (esperado YYYY-MM-DD)');
    }
    if ($data_inicio > $data_fim) retornar_json(false, 'data_inicio não pode ser maior que data_fim');

    $stmt = $conn->prepare(
        "SELECT l.*,
                p.status as periodo_status,
                TIME_FORMAT(l.hora_entrada, '%H:%i')        as he,
                TIME_FORMAT(l.hora_almoco_saida, '%H:%i')   as has,
                TIME_FORMAT(l.hora_almoco_retorno, '%H:%i') as har,
                TIME_FORMAT(l.hora_saida, '%H:%i')          as hs,
                DATE_FORMAT(l.data, '%d/%m/%Y')             as data_fmt,
                DAYNAME(l.data)                             as dia_semana
         FROM rh_ponto_lancamento l
         JOIN rh_ponto_periodo p ON p.id = l.periodo_id
         WHERE l.colaborador_id = ?
           AND l.data BETWEEN ? AND ?
         ORDER BY l.data ASC"
    );
    $stmt->bind_param('iss', $colab_id, $data_inicio, $data_fim);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

if ($acao === 'listar_lancamentos') {
    $periodo_id  = intval($_GET['periodo_id'] ?? 0);
    if ($periodo_id <= 0) retornar_json(false, 'periodo_id obrigatório');

    // Filtro opcional de período personalizado (data_inicio / data_fim)
    $data_inicio = trim($_GET['data_inicio'] ?? '');
    $data_fim    = trim($_GET['data_fim']    ?? '');

    // Valida formato YYYY-MM-DD quando informado
    $usar_filtro_data = false;
    if ($data_inicio !== '' && $data_fim !== '') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)   &&
            $data_inicio <= $data_fim) {
            $usar_filtro_data = true;
        }
    }

    if ($usar_filtro_data) {
        // Período personalizado: filtra lançamentos dentro do intervalo de datas
        $stmt = $conn->prepare(
            "SELECT l.*,
                    TIME_FORMAT(l.hora_entrada, '%H:%i')        as he,
                    TIME_FORMAT(l.hora_almoco_saida, '%H:%i')   as has,
                    TIME_FORMAT(l.hora_almoco_retorno, '%H:%i') as har,
                    TIME_FORMAT(l.hora_saida, '%H:%i')          as hs,
                    DATE_FORMAT(l.data, '%d/%m/%Y')             as data_fmt,
                    DAYNAME(l.data)                             as dia_semana
             FROM rh_ponto_lancamento l
             WHERE l.periodo_id = ?
               AND l.data BETWEEN ? AND ?
             ORDER BY l.data ASC"
        );
        $stmt->bind_param('iss', $periodo_id, $data_inicio, $data_fim);
    } else {
        // Período mensal: retorna todos os lançamentos do período
        $stmt = $conn->prepare(
            "SELECT l.*,
                    TIME_FORMAT(l.hora_entrada, '%H:%i')        as he,
                    TIME_FORMAT(l.hora_almoco_saida, '%H:%i')   as has,
                    TIME_FORMAT(l.hora_almoco_retorno, '%H:%i') as har,
                    TIME_FORMAT(l.hora_saida, '%H:%i')          as hs,
                    DATE_FORMAT(l.data, '%d/%m/%Y')             as data_fmt,
                    DAYNAME(l.data)                             as dia_semana
             FROM rh_ponto_lancamento l
             WHERE l.periodo_id = ?
             ORDER BY l.data ASC"
        );
        $stmt->bind_param('i', $periodo_id);
    }

    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

if ($acao === 'salvar_lancamento' && $metodo === 'POST') {
    $periodo_id    = intval($body['periodo_id'] ?? 0);
    $colab_id      = intval($body['colaborador_id'] ?? 0);
    $data          = $body['data'] ?? '';
    $tipo_dia      = $body['tipo_dia'] ?? 'normal';
    $he            = _hora_valida($body['hora_entrada']        ?? '');
    $has           = _hora_valida($body['hora_almoco_saida']   ?? '');
    $har           = _hora_valida($body['hora_almoco_retorno'] ?? '');
    $hs            = _hora_valida($body['hora_saida']          ?? '');
    $obs           = trim($body['observacoes'] ?? '');

    if ($periodo_id <= 0 || $colab_id <= 0 || empty($data)) retornar_json(false, 'Dados obrigatórios incompletos');

    // Buscar escala para calcular (a resolução por dia-da-semana, inclusive para
    // escala_manual, acontece dentro de _calcular_minutos via _obter_parametros_jornada_dia)
    $escala = _buscar_escala($conn, $colab_id);
    $calc   = _calcular_minutos($he, $has, $har, $hs, $tipo_dia, $escala, $data);

    // Verificar se já existe lançamento para esta data/período (UPSERT)
    $existe = $conn->prepare("SELECT id FROM rh_ponto_lancamento WHERE periodo_id=? AND data=?");
    $existe->bind_param('is', $periodo_id, $data); $existe->execute();
    $existe->store_result();
    $is_update = $existe->num_rows > 0;
    $existe->close();

    if ($is_update) {
        $stmt = $conn->prepare(
            "UPDATE rh_ponto_lancamento SET
             hora_entrada=?,hora_almoco_saida=?,hora_almoco_retorno=?,hora_saida=?,
             tipo_dia=?,horas_trabalhadas_min=?,horas_extras_min=?,atraso_min=?,saida_antecipada_min=?,observacoes=?
             WHERE periodo_id=? AND data=?"
        );
        $stmt->bind_param('sssssiiiisis',
            $he,$has,$har,$hs,$tipo_dia,
            $calc['trabalhadas'],$calc['extras'],$calc['atraso'],$calc['saida_antecipada'],$obs,
            $periodo_id,$data
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO rh_ponto_lancamento
             (periodo_id,colaborador_id,data,hora_entrada,hora_almoco_saida,hora_almoco_retorno,hora_saida,
              tipo_dia,horas_trabalhadas_min,horas_extras_min,atraso_min,saida_antecipada_min,observacoes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('iissssssiiiis',
            $periodo_id,$colab_id,$data,$he,$has,$har,$hs,
            $tipo_dia,$calc['trabalhadas'],$calc['extras'],$calc['atraso'],$calc['saida_antecipada'],$obs
        );
    }

    if (!$stmt->execute()) { $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao salvar lançamento: ' . $conn->error); }
    // Captura o ID do lançamento (INSERT usa insert_id; UPDATE busca pelo periodo+data)
    $lancamento_id_bh = 0;
    if (!$is_update) {
        $lancamento_id_bh = intval($conn->insert_id);
    } else {
        $ridRow = $conn->query("SELECT id FROM rh_ponto_lancamento WHERE periodo_id=$periodo_id AND data='$data' LIMIT 1");
        if ($ridRow) { $ridR = $ridRow->fetch_assoc(); $lancamento_id_bh = intval($ridR['id'] ?? 0); }
    }
    $stmt->close();

    if ($lancamento_id_bh > 0) {
        _bh_sincronizar($conn, $lancamento_id_bh, $colab_id, $data, $calc, $escala);
        _dsr_sincronizar($conn, $colab_id, $data, $escala);
    }
    _recalcular_totais($conn, $periodo_id);

    // Cross-period: se este lançamento é só de saída (turno noturno que começou no mês anterior),
    // busca o dia anterior do mesmo colaborador e recalcula as horas daquele dia.
    if (!$he && $hs) {
        $prevDate = date('Y-m-d', strtotime($data . ' -1 day'));
        $sp = $conn->prepare(
            "SELECT id, hora_entrada, hora_almoco_saida, hora_almoco_retorno, hora_saida, tipo_dia, periodo_id
             FROM rh_ponto_lancamento WHERE colaborador_id=? AND data=? LIMIT 1"
        );
        $sp->bind_param('is', $colab_id, $prevDate);
        $sp->execute();
        $prevRow = $sp->get_result()->fetch_assoc();
        $sp->close();

        if ($prevRow && $prevRow['hora_entrada'] && !$prevRow['hora_saida']) {
            $prevCalc = _calcular_minutos_par(
                $prevRow['hora_entrada'],
                $prevRow['hora_almoco_saida'] ?: null,
                $prevRow['hora_almoco_retorno'] ?: null,
                $hs,
                $prevRow['tipo_dia'],
                $escala,
                $prevDate
            );
            $prevRowId = intval($prevRow['id']);
            $su = $conn->prepare("UPDATE rh_ponto_lancamento SET horas_trabalhadas_min=?, horas_extras_min=?, atraso_min=?, saida_antecipada_min=? WHERE id=?");
            $su->bind_param('iiiii', $prevCalc['trabalhadas'], $prevCalc['extras'], $prevCalc['atraso'], $prevCalc['saida_antecipada'], $prevRowId);
            $su->execute();
            $su->close();
            _recalcular_totais($conn, intval($prevRow['periodo_id']));
            _bh_sincronizar($conn, intval($prevRow['id']), $colab_id, $prevDate, $prevCalc, $escala);
        }
    }

    fechar_conexao($conn);
    retornar_json(true, 'Lançamento salvo', $calc);
}

if ($acao === 'excluir_lancamento' && $metodo === 'DELETE') {
    $body2      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id         = intval($body2['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    // Pega periodo_id antes de excluir
    $p = $conn->prepare("SELECT periodo_id FROM rh_ponto_lancamento WHERE id=?");
    $p->bind_param('i',$id); $p->execute();
    $pr = $p->get_result()->fetch_assoc(); $p->close();

    $stmt = $conn->prepare("DELETE FROM rh_ponto_lancamento WHERE id=?");
    $stmt->bind_param('i',$id); $ok = $stmt->execute(); $stmt->close();

    if ($ok && $pr) _recalcular_totais($conn, $pr['periodo_id']);
    fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Lançamento excluído' : 'Erro ao excluir');
}

// ── RECALCULAR TODOS OS LANÇAMENTOS DO PERÍODO ───────────────────────────────
// POST ?acao=recalcular_lancamentos&periodo_id=N
// Recalcula horas_trabalhadas_min / horas_extras_min / atraso_min de cada linha
// com base nos horários brutos, tratando turnos que cruzam meia-noite.
// Para escalas 12x36 com linhas divididas (entrada num dia, saída no seguinte),
// emparelha automaticamente linhas consecutivas.
if ($acao === 'recalcular_lancamentos' && $metodo === 'POST') {
    $periodo_id = intval($_GET['periodo_id'] ?? $body['periodo_id'] ?? 0);
    if ($periodo_id <= 0) retornar_json(false, 'periodo_id obrigatório');

    $rowP = $conn->query("SELECT colaborador_id FROM rh_ponto_periodo WHERE id=$periodo_id")->fetch_assoc();
    if (!$rowP) { fechar_conexao($conn); retornar_json(false, 'Período não encontrado'); }
    $escala = _buscar_escala($conn, intval($rowP['colaborador_id']));

    $res = $conn->query(
        "SELECT id, data, hora_entrada, hora_almoco_saida, hora_almoco_retorno, hora_saida, tipo_dia
         FROM rh_ponto_lancamento WHERE periodo_id = $periodo_id ORDER BY data ASC"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    $stmtUpd = $conn->prepare(
        "UPDATE rh_ponto_lancamento SET horas_trabalhadas_min=?, horas_extras_min=?, atraso_min=?, saida_antecipada_min=? WHERE id=?"
    );

    $atualizados = 0;
    $n = count($rows);
    $i = 0;
    while ($i < $n) {
        $row  = $rows[$i];
        $he   = $row['hora_entrada']        ?: null;
        $hs   = $row['hora_saida']           ?: null;
        $has  = $row['hora_almoco_saida']   ?: null;
        $har  = $row['hora_almoco_retorno'] ?: null;
        $tipo = $row['tipo_dia'];
        $id   = intval($row['id']);

        $calc      = null;
        $skipNext  = false;
        $rowData   = $row['data'] ?? '';

        if ($he && $hs) {
            // Linha completa: entrada e saída na mesma data (mesmo com cruzamento de meia-noite)
            $calc = _calcular_minutos($he, $has, $har, $hs, $tipo, $escala, $rowData);
        } elseif ($he && !$hs && ($i + 1) < $n) {
            // Linha de entrada sem saída: verifica se a próxima tem somente saída (par 12x36)
            $next    = $rows[$i + 1];
            $he_next = $next['hora_entrada'] ?: null;
            $hs_next = $next['hora_saida']   ?: null;
            if ($hs_next && !$he_next) {
                $calc = _calcular_minutos_par(
                    $he, $has, $har,
                    $hs_next,
                    $tipo, $escala, $rowData
                );
                // Linha "de chegada" fica zerada (é apenas o marcador de término)
                $z = 0;
                $stmtUpd->bind_param('iiiii', $z, $z, $z, $z, $next['id']);
                $stmtUpd->execute();
                $atualizados++;
                $skipNext = true;
            }
        }

        if ($calc !== null) {
            $stmtUpd->bind_param('iiiii', $calc['trabalhadas'], $calc['extras'], $calc['atraso'], $calc['saida_antecipada'], $id);
            $stmtUpd->execute();
            $atualizados++;
            _bh_sincronizar($conn, $id, intval($rowP['colaborador_id']), $rowData, $calc, $escala);
        }

        $i += $skipNext ? 2 : 1;
    }

    // Cross-period: verifica se o último lançamento do período tem entrada mas não tem saída
    // (turno noturno que termina no mês seguinte). Busca o primeiro registro do dia seguinte
    // em qualquer período do mesmo colaborador e emparelha.
    if ($n > 0) {
        $lastRow  = $rows[$n - 1];
        $lastHe   = $lastRow['hora_entrada'] ?: null;
        $lastHs   = $lastRow['hora_saida']   ?: null;
        $lastId   = intval($lastRow['id']);
        $lastTipo = $lastRow['tipo_dia'];

        if ($lastHe && !$lastHs) {
            $colabId = intval($rowP['colaborador_id']);
            $nextQ   = $conn->prepare(
                "SELECT id, hora_entrada, hora_saida, hora_almoco_saida, hora_almoco_retorno, tipo_dia, periodo_id
                 FROM rh_ponto_lancamento
                 WHERE colaborador_id = ?
                   AND data = DATE_ADD((SELECT data FROM rh_ponto_lancamento WHERE id = ?), INTERVAL 1 DAY)
                 LIMIT 1"
            );
            $nextQ->bind_param('ii', $colabId, $lastId);
            $nextQ->execute();
            $nextRow = $nextQ->get_result()->fetch_assoc();
            $nextQ->close();

            if ($nextRow) {
                $nextHe = $nextRow['hora_entrada'] ?: null;
                $nextHs = $nextRow['hora_saida']   ?: null;
                $nextId = intval($nextRow['id']);

                if ($nextHs && !$nextHe) {
                    $calcCross = _calcular_minutos_par(
                        $lastHe,
                        $lastRow['hora_almoco_saida']    ?: null,
                        $lastRow['hora_almoco_retorno']  ?: null,
                        $nextHs,
                        $lastTipo,
                        $escala,
                        $lastRow['data'] ?? ''
                    );
                    $stmtUpd->bind_param('iiiii', $calcCross['trabalhadas'], $calcCross['extras'], $calcCross['atraso'], $calcCross['saida_antecipada'], $lastId);
                    $stmtUpd->execute();
                    _bh_sincronizar($conn, $lastId, $colabId, $lastRow['data'] ?? '', $calcCross, $escala);
                    // O marcador de término do mês seguinte fica zerado (apenas referência)
                    $z = 0;
                    $stmtUpd->bind_param('iiiii', $z, $z, $z, $z, $nextId);
                    $stmtUpd->execute();
                    $atualizados += 2;
                    // Atualiza totais do período do mês seguinte para manter consistência
                    _recalcular_totais($conn, intval($nextRow['periodo_id']));
                }
            }
        }
    }

    $stmtUpd->close();

    // Sincroniza DSR para todas as semanas do período
    // Usa uma representante por semana ISO para evitar chamadas duplicadas
    $semanasProcessadas = [];
    foreach ($rows as $row) {
        $semKey = date('o-W', strtotime($row['data'])); // ex: 2025-04
        if (!isset($semanasProcessadas[$semKey])) {
            $semanasProcessadas[$semKey] = true;
            _dsr_sincronizar($conn, intval($rowP['colaborador_id']), $row['data'], $escala);
        }
    }

    _recalcular_totais($conn, $periodo_id);
    fechar_conexao($conn);
    retornar_json(true, 'Recalculado com sucesso.', ['atualizados' => $atualizados]);
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida');

// ── HELPERS ───────────────────────────────────────────────────────────────────
// _hora_valida, _hora_em_minutos, _buscar_escala, _calcular_minutos,
// _calcular_minutos_par, _recalcular_totais e _bh_sincronizar agora vivem em
// rh_ponto_core.php (compartilhadas com api_rh_abono.php) — ver require_once
// no topo deste arquivo.

function _gerar_dias_mes($conn, int $periodo_id, int $colab_id, int $mes, int $ano): void {
    $dias   = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
    $escala = _buscar_escala($conn, $colab_id);

    // Mapa de dia da semana PHP (0=Dom..6=Sab) para chaves do sistema
    $mapDia = [0=>'dom',1=>'seg',2=>'ter',3=>'qua',4=>'qui',5=>'sex',6=>'sab'];

    // Dias fixos de trabalho (escala normal/controle_jornada)
    $diasTrabalho = [];
    if ($escala && !empty($escala['dias_trabalho'])) {
        $diasTrabalho = json_decode($escala['dias_trabalho'], true) ?? [];
    }

    // Escala manual: usa configuração por dia da semana (via helper unificado)
    $isManual = $escala && ($escala['tipo'] === 'escala_manual');

    // Configuração de escala alternada
    $isAlternada    = $escala && ($escala['tipo'] === 'alternada' || !empty($escala['alternada_ativa']));
    $semanaA        = [];
    $semanaB        = [];
    $diaInicioTs    = null;
    $tipoFolga      = 'folga';
    if ($isAlternada) {
        $semanaA     = json_decode($escala['alternada_semana_a'] ?? '[]', true) ?? [];
        $semanaB     = json_decode($escala['alternada_semana_b'] ?? '[]', true) ?? [];
        $tipoFolga   = $escala['alternada_tipo_folga'] ?? 'folga';
        if (!empty($escala['alternada_dia_inicio'])) {
            $diaInicioTs = strtotime($escala['alternada_dia_inicio']);
        }
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO rh_ponto_lancamento (periodo_id, colaborador_id, data, tipo_dia)
         VALUES (?, ?, ?, ?)"
    );

    for ($d = 1; $d <= $dias; $d++) {
        $data    = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
        $dataTs  = strtotime($data);
        $diaSem  = $mapDia[intval(date('w', $dataTs))]; // seg, ter, ...
        $tipoDia = 'normal';

        if ($isAlternada && !empty($semanaA) && !empty($semanaB)) {
            // Calcula qual semana (A ou B) corresponde a esta data
            // Usa segunda-feira da semana como referência
            $refTs   = $diaInicioTs ?? strtotime('monday this week', $dataTs);
            // Número de semanas desde o dia de início
            $diffSec = $dataTs - $refTs;
            $diffSem = intval(floor($diffSec / (7 * 86400)));
            // Semanas negativas: ajustar para ciclo positivo
            if ($diffSem < 0) $diffSem = (abs($diffSem) % 2 === 0) ? 0 : 1;
            $isSemanaA = ($diffSem % 2 === 0);
            $diasAtivos = $isSemanaA ? $semanaA : $semanaB;

            if (in_array($diaSem, $diasAtivos)) {
                $tipoDia = 'normal';
            } else {
                $tipoDia = $tipoFolga; // folga, falta ou feriado conforme configurado
            }
        } elseif ($isManual) {
            // Escala manual: dia ativo conforme configuração por dia da semana
            $tipoDia = _escala_manual_dia($escala, $data)['ativo'] ? 'normal' : 'folga';
        } elseif (!empty($diasTrabalho)) {
            // Escala normal: dias não listados são folga
            $tipoDia = in_array($diaSem, $diasTrabalho) ? 'normal' : 'folga';
        }

        $stmt->bind_param('iiss', $periodo_id, $colab_id, $data, $tipoDia);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Sincroniza o DSR (Descanso Semanal Remunerado) para a semana da data informada.
 *
 * Regra CLT (Art. 7° Lei 605/49):
 *   • Colaborador que trabalha todos os dias úteis da semana sem falta injustificada
 *     GANHA o DSR → domingo permanece como 'folga' (pago, sem débito no banco de horas).
 *   • Colaborador que tem falta injustificada em qualquer dia útil da semana
 *     PERDE o DSR → domingo vira 'falta' com débito de (carga_horaria_mensal_min ÷ 30) no banco de horas.
 *
 * Aplica-se a: jornada_44h (Seg-Sáb → Dom DSR)
 *              jornada_40h (Seg-Sex → Dom DSR; Sáb é folga sem DSR)
 *              jornada_36h (Seg-Sáb → Dom DSR)
 */
function _dsr_sincronizar(
    mysqli $conn,
    int $colab_id,
    string $data,
    ?array $escala
): void {
    if (!$escala || empty($escala['banco_horas_ativo'])) return;

    $tipo = $escala['tipo'] ?? '';
    // Divisores mensais oficiais MTE por tipo (minutos)
    $mensalPadrao = ['jornada_44h' => 13200, 'jornada_40h' => 12000, 'jornada_36h' => 10800];
    if (!isset($mensalPadrao[$tipo])) return;
    // DSR perdido = valor de 1 dia no divisor mensal (mensal ÷ 30 dias calendário)
    // jornada_44h: 13200÷30 = 440 min; jornada_40h: 12000÷30 = 400 min; jornada_36h: 10800÷30 = 360 min
    // Isso é DIFERENTE da carga_horaria_diaria_min (que reflete o horário programado real, ex: 8h=480 min).
    $mensalMin   = intval($escala['carga_horaria_mensal_min'] ?? 0);
    $cargaDiaria = $mensalMin > 0
        ? intval(round($mensalMin / 30))
        : intval(round($mensalPadrao[$tipo] / 30));

    // Dias úteis de trabalho (que podem causar perda de DSR se faltarem)
    $diasUteisMap = [
        'jornada_44h' => ['seg','ter','qua','qui','sex','sab'],
        'jornada_40h' => ['seg','ter','qua','qui','sex'],
        'jornada_36h' => ['seg','ter','qua','qui','sex','sab'],
    ];
    $diasUteis = $diasUteisMap[$tipo];

    // Nome do dia da semana PHP (0=Dom..6=Sáb)
    $mapDowKey = [0=>'dom',1=>'seg',2=>'ter',3=>'qua',4=>'qui',5=>'sex',6=>'sab'];

    // Segunda-feira da semana ISO
    $ts  = strtotime($data);
    $dow = intval(date('w', $ts));
    $monTs  = $ts + (($dow === 0 ? -6 : 1 - $dow) * 86400);
    $sunTs  = $monTs + 6 * 86400;
    $monStr = date('Y-m-d', $monTs);
    $sunStr = date('Y-m-d', $sunTs);

    // Garante que a tabela rh_banco_horas existe
    _bh_garantir_tabela($conn);

    // Busca lançamentos da semana (Seg a Dom)
    $stmt = $conn->prepare(
        "SELECT id, data, tipo_dia, DAYOFWEEK(data) AS mysql_dow
         FROM rh_ponto_lancamento
         WHERE colaborador_id = ? AND data BETWEEN ? AND ?
         ORDER BY data ASC"
    );
    if (!$stmt) return;
    $stmt->bind_param('iss', $colab_id, $monStr, $sunStr);
    $stmt->execute();
    $semana = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $domingoRow = null;
    $hasFalta   = false;

    foreach ($semana as $row) {
        // mysql DAYOFWEEK: 1=Dom, 2=Seg … 7=Sáb
        $dow_php = ($row['mysql_dow'] == 1) ? 0 : ($row['mysql_dow'] - 1);
        $diaNome = $mapDowKey[$dow_php] ?? '';

        if ($diaNome === 'dom') {
            $domingoRow = $row;
        } elseif (in_array($diaNome, $diasUteis) && $row['tipo_dia'] === 'falta') {
            $hasFalta = true;
        }
    }

    if (!$domingoRow) return; // Sem domingo no período

    $sunId        = intval($domingoRow['id']);
    $sunData      = $domingoRow['data'];
    $tipoAtual    = $domingoRow['tipo_dia'];

    // Preserva feriado ou afastamento manual — não sobrescreve
    if (in_array($tipoAtual, ['feriado','afastamento'])) return;

    $novoTipo = $hasFalta ? 'falta' : 'folga';

    if ($tipoAtual !== $novoTipo) {
        $conn->query("UPDATE rh_ponto_lancamento SET tipo_dia = '$novoTipo', horas_trabalhadas_min=0, horas_extras_min=0, atraso_min=0 WHERE id = $sunId");
    }

    // Atualiza banco de horas do domingo (remove automáticos e recria)
    $conn->query(
        "DELETE FROM rh_banco_horas WHERE lancamento_id = $sunId AND tipo IN ('credito','debito')"
    );

    if ($hasFalta) {
        // DSR perdido: débita carga diária
        $descr = 'DSR perdido — falta injustificada na semana';
        $stBH = $conn->prepare(
            "INSERT INTO rh_banco_horas
             (colaborador_id, lancamento_id, data, tipo, minutos, descricao, usuario)
             VALUES (?, ?, ?, 'debito', ?, ?, 'Sistema')"
        );
        if ($stBH) {
            $stBH->bind_param('iisis', $colab_id, $sunId, $sunData, $cargaDiaria, $descr);
            $stBH->execute();
            $stBH->close();
        }
    }
    // DSR ganho: sem entrada no banco — domingo é descanso remunerado sem débito
}
?>
