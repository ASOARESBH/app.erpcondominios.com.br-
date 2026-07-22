<?php
// =====================================================
// API PARA GERENCIAR DADOS DO MORADOR LOGADO
// =====================================================

session_start();

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

// Limpar buffer e definir headers
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

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Verificar se o morador está logado
if (!isset($_SESSION['morador_logado']) || $_SESSION['morador_logado'] !== true) {
    retornar_json(false, "Sessão inválida. Faça login novamente.");
}

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$morador_id = $_SESSION['morador_id'];

// ========== OBTER DADOS DO MORADOR ==========
if ($metodo === 'GET') {
    $stmt = $conexao->prepare("SELECT id, nome, cpf, unidade, email, telefone, celular, ativo, 
                               DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro 
                               FROM moradores WHERE id = ?");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $morador = $resultado->fetch_assoc();
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Dados obtidos com sucesso", $morador);
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Morador não encontrado");
    }
}

// ========== ATUALIZAR DADOS DO MORADOR ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $email = sanitizar($conexao, $dados['email'] ?? '');
    $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
    $celular = sanitizar($conexao, $dados['celular'] ?? '');
    $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
    
    // Validações
    if (empty($email)) {
        retornar_json(false, "O e-mail é obrigatório");
    }
    
    if (!empty($cpf)) {
        // Verificar se o CPF já existe em outro morador
        $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
        $stmt = $conexao->prepare("SELECT id FROM moradores 
                                   WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? 
                                   AND id != ?");
        $stmt->bind_param("si", $cpf_limpo, $morador_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "CPF já cadastrado para outro morador");
        }
        $stmt->close();
        
        // Atualizar com CPF
        $stmt = $conexao->prepare("UPDATE moradores SET email=?, telefone=?, celular=?, cpf=? WHERE id=?");
        $stmt->bind_param("ssssi", $email, $telefone, $celular, $cpf, $morador_id);
    } else {
        // Atualizar sem CPF
        $stmt = $conexao->prepare("UPDATE moradores SET email=?, telefone=?, celular=? WHERE id=?");
        $stmt->bind_param("sssi", $email, $telefone, $celular, $morador_id);
    }
    
    if ($stmt->execute()) {
        // Atualizar dados da sessão
        $_SESSION['morador_email'] = $email;
        $_SESSION['morador_telefone'] = $telefone;
        $_SESSION['morador_celular'] = $celular;
        if (!empty($cpf)) {
            $_SESSION['morador_cpf'] = $cpf;
        }
        
        registrar_log('MORADOR_DADOS_ATUALIZADOS', "Morador atualizou seus dados: {$_SESSION['morador_nome']}", $_SESSION['morador_nome']);
        
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Dados atualizados com sucesso!");
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Erro ao atualizar dados: " . $stmt->error);
    }
}

fechar_conexao($conexao);
retornar_json(false, "Método não permitido");

