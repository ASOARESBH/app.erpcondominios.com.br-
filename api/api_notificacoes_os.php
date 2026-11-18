<?php
/**
 * API de Notificações — Sistema de Alertas
 * Módulos: Ordens de Serviço (extensível para outros módulos)
 * Versão: 1.0 | 2026-06-22
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_mt_originsPermitidas = [
    'https://app.erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1',
];
$_mt_originPermitida = in_array($_mt_origin, $_mt_originsPermitidas, true);
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

function _json($ok, $msg, $dados = null, int $statusHttp = 200) {
    if ($statusHttp < 100 || $statusHttp > 599) $statusHttp = 500;
    http_response_code($statusHttp);
    echo json_encode(['sucesso' => $ok, 'mensagem' => $msg, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
    exit;
}

function _notif_os_policy(mysqli $db, int $tenantId, int $usuarioId, string $permissao): ?array {
    $gestor = in_array(strtolower(trim($permissao)), ['admin', 'administrador', 'gerente', 'super_admin'], true);
    foreach (['tenant_id', 'criado_por_id', 'atendente_id'] as $column) {
        if (!\ERP\Database\DatabaseDialect::tableExists($db, 'os_chamados')
            || !\ERP\Database\DatabaseDialect::columnExists($db, 'os_chamados', $column)) return null;
    }

    $policyColumnsReady = true;
    foreach ([
        'os_usuarios_config' => ['tenant_id', 'usuario_id', 'restritivo_os'],
        'os_permissoes_departamento' => ['tenant_id', 'usuario_id', 'departamento', 'pode_visualizar'],
    ] as $table => $columns) {
        if (!\ERP\Database\DatabaseDialect::tableExists($db, $table)) {
            $policyColumnsReady = false;
            continue;
        }
        foreach ($columns as $column) {
            if (!\ERP\Database\DatabaseDialect::columnExists($db, $table, $column)) $policyColumnsReady = false;
        }
    }

    if (!$policyColumnsReady) {
        if (\ERP\Database\DatabaseDialect::isPostgres($db)) return null;
        return [
            'pode_ver_todas' => $gestor,
            'restritivo' => false,
            'departamentos_disponiveis' => false,
            'assumido_disponivel' => \ERP\Database\DatabaseDialect::columnExists($db, 'os_chamados', 'assumido_por_id'),
        ];
    }

    if (!\ERP\Database\DatabaseDialect::columnExists($db, 'os_chamados', 'departamento')) return null;

    $stmt = $db->prepare('SELECT restritivo_os FROM os_usuarios_config WHERE tenant_id = ? AND usuario_id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $tenantId, $usuarioId);
    if (!$stmt->execute()) return null;
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $restritivo = !empty($config['restritivo_os']);

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM os_permissoes_departamento WHERE tenant_id = ? AND usuario_id = ?');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $tenantId, $usuarioId);
    if (!$stmt->execute()) return null;
    $politicaAtiva = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    return [
        'pode_ver_todas' => $gestor && !$restritivo && !$politicaAtiva,
        'restritivo' => $restritivo,
        'departamentos_disponiveis' => true,
        'assumido_disponivel' => \ERP\Database\DatabaseDialect::columnExists($db, 'os_chamados', 'assumido_por_id'),
    ];
}

function _notif_os_scope_sql(string $alias, int $tenantId, int $usuarioId, array $policy): string {
    if ($policy['pode_ver_todas']) return '1=1';
    $pessoal = "{$alias}.criado_por_id = {$usuarioId} OR {$alias}.atendente_id = {$usuarioId}";
    if ($policy['assumido_disponivel']) $pessoal .= " OR {$alias}.assumido_por_id = {$usuarioId}";
    if ($policy['restritivo'] || !$policy['departamentos_disponiveis']) return "({$pessoal})";
    return "(({$pessoal}) OR EXISTS (
        SELECT 1 FROM os_permissoes_departamento p
        WHERE p.tenant_id = {$tenantId} AND p.usuario_id = {$usuarioId} AND p.pode_visualizar = 1
          AND UPPER(TRIM(p.departamento)) = UPPER(TRIM(COALESCE({$alias}.departamento, '')))
    ))";
}

function _notif_os_destinatario_visivel(mysqli $db, int $tenantId, int $usuarioId, int $destinatarioId, string $filtro): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM notif_destinatarios d
         JOIN notif_alertas a ON a.tenant_id = d.tenant_id AND a.id = d.alerta_id
         JOIN os_chamados o ON o.tenant_id = a.tenant_id AND o.id = a.link_id
         WHERE d.tenant_id = ? AND d.usuario_id = ? AND d.id = ?
           AND a.link_pagina = 'ordens_servico' AND ($filtro) LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('iii', $tenantId, $usuarioId, $destinatarioId);
    $ok = $stmt->execute() && $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $ok;
}

// ─── Migration automática ─────────────────────────────────────────────────
function _migration($db) {
    global $tenant_id;
    if (!\ERP\Database\DatabaseDialect::isPostgres($db)) {
    // Tabela de regras de notificação
    $db->query("CREATE TABLE IF NOT EXISTS notif_regras (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id     INT NOT NULL,
        modulo        VARCHAR(50)  NOT NULL DEFAULT 'os',
        evento        VARCHAR(80)  NOT NULL,
        ativo         TINYINT(1)   NOT NULL DEFAULT 1,
        canais        VARCHAR(200) NOT NULL DEFAULT 'sistema',
        emails        TEXT,
        usuarios_ids  TEXT,
        horas_limite  DECIMAL(6,2) DEFAULT NULL,
        prioridade    VARCHAR(20)  DEFAULT NULL,
        titulo_tpl    VARCHAR(255) NOT NULL DEFAULT '',
        corpo_tpl     TEXT,
        criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_notif_regra_tenant_evento (tenant_id, modulo, evento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabela de alertas gerados
    $db->query("CREATE TABLE IF NOT EXISTS notif_alertas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id     INT NOT NULL,
        regra_id      INT          DEFAULT NULL,
        modulo        VARCHAR(50)  NOT NULL DEFAULT 'os',
        evento        VARCHAR(80)  NOT NULL,
        titulo        VARCHAR(255) NOT NULL,
        corpo         TEXT,
        icone         VARCHAR(50)  DEFAULT 'fa-bell',
        cor           VARCHAR(20)  DEFAULT 'blue',
        link_pagina   VARCHAR(100) DEFAULT NULL,
        link_id       INT          DEFAULT NULL,
        criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabela de destinatários do alerta (por usuário)
    $db->query("CREATE TABLE IF NOT EXISTS notif_destinatarios (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id   INT NOT NULL,
        alerta_id   INT NOT NULL,
        usuario_id  INT NOT NULL,
        lido        TINYINT(1) NOT NULL DEFAULT 0,
        lido_em     DATETIME DEFAULT NULL,
        dispensado  TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_alerta_usuario (tenant_id, alerta_id, usuario_id),
        INDEX idx_usuario (usuario_id),
        INDEX idx_lido (lido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    }

    // Inserir regras padrão de O.S se não existirem
    $existe = $db->query("SELECT COUNT(*) AS n FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='os'")->fetch_assoc()['n'] ?? 0;
    if (!$existe) {
        $db->query("INSERT INTO notif_regras (tenant_id, modulo, evento, ativo, canais, titulo_tpl, corpo_tpl) VALUES
            ($tenant_id,'os','os_criada',1,'sistema','Nova O.S criada: {numero}','A O.S {numero} — {titulo} foi aberta por {criado_por}. Prioridade: {prioridade}. Departamento: {departamento}.'),
            ($tenant_id,'os','os_aberta_horas',0,'sistema','O.S em aberto há {horas}h: {numero}','A O.S {numero} — {titulo} está aberta há mais de {horas} horas sem atualização.'),
            ($tenant_id,'os','os_prioridade_urgente',1,'sistema','O.S URGENTE aberta: {numero}','Uma O.S com prioridade URGENTE foi criada: {numero} — {titulo}. Atendente: {atendente}.'),
            ($tenant_id,'os','os_prioridade_alta',0,'sistema','O.S de alta prioridade: {numero}','Uma O.S com prioridade ALTA foi criada: {numero} — {titulo}.'),
            ($tenant_id,'os','os_finalizada',1,'sistema','O.S finalizada: {numero}','A O.S {numero} — {titulo} foi finalizada com sucesso. Atendente: {atendente}.'),
            ($tenant_id,'os','os_previsao_definida',0,'sistema','Previsão definida — O.S {numero}','A O.S {numero} — {titulo} teve a previsão de conclusão definida. Atendente: {atendente}.')
        ");
    } else {
        // Adicionar regras que podem não existir em instalações anteriores ao seed completo
        $db->query("INSERT INTO notif_regras (tenant_id, modulo, evento, ativo, canais, titulo_tpl, corpo_tpl)
            SELECT $tenant_id, 'os','os_finalizada',1,'sistema','O.S finalizada: {numero}','A O.S {numero} — {titulo} foi finalizada com sucesso. Atendente: {atendente}.'
            WHERE NOT EXISTS (SELECT 1 FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='os' AND evento='os_finalizada')");
        $db->query("INSERT INTO notif_regras (tenant_id, modulo, evento, ativo, canais, titulo_tpl, corpo_tpl)
            SELECT $tenant_id, 'os','os_previsao_definida',0,'sistema','Previsão definida — O.S {numero}','A O.S {numero} — {titulo} teve a previsão de conclusão definida. Atendente: {atendente}.'
            WHERE NOT EXISTS (SELECT 1 FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='os' AND evento='os_previsao_definida')");
    }
}

// ─── Autenticação ─────────────────────────────────────────────────────────
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
$db  = conectar_banco();
$db->set_charset('utf8mb4');
$instalacaoEstrutura = ($_GET['acao'] ?? '') === 'instalar_estrutura';
if (!$instalacaoEstrutura) {
    foreach (['notif_regras', 'notif_alertas', 'notif_destinatarios'] as $tabelaNotif) {
        if (!\ERP\Database\DatabaseDialect::tableExists($db, $tabelaNotif)
            || !\ERP\Database\DatabaseDialect::columnExists($db, $tabelaNotif, 'tenant_id')) {
            http_response_code(503);
            _json(false, 'Schema de notificações não está pronto.');
        }
    }
    $usuario = obterUsuarioAutenticado();
    $uidAtual = (int)($usuario['id'] ?? $_SESSION['usuario_id'] ?? 0);
    $permissaoUsuario = (string)($usuario['permissao'] ?? $_SESSION['usuario_permissao'] ?? 'operador');
    $osPolicy = _notif_os_policy($db, $tenant_id, $uidAtual, $permissaoUsuario);
    if ($uidAtual <= 0 || $osPolicy === null) {
        http_response_code(503);
        _json(false, 'Política de acesso às Ordens de Serviço não está pronta.');
    }
    $filtroVisibilidadeOS = _notif_os_scope_sql('o', $tenant_id, $uidAtual, $osPolicy);
    $filtroHistoricoOS = _notif_os_scope_sql('os_acl', $tenant_id, $uidAtual, $osPolicy);
    $filtroAlertasOS = $filtroVisibilidadeOS;
}

/*
 * Não execute DDL a cada polling do sino. CREATE TABLE IF NOT EXISTS em cada
 * requisição concorrente pode gerar bloqueios e HTTP 503 em hospedagem
 * compartilhada. A estrutura é instalada pela migration de banco e, quando
 * necessário, pode ser recriada explicitamente por super_admin.
 */
