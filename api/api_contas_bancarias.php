<?php
// =====================================================
// API - CONTAS BANCÁRIAS + MOVIMENTAÇÕES + IMPORTAÇÃO OFX
// Versão: 1.0  |  Data: 2026-06-08
// =====================================================

// ─── Configurações de sessão (ANTES do session_start) ─
// Deve ser idêntico ao validar_login.php para compatibilidade de cookies
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);

// ─── Handler de erro fatal para retornar JSON em vez de HTML ─
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $log_file = __DIR__ . '/../logs/debug_contas_bancarias.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' | PHP_ERROR | ' . json_encode([
        'errno' => $errno,
        'errfile' => basename($errfile), 'errline' => $errline
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['sucesso'=>false,'mensagem'=>'Erro interno. Consulte o log técnico sanitizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false; // continua o handler padrão para warnings
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = __DIR__ . '/../logs/debug_contas_bancarias.log';
        $entry = date('Y-m-d H:i:s') . ' | FATAL_ERROR | ' . json_encode([
            'type' => $error['type'], 'file' => basename($error['file']), 'line' => $error['line']
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso'=>false,'mensagem'=>'Erro interno. Consulte o log técnico sanitizado.'], JSON_UNESCAPED_UNICODE);
        }
    }
});

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';

// ─── Headers ────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_mt_originPermitida = in_array($_mt_origin, [
    'https://app.erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1',
], true);
if ($_mt_origin !== '' && $_mt_originPermitida) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
    header('Vary: Origin');
} elseif ($_mt_origin !== '') {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Origem não autorizada.', 'dados' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_mt_origin !== '' && $_mt_originPermitida) header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ─── Helper JSON ─────────────────────────────────────
function _json($ok, $msg, $dados = null, $code = 200) {
    http_response_code($code);
    $r = ['sucesso' => $ok, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$raw_input = ($metodo !== 'GET') ? file_get_contents('php://input') : '';
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

// ─── Autenticação ─────────────────────────────────────
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();

$body   = [];
if ($metodo !== 'GET') {
    if ($raw_input) $body = json_decode($raw_input, true) ?? [];
    // Merge POST form-data (form-data tem prioridade sobre JSON)
    $body = array_merge($body, $_POST);
}
// acao: GET param > POST param > JSON body (suporte a Content-Type: application/json)
$acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';
$acoesEscrita = [
    'migration', 'migration_bancos', 'criar_conta', 'atualizar_conta', 'excluir_conta',
    'criar_movimentacao', 'atualizar_movimentacao', 'excluir_movimentacao', 'conciliar',
    'conciliar_manual', 'desfazer_conciliacao', 'importar_ofx',
];
if (in_array($metodo, ['PUT', 'PATCH', 'DELETE'], true) || ($metodo !== 'GET' && $metodo !== 'POST')) {
    header('Allow: GET, POST, OPTIONS');
    _json(false, 'Método não permitido.', null, 405);
}
if (in_array($acao, $acoesEscrita, true)) {
    if ($metodo !== 'POST') _json(false, 'Esta ação exige POST.', null, 405);
    if ($_mt_origin === '' || !$_mt_originPermitida) _json(false, 'Origem não autorizada.', null, 403);
}

// ─── Migration automática ─────────────────────────────
if ($acao === 'migration') {
    verificarPermissao('admin');
    if (ERP_DB_DRIVER === 'pgsql') {
        _executar_migration();
        exit;
    }
    _executar_migration();
    exit;
}

$db = conectar_banco();
// ─── Migration automática na primeira chamada ─────────
// Garante que as tabelas existam sem precisar chamar ?acao=migration manualmente
if (!_garantir_tabelas($db)) _json(false, 'Schema financeiro incompleto; solicite ao administrador a migration aprovada.', null, 503);

$tenantTables = [
    'contas_bancarias', 'movimentacoes_bancarias', 'historico_importacoes_ofx',
    'conciliacoes', 'contas_receber', 'contas_pagar',
];
foreach ($tenantTables as $tenantTable) {
    if (!\ERP\Database\DatabaseDialect::tableExists($db, $tenantTable)
        || !\ERP\Database\DatabaseDialect::columnExists($db, $tenantTable, 'tenant_id')) {
        _json(false, 'Schema financeiro tenant-scoped não está pronto.', null, 503);
    }
}
$colunasFinanceiras = [
    'movimentacoes_bancarias' => ['status', 'conciliado', 'conciliacao_id', 'fitid', 'conta_id'],
    'historico_importacoes_ofx' => ['conciliadas_auto', 'pendentes', 'formato_ofx'],
    'conciliacoes' => ['ativa', 'movimentacao_id', 'tipo_titulo', 'titulo_id', 'desfeito_por', 'desfeito_em'],
    'contas_receber' => ['movimentacao_bancaria_id', 'conta_bancaria_id', 'conciliado', 'data_conciliacao', 'usuario_conciliacao'],
    'contas_pagar' => ['movimentacao_bancaria_id', 'conta_bancaria_id', 'conciliado', 'data_conciliacao', 'usuario_conciliacao'],
];
foreach ($colunasFinanceiras as $tabelaFinanceira => $colunas) {
    foreach ($colunas as $colunaFinanceira) {
        if (!\ERP\Database\DatabaseDialect::columnExists($db, $tabelaFinanceira, $colunaFinanceira)) {
            _json(false, 'Schema financeiro não contém os campos de conciliação esperados.', null, 503);
        }
    }
}
if (\ERP\Database\DatabaseDialect::isPostgres($db)) {
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'bancos_brasileiros')) {
        _json(false, 'Referências bancárias não provisionadas pelo DDL versionado.', null, 503);
    }
}

// =====================================================
// ROTEAMENTO
// =====================================================
switch ($acao) {

    // ── CONTAS BANCÁRIAS ──────────────────────────────
    case 'listar_contas':       _listar_contas($db); break;
    case 'obter_conta':         _obter_conta($db); break;
    case 'criar_conta':         _criar_conta($db, $body); break;
    case 'atualizar_conta':     _atualizar_conta($db, $body); break;
    case 'excluir_conta':       _excluir_conta($db, $body); break;

    // ── MOVIMENTAÇÕES ────────────────────────────────
    case 'listar_movimentacoes': _listar_movimentacoes($db); break;
    case 'criar_movimentacao':   _criar_movimentacao($db, $body); break;
    case 'atualizar_movimentacao': _atualizar_movimentacao($db, $body); break;
    case 'excluir_movimentacao': _excluir_movimentacao($db, $body); break;
    case 'conciliar':            _conciliar($db, $body); break;
    case 'conciliar_manual':     _conciliar_manual($db, $body); break;
    case 'desfazer_conciliacao': _desfazer_conciliacao($db, $body); break;
    case 'pendentes_conciliacao':_pendentes_conciliacao($db); break;
    case 'candidatos_conciliacao':_candidatos_conciliacao($db); break;
    case 'dashboard_financeiro': _dashboard_financeiro($db); break;

    // ── IMPORTAÇÃO OFX ───────────────────────────────
    case 'preview_ofx':         _preview_ofx($db); break;
    case 'importar_ofx':        _importar_ofx($db); break;
    case 'historico_importacoes': _historico_importacoes($db); break;
    case 'ultimo_importado':    _ultimo_importado($db); break;

    // ── RELATÓRIOS / KPIs ────────────────────────────
    case 'kpis':                _kpis($db); break;
    case 'saldo_contas':        _saldo_contas($db); break;
    case 'relatorio_extrato':   _relatorio_extrato($db); break;

    // ── BANCOS BRASILEIROS ───────────────────────────
    case 'buscar_banco':        _buscar_banco($db); break;
    case 'listar_bancos':       _listar_bancos($db); break;
    case 'migration_bancos':    verificarPermissao('admin'); _migration_bancos($db); break;

    default:
        _json(false, 'Ação inválida: ' . htmlspecialchars($acao), null, 400);
}

// =====================================================
// CONTAS BANCÁRIAS
// =====================================================

function _listar_contas($db) {
    global $tenant_id;
    // Query resiliente: verifica se tabelas auxiliares existem antes de usar subqueries
    $tem_mov = \ERP\Database\DatabaseDialect::tableExists($db, 'movimentacoes_bancarias');
    $tem_ofx = \ERP\Database\DatabaseDialect::tableExists($db, 'historico_importacoes_ofx');

    $sub_mov = $tem_mov
        ? "(SELECT COUNT(*) FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND mb.conta_id = cb.id)"
        : "0";
    $sub_ofx = $tem_ofx
        ? "(SELECT MAX(importado_em) FROM historico_importacoes_ofx hi WHERE hi.tenant_id = $tenant_id AND hi.conta_id = cb.id)"
        : "NULL";

    $sql = "SELECT cb.*,
                {$sub_mov} AS total_mov,
                {$sub_ofx} AS ultima_importacao
            FROM contas_bancarias cb WHERE tenant_id = $tenant_id AND cb.ativo = 1
            ORDER BY cb.nome ASC";
    $res = $db->query($sql);
    if (!$res) _json(false, 'Não foi possível listar as contas bancárias.', null, 503);
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['id']            = (int)$r['id'];
        $r['saldo_atual']   = (float)$r['saldo_atual'];
        $r['saldo_inicial'] = (float)$r['saldo_inicial'];
        $r['total_mov']     = (int)($r['total_mov'] ?? 0);
        $rows[] = $r;
    }
    _json(true, 'OK', $rows);
}

