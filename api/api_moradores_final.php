<?php
/**
 * API PARA CRUD DE MORADORES - VERSÃO FINAL CORRIGIDA
 * 
 * Correções:
 * 1. Tratamento robusto de erros
 * 2. Sempre retorna JSON válido
 * 3. Prepared statements em todas as queries
 * 4. Validação completa de entrada
 * 5. Logging de erros
 */

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

// Limpar buffer e definir headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array(
            'sucesso' => $sucesso,
            'mensagem' => $mensagem
        );
        if ($dados !== null) {
            $resposta['dados'] = $dados;
        }
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // Verificar autenticação
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    $conexao = conectar_banco();
    
    if (!$conexao) {
        throw new Exception("Erro ao conectar ao banco de dados");
    }
    
    // Para operações de escrita, verificar permissão de admin
    if ($metodo !== 'GET') {
        verificarPermissao('admin');
    }
    
    // ========== LISTAR MORADORES ==========
    if ($metodo === 'GET') {
        // Obter filtros de busca
        $filtro_unidade = isset($_GET['unidade']) ? trim($_GET['unidade']) : '';
        $filtro_nome = isset($_GET['nome']) ? trim($_GET['nome']) : '';
        $filtro_email = isset($_GET['email']) ? trim($_GET['email']) : '';
        $filtro_cpf = isset($_GET['cpf']) ? trim($_GET['cpf']) : '';
        
        // Construir query com prepared statement
        $sql = "SELECT id, nome, cpf, unidade, email, telefone, celular, ativo, 
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro 
                FROM moradores WHERE tenant_id = $tenant_id AND 1=1";
        
        $tipos_param = "";
        $params = array();
        
        // Aplicar filtros com prepared statements
        if ($filtro_unidade) {
            $sql .= " AND unidade = ?";
            $tipos_param .= "s";
            $params[] = $filtro_unidade;
        }
        
        if ($filtro_nome) {
            $sql .= " AND nome LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $filtro_nome . "%";
        }
        
        if ($filtro_email) {
            $sql .= " AND email LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $filtro_email . "%";
        }
        
        if ($filtro_cpf) {
            // Remover pontuação do CPF para busca
            $cpf_limpo = preg_replace('/[^0-9]/', '', $filtro_cpf);
            $sql .= " AND REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $cpf_limpo . "%";
        }
        
        $sql .= " ORDER BY nome ASC";
        
        // Preparar e executar statement
        $stmt = $conexao->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        
        // Bind parameters se houver
        if (count($params) > 0) {
            $stmt->bind_param($tipos_param, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao executar query: " . $stmt->error);
        }
        
        $resultado = $stmt->get_result();
        $moradores = array();
        
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $moradores[] = $row;
            }
        }
        
        $stmt->close();
        
        retornar_json(true, "Moradores listados com sucesso", $moradores);
    }
    
    // ========== CRIAR MORADOR ==========
    if ($metodo === 'POST') {
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $nome = sanitizar($conexao, $dados['nome'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $unidade = sanitizar($conexao, $dados['unidade'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $senha = $dados['senha'] ?? '';
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        
        // Validações
        if (empty($nome) || empty($cpf) || empty($unidade) || empty($email) || empty($senha)) {
            retornar_json(false, "Todos os campos obrigatórios devem ser preenchidos");
        }
        
        // Verificar se CPF já existe
        $stmt = $conexao->prepare("SELECT id FROM moradores WHERE tenant_id = $tenant_id AND cpf = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("s", $cpf);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            retornar_json(false, "CPF já cadastrado no sistema");
        }
        $stmt->close();
        
        // Criptografar senha
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        
        // Inserir morador
        $stmt = $conexao->prepare("INSERT INTO moradores (nome, cpf, unidade, email, senha, telefone, celular) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Erro ao preparar insert: " . $conexao->error);
        }
        $stmt->bind_param("sssssss", $nome, $cpf, $unidade, $email, $senha_hash, $telefone, $celular);
        
        if ($stmt->execute()) {
            $id_inserido = $conexao->insert_id;
            registrar_log('MORADOR_CRIADO', "Morador criado: $nome (ID: $id_inserido)", $nome);
            retornar_json(true, "Morador cadastrado com sucesso", array('id' => $id_inserido));
        } else {
            throw new Exception("Erro ao cadastrar morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== ATUALIZAR MORADOR ==========
    if ($metodo === 'PUT') {
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        $nome = sanitizar($conexao, $dados['nome'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $unidade = sanitizar($conexao, $dados['unidade'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        
        // Validações
        if ($id <= 0 || empty($nome) || empty($cpf) || empty($unidade) || empty($email)) {
            retornar_json(false, "Dados inválidos para atualização");
        }
        
        // Verificar se CPF já existe em outro morador
        $stmt = $conexao->prepare("SELECT id FROM moradores WHERE tenant_id = $tenant_id AND cpf = ? AND id != ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("si", $cpf, $id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            retornar_json(false, "CPF já cadastrado para outro morador");
        }
        $stmt->close();
        
        // Verificar se a senha foi enviada para atualização
        if (isset($dados['senha']) && !empty($dados['senha'])) {
            $senha = $dados['senha'];
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            
            // Atualizar morador com senha
            $stmt = $conexao->prepare("UPDATE moradores SET nome=?, cpf=?, unidade=?, email=?, telefone=?, celular=?, senha=? WHERE tenant_id = $tenant_id AND id=?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("sssssssi", $nome, $cpf, $unidade, $email, $telefone, $celular, $senha_hash, $id);
        } else {
            // Atualizar morador sem senha
            $stmt = $conexao->prepare("UPDATE moradores SET nome=?, cpf=?, unidade=?, email=?, telefone=?, celular=? WHERE tenant_id = $tenant_id AND id=?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("ssssssi", $nome, $cpf, $unidade, $email, $telefone, $celular, $id);
        }
        
        if ($stmt->execute()) {
            registrar_log('MORADOR_ATUALIZADO', "Morador atualizado: $nome (ID: $id)", $nome);
            retornar_json(true, "Morador atualizado com sucesso");
        } else {
            throw new Exception("Erro ao atualizar morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== EXCLUIR MORADOR ==========
    if ($metodo === 'DELETE') {
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        // Buscar nome do morador antes de excluir
        $stmt = $conexao->prepare("SELECT nome FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $morador = $resultado->fetch_assoc();
        $nome_morador = $morador['nome'] ?? 'Desconhecido';
        $stmt->close();
        
        // Excluir morador (veículos serão excluídos automaticamente por CASCADE)
        $stmt = $conexao->prepare("DELETE FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar delete: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            registrar_log('MORADOR_EXCLUIDO', "Morador excluído: $nome_morador (ID: $id)", $nome_morador);
            retornar_json(true, "Morador excluído com sucesso");
        } else {
            throw new Exception("Erro ao excluir morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // Método não suportado
    retornar_json(false, "Método não suportado");
    
} catch (Exception $e) {
    error_log('Erro em api_moradores.php: ' . $e->getMessage());
    registrar_log('MORADOR_ERRO', "Erro: " . $e->getMessage(), 'Sistema');
    
    http_response_code(500);
    retornar_json(false, "Erro ao processar requisição: " . $e->getMessage());
    
} finally {
    if (isset($conexao)) {
        fechar_conexao($conexao);
    }
}
?>
