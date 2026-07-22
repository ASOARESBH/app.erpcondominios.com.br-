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
        'errno' => $errno, 'errstr' => $errstr,
        'errfile' => basename($errfile), 'errline' => $errline
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['sucesso'=>false,'mensagem'=>"Erro interno: $errstr (linha $errline)"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false; // continua o handler padrão para warnings
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = __DIR__ . '/../logs/debug_contas_bancarias.log';
        $entry = date('Y-m-d H:i:s') . ' | FATAL_ERROR | ' . json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso'=>false,'mensagem'=>'Erro fatal: '.$error['message']], JSON_UNESCAPED_UNICODE);
        }
    }
});

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

// ─── Headers ────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

// ─── Log de Debug ────────────────────────────────────
function _debug_log($msg, $dados = []) {
    $log_file = __DIR__ . '/../logs/debug_contas_bancarias.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = date('Y-m-d H:i:s') . ' | ' . $msg;
    if (!empty($dados)) $linha .= ' | ' . json_encode($dados, JSON_UNESCAPED_UNICODE);
    @file_put_contents($log_file, $linha . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$metodo = $_SERVER['REQUEST_METHOD'];
$raw_input = ($metodo !== 'GET') ? file_get_contents('php://input') : '';
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
$session_id = session_id() ?: 'sem-sessao';

_debug_log('REQUEST', [
    'method'       => $metodo,
    'content_type' => $content_type,
    'get_acao'     => $_GET['acao'] ?? '(vazio)',
    'post_acao'    => $_POST['acao'] ?? '(vazio)',
    'raw_input'    => substr($raw_input, 0, 500),
    'session_id'   => $session_id,
    'usuario_logado' => $_SESSION['usuario_logado'] ?? '(sem sessao)',
    'usuario_id'   => $_SESSION['usuario_id'] ?? '(sem sessao)',
]);

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

_debug_log('ACAO_DETERMINADA', [
    'acao'      => $acao,
    'body_keys' => array_keys($body),
    'body_acao' => $body['acao'] ?? '(ausente)',
]);

// ─── Migration automática ─────────────────────────────
if ($acao === 'migration') {
    _executar_migration();
    exit;
}

$db = conectar_banco();
// ─── Migration automática na primeira chamada ─────────
// Garante que as tabelas existam sem precisar chamar ?acao=migration manualmente
_garantir_tabelas($db);

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
    case 'migration_bancos':    _migration_bancos($db); break;

    default:
        _json(false, 'Ação inválida: ' . htmlspecialchars($acao), null, 400);
}

// =====================================================
// CONTAS BANCÁRIAS
// =====================================================

function _listar_contas($db) {
    // Query resiliente: verifica se tabelas auxiliares existem antes de usar subqueries
    $tem_mov = $db->query("SHOW TABLES LIKE 'movimentacoes_bancarias'")->num_rows > 0;
    $tem_ofx = $db->query("SHOW TABLES LIKE 'historico_importacoes_ofx'")->num_rows > 0;

    $sub_mov = $tem_mov
        ? "(SELECT COUNT(*) FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND mb.conta_id = cb.id)"
        : "0";
    $sub_ofx = $tem_ofx
        ? "(SELECT MAX(importado_em) FROM historico_importacoes_ofx hi WHERE hi.conta_id = cb.id)"
        : "NULL";

    $sql = "SELECT cb.*,
                {$sub_mov} AS total_mov,
                {$sub_ofx} AS ultima_importacao
            FROM contas_bancarias cb WHERE tenant_id = $tenant_id AND cb.ativo = 1
            ORDER BY cb.nome ASC";
    $res = $db->query($sql);
    if (!$res) _json(false, 'Erro ao listar contas: ' . $db->error);
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
    verificarPermissao('gerente');
    $campos = ['nome','banco_codigo','banco_nome','agencia','conta_numero','conta_tipo'];
    foreach ($campos as $c) {
        if (empty($body[$c])) _json(false, "Campo obrigatório: $c", null, 400);
    }
    $saldo_ini = (float)($body['saldo_inicial'] ?? 0);
    $stmt = $db->prepare("INSERT INTO contas_bancarias
        (nome, banco_codigo, banco_nome, agencia, conta_numero, conta_tipo, moeda, saldo_inicial, saldo_atual, observacoes)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $moeda = $body['moeda'] ?? 'BRL';
    $obs   = $body['observacoes'] ?? '';
    $stmt->bind_param('sssssssdds',
        $body['nome'], $body['banco_codigo'], $body['banco_nome'],
        $body['agencia'], $body['conta_numero'], $body['conta_tipo'],
        $moeda, $saldo_ini, $saldo_ini, $obs
    );
    if (!$stmt->execute()) _json(false, 'Erro ao criar conta: ' . $stmt->error);
    _json(true, 'Conta criada com sucesso', ['id' => $db->insert_id]);
}

function _atualizar_conta($db, $body) {
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $stmt = $db->prepare("UPDATE contas_bancarias SET
        nome=?, banco_codigo=?, banco_nome=?, agencia=?, conta_numero=?,
        conta_tipo=?, moeda=?, saldo_inicial=?, observacoes=? WHERE tenant_id = $tenant_id AND id=? AND ativo=1");
    $saldo_ini = (float)($body['saldo_inicial'] ?? 0);
    $moeda = $body['moeda'] ?? 'BRL';
    $obs   = $body['observacoes'] ?? '';
    $stmt->bind_param('sssssssdssi',
        $body['nome'], $body['banco_codigo'], $body['banco_nome'],
        $body['agencia'], $body['conta_numero'], $body['conta_tipo'],
        $moeda, $saldo_ini, $obs, $id
    );
    if (!$stmt->execute()) _json(false, 'Erro ao atualizar: ' . $stmt->error);
    _recalcular_saldo($db, $id);
    _json(true, 'Conta atualizada com sucesso');
}

function _excluir_conta($db, $body) {
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
    // Verificar se tabela existe
    if ($db->query("SHOW TABLES LIKE 'movimentacoes_bancarias'")->num_rows === 0) {
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

    $where = ['1=1'];
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
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
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
    verificarPermissao('gerente');
    $conta_id = intval($body['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);
    if (empty($body['tipo']) || !in_array($body['tipo'], ['credito','debito'])) _json(false, 'tipo inválido', null, 400);
    if (empty($body['data_lancamento'])) _json(false, 'data_lancamento obrigatória', null, 400);
    $valor = abs((float)($body['valor'] ?? 0));
    if ($valor <= 0) _json(false, 'valor deve ser maior que zero', null, 400);

    $stmt = $db->prepare("INSERT INTO movimentacoes_bancarias
        (conta_id, tipo, valor, data_lancamento, descricao, favorecido, checknum, numero_documento,
         categoria, centro_custo, status, origem, observacoes)
        VALUES (?,?,?,?,?,?,?,?,?,?,'pendente','manual',?)");
    $desc    = $body['descricao']        ?? '';
    $fav     = $body['favorecido']       ?? null;
    $chk     = $body['checknum']         ?? null;
    $numdoc  = $body['numero_documento'] ?? null;
    $cat     = $body['categoria']        ?? null;
    $ccusto  = $body['centro_custo']     ?? null;
    $obs     = $body['observacoes']      ?? null;
    $dt      = $body['data_lancamento'];
    $stmt->bind_param('isdssssssss', $conta_id, $body['tipo'], $valor, $dt, $desc, $fav, $chk, $numdoc, $cat, $ccusto, $obs);
    if (!$stmt->execute()) _json(false, 'Erro ao criar movimentação: ' . $stmt->error);
    $novo_id = $db->insert_id;
    _recalcular_saldo($db, $conta_id);
    _json(true, 'Movimentação criada', ['id' => $novo_id]);
}

function _atualizar_movimentacao($db, $body) {
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $stmt = $db->prepare("UPDATE movimentacoes_bancarias SET
        tipo=?, valor=?, data_lancamento=?, descricao=?, favorecido=?,
        checknum=?, numero_documento=?, categoria=?, centro_custo=?, status=?, observacoes=? WHERE tenant_id = $tenant_id AND id=?");
    $valor       = abs((float)($body['valor'] ?? 0));
    $upd_tipo    = $body['tipo']             ?? '';
    $upd_dt      = $body['data_lancamento']  ?? '';
    $upd_desc    = $body['descricao']        ?? '';
    $upd_fav     = $body['favorecido']       ?? '';
    $upd_chknum  = $body['checknum']         ?? '';
    $upd_numdoc  = $body['numero_documento'] ?? '';
    $upd_cat     = $body['categoria']        ?? '';
    $upd_ccusto  = $body['centro_custo']     ?? '';
    $upd_status  = $body['status']           ?? 'pendente';
    $upd_obs     = $body['observacoes']      ?? '';
    $stmt->bind_param('sdsssssssssi',
        $upd_tipo, $valor, $upd_dt,
        $upd_desc, $upd_fav, $upd_chknum, $upd_numdoc,
        $upd_cat, $upd_ccusto, $upd_status, $upd_obs, $id
    );
    if (!$stmt->execute()) _json(false, 'Erro ao atualizar: ' . $stmt->error);
    // Recalcular saldo da conta
    $r = $db->query("SELECT conta_id FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$id")->fetch_assoc();
    if ($r) _recalcular_saldo($db, $r['conta_id']);
    _json(true, 'Movimentação atualizada');
}

function _excluir_movimentacao($db, $body) {
    verificarPermissao('gerente');
    $id = intval($body['id'] ?? 0);
    if (!$id) _json(false, 'ID inválido', null, 400);
    $r = $db->query("SELECT conta_id FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$id")->fetch_assoc();
    $stmt = $db->prepare("DELETE FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($r) _recalcular_saldo($db, $r['conta_id']);
    _json(true, 'Movimentação excluída');
}

function _conciliar($db, $body) {
    verificarPermissao('gerente');
    $ids = $body['ids'] ?? [];
    $conciliado = intval($body['conciliado'] ?? 1);
    if (empty($ids)) _json(false, 'Nenhum ID informado', null, 400);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $db->prepare("UPDATE movimentacoes_bancarias SET conciliado=? WHERE tenant_id = $tenant_id AND id IN ($placeholders)");
    $params = array_merge([$conciliado], $ids);
    $types = 'i' . $types;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
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
    if (empty($_FILES['ofx_file'])) _json(false, 'Arquivo OFX não enviado', null, 400);
    $conta_id = intval($_POST['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);

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

    $ultimo_fitid = _buscar_ultimo_fitid($db, $conta_id);

    $importadas = 0; $duplicatas = 0; $erros = 0;
    $conciliadas_auto = 0; $pendentes_conc = 0;
    $ultimo_fitid_novo = null; $ultima_data_nova = null;
    $apos_ultimo = ($ultimo_fitid === null);
    $ids_inseridos = [];

    $db->begin_transaction();
    try {
        $stmt = $db->prepare("INSERT IGNORE INTO movimentacoes_bancarias
            (conta_id, fitid, tipo, valor, data_lancamento, descricao, favorecido, memo, checknum,
             numero_documento, origem, importacao_id, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,'ofx',?,'pendente')");

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
            $imp_id    = 0;

            $stmt->bind_param('issdssssssi',
                $conta_id, $t_fitid, $t_tipo, $t_valor,
                $t_data, $t_desc, $t_fav, $t_memo, $t_chknum, $t_numdoc, $imp_id
            );
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
            }
        }

        // Registrar no histórico
        $nome_arq      = $_FILES['ofx_file']['name'];
        $usuario       = $_SESSION['usuario_nome'] ?? 'sistema';
        $banco_id_ofx  = $parsed['banco_id']  ?? '';
        $acct_id_ofx   = $parsed['acct_id']   ?? '';
        $dt_inicio_ofx = $parsed['dt_inicio'] ?? '';
        $dt_fim_ofx    = $parsed['dt_fim']    ?? '';
        $fitid_final   = $ultimo_fitid_novo ?? $ultimo_fitid ?? '';
        $saldo_final   = (float)($parsed['saldo_final'] ?? 0);
        $total         = count($parsed['transacoes']);
        $formato_ofx   = $parsed['formato'] ?? 'sgml';

        $stmt2 = $db->prepare("INSERT INTO historico_importacoes_ofx
            (conta_id, nome_arquivo, banco_id_ofx, acct_id_ofx, dt_inicio_ofx, dt_fim_ofx,
             ultimo_fitid, ultima_data, total_transacoes, importadas, duplicadas, saldo_final_ofx,
             importado_por, conciliadas_auto, pendentes, formato_ofx)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt2->bind_param('isssssssiiiidssi',
            $conta_id, $nome_arq, $banco_id_ofx, $acct_id_ofx,
            $dt_inicio_ofx, $dt_fim_ofx,
            $fitid_final, $ultima_data_nova,
            $total, $importadas, $duplicatas, $saldo_final, $usuario,
            $conciliadas_auto, $pendentes_conc, $formato_ofx
        );
        // Fallback: se colunas v2 ainda não existirem, insere sem elas
        if (!$stmt2->execute()) {
            $stmt2b = $db->prepare("INSERT INTO historico_importacoes_ofx
                (conta_id, nome_arquivo, banco_id_ofx, acct_id_ofx, dt_inicio_ofx, dt_fim_ofx,
                 ultimo_fitid, ultima_data, total_transacoes, importadas, duplicadas, saldo_final_ofx, importado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt2b->bind_param('isssssssiiids',
                $conta_id, $nome_arq, $banco_id_ofx, $acct_id_ofx,
                $dt_inicio_ofx, $dt_fim_ofx,
                $fitid_final, $ultima_data_nova,
                $total, $importadas, $duplicatas, $saldo_final, $usuario
            );
            $stmt2b->execute();
        }
        $imp_id_novo = $db->insert_id;

        // Atualizar importacao_id nas inseridas
        if ($importadas > 0 && $imp_id_novo && !empty($ids_inseridos)) {
            $placeholders = implode(',', $ids_inseridos);
            $db->query("UPDATE movimentacoes_bancarias SET importacao_id=$imp_id_novo WHERE tenant_id = $tenant_id AND id IN ($placeholders)");
        }

        $db->commit();

        // Motor de conciliação pós-insert (fora da transaction para não bloquear)
        foreach ($ids_inseridos as $mov_id) {
            $res = _motor_conciliacao($db, $mov_id);
            if ($res === true) $conciliadas_auto++;
            else $pendentes_conc++;
        }

        // Atualizar contadores no histórico
        if ($imp_id_novo && ($conciliadas_auto || $pendentes_conc)) {
            $db->query("UPDATE historico_importacoes_ofx SET
                conciliadas_auto=$conciliadas_auto, pendentes=$pendentes_conc
                WHERE id=$imp_id_novo");
        }

    } catch (Exception $e) {
        $db->rollback();
        _json(false, 'Erro durante importação: ' . $e->getMessage());
    }

    _recalcular_saldo($db, $conta_id);

    _json(true, 'Importação concluída', [
        'importadas'       => $importadas,
        'duplicatas'       => $duplicatas,
        'erros'            => $erros,
        'total_arq'        => count($parsed['transacoes']),
        'ultimo_fitid'     => $ultimo_fitid_novo ?? $ultimo_fitid,
        'conciliadas_auto' => $conciliadas_auto,
        'pendentes'        => $pendentes_conc,
        'formato_ofx'      => $parsed['formato'] ?? 'sgml',
    ]);
}

function _historico_importacoes($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $where = $conta_id ? "WHERE hi.conta_id = $conta_id" : '';
    $sql = "SELECT hi.*, cb.nome AS conta_nome
            FROM historico_importacoes_ofx hi
            JOIN contas_bancarias cb ON cb.id = hi.conta_id
            $where
            ORDER BY hi.importado_em DESC
            LIMIT 100";
    $res = $db->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    _json(true, 'OK', $rows);
}

function _ultimo_importado($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    if (!$conta_id) _json(false, 'conta_id obrigatório', null, 400);
    // Verificar se tabela existe
    if ($db->query("SHOW TABLES LIKE 'historico_importacoes_ofx'")->num_rows === 0) {
        _json(true, 'OK', null);
    }
    $res = $db->query("SELECT ultimo_fitid, ultima_data, importado_em, nome_arquivo
                     FROM historico_importacoes_ofx
                     WHERE conta_id=$conta_id
                     ORDER BY importado_em DESC LIMIT 1");
    $r = $res ? $res->fetch_assoc() : null;
    _json(true, 'OK', $r ?? null);
}

// =====================================================
// KPIs e RELATÓRIOS
// =====================================================

function _kpis($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $mes = $_GET['mes'] ?? date('Y-m');

    $where_conta = $conta_id ? "AND mb.conta_id = $conta_id" : '';
    $where_mes   = "AND DATE_FORMAT(mb.data_lancamento,'%Y-%m') = '$mes'";

    $sql = "SELECT
        COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END), 0) AS total_creditos,
        COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END), 0) AS total_debitos,
        COUNT(*) AS total_mov,
        SUM(CASE WHEN mb.conciliado=0 THEN 1 ELSE 0 END) AS pendentes_conciliacao
        FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND 1=1 $where_conta $where_mes";
    $r = $db->query($sql)->fetch_assoc();

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
    $res = $db->query("SELECT * FROM vw_saldo_contas ORDER BY nome ASC");
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
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $dt_ini   = $_GET['dt_ini'] ?? date('Y-m-01');
    $dt_fim   = $_GET['dt_fim'] ?? date('Y-m-d');

    $where = "WHERE mb.data_lancamento BETWEEN ? AND ?";
    $params = [$dt_ini, $dt_fim];
    $types  = 'ss';
    if ($conta_id) { $where .= ' AND mb.conta_id = ?'; $params[] = $conta_id; $types .= 'i'; }

    $sql = "SELECT mb.*, cb.nome AS conta_nome, cb.banco_nome, cb.agencia, cb.conta_numero
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
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
function _recalcular_saldo($db, $conta_id) {
    $db->query("UPDATE contas_bancarias cb
        SET saldo_atual = cb.saldo_inicial
            + COALESCE((SELECT SUM(valor) FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND conta_id=$conta_id AND tipo='credito'),0)
            - COALESCE((SELECT SUM(valor) FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND conta_id=$conta_id AND tipo='debito'),0)
        WHERE id=$conta_id");
}

/** Busca o FITID da última transação importada para a conta */
function _buscar_ultimo_fitid($db, $conta_id) {
    $r = $db->query("SELECT ultimo_fitid FROM historico_importacoes_ofx
                     WHERE conta_id=$conta_id AND ultimo_fitid IS NOT NULL
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
    $chk = $db->query("SHOW TABLES LIKE 'bancos_brasileiros'");
    if (!$chk || $chk->num_rows === 0) {
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
    $stmt2 = $db->prepare("SELECT codigo, ispb, nome, nome_curto FROM bancos_brasileiros WHERE (nome LIKE ? OR nome_curto LIKE ? OR codigo LIKE ?) AND ativo = 1 ORDER BY CAST(codigo AS UNSIGNED), codigo LIMIT 15");
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
    $chk = $db->query("SHOW TABLES LIKE 'bancos_brasileiros'");
    if (!$chk || $chk->num_rows === 0) _json(true, 'Tabela não migrada', []);

    $res = $db->query("SELECT codigo, ispb, nome, nome_curto FROM bancos_brasileiros WHERE ativo = 1 ORDER BY CAST(codigo AS UNSIGNED), codigo");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    _json(true, 'OK', $rows);
}

/**
 * Cria a tabela bancos_brasileiros e insere o seed completo.
 * GET ?acao=migration_bancos
 */
function _migration_bancos($db) {
    header('Content-Type: application/json; charset=utf-8');

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
        echo json_encode(['sucesso'=>false,'mensagem'=>'Erro ao criar tabela: '.$db->error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verificar se já tem dados
    $count = $db->query("SELECT COUNT(*) AS n FROM bancos_brasileiros")->fetch_assoc()['n'];
    if ($count > 0) {
        echo json_encode(['sucesso'=>true,'mensagem'=>"Tabela já populada ($count bancos)"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Inserir seed via arquivo SQL
    $seed_file = __DIR__ . '/../sql/seed_bancos_brasileiros.sql';
    if (!file_exists($seed_file)) {
        // Fallback: inserir os principais bancos inline
        _inserir_bancos_inline($db);
    } else {
        // Executar o arquivo SQL linha a linha
        $sql_content = file_get_contents($seed_file);
        // Remover CREATE TABLE (já feito) e TRUNCATE
        $sql_content = preg_replace('/CREATE TABLE.*?;/s', '', $sql_content);
        $sql_content = preg_replace('/TRUNCATE TABLE.*?;/s', '', $sql_content);
        // Executar multi_query
        if ($db->multi_query($sql_content)) {
            do { if ($res = $db->store_result()) $res->free(); } while ($db->next_result());
        }
        if ($db->errno) {
            // Tentar inserir inline como fallback
            _inserir_bancos_inline($db);
        }
    }

    $total = $db->query("SELECT COUNT(*) AS n FROM bancos_brasileiros")->fetch_assoc()['n'];
    echo json_encode(['sucesso'=>true,'mensagem'=>"$total bancos inseridos com sucesso"], JSON_UNESCAPED_UNICODE);
    exit;
}

function _inserir_bancos_inline($db) {
    // Principais bancos brasileiros (fallback se o arquivo SQL não estiver disponível)
    $bancos = [
        ['001','00000000','Banco do Brasil S.A.','BB'],
        ['033','90400888','Banco Santander (Brasil) S.A.','SANTANDER'],
        ['041','92702067','Banco do Estado do Rio Grande do Sul S.A.','BANRISUL'],
        ['077','00416968','Banco Inter S.A.','INTER'],
        ['104','00360305','Caixa Econômica Federal','CAIXA'],
        ['208','33870163','Banco BTG Pactual S.A.','BTG PACTUAL'],
        ['212','92894922','Banco Original S.A.','ORIGINAL'],
        ['237','60746948','Banco Bradesco S.A.','BRADESCO'],
        ['260','18236120','Nu Pagamentos S.A. — Nubank','NUBANK'],
        ['290','13884775','Pagseguro Internet S.A.','PAGSEGURO'],
        ['318','71371686','Banco BMG S.A.','BMG'],
        ['323','10573521','Mercado Pago — Conta do Mercado Livre','MERCADO PAGO'],
        ['336','00000000','Banco C6 S.A.','C6 BANK'],
        ['341','60701190','Itaú Unibanco S.A.','ITAÚ'],
        ['380','22896431','PicPay Serviços S.A.','PICPAY'],
        ['422','58160789','Banco Safra S.A.','SAFRA'],
        ['748','01181521','Banco Cooperativo Sicredi S.A.','SICREDI'],
        ['756','02038232','Banco Cooperativo do Brasil S.A. — Bancoob','SICOOB'],
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO bancos_brasileiros (codigo,ispb,nome,nome_curto) VALUES (?,?,?,?)");
    foreach ($bancos as $b) {
        $stmt->bind_param('ssss', $b[0], $b[1], $b[2], $b[3]);
        $stmt->execute();
    }
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
    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$mov_id")->fetch_assoc();
    if (!$mov || $mov['status'] !== 'pendente') return false;

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
            WHERE ativo=1 AND status IN ($status_open)
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
    $tipo_titulo = $tabela === 'contas_receber' ? 'receber' : 'pagar';
    $usuario     = $_SESSION['usuario_nome'] ?? 'sistema';

    $db->begin_transaction();
    try {
        $db->query("UPDATE movimentacoes_bancarias SET status='conciliado' WHERE tenant_id = $tenant_id AND id={$mov['id']}");

        $status_novo = $tabela === 'contas_receber' ? 'RECEBIDO' : 'PAGO';
        $campo_data  = $tabela === 'contas_receber' ? 'data_recebimento' : 'data_pagamento';
        $db->query("UPDATE $tabela SET status='$status_novo', $campo_data=CURDATE() WHERE id={$titulo['id']}");

        $criterios = json_encode(['score' => $score, 'tipo' => 'automatica'], JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO conciliacoes
            (movimentacao_id, tipo_titulo, titulo_id, score, tipo_conciliacao, criterios, conciliado_por)
            VALUES (?,?,?,?,?,?,?)");
        $tipo_str = $tipo_titulo;
        $stmt->bind_param('isiisss', $mov['id'], $tipo_str, $titulo['id'], $score, 'automatica', $criterios, $usuario);
        // Silenciar se a tabela não existir
        if (!$stmt->execute()) { $db->rollback(); return false; }

        $conc_id = $db->insert_id;
        $db->query("UPDATE movimentacoes_bancarias SET conciliacao_id=$conc_id WHERE tenant_id = $tenant_id AND id={$mov['id']}");
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollback();
        return false;
    }
}

function _conciliar_manual($db, array $body) {
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

    // Verificar que a movimentação não está já conciliada
    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id=$mov_id")->fetch_assoc();
    if (!$mov) _json(false, 'Movimentação não encontrada', null, 404);
    if ($mov['status'] === 'conciliado') _json(false, 'Movimentação já está conciliada');

    $db->begin_transaction();
    try {
        $db->query("UPDATE movimentacoes_bancarias SET status='conciliado' WHERE tenant_id = $tenant_id AND id=$mov_id");
        $db->query("UPDATE $tabela SET status='$status_novo', $campo_data=CURDATE() WHERE id=$titulo_id AND ativo=1");

        $stmt = $db->prepare("INSERT INTO conciliacoes
            (movimentacao_id, tipo_titulo, titulo_id, score, tipo_conciliacao, criterios, conciliado_por)
            VALUES (?,?,?,0,'manual',NULL,?)");
        $stmt->bind_param('isis', $mov_id, $tipo_titulo, $titulo_id, $usuario);
        $stmt->execute();
        $conc_id = $db->insert_id;

        $db->query("UPDATE movimentacoes_bancarias SET conciliacao_id=$conc_id WHERE tenant_id = $tenant_id AND id=$mov_id");
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        _json(false, 'Erro ao conciliar: ' . $e->getMessage());
    }

    registrar_log($db, 'CONCILIACAO_MANUAL', "mov_id=$mov_id, titulo=$tipo_titulo:$titulo_id", 'conciliacao', $mov_id);
    _json(true, 'Conciliação realizada com sucesso', ['conciliacao_id' => $conc_id ?? 0]);
}

function _desfazer_conciliacao($db, array $body) {
    verificarPermissao('gerente');
    $conc_id = intval($body['conc_id'] ?? 0);
    if (!$conc_id) _json(false, 'conc_id obrigatório', null, 400);

    $conc = $db->query("SELECT * FROM conciliacoes WHERE tenant_id = $tenant_id AND id=$conc_id AND ativa=1")->fetch_assoc();
    if (!$conc) _json(false, 'Conciliação não encontrada ou já desfeita', null, 404);

    $tabela      = $conc['tipo_titulo'] === 'receber' ? 'contas_receber' : 'contas_pagar';
    $campo_data  = $conc['tipo_titulo'] === 'receber' ? 'data_recebimento' : 'data_pagamento';
    $usuario     = $_SESSION['usuario_nome'] ?? 'sistema';

    $db->begin_transaction();
    try {
        $db->query("UPDATE movimentacoes_bancarias SET status='pendente', conciliacao_id=NULL WHERE tenant_id = $tenant_id AND id={$conc['movimentacao_id']}");
        $db->query("UPDATE $tabela SET status='PENDENTE', $campo_data=NULL WHERE id={$conc['titulo_id']}");
        $db->query("UPDATE conciliacoes SET ativa=0, desfeita_por='$usuario', desfeita_em=NOW() WHERE tenant_id = $tenant_id AND id=$conc_id");
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        _json(false, 'Erro ao desfazer: ' . $e->getMessage());
    }

    _json(true, 'Conciliação desfeita');
}

function _pendentes_conciliacao($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo     = $_GET['tipo']    ?? '';
    $dt_ini   = $_GET['dt_ini']  ?? '';
    $dt_fim   = $_GET['dt_fim']  ?? '';
    $busca    = $_GET['busca']   ?? '';
    $limite   = min(intval($_GET['limite'] ?? 50), 200);
    $offset   = intval($_GET['offset'] ?? 0);

    $where = ["mb.status = 'pendente'"];
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
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
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
function _garantir_tabelas($db) {
    $check = $db->query("SHOW TABLES LIKE 'contas_bancarias'");
    if ($check && $check->num_rows === 0) {
        _executar_migration();
    }
    _garantir_colunas_v2($db);
}

function _garantir_colunas_v2($db) {
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
    ];
    foreach ($colunas as [$tabela, $coluna, $def]) {
        $r = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='$tabela' AND COLUMN_NAME='$coluna'");
        if ($r && $r->num_rows === 0) {
            $db->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def");
        }
    }
    // Tabela conciliacoes
    $r = $db->query("SHOW TABLES LIKE 'conciliacoes'");
    if ($r && $r->num_rows === 0) {
        $db->query("CREATE TABLE conciliacoes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            movimentacao_id BIGINT UNSIGNED NOT NULL,
            tipo_titulo ENUM('receber','pagar') NOT NULL,
            titulo_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            tipo_conciliacao ENUM('automatica','manual') NOT NULL DEFAULT 'manual',
            criterios LONGTEXT NULL,
            ativa TINYINT(1) NOT NULL DEFAULT 1,
            conciliado_por VARCHAR(80) NULL,
            conciliado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            desfeita_por VARCHAR(80) NULL,
            desfeita_em DATETIME NULL,
            INDEX idx_conc_movimentacao (movimentacao_id),
            INDEX idx_conc_titulo (tipo_titulo, titulo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function _executar_migration() {
    header('Content-Type: application/json; charset=utf-8');
    $db = conectar_banco();
    $sqls = [];

    // contas_bancarias
    $sqls[] = "CREATE TABLE IF NOT EXISTS contas_bancarias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
        UNIQUE KEY uq_conta (banco_codigo, agencia, conta_numero)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // historico_importacoes_ofx (antes de movimentacoes por causa da FK)
    $sqls[] = "CREATE TABLE IF NOT EXISTS historico_importacoes_ofx (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
        CONSTRAINT fk_imp_conta FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE,
        INDEX idx_conta_data (conta_id, importado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // movimentacoes_bancarias
    $sqls[] = "CREATE TABLE IF NOT EXISTS movimentacoes_bancarias (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
        CONSTRAINT fk_mov_conta FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE,
        UNIQUE KEY uq_fitid_conta (conta_id, fitid),
        INDEX idx_conta_data (conta_id, data_lancamento),
        INDEX idx_tipo (tipo),
        INDEX idx_conciliado (conciliado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // View
    $sqls[] = "CREATE OR REPLACE VIEW vw_saldo_contas AS
        SELECT cb.id, cb.nome, cb.banco_nome, cb.agencia, cb.conta_numero, cb.conta_tipo, cb.saldo_inicial,
            COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0) AS total_creditos,
            COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS total_debitos,
            cb.saldo_inicial
                + COALESCE(SUM(CASE WHEN mb.tipo='credito' THEN mb.valor ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN mb.tipo='debito'  THEN mb.valor ELSE 0 END),0) AS saldo_calculado
        FROM contas_bancarias cb
        LEFT JOIN movimentacoes_bancarias mb ON mb.conta_id = cb.id
        WHERE cb.ativo = 1
        GROUP BY cb.id";

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
        if (!$db->query($sql)) $erros[] = $db->error;
    }

    // Seed bancos brasileiros (se tabela vazia)
    $count_bancos = $db->query("SELECT COUNT(*) AS n FROM bancos_brasileiros")->fetch_assoc()['n'] ?? 0;
    if ($count_bancos == 0) {
        _inserir_bancos_inline($db);
    }

    echo json_encode([
        'sucesso'  => empty($erros),
        'mensagem' => empty($erros) ? 'Migration executada com sucesso' : 'Migration com erros',
        'erros'    => $erros,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
