<?php
// =====================================================
// API PARA CRUD DE GRUPOS DE INVENTÁRIO
// Sistema ERP Condomínio - ASL
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

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== LISTAR GRUPOS ==========
if ($metodo === 'GET') {
    $apenas_ativos = isset($_GET['ativos']) ? true : false;
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

    $sql = "SELECT id, nome, descricao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_formatada
            FROM grupos_inventario
            WHERE 1=1";

    if ($apenas_ativos) {
        $sql .= " AND ativo = 1";
    }

    if (!empty($busca)) {
        $busca_esc = $conexao->real_escape_string($busca);
        $sql .= " AND nome LIKE '%$busca_esc%'";
    }

    $sql .= " ORDER BY nome ASC";

    $resultado = $conexao->query($sql);
    $grupos = [];
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $row['ativo'] = (bool)$row['ativo'];
            $grupos[] = $row;
        }
    }

    retornar_json(true, "Grupos listados com sucesso", $grupos);
}

// ========== CRIAR GRUPO ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);

    $nome = trim($dados['nome'] ?? '');
    $descricao = trim($dados['descricao'] ?? '');

    if (empty($nome)) {
        retornar_json(false, "Nome do grupo é obrigatório");
    }

    if (strlen($nome) > 100) {
        retornar_json(false, "Nome do grupo deve ter no máximo 100 caracteres");
    }

    // Verificar duplicidade
    $stmt = $conexao->prepare("SELECT id FROM grupos_inventario WHERE nome = ?");
    $stmt->bind_param("s", $nome);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        retornar_json(false, "Já existe um grupo com este nome");
    }
    $stmt->close();

    // Inserir
    $stmt = $conexao->prepare("INSERT INTO grupos_inventario (nome, descricao, ativo) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $nome, $descricao);

    if ($stmt->execute()) {
        $novo_id = $stmt->insert_id;
        $stmt->close();

        // Buscar o grupo recém-criado para retornar
        $stmt2 = $conexao->prepare("SELECT id, nome, descricao, ativo FROM grupos_inventario WHERE id = ?");
        $stmt2->bind_param("i", $novo_id);
        $stmt2->execute();
        $res = $stmt2->get_result();
        $grupo = $res->fetch_assoc();
        $grupo['ativo'] = (bool)$grupo['ativo'];
        $stmt2->close();

        retornar_json(true, "Grupo criado com sucesso", $grupo);
    } else {
        retornar_json(false, "Erro ao criar grupo: " . $stmt->error);
    }
}

// ========== ATUALIZAR GRUPO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);

    $id = intval($dados['id'] ?? 0);
    $nome = trim($dados['nome'] ?? '');
    $descricao = trim($dados['descricao'] ?? '');
    $ativo = isset($dados['ativo']) ? (int)(bool)$dados['ativo'] : 1;

    if ($id <= 0) retornar_json(false, "ID inválido");
    if (empty($nome)) retornar_json(false, "Nome do grupo é obrigatório");

    // Verificar duplicidade em outro registro
    $stmt = $conexao->prepare("SELECT id FROM grupos_inventario WHERE nome = ? AND id != ?");
    $stmt->bind_param("si", $nome, $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        retornar_json(false, "Já existe outro grupo com este nome");
    }
    $stmt->close();

    $stmt = $conexao->prepare("UPDATE grupos_inventario SET nome = ?, descricao = ?, ativo = ? WHERE id = ?");
    $stmt->bind_param("ssii", $nome, $descricao, $ativo, $id);

    if ($stmt->execute()) {
        $stmt->close();
        retornar_json(true, "Grupo atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar grupo: " . $stmt->error);
    }
}

// ========== EXCLUIR GRUPO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);

    if ($id <= 0) retornar_json(false, "ID inválido");

    // Verificar se há itens usando este grupo
    $stmt = $conexao->prepare("SELECT COUNT(*) as total FROM inventario WHERE grupo_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row['total'] > 0) {
        retornar_json(false, "Não é possível excluir: existem {$row['total']} item(ns) neste grupo. Remova os itens primeiro ou altere o grupo deles.");
    }

    $stmt = $conexao->prepare("DELETE FROM grupos_inventario WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $stmt->close();
        retornar_json(true, "Grupo excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir grupo: " . $stmt->error);
    }
}

fechar_conexao($conexao);
