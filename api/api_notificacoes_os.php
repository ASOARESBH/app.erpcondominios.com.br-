<?php
/**
 * API de Notificações — Sistema de Alertas
 * Módulos: Ordens de Serviço (extensível para outros módulos)
 * Versão: 1.0 | 2026-06-22
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function _json($ok, $msg, $dados = null) {
    echo json_encode(['sucesso' => $ok, 'mensagem' => $msg, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Migration automática ─────────────────────────────────────────────────
function _migration($db) {
    // Tabela de regras de notificação
    $db->query("CREATE TABLE IF NOT EXISTS notif_regras (
        id            INT AUTO_INCREMENT PRIMARY KEY,
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
        atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabela de alertas gerados
    $db->query("CREATE TABLE IF NOT EXISTS notif_alertas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
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
        alerta_id   INT NOT NULL,
        usuario_id  INT NOT NULL,
        lido        TINYINT(1) NOT NULL DEFAULT 0,
        lido_em     DATETIME DEFAULT NULL,
        dispensado  TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_alerta_usuario (alerta_id, usuario_id),
        INDEX idx_usuario (usuario_id),
        INDEX idx_lido (lido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inserir regras padrão de O.S se não existirem
    $existe = $db->query("SELECT COUNT(*) AS n FROM notif_regras WHERE modulo='os'")->fetch_assoc()['n'] ?? 0;
    if (!$existe) {
        $db->query("INSERT INTO notif_regras (modulo, evento, ativo, canais, titulo_tpl, corpo_tpl) VALUES
            ('os','os_criada',1,'sistema','Nova O.S criada: {numero}','A O.S {numero} — {titulo} foi aberta por {criado_por}. Prioridade: {prioridade}. Departamento: {departamento}.'),
            ('os','os_aberta_horas',0,'sistema','O.S em aberto há {horas}h: {numero}','A O.S {numero} — {titulo} está aberta há mais de {horas} horas sem atualização.'),
            ('os','os_prioridade_urgente',1,'sistema','O.S URGENTE aberta: {numero}','Uma O.S com prioridade URGENTE foi criada: {numero} — {titulo}. Atendente: {atendente}.'),
            ('os','os_prioridade_alta',0,'sistema','O.S de alta prioridade: {numero}','Uma O.S com prioridade ALTA foi criada: {numero} — {titulo}.'),
            ('os','os_finalizada',1,'sistema','O.S finalizada: {numero}','A O.S {numero} — {titulo} foi finalizada com sucesso. Atendente: {atendente}.'),
            ('os','os_previsao_definida',0,'sistema','Previsão definida — O.S {numero}','A O.S {numero} — {titulo} teve a previsão de conclusão definida. Atendente: {atendente}.')
        ");
    } else {
        // Adicionar regras que podem não existir em instalações anteriores ao seed completo
        $db->query("INSERT INTO notif_regras (modulo, evento, ativo, canais, titulo_tpl, corpo_tpl)
            SELECT 'os','os_finalizada',1,'sistema','O.S finalizada: {numero}','A O.S {numero} — {titulo} foi finalizada com sucesso. Atendente: {atendente}.'
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notif_regras WHERE modulo='os' AND evento='os_finalizada')");
        $db->query("INSERT INTO notif_regras (modulo, evento, ativo, canais, titulo_tpl, corpo_tpl)
            SELECT 'os','os_previsao_definida',0,'sistema','Previsão definida — O.S {numero}','A O.S {numero} — {titulo} teve a previsão de conclusão definida. Atendente: {atendente}.'
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notif_regras WHERE modulo='os' AND evento='os_previsao_definida')");
    }
}

// ─── Autenticação ─────────────────────────────────────────────────────────
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
$db  = conectar_banco();
$db->set_charset('utf8mb4');
_migration($db);

$metodo = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($metodo === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}
$acao = $_GET['acao'] ?? $body['acao'] ?? '';
$usuario = obterUsuarioAutenticado();

// ─── Roteamento ───────────────────────────────────────────────────────────
switch ($acao) {

    // ── Listar regras de notificação ──────────────────────────────────────
    case 'listar_regras':
        $modulo = $_GET['modulo'] ?? 'os';
        $mod = $db->real_escape_string($modulo);
        $rows = $db->query("SELECT * FROM notif_regras WHERE modulo='$mod' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
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
                WHERE id=$id");
            _json(true, 'Regra atualizada com sucesso');
        }
        _json(false, 'ID inválido', null);

    // ── Listar alertas não lidos do usuário logado ────────────────────────
    case 'meus_alertas':
        $uid    = intval($usuario['id']);
        $limite = intval($_GET['limite'] ?? 30);
        // Auto-sync: incluir alertas de OS onde o usuário é atendente, morador ou criador
        $db->query("INSERT IGNORE INTO notif_destinatarios (alerta_id, usuario_id)
            SELECT a.id, $uid
            FROM notif_alertas a
            INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico'
            WHERE o.atendente_id = $uid OR o.morador_id = $uid OR o.criado_por_id = $uid");
        $sql = "SELECT a.*, d.lido, d.dispensado, d.id AS dest_id,
                    DATE_FORMAT(a.criado_em,'%d/%m/%Y %H:%i') AS criado_fmt
                FROM notif_alertas a
                JOIN notif_destinatarios d ON d.alerta_id = a.id AND d.usuario_id = $uid
                WHERE d.dispensado = 0
                ORDER BY d.lido ASC, a.criado_em DESC
                LIMIT $limite";
        $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
        $nao_lidos = $db->query("SELECT COUNT(*) AS n FROM notif_destinatarios WHERE usuario_id=$uid AND lido=0 AND dispensado=0")->fetch_assoc()['n'] ?? 0;
        _json(true, 'OK', ['alertas' => $rows, 'nao_lidos' => intval($nao_lidos)]);

    // ── Marcar alerta como lido ───────────────────────────────────────────
    case 'marcar_lido':
        $uid      = intval($usuario['id']);
        $dest_id  = intval($body['dest_id'] ?? 0);
        if ($dest_id > 0) {
            $db->query("UPDATE notif_destinatarios SET lido=1, lido_em=NOW() WHERE id=$dest_id AND usuario_id=$uid");
        }
        _json(true, 'Marcado como lido');

    // ── Marcar todos como lidos ───────────────────────────────────────────
    case 'marcar_todos_lidos':
        $uid = intval($usuario['id']);
        $db->query("UPDATE notif_destinatarios SET lido=1, lido_em=NOW() WHERE usuario_id=$uid AND lido=0");
        _json(true, 'Todos marcados como lidos');

    // ── Dispensar (remover) alerta ────────────────────────────────────────
    case 'dispensar':
        $uid     = intval($usuario['id']);
        $dest_id = intval($body['dest_id'] ?? 0);
        if ($dest_id > 0) {
            $db->query("UPDATE notif_destinatarios SET dispensado=1, lido=1, lido_em=NOW() WHERE id=$dest_id AND usuario_id=$uid");
        }
        _json(true, 'Notificação dispensada');

    // ── Dispensar todas ───────────────────────────────────────────────────
    case 'dispensar_todas':
        $uid = intval($usuario['id']);
        $db->query("UPDATE notif_destinatarios SET dispensado=1, lido=1, lido_em=NOW() WHERE usuario_id=$uid AND dispensado=0");
        _json(true, 'Todas as notificações dispensadas');

    // ── Verificar alertas pendentes de O.S em aberto há X horas (cron/polling) ──
    case 'verificar_os_abertas':
        verificarPermissao('admin');
        _verificar_os_abertas($db);
        _json(true, 'Verificação concluída');

    // ── Contar não lidos (polling leve) ──────────────────────────────────
    case 'contar_nao_lidos':
        $uid = intval($usuario['id']);
        // Auto-sync: incluir alertas de OS onde o usuário é atendente, morador ou criador
        $db->query("INSERT IGNORE INTO notif_destinatarios (alerta_id, usuario_id)
            SELECT a.id, $uid
            FROM notif_alertas a
            INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico'
            WHERE o.atendente_id = $uid OR o.morador_id = $uid OR o.criado_por_id = $uid");
        $n = $db->query("SELECT COUNT(*) AS n FROM notif_destinatarios WHERE usuario_id=$uid AND lido=0 AND dispensado=0")->fetch_assoc()['n'] ?? 0;
        _json(true, 'OK', ['nao_lidos' => intval($n)]);

    // ── Histórico completo de notificações do usuário ─────────────────────
    case 'historico':
        $uid    = intval($usuario['id']);
        $pag    = max(1, intval($_GET['pag'] ?? 1));
        $porPag = 20;
        $offset = ($pag - 1) * $porPag;
        $evFiltro   = isset($_GET['evento'])  && $_GET['evento']  !== '' ? $db->real_escape_string($_GET['evento'])  : '';
        $lidoFiltro = isset($_GET['lido'])    && $_GET['lido']    !== '' ? intval($_GET['lido'])                     : -1;

        // Auto-sync
        $db->query("INSERT IGNORE INTO notif_destinatarios (alerta_id, usuario_id)
            SELECT a.id, $uid
            FROM notif_alertas a
            INNER JOIN os_chamados o ON o.id = a.link_id AND a.link_pagina = 'ordens_servico'
            WHERE o.atendente_id = $uid OR o.morador_id = $uid OR o.criado_por_id = $uid");

        $where = "d.usuario_id = $uid";
        if ($evFiltro)    $where .= " AND a.evento = '$evFiltro'";
        if ($lidoFiltro >= 0) $where .= " AND d.lido = $lidoFiltro";

        $total = intval($db->query(
            "SELECT COUNT(*) AS n FROM notif_alertas a
             JOIN notif_destinatarios d ON d.alerta_id = a.id
             WHERE $where"
        )->fetch_assoc()['n'] ?? 0);

        $rows = $db->query(
            "SELECT a.*, d.lido, d.dispensado, d.id AS dest_id,
                DATE_FORMAT(a.criado_em,'%d/%m/%Y %H:%i') AS criado_fmt
             FROM notif_alertas a
             JOIN notif_destinatarios d ON d.alerta_id = a.id
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
    // Buscar regras ativas para este evento
    $ev  = $db->real_escape_string($evento);
    $mod = $db->real_escape_string($modulo);
    $regras = $db->query("SELECT * FROM notif_regras WHERE modulo='$mod' AND evento='$ev' AND ativo=1")->fetch_all(MYSQLI_ASSOC);
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

        $db->query("INSERT INTO notif_alertas (regra_id, modulo, evento, titulo, corpo, icone, cor, link_pagina, link_id)
            VALUES ($regra_id, '$mod', '$ev', '$titulo_esc', '$corpo_esc', '$icone_esc', '$cor_esc', $link_pag, $link_i)");
        $alerta_id = $db->insert_id;
        if (!$alerta_id) continue;

        // Determinar destinatários
        $destinatarios = _obter_destinatarios($db, $regra);
        foreach ($destinatarios as $uid) {
            $uid = intval($uid);
            $db->query("INSERT IGNORE INTO notif_destinatarios (alerta_id, usuario_id) VALUES ($alerta_id, $uid)");
        }
    }
}

/**
 * Verifica O.S em aberto há mais de X horas (chamado por polling ou cron)
 */
function _verificar_os_abertas($db) {
    $regras = $db->query("SELECT * FROM notif_regras WHERE modulo='os' AND evento='os_aberta_horas' AND ativo=1 AND horas_limite IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
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
                    WHERE na.evento='os_aberta_horas' AND na.link_id=o.id AND na.regra_id={$regra['id']}
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
    $uids = [];
    // Usuários explicitamente configurados na regra
    if (!empty($regra['usuarios_ids'])) {
        $ids = array_filter(array_map('intval', explode(',', $regra['usuarios_ids'])));
        $uids = array_merge($uids, $ids);
    }
    // Se não há usuários configurados, notificar todos os admins/gerentes
    if (empty($uids)) {
        $rows = $db->query("SELECT id FROM usuarios WHERE tenant_id = $tenant_id AND permissao IN ('admin','gerente') AND ativo=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) $uids[] = intval($r['id']);
    }
    return array_unique($uids);
}
