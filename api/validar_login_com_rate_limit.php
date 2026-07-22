<?php
/**
 * =====================================================
 * SISTEMA DE CONTROLE DE ACESSO - VALIDAÇÃO DE LOGIN COM RATE LIMITING
 * =====================================================
 * 
 * Versão melhorada com proteção contra força bruta
 * Inclui rate limiting, logging detalhado e tratamento de erros
 */

// Configurações de sessão ANTES de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas

// Iniciar sessão ANTES de incluir config.php
session_start();

// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir arquivos necessários
require_once 'config.php';
require_once 'rate_limiter.php';

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    retornar_json(false, 'Método não permitido');
}

// =====================================================
// INICIALIZAR RATE LIMITER
// =====================================================
$limiter = new RateLimiter();
$client_ip = getClientIP();
$rate_limit_key = "login:{$client_ip}";

// Verificar rate limit ANTES de processar
if (!$limiter->isAllowed($rate_limit_key, 5, 300)) {
    // 5 tentativas em 5 minutos
    retornarRateLimitExcedido($limiter, $rate_limit_key, 5);
}

// =====================================================
// RECEBER E VALIDAR DADOS
// =====================================================
$input_data = array();
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

// Validar campos obrigatórios
$usuario = isset($input_data['usuario']) ? trim($input_data['usuario']) : '';
$senha = isset($input_data['senha']) ? $input_data['senha'] : '';

if (empty($usuario) || empty($senha)) {
    error_log("Login falhou: Campos vazios - IP: {$client_ip}");
    retornar_json(false, 'Usuário e senha são obrigatórios');
}

// =====================================================
// CONECTAR AO BANCO DE DADOS
// =====================================================
$conexao = conectar_banco();

if (!$conexao) {
    error_log("Login falhou: Erro de conexão com banco de dados");
    retornar_json(false, 'Erro ao conectar ao banco de dados', null, 500);
}

// =====================================================
// BUSCAR USUÁRIO NO BANCO DE DADOS
// =====================================================
$sql = "SELECT id, nome, email, senha, permissao, funcao, departamento, ativo 
        FROM usuarios 
        WHERE (email = ? OR nome = ?) 
        LIMIT 1";

$stmt = $conexao->prepare($sql);

if (!$stmt) {
    error_log("Login falhou: Erro ao preparar query - " . $conexao->error);
    retornar_json(false, 'Erro ao processar login', null, 500);
}

$stmt->bind_param("ss", $usuario, $usuario);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    error_log("Login falhou: Usuário não encontrado - Usuario: {$usuario}, IP: {$client_ip}");
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(false, 'Usuário ou senha incorretos');
}

$usuario_db = $resultado->fetch_assoc();
$stmt->close();

// =====================================================
// VALIDAR USUÁRIO
// =====================================================

// Verificar se usuário está ativo
if ($usuario_db['ativo'] != 1) {
    error_log("Login falhou: Usuário inativo - Usuario: {$usuario}, IP: {$client_ip}");
    fechar_conexao($conexao);
    retornar_json(false, 'Usuário inativo. Contate o administrador.');
}

// =====================================================
// VALIDAR SENHA
// =====================================================
$senha_valida = false;

// Tentar diferentes métodos de validação de senha
if (password_verify($senha, $usuario_db['senha'])) {
    // Senha com hash bcrypt (recomendado)
    $senha_valida = true;
} elseif (md5($senha) === $usuario_db['senha']) {
    // Senha com MD5 (legado)
    $senha_valida = true;
    // Atualizar para bcrypt na próxima oportunidade
    $novo_hash = password_hash($senha, PASSWORD_BCRYPT);
    $sql_update = "UPDATE usuarios SET senha = ? WHERE id = ?";
    $stmt_update = $conexao->prepare($sql_update);
    $stmt_update->bind_param("si", $novo_hash, $usuario_db['id']);
    $stmt_update->execute();
    $stmt_update->close();
} elseif ($senha === $usuario_db['senha']) {
    // Senha em texto plano (MUITO inseguro - legado)
    $senha_valida = true;
    // Atualizar para bcrypt
    $novo_hash = password_hash($senha, PASSWORD_BCRYPT);
    $sql_update = "UPDATE usuarios SET senha = ? WHERE id = ?";
    $stmt_update = $conexao->prepare($sql_update);
    $stmt_update->bind_param("si", $novo_hash, $usuario_db['id']);
    $stmt_update->execute();
    $stmt_update->close();
}

if (!$senha_valida) {
    error_log("Login falhou: Senha incorreta - Usuario: {$usuario}, IP: {$client_ip}");
    fechar_conexao($conexao);
    retornar_json(false, 'Usuário ou senha incorretos');
}

// =====================================================
// LOGIN BEM-SUCEDIDO
// =====================================================

// Resetar rate limit após login bem-sucedido
$limiter->reset($rate_limit_key);

// Atualizar dados de sessão
$_SESSION['usuario_logado'] = true;
$_SESSION['usuario_id'] = $usuario_db['id'];
$_SESSION['usuario_nome'] = $usuario_db['nome'];
$_SESSION['usuario_email'] = $usuario_db['email'];
$_SESSION['usuario_funcao'] = $usuario_db['funcao'];
$_SESSION['usuario_departamento'] = $usuario_db['departamento'];
$_SESSION['usuario_permissao'] = $usuario_db['permissao'];
$_SESSION['login_timestamp'] = time();
$_SESSION['login_ip'] = $client_ip;
$_SESSION['login_user_agent'] = $_SERVER['HTTP_USER_AGENT'];

// Registrar log de login bem-sucedido
registrar_log($conexao, 'LOGIN_SUCESSO', "Usuário {$usuario_db['nome']} fez login com sucesso", $usuario_db['nome']);

fechar_conexao($conexao);

// Retornar sucesso
retornar_json(true, 'Login realizado com sucesso', [
    'usuario_id' => $usuario_db['id'],
    'usuario_nome' => $usuario_db['nome'],
    'usuario_email' => $usuario_db['email'],
    'usuario_permissao' => $usuario_db['permissao'],
    'usuario_funcao' => $usuario_db['funcao'],
    'usuario_departamento' => $usuario_db['departamento']
]);
?>
