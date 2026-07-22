<?php
// =====================================================
// API PARA CRUD DE VISITANTES v2
// Suporte: foto, documento_arquivo, placa_veiculo,
//          telefone_contato, busca por RG/CPF
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

verificarAutenticacao(true, 'operador');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Diretório de uploads
define('UPLOAD_DIR_FOTOS', __DIR__ . '/../uploads/visitantes/fotos/');
define('UPLOAD_DIR_DOCS',  __DIR__ . '/../uploads/visitantes/documentos/');
define('UPLOAD_URL_FOTOS', '../uploads/visitantes/fotos/');
define('UPLOAD_URL_DOCS',  '../uploads/visitantes/documentos/');

// Criar diretórios se não existirem
if (!is_dir(UPLOAD_DIR_FOTOS)) @mkdir(UPLOAD_DIR_FOTOS, 0755, true);
if (!is_dir(UPLOAD_DIR_DOCS))  @mkdir(UPLOAD_DIR_DOCS,  0755, true);

// ========== UPLOAD DE FOTO / DOCUMENTO ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'upload') {
    verificarPermissao('operador');

    $tipo_upload = $_GET['tipo'] ?? 'foto'; // 'foto' ou 'documento'
    $visitante_id = intval($_GET['visitante_id'] ?? 0);

    if ($visitante_id <= 0) retornar_json(false, "ID do visitante inválido");

    $campo_arquivo = ($tipo_upload === 'foto') ? $_FILES['foto'] ?? null : $_FILES['documento'] ?? null;
    if (!$campo_arquivo || $campo_arquivo['error'] !== UPLOAD_ERR_OK) {
        retornar_json(false, "Nenhum arquivo enviado ou erro no upload");
    }

    $ext_permitidas = ($tipo_upload === 'foto')
        ? ['jpg', 'jpeg', 'png', 'webp']
        : ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

    $ext = strtolower(pathinfo($campo_arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ext_permitidas)) {
        retornar_json(false, "Formato de arquivo não permitido. Use: " . implode(', ', $ext_permitidas));
    }

    // Limite de 5MB
    if ($campo_arquivo['size'] > 5 * 1024 * 1024) {
        retornar_json(false, "Arquivo muito grande. Máximo: 5MB");
    }

    $nome_arquivo = ($tipo_upload === 'foto' ? 'foto' : 'doc') . '_' . $visitante_id . '_' . time() . '.' . $ext;
    $dir_destino  = ($tipo_upload === 'foto') ? UPLOAD_DIR_FOTOS : UPLOAD_DIR_DOCS;
    $url_relativa = ($tipo_upload === 'foto') ? UPLOAD_URL_FOTOS . $nome_arquivo : UPLOAD_URL_DOCS . $nome_arquivo;

    if (!move_uploaded_file($campo_arquivo['tmp_name'], $dir_destino . $nome_arquivo)) {
        retornar_json(false, "Erro ao salvar arquivo no servidor");
    }

    // Atualizar o campo no banco
    $campo_db = ($tipo_upload === 'foto') ? 'foto' : 'documento_arquivo';
    $stmt = $conexao->prepare("UPDATE visitantes SET $campo_db = ? WHERE id = ?");
    $stmt->bind_param("si", $url_relativa, $visitante_id);
    if (!$stmt->execute()) {
        retornar_json(false, "Arquivo salvo mas erro ao atualizar banco: " . $stmt->error);
    }
    $stmt->close();

    registrar_log($conexao, 'INFO', "Upload $tipo_upload visitante ID $visitante_id: $nome_arquivo");
    retornar_json(true, "Arquivo enviado com sucesso", ['url' => $url_relativa, 'nome' => $nome_arquivo]);
}

// ========== BUSCAR POR DOCUMENTO (RG ou CPF) — para o módulo de Registro ==========
if ($metodo === 'GET' && isset($_GET['documento'])) {
    $doc = sanitizar($conexao, trim($_GET['documento']));
    $doc_limpo = preg_replace('/[^0-9A-Za-z]/', '', $doc);

    $stmt = $conexao->prepare(
        "SELECT id, nome_completo, documento, tipo_documento, telefone, celular, telefone_contato,
                placa_veiculo, foto, observacao, ativo
         FROM visitantes
         WHERE REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?
            OR documento = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $doc_limpo, $doc);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        retornar_json(true, "Visitante encontrado", $row);
    } else {
        retornar_json(false, "Visitante não encontrado com este documento");
    }
}

