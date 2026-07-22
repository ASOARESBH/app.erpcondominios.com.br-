<?php
// =====================================================
// API - USUÁRIO LOGADO E TEMPO DE SESSÃO
// =====================================================
// Endpoint unificado para obter informações do usuário logado
// e tempo restante de sessão
//
// Uso:
// GET /api/api_usuario_logado.php - Obter dados do usuário
// POST /api/api_usuario_logado.php?acao=renovar - Renovar sessão
// POST /api/api_usuario_logado.php?acao=logout - Fazer logout

// Configurações de sessão
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers para API
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
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir configurações
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'controllers/SessionController.php';

try {
    // Conectar ao banco
    $conexao = conectar_banco();
    
    // Criar controller
    $controller = new SessionController($conexao);
    
    // Obter ação da requisição
    $acao = isset($_GET['acao']) ? $_GET['acao'] : '';
    $metodo = $_SERVER['REQUEST_METHOD'];
    
    // Processar requisição
    if ($metodo === 'GET') {
        // Obter dados do usuário logado
        $resultado = $controller->obterDadosUsuarioLogado();
        
        http_response_code($resultado['sucesso'] ? 200 : 401);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        
    } elseif ($metodo === 'POST') {
        
        if ($acao === 'renovar') {
            // Renovar sessão
            $resultado = $controller->renovarSessao();
            http_response_code($resultado['sucesso'] ? 200 : 400);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
            
        } elseif ($acao === 'logout') {
            // Fazer logout
            $resultado = $controller->encerrarSessao();
            http_response_code($resultado['sucesso'] ? 200 : 400);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
            
        } elseif ($acao === 'sessoes') {
            // Obter todas as sessões ativas
            $resultado = $controller->obterSessoesAtivasUsuario();
            http_response_code($resultado['sucesso'] ? 200 : 400);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
            
        } elseif ($acao === 'limpar') {
            // Limpar sessões expiradas (apenas admin)
            if (isset($_SESSION['usuario_permissao']) && $_SESSION['usuario_permissao'] === 'admin') {
                $resultado = $controller->limparSessoesExpiradas();
                http_response_code($resultado['sucesso'] ? 200 : 400);
                echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(403);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Permissão negada'
                ], JSON_UNESCAPED_UNICODE);
            }
            
        } else {
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Ação não reconhecida'
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        http_response_code(405);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Método não permitido'
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // Fechar conexão
    fechar_conexao($conexao);
    
} catch (Exception $e) {
    error_log('Erro em api_usuario_logado.php: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao processar requisição',
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
