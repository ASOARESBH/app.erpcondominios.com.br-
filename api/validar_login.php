<?php
// =====================================================
// SISTEMA DE CONTROLE DE ACESSO - VALIDAÇÃO DE LOGIN
// =====================================================

// Configurações de sessão ANTES de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas

// Iniciar sessão ANTES de incluir config.php
session_start();

// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://app.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir arquivo de configuração
require_once 'config.php';

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    retornar_json(false, 'Método não permitido');
}

// Receber dados do formulário (suporta POST e JSON)
$input_data = array();

// Verificar se é JSON
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($content_type, 'application/json') !== false) {
    // Receber JSON
    $json_input = file_get_contents('php://input');
    $input_data = json_decode($json_input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        retornar_json(false, 'JSON inválido');
    }
} else {
    // Receber POST tradicional
    $input_data = $_POST;
}

$email = isset($input_data['email']) ? trim($input_data['email']) : '';
$senha = isset($input_data['senha']) ? trim($input_data['senha']) : '';

// Validar campos obrigatórios
if (empty($email) || empty($senha)) {
    retornar_json(false, 'Preencha todos os campos!');
}

// Validar formato de e-mail
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    retornar_json(false, 'E-mail inválido!');
}

try {
    // Conectar ao banco de dados
    $conexao = conectar_banco();
    
    // Preparar consulta para buscar usuário
    $stmt = $conexao->prepare("SELECT id, nome, email, senha, funcao, departamento, permissao, ativo, COALESCE(sessao_inativa,0) AS sessao_inativa FROM usuarios WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    // Verificar se o usuário existe
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        
        // Registrar tentativa de login falha
        registrar_log('login_falha', "Tentativa de login com e-mail não cadastrado: {$email}");
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    // Obter dados do usuário
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    
    // Verificar se o usuário está ativo
    if ($usuario['ativo'] != 1) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com usuário inativo
        registrar_log('login_falha', "Tentativa de login com usuário inativo: {$email}", $usuario['nome']);
        
        retornar_json(false, 'Usuário inativo. Entre em contato com o administrador.');
    }
    
    // Verificar senha
    // A senha no banco está com hash bcrypt ($2y$10$...)
    $senha_valida = password_verify($senha, $usuario['senha']);
    
    if (!$senha_valida) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com senha incorreta
        registrar_log('login_falha', "Tentativa de login com senha incorreta: {$email}", $usuario['nome']);
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    // Login bem-sucedido - criar sessão
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_funcao'] = $usuario['funcao'];
    $_SESSION['usuario_departamento'] = $usuario['departamento'];
    $_SESSION['usuario_permissao'] = $usuario['permissao'];
    $_SESSION['usuario_logado']  = true;
    $_SESSION['login_timestamp']  = time();
    $_SESSION['sessao_inativa']   = !empty($usuario['sessao_inativa']) ? 1 : 0;
    
    // Regenerar ID da sessão para segurança
    session_regenerate_id(true);
    
    // Atualizar último acesso do usuário
    $stmt_update = $conexao->prepare("UPDATE usuarios SET data_atualizacao = NOW() WHERE id = ?");
    $stmt_update->bind_param("i", $usuario['id']);
    $stmt_update->execute();
    $stmt_update->close();
    
    fechar_conexao($conexao);
    
    // Registrar login bem-sucedido
    registrar_log('login_sucesso', "Login realizado com sucesso: {$email}", $usuario['nome']);
    
    // Retornar sucesso
    retornar_json(true, 'Login realizado com sucesso!', array(
        'nome' => $usuario['nome'],
        'permissao' => $usuario['permissao']
    ));
    
} catch (Exception $e) {
    error_log("Erro no login: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("POST data: " . print_r($_POST, true));
    
    retornar_json(false, 'Erro ao processar login. Tente novamente.', [
        'erro_tecnico' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>