// ========== LISTAR VISITANTES ==========
if ($metodo === 'GET') {
    $busca = isset($_GET['busca']) ? sanitizar($conexao, $_GET['busca']) : '';

    $sql = "SELECT id, nome_completo, documento, tipo_documento,
                   telefone, celular, telefone_contato, email,
                   placa_veiculo, foto, documento_arquivo,
                   cep, endereco, numero, complemento, bairro, cidade, estado,
                   observacao, ativo,
                   DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_formatada
            FROM visitantes ";

    if (!empty($busca)) {
        $sql .= "WHERE nome_completo LIKE '%$busca%'
                    OR documento LIKE '%$busca%'
                    OR telefone_contato LIKE '%$busca%'
                    OR placa_veiculo LIKE '%$busca%' ";
    }

    $sql .= "ORDER BY nome_completo ASC";

    $resultado = $conexao->query($sql);
    $visitantes = [];

    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $visitantes[] = $row;
        }
    }

    retornar_json(true, "Visitantes listados com sucesso", $visitantes);
}

// ========== CRIAR VISITANTE ==========
if ($metodo === 'POST') {
    verificarPermissao('operador');
    $dados = json_decode(file_get_contents('php://input'), true);

    $nome_completo    = sanitizar($conexao, trim($dados['nome_completo']    ?? ''));
    $documento        = sanitizar($conexao, trim($dados['documento']        ?? ''));
    $tipo_documento   = sanitizar($conexao, $dados['tipo_documento']        ?? 'CPF');
    $telefone_contato = sanitizar($conexao, trim($dados['telefone_contato'] ?? ''));
    $telefone         = sanitizar($conexao, trim($dados['telefone']         ?? ''));
    $celular          = sanitizar($conexao, trim($dados['celular']          ?? ''));
    $placa_veiculo    = strtoupper(sanitizar($conexao, preg_replace('/[^A-Za-z0-9]/', '', $dados['placa_veiculo'] ?? '')));
    $email            = sanitizar($conexao, trim($dados['email']            ?? ''));
    $cep              = sanitizar($conexao, trim($dados['cep']              ?? ''));
    $endereco         = sanitizar($conexao, trim($dados['endereco']         ?? ''));
    $numero           = sanitizar($conexao, trim($dados['numero']           ?? ''));
    $complemento      = sanitizar($conexao, trim($dados['complemento']      ?? ''));
    $bairro           = sanitizar($conexao, trim($dados['bairro']           ?? ''));
    $cidade           = sanitizar($conexao, trim($dados['cidade']           ?? ''));
    $estado           = sanitizar($conexao, trim($dados['estado']           ?? ''));
    $observacao       = sanitizar($conexao, trim($dados['observacao']       ?? ''));

    // Validações
    if (empty($nome_completo)) retornar_json(false, "Nome completo é obrigatório");
    if (empty($documento))     retornar_json(false, "Documento (RG ou CPF) é obrigatório");

    $tipo_documento = in_array(strtoupper($tipo_documento), ['RG', 'CPF']) ? strtoupper($tipo_documento) : 'CPF';

    // Validar CPF
    if ($tipo_documento === 'CPF') {
        $doc_limpo = preg_replace('/[^0-9]/', '', $documento);
        if (strlen($doc_limpo) !== 11) {
            retornar_json(false, "CPF inválido — deve ter 11 dígitos");
        }
    }

    // Verificar duplicidade por documento (ignorando pontuação)
    $doc_limpo_busca = preg_replace('/[^0-9A-Za-z]/', '', $documento);
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo FROM visitantes
         WHERE REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?"
    );
    $stmt->bind_param("s", $doc_limpo_busca);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id_existente, $nome_existente);
        $stmt->fetch();
        $stmt->close();
        retornar_json(false, "Documento já cadastrado para: $nome_existente (ID: $id_existente)", [
            'id' => $id_existente,
            'nome' => $nome_existente,
            'duplicado' => true
        ]);
    }
    $stmt->close();

    // Inserir visitante
    $stmt = $conexao->prepare(
        "INSERT INTO visitantes
            (nome_completo, documento, tipo_documento, telefone_contato, telefone, celular,
             placa_veiculo, email, cep, endereco, numero, complemento, bairro, cidade, estado, observacao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "ssssssssssssssss",
        $nome_completo, $documento, $tipo_documento, $telefone_contato, $telefone, $celular,
        $placa_veiculo, $email, $cep, $endereco, $numero, $complemento, $bairro, $cidade, $estado, $observacao
    );

    if ($stmt->execute()) {
        $id = $conexao->insert_id;
        registrar_log($conexao, 'INFO', "Visitante cadastrado: $nome_completo ($tipo_documento: $documento) ID: $id");
        retornar_json(true, "Visitante cadastrado com sucesso", ['id' => $id]);
    } else {
        retornar_json(false, "Erro ao cadastrar visitante: " . $stmt->error);
    }
    $stmt->close();
}

