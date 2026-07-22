<?php
// =====================================================
// API: EVIDÊNCIA FOTOGRÁFICA DAS LEITURAS DE HIDRÔMETROS
// =====================================================
// Ações:
//   GET  ?leitura_id=N              → listar fotos já vinculadas a uma leitura
//   GET  ?hidrometro_id=N           → galeria completa do hidrômetro (todas as leituras)
//   GET  ?hidrometro_id=N&ultima=1  → só a foto mais recente do hidrômetro
//   POST (multipart/form-data)      → upload de uma foto (câmera ou anexo),
//                                      ainda SEM leitura_id — fica "pendente"
//                                      até api_leituras.php vinculá-la ao salvar
//                                      a leitura (individual ou coletiva)
//     campos: hidrometro_id, origem ('camera'|'upload'), arquivo (file)
//   DELETE ?id=N                    → remove uma foto AINDA NÃO vinculada
//                                      (leitura_id IS NULL) — usado quando o
//                                      operador cancela/remove antes de salvar.
//                                      Fotos já vinculadas a uma leitura são
//                                      permanentes e nunca são substituídas.
//
// Formatos aceitos: JPG, JPEG, PNG, WEBP — máx. 8 MB (o front-end já
// redimensiona/comprime antes de enviar; o backend valida de novo).

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

