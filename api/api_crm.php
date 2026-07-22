<?php
// =====================================================
// API: CRM — Relacionamentos e Tarefas Diretivas
// =====================================================
// GET  ?acao=listar          [&status=X&departamento=X&busca=X&pagina=N]
// GET  ?acao=obter&id=N
// GET  ?acao=sla             (painel SLA: vencidos + próximos do vencimento)
// GET  ?acao=interacoes&id=N
// GET  ?acao=anexos&id=N
// GET  ?acao=download_anexo&id=N
// GET  ?acao=usuarios        (lista usuários para seleção)
// GET  ?acao=moradores       (lista moradores para seleção)
// POST ?acao=criar           (multipart ou JSON)
// POST ?acao=atualizar&id=N
// POST ?acao=interagir&id=N  (nova interação / mudança de status)
// POST ?acao=upload_anexo&id=N  (multipart: campo "arquivo", "nome_documento")
// POST ?acao=mudar_status&id=N  {status}
// POST ?acao=mudar_prioridade&id=N {prioridade}
// DELETE ?acao=excluir&id=N
// DELETE ?acao=excluir_anexo&id=N

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'error_logger.php';
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

define('CRM_UPLOAD_DIR', dirname(__DIR__) . '/uploads/crm_anexos/');
define('CRM_UPLOAD_URL', 'uploads/crm_anexos/');
define('CRM_MAX_SIZE',   15 * 1024 * 1024);
define('CRM_MIME_ACEITOS', [
    'application/pdf'       => 'pdf',
    'image/jpeg'            => 'jpg',
    'image/jpg'             => 'jpg',
    'image/png'             => 'png',
    'image/webp'            => 'webp',
    'application/msword'    => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain'            => 'txt',
]);

if (!is_dir(CRM_UPLOAD_DIR)) mkdir(CRM_UPLOAD_DIR, 0755, true);

try { verificarAutenticacao(true, 'operador'); }
catch (Exception $e) { retornar_json(false, 'Não autenticado'); }

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = $_GET['acao'] ?? '';
$conn   = conectar_banco();
if (!$conn) retornar_json(false, 'Erro ao conectar ao banco');

$uid       = $_SESSION['usuario_id']   ?? 0;
$unome     = $_SESSION['usuario_nome'] ?? 'Sistema';
$uperm     = $_SESSION['usuario_permissao'] ?? 'operador';