// ========== ATUALIZAR VISITANTE ==========
if ($metodo === 'PUT') {
    verificarPermissao('operador');
    $dados = json_decode(file_get_contents('php://input'), true);

    $id               = intval($dados['id'] ?? 0);
    $nome_completo    = sanitizar($conexao, trim($dados['nome_completo']    ?? ''));
    $documento        = sanitizar($conexao, trim($dados['documento']        ?? ''));
    $tipo_documento   = sanitizar($conexao, $dados['tipo_documento']        ?? 'CPF');
    $telefone_contato = sanitizar($conexao, trim($dados['telefone_contato'] ?? ''));
    $telefone         = sanitizar($conexao, trim($dados['telefone']         ?? ''));
    $celular          = sanitizar($conexao, trim($dados['celular']          ?? ''));
    $placa_veiculo    = strtoupper(sanitizar($conexao, preg_replace('/[^A-Za-z0-9]/', '', $dados['placa_veiculo'] ?? '')));
    $email            = sanitizar($conexao, trim($dados['email']            ?? ''));
    $cep              = sanitizar($conexao, trim($dados['cep']              ?? ''));
    $endereco         = sanitizar($conexao, trim($dados['endereco']         ?? ''));
    $numero           = sanitizar($conexao, trim($dados['numero']           ?? ''));
    $complemento      = sanitizar($conexao, trim($dados['complemento']      ?? ''));
    $bairro           = sanitizar($conexao, trim($dados['bairro']           ?? ''));
    $cidade           = sanitizar($conexao, trim($dados['cidade']           ?? ''));
    $estado           = sanitizar($conexao, trim($dados['estado']           ?? ''));
    $observacao       = sanitizar($conexao, trim($dados['observacao']       ?? ''));

    if ($id <= 0)          retornar_json(false, "ID inválido");
    if (empty($nome_completo)) retornar_json(false, "Nome completo é obrigatório");
    if (empty($documento))     retornar_json(false, "Documento é obrigatório");

    $tipo_documento = in_array(strtoupper($tipo_documento), ['RG', 'CPF']) ? strtoupper($tipo_documento) : 'CPF';

    // Verificar duplicidade em outro visitante
    $doc_limpo_busca = preg_replace('/[^0-9A-Za-z]/', '', $documento);
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo FROM visitantes
         WHERE REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?
           AND id != ?"
    );
    $stmt->bind_param("si", $doc_limpo_busca, $id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id_dup, $nome_dup);
        $stmt->fetch();
        $stmt->close();
        retornar_json(false, "Documento já cadastrado para outro visitante: $nome_dup (ID: $id_dup)");
    }
    $stmt->close();

    $stmt = $conexao->prepare(
        "UPDATE visitantes SET
            nome_completo = ?, documento = ?, tipo_documento = ?,
            telefone_contato = ?, telefone = ?, celular = ?,
            placa_veiculo = ?, email = ?,
            cep = ?, endereco = ?, numero = ?, complemento = ?,
            bairro = ?, cidade = ?, estado = ?, observacao = ?
         WHERE id = ?"
    );
    $stmt->bind_param(
        "ssssssssssssssssi",
        $nome_completo, $documento, $tipo_documento,
        $telefone_contato, $telefone, $celular,
        $placa_veiculo, $email,
        $cep, $endereco, $numero, $complemento,
        $bairro, $cidade, $estado, $observacao, $id
    );

    if ($stmt->execute()) {
        registrar_log($conexao, 'INFO', "Visitante atualizado: $nome_completo (ID: $id)");
        retornar_json(true, "Visitante atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar visitante: " . $stmt->error);
    }
    $stmt->close();
}

// ========== EXCLUIR VISITANTE ==========
if ($metodo === 'DELETE') {
    verificarPermissao('admin');
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);

    if ($id <= 0) retornar_json(false, "ID inválido");

    $stmt = $conexao->prepare("SELECT nome_completo, foto, documento_arquivo FROM visitantes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) retornar_json(false, "Visitante não encontrado");

    $stmt = $conexao->prepare("DELETE FROM visitantes WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        // Remover arquivos de upload
        if (!empty($row['foto']))              @unlink(__DIR__ . '/../' . ltrim($row['foto'], './'));
        if (!empty($row['documento_arquivo'])) @unlink(__DIR__ . '/../' . ltrim($row['documento_arquivo'], './'));

        registrar_log($conexao, 'INFO', "Visitante excluído: " . $row['nome_completo'] . " (ID: $id)");
        retornar_json(true, "Visitante excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir visitante: " . $stmt->error);
    }
    $stmt->close();
}

fechar_conexao($conexao);