function _obter_conta($db) {
    global $tenant_id;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $stmt = $db->prepare("SELECT * FROM contas_bancarias WHERE tenant_id = $tenant_id AND id = ? AND ativo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if (!$r) _json(false, 'Conta não encontrada', null, 404);
    $r['saldo_atual'] = (float)$r['saldo_atual'];
    _json(true, 'OK', $r);
}

function _criar_conta($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $campos = ['nome','banco_codigo','banco_nome','agencia','conta_numero','conta_tipo'];
    foreach ($campos as $c) {
        if (empty($body[$c])) _json(false, "Campo obrigatório: $c", null, 400);
    }
    $saldo_ini = (float)($body['saldo_inicial'] ?? 0);
    $stmt = $db->prepare("INSERT INTO contas_bancarias
        (tenant_id, nome, banco_codigo, banco_nome, agencia, conta_numero, conta_tipo, moeda, saldo_inicial, saldo_atual, observacoes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $moeda = $body['moeda'] ?? 'BRL';
    $obs   = $body['observacoes'] ?? '';
    $stmt->bind_param('isssssssdds',
        $tenant_id, $body['nome'], $body['banco_codigo'], $body['banco_nome'],
        $body['agencia'], $body['conta_numero'], $body['conta_tipo'],
        $moeda, $saldo_ini, $saldo_ini, $obs
    );
    if (!$stmt->execute()) _json(false, 'Não foi possível criar a conta bancária.', null, 503);
    _json(true, 'Conta criada com sucesso', ['id' => $db->insert_id]);
}

function _atualizar_conta($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $stmt = $db->prepare("UPDATE contas_bancarias SET
        nome=?, banco_codigo=?, banco_nome=?, agencia=?, conta_numero=?,
        conta_tipo=?, moeda=?, saldo_inicial=?, observacoes=? WHERE tenant_id = $tenant_id AND id=? AND ativo=1");
    $saldo_ini = (float)($body['saldo_inicial'] ?? 0);
    $moeda = $body['moeda'] ?? 'BRL';
    $obs   = $body['observacoes'] ?? '';
    $stmt->bind_param('sssssssdsi',
        $body['nome'], $body['banco_codigo'], $body['banco_nome'],
        $body['agencia'], $body['conta_numero'], $body['conta_tipo'],
        $moeda, $saldo_ini, $obs, $id
    );
    if (!$stmt->execute()) _json(false, 'Não foi possível atualizar a conta bancária.', null, 503);
    _recalcular_saldo($db, $id);
    _json(true, 'Conta atualizada com sucesso');
}

function _excluir_conta($db, $body) {
    global $tenant_id;
    verificarPermissao('admin');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $stmt = $db->prepare("UPDATE contas_bancarias SET ativo=0 WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    _json(true, 'Conta removida');
}

// =====================================================
// MOVIMENTAÇÕES
// =====================================================

function _listar_movimentacoes($db) {
    global $tenant_id;
    // Verificar se tabela existe
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'movimentacoes_bancarias')) {
        _json(true, 'OK', ['movimentacoes' => [], 'total' => 0]);
    }
    $conta_id   = intval($_GET['conta_id'] ?? 0);
    $tipo       = $_GET['tipo'] ?? '';
    $conciliado = $_GET['conciliado'] ?? '';
    $dt_ini     = $_GET['dt_ini'] ?? '';
    $dt_fim     = $_GET['dt_fim'] ?? '';
    $busca      = $_GET['busca'] ?? '';
    $limite     = min(intval($_GET['limite'] ?? 100), 500);
    $offset     = intval($_GET['offset'] ?? 0);

    $where = ['mb.tenant_id = ' . (int)$tenant_id];
    $params = [];
    $types  = '';

    if ($conta_id) { $where[] = 'mb.conta_id = ?'; $params[] = $conta_id; $types .= 'i'; }
    if ($tipo)     { $where[] = 'mb.tipo = ?';      $params[] = $tipo;     $types .= 's'; }
    if ($conciliado !== '') { $where[] = 'mb.conciliado = ?'; $params[] = intval($conciliado); $types .= 'i'; }
    if ($dt_ini)   { $where[] = 'mb.data_lancamento >= ?'; $params[] = $dt_ini; $types .= 's'; }
    if ($dt_fim)   { $where[] = 'mb.data_lancamento <= ?'; $params[] = $dt_fim; $types .= 's'; }
    if ($busca)    { $where[] = 'mb.descricao LIKE ?'; $params[] = "%$busca%"; $types .= 's'; }

    $sql = "SELECT mb.*, cb.nome AS conta_nome, cb.banco_nome
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id AND cb.tenant_id = $tenant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY mb.data_lancamento DESC, mb.id DESC
            LIMIT ? OFFSET ?";
    $params[] = $limite; $types .= 'i';
    $params[] = $offset; $types .= 'i';

    $stmt = $db->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['valor'] = (float)$r['valor'];
        $rows[] = $r;
    }

    // Total para paginação
    $sql_count = "SELECT COUNT(*) AS total FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND " . implode(' AND ', $where);
    $types_count  = substr($types, 0, -2); // remove os 2 'i' do fim (limite e offset)
    $params_count = array_slice($params, 0, -2);
    $stmt2 = $db->prepare($sql_count);
    if ($types_count && count($params_count) > 0) $stmt2->bind_param($types_count, ...$params_count);
    $stmt2->execute();
    $total = $stmt2->get_result()->fetch_assoc()['total'] ?? 0;

    _json(true, 'OK', ['movimentacoes' => $rows, 'total' => (int)$total]);
}

function _criar_movimentacao($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $conta_id = intval($body['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);
    if (empty($body['tipo']) || !in_array($body['tipo'], ['credito','debito'])) _json(false, 'tipo inválido', null, 400);
    if (empty($body['data_lancamento'])) _json(false, 'data_lancamento obrigatória', null, 400);
    $valor = abs((float)($body['valor'] ?? 0));
    if ($valor <= 0) _json(false, 'valor deve ser maior que zero', null, 400);

    $stConta = $db->prepare('SELECT 1 FROM contas_bancarias WHERE id = ? AND tenant_id = ? AND ativo = 1 LIMIT 1');
    if (!$stConta) _json(false, 'Não foi possível validar a conta bancária.', null, 503);
    $stConta->bind_param('ii', $conta_id, $tenant_id);
    if (!$stConta->execute()) {
        $stConta->close();
        _json(false, 'Não foi possível validar a conta bancária.', null, 503);
    }
    $contaValida = $stConta->get_result()->fetch_assoc() !== null;
    $stConta->close();
    if (!$contaValida) _json(false, 'Conta bancária não encontrada neste condomínio.', null, 404);

    $stmt = $db->prepare("INSERT INTO movimentacoes_bancarias
        (tenant_id, conta_id, tipo, valor, data_lancamento, descricao, favorecido, checknum, numero_documento,
         categoria, centro_custo, status, origem, observacoes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'pendente','manual',?)");
    $desc    = $body['descricao']        ?? '';
    $fav     = $body['favorecido']       ?? null;
    $chk     = $body['checknum']         ?? null;
    $numdoc  = $body['numero_documento'] ?? null;
    $cat     = $body['categoria']        ?? null;
    $ccusto  = $body['centro_custo']     ?? null;
    $obs     = $body['observacoes']      ?? null;
    $dt      = $body['data_lancamento'];
    $stmt->bind_param('iisdssssssss', $tenant_id, $conta_id, $body['tipo'], $valor, $dt, $desc, $fav, $chk, $numdoc, $cat, $ccusto, $obs);
    if (!$stmt->execute()) _json(false, 'Não foi possível criar a movimentação bancária.', null, 503);
    $novo_id = $db->insert_id;
    _recalcular_saldo($db, $conta_id);
    _json(true, 'Movimentação criada', ['id' => $novo_id]);
}

function _atualizar_movimentacao($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $upd_status  = $body['status']           ?? 'pendente';
    if (!in_array($upd_status, ['pendente', 'conciliado', 'ignorado'], true)) _json(false, 'Estado da movimentação inválido.', null, 400);
    $upd_conciliado = $upd_status === 'conciliado' ? 1 : 0;
    $stmt = $db->prepare("UPDATE movimentacoes_bancarias SET
        tipo=?, valor=?, data_lancamento=?, descricao=?, favorecido=?,
        checknum=?, numero_documento=?, categoria=?, centro_custo=?, status=?, conciliado=?, observacoes=?
        WHERE tenant_id = $tenant_id AND id=? AND conciliacao_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM conciliacoes c WHERE c.tenant_id = $tenant_id AND c.movimentacao_id = movimentacoes_bancarias.id AND c.ativa = 1)");
    $valor       = abs((float)($body['valor'] ?? 0));
    $upd_tipo    = $body['tipo']             ?? '';
    $upd_dt      = $body['data_lancamento']  ?? '';
    $upd_desc    = $body['descricao']        ?? '';
    $upd_fav     = $body['favorecido']       ?? '';
    $upd_chknum  = $body['checknum']         ?? '';
    $upd_numdoc  = $body['numero_documento'] ?? '';
    $upd_cat     = $body['categoria']        ?? '';
    $upd_ccusto  = $body['centro_custo']     ?? '';
    $upd_obs     = $body['observacoes']      ?? '';
    $stmt->bind_param('sdssssssssisi',
        $upd_tipo, $valor, $upd_dt,
        $upd_desc, $upd_fav, $upd_chknum, $upd_numdoc,
        $upd_cat, $upd_ccusto, $upd_status, $upd_conciliado, $upd_obs, $id
    );
    if (!$stmt->execute()) _json(false, 'Não foi possível atualizar a movimentação bancária.', null, 503);
    if ($stmt->affected_rows !== 1) _json(false, 'Movimentação vinculada a uma conciliação ou não encontrada.', null, 409);
    // Recalcular saldo da conta
    $r = $db->query("SELECT conta_id FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$id")->fetch_assoc();
    if ($r) _recalcular_saldo($db, $r['conta_id']);
    _json(true, 'Movimentação atualizada');
}

function _excluir_movimentacao($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $r = $db->query("SELECT conta_id FROM movimentacoes_bancarias mb WHERE mb.tenant_id = $tenant_id AND mb.id=$id AND mb.conciliacao_id IS NULL AND NOT EXISTS (SELECT 1 FROM conciliacoes c WHERE c.tenant_id = mb.tenant_id AND c.movimentacao_id = mb.id AND c.ativa = 1)")->fetch_assoc();
    if (!$r) _json(false, 'Movimentação vinculada a uma conciliação ou não encontrada.', null, 409);
    $stmt = $db->prepare("DELETE FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) _json(false, 'Não foi possível excluir a movimentação bancária.', null, 503);
    if ($stmt->affected_rows !== 1) _json(false, 'Movimentação não encontrada.', null, 404);
    if ($r) _recalcular_saldo($db, $r['conta_id']);
    _json(true, 'Movimentação excluída');
}

function _conciliar($db, $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $ids = $body['ids'] ?? [];
    $conciliado = intval($body['conciliado'] ?? 1);
    if (!in_array($conciliado, [0, 1], true)) _json(false, 'Estado de conciliação inválido.', null, 400);
    if (!is_array($ids) || empty($ids) || count($ids) > 500) _json(false, 'Lista de movimentações inválida.', null, 400);
    foreach ($ids as &$id) {
        if (!is_int($id) && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id))) {
            _json(false, 'IDs de movimentação inválidos.', null, 400);
        }
        $id = (int)$id;
    }
    unset($id);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE movimentacoes_bancarias SET conciliado=?, status=CASE WHEN ?=1 THEN 'conciliado' ELSE 'pendente' END WHERE tenant_id = $tenant_id AND conciliacao_id IS NULL AND status=CASE WHEN ?=1 THEN 'pendente' ELSE 'conciliado' END AND conciliado=CASE WHEN ?=1 THEN 0 ELSE 1 END AND NOT EXISTS (SELECT 1 FROM conciliacoes c WHERE c.tenant_id = movimentacoes_bancarias.tenant_id AND c.movimentacao_id = movimentacoes_bancarias.id AND c.ativa = 1) AND id IN ($placeholders)");
    $types = 'iiii' . str_repeat('i', count($ids));
    $params = array_merge([$conciliado, $conciliado, $conciliado, $conciliado], $ids);
    if (!$stmt || !$stmt->bind_param($types, ...$params) || !$stmt->execute()) _json(false, 'Não foi possível atualizar a conciliação das movimentações.', null, 503);
    _json(true, 'Conciliação atualizada', ['afetadas' => $stmt->affected_rows]);
}

// =====================================================
// IMPORTAÇÃO OFX
// =====================================================

/**
 * Preview do OFX: analisa o arquivo e retorna estatísticas
 * sem inserir nada no banco. Informa quantas transações são
 * novas (após o último FITID importado) e quantas são duplicatas.
 */
function _preview_ofx($db) {
    global $tenant_id;
    if (empty($_FILES['ofx_file'])) _json(false, 'Arquivo OFX não enviado', null, 400);
    $conta_id = intval($_POST['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);
    $conta = $db->query("SELECT id FROM contas_bancarias WHERE tenant_id = $tenant_id AND id=$conta_id AND ativo=1");
    if (!$conta || $conta->num_rows === 0) _json(false, 'Conta não encontrada', null, 404);

    $conteudo = _ler_ofx($_FILES['ofx_file']['tmp_name']);
    $parsed   = _parsear_ofx_v2($conteudo);

    // Buscar último FITID importado para esta conta
    $ultimo = _buscar_ultimo_fitid($db, $conta_id);

    $novas = 0; $duplicatas = 0; $apos_ultimo = false;
    foreach ($parsed['transacoes'] as $t) {
        if ($ultimo && !$apos_ultimo) {
            if ($t['fitid'] === $ultimo) { $apos_ultimo = true; }
            $duplicatas++;
            continue;
        }
        // Verificar se FITID já existe no banco
        $stmt = $db->prepare("SELECT id FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND conta_id=? AND fitid=?");
        $stmt->bind_param('is', $conta_id, $t['fitid']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) { $duplicatas++; } else { $novas++; }
    }

    _json(true, 'Preview gerado', [
        'banco_id'         => $parsed['banco_id'],
        'acct_id'          => $parsed['acct_id'],
        'dt_inicio'        => $parsed['dt_inicio'],
        'dt_fim'           => $parsed['dt_fim'],
        'saldo_final'      => $parsed['saldo_final'],
        'total_transacoes' => count($parsed['transacoes']),
        'novas'            => $novas,
        'duplicatas'       => $duplicatas,
        'ultimo_fitid_bd'  => $ultimo,
        'nome_arquivo'     => $_FILES['ofx_file']['name'],
    ]);
}

/**
 * Importação efetiva do OFX:
 *  1. Parseia o arquivo
 *  2. Busca o último FITID importado para a conta
 *  3. Importa apenas transações com FITID posterior ao último
 *  4. Ignora FITIDs já existentes (deduplicação por UNIQUE KEY)
 *  5. Registra no histórico de importações
 *  6. Recalcula saldo da conta
 */
function _importar_ofx($db) {
    global $tenant_id;
    verificarPermissao('gerente');
    if (empty($_FILES['ofx_file'])) _json(false, 'Arquivo OFX não enviado', null, 400);
    $conta_id = intval($_POST['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);

    // Verificar se conta existe
    $r = $db->query("SELECT id, nome FROM contas_bancarias WHERE tenant_id = $tenant_id AND id=$conta_id AND ativo=1")->fetch_assoc();
    if (!$r) _json(false, 'Conta não encontrada', null, 404);

    $conteudo = _ler_ofx($_FILES['ofx_file']['tmp_name']);
    $parsed   = _parsear_ofx_v2($conteudo);

    if (empty($parsed['transacoes'])) _json(false, 'Nenhuma transação encontrada no arquivo OFX');

    $importadas = 0; $duplicatas = 0; $erros = 0;
    $conciliadas_auto = 0; $pendentes_conc = 0;
    $ultimo_fitid_novo = null; $ultima_data_nova = null;
    $ultimo_fitid = null;
    $apos_ultimo = false;
    $ids_inseridos = [];
    $nome_arq      = basename((string)$_FILES['ofx_file']['name']);
    $usuario       = mb_substr((string)($_SESSION['usuario_nome'] ?? 'sistema'), 0, 80);
    $banco_id_ofx  = $parsed['banco_id'] ?? '';
    $acct_id_ofx   = $parsed['acct_id'] ?? '';
    $dt_inicio_ofx = $parsed['dt_inicio'] ?? null;
    $dt_fim_ofx    = $parsed['dt_fim'] ?? null;
    $saldo_final   = (float)($parsed['saldo_final'] ?? 0);
    $total         = count($parsed['transacoes']);
    $formato_ofx   = $parsed['formato'] ?? 'sgml';
    $ofxCommitted = false;
    $contadoresPersistidos = false;
    $saldoRecalculado = false;

    if (!$db->begin_transaction()) _json(false, 'Não foi possível iniciar a importação OFX.', null, 503);
    try {
        $stmtContaLock = $db->prepare('SELECT id FROM contas_bancarias WHERE tenant_id = ? AND id = ? AND ativo = 1 FOR UPDATE');
        if (!$stmtContaLock) throw new RuntimeException('ofx_account_lock_prepare_failed');
        $stmtContaLock->bind_param('ii', $tenant_id, $conta_id);
        if (!$stmtContaLock->execute() || !$stmtContaLock->get_result()->fetch_assoc()) {
            $stmtContaLock->close();
            $db->rollback();
            _json(false, 'Conta não encontrada.', null, 404);
        }
        $stmtContaLock->close();
        $ultimo_fitid = _buscar_ultimo_fitid($db, $conta_id);
        $apos_ultimo = ($ultimo_fitid === null);

        $historyV2 = \ERP\Database\DatabaseDialect::columnExists($db, 'historico_importacoes_ofx', 'conciliadas_auto')
            && \ERP\Database\DatabaseDialect::columnExists($db, 'historico_importacoes_ofx', 'pendentes')
            && \ERP\Database\DatabaseDialect::columnExists($db, 'historico_importacoes_ofx', 'formato_ofx');
        if ($historyV2) {
            $stmtHistorico = $db->prepare("INSERT INTO historico_importacoes_ofx
                (tenant_id, conta_id, nome_arquivo, banco_id_ofx, acct_id_ofx, dt_inicio_ofx, dt_fim_ofx,
                 ultimo_fitid, ultima_data, total_transacoes, importadas, duplicadas, saldo_final_ofx,
                 importado_por, conciliadas_auto, pendentes, formato_ofx)
                VALUES (?,?,?,?,?,?,?,NULL,NULL,?,0,0,?,?,0,0,?)");
            if (!$stmtHistorico) throw new RuntimeException('ofx_history_prepare_failed');
            if (!$stmtHistorico->bind_param('iisssssidss',
                $tenant_id, $conta_id, $nome_arq, $banco_id_ofx, $acct_id_ofx, $dt_inicio_ofx,
                $dt_fim_ofx, $total, $saldo_final, $usuario, $formato_ofx
            )) throw new RuntimeException('ofx_history_bind_failed');
        } else {
            $stmtHistorico = $db->prepare("INSERT INTO historico_importacoes_ofx
                (tenant_id, conta_id, nome_arquivo, banco_id_ofx, acct_id_ofx, dt_inicio_ofx, dt_fim_ofx,
                 ultimo_fitid, ultima_data, total_transacoes, importadas, duplicadas, saldo_final_ofx, importado_por)
                VALUES (?,?,?,?,?,?,?,NULL,NULL,?,0,0,?,?)");
            if (!$stmtHistorico) throw new RuntimeException('ofx_history_prepare_failed');
            if (!$stmtHistorico->bind_param('iisssssids',
                $tenant_id, $conta_id, $nome_arq, $banco_id_ofx, $acct_id_ofx, $dt_inicio_ofx,
                $dt_fim_ofx, $total, $saldo_final, $usuario
            )) throw new RuntimeException('ofx_history_bind_failed');
        }
        if (!$stmtHistorico->execute()) {
            $stmtHistorico->close();
            throw new RuntimeException('ofx_history_insert_failed');
        }
        $stmtHistorico->close();
        $imp_id_novo = (int)$db->insert_id;
        if ($imp_id_novo <= 0) throw new RuntimeException('ofx_history_id_invalid');

        $sqlMovimentacao = \ERP\Database\DatabaseDialect::isPostgres($db)
            ? "INSERT INTO movimentacoes_bancarias
                (tenant_id, conta_id, fitid, tipo, valor, data_lancamento, descricao, favorecido, memo, checknum,
                 numero_documento, origem, importacao_id, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'ofx',?,'pendente') ON CONFLICT DO NOTHING"
            : "INSERT IGNORE INTO movimentacoes_bancarias
            (tenant_id, conta_id, fitid, tipo, valor, data_lancamento, descricao, favorecido, memo, checknum,
             numero_documento, origem, importacao_id, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'ofx',?,'pendente')";
        $stmt = $db->prepare($sqlMovimentacao);
        if (!$stmt) throw new RuntimeException('ofx_movement_prepare_failed');

        foreach ($parsed['transacoes'] as $t) {
            if (!$apos_ultimo) {
                if ($t['fitid'] === $ultimo_fitid) { $apos_ultimo = true; }
                $duplicatas++;
                continue;
            }

            $t_fitid   = $t['fitid']      ?? '';
            $t_tipo    = $t['tipo']        ?? 'credito';
            $t_valor   = abs((float)($t['valor'] ?? 0));
            $t_data    = $t['data']        ?? date('Y-m-d');
            $t_desc    = $t['memo']        ?: ($t['favorecido'] ?? '');
            $t_fav     = $t['favorecido']  ?? '';
            $t_memo    = $t['memo']        ?? '';
            $t_chknum  = $t['checknum']    ?? '';
            $t_numdoc  = $t['checknum']    ?? '';
            $imp_id    = $imp_id_novo;

            if (!$stmt->bind_param('iissdssssssi',
                $tenant_id, $conta_id, $t_fitid, $t_tipo, $t_valor,
                $t_data, $t_desc, $t_fav, $t_memo, $t_chknum, $t_numdoc, $imp_id
            )) throw new RuntimeException('ofx_movement_bind_failed');
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $importadas++;
                    $ids_inseridos[] = $db->insert_id;
                    $ultimo_fitid_novo = $t_fitid;
                    $ultima_data_nova  = $t_data;
                } else {
                    $duplicatas++;
                }
            } else {
                $erros++;
                $stmt->close();
                throw new RuntimeException('ofx_movement_insert_failed');
            }
        }
        $stmt->close();

        $fitid_final   = $ultimo_fitid_novo ?? $ultimo_fitid ?? '';
        $stAtualizarHistorico = $db->prepare("UPDATE historico_importacoes_ofx SET
            ultimo_fitid = NULLIF(?, ''), ultima_data = ?, importadas = ?, duplicadas = ?
            WHERE tenant_id = ? AND id = ?");
        if (!$stAtualizarHistorico) throw new RuntimeException('ofx_history_update_prepare_failed');
        $stAtualizarHistorico->bind_param('ssiiii', $fitid_final, $ultima_data_nova, $importadas, $duplicatas, $tenant_id, $imp_id_novo);
        if (!$stAtualizarHistorico->execute()) {
            $stAtualizarHistorico->close();
            throw new RuntimeException('ofx_history_update_failed');
        }
        $stAtualizarHistorico->close();

        if (!$db->commit()) throw new RuntimeException('ofx_import_commit_failed');
        $ofxCommitted = true;

    } catch (Throwable $e) {
        if (!$ofxCommitted) $db->rollback();
        error_log('Falha na importação OFX (' . get_class($e) . ').');
        _json(false, 'Não foi possível concluir a importação OFX.', null, 503);
    }

    foreach ($ids_inseridos as $mov_id) {
        try {
            if (_motor_conciliacao($db, $mov_id) === true) $conciliadas_auto++;
            else $pendentes_conc++;
        } catch (Throwable $e) {
            $pendentes_conc++;
            error_log('Falha na conciliação automática pós-importação OFX (' . get_class($e) . ').');
        }
    }

    if ($historyV2 && $imp_id_novo) {
        try {
            $stContadores = $db->prepare('UPDATE historico_importacoes_ofx SET conciliadas_auto = ?, pendentes = ? WHERE tenant_id = ? AND id = ?');
            if (!$stContadores || !$stContadores->bind_param('iiii', $conciliadas_auto, $pendentes_conc, $tenant_id, $imp_id_novo)
                || !$stContadores->execute()) {
                if ($stContadores) $stContadores->close();
                throw new RuntimeException('ofx_history_counters_update_failed');
            }
            $stContadores->close();
            $contadoresPersistidos = true;
        } catch (Throwable $e) {
            error_log('Falha ao atualizar contadores da importação OFX (' . get_class($e) . ').');
        }
    }

    try {
        $saldoRecalculado = _recalcular_saldo($db, $conta_id);
        if (!$saldoRecalculado) error_log('Falha ao recalcular saldo após importação OFX.');
    } catch (Throwable $e) {
        error_log('Falha ao recalcular saldo após importação OFX (' . get_class($e) . ').');
    }

    _json(true, 'Importação concluída', [
        'importadas'       => $importadas,
        'duplicatas'       => $duplicatas,
        'erros'            => $erros,
        'total_arq'        => count($parsed['transacoes']),
        'ultimo_fitid'     => $ultimo_fitid_novo ?? $ultimo_fitid,
        'conciliadas_auto' => $conciliadas_auto,
        'pendentes'        => $pendentes_conc,
        'formato_ofx'      => $parsed['formato'] ?? 'sgml',
        'contadores_salvos' => $historyV2 ? $contadoresPersistidos : false,
        'saldo_recalculado' => $saldoRecalculado,
    ]);
}

function _historico_importacoes($db) {
    global $tenant_id;
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $where = "WHERE hi.tenant_id = $tenant_id" . ($conta_id ? " AND hi.conta_id = $conta_id" : '');
    $sql = "SELECT hi.*, cb.nome AS conta_nome
            FROM historico_importacoes_ofx hi
            JOIN contas_bancarias cb ON cb.id = hi.conta_id AND cb.tenant_id = hi.tenant_id
            $where
            ORDER BY hi.importado_em DESC
            LIMIT 100";
    $res = $db->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    _json(true, 'OK', $rows);
}

function _ultimo_importado($db) {
    global $tenant_id;
    $conta_id = intval($_GET['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);
    // Verificar se tabela existe
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'historico_importacoes_ofx')) {
        _json(true, 'OK', null);
    }
    $res = $db->query("SELECT ultimo_fitid, ultima_data, importado_em, nome_arquivo
                     FROM historico_importacoes_ofx
                     WHERE tenant_id = $tenant_id AND conta_id=$conta_id
                     ORDER BY importado_em DESC LIMIT 1");
    $r = $res ? $res->fetch_assoc() : null;
    _json(true, 'OK', $r ?? null);
}

// =====================================================
// KPIs e RELATÓRIOS
// =====================================================

function _kpis($db) {
    global $tenant_id;
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $mes = $_GET['mes'] ?? date('Y-m');

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$mes)) {
        _json(false, 'Formato de mês inválido; use YYYY-MM.', null, 400);
    }
    $inicio_mes = $mes . '-01';
    $fim_mes = (new DateTimeImmutable($inicio_mes))->modify('+1 month')->format('Y-m-d');
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END), 0) AS total_creditos,
        COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END), 0) AS total_debitos,
        COUNT(*) AS total_mov,
        SUM(CASE WHEN mb.status='pendente' THEN 1 ELSE 0 END) AS pendentes_conciliacao
        FROM movimentacoes_bancarias mb
        WHERE mb.tenant_id = ? AND mb.data_lancamento >= ? AND mb.data_lancamento < ?";
    $types = 'iss';
    $params = [$tenant_id, $inicio_mes, $fim_mes];
    if ($conta_id > 0) {
        $sql .= ' AND mb.conta_id = ?';
        $types .= 'i';
        $params[] = $conta_id;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) _json(false, 'Não foi possível carregar os indicadores bancários.', null, 503);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $stmt->close(); _json(false, 'Não foi possível carregar os indicadores bancários.', null, 503); }
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $saldo_total = $db->query("SELECT COALESCE(SUM(saldo_atual),0) AS s FROM contas_bancarias WHERE tenant_id = $tenant_id AND ativo=1")->fetch_assoc()['s'];
    $total_contas = $db->query("SELECT COUNT(*) AS c FROM contas_bancarias WHERE tenant_id = $tenant_id AND ativo=1")->fetch_assoc()['c'];

    _json(true, 'OK', [
        'saldo_total'           => (float)$saldo_total,
        'total_contas'          => (int)$total_contas,
        'total_creditos_mes'    => (float)$r['total_creditos'],
        'total_debitos_mes'     => (float)$r['total_debitos'],
        'saldo_mes'             => (float)$r['total_creditos'] - (float)$r['total_debitos'],
        'total_mov_mes'         => (int)$r['total_mov'],
        'pendentes_conciliacao' => (int)$r['pendentes_conciliacao'],
    ]);
}

function _saldo_contas($db) {
    global $tenant_id;
    $res = $db->query("SELECT * FROM vw_saldo_contas WHERE tenant_id = $tenant_id ORDER BY nome ASC");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['saldo_calculado']  = (float)$r['saldo_calculado'];
        $r['total_creditos']   = (float)$r['total_creditos'];
        $r['total_debitos']    = (float)$r['total_debitos'];
        $rows[] = $r;
    }
    _json(true, 'OK', $rows);
}

function _relatorio_extrato($db) {
    global $tenant_id;
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $dt_ini   = $_GET['dt_ini'] ?? date('Y-m-01');
    $dt_fim   = $_GET['dt_fim'] ?? date('Y-m-d');

    $where = "WHERE mb.tenant_id = $tenant_id AND mb.data_lancamento BETWEEN ? AND ?";
    $params = [$dt_ini, $dt_fim];
    $types  = 'ss';
    if ($conta_id) { $where .= ' AND mb.conta_id = ?'; $params[] = $conta_id; $types .= 'i'; }

    $sql = "SELECT mb.*, cb.nome AS conta_nome, cb.banco_nome, cb.agencia, cb.conta_numero
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id AND cb.tenant_id = $tenant_id
            $where
            ORDER BY mb.data_lancamento ASC, mb.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $total_c = 0; $total_d = 0;
    while ($r = $res->fetch_assoc()) {
        $r['valor'] = (float)$r['valor'];
        if ($r['tipo'] === 'credito') $total_c += $r['valor'];
        else $total_d += $r['valor'];
        $rows[] = $r;
    }
    _json(true, 'OK', [
        'movimentacoes'  => $rows,
        'total_creditos' => $total_c,
        'total_debitos'  => $total_d,
        'saldo_periodo'  => $total_c - $total_d,
    ]);
}

// =====================================================
// HELPERS INTERNOS
// =====================================================

/** Recalcula saldo_atual da conta somando saldo_inicial + créditos - débitos */
function _recalcular_saldo($db, $conta_id): bool {
    global $tenant_id;
    $stmt = $db->prepare("UPDATE contas_bancarias
        SET saldo_atual = saldo_inicial
            + COALESCE((SELECT SUM(valor) FROM movimentacoes_bancarias WHERE tenant_id = ? AND conta_id = ? AND tipo = 'credito'), 0)
            - COALESCE((SELECT SUM(valor) FROM movimentacoes_bancarias WHERE tenant_id = ? AND conta_id = ? AND tipo = 'debito'), 0)
        WHERE tenant_id = ? AND id = ?");
    if (!$stmt || !$stmt->bind_param('iiiiii', $tenant_id, $conta_id, $tenant_id, $conta_id, $tenant_id, $conta_id)) {
        if ($stmt) $stmt->close();
        return false;
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Busca o FITID da última transação importada para a conta */
function _buscar_ultimo_fitid($db, $conta_id) {
    global $tenant_id;
    $r = $db->query("SELECT ultimo_fitid FROM historico_importacoes_ofx
                     WHERE tenant_id = $tenant_id AND conta_id=$conta_id AND ultimo_fitid IS NOT NULL
                     ORDER BY importado_em DESC LIMIT 1")->fetch_assoc();
    return $r ? $r['ultimo_fitid'] : null;
}

/** Lê o arquivo OFX convertendo para UTF-8 se necessário */
function _ler_ofx($path) {
    $raw = file_get_contents($path);
    if (preg_match('/CHARSET:\s*1252/i', $raw) || preg_match('/ENCODING:\s*USASCII/i', $raw)) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
    return $raw;
}

/**
 * Parser OFX v2 — suporta SGML (Bradesco/BB/Caixa/Santander) e XML (Itaú/Nubank)
 */
function _parsear_ofx_v2($conteudo) {
    $result = [
        'banco_id'    => '',
        'acct_id'     => '',
        'dt_inicio'   => null,
        'dt_fim'      => null,
        'saldo_final' => 0.0,
        'formato'     => 'sgml',
        'transacoes'  => [],
    ];

    if (preg_match('/<BANKID[>\s]/i', $conteudo, $m)) {
        if (preg_match('/<BANKID>\s*([^\r\n<]+)/i', $conteudo, $m)) $result['banco_id'] = trim($m[1]);
    }
    if (preg_match('/<ACCTID>\s*([^\r\n<]+)/i',  $conteudo, $m)) $result['acct_id']  = trim($m[1]);
    if (preg_match('/<DTSTART[^>]*>\s*(\d{8})/i', $conteudo, $m)) $result['dt_inicio'] = _ofx_data($m[1]);
    if (preg_match('/<DTEND[^>]*>\s*(\d{8})/i',   $conteudo, $m)) $result['dt_fim']    = _ofx_data($m[1]);
    if (preg_match('/<BALAMT[^>]*>\s*([\d.,\-]+)/i', $conteudo, $m)) {
        $result['saldo_final'] = (float)str_replace(',', '.', $m[1]);
    }

    $is_xml = stripos($conteudo, '</STMTTRN>') !== false;
    $result['formato'] = $is_xml ? 'xml' : 'sgml';

    if ($is_xml) {
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $blocos);
        foreach ($blocos[1] as $bloco) {
            $t = _ofx_extrair_transacao_v2($bloco, true);
            if ($t) $result['transacoes'][] = $t;
        }
    } else {
        if (!preg_match('/<BANKTRANLIST>(.*?)(<\/BANKTRANLIST>|$)/is', $conteudo, $bm)) {
            return $result;
        }
        $lista  = $bm[1];
        $blocos = preg_split('/<STMTTRN>/i', $lista);
        array_shift($blocos);
        foreach ($blocos as $bloco) {
            $bloco = preg_replace('/<\/STMTTRN>.*/is', '', $bloco);
            $t = _ofx_extrair_transacao_v2($bloco, false);
            if ($t) $result['transacoes'][] = $t;
        }
    }

    return $result;
}

function _ofx_extrair_transacao_v2(string $bloco, bool $xml): ?array {
    $campo = function(string $tag) use ($bloco, $xml): string {
        if ($xml) {
            if (preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/is', $bloco, $m)) return trim($m[1]);
        } else {
            if (preg_match('/<' . $tag . '>\s*([^\r\n<]+)/i', $bloco, $m)) return trim($m[1]);
        }
        return '';
    };

    $trntype  = strtoupper($campo('TRNTYPE'));
    $raw_amt  = $campo('TRNAMT');
    $fitid    = $campo('FITID');
    if (!$fitid || $raw_amt === '') return null;

    $valor_float = (float)str_replace(',', '.', $raw_amt);
    $tipo  = ($trntype === 'CREDIT' || $valor_float > 0) ? 'credito' : 'debito';
    $valor = abs($valor_float);

    $dt_raw = preg_replace('/[^0-9]/', '', substr($campo('DTPOSTED'), 0, 8));
    $data   = strlen($dt_raw) === 8 ? _ofx_data($dt_raw) : date('Y-m-d');

    $name     = $campo('NAME');
    $payee    = $campo('PAYEE');
    $memo     = $campo('MEMO');
    $checknum = $campo('CHECKNUM');

    $favorecido = $name ?: $payee ?: _ofx_favorecido_do_memo($memo);

    return compact('fitid','tipo','valor','data','favorecido','memo','payee','checknum');
}

function _ofx_favorecido_do_memo(string $memo): string {
    if (!$memo) return '';
    foreach (['PIX ','TED ','DOC ','TRANSF ','PGTO ','PAG '] as $pref) {
        if (stripos($memo, $pref) === 0) {
            $partes = preg_split('/\s+/', trim(substr($memo, strlen($pref))));
            return implode(' ', array_slice($partes, 0, 4));
        }
    }
    $partes = preg_split('/\s+/', $memo);
    return implode(' ', array_slice($partes, 0, 4));
}

/** Converte data OFX (YYYYMMDD) para formato MySQL (YYYY-MM-DD) */
function _ofx_data($str) {
    $str = substr($str, 0, 8);
    if (strlen($str) !== 8) return null;
    return substr($str,0,4).'-'.substr($str,4,2).'-'.substr($str,6,2);
}

// =====================================================
// BANCOS BRASILEIROS
// =====================================================

/**
 * Busca banco por código exato ou por nome (busca parcial).
 * GET ?acao=buscar_banco&q=237  ou  ?acao=buscar_banco&q=bradesco
 */
function _buscar_banco($db) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) === 0) _json(false, 'Parâmetro q obrigatório', null, 400);

    // Verifica se a tabela existe
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'bancos_brasileiros')) {
        // Tabela não existe ainda — retorna array vazio para não quebrar o front
        _json(true, 'Tabela não migrada', []);
    }

    // Busca por código exato primeiro
    $stmt = $db->prepare("SELECT codigo, ispb, nome, nome_curto FROM bancos_brasileiros WHERE codigo = ? AND ativo = 1 LIMIT 1");
    $stmt->bind_param('s', $q);
    $stmt->execute();
    $exact = $stmt->get_result()->fetch_assoc();
    if ($exact) {
        _json(true, 'OK', [$exact]);
    }

    // Busca por nome parcial (máx 15 resultados)
    $like = '%' . $q . '%';
    $stmt2 = $db->prepare("SELECT codigo, ispb, nome, nome_curto FROM bancos_brasileiros WHERE (nome LIKE ? OR nome_curto LIKE ? OR codigo LIKE ?) AND ativo = 1 ORDER BY CAST(codigo AS DECIMAL(20,0)), codigo LIMIT 15");
    $stmt2->bind_param('sss', $like, $like, $like);
    $stmt2->execute();
    $res = $stmt2->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    _json(true, 'OK', $rows);
}

/**
 * Lista todos os bancos ativos (para popular um select completo).
 * GET ?acao=listar_bancos
 */
function _listar_bancos($db) {
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'bancos_brasileiros')) _json(true, 'Tabela não migrada', []);

    $res = $db->query("SELECT codigo, ispb, nome, nome_curto FROM bancos_brasileiros WHERE ativo = 1 ORDER BY CAST(codigo AS DECIMAL(20,0)), codigo");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    _json(true, 'OK', $rows);
}

