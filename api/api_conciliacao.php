<?php
/**
 * api_conciliacao.php — Motor de Conciliação Bancária
 * Ações: estatisticas, listar_pendentes, listar_conciliadas,
 *        candidatos, conciliar_manual, desfazer, historico,
 *        atualizar_status
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';

verificarAutenticacao();

header('Content-Type: application/json; charset=utf-8');

$db     = conectar_banco();
$acao   = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($metodo === 'POST') {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? $_POST) : $_POST;
}

switch ($acao) {
    case 'estatisticas':       _estatisticas($db);              break;
    case 'listar_pendentes':   _listar_pendentes($db);          break;
    case 'listar_conciliadas': _listar_conciliadas($db);        break;
    case 'candidatos':         _candidatos($db);                break;
    case 'conciliar_manual':   _conciliar_manual($db, $body);   break;
    case 'desfazer':           _desfazer($db, $body);           break;
    case 'historico':          _historico($db);                  break;
    case 'atualizar_status':   _atualizar_status($db, $body);   break;
    default: _json(false, 'Ação não reconhecida: ' . htmlspecialchars($acao), null, 400);
}

// ─────────────────────────────────────────────────────────────────
// HELPER
// ─────────────────────────────────────────────────────────────────
function _json($ok, $msg, $dados = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['sucesso' => (bool)$ok, 'mensagem' => $msg, 'dados' => $dados],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// AÇÕES
// ─────────────────────────────────────────────────────────────────

function _estatisticas($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $where    = $conta_id ? "AND mb.conta_id = $conta_id" : '';

    $r = $db->query("SELECT
        COUNT(*)                                                       AS total,
        SUM(mb.status = 'pendente')                                    AS pendentes,
        SUM(mb.status = 'conciliado')                                  AS conciliados,
        SUM(mb.status = 'ignorado')                                    AS ignorados,
        SUM(mb.status = 'divergente')                                  AS divergentes,
        SUM(c.tipo_conciliacao = 'automatica' AND c.ativa = 1)        AS auto_conciliados,
        SUM(c.tipo_conciliacao = 'manual'     AND c.ativa = 1)        AS manual_conciliados
        FROM movimentacoes_bancarias mb
        LEFT JOIN conciliacoes c ON c.movimentacao_id = mb.id AND c.ativa = 1
        WHERE 1=1 $where")->fetch_assoc();

    $r['taxa_conciliacao'] = $r['total'] > 0
        ? round(($r['conciliados'] / $r['total']) * 100, 1)
        : 0;

    _json(true, 'OK', $r);
}

function _listar_pendentes($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo     = $_GET['tipo']   ?? '';
    $dt_ini   = $_GET['dt_ini'] ?? '';
    $dt_fim   = $_GET['dt_fim'] ?? '';
    $busca    = trim($_GET['busca'] ?? '');
    $limite   = min(intval($_GET['limite'] ?? 50), 200);
    $offset   = intval($_GET['offset'] ?? 0);

    $conds = ["mb.status = 'pendente'"];
    if ($conta_id) $conds[] = "mb.conta_id = $conta_id";
    if ($tipo && in_array($tipo, ['credito','debito'], true)) {
        $conds[] = "mb.tipo = '" . $db->real_escape_string($tipo) . "'";
    }
    if ($dt_ini) $conds[] = "mb.data_lancamento >= '" . $db->real_escape_string($dt_ini) . "'";
    if ($dt_fim) $conds[] = "mb.data_lancamento <= '" . $db->real_escape_string($dt_fim) . "'";
    if ($busca) {
        $b = $db->real_escape_string($busca);
        $conds[] = "(mb.descricao LIKE '%$b%' OR mb.favorecido LIKE '%$b%' OR mb.checknum LIKE '%$b%')";
    }
    $where = implode(' AND ', $conds);

    $sql = "SELECT
                mb.*,
                cb.nome   AS conta_nome,
                cb.banco_nome,
                (SELECT COUNT(*) FROM contas_receber cr WHERE tenant_id = $tenant_id AND cr.ativo=1 AND cr.conciliado=0 AND cr.status IN ('PENDENTE','PARCIAL')
                   AND ABS(cr.valor_original - mb.valor) <= GREATEST(mb.valor * 0.05, 0.02)
                   AND cr.data_vencimento BETWEEN
                       DATE_SUB(mb.data_lancamento, INTERVAL 15 DAY) AND
                       DATE_ADD(mb.data_lancamento, INTERVAL 15 DAY)
                ) AS cand_receber,
                (SELECT COUNT(*) FROM contas_pagar cp WHERE tenant_id = $tenant_id AND cp.ativo=1 AND cp.conciliado=0 AND cp.status IN ('PENDENTE','PARCIAL')
                   AND ABS(cp.valor_original - mb.valor) <= GREATEST(mb.valor * 0.05, 0.02)
                   AND cp.data_vencimento BETWEEN
                       DATE_SUB(mb.data_lancamento, INTERVAL 15 DAY) AND
                       DATE_ADD(mb.data_lancamento, INTERVAL 15 DAY)
                ) AS cand_pagar
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
            WHERE $where
            ORDER BY mb.data_lancamento DESC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total = (int)$db->query(
        "SELECT COUNT(*) AS n FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where"
    )->fetch_assoc()['n'];

    _json(true, 'OK', ['movimentacoes' => $rows, 'total' => $total]);
}

function _listar_conciliadas($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo     = $_GET['tipo']   ?? '';
    $dt_ini   = $_GET['dt_ini'] ?? '';
    $dt_fim   = $_GET['dt_fim'] ?? '';
    $busca    = trim($_GET['busca'] ?? '');
    $limite   = min(intval($_GET['limite'] ?? 50), 200);
    $offset   = intval($_GET['offset'] ?? 0);

    $conds = ["mb.status = 'conciliado'"];
    if ($conta_id) $conds[] = "mb.conta_id = $conta_id";
    if ($tipo && in_array($tipo, ['credito','debito'], true)) {
        $conds[] = "mb.tipo = '" . $db->real_escape_string($tipo) . "'";
    }
    if ($dt_ini) $conds[] = "mb.data_lancamento >= '" . $db->real_escape_string($dt_ini) . "'";
    if ($dt_fim) $conds[] = "mb.data_lancamento <= '" . $db->real_escape_string($dt_fim) . "'";
    if ($busca) {
        $b = $db->real_escape_string($busca);
        $conds[] = "(mb.descricao LIKE '%$b%' OR mb.favorecido LIKE '%$b%')";
    }
    $where = implode(' AND ', $conds);

    $sql = "SELECT
                mb.id, mb.conta_id, mb.data_lancamento, mb.tipo, mb.valor,
                mb.descricao, mb.favorecido, mb.checknum, mb.status, mb.origem,
                mb.conta_receber_id, mb.conta_pagar_id,
                cb.nome AS conta_nome,
                c.id AS conc_id, c.tipo_conciliacao, c.score, c.criterios,
                c.conciliado_por, c.conciliado_em, c.tipo_titulo,
                COALESCE(cr.numero_documento, cp.numero_documento) AS doc_titulo,
                COALESCE(cr.morador_nome, cp.fornecedor_nome)      AS nome_titulo,
                COALESCE(cr.data_vencimento, cp.data_vencimento)   AS venc_titulo
            FROM movimentacoes_bancarias mb
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
            LEFT JOIN conciliacoes c  ON c.movimentacao_id = mb.id AND c.ativa = 1
            LEFT JOIN contas_receber cr ON cr.id = mb.conta_receber_id
            LEFT JOIN contas_pagar   cp ON cp.id = mb.conta_pagar_id
            WHERE $where
            ORDER BY c.conciliado_em DESC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total = (int)$db->query(
        "SELECT COUNT(*) AS n FROM movimentacoes_bancarias mb WHERE tenant_id = $tenant_id AND $where"
    )->fetch_assoc()['n'];

    _json(true, 'OK', ['movimentacoes' => $rows, 'total' => $total]);
}

function _candidatos($db) {
    $mov_id = intval($_GET['movimentacao_id'] ?? 0);
    if (!$mov_id) _json(false, 'movimentacao_id obrigatório', null, 400);

    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id = $mov_id")->fetch_assoc();
    if (!$mov) _json(false, 'Movimentação não encontrada', null, 404);

    $valor_abs = abs((float)$mov['valor']);
    $tol       = max($valor_abs * 0.05, 0.02);
    $d_ini     = date('Y-m-d', strtotime($mov['data_lancamento'] . ' -30 days'));
    $d_fim     = date('Y-m-d', strtotime($mov['data_lancamento'] . ' +30 days'));

    $resultado = ['movimentacao' => $mov, 'receber' => [], 'pagar' => []];

    foreach (['receber' => 'contas_receber', 'pagar' => 'contas_pagar'] as $tipo => $tab) {
        $nome_f = ($tipo === 'receber') ? 'morador_nome AS nome' : 'fornecedor_nome AS nome';
        $sql = "SELECT id, numero_documento, valor_original, data_vencimento, status, $nome_f
                FROM `$tab`
                WHERE ativo = 1 AND conciliado = 0 AND status IN ('PENDENTE','PARCIAL')
                  AND valor_original BETWEEN ? AND ?
                  AND data_vencimento BETWEEN ? AND ?
                ORDER BY ABS(valor_original - ?) ASC
                LIMIT 15";
        $stmt = $db->prepare($sql);
        $vmin = $valor_abs - $tol; $vmax = $valor_abs + $tol;
        $stmt->bind_param('ddssd', $vmin, $vmax, $d_ini, $d_fim, $valor_abs);
        $stmt->execute();
        $resultado[$tipo] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    _json(true, 'OK', $resultado);
}

function _conciliar_manual($db, $body) {
    verificarPermissao('gerente');
    $mov_id      = intval($body['movimentacao_id'] ?? 0);
    $tipo_titulo = $body['tipo_titulo'] ?? '';
    $titulo_id   = intval($body['titulo_id'] ?? 0);

    if (!$mov_id || !$titulo_id || !in_array($tipo_titulo, ['receber','pagar'], true)) {
        _json(false, 'Parâmetros inválidos', null, 400);
    }

    $usuario    = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_logado'] ?? 'sistema';
    $tabela     = ($tipo_titulo === 'receber') ? 'contas_receber' : 'contas_pagar';
    $campo_fk   = ($tipo_titulo === 'receber') ? 'conta_receber_id' : 'conta_pagar_id';
    $campo_vlr  = ($tipo_titulo === 'receber') ? 'valor_recebido'   : 'valor_pago';
    $campo_data = ($tipo_titulo === 'receber') ? 'data_recebimento'  : 'data_pagamento';
    $status_ok  = ($tipo_titulo === 'receber') ? 'RECEBIDO'          : 'PAGO';

    $titulo = $db->query("SELECT * FROM `$tabela` WHERE id = $titulo_id AND ativo = 1")->fetch_assoc();
    if (!$titulo) _json(false, 'Título não encontrado', null, 404);

    $mov = $db->query("SELECT * FROM movimentacoes_bancarias WHERE tenant_id = $tenant_id AND id = $mov_id")->fetch_assoc();
    if (!$mov) _json(false, 'Movimentação não encontrada', null, 404);

    if ($mov['status'] === 'conciliado') _json(false, 'Esta movimentação já está conciliada');
    if ($titulo['conciliado']) _json(false, 'Este título já está conciliado');

    $db->begin_transaction();
    try {
        $db->query("UPDATE movimentacoes_bancarias
                    SET $campo_fk = $titulo_id, status = 'conciliado', conciliado = 1 WHERE tenant_id = $tenant_id AND id = $mov_id");

        $stmt = $db->prepare("UPDATE `$tabela` SET
            status = ?,
            $campo_vlr = valor_original,
            saldo_devedor = 0,
            $campo_data = ?,
            movimentacao_bancaria_id = ?,
            conta_bancaria_id = ?,
            conciliado = 1,
            data_conciliacao = NOW(),
            usuario_conciliacao = ?
            WHERE id = ?");
        $stmt->bind_param('ssiisi',
            $status_ok, $mov['data_lancamento'],
            $mov_id, $mov['conta_id'], $usuario, $titulo_id
        );
        $stmt->execute();

        $criterios = json_encode(['manual'], JSON_UNESCAPED_UNICODE);
        $stmt2 = $db->prepare("INSERT INTO conciliacoes
            (movimentacao_id, tipo_titulo, titulo_id, tipo_conciliacao, score, criterios, conciliado_por)
            VALUES (?, ?, ?, 'manual', 100, ?, ?)");
        $stmt2->bind_param('isiss', $mov_id, $tipo_titulo, $titulo_id, $criterios, $usuario);
        $stmt2->execute();

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        _json(false, 'Erro ao conciliar: ' . $e->getMessage());
    }

    registrar_log('CONCILIACAO_MANUAL',
        "Conciliação manual: mov=$mov_id → $tipo_titulo #$titulo_id", $usuario);

    _json(true, 'Conciliação realizada com sucesso');
}

function _desfazer($db, $body) {
    verificarPermissao('gerente');
    $conc_id = intval($body['conciliacao_id'] ?? 0);
    if (!$conc_id) _json(false, 'conciliacao_id obrigatório', null, 400);

    $conc = $db->query("SELECT * FROM conciliacoes WHERE tenant_id = $tenant_id AND id = $conc_id AND ativa = 1")->fetch_assoc();
    if (!$conc) _json(false, 'Conciliação não encontrada ou já desfeita', null, 404);

    $usuario    = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_logado'] ?? 'sistema';
    $tabela     = ($conc['tipo_titulo'] === 'receber') ? 'contas_receber' : 'contas_pagar';
    $campo_fk   = ($conc['tipo_titulo'] === 'receber') ? 'conta_receber_id' : 'conta_pagar_id';
    $campo_vlr  = ($conc['tipo_titulo'] === 'receber') ? 'valor_recebido'   : 'valor_pago';
    $campo_data = ($conc['tipo_titulo'] === 'receber') ? 'data_recebimento'  : 'data_pagamento';

    $db->begin_transaction();
    try {
        $db->query("UPDATE movimentacoes_bancarias
                    SET $campo_fk = NULL, status = 'pendente', conciliado = 0 WHERE tenant_id = $tenant_id AND id = {$conc['movimentacao_id']}");

        $db->query("UPDATE `$tabela` SET
                    status = 'PENDENTE',
                    $campo_vlr = 0,
                    saldo_devedor = valor_original,
                    $campo_data = NULL,
                    movimentacao_bancaria_id = NULL,
                    conta_bancaria_id = NULL,
                    conciliado = 0,
                    data_conciliacao = NULL,
                    usuario_conciliacao = NULL
                    WHERE id = {$conc['titulo_id']}");

        $uesc = $db->real_escape_string($usuario);
        $db->query("UPDATE conciliacoes
                    SET ativa = 0,
                        desfeito_por = '$uesc',
                        desfeito_em  = NOW()
                    WHERE id = $conc_id");

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        _json(false, 'Erro ao desfazer: ' . $e->getMessage());
    }

    registrar_log('CONCILIACAO_DESFEITA', "Desfeita conciliação #$conc_id por $usuario", $usuario);
    _json(true, 'Conciliação desfeita com sucesso');
}

function _historico($db) {
    $conta_id = intval($_GET['conta_id'] ?? 0);
    $tipo_c   = $_GET['tipo_conc'] ?? '';
    $dt_ini   = $_GET['dt_ini']    ?? '';
    $dt_fim   = $_GET['dt_fim']    ?? '';
    $limite   = min(intval($_GET['limite'] ?? 50), 200);
    $offset   = intval($_GET['offset'] ?? 0);

    $conds = ['1=1'];
    if ($conta_id) $conds[] = "mb.conta_id = $conta_id";
    if ($tipo_c && in_array($tipo_c, ['automatica','manual'], true)) {
        $conds[] = "c.tipo_conciliacao = '" . $db->real_escape_string($tipo_c) . "'";
    }
    if ($dt_ini) $conds[] = "c.conciliado_em >= '" . $db->real_escape_string($dt_ini) . "'";
    if ($dt_fim) $conds[] = "c.conciliado_em <= '" . $db->real_escape_string($dt_fim) . " 23:59:59'";
    $where = implode(' AND ', $conds);

    $sql = "SELECT
                c.id, c.movimentacao_id, c.tipo_titulo, c.titulo_id,
                c.tipo_conciliacao, c.score, c.criterios,
                c.conciliado_por, c.conciliado_em,
                c.desfeito_por, c.desfeito_em, c.ativa,
                mb.data_lancamento, mb.tipo AS mov_tipo,
                mb.valor AS mov_valor, mb.descricao AS mov_descricao,
                cb.nome AS conta_nome,
                COALESCE(cr.numero_documento, cp.numero_documento) AS doc_titulo,
                COALESCE(cr.morador_nome, cp.fornecedor_nome)      AS nome_titulo
            FROM conciliacoes c
            JOIN movimentacoes_bancarias mb ON mb.id = c.movimentacao_id
            JOIN contas_bancarias cb ON cb.id = mb.conta_id
            LEFT JOIN contas_receber cr ON cr.id = c.titulo_id AND c.tipo_titulo = 'receber'
            LEFT JOIN contas_pagar   cp ON cp.id = c.titulo_id AND c.tipo_titulo = 'pagar'
            WHERE $where
            ORDER BY c.conciliado_em DESC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $limite, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total = (int)$db->query("SELECT COUNT(*) AS n
        FROM conciliacoes c
        JOIN movimentacoes_bancarias mb ON mb.id = c.movimentacao_id
        WHERE $where")->fetch_assoc()['n'];

    _json(true, 'OK', ['conciliacoes' => $rows, 'total' => $total]);
}

function _atualizar_status($db, $body) {
    verificarPermissao('gerente');
    $id     = intval($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$id || !in_array($status, ['pendente','ignorado','divergente'], true)) {
        _json(false, 'Parâmetros inválidos', null, 400);
    }
    $usuario = $_SESSION['usuario_nome'] ?? 'sistema';
    $db->query("UPDATE movimentacoes_bancarias SET status = '$status' WHERE tenant_id = $tenant_id AND id = $id");
    registrar_log('MOV_STATUS', "Mov #$id → $status por $usuario", $usuario);
    _json(true, 'Status atualizado');
}
