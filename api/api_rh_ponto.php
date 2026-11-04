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
require_once 'tenant_helper.php';;
require_once 'error_logger.php';
require_once 'rh_ponto_core.php';
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

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) { retornar_json(false, 'Não autenticado'); }

$metodo = $_SERVER['REQUEST_METHOD'];
$body   = ($metodo !== 'GET') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$acao   = $_GET['acao'] ?? $body['acao'] ?? '';
$conn   = conectar_banco();
if (!$conn) retornar_json(false, 'Erro ao conectar ao banco');

try { _ponto_garantir_colunas($conn); }
catch (Throwable $e) { error_log('[RH Ponto] Schema pendente; tenant='.(int)$tenant_id.'; erro='.$e->getMessage()); fechar_conexao($conn); http_response_code(503); retornar_json(false,'Estrutura de Ponto pendente. Aplique as migrations RH antes de continuar.'); }

if (!function_exists('ponto_exigir_rbac')) {
    function ponto_exigir_rbac(mysqli $conn, string $acaoRbac, string $fallback = 'gerente'): void {
        if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conn)) {
            rbacExigir($conn, 'recursos_humanos', $acaoRbac, ['submodulo_chave'=>'ponto']);
            return;
        }
        verificarPermissao($fallback);
    }
}

// ── PERÍODOS ──────────────────────────────────────────────────────────────────
if ($acao === 'listar_periodos') {
    ponto_exigir_rbac($conn, 'visualizar', 'visualizador');
    $colab_id = filter_var($_GET['colaborador_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$colab_id || $colab_id <= 0) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'colaborador_id obrigatório.'); }
    $stmt = $conn->prepare(
        'SELECT p.*, c.nome AS colaborador_nome FROM rh_ponto_periodo p
         INNER JOIN rh_colaboradores c ON c.id=p.colaborador_id AND c.tenant_id=p.tenant_id
         WHERE p.tenant_id=? AND c.tenant_id=? AND p.colaborador_id=? ORDER BY p.ano DESC,p.mes DESC'
    );
    if (!$stmt) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar períodos.'); }
    $stmt->bind_param('iii', $tenant_id, $tenant_id, $colab_id);
    if (!$stmt->execute()) { $stmt->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar períodos.'); }
    $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); fechar_conexao($conn); retornar_json(true, 'OK', $list);
}

if ($acao === 'obter_periodo') {
    ponto_exigir_rbac($conn, 'visualizar', 'visualizador');
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'ID inválido.'); }
    $stmt = $conn->prepare(
        'SELECT p.*,c.nome AS colaborador_nome,c.cargo,c.departamento FROM rh_ponto_periodo p
         INNER JOIN rh_colaboradores c ON c.id=p.colaborador_id AND c.tenant_id=p.tenant_id
         WHERE p.tenant_id=? AND p.id=? LIMIT 1'
    );
    if (!$stmt) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o período.'); }
    $stmt->bind_param('ii', $tenant_id, $id);
    if (!$stmt->execute()) { $stmt->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o período.'); }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); fechar_conexao($conn);
    if (!$row) { http_response_code(404); retornar_json(false, 'Período não encontrado.'); }
    retornar_json(true, 'OK', $row);
}

if ($acao === 'criar_periodo' && $metodo === 'POST') {
    ponto_exigir_rbac($conn, 'criar', 'gerente');
    $colab_id = filter_var($body['colaborador_id'] ?? null, FILTER_VALIDATE_INT);
    $mes = filter_var($body['mes'] ?? null, FILTER_VALIDATE_INT);
    $ano = filter_var($body['ano'] ?? null, FILTER_VALIDATE_INT);
    if (!$colab_id || $colab_id <= 0 || !$mes || $mes < 1 || $mes > 12 || !$ano || $ano < 2000 || $ano > 2099) {
        fechar_conexao($conn); http_response_code(422); retornar_json(false, 'Dados do período inválidos.');
    }
    if (!bh_colaborador_pertence_tenant($conn, (int)$tenant_id, (int)$colab_id)) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado.'); }
    $chk = $conn->prepare('SELECT id FROM rh_ponto_periodo WHERE tenant_id=? AND colaborador_id=? AND mes=? AND ano=? LIMIT 1');
    if (!$chk) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar período existente.'); }
    $chk->bind_param('iiii', $tenant_id, $colab_id, $mes, $ano); $chk->execute(); $exists = $chk->get_result()->num_rows > 0; $chk->close();
    if ($exists) { fechar_conexao($conn); http_response_code(409); retornar_json(false, 'Período já existe para este colaborador.'); }
    $stmt = $conn->prepare('INSERT INTO rh_ponto_periodo (tenant_id,colaborador_id,mes,ano) VALUES (?,?,?,?)');
    if (!$stmt) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível criar o período.'); }
    $stmt->bind_param('iiii', $tenant_id, $colab_id, $mes, $ano);
    if (!$stmt->execute()) { error_log('[RH Ponto] Falha ao criar período; errno='.(int)$stmt->errno); $stmt->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível criar o período.'); }
    $novo_id = (int)$conn->insert_id; $stmt->close();
    _gerar_dias_mes($conn, $novo_id, (int)$colab_id, (int)$mes, (int)$ano, (int)$tenant_id);
    fechar_conexao($conn); retornar_json(true, 'Período criado', ['id'=>$novo_id]);
}

if ($acao === 'fechar_periodo' && $metodo === 'POST') {
    ponto_exigir_rbac($conn, 'editar', 'gerente');
    $id = filter_var($_GET['id'] ?? $body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'ID inválido.'); }
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Falha transacional.');
        $st = $conn->prepare('SELECT status FROM rh_ponto_periodo WHERE tenant_id=? AND id=? FOR UPDATE');
        if (!$st) throw new RuntimeException('Falha ao consultar período.');
        $st->bind_param('ii', $tenant_id, $id); $st->execute(); $p=$st->get_result()->fetch_assoc(); $st->close();
        if (!$p) { $conn->rollback(); fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Período não encontrado.'); }
        if (($p['status'] ?? '') === 'fechado') { $conn->commit(); fechar_conexao($conn); http_response_code(409); retornar_json(false, 'Período já está fechado.'); }
        _recalcular_totais($conn, (int)$id, (int)$tenant_id);
        $up=$conn->prepare("UPDATE rh_ponto_periodo SET status='fechado' WHERE tenant_id=? AND id=? AND status='aberto'");
        if (!$up) throw new RuntimeException('Falha ao fechar período.');
        $up->bind_param('ii',$tenant_id,$id); if (!$up->execute() || $up->affected_rows!==1) throw new RuntimeException('Falha ao fechar período.'); $up->close();
        if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar fechamento.');
        fechar_conexao($conn); retornar_json(true, 'Período fechado');
    } catch (Throwable $e) { $conn->rollback(); error_log('[RH Ponto] Falha ao fechar período; tenant='.(int)$tenant_id.'; erro='.$e->getMessage()); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível fechar o período.'); }
}

if ($acao === 'reabrir_periodo' && $metodo === 'POST') {
    ponto_exigir_rbac($conn, 'editar', 'gerente');
    $id = filter_var($_GET['id'] ?? $body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) { fechar_conexao($conn); http_response_code(422); retornar_json(false, 'ID inválido.'); }
    $stmt=$conn->prepare("UPDATE rh_ponto_periodo SET status='aberto' WHERE tenant_id=? AND id=? AND status='fechado'");
    if (!$stmt) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível reabrir o período.'); }
    $stmt->bind_param('ii',$tenant_id,$id); $ok=$stmt->execute(); $changed=$stmt->affected_rows; $stmt->close(); fechar_conexao($conn);
    if (!$ok) { http_response_code(500); retornar_json(false, 'Não foi possível reabrir o período.'); }
    if (!$changed) { http_response_code(404); retornar_json(false, 'Período fechado não encontrado.'); }
    retornar_json(true, 'Período reaberto');
}

if ($acao === 'excluir_periodo' && $metodo === 'DELETE') {
    ponto_exigir_rbac($conn, 'excluir', 'admin');
    $id=filter_var($body['id'] ?? $_GET['id'] ?? null,FILTER_VALIDATE_INT);
    if (!$id || $id<=0) { fechar_conexao($conn); http_response_code(422); retornar_json(false,'ID inválido.'); }
    $stmt=$conn->prepare('DELETE FROM rh_ponto_periodo WHERE tenant_id=? AND id=?');
    if (!$stmt) { fechar_conexao($conn); http_response_code(500); retornar_json(false,'Não foi possível excluir o período.'); }
    $stmt->bind_param('ii',$tenant_id,$id); $ok=$stmt->execute(); $changed=$stmt->affected_rows; $stmt->close(); fechar_conexao($conn);
    if (!$ok) { http_response_code(500); retornar_json(false,'Não foi possível excluir o período.'); }
    if (!$changed) { http_response_code(404); retornar_json(false,'Período não encontrado.'); }
    retornar_json(true,'Período excluído');
}

// ── LANÇAMENTOS ───────────────────────────────────────────────────────────────

// Endpoint para período personalizado que pode cruzar múltiplos meses:
// GET ?acao=listar_lancamentos_por_colaborador&colaborador_id=N&data_inicio=YYYY-MM-DD&data_fim=YYYY-MM-DD
if ($acao === 'listar_lancamentos_por_colaborador') {
    ponto_exigir_rbac($conn, 'visualizar', 'visualizador');
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
         JOIN rh_ponto_periodo p ON p.id = l.periodo_id AND p.tenant_id = l.tenant_id
         JOIN rh_colaboradores c ON c.id = l.colaborador_id AND c.tenant_id = l.tenant_id
         WHERE l.tenant_id = ? AND l.colaborador_id = ?
           AND l.data BETWEEN ? AND ?
         ORDER BY l.data ASC"
    );
    $stmt->bind_param('iiss', $tenant_id, $colab_id, $data_inicio, $data_fim);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

if ($acao === 'listar_lancamentos') {
    ponto_exigir_rbac($conn, 'visualizar', 'visualizador');
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
             FROM rh_ponto_lancamento l WHERE tenant_id = ? AND l.periodo_id = ?
               AND l.data BETWEEN ? AND ?
             ORDER BY l.data ASC"
        );
        $stmt->bind_param('iiss', $tenant_id, $periodo_id, $data_inicio, $data_fim);
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
             FROM rh_ponto_lancamento l WHERE tenant_id = ? AND l.periodo_id = ?
             ORDER BY l.data ASC"
        );
        $stmt->bind_param('ii', $tenant_id, $periodo_id);
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

    $stContexto = $conn->prepare(
         'SELECT p.id,p.status FROM rh_ponto_periodo p
         INNER JOIN rh_colaboradores c ON c.id=p.colaborador_id AND c.tenant_id=p.tenant_id
         WHERE p.tenant_id=? AND p.id=? AND p.colaborador_id=? LIMIT 1'
    );
    if (!$stContexto) { error_log('[RH Ponto] Falha ao preparar validação de período; tenant=' . (int)$tenant_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível validar o período do ponto.'); }
    $stContexto->bind_param('iii', $tenant_id, $periodo_id, $colab_id);
    if (!$stContexto->execute()) { error_log('[RH Ponto] Falha ao validar período; errno=' . (int)$stContexto->errno); $stContexto->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível validar o período do ponto.'); }
    $contexto = $stContexto->get_result()->fetch_assoc();
    $stContexto->close();
    if (!$contexto) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador ou período não encontrado.'); }
    if (($contexto['status'] ?? '') !== 'aberto') { fechar_conexao($conn); http_response_code(409); retornar_json(false, 'Período fechado não permite alteração de ponto.'); }

    // Buscar escala para calcular (a resolução por dia-da-semana, inclusive para
    // escala_manual, acontece dentro de _calcular_minutos via _obter_parametros_jornada_dia)
    $escala = _buscar_escala($conn, $colab_id, $tenant_id);
    $calc   = _calcular_minutos($he, $has, $har, $hs, $tipo_dia, $escala, $data);

    // Serializa a origem do espelho contra Abono, lançamento manual e reprocessamento.
    try {
        if (!bh_obter_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id)) throw new RuntimeException('Lock do colaborador indisponível.');
        if (!$conn->begin_transaction()) { bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id); throw new RuntimeException('Falha ao iniciar transação de Ponto.'); }
        $stPeriodoLock = $conn->prepare('SELECT status FROM rh_ponto_periodo WHERE tenant_id=? AND id=? AND colaborador_id=? LIMIT 1 FOR UPDATE');
        if (!$stPeriodoLock) throw new RuntimeException('Falha ao bloquear o período de Ponto.');
        $stPeriodoLock->bind_param('iii', $tenant_id, $periodo_id, $colab_id);
        if (!$stPeriodoLock->execute()) { $stPeriodoLock->close(); throw new RuntimeException('Falha ao bloquear o período de Ponto.'); }
        $periodoAtual = $stPeriodoLock->get_result()->fetch_assoc(); $stPeriodoLock->close();
        if (!$periodoAtual) throw new DomainException('PERIODO_NAO_ENCONTRADO');
        if (($periodoAtual['status'] ?? '') !== 'aberto') throw new DomainException('PERIODO_FECHADO');
    } catch (Throwable $e) {
        if ($conn->errno || $conn->thread_id) $conn->rollback();
        bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id);
        fechar_conexao($conn);
        if ($e instanceof DomainException) { http_response_code($e->getMessage()==='PERIODO_FECHADO'?409:404); retornar_json(false, $e->getMessage()==='PERIODO_FECHADO'?'Período fechado não permite alteração de ponto.':'Colaborador ou período não encontrado.'); }
        error_log('[RH Ponto] Falha ao iniciar gravação atômica; tenant='.(int)$tenant_id.'; colaborador='.(int)$colab_id.'; erro='.$e->getMessage());
        http_response_code(503); retornar_json(false, 'Não foi possível iniciar a gravação do ponto.');
    }

    // Verificar se já existe lançamento para esta data/período (UPSERT)
    $existe = $conn->prepare('SELECT id FROM rh_ponto_lancamento WHERE tenant_id=? AND periodo_id=? AND data=? LIMIT 1');
    if (!$existe) { error_log('[RH Ponto] Falha ao verificar lançamento existente; tenant=' . (int)$tenant_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o lançamento de ponto.'); }
    $existe->bind_param('iis', $tenant_id, $periodo_id, $data);
    if (!$existe->execute()) { error_log('[RH Ponto] Falha ao consultar lançamento existente; errno=' . (int)$existe->errno); $existe->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o lançamento de ponto.'); }
    $existe->store_result();
    $is_update = $existe->num_rows > 0;
    $existe->close();
    ponto_exigir_rbac($conn, $is_update ? 'editar' : 'criar', 'gerente');

    if ($is_update) {
        $stmt = $conn->prepare(
            "UPDATE rh_ponto_lancamento SET
             hora_entrada=?,hora_almoco_saida=?,hora_almoco_retorno=?,hora_saida=?,
             tipo_dia=?,horas_trabalhadas_min=?,horas_extras_min=?,atraso_min=?,saida_antecipada_min=?,observacoes=? WHERE tenant_id=? AND periodo_id=? AND data=?"
        );
        if (!$stmt) { error_log('[RH Ponto] Falha ao preparar atualização; tenant=' . (int)$tenant_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível preparar o lançamento de ponto.'); }
        $stmt->bind_param('sssssiiiisiis',
            $he,$has,$har,$hs,$tipo_dia,
            $calc['trabalhadas'],$calc['extras'],$calc['atraso'],$calc['saida_antecipada'],$obs,
            $tenant_id,$periodo_id,$data
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO rh_ponto_lancamento
             (tenant_id,periodo_id,colaborador_id,data,hora_entrada,hora_almoco_saida,hora_almoco_retorno,hora_saida,
              tipo_dia,horas_trabalhadas_min,horas_extras_min,atraso_min,saida_antecipada_min,observacoes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) { error_log('[RH Ponto] Falha ao preparar inserção; tenant=' . (int)$tenant_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível preparar o lançamento de ponto.'); }
        $stmt->bind_param('iiissssssiiiis',
            $tenant_id,$periodo_id,$colab_id,$data,$he,$has,$har,$hs,
            $tipo_dia,$calc['trabalhadas'],$calc['extras'],$calc['atraso'],$calc['saida_antecipada'],$obs
        );
    }

    if (!$stmt->execute()) { error_log('[RH Ponto] Falha ao salvar lançamento; tenant=' . (int)$tenant_id . '; errno=' . (int)$stmt->errno); $stmt->close(); $conn->rollback(); bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível salvar o lançamento de ponto.'); }
    try {
    // Captura o ID do lançamento (INSERT usa insert_id; UPDATE busca pelo periodo+data)
    $lancamento_id_bh = 0;
    if (!$is_update) {
        $lancamento_id_bh = intval($conn->insert_id);
    } else {
        $stRid = $conn->prepare('SELECT id FROM rh_ponto_lancamento WHERE tenant_id=? AND periodo_id=? AND data=? LIMIT 1');
        if (!$stRid) throw new RuntimeException('Falha ao preparar leitura do ID do lançamento atualizado.');
        $stRid->bind_param('iis', $tenant_id, $periodo_id, $data);
        if (!$stRid->execute()) { $stRid->close(); throw new RuntimeException('Falha ao recuperar ID do lançamento atualizado.'); }
        $ridR=$stRid->get_result()->fetch_assoc();$stRid->close();$lancamento_id_bh=(int)($ridR['id']??0);
        if($lancamento_id_bh<=0)throw new RuntimeException('Lançamento atualizado não encontrado para sincronização.');
    }
    $stmt->close();

    if ($lancamento_id_bh > 0) {
        _bh_sincronizar($conn, $lancamento_id_bh, $colab_id, $data, $calc, $escala, $tenant_id, ['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        _dsr_sincronizar($conn, $colab_id, $data, $escala, $tenant_id, ['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
    }
    _recalcular_totais($conn, $periodo_id, (int)$tenant_id);

    // Cross-period: se este lançamento é só de saída (turno noturno que começou no mês anterior),
    // busca o dia anterior do mesmo colaborador e recalcula as horas daquele dia.
    if (!$he && $hs) {
        $prevDate = date('Y-m-d', strtotime($data . ' -1 day'));
        $sp = $conn->prepare(
            "SELECT l.id,l.hora_entrada,l.hora_almoco_saida,l.hora_almoco_retorno,l.hora_saida,l.tipo_dia,l.periodo_id,p.status
             FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data=? LIMIT 1"
        );
        if (!$sp) throw new RuntimeException('Falha ao consultar turno cruzado.');
        $sp->bind_param('iis', $tenant_id, $colab_id, $prevDate);
        if (!$sp->execute()) { $sp->close(); throw new RuntimeException('Falha ao consultar turno cruzado.'); }
        $prevRow = $sp->get_result()->fetch_assoc();
        $sp->close();

        if ($prevRow && ($prevRow['status'] ?? '') === 'aberto' && $prevRow['hora_entrada'] && !$prevRow['hora_saida']) {
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
            $su = $conn->prepare("UPDATE rh_ponto_lancamento SET horas_trabalhadas_min=?, horas_extras_min=?, atraso_min=?, saida_antecipada_min=? WHERE tenant_id = ? AND id=?");
            if (!$su) throw new RuntimeException('Falha ao preparar atualização do turno cruzado.');
            $su->bind_param('iiiiii', $prevCalc['trabalhadas'], $prevCalc['extras'], $prevCalc['atraso'], $prevCalc['saida_antecipada'], $tenant_id, $prevRowId);
            if (!$su->execute()) { $su->close(); throw new RuntimeException('Falha ao atualizar turno cruzado.'); }
            $su->close();
            _recalcular_totais($conn, intval($prevRow['periodo_id']), (int)$tenant_id);
            _bh_sincronizar($conn, intval($prevRow['id']), $colab_id, $prevDate, $prevCalc, $escala, $tenant_id, ['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        }
    }
    } catch (Throwable $e) {
        error_log('[RH Ponto] Falha ao sincronizar espelho e Banco de Horas; tenant='.(int)$tenant_id.'; colaborador='.(int)$colab_id.'; erro='.$e->getMessage());
        $conn->rollback(); bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id); fechar_conexao($conn);
        http_response_code(500); retornar_json(false, 'Não foi possível sincronizar o lançamento de ponto com o Banco de Horas.');
    }
    if (!$conn->commit()) { $conn->rollback(); bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível confirmar o lançamento de ponto.'); }
    bh_liberar_lock_colaborador($conn, (int)$tenant_id, (int)$colab_id);

    fechar_conexao($conn);
    retornar_json(true, 'Lançamento salvo', $calc);
}

if ($acao === 'excluir_lancamento' && $metodo === 'DELETE') {
    ponto_exigir_rbac($conn, 'excluir', 'admin');
    $body2      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id         = intval($body2['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $p = $conn->prepare('SELECT l.periodo_id,l.colaborador_id,l.data,p.status FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.id=? LIMIT 1');
    if (!$p) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o lançamento.'); }
    $p->bind_param('ii',$tenant_id,$id); $p->execute();
    $pr = $p->get_result()->fetch_assoc(); $p->close();
    if (!$pr) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Lançamento não encontrado.'); }
    $colabExclusao=(int)$pr['colaborador_id'];$periodoExclusao=(int)$pr['periodo_id'];$dataExclusao=(string)$pr['data'];
    try {
        if (!bh_obter_lock_colaborador($conn,(int)$tenant_id,$colabExclusao)) throw new RuntimeException('Lock do colaborador indisponível.');
        if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar transação de exclusão.');
        $lock=$conn->prepare('SELECT p.status FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.id=? AND l.colaborador_id=? LIMIT 1 FOR UPDATE');
        if(!$lock)throw new RuntimeException('Falha ao bloquear lançamento.');$lock->bind_param('iii',$tenant_id,$id,$colabExclusao);if(!$lock->execute()){$lock->close();throw new RuntimeException('Falha ao bloquear lançamento.');}$lockedRow=$lock->get_result()->fetch_assoc();$lock->close();
        if(!$lockedRow){$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabExclusao);fechar_conexao($conn);http_response_code(404);retornar_json(false,'Lançamento não encontrado.');}
        if(($lockedRow['status']??'')!=='aberto'){$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabExclusao);fechar_conexao($conn);http_response_code(409);retornar_json(false,'Período fechado não permite exclusão.');}
        $stmt=$conn->prepare('DELETE FROM rh_ponto_lancamento WHERE tenant_id=? AND id=? AND colaborador_id=?');if(!$stmt)throw new RuntimeException('Falha ao preparar exclusão.');$stmt->bind_param('iii',$tenant_id,$id,$colabExclusao);if(!$stmt->execute()||$stmt->affected_rows!==1){$stmt->close();throw new RuntimeException('Falha ao excluir lançamento.');}$stmt->close();
        $escalaExclusao=_buscar_escala($conn,$colabExclusao,(int)$tenant_id);
        _bh_sincronizar($conn,$id,$colabExclusao,$dataExclusao,['extras'=>0,'atraso'=>0,'saida_antecipada'=>0],$escalaExclusao,(int)$tenant_id,['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        _dsr_sincronizar($conn,$colabExclusao,$dataExclusao,$escalaExclusao,(int)$tenant_id,['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        _recalcular_totais($conn,$periodoExclusao,(int)$tenant_id);
        if(!$conn->commit())throw new RuntimeException('Falha ao confirmar exclusão.');
        bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabExclusao);fechar_conexao($conn);retornar_json(true,'Lançamento excluído');
    } catch(Throwable $e) {
        error_log('[RH Ponto] Falha ao excluir lançamento; tenant='.(int)$tenant_id.'; id='.(int)$id.'; erro='.$e->getMessage());$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabExclusao);fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível excluir e sincronizar o lançamento.');
    }
}

// ── RECALCULAR LANÇAMENTOS DO PERÍODO ────────────────────────────────────────
if ($acao === 'recalcular_lancamentos' && $metodo === 'POST') {
    $periodo_id = filter_var($_GET['periodo_id'] ?? $body['periodo_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$periodo_id || $periodo_id <= 0) {
        fechar_conexao($conn); http_response_code(422); retornar_json(false, 'periodo_id obrigatório e inválido.');
    }
    $st = $conn->prepare('SELECT colaborador_id,status FROM rh_ponto_periodo WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$st) { fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o período.'); }
    $st->bind_param('ii', $tenant_id, $periodo_id);
    if (!$st->execute()) { $st->close(); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível consultar o período.'); }
    $periodo = $st->get_result()->fetch_assoc(); $st->close();
    if (!$periodo) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Período não encontrado.'); }
    if (($periodo['status'] ?? '') !== 'aberto') { fechar_conexao($conn); http_response_code(409); retornar_json(false, 'Período fechado não pode ser recalculado.'); }
    try {
        $colaboradorId = (int)$periodo['colaborador_id'];
        if (!bh_colaborador_pertence_tenant($conn, (int)$tenant_id, $colaboradorId)) {
            fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Período não encontrado.');
        }
        $escala = _buscar_escala($conn, $colaboradorId, (int)$tenant_id);
        $resultado = bh_processar_periodo($conn, (int)$tenant_id, (int)$periodo_id, $escala, [
            'colaborador_id' => $colaboradorId,
            'sincronizar_dsr' => true,
        ]);
        fechar_conexao($conn);
        retornar_json(true, 'Recalculado com sucesso.', ['atualizados' => (int)$resultado['atualizados']]);
    } catch (Throwable $e) {
        error_log('[RH Ponto] Falha ao recalcular período; tenant=' . (int)$tenant_id . '; periodo=' . (int)$periodo_id . '; erro=' . $e->getMessage());
        fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível recalcular o período.');
    }
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida');

// ── HELPERS ───────────────────────────────────────────────────────────────────
// _hora_valida, _hora_em_minutos, _buscar_escala, _calcular_minutos,
// _calcular_minutos_par, _recalcular_totais e _bh_sincronizar agora vivem em
// rh_ponto_core.php (compartilhadas com api_rh_abono.php) — ver require_once
// no topo deste arquivo.

function _gerar_dias_mes($conn, int $periodo_id, int $colab_id, int $mes, int $ano, ?int $tenant_id = null): void {
    $dias   = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
    $tenant_id = $tenant_id ?: (int)($_SESSION['tenant_id'] ?? 0);
    $escala = _buscar_escala($conn, $colab_id, $tenant_id);

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
        "INSERT IGNORE INTO rh_ponto_lancamento (tenant_id, periodo_id, colaborador_id, data, tipo_dia)
         VALUES (?, ?, ?, ?, ?)"
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

        $stmt->bind_param('iiiss', $tenant_id, $periodo_id, $colab_id, $data, $tipoDia);
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
    ?array $escala,
    ?int $tenant_id = null,
    array $opcoes = []
): void {
    _bh_dsr_sincronizar($conn, $colab_id, $data, $escala, $tenant_id, $opcoes);
}

?>