if (($_GET['acao'] ?? '') === 'instalar_estrutura') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') _json(false, 'Esta ação exige POST.', null, 405);
    if ($_mt_origin === '' || !$_mt_originPermitida) _json(false, 'Origem não autorizada.', null, 403);
    verificarPermissao('super_admin');
    if (\ERP\Database\DatabaseDialect::isPostgres($db)) {
        http_response_code(409);
        _json(false, 'A estrutura de notificações PostgreSQL deve ser aplicada pela migration versionada.');
    }
    _migration($db);
    _json(true, 'Estrutura de notificações verificada com sucesso');
}

$metodo = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($metodo === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}
$acao = $_GET['acao'] ?? $body['acao'] ?? '';
$metodosPermitidos = ['GET', 'POST'];
if (!in_array($metodo, $metodosPermitidos, true)) {
    header('Allow: GET, POST, OPTIONS');
    _json(false, 'Método não permitido.', null, 405);
}
$acoesEscrita = ['salvar_regra', 'marcar_lido', 'marcar_todos_lidos', 'dispensar', 'dispensar_todas', 'verificar_os_abertas'];
if (in_array($acao, $acoesEscrita, true) && $metodo !== 'POST') {
    _json(false, 'Esta ação exige POST.', null, 405);
}
if (in_array($acao, $acoesEscrita, true) && ($_mt_origin === '' || !$_mt_originPermitida)) {
    _json(false, 'Origem não autorizada.', null, 403);
}
if ($acao !== 'instalar_estrutura') {
    $rbacAcao = $acao === 'salvar_regra' ? 'editar' : 'visualizar';
    rbacExigir($db, 'ordens_servico', $rbacAcao, ['submodulo_chave' => $acao ?: null]);
}