define('LF_UPLOAD_DIR',  dirname(__DIR__) . '/uploads/leituras_fotos/');
define('LF_UPLOAD_PATH', 'uploads/leituras_fotos/');
define('LF_MAX_TAMANHO', 8 * 1024 * 1024); // 8 MB (já vem comprimido do front-end)
define('LF_TIPOS_ACEITOS', [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);

if (!is_dir(LF_UPLOAD_DIR)) {
    mkdir(LF_UPLOAD_DIR, 0755, true);
}

// ── Autenticação: só o ERP Administrativo captura/anexa fotos ──────────────
// (Portal do Morador é somente leitura — usa visualizar_foto_leitura.php)
try {
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
} catch (Exception $e) {
    retornar_json(false, 'Não autenticado');
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
if (!$conexao) { retornar_json(false, 'Erro ao conectar ao banco de dados'); }

// ── GET: listar/consultar fotos ─────────────────────────────────────────────
if ($metodo === 'GET') {
    if (isset($_GET['leitura_id'])) {
        $leitura_id = intval($_GET['leitura_id']);
        $stmt = $conexao->prepare(
            "SELECT id, hidrometro_id, origem, lancado_por_nome, data_upload
             FROM leituras_fotos WHERE tenant_id = $tenant_id AND leitura_id = ? ORDER BY data_upload ASC"
        );
        $stmt->bind_param('i', $leitura_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $fotos = [];
        while ($row = $res->fetch_assoc()) { $fotos[] = $row; }
        $stmt->close();
        retornar_json(true, 'Fotos listadas com sucesso', $fotos);
    }

    if (isset($_GET['hidrometro_id'])) {
        $hidrometro_id = intval($_GET['hidrometro_id']);
        $apenas_ultima = isset($_GET['ultima']);

        $sql = "SELECT f.id, f.leitura_id, f.hidrometro_id, f.origem, f.lancado_por_nome, f.data_upload,
                       h.unidade, h.numero_hidrometro,
                       DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') as data_leitura_formatada,
                       l.leitura_atual, l.consumo
                FROM leituras_fotos f
                INNER JOIN hidrometros h ON h.id = f.hidrometro_id
                LEFT JOIN leituras l ON l.id = f.leitura_id
                WHERE f.hidrometro_id = ?
                ORDER BY f.data_upload DESC" . ($apenas_ultima ? " LIMIT 1" : "");

        $stmt = $conexao->prepare($sql);
        $stmt->bind_param('i', $hidrometro_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $fotos = [];
        while ($row = $res->fetch_assoc()) { $fotos[] = $row; }
        $stmt->close();
        retornar_json(true, 'Fotos listadas com sucesso', $apenas_ultima ? ($fotos[0] ?? null) : $fotos);
    }

    retornar_json(false, 'Informe leitura_id ou hidrometro_id');
}

// ── POST: upload de uma nova foto (ainda sem leitura_id) ────────────────────
if ($metodo === 'POST') {
    $hidrometro_id = intval($_POST['hidrometro_id'] ?? 0);
    $origem        = ($_POST['origem'] ?? 'upload') === 'camera' ? 'camera' : 'upload';

    if ($hidrometro_id <= 0) {
        retornar_json(false, 'Hidrômetro é obrigatório');
    }

    // Confirma que o hidrômetro existe
    $stmt = $conexao->prepare("SELECT id FROM hidrometros WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param('i', $hidrometro_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) { $stmt->close(); retornar_json(false, 'Hidrômetro não encontrado'); }
    $stmt->close();

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] === UPLOAD_ERR_NO_FILE) {
        retornar_json(false, 'Nenhuma foto enviada');
    }
    $arquivo = $_FILES['arquivo'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $erros = [
            UPLOAD_ERR_INI_SIZE   => 'Foto excede o limite do servidor',
            UPLOAD_ERR_FORM_SIZE  => 'Foto excede o limite do formulário',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário ausente',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar no disco',
            UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP',
        ];
        retornar_json(false, $erros[$arquivo['error']] ?? 'Erro desconhecido no upload');
    }

    if ($arquivo['size'] > LF_MAX_TAMANHO) {
        retornar_json(false, 'Foto excede o limite de 8 MB');
    }

    // Nunca confiar no MIME informado pelo cliente — detectar de fato
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $tipo_mime = $finfo->file($arquivo['tmp_name']);
    if (!array_key_exists($tipo_mime, LF_TIPOS_ACEITOS)) {
        retornar_json(false, 'Formato não permitido. Envie JPG, PNG ou WEBP');
    }

    $extensao      = LF_TIPOS_ACEITOS[$tipo_mime];
    $nome_servidor = 'leitura_' . $hidrometro_id . '_' . time() . '_' . uniqid() . '.' . $extensao;
    $caminho_abs   = LF_UPLOAD_DIR . $nome_servidor;
    $caminho_rel   = LF_UPLOAD_PATH . $nome_servidor;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminho_abs)) {
        retornar_json(false, 'Falha ao salvar a foto no servidor');
    }

    $usuario_nome = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_email'] ?? 'Sistema';
    $usuario_id   = intval($_SESSION['usuario_id'] ?? 0);
    $ip_origem    = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conexao->prepare(
        "INSERT INTO leituras_fotos
            (leitura_id, hidrometro_id, nome_arquivo, nome_original, caminho, tipo_mime, tamanho_bytes,
             origem, lancado_por_tipo, lancado_por_id, lancado_por_nome, ip_origem)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'usuario', ?, ?, ?)"
    );
    $stmt->bind_param(
        'isssssisss',
        $hidrometro_id,
        $nome_servidor,
        $arquivo['name'],
        $caminho_rel,
        $tipo_mime,
        $arquivo['size'],
        $origem,
        $usuario_id,
        $usuario_nome,
        $ip_origem
    );

    if ($stmt->execute()) {
        $foto_id = $conexao->insert_id;
        $stmt->close();
        registrar_log(
            'FOTO_LEITURA_UPLOAD',
            "Foto de leitura enviada ({$origem}) para hidrômetro ID {$hidrometro_id} — aguardando vínculo com a leitura",
            $usuario_nome
        );
        retornar_json(true, 'Foto enviada com sucesso', ['id' => $foto_id]);
    } else {
        @unlink($caminho_abs);
        $stmt->close();
        retornar_json(false, 'Erro ao salvar a foto no banco de dados');
    }
}

// ── DELETE: remover foto ainda não vinculada a nenhuma leitura ──────────────
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = isset($dados['id']) ? intval($dados['id']) : 0;
    if ($id <= 0) { retornar_json(false, 'ID inválido'); }

    $stmt = $conexao->prepare("SELECT caminho, leitura_id FROM leituras_fotos WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { $stmt->close(); retornar_json(false, 'Foto não encontrada'); }
    $foto = $res->fetch_assoc();
    $stmt->close();

    if ($foto['leitura_id'] !== null) {
        // Regra de negócio: fotos já vinculadas a uma leitura são permanentes
        retornar_json(false, 'Esta foto já está vinculada a uma leitura registrada e não pode ser removida');
    }

    $stmt = $conexao->prepare("DELETE FROM leituras_fotos WHERE tenant_id = $tenant_id AND id = ? AND leitura_id IS NULL");
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $stmt->close();
        @unlink(dirname(__DIR__) . '/' . $foto['caminho']);
        retornar_json(true, 'Foto removida com sucesso');
    }
    $stmt->close();
    retornar_json(false, 'Não foi possível remover a foto');
}

retornar_json(false, 'Método não suportado');
