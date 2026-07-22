<?php
/**
 * ============================================================
 * API DE LOGS FINANCEIROS — ERP Condomínio
 * ============================================================
 * Registra e expõe logs de diagnóstico de todos os módulos
 * financeiros: contas_pagar, contas_receber, importação,
 * planos de contas, conciliação.
 *
 * Ações (GET):
 *   listar       — Lista logs com filtros (módulo, nível, período, busca)
 *   resumo       — KPIs: total erros, avisos, infos por módulo
 *   limpar       — Remove logs mais antigos que X dias (padrão 30)
 *
 * Ações (POST):
 *   registrar    — Registra um novo log (chamado internamente pelas APIs)
 *
 * @version 1.0.0
 */
require_once 'config.php';
require_once 'auth_helper.php';

$conn         = conectar_banco();
$usuario      = verificarAutenticacao(true, 'operador');
$usuario_nome = $usuario['nome'] ?? 'Sistema';

date_default_timezone_set('America/Sao_Paulo');

// ── Criar tabela de logs se não existir ──────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `logs_financeiro` (
  `id`            bigint(20)   NOT NULL AUTO_INCREMENT,
  `modulo`        varchar(50)  NOT NULL COMMENT 'importacao|contas_pagar|contas_receber|planos_contas|conciliacao|geral',
  `nivel`         enum('INFO','AVISO','ERRO','DEBUG','CRITICO') NOT NULL DEFAULT 'INFO',
  `acao`          varchar(100) DEFAULT NULL COMMENT 'ação que gerou o log',
  `mensagem`      text         NOT NULL,
  `detalhe`       text         DEFAULT NULL COMMENT 'stack trace, dados extras, JSON',
  `usuario`       varchar(100) DEFAULT NULL,
  `ip`            varchar(45)  DEFAULT NULL,
  `user_agent`    varchar(255) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `request_uri`   varchar(500) DEFAULT NULL,
  `post_data`     text         DEFAULT NULL COMMENT 'dados POST (sem senhas)',
  `referencia_id` int(11)      DEFAULT NULL COMMENT 'ID do lote, conta ou registro relacionado',
  `duracao_ms`    int(11)      DEFAULT NULL COMMENT 'duração da operação em ms',
  `criado_em`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_modulo`   (`modulo`),
  KEY `idx_nivel`    (`nivel`),
  KEY `idx_criado`   (`criado_em`),
  KEY `idx_usuario`  (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Roteamento ────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$acao   = $_REQUEST['acao'] ?? 'listar';

switch ($acao) {
    case 'listar':    _listar();    break;
    case 'resumo':    _resumo();    break;
    case 'limpar':    _limpar();    break;
    case 'registrar': _registrar(); break;
    default:
        retornar_json(false, 'Ação inválida');
}

// ══════════════════════════════════════════════════════════════
// LISTAR LOGS
// ══════════════════════════════════════════════════════════════
function _listar() {
    global $conn;

    $modulo    = trim($_GET['modulo']    ?? '');
    $nivel     = trim($_GET['nivel']     ?? '');
    $busca     = trim($_GET['busca']     ?? '');
    $data_ini  = trim($_GET['data_ini']  ?? '');
    $data_fim  = trim($_GET['data_fim']  ?? '');
    $pagina    = max(1, (int)($_GET['pagina']    ?? 1));
    $por_pag   = min(200, max(10, (int)($_GET['por_pagina'] ?? 50)));
    $offset    = ($pagina - 1) * $por_pag;

    $where = ['1=1'];
    $params = [];
    $types  = '';

    if ($modulo) { $where[] = 'modulo = ?'; $params[] = $modulo; $types .= 's'; }
    if ($nivel)  { $where[] = 'nivel = ?';  $params[] = $nivel;  $types .= 's'; }
    if ($busca)  {
        $where[] = '(mensagem LIKE ? OR detalhe LIKE ? OR acao LIKE ? OR usuario LIKE ?)';
        $like = '%' . $busca . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
        $types .= 'ssss';
    }
    if ($data_ini) { $where[] = 'DATE(criado_em) >= ?'; $params[] = $data_ini; $types .= 's'; }
    if ($data_fim) { $where[] = 'DATE(criado_em) <= ?'; $params[] = $data_fim; $types .= 's'; }

    $sql_where = implode(' AND ', $where);

    // Total
    $sql_count = "SELECT COUNT(*) as total FROM logs_financeiro WHERE $sql_where";
    $stmt_c = $conn->prepare($sql_count);
    if ($types) $stmt_c->bind_param($types, ...$params);
    $stmt_c->execute();
    $total = $stmt_c->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt_c->close();

    // Dados
    $sql = "SELECT id, modulo, nivel, acao, mensagem, detalhe, usuario, ip,
                   request_method, request_uri, post_data, referencia_id, duracao_ms,
                   criado_em
            FROM logs_financeiro
            WHERE $sql_where
            ORDER BY criado_em DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $all_params = array_merge($params, [$por_pag, $offset]);
    $all_types  = $types . 'ii';
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $res = $stmt->get_result();

    $logs = [];
    while ($row = $res->fetch_assoc()) {
        // Truncar post_data para não sobrecarregar o frontend
        if ($row['post_data'] && strlen($row['post_data']) > 500) {
            $row['post_data'] = substr($row['post_data'], 0, 500) . '... [truncado]';
        }
        $logs[] = $row;
    }
    $stmt->close();

    retornar_json(true, 'OK', [
        'itens'    => $logs,
        'total'    => (int)$total,
        'pagina'   => $pagina,
        'paginas'  => (int)ceil($total / $por_pag),
        'por_pagina' => $por_pag
    ]);
}

// ══════════════════════════════════════════════════════════════
// RESUMO / KPIs
// ══════════════════════════════════════════════════════════════
function _resumo() {
    global $conn;

    // KPIs gerais (últimas 24h e últimos 7 dias)
    $kpis_24h = [];
    $res = $conn->query("SELECT nivel, COUNT(*) as qtd FROM logs_financeiro
                         WHERE criado_em >= NOW() - INTERVAL 24 HOUR
                         GROUP BY nivel ORDER BY FIELD(nivel,'CRITICO','ERRO','AVISO','INFO','DEBUG')");
    while ($r = $res->fetch_assoc()) $kpis_24h[$r['nivel']] = (int)$r['qtd'];

    $kpis_7d = [];
    $res = $conn->query("SELECT nivel, COUNT(*) as qtd FROM logs_financeiro
                         WHERE criado_em >= NOW() - INTERVAL 7 DAY
                         GROUP BY nivel ORDER BY FIELD(nivel,'CRITICO','ERRO','AVISO','INFO','DEBUG')");
    while ($r = $res->fetch_assoc()) $kpis_7d[$r['nivel']] = (int)$r['qtd'];

    // Por módulo (últimos 7 dias)
    $por_modulo = [];
    $res = $conn->query("SELECT modulo,
                                SUM(nivel='CRITICO') as criticos,
                                SUM(nivel='ERRO')    as erros,
                                SUM(nivel='AVISO')   as avisos,
                                SUM(nivel='INFO')    as infos,
                                COUNT(*)             as total,
                                MAX(criado_em)       as ultimo
                         FROM logs_financeiro
                         WHERE criado_em >= NOW() - INTERVAL 7 DAY
                         GROUP BY modulo
                         ORDER BY erros DESC, total DESC");
    while ($r = $res->fetch_assoc()) $por_modulo[] = $r;

    // Erros recentes (últimas 24h)
    $erros_recentes = [];
    $res = $conn->query("SELECT id, modulo, nivel, acao, mensagem, usuario, criado_em
                         FROM logs_financeiro
                         WHERE nivel IN ('ERRO','CRITICO') AND criado_em >= NOW() - INTERVAL 24 HOUR
                         ORDER BY criado_em DESC LIMIT 20");
    while ($r = $res->fetch_assoc()) $erros_recentes[] = $r;

    // Timeline últimas 24h (por hora)
    $timeline = [];
    $res = $conn->query("SELECT DATE_FORMAT(criado_em, '%H:00') as hora,
                                SUM(nivel='ERRO' OR nivel='CRITICO') as erros,
                                SUM(nivel='AVISO') as avisos,
                                COUNT(*) as total
                         FROM logs_financeiro
                         WHERE criado_em >= NOW() - INTERVAL 24 HOUR
                         GROUP BY hora ORDER BY hora ASC");
    while ($r = $res->fetch_assoc()) $timeline[] = $r;

    // Total geral
    $total_geral = $conn->query("SELECT COUNT(*) as t FROM logs_financeiro")->fetch_assoc()['t'] ?? 0;

    retornar_json(true, 'OK', [
        'kpis_24h'       => $kpis_24h,
        'kpis_7d'        => $kpis_7d,
        'por_modulo'     => $por_modulo,
        'erros_recentes' => $erros_recentes,
        'timeline'       => $timeline,
        'total_geral'    => (int)$total_geral
    ]);
}

// ══════════════════════════════════════════════════════════════
// LIMPAR LOGS ANTIGOS
// ══════════════════════════════════════════════════════════════
function _limpar() {
    global $conn, $usuario_nome;
    $dias = max(1, (int)($_GET['dias'] ?? 30));
    $conn->query("DELETE FROM logs_financeiro WHERE criado_em < NOW() - INTERVAL {$dias} DAY");
    $removidos = $conn->affected_rows;
    // Registrar a limpeza
    _log_interno('geral', 'INFO', 'limpar_logs', "Logs com mais de {$dias} dias removidos ({$removidos} registros)", null, null, $usuario_nome);
    retornar_json(true, "Removidos {$removidos} logs com mais de {$dias} dias.");
}

// ══════════════════════════════════════════════════════════════
// REGISTRAR LOG (chamado por outras APIs via include)
// ══════════════════════════════════════════════════════════════
function _registrar() {
    global $conn, $usuario_nome;
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $modulo   = $data['modulo']        ?? 'geral';
    $nivel    = strtoupper($data['nivel'] ?? 'INFO');
    $acao     = $data['acao']          ?? null;
    $mensagem = $data['mensagem']      ?? '';
    $detalhe  = $data['detalhe']       ?? null;
    $ref_id   = $data['referencia_id'] ?? null;
    $duracao  = $data['duracao_ms']    ?? null;

    if (!$mensagem) { retornar_json(false, 'mensagem obrigatória'); return; }

    _log_interno($modulo, $nivel, $acao, $mensagem, $detalhe, $ref_id, $usuario_nome, $duracao);
    retornar_json(true, 'Log registrado');
}

// ══════════════════════════════════════════════════════════════
// FUNÇÃO INTERNA DE LOG (pode ser chamada por outras APIs)
// ══════════════════════════════════════════════════════════════
function _log_interno($modulo, $nivel, $acao, $mensagem, $detalhe = null, $ref_id = null, $usuario = null, $duracao = null) {
    global $conn;
    if (!$conn) return;

    $ip         = $_SERVER['REMOTE_ADDR']                     ?? null;
    $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $method     = $_SERVER['REQUEST_METHOD']                  ?? null;
    $uri        = substr($_SERVER['REQUEST_URI'] ?? '', 0, 500);

    // Sanitizar POST data (remover senhas e tokens)
    $post_data = null;
    if (!empty($_POST)) {
        $safe = $_POST;
        foreach (['senha', 'password', 'token', 'secret', 'key'] as $k) {
            if (isset($safe[$k])) $safe[$k] = '***';
        }
        // Remover arquivo binário
        unset($safe['arquivo']);
        $post_data = json_encode($safe, JSON_UNESCAPED_UNICODE);
        if (strlen($post_data) > 2000) $post_data = substr($post_data, 0, 2000) . '...';
    }

    $stmt = $conn->prepare("INSERT INTO logs_financeiro
        (modulo, nivel, acao, mensagem, detalhe, usuario, ip, user_agent,
         request_method, request_uri, post_data, referencia_id, duracao_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;

    $stmt->bind_param('sssssssssssii',
        $modulo, $nivel, $acao, $mensagem, $detalhe,
        $usuario, $ip, $ua, $method, $uri, $post_data,
        $ref_id, $duracao
    );
    $stmt->execute();
    $stmt->close();
}