/**
 * Cria a tabela bancos_brasileiros e insere o seed completo.
 * GET ?acao=migration_bancos
 */
function _migration_bancos($db) {
    verificarPermissao('admin');
    header('Content-Type: application/json; charset=utf-8');
    if (\ERP\Database\DatabaseDialect::isPostgres($db)) {
        if (!\ERP\Database\DatabaseDialect::tableExists($db, 'bancos_brasileiros')) {
            _json(false, 'A tabela de bancos não foi provisionada pelo DDL versionado.', null, 503);
        }
        $r = $db->query('SELECT COUNT(*) AS n FROM bancos_brasileiros');
        $count = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : -1;
        if ($count <= 0 || !_bancos_seed_versionado_completo($db)) {
            _json(false, 'Referências bancárias não foram provisionadas integralmente pelo seed versionado.', null, 503);
        }
        _json(true, 'Estrutura PostgreSQL mantida; referências bancárias verificadas (' . $count . ').');
    }

    // 1. Criar tabela
    $create = "CREATE TABLE IF NOT EXISTS bancos_brasileiros (
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        codigo    VARCHAR(10)  NOT NULL,
        ispb      VARCHAR(8)   DEFAULT NULL,
        nome      VARCHAR(120) NOT NULL,
        nome_curto VARCHAR(60) DEFAULT NULL,
        ativo     TINYINT(1)   NOT NULL DEFAULT 1,
        UNIQUE KEY uk_codigo (codigo),
        KEY idx_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$db->query($create)) {
        echo json_encode(['sucesso'=>false,'mensagem'=>'Não foi possível provisionar a referência bancária.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!_aplicar_seed_bancos_brasileiros($db)) {
        _json(false, 'Não foi possível provisionar integralmente a referência de bancos pelo seed versionado.', null, 503);
    }

    $totalRow = $db->query('SELECT COUNT(*) AS n FROM bancos_brasileiros');
    $total = $totalRow ? (int)($totalRow->fetch_assoc()['n'] ?? 0) : 0;
    if ($total <= 0) _json(false, 'A referência de bancos está vazia.', null, 503);
    _json(true, 'Referências bancárias verificadas (' . $total . ').');
}

function _aplicar_seed_bancos_brasileiros($db): bool {
    $seedFile = __DIR__ . '/../sql/seed_bancos_brasileiros.sql';
    if (is_link($seedFile) || !is_file($seedFile)) return false;
    $sql = file_get_contents($seedFile);
    if (!is_string($sql)) return false;
    $sql = preg_replace('/CREATE TABLE.*?;/s', '', $sql);
    $sql = preg_replace('/TRUNCATE TABLE.*?;/s', '', $sql);
    $sql = preg_replace('/\bINSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $sql, 1, $replacements);
    if (!is_string($sql) || $replacements !== 1 || !$db->multi_query($sql)) return false;
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    return $db->errno === 0 && _bancos_seed_versionado_completo($db);
}

function _bancos_seed_versionado_completo($db) {
    $seed_file = __DIR__ . '/../sql/seed_bancos_brasileiros.sql';
    if (is_link($seed_file) || !is_file($seed_file)) return false;
    $sql = file_get_contents($seed_file);
    if (!is_string($sql) || !preg_match_all("/\\(\\s*'([0-9]{1,10})'\\s*,/", $sql, $matches)) return false;
    $codes = array_values(array_unique($matches[1]));
    if (count($codes) !== 330) return false;
    $result = $db->query('SELECT codigo FROM bancos_brasileiros');
    if (!$result) return false;
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        if (isset($row['codigo']) && is_string($row['codigo'])) $existing[$row['codigo']] = true;
    }
    foreach ($codes as $code) if (!isset($existing[$code])) return false;
    return true;
}

// =====================================================

// =====================================================
// CONCILIAÇÃO BANCÁRIA
// =====================================================

/**
 * Motor de conciliação: tenta auto-conciliar uma movimentação.
 * Retorna true se auto-conciliou, false se ficou pendente.
 */
function _motor_conciliacao($db, $mov_id): bool {
    global $tenant_id;
    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$mov_id")->fetch_assoc();
    if (!$mov || $mov['status'] !== 'pendente' || (int)$mov['conciliado'] !== 0 || $mov['conciliacao_id'] !== null) return false;

    $tabela = $mov['tipo'] === 'credito' ? 'contas_receber' : 'contas_pagar';
    $campo_valor = $tabela === 'contas_receber' ? 'valor_original' : 'valor_original';
    $campo_fav   = $tabela === 'contas_receber' ? 'morador_nome' : 'favorecido';
    $campo_data  = 'data_vencimento';
    $campo_doc   = 'numero_documento';
    $status_open = $tabela === 'contas_receber' ? "'PENDENTE'" : "'PENDENTE'";

    $tol = $mov['valor'] * 0.05;
    $v_min = $mov['valor'] - $tol;
    $v_max = $mov['valor'] + $tol;
    $dt    = $mov['data_lancamento'];

    $sql = "SELECT * FROM $tabela
            WHERE tenant_id = $tenant_id AND ativo=1 AND status IN ($status_open)
              AND $campo_valor BETWEEN $v_min AND $v_max
              AND $campo_data BETWEEN DATE_SUB('$dt', INTERVAL 30 DAY) AND DATE_ADD('$dt', INTERVAL 30 DAY)
            LIMIT 20";
    $res = $db->query($sql);
    if (!$res) return false;

    $melhor = null; $melhor_score = 0;
    while ($t = $res->fetch_assoc()) {
        $score = 0;
        // Valor exato
        if (abs($mov['valor'] - $t[$campo_valor]) < 0.01) $score += 40;
        // Checknum = numero_documento
        if (!empty($mov['checknum']) && !empty($t[$campo_doc]) &&
            strtolower($mov['checknum']) === strtolower($t[$campo_doc])) $score += 25;
        // Data
        $diff = abs(strtotime($mov['data_lancamento']) - strtotime($t[$campo_data])) / 86400;
        if ($diff <= 3)     $score += 20;
        elseif ($diff <= 7) $score += 12;
        // Favorecido
        $fav_mov = strtolower(preg_replace('/[^a-z0-9 ]/i','', $mov['favorecido'] ?? ''));
        $fav_tit = strtolower(preg_replace('/[^a-z0-9 ]/i','', $t[$campo_fav] ?? ''));
        if ($fav_mov && $fav_tit) {
            similar_text($fav_mov, $fav_tit, $pct);
            if ($pct >= 80) $score += 15;
        }
        // Memo contém numero_documento
        if (!empty($t[$campo_doc]) && stripos($mov['memo'] ?? '', $t[$campo_doc]) !== false) $score += 10;

        if ($score > $melhor_score) { $melhor_score = $score; $melhor = $t; }
    }

    if ($melhor_score >= 80 && $melhor) {
        return _conciliar_auto($db, $mov, $tabela, $melhor, $melhor_score);
    }
    return false;
}

function _conciliar_auto($db, array $mov, string $tabela, array $titulo, int $score): bool {
    global $tenant_id;
    if (!in_array($tabela, ['contas_receber', 'contas_pagar'], true)) return false;
    $tipo_titulo = $tabela === 'contas_receber' ? 'receber' : 'pagar';
    $usuario     = $_SESSION['usuario_nome'] ?? 'sistema';

    if (!$db->begin_transaction()) return false;
    try {
        $stMov = $db->prepare("UPDATE movimentacoes_bancarias
            SET status='conciliado', conciliado=1
            WHERE tenant_id = ? AND id = ? AND status = 'pendente' AND conciliado = 0 AND conciliacao_id IS NULL");
        if (!$stMov) throw new RuntimeException('auto_reconcile_movement_prepare_failed');
        $movId = (int)$mov['id'];
        $stMov->bind_param('ii', $tenant_id, $movId);
        if (!$stMov->execute() || $stMov->affected_rows !== 1) {
            $stMov->close();
            throw new RuntimeException('auto_reconcile_movement_state_changed');
        }
        $stMov->close();

        $status_novo = $tabela === 'contas_receber' ? 'RECEBIDO' : 'PAGO';
        $campo_data  = $tabela === 'contas_receber' ? 'data_recebimento' : 'data_pagamento';
        $movId = (int)$mov['id'];
        $contaId = (int)$mov['conta_id'];
        $stTitulo = $db->prepare("UPDATE $tabela SET status = ?, $campo_data = CURRENT_DATE,
            movimentacao_bancaria_id = ?, conta_bancaria_id = ?, conciliado = 1,
            data_conciliacao = CURRENT_TIMESTAMP, usuario_conciliacao = ?
            WHERE tenant_id = ? AND id = ? AND ativo = 1 AND status = 'PENDENTE'");
        if (!$stTitulo) throw new RuntimeException('auto_reconcile_title_prepare_failed');
        $tituloId = (int)$titulo['id'];
        if (!$stTitulo->bind_param('siisii', $status_novo, $movId, $contaId, $usuario, $tenant_id, $tituloId)) {
            $stTitulo->close();
            throw new RuntimeException('auto_reconcile_title_bind_failed');
        }
        if (!$stTitulo->execute() || $stTitulo->affected_rows !== 1) {
            $stTitulo->close();
            throw new RuntimeException('auto_reconcile_title_state_changed');
        }
        $stTitulo->close();

        $criterios = json_encode(['score' => $score, 'tipo' => 'automatica'], JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO conciliacoes
            (tenant_id, movimentacao_id, tipo_titulo, titulo_id, score, tipo_conciliacao, criterios, conciliado_por)
            VALUES (?,?,?,?,?,?,?,?)");
        if (!$stmt) throw new RuntimeException('auto_reconcile_record_prepare_failed');
        $tipo_str = $tipo_titulo;
        $tipo_conciliacao = 'automatica';
        $stmt->bind_param('iisiisss', $tenant_id, $mov['id'], $tipo_str, $titulo['id'], $score, $tipo_conciliacao, $criterios, $usuario);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('auto_reconcile_record_failed'); }
        $stmt->close();

        $conc_id = (int)$db->insert_id;
        if ($conc_id <= 0) throw new RuntimeException('auto_reconcile_record_id_invalid');
        $stMovLink = $db->prepare("UPDATE movimentacoes_bancarias SET conciliacao_id = ?
            WHERE tenant_id = ? AND id = ? AND status = 'conciliado' AND conciliado = 1");
        if (!$stMovLink) throw new RuntimeException('auto_reconcile_link_prepare_failed');
        $stMovLink->bind_param('iii', $conc_id, $tenant_id, $movId);
        if (!$stMovLink->execute() || $stMovLink->affected_rows !== 1) {
            $stMovLink->close();
            throw new RuntimeException('auto_reconcile_link_failed');
        }
        $stMovLink->close();
        if (!$db->commit()) throw new RuntimeException('auto_reconcile_commit_failed');
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        return false;
    }
}

function _conciliar_manual($db, array $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $mov_id      = intval($body['mov_id']      ?? 0);
    $tipo_titulo = $body['tipo_titulo']         ?? '';
    $titulo_id   = intval($body['titulo_id']   ?? 0);
    if (!$mov_id || !$titulo_id || !in_array($tipo_titulo, ['receber','pagar'])) {
        _json(false, 'Parâmetros inválidos', null, 400);
    }

    $tabela      = $tipo_titulo === 'receber' ? 'contas_receber' : 'contas_pagar';
    $status_novo = $tipo_titulo === 'receber' ? 'RECEBIDO' : 'PAGO';
    $campo_data  = $tipo_titulo === 'receber' ? 'data_recebimento' : 'data_pagamento';
    $usuario     = $_SESSION['usuario_nome'] ?? 'sistema';

    if (!$db->begin_transaction()) _json(false, 'Não foi possível iniciar a conciliação bancária.', null, 503);
    try {
        $stMov = $db->prepare('SELECT status, tipo, conta_id, conciliado, conciliacao_id FROM movimentacoes_bancarias WHERE tenant_id = ? AND id = ? FOR UPDATE');
        if (!$stMov) throw new RuntimeException('movement_lookup_failed');
        $stMov->bind_param('ii', $tenant_id, $mov_id);
        if (!$stMov->execute()) throw new RuntimeException('movement_lookup_failed');
        $mov = $stMov->get_result()->fetch_assoc();
        $stMov->close();
        if (!$mov) { $db->rollback(); _json(false, 'Movimentação não encontrada neste condomínio.', null, 404); }
        if ($mov['status'] !== 'pendente' || (int)$mov['conciliado'] !== 0 || $mov['conciliacao_id'] !== null) {
            $db->rollback();
            _json(false, 'A movimentação não está pendente para conciliação.', null, 409);
        }
        $tipoMovimentacaoEsperado = $tipo_titulo === 'receber' ? 'credito' : 'debito';
        if ($mov['tipo'] !== $tipoMovimentacaoEsperado) {
            $db->rollback();
            _json(false, 'A direção da movimentação não corresponde ao tipo de título.', null, 409);
        }

        $stTitulo = $db->prepare("SELECT status FROM $tabela WHERE tenant_id = ? AND id = ? AND ativo = 1 FOR UPDATE");
        if (!$stTitulo) throw new RuntimeException('title_lookup_failed');
        $stTitulo->bind_param('ii', $tenant_id, $titulo_id);
        if (!$stTitulo->execute()) throw new RuntimeException('title_lookup_failed');
        $titulo = $stTitulo->get_result()->fetch_assoc();
        $stTitulo->close();
        if (!$titulo) { $db->rollback(); _json(false, 'Título não encontrado neste condomínio.', null, 404); }
        if (strtoupper((string)$titulo['status']) !== 'PENDENTE') {
            $db->rollback();
            _json(false, 'Somente títulos pendentes podem ser conciliados.', null, 409);
        }

        $stConcAtiva = $db->prepare('SELECT id FROM conciliacoes WHERE tenant_id = ? AND ativa = 1 AND (movimentacao_id = ? OR (tipo_titulo = ? AND titulo_id = ?)) LIMIT 1 FOR UPDATE');
        if (!$stConcAtiva) throw new RuntimeException('active_reconciliation_lookup_failed');
        $stConcAtiva->bind_param('iisi', $tenant_id, $mov_id, $tipo_titulo, $titulo_id);
        if (!$stConcAtiva->execute()) throw new RuntimeException('active_reconciliation_lookup_failed');
        $concAtiva = $stConcAtiva->get_result()->fetch_assoc();
        $stConcAtiva->close();
        if ($concAtiva) {
            $db->rollback();
            _json(false, 'Movimentação ou título já possui conciliação ativa.', null, 409);
        }

        $stMovUpdate = $db->prepare("UPDATE movimentacoes_bancarias SET status = 'conciliado', conciliado = 1 WHERE tenant_id = ? AND id = ? AND status = 'pendente' AND conciliado = 0 AND conciliacao_id IS NULL");
        if (!$stMovUpdate) throw new RuntimeException('movement_update_failed');
        $stMovUpdate->bind_param('ii', $tenant_id, $mov_id);
        if (!$stMovUpdate->execute() || $stMovUpdate->affected_rows !== 1) throw new RuntimeException('movement_update_failed');
        $stMovUpdate->close();

        $contaId = (int)$mov['conta_id'];
        $stTituloUpdate = $db->prepare("UPDATE $tabela SET status = ?, $campo_data = CURRENT_DATE,
            movimentacao_bancaria_id = ?, conta_bancaria_id = ?, conciliado = 1,
            data_conciliacao = CURRENT_TIMESTAMP, usuario_conciliacao = ?
            WHERE tenant_id = ? AND id = ? AND ativo = 1 AND status = 'PENDENTE'");
        if (!$stTituloUpdate) throw new RuntimeException('title_update_failed');
        if (!$stTituloUpdate->bind_param('siisii', $status_novo, $mov_id, $contaId, $usuario, $tenant_id, $titulo_id)) {
            $stTituloUpdate->close();
            throw new RuntimeException('title_update_failed');
        }
        if (!$stTituloUpdate->execute() || $stTituloUpdate->affected_rows !== 1) throw new RuntimeException('title_update_failed');
        $stTituloUpdate->close();

        $stmt = $db->prepare("INSERT INTO conciliacoes
            (tenant_id, movimentacao_id, tipo_titulo, titulo_id, score, tipo_conciliacao, criterios, conciliado_por)
            VALUES (?,?,?,?,0,'manual',NULL,?)");
        if (!$stmt || !$stmt->bind_param('iisis', $tenant_id, $mov_id, $tipo_titulo, $titulo_id, $usuario)
            || !$stmt->execute()) throw new RuntimeException('reconciliation_insert_failed');
        $conc_id = $db->insert_id;
        $stmt->close();
        if ($conc_id <= 0) throw new RuntimeException('reconciliation_insert_failed');

        $stMovLink = $db->prepare('UPDATE movimentacoes_bancarias SET conciliacao_id = ? WHERE tenant_id = ? AND id = ?');
        if (!$stMovLink) throw new RuntimeException('movement_link_failed');
        $stMovLink->bind_param('iii', $conc_id, $tenant_id, $mov_id);
        if (!$stMovLink->execute()) throw new RuntimeException('movement_link_failed');
        $stMovLink->close();
        if (!erp_registrar_log_em_conexao(
            $db,
            'CONCILIACAO_MANUAL',
            'Conciliação bancária manual concluída.',
            'usuario_id=' . (int)($_SESSION['usuario_id'] ?? 0),
            false
        )) throw new RuntimeException('reconciliation_audit_failed');
        if (!$db->commit()) throw new RuntimeException('reconciliation_commit_failed');
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Falha na conciliação bancária manual (' . get_class($e) . ').');
        _json(false, 'Não foi possível concluir a conciliação bancária.', null, 503);
    }

    _json(true, 'Conciliação realizada com sucesso', ['conciliacao_id' => $conc_id ?? 0]);
}

function _desfazer_conciliacao($db, array $body) {
    global $tenant_id;
    verificarPermissao('gerente');
    $conc_id = intval($body['conc_id'] ?? 0);
    if (!$conc_id) _json(false, 'conc_id obrigatório', null, 400);

    if (!$db->begin_transaction()) _json(false, 'Não foi possível iniciar o desfazimento da conciliação.', null, 503);
    try {
        $stConc = $db->prepare('SELECT movimentacao_id, tipo_titulo, titulo_id FROM conciliacoes WHERE tenant_id = ? AND id = ? AND ativa = 1 FOR UPDATE');
        if (!$stConc) throw new RuntimeException('reconciliation_lookup_failed');
        $stConc->bind_param('ii', $tenant_id, $conc_id);
        if (!$stConc->execute()) throw new RuntimeException('reconciliation_lookup_failed');
        $conc = $stConc->get_result()->fetch_assoc();
        $stConc->close();
        if (!$conc || !in_array($conc['tipo_titulo'], ['receber', 'pagar'], true)) {
            $db->rollback();
            _json(false, 'Conciliação não encontrada ou já desfeita', null, 404);
        }

        $tabela = $conc['tipo_titulo'] === 'receber' ? 'contas_receber' : 'contas_pagar';
        $campoData = $conc['tipo_titulo'] === 'receber' ? 'data_recebimento' : 'data_pagamento';
        $statusNovo = $conc['tipo_titulo'] === 'receber' ? 'RECEBIDO' : 'PAGO';
        $usuario = (string)($_SESSION['usuario_nome'] ?? 'sistema');

        $stMov = $db->prepare("UPDATE movimentacoes_bancarias SET status = 'pendente', conciliado = 0, conciliacao_id = NULL WHERE tenant_id = ? AND id = ? AND conciliacao_id = ?");
        if (!$stMov) throw new RuntimeException('movement_undo_failed');
        $stMov->bind_param('iii', $tenant_id, $conc['movimentacao_id'], $conc_id);
        if (!$stMov->execute() || $stMov->affected_rows !== 1) throw new RuntimeException('movement_undo_failed');
        $stMov->close();

        $movimentacaoId = (int)$conc['movimentacao_id'];
        $stTitulo = $db->prepare("UPDATE $tabela SET status = 'PENDENTE', $campoData = NULL,
            movimentacao_bancaria_id = NULL, conta_bancaria_id = NULL, conciliado = 0,
            data_conciliacao = NULL, usuario_conciliacao = NULL
            WHERE tenant_id = ? AND id = ? AND status = ? AND ativo = 1 AND movimentacao_bancaria_id = ?");
        if (!$stTitulo) throw new RuntimeException('title_undo_failed');
        if (!$stTitulo->bind_param('iisi', $tenant_id, $conc['titulo_id'], $statusNovo, $movimentacaoId)) {
            $stTitulo->close();
            throw new RuntimeException('title_undo_failed');
        }
        if (!$stTitulo->execute() || $stTitulo->affected_rows !== 1) throw new RuntimeException('title_undo_failed');
        $stTitulo->close();

        $stDesfazer = $db->prepare('UPDATE conciliacoes SET ativa = 0, desfeito_por = ?, desfeito_em = NOW() WHERE tenant_id = ? AND id = ? AND ativa = 1');
        if (!$stDesfazer) throw new RuntimeException('reconciliation_undo_failed');
        $stDesfazer->bind_param('sii', $usuario, $tenant_id, $conc_id);
        if (!$stDesfazer->execute() || $stDesfazer->affected_rows !== 1) throw new RuntimeException('reconciliation_undo_failed');
        $stDesfazer->close();
        if (!$db->commit()) throw new RuntimeException('reconciliation_undo_commit_failed');
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Falha ao desfazer conciliação bancária (' . get_class($e) . ').');
        _json(false, 'Não foi possível desfazer a conciliação.', null, 503);
    }

    _json(true, 'Conciliação desfeita');
}

function _pendentes_conciliacao($db) {
    global $tenant_id;
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo     = $_GET['tipo']    ?? '';
    $dt_ini   = $_GET['dt_ini']  ?? '';
    $dt_fim   = $_GET['dt_fim']  ?? '';
    $busca    = $_GET['busca']   ?? '';
    $limite   = min(intval($_GET['limite'] ?? 50), 200);
    $offset   = intval($_GET['offset'] ?? 0);

    $where = ["mb.tenant_id = $tenant_id", "mb.status = 'pendente'"];
    $params = []; $types = '';

    if ($conta_id) { $where[] = 'mb.conta_id = ?'; $params[] = $conta_id; $types .= 'i'; }
    if ($tipo)     { $where[] = 'mb.tipo = ?';      $params[] = $tipo;     $types .= 's'; }
    if ($dt_ini)   { $where[] = 'mb.data_lancamento >= ?'; $params[] = $dt_ini; $types .= 's'; }
    if ($dt_fim)   { $where[] = 'mb.data_lancamento <= ?'; $params[] = $dt_fim; $types .= 's'; }
    if ($busca)    {
        $where[] = '(mb.descricao LIKE ? OR mb.favorecido LIKE ?)';
        $params[] = "%$busca%"; $params[] = "%$busca%"; $types .= 'ss';
    }

    $where_sql = implode(' AND ', $where);

    // Subconsultas de candidatos
    $sql = "SELECT mb.*,
                cb.nome AS conta_nome,
                (SELECT COUNT(*) FROM contas_receber cr WHERE tenant_id = $tenant_id AND cr.ativo=1 AND cr.status='PENDENTE'
                   AND cr.valor_original BETWEEN mb.valor*0.95 AND mb.valor*1.05
                   AND cr.data_vencimento BETWEEN DATE_SUB(mb.data_lancamento,INTERVAL 30 DAY) AND DATE_ADD(mb.data_lancamento,INTERVAL 30 DAY)
                ) AS cand_receber,
                (SELECT COUNT(*) FROM contas_pagar cp WHERE tenant_id = $tenant_id AND cp.ativo=1 AND cp.status='PENDENTE'
                   AND cp.valor_original BETWEEN mb.valor*0.95 AND mb.valor*1.05
                   AND cp.data_vencimento BETWEEN DATE_SUB(mb.data_lancamento,INTERVAL 30 DAY) AND DATE_ADD(mb.data_lancamento,INTERVAL 30 DAY)
                ) AS cand_pagar
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id AND cb.tenant_id = $tenant_id
            WHERE $where_sql
            ORDER BY mb.data_lancamento DESC, mb.id DESC
            LIMIT ? OFFSET ?";
    $params[] = $limite; $types .= 'i';
    $params[] = $offset; $types .= 'i';

    $stmt = $db->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['valor'] = (float)$r['valor'];
        $rows[] = $r;
    }

    $types_c  = substr($types, 0, -2);
    $params_c = array_slice($params, 0, -2);
    $stmt2 = $db->prepare("SELECT COUNT(*) AS total FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where_sql");
    if ($types_c) $stmt2->bind_param($types_c, ...$params_c);
    $stmt2->execute();
    $total = $stmt2->get_result()->fetch_assoc()['total'] ?? 0;

    _json(true, 'OK', ['movimentacoes' => $rows, 'total' => (int)$total]);
}

function _candidatos_conciliacao($db) {
    global $tenant_id;
    $mov_id = intval($_GET['mov_id'] ?? 0);
    if (!$mov_id) _json(false, 'mov_id obrigatório', null, 400);

    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$mov_id")->fetch_assoc();
    if (!$mov) _json(false, 'Movimentação não encontrada', null, 404);

    $tol   = $mov['valor'] * 0.05;
    $v_min = $mov['valor'] - $tol;
    $v_max = $mov['valor'] + $tol;
    $dt    = $mov['data_lancamento'];

    $receber = []; $pagar = [];

    $res = $db->query("SELECT id, numero_documento, descricao, valor_original, data_vencimento, morador_nome AS favorecido
                       FROM contas_receber WHERE tenant_id = $tenant_id AND ativo=1 AND status='PENDENTE'
                         AND valor_original BETWEEN $v_min AND $v_max
                         AND data_vencimento BETWEEN DATE_SUB('$dt',INTERVAL 30 DAY) AND DATE_ADD('$dt',INTERVAL 30 DAY)
                       LIMIT 15");
    if ($res) while ($r = $res->fetch_assoc()) { $r['valor_original'] = (float)$r['valor_original']; $receber[] = $r; }

    $res2 = $db->query("SELECT id, numero_documento, descricao, valor_original, data_vencimento, '' AS favorecido
                        FROM contas_pagar WHERE tenant_id = $tenant_id AND ativo=1 AND status='PENDENTE'
                          AND valor_original BETWEEN $v_min AND $v_max
                          AND data_vencimento BETWEEN DATE_SUB('$dt',INTERVAL 30 DAY) AND DATE_ADD('$dt',INTERVAL 30 DAY)
                        LIMIT 15");
    if ($res2) while ($r = $res2->fetch_assoc()) { $r['valor_original'] = (float)$r['valor_original']; $pagar[] = $r; }

    _json(true, 'OK', ['movimentacao' => $mov, 'receber' => $receber, 'pagar' => $pagar]);
}

function _dashboard_financeiro($db) {
    global $tenant_id;
    $mes = date('Y-m');

    $r_banco = $db->query("SELECT COALESCE(SUM(saldo_atual),0) AS s FROM contas_bancarias WHERE tenant_id = $tenant_id AND ativo=1")->fetch_assoc();
    $r_mes   = $db->query("SELECT
        COALESCE(SUM(CASE WHEN tipo='credito' THEN valor ELSE 0 END),0) AS entradas,
        COALESCE(SUM(CASE WHEN tipo='debito'  THEN valor ELSE 0 END),0) AS saidas
        FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND DATE_FORMAT(data_lancamento,'%Y-%m')='$mes'")->fetch_assoc();
    $r_pend  = $db->query("SELECT COUNT(*) AS c FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND status='pendente'")->fetch_assoc();

    _json(true, 'OK', [
        'saldo_bancario'         => (float)($r_banco['s']        ?? 0),
        'entradas_mes'           => (float)($r_mes['entradas']   ?? 0),
        'saidas_mes'             => (float)($r_mes['saidas']     ?? 0),
        'pendentes_conciliacao'  => (int)  ($r_pend['c']         ?? 0),
    ]);
}

// ─── Garantir tabelas (migration silenciosa) ──────────
function _garantir_tabelas($db): bool {
    if (\ERP\Database\DatabaseDialect::isPostgres($db)) return true;
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'contas_bancarias')) {
        _executar_migration();
    }
    return _garantir_colunas_v2($db);
}

function _garantir_colunas_v2($db): bool {
    if (\ERP\Database\DatabaseDialect::isPostgres($db)) return true;
    $ok = true;
    // Adiciona colunas v2 se ainda não existirem (idempotente via INFORMATION_SCHEMA)
    $db_name = DB_NAME;
    $colunas = [
        ['movimentacoes_bancarias', 'favorecido',       'VARCHAR(255) NULL'],
        ['movimentacoes_bancarias', 'memo',             'TEXT NULL'],
        ['movimentacoes_bancarias', 'payee',            'VARCHAR(255) NULL'],
        ['movimentacoes_bancarias', 'numero_documento', 'VARCHAR(60) NULL'],
        ['movimentacoes_bancarias', 'banco_origem',     'VARCHAR(20) NULL'],
        ['movimentacoes_bancarias', 'centro_custo',     'VARCHAR(80) NULL'],
        ['movimentacoes_bancarias', 'conciliacao_id',   'INT UNSIGNED NULL'],
        ['movimentacoes_bancarias', 'status',           "VARCHAR(20) NOT NULL DEFAULT 'pendente'"],
        ['historico_importacoes_ofx', 'conciliadas_auto', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['historico_importacoes_ofx', 'pendentes',        'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['historico_importacoes_ofx', 'formato_ofx',      "VARCHAR(10) NULL"],
        ['contas_receber', 'movimentacao_bancaria_id', 'BIGINT UNSIGNED NULL'],
        ['contas_receber', 'conta_bancaria_id', 'INT UNSIGNED NULL'],
        ['contas_receber', 'conciliado', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['contas_receber', 'data_conciliacao', 'DATETIME NULL'],
        ['contas_receber', 'usuario_conciliacao', 'VARCHAR(80) NULL'],
        ['contas_pagar', 'movimentacao_bancaria_id', 'BIGINT UNSIGNED NULL'],
        ['contas_pagar', 'conta_bancaria_id', 'INT UNSIGNED NULL'],
        ['contas_pagar', 'conciliado', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['contas_pagar', 'data_conciliacao', 'DATETIME NULL'],
        ['contas_pagar', 'usuario_conciliacao', 'VARCHAR(80) NULL'],
    ];
    foreach ($colunas as [$tabela, $coluna, $def]) {
        $r = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='$tabela' AND COLUMN_NAME='$coluna'");
        if (!$r) {
            $ok = false;
            continue;
        }
        if ($r->num_rows === 0 && !$db->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def")) {
            $ok = false;
        }
    }
    // Tabela conciliacoes
    if (!\ERP\Database\DatabaseDialect::tableExists($db, 'conciliacoes') && !$db->query("CREATE TABLE conciliacoes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            movimentacao_id BIGINT UNSIGNED NOT NULL,
            tipo_titulo ENUM('receber','pagar') NOT NULL,
            titulo_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            tipo_conciliacao ENUM('automatica','manual') NOT NULL DEFAULT 'manual',
            criterios LONGTEXT NULL,
            ativa TINYINT(1) NOT NULL DEFAULT 1,
            conciliado_por VARCHAR(80) NULL,
            conciliado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            desfeito_por VARCHAR(80) NULL,
            desfeito_em DATETIME NULL,
            INDEX idx_conc_tenant_movimentacao (tenant_id, movimentacao_id),
            INDEX idx_conc_titulo (tipo_titulo, titulo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
        $ok = false;
    }
    foreach ([['desfeito_por', 'VARCHAR(80) NULL'], ['desfeito_em', 'DATETIME NULL']] as [$coluna, $def]) {
        if (!\ERP\Database\DatabaseDialect::columnExists($db, 'conciliacoes', $coluna)
            && !$db->query("ALTER TABLE conciliacoes ADD COLUMN `$coluna` $def")) {
            $ok = false;
        }
    }
    return $ok;
}

function _executar_migration() {
    verificarPermissao('admin');
    header('Content-Type: application/json; charset=utf-8');
    $db = conectar_banco();
    if (\ERP\Database\DatabaseDialect::isPostgres($db)) {
        foreach (['contas_bancarias', 'historico_importacoes_ofx', 'movimentacoes_bancarias', 'bancos_brasileiros', 'conciliacoes', 'modulos_sistema'] as $table) {
            if (!\ERP\Database\DatabaseDialect::tableExists($db, $table)) {
                _json(false, 'A estrutura PostgreSQL não foi provisionada completamente pelo DDL versionado.', null, 503);
            }
        }
        foreach (['contas_bancarias', 'historico_importacoes_ofx', 'movimentacoes_bancarias', 'conciliacoes', 'contas_receber', 'contas_pagar'] as $table) {
            if (!\ERP\Database\DatabaseDialect::tableExists($db, $table)
                || !\ERP\Database\DatabaseDialect::columnExists($db, $table, 'tenant_id')) {
                _json(false, 'Schema financeiro tenant-scoped não está pronto.', null, 503);
            }
        }
        foreach ([
            'movimentacoes_bancarias' => ['status', 'conciliado', 'conciliacao_id', 'fitid', 'conta_id'],
            'historico_importacoes_ofx' => ['conciliadas_auto', 'pendentes', 'formato_ofx'],
            'conciliacoes' => ['ativa', 'movimentacao_id', 'tipo_titulo', 'titulo_id', 'desfeito_por', 'desfeito_em'],
            'contas_receber' => ['movimentacao_bancaria_id', 'conta_bancaria_id', 'conciliado', 'data_conciliacao', 'usuario_conciliacao'],
            'contas_pagar' => ['movimentacao_bancaria_id', 'conta_bancaria_id', 'conciliado', 'data_conciliacao', 'usuario_conciliacao'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!\ERP\Database\DatabaseDialect::columnExists($db, $table, $column)) {
                    _json(false, 'Schema financeiro não contém os campos de conciliação esperados.', null, 503);
                }
            }
        }
        $banks = $db->query('SELECT COUNT(*) AS n FROM bancos_brasileiros');
        $bankCount = $banks ? (int)($banks->fetch_assoc()['n'] ?? 0) : -1;
        $module = $db->prepare("SELECT 1 FROM modulos_sistema WHERE chave = ? LIMIT 1");
        if (!$module) _json(false, 'Não foi possível verificar o módulo bancário.', null, 503);
        $moduleKey = 'contas_bancarias';
        $module->bind_param('s', $moduleKey);
        $moduleExecuted = $module->execute();
        $moduleResult = $moduleExecuted ? $module->get_result() : null;
        if (!$moduleExecuted || !$moduleResult || !$moduleResult->fetch_assoc()) {
            $module->close();
            _json(false, 'Módulo bancário não foi provisionado pelo seed versionado.', null, 503);
        }
        $module->close();
        if ($bankCount <= 0 || !_bancos_seed_versionado_completo($db)) {
            _json(false, 'Referências bancárias não foram provisionadas integralmente pelo seed versionado.', null, 503);
        }
        _json(true, 'Estrutura PostgreSQL mantida; seeds bancários verificados.');
    }
    $sqls = [];

    // contas_bancarias
    $sqls[] = "CREATE TABLE IF NOT EXISTS contas_bancarias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        nome VARCHAR(120) NOT NULL,
        banco_codigo VARCHAR(10) NOT NULL,
        banco_nome VARCHAR(80) NOT NULL,
        agencia VARCHAR(20) NOT NULL,
        conta_numero VARCHAR(30) NOT NULL,
        conta_tipo ENUM('corrente','poupanca','investimento','caixa') NOT NULL DEFAULT 'corrente',
        moeda CHAR(3) NOT NULL DEFAULT 'BRL',
        saldo_inicial DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        saldo_atual DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        observacoes TEXT,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_conta (tenant_id, banco_codigo, agencia, conta_numero),
        UNIQUE KEY uq_conta_tenant_id (tenant_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // historico_importacoes_ofx (antes de movimentacoes por causa da FK)
    $sqls[] = "CREATE TABLE IF NOT EXISTS historico_importacoes_ofx (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        conta_id INT UNSIGNED NOT NULL,
        nome_arquivo VARCHAR(255) NOT NULL,
        banco_id_ofx VARCHAR(20) NULL,
        acct_id_ofx VARCHAR(40) NULL,
        dt_inicio_ofx DATE NULL,
        dt_fim_ofx DATE NULL,
        ultimo_fitid VARCHAR(80) NULL,
        ultima_data DATE NULL,
        total_transacoes INT UNSIGNED NOT NULL DEFAULT 0,
        importadas INT UNSIGNED NOT NULL DEFAULT 0,
        duplicadas INT UNSIGNED NOT NULL DEFAULT 0,
        saldo_final_ofx DECIMAL(15,2) NULL,
        importado_por VARCHAR(80) NULL,
        importado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_imp_conta FOREIGN KEY (tenant_id, conta_id) REFERENCES contas_bancarias(tenant_id, id) ON DELETE CASCADE,
        INDEX idx_conta_data (tenant_id, conta_id, importado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // movimentacoes_bancarias
    $sqls[] = "CREATE TABLE IF NOT EXISTS movimentacoes_bancarias (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        conta_id INT UNSIGNED NOT NULL,
        fitid VARCHAR(80) NULL,
        tipo ENUM('credito','debito') NOT NULL,
        valor DECIMAL(15,2) NOT NULL,
        data_lancamento DATE NOT NULL,
        descricao VARCHAR(500) NOT NULL,
        checknum VARCHAR(30) NULL,
        categoria VARCHAR(80) NULL,
        conciliado TINYINT(1) NOT NULL DEFAULT 0,
        origem ENUM('ofx','manual','importacao') NOT NULL DEFAULT 'ofx',
        importacao_id INT UNSIGNED NULL,
        observacoes TEXT,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_mov_conta FOREIGN KEY (tenant_id, conta_id) REFERENCES contas_bancarias(tenant_id, id) ON DELETE CASCADE,
        UNIQUE KEY uq_fitid_conta (tenant_id, conta_id, fitid),
        UNIQUE KEY uq_mov_tenant_id (tenant_id, id),
        INDEX idx_conta_data (tenant_id, conta_id, data_lancamento),
        INDEX idx_tipo (tipo),
        INDEX idx_conciliado (conciliado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // View
    $sqls[] = "CREATE OR REPLACE VIEW vw_saldo_contas AS
        SELECT cb.tenant_id, cb.id, cb.nome, cb.banco_nome, cb.agencia, cb.conta_numero, cb.conta_tipo, cb.saldo_inicial,
            COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0) AS total_creditos,
            COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS total_debitos,
            cb.saldo_inicial
                + COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS saldo_calculado
        FROM contas_bancarias cb
        LEFT JOIN movimentacoes_bancarias mb ON mb.tenant_id = cb.tenant_id AND mb.conta_id = cb.id
        WHERE cb.ativo = 1
        GROUP BY cb.tenant_id, cb.id";

    // Tabela bancos_brasileiros
    $sqls[] = "CREATE TABLE IF NOT EXISTS bancos_brasileiros (
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        codigo    VARCHAR(10)  NOT NULL,
        ispb      VARCHAR(8)   DEFAULT NULL,
        nome      VARCHAR(120) NOT NULL,
        nome_curto VARCHAR(60) DEFAULT NULL,
        ativo     TINYINT(1)   NOT NULL DEFAULT 1,
        UNIQUE KEY uk_codigo (codigo),
        KEY idx_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Seed módulo
    $sqls[] = "INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, icone, grupo, ordem)
        VALUES ('contas_bancarias','Contas Bancárias','Cadastro de contas e importação OFX','fas fa-university','financeiro',55)";

    $erros = [];
    foreach ($sqls as $sql) {
        if (!$db->query($sql)) $erros[] = 'statement_failed';
    }

    if (!_garantir_colunas_v2($db)) $erros[] = 'v2_schema_incomplete';
    foreach (['contas_bancarias', 'movimentacoes_bancarias', 'historico_importacoes_ofx', 'conciliacoes', 'contas_receber', 'contas_pagar'] as $table) {
        if (!\ERP\Database\DatabaseDialect::tableExists($db, $table)
            || !\ERP\Database\DatabaseDialect::columnExists($db, $table, 'tenant_id')) {
            $erros[] = 'tenant_scope_unresolved';
            break;
        }
    }

    if (!_aplicar_seed_bancos_brasileiros($db)) $erros[] = 'bank_seed_incomplete';

    if ($erros) http_response_code(503);
    echo json_encode([
        'sucesso'  => empty($erros),
        'mensagem' => empty($erros) ? 'Migration executada com sucesso' : 'Não foi possível concluir a migration bancária.',
        'erros'    => $erros,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