// ─── Roteamento ───────────────────────────────────────────────────────────
switch ($acao) {

    // ── Listar regras de notificação ──────────────────────────────────────
    case 'listar_regras':
        $modulo = $_GET['modulo'] ?? 'os';
        $mod = $db->real_escape_string($modulo);
        $rows = $db->query("SELECT * FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='$mod' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        _json(true, 'OK', $rows);

    // ── Salvar/atualizar regra ────────────────────────────────────────────
    case 'salvar_regra':
        verificarPermissao('admin');
        $id          = intval($body['id'] ?? 0);
        $ativo       = intval($body['ativo'] ?? 1);
        $canais      = $db->real_escape_string($body['canais'] ?? 'sistema');
        $emails      = $db->real_escape_string($body['emails'] ?? '');
        $uids        = $db->real_escape_string($body['usuarios_ids'] ?? '');
        $horas       = isset($body['horas_limite']) && $body['horas_limite'] !== '' ? floatval($body['horas_limite']) : 'NULL';
        $prioridade  = $db->real_escape_string($body['prioridade'] ?? '');
        $titulo_tpl  = $db->real_escape_string($body['titulo_tpl'] ?? '');
        $corpo_tpl   = $db->real_escape_string($body['corpo_tpl'] ?? '');
        $horas_sql   = ($horas === 'NULL') ? 'NULL' : $horas;
        if ($id > 0) {
            $db->query("UPDATE notif_regras SET
                ativo=$ativo, canais='$canais', emails='$emails', usuarios_ids='$uids',
                horas_limite=$horas_sql, prioridade='$prioridade',
                titulo_tpl='$titulo_tpl', corpo_tpl='$corpo_tpl'
                WHERE tenant_id = $tenant_id AND id=$id");
            _json(true, 'Regra atualizada com sucesso');
        }
        _json(false, 'ID inválido', null);

    // ── Listar alertas não lidos do usuário logado ────────────────────────
    case 'meus_alertas':
        $uid    = intval($usuario['id']);
        $limite = intval($_GET['limite'] ?? 30);
        // Usuários comuns só recebem alertas de O.S. que eles abriram. Gestores
        // mantêm o comportamento operacional de acompanhar OS atribuídas.
        $db->query("INSERT IGNORE INTO notif_destinatarios (tenant_id, alerta_id, usuario_id)
            SELECT $tenant_id, a.id, $uid
            FROM notif_alertas a
            INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico'
            WHERE a.tenant_id = $tenant_id AND o.tenant_id = $tenant_id AND ($filtroAlertasOS)");
        $sql = "SELECT a.*, d.lido, d.dispensado, d.id AS dest_id,
                    DATE_FORMAT(a.criado_em,'%d/%m/%Y %H:%i') AS criado_fmt
                FROM notif_alertas a
                JOIN notif_destinatarios d ON d.tenant_id = a.tenant_id AND d.alerta_id = a.id AND d.usuario_id = $uid
                INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico' AND o.tenant_id = $tenant_id
                WHERE a.tenant_id = $tenant_id AND d.tenant_id = $tenant_id AND d.dispensado = 0
                  AND $filtroVisibilidadeOS
                ORDER BY d.lido ASC, a.criado_em DESC
                LIMIT $limite";
        $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
        $nao_lidos = $db->query("SELECT COUNT(*) AS n
            FROM notif_destinatarios d
            JOIN notif_alertas a ON a.tenant_id = d.tenant_id AND a.id = d.alerta_id
            JOIN os_chamados o ON o.tenant_id = a.tenant_id AND o.id = a.link_id
            WHERE d.tenant_id = $tenant_id AND d.usuario_id = $uid AND d.lido = 0 AND d.dispensado = 0
              AND a.link_pagina = 'ordens_servico' AND ($filtroVisibilidadeOS)")->fetch_assoc()['n'] ?? 0;
        _json(true, 'OK', ['alertas' => $rows, 'nao_lidos' => intval($nao_lidos)]);

    // ── Marcar alerta como lido ───────────────────────────────────────────
    case 'marcar_lido':
        $uid      = intval($usuario['id']);
        $dest_id  = intval($body['dest_id'] ?? 0);
        if ($dest_id > 0 && _notif_os_destinatario_visivel($db, $tenant_id, $uid, $dest_id, $filtroVisibilidadeOS)) {
            $db->query("UPDATE notif_destinatarios SET lido=1, lido_em=NOW() WHERE tenant_id = $tenant_id AND id=$dest_id AND usuario_id=$uid");
        }
        _json(true, 'Marcado como lido');

    // ── Marcar todos como lidos ───────────────────────────────────────────
    case 'marcar_todos_lidos':
        $uid = intval($usuario['id']);
        $db->query("UPDATE notif_destinatarios SET lido=1, lido_em=NOW()
            WHERE tenant_id = $tenant_id AND usuario_id=$uid AND lido=0
              AND alerta_id IN (
                  SELECT a.id FROM notif_alertas a
                  JOIN os_chamados o ON o.tenant_id = a.tenant_id AND o.id = a.link_id
                  WHERE a.tenant_id = $tenant_id AND a.link_pagina = 'ordens_servico'
                    AND ($filtroVisibilidadeOS)
              )");
        _json(true, 'Todos marcados como lidos');

    // ── Dispensar (remover) alerta ────────────────────────────────────────
    case 'dispensar':
        $uid     = intval($usuario['id']);
        $dest_id = intval($body['dest_id'] ?? 0);
        if ($dest_id > 0 && _notif_os_destinatario_visivel($db, $tenant_id, $uid, $dest_id, $filtroVisibilidadeOS)) {
            $db->query("UPDATE notif_destinatarios SET dispensado=1, lido=1, lido_em=NOW() WHERE tenant_id = $tenant_id AND id=$dest_id AND usuario_id=$uid");
        }
        _json(true, 'Notificação dispensada');

    // ── Dispensar todas ───────────────────────────────────────────────────
    case 'dispensar_todas':
        $uid = intval($usuario['id']);
        $db->query("UPDATE notif_destinatarios SET dispensado=1, lido=1, lido_em=NOW()
            WHERE tenant_id = $tenant_id AND usuario_id=$uid AND dispensado=0
              AND alerta_id IN (
                  SELECT a.id FROM notif_alertas a
                  JOIN os_chamados o ON o.tenant_id = a.tenant_id AND o.id = a.link_id
                  WHERE a.tenant_id = $tenant_id AND a.link_pagina = 'ordens_servico'
                    AND ($filtroVisibilidadeOS)
              )");
        _json(true, 'Todas as notificações dispensadas');

    // ── Verificar alertas pendentes de O.S em aberto há X horas (cron/polling) ──
    case 'verificar_os_abertas':
        verificarPermissao('admin');
        _verificar_os_abertas($db);
        _json(true, 'Verificação concluída');

    // ── Contar não lidos (polling leve) ──────────────────────────────────
    case 'contar_nao_lidos':
        $uid = intval($usuario['id']);

        /*
         * Este endpoint é chamado periodicamente pelo sino. Ele deve fazer
         * somente uma leitura indexada. A sincronização de alertas de O.S.
         * permanece em meus_alertas/historico, acionados pelo usuário.
         */
        $resultadoContagem = $db->query(
            "SELECT COUNT(*) AS n
             FROM notif_destinatarios d
             JOIN notif_alertas a ON a.tenant_id = d.tenant_id AND a.id = d.alerta_id
             JOIN os_chamados o ON o.tenant_id = a.tenant_id AND o.id = a.link_id
             WHERE d.tenant_id = $tenant_id AND d.usuario_id = $uid AND d.lido = 0 AND d.dispensado = 0
               AND a.link_pagina = 'ordens_servico' AND ($filtroVisibilidadeOS)"
        );
        $n = $resultadoContagem ? (int)($resultadoContagem->fetch_assoc()['n'] ?? 0) : 0;
        _json(true, 'OK', ['nao_lidos' => $n]);

    // ── Histórico completo de notificações do usuário ─────────────────────
    case 'historico':
        $uid    = intval($usuario['id']);
        $pag    = max(1, intval($_GET['pag'] ?? 1));
        $porPag = 20;
        $offset = ($pag - 1) * $porPag;
        $evFiltro   = isset($_GET['evento'])  && $_GET['evento']  !== '' ? $db->real_escape_string($_GET['evento'])  : '';
        $lidoFiltro = isset($_GET['lido'])    && $_GET['lido']    !== '' ? intval($_GET['lido'])                     : -1;

        // Auto-sync
        $db->query("INSERT IGNORE INTO notif_destinatarios (tenant_id, alerta_id, usuario_id)
            SELECT $tenant_id, a.id, $uid
            FROM notif_alertas a
            INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico'
            WHERE a.tenant_id = $tenant_id AND o.tenant_id = $tenant_id AND ($filtroAlertasOS)");

        $where = "d.tenant_id = $tenant_id AND a.tenant_id = $tenant_id AND d.usuario_id = $uid";
        $where .= " AND a.link_pagina = 'ordens_servico' AND EXISTS (SELECT 1 FROM os_chamados os_acl WHERE os_acl.id = a.link_id AND os_acl.tenant_id = $tenant_id AND ($filtroHistoricoOS))";
        if ($evFiltro)    $where .= " AND a.evento = '$evFiltro'";
        if ($lidoFiltro >= 0) $where .= " AND d.lido = $lidoFiltro";

        $total = intval($db->query(
            "SELECT COUNT(*) AS n FROM notif_alertas a
             JOIN notif_destinatarios d ON d.tenant_id = a.tenant_id AND d.alerta_id = a.id
             WHERE $where"
        )->fetch_assoc()['n'] ?? 0);

        $rows = $db->query(
            "SELECT a.*, d.lido, d.dispensado, d.id AS dest_id,
                DATE_FORMAT(a.criado_em,'%d/%m/%Y %H:%i') AS criado_fmt
             FROM notif_alertas a
             JOIN notif_destinatarios d ON d.tenant_id = a.tenant_id AND d.alerta_id = a.id
             WHERE $where
             ORDER BY a.criado_em DESC
             LIMIT $porPag OFFSET $offset"
        )->fetch_all(MYSQLI_ASSOC);

        _json(true, 'OK', [
            'alertas' => $rows,
            'total'   => $total,
            'pagina'  => $pag,
            'por_pag' => $porPag,
            'paginas' => max(1, (int)ceil($total / $porPag)),
        ]);

    default:
        _json(false, 'Ação inválida: ' . $acao);
}

// ─── Funções auxiliares ───────────────────────────────────────────────────

/**
 * Gera um alerta para todos os usuários configurados na regra
 */
function _gerar_alerta($db, $evento, $modulo, $vars = [], $link_pagina = null, $link_id = null) {
    global $tenant_id;
    // Buscar regras ativas para este evento
    $ev  = $db->real_escape_string($evento);
    $mod = $db->real_escape_string($modulo);
    $regras = $db->query("SELECT * FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='$mod' AND evento='$ev' AND ativo=1")->fetch_all(MYSQLI_ASSOC);
    if (!$regras) return;

    foreach ($regras as $regra) {
        // Substituir variáveis no template
        $titulo = _substituir_vars($regra['titulo_tpl'], $vars);
        $corpo  = _substituir_vars($regra['corpo_tpl'], $vars);

        // Definir ícone e cor por evento
        $icone = 'fa-bell';
        $cor   = 'blue';
        if (strpos($evento, 'urgente') !== false) { $icone = 'fa-exclamation-triangle'; $cor = 'red'; }
        elseif (strpos($evento, 'alta') !== false) { $icone = 'fa-exclamation-circle'; $cor = 'orange'; }
        elseif (strpos($evento, 'criada') !== false) { $icone = 'fa-plus-circle'; $cor = 'green'; }
        elseif (strpos($evento, 'horas') !== false) { $icone = 'fa-clock'; $cor = 'amber'; }

        // Inserir alerta
        $titulo_esc = $db->real_escape_string($titulo);
        $corpo_esc  = $db->real_escape_string($corpo);
        $icone_esc  = $db->real_escape_string($icone);
        $cor_esc    = $db->real_escape_string($cor);
        $link_pag   = $link_pagina ? "'" . $db->real_escape_string($link_pagina) . "'" : 'NULL';
        $link_i     = $link_id ? intval($link_id) : 'NULL';
        $regra_id   = intval($regra['id']);

        $db->query("INSERT INTO notif_alertas (tenant_id, regra_id, modulo, evento, titulo, corpo, icone, cor, link_pagina, link_id)
            VALUES ($tenant_id, $regra_id, '$mod', '$ev', '$titulo_esc', '$corpo_esc', '$icone_esc', '$cor_esc', $link_pag, $link_i)");
        $alerta_id = $db->insert_id;
        if (!$alerta_id) continue;

        // Determinar destinatários
        $destinatarios = _obter_destinatarios($db, $regra);
        foreach ($destinatarios as $uid) {
            $uid = intval($uid);
            $db->query("INSERT IGNORE INTO notif_destinatarios (tenant_id, alerta_id, usuario_id) VALUES ($tenant_id, $alerta_id, $uid)");
        }
    }
}

/**
 * Verifica O.S em aberto há mais de X horas (chamado por polling ou cron)
 */
function _verificar_os_abertas($db) {
    global $tenant_id;
    $regras = $db->query("SELECT * FROM notif_regras WHERE tenant_id = $tenant_id AND modulo='os' AND evento='os_aberta_horas' AND ativo=1 AND horas_limite IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
    foreach ($regras as $regra) {
        $horas = floatval($regra['horas_limite']);
        $minutos = intval($horas * 60);
        // Buscar OS abertas há mais de X horas que ainda não geraram alerta para esta regra
        $sql = "SELECT o.id, o.numero, o.titulo, o.prioridade, o.departamento, o.atendente_nome,
                    TIMESTAMPDIFF(MINUTE, o.data_abertura, NOW()) AS minutos_aberta
                FROM os_chamados o WHERE tenant_id = $tenant_id AND o.status IN ('aberto','andamento')
                AND TIMESTAMPDIFF(MINUTE, o.data_abertura, NOW()) >= $minutos
                AND NOT EXISTS (
                    SELECT 1 FROM notif_alertas na
                    WHERE na.tenant_id = $tenant_id AND na.evento='os_aberta_horas' AND na.link_id=o.id AND na.regra_id={$regra['id']}
                    AND na.criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                )";
        $os_list = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
        foreach ($os_list as $os) {
            $horas_abertas = round($os['minutos_aberta'] / 60, 1);
            _gerar_alerta($db, 'os_aberta_horas', 'os', [
                'numero'      => $os['numero'],
                'titulo'      => $os['titulo'],
                'prioridade'  => $os['prioridade'],
                'departamento'=> $os['departamento'],
                'atendente'   => $os['atendente_nome'],
                'horas'       => $horas_abertas,
            ], 'ordens_servico', $os['id']);
        }
    }
}

/**
 * Substitui variáveis {chave} no template
 */
function _substituir_vars($tpl, $vars) {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', $v, $tpl);
    }
    return $tpl;
}

/**
 * Obtém lista de IDs de usuários destinatários para uma regra
 */
function _obter_destinatarios($db, $regra) {
    global $tenant_id;
    $uids = [];
    // Usuários explicitamente configurados na regra
    if (!empty($regra['usuarios_ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $regra['usuarios_ids']))));
        if ($ids) {
            $in = implode(',', $ids);
            $rows = $db->query("SELECT id FROM usuarios WHERE tenant_id = $tenant_id AND ativo = 1 AND id IN ($in)")->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) $uids[] = (int)$row['id'];
        }
    }
    // Se não há usuários configurados, notificar todos os admins/gerentes
    if (empty($uids)) {
        $rows = $db->query("SELECT id FROM usuarios WHERE tenant_id = $tenant_id AND permissao IN ('admin','gerente') AND ativo=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) $uids[] = intval($r['id']);
    }
    return array_unique($uids);
}