// ────────────────────────────────────────────────────────────────────────────
// GET: LISTAR
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'listar') {
    $status      = trim($_GET['status']      ?? '');
    $departamento = trim($_GET['departamento'] ?? '');
    $prioridade  = trim($_GET['prioridade']  ?? '');
    $busca       = '%' . trim($_GET['busca'] ?? '') . '%';
    $pagina      = max(1, intval($_GET['pagina'] ?? 1));
    $por_pagina  = 20;
    $offset      = ($pagina - 1) * $por_pagina;

    $where = ['r.ativo = 1'];
    $params = []; $types = '';

    if ($status !== '') { $where[] = 'r.status = ?'; $params[] = $status; $types .= 's'; }
    if ($departamento !== '') { $where[] = 'r.departamento = ?'; $params[] = $departamento; $types .= 's'; }
    if ($prioridade !== '') { $where[] = 'r.prioridade = ?'; $params[] = $prioridade; $types .= 's'; }
    if ($_GET['busca'] ?? '' !== '') {
        $where[] = '(r.numero LIKE ? OR r.assunto LIKE ? OR r.remetente_nome LIKE ? OR r.destinatario_nome LIKE ?)';
        $params[] = $busca; $params[] = $busca; $params[] = $busca; $params[] = $busca;
        $types .= 'ssss';
    }

    $sql_where = implode(' AND ', $where);

    // Total
    $total_stmt = $conn->prepare("SELECT COUNT(*) FROM crm_relacionamentos r WHERE $sql_where");
    if ($types) $total_stmt->bind_param($types, ...$params);
    $total_stmt->execute();
    $total_stmt->bind_result($total); $total_stmt->fetch(); $total_stmt->close();

    // Dados
    $sql = "SELECT r.*,
                   DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as criado_fmt,
                   DATE_FORMAT(r.data_limite, '%d/%m/%Y %H:%i') as prazo_fmt,
                   DATE_FORMAT(r.data_finalizacao, '%d/%m/%Y %H:%i') as finalizado_fmt,
                   TIMESTAMPDIFF(MINUTE, NOW(), r.data_limite) as minutos_restantes,
                   (SELECT COUNT(*) FROM crm_interacoes i WHERE i.relacionamento_id = r.id) as total_interacoes,
                   (SELECT COUNT(*) FROM crm_anexos a WHERE a.relacionamento_id = r.id AND a.ativo=1) as total_anexos
            FROM crm_relacionamentos r
            WHERE $sql_where
            ORDER BY
                FIELD(r.prioridade,'urgente','alta','media','baixa'),
                CASE WHEN r.data_limite IS NOT NULL AND r.data_limite > NOW() THEN 0 ELSE 1 END,
                r.data_limite ASC,
                r.created_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $por_pagina; $params[] = $offset;
    $types   .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $list = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['sla_status'] = _calcular_sla($r);
        $list[] = $r;
    }
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', [
        'itens'      => $list,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
        'total_paginas' => max(1, ceil($total / $por_pagina)),
    ]);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: OBTER
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'obter') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare(
        "SELECT r.*,
                DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as criado_fmt,
                DATE_FORMAT(r.data_limite, '%d/%m/%Y %H:%i') as prazo_fmt,
                DATE_FORMAT(r.data_finalizacao, '%d/%m/%Y %H:%i') as finalizado_fmt,
                TIMESTAMPDIFF(MINUTE, NOW(), r.data_limite) as minutos_restantes
         FROM crm_relacionamentos r WHERE r.id = ? AND r.ativo = 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { fechar_conexao($conn); retornar_json(false, 'Relacionamento não encontrado'); }
    $row['sla_status'] = _calcular_sla($row);
    fechar_conexao($conn);
    retornar_json(true, 'OK', $row);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: SLA PAINEL
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'sla') {
    $res = $conn->query(
        "SELECT r.*,
                DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as criado_fmt,
                DATE_FORMAT(r.data_limite, '%d/%m/%Y') as prazo_fmt,
                TIMESTAMPDIFF(MINUTE, NOW(), r.data_limite) as minutos_restantes,
                TIMESTAMPDIFF(DAY, NOW(), r.data_limite) as dias_restantes
         FROM crm_relacionamentos r
         WHERE r.ativo = 1
           AND r.status NOT IN ('finalizado','cancelado')
           AND r.data_limite IS NOT NULL
         ORDER BY r.data_limite ASC
         LIMIT 100"
    );
    $vencidos = []; $proximos = []; $no_prazo = [];
    while ($r = $res->fetch_assoc()) {
        $r['sla_status'] = _calcular_sla($r);
        if ($r['sla_status'] === 'vencido')  $vencidos[]  = $r;
        elseif ($r['sla_status'] === 'critico' || $r['sla_status'] === 'atencao') $proximos[] = $r;
        else $no_prazo[] = $r;
    }
    fechar_conexao($conn);
    retornar_json(true, 'OK', ['vencidos' => $vencidos, 'proximos' => $proximos, 'no_prazo' => $no_prazo]);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: INTERAÇÕES
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'interacoes') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare(
        "SELECT i.*,
                DATE_FORMAT(i.created_at, '%d/%m/%Y %H:%i') as criado_fmt
         FROM crm_interacoes i WHERE i.relacionamento_id = ? ORDER BY i.created_at ASC"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $list = [];
    while ($r = $stmt->get_result()->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: ANEXOS
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'anexos') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare(
        "SELECT id, nome_documento, nome_original, tipo_mime, tamanho_bytes, usuario_nome,
                DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') as criado_fmt
         FROM crm_anexos WHERE relacionamento_id = ? AND ativo = 1 ORDER BY created_at ASC"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $list = [];
    while ($r = $stmt->get_result()->fetch_assoc()) $list[] = $r;
    $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: DOWNLOAD ANEXO
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'download_anexo') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("SELECT * FROM crm_anexos WHERE id = ? AND ativo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close(); fechar_conexao($conn);
    if (!$row) retornar_json(false, 'Anexo não encontrado');

    $abs = dirname(__DIR__) . '/' . $row['caminho'];
    if (!file_exists($abs)) retornar_json(false, 'Arquivo não encontrado no servidor');

    header('Content-Type: ' . $row['tipo_mime']);
    header('Content-Disposition: attachment; filename="' . addslashes($row['nome_original']) . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private');
    ob_end_clean();
    readfile($abs);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// GET: USUÁRIOS (para seleção de destinatário)
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'usuarios') {
    $res  = $conn->query("SELECT id, nome, funcao, departamento FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
    $list = [];
    while ($r = $res->fetch_assoc()) $list[] = $r;
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ────────────────────────────────────────────────────────────────────────────
// GET: MORADORES (para seleção de destinatário)
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'moradores') {
    $res  = $conn->query("SELECT id, nome, unidade FROM moradores WHERE ativo = 1 ORDER BY nome ASC LIMIT 500");
    $list = [];
    while ($r = $res->fetch_assoc()) $list[] = $r;
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ────────────────────────────────────────────────────────────────────────────
// POST: CRIAR
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'criar') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!empty($_POST)) $body = array_merge($body, $_POST);

    $dest_tipo  = $body['destinatario_tipo']  ?? 'usuario';
    $dest_id    = intval($body['destinatario_id']  ?? 0);
    $dest_nome  = trim($body['destinatario_nome']  ?? '');
    $depto      = strtoupper(trim($body['departamento'] ?? ''));
    $assunto    = trim($body['assunto']   ?? '');
    $descricao  = trim($body['descricao'] ?? '');
    $prioridade = $body['prioridade'] ?? 'media';
    $data_limite = !empty($body['data_limite']) ? $body['data_limite'] : null;

    if (empty($assunto))    retornar_json(false, 'Assunto é obrigatório');
    if ($dest_id <= 0)      retornar_json(false, 'Destinatário é obrigatório');
    if (empty($depto))      retornar_json(false, 'Departamento é obrigatório');

    // Resolve nome destinatário se não fornecido
    if (empty($dest_nome)) {
        $dest_nome = _buscar_nome_destinatario($conn, $dest_tipo, $dest_id);
    }

    $numero = _gerar_numero($conn);

    $stmt = $conn->prepare(
        "INSERT INTO crm_relacionamentos
         (numero,remetente_id,remetente_nome,destinatario_tipo,destinatario_id,destinatario_nome,
          departamento,assunto,descricao,prioridade,data_limite)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('ssssisssss' . ($data_limite ? 's' : 's'),
        $numero, $uid, $unome, $dest_tipo, $dest_id, $dest_nome,
        $depto, $assunto, $descricao, $prioridade, $data_limite
    );
    if (!$stmt->execute()) { $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao criar: ' . $conn->error); }
    $novo_id = $conn->insert_id;
    $stmt->close();

    // Interação inicial automática
    if (!empty($descricao)) {
        _registrar_interacao($conn, $novo_id, $uid, $unome, $descricao, 'comentario');
    }
    _registrar_interacao($conn, $novo_id, 0, 'Sistema', "Relacionamento $numero criado por $unome.", 'sistema');

    fechar_conexao($conn);
    retornar_json(true, 'Relacionamento criado com sucesso', ['id' => $novo_id, 'numero' => $numero]);
}

// ────────────────────────────────────────────────────────────────────────────
// POST: ATUALIZAR
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'atualizar') {
    $id   = intval($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $depto      = strtoupper(trim($body['departamento'] ?? ''));
    $assunto    = trim($body['assunto']   ?? '');
    $descricao  = trim($body['descricao'] ?? '');
    $prioridade = $body['prioridade'] ?? 'media';
    $data_limite = !empty($body['data_limite']) ? $body['data_limite'] : null;

    if (empty($assunto)) retornar_json(false, 'Assunto é obrigatório');

    $stmt = $conn->prepare(
        "UPDATE crm_relacionamentos SET departamento=?,assunto=?,descricao=?,prioridade=?,data_limite=? WHERE id=?"
    );
    $stmt->bind_param('sssssi', $depto, $assunto, $descricao, $prioridade, $data_limite, $id);
    $ok = $stmt->execute(); $stmt->close();

    if ($ok) _registrar_interacao($conn, $id, $uid, $unome, "Dados do relacionamento atualizados por $unome.", 'sistema');
    fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Atualizado com sucesso' : 'Erro ao atualizar');
}

// ────────────────────────────────────────────────────────────────────────────
// POST: NOVA INTERAÇÃO (chat)
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'interagir') {
    $id      = intval($_GET['id'] ?? 0);
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $msg     = trim($body['mensagem'] ?? '');
    if ($id <= 0 || empty($msg)) retornar_json(false, 'Mensagem é obrigatória');

    // Verifica se usuário é parte do relacionamento
    $chk = $conn->prepare("SELECT id, remetente_id, destinatario_id, destinatario_tipo, status FROM crm_relacionamentos WHERE id=? AND ativo=1");
    $chk->bind_param('i', $id); $chk->execute();
    $rel = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$rel) retornar_json(false, 'Relacionamento não encontrado');
    if ($rel['status'] === 'finalizado' || $rel['status'] === 'cancelado') {
        retornar_json(false, 'Não é possível interagir em um relacionamento finalizado/cancelado');
    }

    // Muda status para em_andamento se ainda estava aberto
    if ($rel['status'] === 'aberto') {
        $conn->query("UPDATE crm_relacionamentos SET status='em_andamento' WHERE id=$id");
        _registrar_interacao($conn, $id, $uid, $unome, '', 'mudanca_status', 'aberto', 'em_andamento');
    }

    $inter_id = _registrar_interacao($conn, $id, $uid, $unome, $msg, 'comentario');
    fechar_conexao($conn);
    retornar_json(true, 'Interação registrada', ['interacao_id' => $inter_id]);
}

// ────────────────────────────────────────────────────────────────────────────
// POST: MUDAR STATUS
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'mudar_status') {
    $id   = intval($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $novo = $body['status'] ?? '';
    $validos = ['aberto','em_andamento','aguardando_retorno','finalizado','cancelado'];
    if ($id <= 0 || !in_array($novo, $validos)) retornar_json(false, 'Dados inválidos');

    $chk = $conn->prepare("SELECT status FROM crm_relacionamentos WHERE id=? AND ativo=1");
    $chk->bind_param('i', $id); $chk->execute();
    $row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$row) retornar_json(false, 'Não encontrado');

    $antigo = $row['status'];
    $fin    = ($novo === 'finalizado') ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("UPDATE crm_relacionamentos SET status=?, data_finalizacao=? WHERE id=?");
    $stmt->bind_param('ssi', $novo, $fin, $id);
    $ok = $stmt->execute(); $stmt->close();

    if ($ok) _registrar_interacao($conn, $id, $uid, $unome, "Status alterado de \"" . _label_status($antigo) . "\" para \"" . _label_status($novo) . "\".", 'mudanca_status', $antigo, $novo);
    fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Status atualizado' : 'Erro ao atualizar status');
}

