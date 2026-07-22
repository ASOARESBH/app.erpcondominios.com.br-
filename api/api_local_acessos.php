<?php
/**
 * =====================================================
 * API DE GERENCIAMENTO DE LOCAL DE ACESSOS
 * =====================================================
 * 
 * Endpoints:
 * - GET  /api_local_acessos.php?action=listar           -> Listar todos os locais
 * - GET  /api_local_acessos.php?action=buscar&id=X      -> Buscar local por ID
 * - POST /api_local_acessos.php?action=criar            -> Criar novo local
 * - POST /api_local_acessos.php?action=atualizar        -> Atualizar local
 * - POST /api_local_acessos.php?action=deletar          -> Deletar local
 * - POST /api_local_acessos.php?action=atualizar_status -> Atualizar status
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';

function retornar_json($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function registrar_log_local_acesso($local_acesso_id, $acao, $dados_anteriores, $dados_novos, $usuario_id) {
    global $conexao;
    try {
        $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        $stmt = $conexao->prepare("
            INSERT INTO local_acessos_log (local_acesso_id, acao, dados_anteriores, dados_novos, usuario_id, ip_usuario)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar statement de log: " . $conexao->error);
            return false;
        }
        $dados_ant_json = json_encode($dados_anteriores, JSON_UNESCAPED_UNICODE);
        $dados_nov_json = json_encode($dados_novos, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param("isssii", $local_acesso_id, $acao, $dados_ant_json, $dados_nov_json, $usuario_id, $ip_usuario);
        if (!$stmt->execute()) {
            error_log("[API LOCAL ACESSOS] Erro ao executar log: " . $stmt->error);
            return false;
        }
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao registrar log: " . $e->getMessage());
        return false;
    }
}

$usuario = verificarAutenticacao(true, 'admin');
$usuario_id = $usuario['id'];
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$conexao = conectar_banco();

// LISTAR TODOS OS LOCAIS
if ($action === 'listar' && $metodo === 'GET') {
    try {
        $status = $_GET['status'] ?? null;
        $query = "SELECT * FROM local_acessos";
        if ($status) {
            $query .= " WHERE situacao = '$status'";
        }
        $query .= " ORDER BY nome ASC";
        
        $resultado = $conexao->query($query);
        if (!$resultado) {
            error_log("[API LOCAL ACESSOS] Erro na query: " . $conexao->error);
            retornar_json(false, 'Erro ao listar locais de acesso');
        }
        
        $locais = [];
        while ($row = $resultado->fetch_assoc()) {
            $locais[] = $row;
        }
        
        retornar_json(true, 'Locais listados com sucesso', $locais);
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao listar: " . $e->getMessage());
        retornar_json(false, 'Erro ao listar locais de acesso');
    }
}

// BUSCAR LOCAL POR ID
if ($action === 'buscar' && $metodo === 'GET') {
    try {
        $id = $_GET['id'] ?? 0;
        if (!$id) {
            retornar_json(false, 'ID não fornecido');
        }
        
        $stmt = $conexao->prepare("SELECT * FROM local_acessos WHERE id = ?");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar query: " . $conexao->error);
            retornar_json(false, 'Erro ao buscar local');
        }
        
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $local = $resultado->fetch_assoc();
        $stmt->close();
        
        if ($local) {
            retornar_json(true, 'Local encontrado', $local);
        } else {
            retornar_json(false, 'Local não encontrado');
        }
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao buscar: " . $e->getMessage());
        retornar_json(false, 'Erro ao buscar local');
    }
}

// CRIAR NOVO LOCAL
if ($action === 'criar' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $nome = $input['nome'] ?? '';
        $descricao = $input['descricao'] ?? '';
        $observacao = $input['observacao'] ?? '';
        $situacao = $input['situacao'] ?? 'ativo';
        
        if (empty($nome)) {
            retornar_json(false, 'Nome do local é obrigatório');
        }
        
        $stmt = $conexao->prepare("
            INSERT INTO local_acessos (nome, descricao, observacao, situacao, usuario_criacao_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar insert: " . $conexao->error);
            retornar_json(false, 'Erro ao criar local');
        }
        
        $stmt->bind_param("ssssi", $nome, $descricao, $observacao, $situacao, $usuario_id);
        if (!$stmt->execute()) {
            error_log("[API LOCAL ACESSOS] Erro ao executar insert: " . $stmt->error);
            retornar_json(false, 'Erro ao criar local');
        }
        
        $local_id = $stmt->insert_id;
        $stmt->close();
        
        $dados_novos = ['nome' => $nome, 'descricao' => $descricao, 'observacao' => $observacao, 'situacao' => $situacao];
        registrar_log_local_acesso($local_id, 'criar', null, $dados_novos, $usuario_id);
        
        error_log("[API LOCAL ACESSOS] Local criado: ID $local_id");
        retornar_json(true, 'Local criado com sucesso', ['local_id' => $local_id]);
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao criar: " . $e->getMessage());
        retornar_json(false, 'Erro ao criar local');
    }
}

// ATUALIZAR LOCAL
if ($action === 'atualizar' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $nome = $input['nome'] ?? '';
        $descricao = $input['descricao'] ?? '';
        $observacao = $input['observacao'] ?? '';
        $situacao = $input['situacao'] ?? 'ativo';
        
        if (!$id || empty($nome)) {
            retornar_json(false, 'ID e Nome são obrigatórios');
        }
        
        $stmt_anterior = $conexao->prepare("SELECT * FROM local_acessos WHERE id = ?");
        $stmt_anterior->bind_param("i", $id);
        $stmt_anterior->execute();
        $resultado_anterior = $stmt_anterior->get_result();
        $dados_anteriores = $resultado_anterior->fetch_assoc();
        $stmt_anterior->close();
        
        if (!$dados_anteriores) {
            retornar_json(false, 'Local não encontrado');
        }
        
        $stmt = $conexao->prepare("
            UPDATE local_acessos
            SET nome = ?, descricao = ?, observacao = ?, situacao = ?, usuario_atualizacao_id = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar update: " . $conexao->error);
            retornar_json(false, 'Erro ao atualizar local');
        }
        
        $stmt->bind_param("ssssi", $nome, $descricao, $observacao, $situacao, $usuario_id, $id);
        if (!$stmt->execute()) {
            error_log("[API LOCAL ACESSOS] Erro ao executar update: " . $stmt->error);
            retornar_json(false, 'Erro ao atualizar local');
        }
        $stmt->close();
        
        $dados_novos = ['nome' => $nome, 'descricao' => $descricao, 'observacao' => $observacao, 'situacao' => $situacao];
        registrar_log_local_acesso($id, 'atualizar', $dados_anteriores, $dados_novos, $usuario_id);
        
        error_log("[API LOCAL ACESSOS] Local atualizado: ID $id");
        retornar_json(true, 'Local atualizado com sucesso', ['local_id' => $id]);
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao atualizar: " . $e->getMessage());
        retornar_json(false, 'Erro ao atualizar local');
    }
}

// DELETAR LOCAL
if ($action === 'deletar' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        
        if (!$id) {
            retornar_json(false, 'ID não fornecido');
        }
        
        $stmt_anterior = $conexao->prepare("SELECT * FROM local_acessos WHERE id = ?");
        $stmt_anterior->bind_param("i", $id);
        $stmt_anterior->execute();
        $resultado_anterior = $stmt_anterior->get_result();
        $dados_anteriores = $resultado_anterior->fetch_assoc();
        $stmt_anterior->close();
        
        if (!$dados_anteriores) {
            retornar_json(false, 'Local não encontrado');
        }
        
        $stmt = $conexao->prepare("DELETE FROM local_acessos WHERE id = ?");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar delete: " . $conexao->error);
            retornar_json(false, 'Erro ao deletar local');
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            error_log("[API LOCAL ACESSOS] Erro ao executar delete: " . $stmt->error);
            retornar_json(false, 'Erro ao deletar local');
        }
        $stmt->close();
        
        registrar_log_local_acesso($id, 'deletar', $dados_anteriores, null, $usuario_id);
        
        error_log("[API LOCAL ACESSOS] Local deletado: ID $id");
        retornar_json(true, 'Local deletado com sucesso');
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao deletar: " . $e->getMessage());
        retornar_json(false, 'Erro ao deletar local');
    }
}

// ATUALIZAR STATUS
if ($action === 'atualizar_status' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $situacao = $input['situacao'] ?? '';
        
        if (!$id || !in_array($situacao, ['ativo', 'inativo'])) {
            retornar_json(false, 'Dados inválidos');
        }
        
        $stmt = $conexao->prepare("UPDATE local_acessos SET situacao = ?, usuario_atualizacao_id = ? WHERE id = ?");
        if (!$stmt) {
            error_log("[API LOCAL ACESSOS] Erro ao preparar update de status: " . $conexao->error);
            retornar_json(false, 'Erro ao atualizar status');
        }
        
        $stmt->bind_param("sii", $situacao, $usuario_id, $id);
        if (!$stmt->execute()) {
            error_log("[API LOCAL ACESSOS] Erro ao executar update de status: " . $stmt->error);
            retornar_json(false, 'Erro ao atualizar status');
        }
        $stmt->close();
        
        error_log("[API LOCAL ACESSOS] Status atualizado: ID $id -> $situacao");
        retornar_json(true, 'Status atualizado com sucesso');
    } catch (Exception $e) {
        error_log("[API LOCAL ACESSOS] Exceção ao atualizar status: " . $e->getMessage());
        retornar_json(false, 'Erro ao atualizar status');
    }
}

retornar_json(false, 'Ação não encontrada');
?>
