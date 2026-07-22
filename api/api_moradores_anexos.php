<?php
// =====================================================
// API: ANEXOS DE MORADORES
// =====================================================
// Ações:
//   GET  ?morador_id=N          → listar anexos do morador
//   GET  ?id=N                  → obter anexo específico
//   GET  ?download=N            → download do arquivo
//   POST (multipart/form-data)  → upload de novo anexo
//     campos: morador_id, nome_documento, arquivo (file)
//   DELETE ?id=N                → remover anexo
//
// Formatos aceitos: PDF, JPG, JPEG, PNG, GIF, WEBP
// Tamanho máximo: 10 MB

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'error_logger.php';
ob_end_clean();

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$allowed_origins = [
    'https://asl.erpcondominios.com.br',
    'http://asl.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Helpers ───────────────────────────────────────────────────────────────────
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Constantes ────────────────────────────────────────────────────────────────
define('UPLOAD_DIR',      dirname(__DIR__) . '/uploads/moradores_anexos/');
define('UPLOAD_URL_PATH', 'uploads/moradores_anexos/');
define('MAX_TAMANHO',     10 * 1024 * 1024); // 10 MB
define('TIPOS_ACEITOS', [
    'application/pdf'  => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/jpg'        => 'jpg',
    'image/png'        => 'png',
    'image/gif'        => 'gif',
    'image/webp'       => 'webp',
]);

// Garantir que o diretório de upload existe
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ── Autenticação ──────────────────────────────────────────────────────────────
try {
    verificarAutenticacao(true, 'operador');
} catch (Exception $e) {
    retornar_json(false, 'Não autenticado', null);
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
if (!$conexao) { retornar_json(false, 'Erro ao conectar ao banco de dados'); }

// ── GET: download de arquivo ──────────────────────────────────────────────────
if ($metodo === 'GET' && isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $stmt = $conexao->prepare("SELECT nome_original, caminho, tipo_mime FROM moradores_anexos WHERE id = ? AND ativo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { retornar_json(false, 'Anexo não encontrado'); }
    $row = $res->fetch_assoc();
    $stmt->close();
    fechar_conexao($conexao);

    $caminho_abs = dirname(__DIR__) . '/' . $row['caminho'];
    if (!file_exists($caminho_abs)) { retornar_json(false, 'Arquivo não encontrado no servidor'); }

    // Servir o arquivo diretamente
    header('Content-Type: ' . $row['tipo_mime']);
    header('Content-Disposition: attachment; filename="' . addslashes($row['nome_original']) . '"');
    header('Content-Length: ' . filesize($caminho_abs));
    header('Cache-Control: private');
    ob_end_clean();
    readfile($caminho_abs);
    exit;
}

// ── GET: obter anexo específico ───────────────────────────────────────────────
if ($metodo === 'GET' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conexao->prepare(
        "SELECT a.*, m.nome as morador_nome
         FROM moradores_anexos a
         LEFT JOIN moradores m ON a.morador_id = m.id
         WHERE a.id = ? AND a.ativo = 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { retornar_json(false, 'Anexo não encontrado'); }
    $row = $res->fetch_assoc();
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Anexo encontrado', $row);
}

// ── GET: listar anexos de um morador ─────────────────────────────────────────
if ($metodo === 'GET') {
    $morador_id = isset($_GET['morador_id']) ? intval($_GET['morador_id']) : 0;
    if ($morador_id <= 0) { retornar_json(false, 'morador_id é obrigatório'); }

    $stmt = $conexao->prepare(
        "SELECT id, morador_id, nome_documento, nome_original, tipo_mime, tamanho_bytes,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro,
                criado_por
         FROM moradores_anexos
         WHERE morador_id = ? AND ativo = 1
         ORDER BY data_cadastro DESC"
    );
    $stmt->bind_param('i', $morador_id);
    $stmt->execute();
    $res   = $stmt->get_result();
    $lista = [];
    while ($row = $res->fetch_assoc()) { $lista[] = $row; }
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Anexos listados com sucesso', $lista);
}

// ── POST: upload de novo anexo ────────────────────────────────────────────────
if ($metodo === 'POST') {
    verificarPermissao('admin');

    $morador_id     = isset($_POST['morador_id'])     ? intval($_POST['morador_id'])          : 0;
    $nome_documento = isset($_POST['nome_documento']) ? trim($_POST['nome_documento'])         : '';

    if ($morador_id <= 0)           { retornar_json(false, 'morador_id é obrigatório'); }
    if (empty($nome_documento))     { retornar_json(false, 'Nome do documento é obrigatório'); }
    if (!isset($_FILES['arquivo']))  { retornar_json(false, 'Nenhum arquivo enviado'); }

    $arquivo = $_FILES['arquivo'];

    // Verificar erros de upload
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $erros = [
            UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o limite do servidor (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo selecionado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário ausente',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar no disco',
            UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP',
        ];
        retornar_json(false, $erros[$arquivo['error']] ?? 'Erro desconhecido no upload');
    }

    // Verificar tamanho
    if ($arquivo['size'] > MAX_TAMANHO) {
        retornar_json(false, 'Arquivo excede o limite de 10 MB');
    }

    // Verificar MIME type real (não confiar no informado pelo cliente)
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $tipo_mime = $finfo->file($arquivo['tmp_name']);
    if (!array_key_exists($tipo_mime, TIPOS_ACEITOS)) {
        retornar_json(false, 'Formato não permitido. Envie PDF, JPG, PNG, GIF ou WEBP');
    }

    // Verificar se morador existe
    $stmt = $conexao->prepare("SELECT id FROM moradores WHERE id = ?");
    $stmt->bind_param('i', $morador_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) { $stmt->close(); retornar_json(false, 'Morador não encontrado'); }
    $stmt->close();

    // Gerar nome único para o arquivo
    $extensao      = TIPOS_ACEITOS[$tipo_mime];
    $nome_servidor = time() . '_' . uniqid() . '.' . $extensao;
    $caminho_abs   = UPLOAD_DIR . $nome_servidor;
    $caminho_rel   = UPLOAD_URL_PATH . $nome_servidor;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminho_abs)) {
        retornar_json(false, 'Falha ao mover o arquivo para o servidor');
    }

    // Obter usuário logado
    $criado_por = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_email'] ?? 'Sistema';

    // Inserir no banco
    $stmt = $conexao->prepare(
        "INSERT INTO moradores_anexos
         (morador_id, nome_documento, nome_arquivo, nome_original, caminho, tipo_mime, tamanho_bytes, criado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'isssssss',
        $morador_id,
        $nome_documento,
        $nome_servidor,
        $arquivo['name'],
        $caminho_rel,
        $tipo_mime,
        $arquivo['size'],
        $criado_por
    );

    if ($stmt->execute()) {
        $id_inserido = $conexao->insert_id;
        registrar_log('ANEXO_MORADOR_CRIADO', "Anexo '$nome_documento' vinculado ao morador ID $morador_id", $criado_por);
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Anexo enviado com sucesso', ['id' => $id_inserido]);
    } else {
        // Remover arquivo se falhou a inserção
        @unlink($caminho_abs);
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao salvar no banco de dados');
    }
}

// ── DELETE: remover anexo ─────────────────────────────────────────────────────
if ($metodo === 'DELETE') {
    verificarPermissao('admin');

    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = isset($dados['id']) ? intval($dados['id']) : 0;
    if ($id <= 0) { retornar_json(false, 'ID inválido'); }

    // Buscar caminho do arquivo antes de deletar
    $stmt = $conexao->prepare("SELECT caminho, nome_documento FROM moradores_anexos WHERE id = ? AND ativo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { $stmt->close(); retornar_json(false, 'Anexo não encontrado'); }
    $row = $res->fetch_assoc();
    $stmt->close();

    // Soft delete (manter arquivo no disco para auditoria)
    $stmt = $conexao->prepare("UPDATE moradores_anexos SET ativo = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $criado_por = $_SESSION['usuario_nome'] ?? 'Sistema';
        registrar_log('ANEXO_MORADOR_REMOVIDO', "Anexo '{$row['nome_documento']}' removido (ID $id)", $criado_por);
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Anexo removido com sucesso');
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao remover anexo');
    }
}

retornar_json(false, 'Método não suportado');
?>
