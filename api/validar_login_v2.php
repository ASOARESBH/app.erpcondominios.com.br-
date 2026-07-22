<?php
// =====================================================
// SISTEMA DE CONTROLE DE ACESSO - VALIDAÇÃO DE LOGIN V2
// Com logs detalhados para diagnóstico
// =====================================================

// Configurações de erro
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("===== INÍCIO DO LOGIN =====");

// Configurações de sessão ANTES de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas

// Iniciar sessão ANTES de incluir config.php
session_start();
error_log("Sessão iniciada: " . session_id());

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
error_log("Incluindo config.php...");
require_once 'config.php';
error_log("config.php incluído com sucesso");

// Verificar se constantes foram definidas
error_log("DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NÃO DEFINIDO'));
error_log("DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'NÃO DEFINIDO'));
error_log("DB_USER: " . (defined('DB_USER') ? DB_USER : 'NÃO DEFINIDO'));

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Método não permitido: " . $_SERVER['REQUEST_METHOD']);
    retornar_json(false, 'Método não permitido');
}

// Receber dados do formulário
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$senha = isset($_POST['senha']) ? trim($_POST['senha']) : '';

error_log("Email recebido: " . $email);
error_log("Senha recebida: " . (empty($senha) ? 'VAZIA' : 'PREENCHIDA'));

// Validar campos obrigatórios
if (empty($email) || empty($senha)) {
    error_log("ERRO: Campos vazios");
    retornar_json(false, 'Preencha todos os campos!');
}

// Validar formato de e-mail
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_log("ERRO: E-mail inválido: " . $email);
    retornar_json(false, 'E-mail inválido!');
}

try {
    error_log("Tentando conectar ao banco...");
    
    // Conectar ao banco de dados
    $conexao = conectar_banco();
    
    if (!$conexao) {
        error_log("ERRO: conectar_banco() retornou null");
        throw new Exception("Falha ao conectar ao banco de dados");
    }
    
    error_log("Conexão estabelecida: " . get_class($conexao));
    error_log("Host info: " . $conexao->host_info);
    
    // Preparar consulta para buscar usuário
    error_log("Preparando query...");
    $stmt = $conexao->prepare("SELECT id, nome, email, senha, funcao, departamento, permissao, ativo FROM usuarios WHERE email = ? LIMIT 1");
    
    if (!$stmt) {
        error_log("ERRO ao preparar statement: " . $conexao->error);
        throw new Exception("Erro ao preparar consulta: " . $conexao->error);
    }
    
    error_log("Query preparada com sucesso");
    
    $stmt->bind_param("s", $email);
    error_log("Parâmetros vinculados");
    
    $stmt->execute();
    error_log("Query executada");
    
    $resultado = $stmt->get_result();
    error_log("Resultado obtido. Linhas: " . $resultado->num_rows);
    
    // Verificar se o usuário existe
    if ($resultado->num_rows === 0) {
        error_log("AVISO: Usuário não encontrado: " . $email);
        $stmt->close();
        fechar_conexao($conexao);
        
        // Registrar tentativa de login falha
        registrar_log('login_falha', "Tentativa de login com e-mail não cadastrado: {$email}");
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    // Obter dados do usuário
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    
    error_log("Usuário encontrado: " . $usuario['nome'] . " (ID: " . $usuario['id'] . ")");
    error_log("Usuário ativo: " . $usuario['ativo']);
    
    // Verificar se o usuário está ativo
    if ($usuario['ativo'] != 1) {
        error_log("AVISO: Usuário inativo: " . $email);
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com usuário inativo
        registrar_log('login_falha', "Tentativa de login com usuário inativo: {$email}", $usuario['nome']);
        
        retornar_json(false, 'Usuário inativo. Entre em contato com o administrador.');
    }
    
    // Verificar senha
    error_log("Verificando senha...");
    error_log("Hash no banco: " . substr($usuario['senha'], 0, 20) . "...");
    
    $senha_valida = password_verify($senha, $usuario['senha']);
    error_log("Senha válida: " . ($senha_valida ? 'SIM' : 'NÃO'));
    
    if (!$senha_valida) {
        error_log("AVISO: Senha incorreta para: " . $email);
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com senha incorreta
        registrar_log('login_falha', "Tentativa de login com senha incorreta: {$email}", $usuario['nome']);
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    // Login bem-sucedido - criar sessão
    error_log("LOGIN BEM-SUCEDIDO! Criando sessão...");
    
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_funcao'] = $usuario['funcao'];
    $_SESSION['usuario_departamento'] = $usuario['departamento'];
    $_SESSION['usuario_permissao'] = $usuario['permissao'];
    $_SESSION['usuario_logado'] = true;
    $_SESSION['login_timestamp'] = time();
    
    error_log("Sessão criada. ID: " . session_id());
    error_log("Usuario ID: " . $_SESSION['usuario_id']);
    error_log("Usuario Nome: " . $_SESSION['usuario_nome']);
    
    // Regenerar ID da sessão para segurança
    session_regenerate_id(true);
    error_log("Session ID regenerado: " . session_id());
    
    // Atualizar último acesso do usuário
    $stmt_update = $conexao->prepare("UPDATE usuarios SET data_atualizacao = NOW() WHERE id = ?");
    $stmt_update->bind_param("i", $usuario['id']);
    $stmt_update->execute();
    $stmt_update->close();
    
    error_log("Último acesso atualizado");
    
    fechar_conexao($conexao);
    error_log("Conexão fechada");
    
    // Registrar login bem-sucedido
    registrar_log('login_sucesso', "Login realizado com sucesso: {$email}", $usuario['nome']);
    
    error_log("===== LOGIN CONCLUÍDO COM SUCESSO =====");
    
    // Retornar sucesso
    retornar_json(true, 'Login realizado com sucesso!', array(
        'nome' => $usuario['nome'],
        'permissao' => $usuario['permissao']
    ));
    
} catch (Exception $e) {
    error_log("===== ERRO NO LOGIN =====");
    error_log("Exception: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("POST data: " . print_r($_POST, true));
    error_log("========================");
    
    retornar_json(false, 'Erro ao processar login. Tente novamente.', [
        'erro_tecnico' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
