<?php
// =====================================================
// API - PLANOS DE CONTAS
// =====================================================

require_once 'config.php';
require_once 'auth_helper.php';

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

$conexao = conectar_banco();

// ========== LISTAR PLANOS DE CONTAS ==========
if ($acao === 'listar' && $metodo === 'GET') {
    $tipo = $_GET['tipo'] ?? '';
    $categoria = $_GET['categoria'] ?? '';
    $ativo = $_GET['ativo'] ?? 1;
    
    $sql = "SELECT * FROM planos_contas WHERE ativo = ?";
    $params = [$ativo];
    $types = "i";
    
    if (!empty($tipo)) {
        $sql .= " AND tipo = ?";
        $params[] = $tipo;
        $types .= "s";
    }
    
    if (!empty($categoria)) {
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
        $types .= "s";
    }
    
    $sql .= " ORDER BY codigo ASC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $planos = [];
    while ($row = $result->fetch_assoc()) {
        $planos[] = $row;
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Planos de contas carregados', $planos);
}

// ========== BUSCAR PLANO DE CONTA ==========
if ($acao === 'buscar' && $metodo === 'GET') {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("SELECT * FROM planos_contas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $plano = $result->fetch_assoc();
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Plano encontrado', $plano);
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Plano não encontrado');
    }
}

// ========== CADASTRAR PLANO DE CONTA ==========
if ($acao === 'cadastrar' && $metodo === 'POST') {
    $codigo = trim($_POST['codigo'] ?? '');
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $natureza = trim($_POST['natureza'] ?? 'DEVEDORA');
    $categoria = trim($_POST['categoria'] ?? '');
    
    // Validações
    if (empty($codigo)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Código é obrigatório');
    }
    
    if (empty($nome)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Nome é obrigatório');
    }
    
    if (empty($tipo)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Tipo é obrigatório');
    }
    
    // Verificar se código já existe
    $stmt_check = $conexao->prepare("SELECT id FROM planos_contas WHERE codigo = ?");
    $stmt_check->bind_param("s", $codigo);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $stmt_check->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Código já existe no sistema');
    }
    
    $stmt_check->close();
    
    // Inserir plano
    $sql_insert = "INSERT INTO planos_contas (codigo, nome, descricao, tipo, natureza, categoria, ativo, data_criacao) 
                   VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";
    
    $stmt_insert = $conexao->prepare($sql_insert);
    $stmt_insert->bind_param("ssssss", $codigo, $nome, $descricao, $tipo, $natureza, $categoria);
    
    if ($stmt_insert->execute()) {
        $novo_id = $stmt_insert->insert_id;
        $stmt_insert->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Plano de conta cadastrado com sucesso', ['id' => $novo_id]);
    } else {
        $erro = $stmt_insert->error;
        $stmt_insert->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao cadastrar: ' . $erro);
    }
}

// ========== ATUALIZAR PLANO DE CONTA ==========
if ($acao === 'atualizar' && $metodo === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $natureza = trim($_POST['natureza'] ?? 'DEVEDORA');
    $categoria = trim($_POST['categoria'] ?? '');
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    if (empty($nome)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Nome é obrigatório');
    }
    
    $sql_update = "UPDATE planos_contas SET nome = ?, descricao = ?, tipo = ?, natureza = ?, categoria = ?, data_atualizacao = NOW() WHERE id = ?";
    
    $stmt_update = $conexao->prepare($sql_update);
    $stmt_update->bind_param("sssssi", $nome, $descricao, $tipo, $natureza, $categoria, $id);
    
    if ($stmt_update->execute()) {
        $stmt_update->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Plano de conta atualizado com sucesso');
    } else {
        $erro = $stmt_update->error;
        $stmt_update->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao atualizar: ' . $erro);
    }
}

// ========== DELETAR PLANO DE CONTA ==========
if ($acao === 'deletar' && $metodo === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    // Soft delete
    $sql_delete = "UPDATE planos_contas SET ativo = 0, data_atualizacao = NOW() WHERE id = ?";
    
    $stmt_delete = $conexao->prepare($sql_delete);
    $stmt_delete->bind_param("i", $id);
    
    if ($stmt_delete->execute()) {
        $stmt_delete->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Plano de conta deletado com sucesso');
    } else {
        $erro = $stmt_delete->error;
        $stmt_delete->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao deletar: ' . $erro);
    }
}

// ========== LISTAR CATEGORIAS ==========
if ($acao === 'categorias' && $metodo === 'GET') {
    $stmt = $conexao->prepare("SELECT DISTINCT categoria FROM planos_contas WHERE ativo = 1 AND categoria IS NOT NULL ORDER BY categoria");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Categorias carregadas', $categorias);
}

fechar_conexao($conexao);
retornar_json(false, 'Ação inválida');
?>
