<?php
// =====================================================
// VALIDAÇÃO DE LOGIN COM DEBUG COMPLETO
// =====================================================

// Incluir sistema de debug
require_once 'debug_system.php';

DebugSystem::info('Iniciando validação de login com debug');

// Configurações de sessão ANTES de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas

// Iniciar sessão ANTES de incluir config.php
session_start();
DebugSystem::info('Sessão iniciada', ['session_id' => session_id()]);

// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://app.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

DebugSystem::info('Headers configurados');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    DebugSystem::info('Requisição OPTIONS (CORS preflight)');
    http_response_code(200);
    exit;
}

// Registrar dados da requisição
DebugSystem::logRequest();

// Incluir arquivo de configuração
DebugSystem::info('Incluindo config.php');
require_once 'config.php';
DebugSystem::success('config.php incluído com sucesso');

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    DebugSystem::warning('Método não permitido', ['method' => $_SERVER['REQUEST_METHOD']]);
    retornar_json(false, 'Método não permitido');
}

DebugSystem::info('Método POST confirmado');

// Receber dados do formulário
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$senha = isset($_POST['senha']) ? trim($_POST['senha']) : '';

DebugSystem::info('Dados recebidos', [
    'email' => $email,
    'senha' => '***HIDDEN***',
    'email_length' => strlen($email),
    'senha_length' => strlen($senha)
]);

// Validar campos obrigatórios
if (empty($email) || empty($senha)) {
    DebugSystem::warning('Campos obrigatórios vazios');
    retornar_json(false, 'Preencha todos os campos!');
}

// Validar formato de e-mail
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    DebugSystem::warning('E-mail inválido', ['email' => $email]);
    retornar_json(false, 'E-mail inválido!');
}

DebugSystem::success('Validações iniciais OK');

try {
    // Conectar ao banco de dados
    DebugSystem::info('Tentando conectar ao banco de dados');
    $conexao = conectar_banco();
    DebugSystem::success('Conexão com banco estabelecida');
    
    // Preparar consulta para buscar usuário
    $query = "SELECT id, nome, email, senha, funcao, departamento, permissao, ativo FROM usuarios WHERE email = ? LIMIT 1";
    DebugSystem::logQuery($query, ['email' => $email]);
    
    $stmt = $conexao->prepare($query);
    $stmt->bind_param("s", $email);
    
    DebugSystem::info('Executando query');
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    DebugSystem::info('Query executada', [
        'num_rows' => $resultado->num_rows
    ]);
    
    // Verificar se o usuário existe
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        
        DebugSystem::warning('Usuário não encontrado', ['email' => $email]);
        
        // Registrar tentativa de login falha
        registrar_log('login_falha', "Tentativa de login com e-mail não cadastrado: {$email}");
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    // Obter dados do usuário
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    
    DebugSystem::success('Usuário encontrado', [
        'id' => $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'funcao' => $usuario['funcao'],
        'ativo' => $usuario['ativo']
    ]);
    
    // Verificar se o usuário está ativo
    if ($usuario['ativo'] != 1) {
        fechar_conexao($conexao);
        
        DebugSystem::warning('Usuário inativo', ['email' => $email]);
        
        // Registrar tentativa de login com usuário inativo
        registrar_log('login_falha', "Tentativa de login com usuário inativo: {$email}", $usuario['nome']);
        
        retornar_json(false, 'Usuário inativo. Entre em contato com o administrador.');
    }
    
    DebugSystem::info('Verificando senha');
    
    // Verificar senha
    // A senha no banco está com hash bcrypt ($2y$10$...)
    $senha_valida = password_verify($senha, $usuario['senha']);
    
    DebugSystem::info('Resultado verificação senha', [
        'senha_valida' => $senha_valida,
        'hash_type' => substr($usuario['senha'], 0, 7)
    ]);
    
    if (!$senha_valida) {
        fechar_conexao($conexao);
        
        DebugSystem::warning('Senha incorreta', ['email' => $email]);
        
        // Registrar tentativa de login com senha incorreta
        registrar_log('login_falha', "Tentativa de login com senha incorreta: {$email}", $usuario['nome']);
        
        retornar_json(false, 'E-mail ou senha incorretos!');
    }
    
    DebugSystem::success('Senha válida!');
    
    // Login bem-sucedido - criar sessão
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_funcao'] = $usuario['funcao'];
    $_SESSION['usuario_departamento'] = $usuario['departamento'];
    $_SESSION['usuario_permissao'] = $usuario['permissao'];
    $_SESSION['usuario_logado'] = true;
    $_SESSION['login_timestamp'] = time();
    
    DebugSystem::success('Sessão criada', [
        'usuario_id' => $usuario['id'],
        'usuario_nome' => $usuario['nome'],
        'session_id' => session_id()
    ]);
    
    // Regenerar ID da sessão para segurança
    session_regenerate_id(true);
    DebugSystem::info('Session ID regenerado', ['new_session_id' => session_id()]);
    
    // Atualizar último acesso do usuário
    $stmt_update = $conexao->prepare("UPDATE usuarios SET data_atualizacao = NOW() WHERE id = ?");
    $stmt_update->bind_param("i", $usuario['id']);
    $stmt_update->execute();
    $stmt_update->close();
    
    DebugSystem::success('Último acesso atualizado');
    
    fechar_conexao($conexao);
    
    // Registrar login bem-sucedido
    registrar_log('login_sucesso', "Login realizado com sucesso: {$email}", $usuario['nome']);
    
    DebugSystem::success('LOGIN REALIZADO COM SUCESSO!');
    
    // Preparar resposta
    $resposta = array(
        'nome' => $usuario['nome'],
        'permissao' => $usuario['permissao']
    );
    
    // Adicionar logs de debug na resposta
    $resposta['debug_logs'] = DebugSystem::getLogs();
    
    DebugSystem::logResponse($resposta);
    
    // Retornar sucesso
    retornar_json(true, 'Login realizado com sucesso!', $resposta);
    
} catch (Exception $e) {
    DebugSystem::error('Erro no processo de login', $e);
    
    error_log("Erro no login: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("POST data: " . print_r($_POST, true));
    
    retornar_json(false, 'Erro ao processar login. Tente novamente.', [
        'erro_tecnico' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
        'debug_logs' => DebugSystem::getLogs()
    ]);
}
?>