// ────────────────────────────────────────────────────────────────────────────
// POST: MUDAR PRIORIDADE
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'mudar_prioridade') {
    $id   = intval($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $nova = $body['prioridade'] ?? '';
    if ($id <= 0 || !in_array($nova, ['baixa','media','alta','urgente'])) retornar_json(false, 'Dados inválidos');

    $stmt = $conn->prepare("UPDATE crm_relacionamentos SET prioridade=? WHERE id=?");
    $stmt->bind_param('si', $nova, $id);
    $ok = $stmt->execute(); $stmt->close();

    if ($ok) _registrar_interacao($conn, $id, $uid, $unome, "Prioridade alterada para \"$nova\".", 'mudanca_prioridade');
    fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Prioridade atualizada' : 'Erro');
}

// ────────────────────────────────────────────────────────────────────────────
// POST: UPLOAD ANEXO
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'upload_anexo') {
    $id            = intval($_GET['id'] ?? 0);
    $nome_doc      = trim($_POST['nome_documento'] ?? '');
    $interacao_id  = intval($_POST['interacao_id'] ?? 0) ?: null;

    if ($id <= 0)           retornar_json(false, 'ID inválido');
    if (empty($nome_doc))   retornar_json(false, 'Nome do documento é obrigatório');
    if (empty($_FILES['arquivo'])) retornar_json(false, 'Nenhum arquivo enviado');

    $file = $_FILES['arquivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) retornar_json(false, 'Erro no upload');
    if ($file['size'] > CRM_MAX_SIZE) retornar_json(false, 'Arquivo excede 15 MB');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset(CRM_MIME_ACEITOS[$mime])) retornar_json(false, 'Formato não permitido');

    $ext      = CRM_MIME_ACEITOS[$mime];
    $filename = 'crm_' . $id . '_' . time() . '_' . uniqid() . '.' . $ext;
    $dest     = CRM_UPLOAD_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) retornar_json(false, 'Falha ao salvar arquivo');

    $caminho = CRM_UPLOAD_URL . $filename;

    $stmt = $conn->prepare(
        "INSERT INTO crm_anexos
         (relacionamento_id,interacao_id,usuario_id,usuario_nome,nome_documento,nome_arquivo,nome_original,caminho,tipo_mime,tamanho_bytes)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('iiissssssi',
        $id, $interacao_id, $uid, $unome,
        $nome_doc, $filename, $file['name'],
        $caminho, $mime, $file['size']
    );
    if (!$stmt->execute()) { @unlink($dest); $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao salvar no banco'); }
    $anex_id = $conn->insert_id;
    $stmt->close();

    _registrar_interacao($conn, $id, $uid, $unome, "Anexo adicionado: \"$nome_doc\" ({$file['name']})", 'anexo');
    fechar_conexao($conn);
    retornar_json(true, 'Anexo enviado com sucesso', ['id' => $anex_id]);
}

// ────────────────────────────────────────────────────────────────────────────
// DELETE: EXCLUIR RELACIONAMENTO (soft)
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'DELETE' && $acao === 'excluir') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = intval($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("UPDATE crm_relacionamentos SET ativo=0 WHERE id=?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Removido com sucesso' : 'Erro ao remover');
}

// ────────────────────────────────────────────────────────────────────────────
// DELETE: EXCLUIR ANEXO (soft)
// ────────────────────────────────────────────────────────────────────────────
if ($metodo === 'DELETE' && $acao === 'excluir_anexo') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = intval($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("UPDATE crm_anexos SET ativo=0 WHERE id=?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Anexo removido' : 'Erro ao remover');
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida');

// ────────────────────────────────────────────────────────────────────────────
// HELPERS
// ────────────────────────────────────────────────────────────────────────────

function _gerar_numero($conn): string {
    $ano = date('Y');
    $conn->query("INSERT INTO crm_sequencia (ano, ultimo) VALUES ($ano, 1) ON DUPLICATE KEY UPDATE ultimo = ultimo + 1");
    $res = $conn->query("SELECT ultimo FROM crm_sequencia WHERE ano = $ano");
    $row = $res->fetch_assoc();
    return 'CRM-' . $ano . '-' . str_pad($row['ultimo'], 4, '0', STR_PAD_LEFT);
}

function _registrar_interacao($conn, int $rel_id, int $uid, string $unome, string $msg, string $tipo, string $ant = null, string $nov = null): int {
    $stmt = $conn->prepare(
        "INSERT INTO crm_interacoes (relacionamento_id,usuario_id,usuario_nome,mensagem,tipo,status_anterior,status_novo)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('iisssss', $rel_id, $uid, $unome, $msg, $tipo, $ant, $nov);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function _calcular_sla(array $r): string {
    if (empty($r['data_limite'])) return 'sem_prazo';
    if (in_array($r['status'] ?? '', ['finalizado','cancelado'])) return 'concluido';
    $min = intval($r['minutos_restantes'] ?? 0);
    if ($min < 0)        return 'vencido';
    if ($min < 60)       return 'critico';   // < 1h
    if ($min < 1440)     return 'atencao';   // < 24h
    return 'ok';
}

function _buscar_nome_destinatario($conn, string $tipo, int $id): string {
    if ($tipo === 'usuario') {
        $s = $conn->prepare("SELECT nome FROM usuarios WHERE id=?");
    } else {
        $s = $conn->prepare("SELECT nome FROM moradores WHERE id=?");
    }
    $s->bind_param('i', $id); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r['nome'] ?? 'Desconhecido';
}

function _label_status(string $s): string {
    $map = ['aberto'=>'Aberto','em_andamento'=>'Em Andamento','aguardando_retorno'=>'Aguardando Retorno','finalizado'=>'Finalizado','cancelado'=>'Cancelado'];
    return $map[$s] ?? $s;
}
?>
