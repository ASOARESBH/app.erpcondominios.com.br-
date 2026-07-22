<?php
// =====================================================
// API DE FORNECEDORES - VERSÃO DEFINITIVAMENTE CORRIGIDA
// =====================================================
// ERRO CORRIGIDO: bind_param com "sssissss" (8 tipos para 8 variáveis)
// =====================================================

require_once 'config.php';
require_once 'auth_helper.php';

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

// ========== LISTAR FORNECEDORES ==========
if ($acao === 'listar' && $metodo === 'GET') {
    try {
        $conexao = conectar_banco();
        
        $sql = "SELECT * FROM v_fornecedores_completo WHERE ativo=1 ORDER BY nome_estabelecimento";
        $result = $conexao->query($sql);
        
        if (!$result) {
            throw new Exception('Erro ao listar fornecedores: ' . $conexao->error);
        }
        
        $fornecedores = [];
        while ($row = $result->fetch_assoc()) {
            $fornecedores[] = $row;
        }
        
        fechar_conexao($conexao);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Fornecedores carregados',
            'dados' => $fornecedores
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== CADASTRAR FORNECEDOR ==========
if ($acao === 'cadastrar' && $metodo === 'POST') {
    try {
        $conexao = conectar_banco();
        
        // Obter dados do formulário
        $cpf_cnpj = trim($_POST['cpf_cnpj'] ?? '');
        $nome = trim($_POST['nome_estabelecimento'] ?? '');
        $ramo = intval($_POST['ramo_atividade_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $nome_responsavel = trim($_POST['nome_responsavel'] ?? '');
        
        // Validações
        if (empty($cpf_cnpj)) {
            throw new Exception('CPF/CNPJ é obrigatório');
        }
        
        if (empty($nome)) {
            throw new Exception('Nome do estabelecimento é obrigatório');
        }
        
        if ($ramo <= 0) {
            throw new Exception('Ramo de atividade é obrigatório');
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail válido é obrigatório');
        }
        
        if (strlen($senha) < 6) {
            throw new Exception('Senha deve ter no mínimo 6 caracteres');
        }
        
        // Verificar se CPF/CNPJ já existe
        $sql_check = "SELECT id FROM fornecedores WHERE cpf_cnpj = ?";
        $stmt_check = $conexao->prepare($sql_check);
        
        if (!$stmt_check) {
            throw new Exception('Erro ao preparar consulta: ' . $conexao->error);
        }
        
        $stmt_check->bind_param("s", $cpf_cnpj);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $stmt_check->close();
            fechar_conexao($conexao);
            throw new Exception('CPF/CNPJ já cadastrado no sistema');
        }
        
        $stmt_check->close();
        
        // Verificar se e-mail já existe
        $sql_check_email = "SELECT id FROM fornecedores WHERE email = ?";
        $stmt_check_email = $conexao->prepare($sql_check_email);
        
        if (!$stmt_check_email) {
            throw new Exception('Erro ao preparar consulta: ' . $conexao->error);
        }
        
        $stmt_check_email->bind_param("s", $email);
        $stmt_check_email->execute();
        $result_check_email = $stmt_check_email->get_result();
        
        if ($result_check_email->num_rows > 0) {
            $stmt_check_email->close();
            fechar_conexao($conexao);
            throw new Exception('E-mail já cadastrado no sistema');
        }
        
        $stmt_check_email->close();
        
        // Hash da senha com bcrypt
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        
        // ✅ CORRIGIDO DEFINITIVAMENTE
        // INSERT com 8 parâmetros: cpf_cnpj, nome, nome_responsavel, ramo, email, senha, telefone, endereco
        $sql_insert = "INSERT INTO fornecedores 
                       (cpf_cnpj, nome_estabelecimento, nome_responsavel, ramo_atividade_id, email, senha, telefone, endereco, ativo, aprovado, data_cadastro) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())";
        
        $stmt_insert = $conexao->prepare($sql_insert);
        
        if (!$stmt_insert) {
            throw new Exception('Erro ao preparar insert: ' . $conexao->error);
        }
        
        // ✅ CORRETO: "sssissss" = 8 caracteres para 8 parâmetros
        // 1. cpf_cnpj (s = string)
        // 2. nome (s = string)
        // 3. nome_responsavel (s = string)
        // 4. ramo (i = integer)
        // 5. email (s = string)
        // 6. senha_hash (s = string)
        // 7. telefone (s = string)
        // 8. endereco (s = string)
        
        $tipo_bind = "sssissss";
        
        $resultado = $stmt_insert->bind_param(
            $tipo_bind,
            $cpf_cnpj,
            $nome,
            $nome_responsavel,
            $ramo,
            $email,
            $senha_hash,
            $telefone,
            $endereco
        );
        
        if (!$resultado) {
            throw new Exception('Erro ao vincular parâmetros: ' . $stmt_insert->error);
        }
        
        if (!$stmt_insert->execute()) {
            throw new Exception('Erro ao cadastrar: ' . $stmt_insert->error);
        }
        
        $novo_id = $stmt_insert->insert_id;
        $stmt_insert->close();
        fechar_conexao($conexao);
        
        // Registrar log
        registrar_log('FORNECEDOR_CADASTRO', 'Novo fornecedor cadastrado: ' . $nome . ' (' . $email . ')');
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Cadastro realizado com sucesso! Aguarde aprovação do administrador.',
            'dados' => ['id' => $novo_id]
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== ATUALIZAR FORNECEDOR ==========
if ($acao === 'atualizar' && $metodo === 'POST') {
    try {
        $conexao = conectar_banco();
        
        $id = intval($_POST['id'] ?? 0);
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido');
        }
        
        $sql_update = "UPDATE fornecedores SET telefone=?, endereco=?, email=?, data_atualizacao=NOW() WHERE id=?";
        $stmt_update = $conexao->prepare($sql_update);
        
        if (!$stmt_update) {
            throw new Exception('Erro ao preparar update: ' . $conexao->error);
        }
        
        $stmt_update->bind_param("sssi", $telefone, $endereco, $email, $id);
        
        if (!$stmt_update->execute()) {
            throw new Exception('Erro ao atualizar: ' . $stmt_update->error);
        }
        
        $stmt_update->close();
        fechar_conexao($conexao);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Fornecedor atualizado com sucesso'
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== BUSCAR FORNECEDOR ==========
if ($acao === 'buscar' && $metodo === 'GET') {
    try {
        $conexao = conectar_banco();
        
        $id = intval($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        $sql_buscar = "SELECT * FROM v_fornecedores_completo WHERE id=?";
        $stmt_buscar = $conexao->prepare($sql_buscar);
        
        if (!$stmt_buscar) {
            throw new Exception('Erro ao preparar consulta: ' . $conexao->error);
        }
        
        $stmt_buscar->bind_param("i", $id);
        $stmt_buscar->execute();
        $result_buscar = $stmt_buscar->get_result();
        
        if ($result_buscar->num_rows > 0) {
            $fornecedor = $result_buscar->fetch_assoc();
            $stmt_buscar->close();
            fechar_conexao($conexao);
            
            http_response_code(200);
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Fornecedor encontrado',
                'dados' => $fornecedor
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $stmt_buscar->close();
            fechar_conexao($conexao);
            throw new Exception('Fornecedor não encontrado');
        }
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== DELETAR FORNECEDOR ==========
if ($acao === 'deletar' && $metodo === 'POST') {
    try {
        $conexao = conectar_banco();
        
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        $sql_delete = "UPDATE fornecedores SET ativo=0, data_atualizacao=NOW() WHERE id=?";
        $stmt_delete = $conexao->prepare($sql_delete);
        
        if (!$stmt_delete) {
            throw new Exception('Erro ao preparar delete: ' . $conexao->error);
        }
        
        $stmt_delete->bind_param("i", $id);
        
        if (!$stmt_delete->execute()) {
            throw new Exception('Erro ao deletar: ' . $stmt_delete->error);
        }
        
        $stmt_delete->close();
        fechar_conexao($conexao);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Fornecedor deletado com sucesso'
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== APROVAR FORNECEDOR ==========
if ($acao === 'aprovar' && $metodo === 'POST') {
    try {
        $conexao = conectar_banco();
        
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        $sql_aprovar = "UPDATE fornecedores SET aprovado=1, data_atualizacao=NOW() WHERE id=?";
        $stmt_aprovar = $conexao->prepare($sql_aprovar);
        
        if (!$stmt_aprovar) {
            throw new Exception('Erro ao preparar aprovação: ' . $conexao->error);
        }
        
        $stmt_aprovar->bind_param("i", $id);
        
        if (!$stmt_aprovar->execute()) {
            throw new Exception('Erro ao aprovar: ' . $stmt_aprovar->error);
        }
        
        $stmt_aprovar->close();
        fechar_conexao($conexao);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Fornecedor aprovado com sucesso'
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        fechar_conexao($conexao ?? null);
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== AÇÃO INVÁLIDA ==========
fechar_conexao($conexao ?? null);
http_response_code(400);
echo json_encode([
    'sucesso' => false,
    'mensagem' => 'Ação inválida ou método não permitido'
], JSON_UNESCAPED_UNICODE);
exit;
?>
