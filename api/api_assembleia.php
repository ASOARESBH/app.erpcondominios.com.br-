<?php
/**
 * api_assembleia.php — API do Módulo de Assembleia v1.0
 * Ações: listar, obter, criar, atualizar, excluir, alterar_status,
 *        listar_pautas, criar_pauta, atualizar_pauta, excluir_pauta, reordenar_pautas,
 *        iniciar_votacao, encerrar_votacao, registrar_voto, listar_votos,
 *        upload_anexo, listar_anexos, excluir_anexo,
 *        registrar_participante, listar_participantes,
 *        meus_votos (portal morador), migration
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Autenticação ─────────────────────────────────────────────
$sess = verificarAutenticacao(true, null);
$tenant_id = exigirTenantId();
$uid  = $sess['id']   ?? 0;
$role = $sess['role'] ?? $sess['perfil'] ?? 'morador';

// ── Roteamento ───────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$body   = [];
if (in_array($method, ['POST','PUT','PATCH'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (empty($body)) $body = $_POST;
}
// acao: GET param > POST param > JSON body (suporte a Content-Type: application/json)
$acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';

switch ($acao) {
    // ── Assembleias ──
    case 'listar':              responder_listar();          break;
    case 'obter':               responder_obter();           break;
    case 'criar':               responder_criar();           break;
    case 'atualizar':           responder_atualizar();       break;
    case 'excluir':             responder_excluir();         break;
    case 'alterar_status':      responder_alterar_status();  break;
    // ── Pautas ──
    case 'listar_pautas':       responder_listar_pautas();   break;
    case 'criar_pauta':         responder_criar_pauta();     break;
    case 'atualizar_pauta':     responder_atualizar_pauta(); break;
    case 'excluir_pauta':       responder_excluir_pauta();   break;
    case 'reordenar_pautas':    responder_reordenar_pautas(); break;
    // ── Votação ──
    case 'iniciar_votacao':     responder_iniciar_votacao();  break;
    case 'encerrar_votacao':    responder_encerrar_votacao(); break;
    case 'registrar_voto':      responder_registrar_voto();   break;
    case 'listar_votos':        responder_listar_votos();     break;
    case 'meus_votos':          responder_meus_votos();       break;
    // ── Anexos ──
    case 'upload_anexo':        responder_upload_anexo();     break;
    case 'listar_anexos':       responder_listar_anexos();    break;
    case 'excluir_anexo':       responder_excluir_anexo();    break;
    // ── Participantes ──
    case 'registrar_participante': responder_registrar_participante(); break;
    case 'listar_participantes':   responder_listar_participantes();   break;
    // ── Migration ──
    case 'migration':           responder_migration();        break;
    default:
        http_response_code(400);
        echo json_encode(['erro' => 'Ação inválida: ' . $acao]);
}

// ════════════════════════════════════════════════════════════
// MIGRATION
// ════════════════════════════════════════════════════════════
function responder_migration() {
    global $conn;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS assembleias (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome            VARCHAR(255)  NOT NULL,
            tipo            ENUM('ordinaria','extraordinaria','deliberacao') NOT NULL DEFAULT 'ordinaria',
            data_assembleia DATETIME      NOT NULL,
            local_realizacao VARCHAR(255) DEFAULT NULL,
            descricao       TEXT          DEFAULT NULL,
            status          ENUM('rascunho','convocada','em_andamento','encerrada','cancelada') NOT NULL DEFAULT 'rascunho',
            quorum_minimo   INT UNSIGNED  DEFAULT 0,
            criado_por      INT UNSIGNED  DEFAULT NULL,
            criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_data   (data_assembleia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS assembleia_pautas (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assembleia_id   INT UNSIGNED NOT NULL,
            ordem           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            titulo          VARCHAR(255) NOT NULL,
            descricao       TEXT         DEFAULT NULL,
            tipo            ENUM('informativo','votacao','tema') NOT NULL DEFAULT 'informativo',
            status          ENUM('pendente','em_votacao','encerrado') NOT NULL DEFAULT 'pendente',
            resultado       ENUM('aprovado','reprovado','anulado','sem_quorum','pendente') DEFAULT 'pendente',
            votos_aprovado  INT UNSIGNED NOT NULL DEFAULT 0,
            votos_reprovado INT UNSIGNED NOT NULL DEFAULT 0,
            votos_anulado   INT UNSIGNED NOT NULL DEFAULT 0,
            criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
            INDEX idx_assembleia (assembleia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS assembleia_votos (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pauta_id        INT UNSIGNED NOT NULL,
            assembleia_id   INT UNSIGNED NOT NULL,
            morador_id      INT UNSIGNED DEFAULT NULL,
            unidade         VARCHAR(50)  DEFAULT NULL,
            nome_votante    VARCHAR(255) DEFAULT NULL,
            voto            ENUM('aprovado','reprovado','anulado') NOT NULL,
            tipo_participacao ENUM('online','presencial') NOT NULL DEFAULT 'presencial',
            ip_votante      VARCHAR(45)  DEFAULT NULL,
            criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_voto_morador (pauta_id, morador_id),
            FOREIGN KEY (pauta_id)      REFERENCES assembleia_pautas(id) ON DELETE CASCADE,
            FOREIGN KEY (assembleia_id) REFERENCES assembleias(id)       ON DELETE CASCADE,
            INDEX idx_pauta      (pauta_id),
            INDEX idx_assembleia (assembleia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS assembleia_anexos (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assembleia_id   INT UNSIGNED NOT NULL,
            tipo_anexo      ENUM('convocacao','ata_encerramento','documento','outro') NOT NULL DEFAULT 'documento',
            nome_arquivo    VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(512) NOT NULL,
            tamanho_bytes   INT UNSIGNED DEFAULT 0,
            mime_type       VARCHAR(100) DEFAULT NULL,
            enviado_por     INT UNSIGNED DEFAULT NULL,
            enviado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
            INDEX idx_assembleia (assembleia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS assembleia_participantes (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assembleia_id   INT UNSIGNED NOT NULL,
            morador_id      INT UNSIGNED DEFAULT NULL,
            unidade         VARCHAR(50)  DEFAULT NULL,
            nome            VARCHAR(255) NOT NULL,
            tipo_participacao ENUM('presencial','online','procuracao') NOT NULL DEFAULT 'presencial',
            confirmado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
            INDEX idx_assembleia (assembleia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    $erros = [];
    foreach ($sqls as $sql) {
        if (!mysqli_query($conn, $sql)) {
            $erros[] = mysqli_error($conn);
        }
    }

    // Seed catálogo de módulos
    $seeds = [
        "INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, grupo, icone, ordem)
         VALUES ('assembleia','Assembleia','Criação e gestão de assembleias, pautas e votações','Administrativo','fa-landmark',90)",
        "INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, grupo, icone, ordem)
         VALUES ('checklists','Checklists','Listas de verificação para rondas e inspeções','Manutenção','fa-clipboard-list',75)",
    ];
    foreach ($seeds as $sql) {
        mysqli_query($conn, $sql);
    }

    if (!empty($erros)) {
        echo json_encode(['ok' => false, 'erros' => $erros]);
    } else {
        echo json_encode(['ok' => true, 'mensagem' => 'Migration executada com sucesso']);
    }
}

// ════════════════════════════════════════════════════════════
// LISTAR ASSEMBLEIAS
// ════════════════════════════════════════════════════════════
function responder_listar() {
    global $conn;
    $status = $_GET['status'] ?? '';
    $busca  = trim($_GET['busca'] ?? '');

    $where = ['1=1'];
    $params = [];
    $types  = '';

    if ($status !== '') {
        $where[] = 'a.status = ?';
        $params[] = $status; $types .= 's';
    }
    if ($busca !== '') {
        $where[] = '(a.nome LIKE ? OR a.local_realizacao LIKE ?)';
        $b = "%$busca%";
        $params[] = $b; $params[] = $b; $types .= 'ss';
    }

    $sql = "SELECT a.*,
                   u.nome AS criador_nome,
                   (SELECT COUNT(*) FROM assembleia_pautas p WHERE p.assembleia_id = a.id) AS total_pautas,
                   (SELECT COUNT(*) FROM assembleia_participantes pt WHERE pt.assembleia_id = a.id) AS total_participantes,
                   (SELECT COUNT(*) FROM assembleia_votos v WHERE v.assembleia_id = a.id) AS total_votos
            FROM assembleias a
            LEFT JOIN usuarios u ON u.id = a.criado_por
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.data_assembleia DESC";

    $stmt = mysqli_prepare($conn, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows]);
}

// ════════════════════════════════════════════════════════════
// OBTER ASSEMBLEIA (com pautas e anexos)
// ════════════════════════════════════════════════════════════
function responder_obter() {
    global $conn;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['erro' => 'ID obrigatório']); return; }

    $stmt = mysqli_prepare($conn, "SELECT a.*, u.nome AS criador_nome FROM assembleias a LEFT JOIN usuarios u ON u.id = a.criado_por WHERE a.id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $ass = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$ass) { http_response_code(404); echo json_encode(['erro' => 'Assembleia não encontrada']); return; }

    // Pautas
    $stmt2 = mysqli_prepare($conn, "SELECT * FROM assembleia_pautas WHERE assembleia_id = ? ORDER BY ordem ASC");
    mysqli_stmt_bind_param($stmt2, 'i', $id);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    $pautas = [];
    while ($r = mysqli_fetch_assoc($res2)) $pautas[] = $r;

    // Anexos
    $stmt3 = mysqli_prepare($conn, "SELECT * FROM assembleia_anexos WHERE assembleia_id = ? ORDER BY enviado_em DESC");
    mysqli_stmt_bind_param($stmt3, 'i', $id);
    mysqli_stmt_execute($stmt3);
    $res3 = mysqli_stmt_get_result($stmt3);
    $anexos = [];
    while ($r = mysqli_fetch_assoc($res3)) $anexos[] = $r;

    // Participantes
    $stmt4 = mysqli_prepare($conn, "SELECT * FROM assembleia_participantes WHERE assembleia_id = ? ORDER BY confirmado_em ASC");
    mysqli_stmt_bind_param($stmt4, 'i', $id);
    mysqli_stmt_execute($stmt4);
    $res4 = mysqli_stmt_get_result($stmt4);
    $participantes = [];
    while ($r = mysqli_fetch_assoc($res4)) $participantes[] = $r;

    $ass['pautas']        = $pautas;
    $ass['anexos']        = $anexos;
    $ass['participantes'] = $participantes;

    echo json_encode(['ok' => true, 'dados' => $ass]);
}

// ════════════════════════════════════════════════════════════
// CRIAR ASSEMBLEIA
// ════════════════════════════════════════════════════════════
function responder_criar() {
    global $conn, $uid;
    _exigir_admin();
    $nome   = trim($body['nome']   ?? ''); if (!$nome) { _erro('Nome obrigatório'); return; }
    $tipo   = $body['tipo']   ?? 'ordinaria';
    $data   = $body['data_assembleia'] ?? '';
    $local  = trim($body['local_realizacao'] ?? '');
    $desc   = trim($body['descricao'] ?? '');
    $quorum = (int)($body['quorum_minimo'] ?? 0);

    if (!$data) { _erro('Data da assembleia obrigatória'); return; }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO assembleias (nome, tipo, data_assembleia, local_realizacao, descricao, quorum_minimo, criado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sssssii', $nome, $tipo, $data, $local, $desc, $quorum, $uid);
    if (mysqli_stmt_execute($stmt)) {
        $id = mysqli_insert_id($conn);
        echo json_encode(['ok' => true, 'id' => $id, 'mensagem' => 'Assembleia criada com sucesso']);
    } else {
        _erro('Erro ao criar assembleia: ' . mysqli_error($conn));
    }
}

// ════════════════════════════════════════════════════════════
// ATUALIZAR ASSEMBLEIA
// ════════════════════════════════════════════════════════════
function responder_atualizar() {
    global $conn, $body;
    _exigir_admin();
    $id     = (int)($body['id'] ?? 0); if (!$id) { _erro('ID obrigatório'); return; }
    $nome   = trim($body['nome']   ?? '');
    $tipo   = $body['tipo']   ?? 'ordinaria';
    $data   = $body['data_assembleia'] ?? '';
    $local  = trim($body['local_realizacao'] ?? '');
    $desc   = trim($body['descricao'] ?? '');
    $quorum = (int)($body['quorum_minimo'] ?? 0);

    $stmt = mysqli_prepare($conn,
        "UPDATE assembleias SET nome=?, tipo=?, data_assembleia=?, local_realizacao=?, descricao=?, quorum_minimo=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'sssssii', $nome, $tipo, $data, $local, $desc, $quorum, $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Assembleia atualizada']);
}

// ════════════════════════════════════════════════════════════
// EXCLUIR ASSEMBLEIA
// ════════════════════════════════════════════════════════════
function responder_excluir() {
    global $conn, $body;
    _exigir_admin();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { _erro('ID obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "DELETE FROM assembleias WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Assembleia excluída']);
}

// ════════════════════════════════════════════════════════════
// ALTERAR STATUS
// ════════════════════════════════════════════════════════════
function responder_alterar_status() {
    global $conn, $body;
    _exigir_admin();
    $id     = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    $validos = ['rascunho','convocada','em_andamento','encerrada','cancelada'];
    if (!$id || !in_array($status, $validos)) { _erro('Dados inválidos'); return; }
    $stmt = mysqli_prepare($conn, "UPDATE assembleias SET status=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'si', $status, $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Status alterado para ' . $status]);
}

// ════════════════════════════════════════════════════════════
// PAUTAS
// ════════════════════════════════════════════════════════════
function responder_listar_pautas() {
    global $conn;
    $aid = (int)($_GET['assembleia_id'] ?? 0);
    if (!$aid) { _erro('assembleia_id obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "SELECT * FROM assembleia_pautas WHERE assembleia_id=? ORDER BY ordem ASC");
    mysqli_stmt_bind_param($stmt, 'i', $aid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows]);
}

function responder_criar_pauta() {
    global $conn, $body;
    _exigir_admin();
    $aid    = (int)($body['assembleia_id'] ?? 0); if (!$aid) { _erro('assembleia_id obrigatório'); return; }
    $titulo = trim($body['titulo'] ?? '');         if (!$titulo) { _erro('Título obrigatório'); return; }
    $desc   = trim($body['descricao'] ?? '');
    $tipo   = $body['tipo'] ?? 'informativo';
    // Próxima ordem
    $r = mysqli_query($conn, "SELECT COALESCE(MAX(ordem),0)+1 AS prox FROM assembleia_pautas WHERE assembleia_id=$aid");
    $ordem = (int)(mysqli_fetch_assoc($r)['prox'] ?? 1);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO assembleia_pautas (assembleia_id, ordem, titulo, descricao, tipo) VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iisss', $aid, $ordem, $titulo, $desc, $tipo);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['ok' => true, 'id' => mysqli_insert_id($conn), 'mensagem' => 'Pauta criada']);
    } else {
        _erro('Erro ao criar pauta: ' . mysqli_error($conn));
    }
}

function responder_atualizar_pauta() {
    global $conn, $body;
    _exigir_admin();
    $id     = (int)($body['id'] ?? 0);
    $titulo = trim($body['titulo'] ?? '');
    $desc   = trim($body['descricao'] ?? '');
    $tipo   = $body['tipo'] ?? 'informativo';
    if (!$id || !$titulo) { _erro('Dados inválidos'); return; }
    $stmt = mysqli_prepare($conn, "UPDATE assembleia_pautas SET titulo=?, descricao=?, tipo=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'sssi', $titulo, $desc, $tipo, $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Pauta atualizada']);
}

function responder_excluir_pauta() {
    global $conn, $body;
    _exigir_admin();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { _erro('ID obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "DELETE FROM assembleia_pautas WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Pauta excluída']);
}

function responder_reordenar_pautas() {
    global $conn, $body;
    _exigir_admin();
    $ids = $body['ids'] ?? []; // array de IDs na nova ordem
    foreach ($ids as $pos => $pid) {
        $ordem = $pos + 1;
        $pid   = (int)$pid;
        mysqli_query($conn, "UPDATE assembleia_pautas SET ordem=$ordem WHERE id=$pid");
    }
    echo json_encode(['ok' => true, 'mensagem' => 'Pautas reordenadas']);
}

// ════════════════════════════════════════════════════════════
// VOTAÇÃO
// ════════════════════════════════════════════════════════════
function responder_iniciar_votacao() {
    global $conn, $body;
    _exigir_admin();
    $pid = (int)($body['pauta_id'] ?? 0);
    if (!$pid) { _erro('pauta_id obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "UPDATE assembleia_pautas SET status='em_votacao' WHERE id=? AND tipo='votacao'");
    mysqli_stmt_bind_param($stmt, 'i', $pid);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) === 0) { _erro('Pauta não encontrada ou não é do tipo votação'); return; }
    echo json_encode(['ok' => true, 'mensagem' => 'Votação iniciada']);
}

function responder_encerrar_votacao() {
    global $conn, $body;
    _exigir_admin();
    $pid = (int)($body['pauta_id'] ?? 0);
    if (!$pid) { _erro('pauta_id obrigatório'); return; }

    // Contar votos
    $r = mysqli_query($conn,
        "SELECT
            SUM(voto='aprovado')  AS ap,
            SUM(voto='reprovado') AS rp,
            SUM(voto='anulado')   AS an
         FROM assembleia_votos WHERE pauta_id=$pid");
    $v = mysqli_fetch_assoc($r);
    $ap = (int)$v['ap']; $rp = (int)$v['rp']; $an = (int)$v['an'];

    // Determinar resultado
    if ($ap > $rp && $ap > $an)       $resultado = 'aprovado';
    elseif ($rp > $ap && $rp > $an)   $resultado = 'reprovado';
    elseif ($an >= $ap && $an >= $rp)  $resultado = 'anulado';
    else                               $resultado = 'aprovado'; // empate: aprovado por padrão

    $stmt = mysqli_prepare($conn,
        "UPDATE assembleia_pautas
         SET status='encerrado', resultado=?, votos_aprovado=?, votos_reprovado=?, votos_anulado=?
         WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'siiii', $resultado, $ap, $rp, $an, $pid);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'resultado' => $resultado, 'votos' => ['aprovado'=>$ap,'reprovado'=>$rp,'anulado'=>$an]]);
}

function responder_registrar_voto() {
    global $conn, $body, $uid;
    $pid  = (int)($body['pauta_id'] ?? 0);
    $voto = $body['voto'] ?? '';
    $tipo = $body['tipo_participacao'] ?? 'online';
    $unid = trim($body['unidade'] ?? '');
    $nome = trim($body['nome_votante'] ?? '');

    if (!$pid || !in_array($voto, ['aprovado','reprovado','anulado'])) {
        _erro('Dados inválidos'); return;
    }

    // Verificar se pauta está em votação
    $r = mysqli_query($conn, "SELECT ap.status, ap.assembleia_id FROM assembleia_pautas ap WHERE ap.id=$pid");
    $p = mysqli_fetch_assoc($r);
    if (!$p || $p['status'] !== 'em_votacao') { _erro('Esta pauta não está em votação'); return; }

    $aid = (int)$p['assembleia_id'];
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';

    // Verificar voto duplicado
    if ($uid) {
        $chk = mysqli_prepare($conn, "SELECT id FROM assembleia_votos WHERE pauta_id=? AND morador_id=?");
        mysqli_stmt_bind_param($chk, 'ii', $pid, $uid);
        mysqli_stmt_execute($chk);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) {
            _erro('Você já votou nesta pauta'); return;
        }
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO assembleia_votos (pauta_id, assembleia_id, morador_id, unidade, nome_votante, voto, tipo_participacao, ip_votante)
         VALUES (?,?,?,?,?,?,?,?)");
    $mid = $uid ?: null;
    mysqli_stmt_bind_param($stmt, 'iiisssss', $pid, $aid, $mid, $unid, $nome, $voto, $tipo, $ip);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['ok' => true, 'mensagem' => 'Voto registrado com sucesso']);
    } else {
        $err = mysqli_error($conn);
        if (strpos($err, 'Duplicate') !== false) {
            _erro('Você já votou nesta pauta');
        } else {
            _erro('Erro ao registrar voto: ' . $err);
        }
    }
}

function responder_listar_votos() {
    global $conn;
    $pid = (int)($_GET['pauta_id'] ?? 0);
    $aid = (int)($_GET['assembleia_id'] ?? 0);
    $where = $pid ? "pauta_id=$pid" : ($aid ? "assembleia_id=$aid" : '1=1');
    $res = mysqli_query($conn, "SELECT * FROM assembleia_votos WHERE $where ORDER BY criado_em DESC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows]);
}

function responder_meus_votos() {
    global $conn, $uid;
    $aid = (int)($_GET['assembleia_id'] ?? 0);
    if (!$uid || !$aid) { echo json_encode(['ok' => true, 'dados' => []]); return; }
    $stmt = mysqli_prepare($conn,
        "SELECT v.pauta_id, v.voto, v.criado_em FROM assembleia_votos v WHERE v.assembleia_id=? AND v.morador_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $aid, $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[$r['pauta_id']] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows]);
}

// ════════════════════════════════════════════════════════════
// ANEXOS
// ════════════════════════════════════════════════════════════
function responder_upload_anexo() {
    global $conn, $uid;
    _exigir_admin();
    $aid       = (int)($_POST['assembleia_id'] ?? 0);
    $tipo_anx  = $_POST['tipo_anexo'] ?? 'documento';
    if (!$aid) { _erro('assembleia_id obrigatório'); return; }

    if (empty($_FILES['arquivo'])) { _erro('Arquivo não enviado'); return; }
    $file = $_FILES['arquivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) { _erro('Erro no upload'); return; }

    $ext_ok = ['pdf','doc','docx','jpg','jpeg','png','odt'];
    $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ext_ok)) { _erro('Extensão não permitida'); return; }

    $dir = __DIR__ . '/../uploads/assembleias/' . $aid . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $fname = uniqid('anx_') . '.' . $ext;
    $dest  = $dir . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) { _erro('Falha ao salvar arquivo'); return; }

    $caminho = 'uploads/assembleias/' . $aid . '/' . $fname;
    $tamanho = $file['size'];
    $mime    = $file['type'];
    $nome    = $file['name'];

    $stmt = mysqli_prepare($conn,
        "INSERT INTO assembleia_anexos (assembleia_id, tipo_anexo, nome_arquivo, caminho_arquivo, tamanho_bytes, mime_type, enviado_por)
         VALUES (?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'isssiis', $aid, $tipo_anx, $nome, $caminho, $tamanho, $mime, $uid);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['ok' => true, 'id' => mysqli_insert_id($conn), 'caminho' => $caminho, 'mensagem' => 'Arquivo enviado']);
    } else {
        _erro('Erro ao salvar anexo: ' . mysqli_error($conn));
    }
}

function responder_listar_anexos() {
    global $conn;
    $aid = (int)($_GET['assembleia_id'] ?? 0);
    if (!$aid) { _erro('assembleia_id obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "SELECT * FROM assembleia_anexos WHERE assembleia_id=? ORDER BY enviado_em DESC");
    mysqli_stmt_bind_param($stmt, 'i', $aid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows]);
}

function responder_excluir_anexo() {
    global $conn, $body;
    _exigir_admin();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { _erro('ID obrigatório'); return; }
    $r = mysqli_query($conn, "SELECT caminho_arquivo FROM assembleia_anexos WHERE id=$id");
    $anx = mysqli_fetch_assoc($r);
    if ($anx) {
        $path = __DIR__ . '/../' . $anx['caminho_arquivo'];
        if (file_exists($path)) @unlink($path);
    }
    $stmt = mysqli_prepare($conn, "DELETE FROM assembleia_anexos WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Anexo excluído']);
}

// ════════════════════════════════════════════════════════════
// PARTICIPANTES
// ════════════════════════════════════════════════════════════
function responder_registrar_participante() {
    global $conn, $body, $uid;
    $aid  = (int)($body['assembleia_id'] ?? 0);
    $nome = trim($body['nome'] ?? '');
    $unid = trim($body['unidade'] ?? '');
    $tipo = $body['tipo_participacao'] ?? 'presencial';
    if (!$aid || !$nome) { _erro('Dados obrigatórios ausentes'); return; }
    $mid  = $uid ?: null;
    $stmt = mysqli_prepare($conn,
        "INSERT IGNORE INTO assembleia_participantes (assembleia_id, morador_id, unidade, nome, tipo_participacao)
         VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iisss', $aid, $mid, $unid, $nome, $tipo);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'mensagem' => 'Participante registrado']);
}

function responder_listar_participantes() {
    global $conn;
    $aid = (int)($_GET['assembleia_id'] ?? 0);
    if (!$aid) { _erro('assembleia_id obrigatório'); return; }
    $stmt = mysqli_prepare($conn, "SELECT * FROM assembleia_participantes WHERE assembleia_id=? ORDER BY nome ASC");
    mysqli_stmt_bind_param($stmt, 'i', $aid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'dados' => $rows, 'total' => count($rows)]);
}

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════
function _exigir_admin() {
    global $role;
    $admins = ['admin','administrador','sindico','gerente'];
    if (!in_array(strtolower($role), $admins)) {
        http_response_code(403);
        echo json_encode(['erro' => 'Acesso restrito a administradores']);
        exit;
    }
}

function _erro($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'erro' => $msg]);
}
