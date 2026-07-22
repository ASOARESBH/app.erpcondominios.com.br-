<?php
/**
 * API - USUÁRIO LOGADO E TEMPO DE SESSÃO (VERSÃO CORRIGIDA)
 * 
 * Versão simplificada que não depende de Controllers/Models complexos
 * Evita erros de dependências ausentes
 */

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

try {
    // Conectar ao banco
    $conexao = conectar_banco();
    
    // Obter ação da requisição
    $acao = isset($_GET['acao']) ? sanitizar($conexao, $_GET['acao']) : '';
    $metodo = $_SERVER['REQUEST_METHOD'];
    
    // ========== GET: Obter dados do usuário logado ==========
    if ($metodo === 'GET') {
        // Verificar se usuário está logado
        if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
            http_response_code(401);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Usuário não autenticado',
                'logado' => false
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Verificar se ID do usuário existe
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Sessão inválida',
                'logado' => false
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Calcular tempo de sessão
        $timeout = 7200; // 2 horas em segundos
        $login_timestamp = $_SESSION['login_timestamp'] ?? time();
        $tempo_decorrido = time() - $login_timestamp;
        $tempo_restante = max(0, $timeout - $tempo_decorrido);
        
        // Formatar tempo
        $formatarTempo = function($segundos) {
            $horas = floor($segundos / 3600);
            $minutos = floor(($segundos % 3600) / 60);
            $segs = $segundos % 60;
            return sprintf('%02d:%02d:%02d', $horas, $minutos, $segs);
        };
        
        // Preparar dados do usuário
        $dados_usuario = [
            'id' => $_SESSION['usuario_id'],
            'nome' => $_SESSION['usuario_nome'] ?? 'Usuário',
            'email' => $_SESSION['usuario_email'] ?? '',
            'funcao' => $_SESSION['usuario_funcao'] ?? '',
            'departamento' => $_SESSION['usuario_departamento'] ?? '',
            'permissao' => $_SESSION['usuario_permissao'] ?? 'operador'
        ];
        
        // Preparar dados de sessão
        $dados_sessao = [
            'id' => session_id(),
            'tempo_decorrido' => $tempo_decorrido,
            'tempo_restante' => $tempo_restante,
            'tempo_decorrido_formatado' => $formatarTempo($tempo_decorrido),
            'tempo_restante_formatado' => $formatarTempo($tempo_restante),
            'percentual_usado' => round(($tempo_decorrido / $timeout) * 100, 2),
            'percentual_restante' => round(($tempo_restante / $timeout) * 100, 2),
            'data_login' => $_SESSION['data_login'] ?? date('Y-m-d H:i:s', $login_timestamp),
            'data_expiracao' => date('Y-m-d H:i:s', $login_timestamp + $timeout),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'ativo' => true
        ];
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'logado' => true,
            'usuario' => $dados_usuario,
            'sessao' => $dados_sessao
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== POST: Processar ações ==========
    if ($metodo === 'POST') {
        
        // Ação: Renovar sessão
        if ($acao === 'renovar') {
            if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
                http_response_code(401);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Usuário não autenticado'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Renovar timestamp
            $_SESSION['login_timestamp'] = time();
            
            http_response_code(200);
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Sessão renovada com sucesso',
                'novo_timestamp' => $_SESSION['login_timestamp']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Ação: Fazer logout
        if ($acao === 'logout') {
            // Registrar logout no log
            if (isset($_SESSION['usuario_nome'])) {
                registrar_log('LOGOUT', "Logout realizado: {$_SESSION['usuario_email']}", $_SESSION['usuario_nome']);
            }
            
            // Destruir sessão PHP
            $_SESSION = [];
            
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
            
            http_response_code(200);
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Logout realizado com sucesso'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Ação: Obter sessões ativas
        if ($acao === 'sessoes') {
            if (!isset($_SESSION['usuario_id'])) {
                http_response_code(401);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Usuário não autenticado'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Retornar sessão atual
            $sessoes = [[
                'id' => session_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'data_login' => $_SESSION['data_login'] ?? date('Y-m-d H:i:s'),
                'ativo' => true
            ]];
            
            http_response_code(200);
            echo json_encode([
                'sucesso' => true,
                'sessoes' => $sessoes,
                'total' => count($sessoes)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Ação: Limpar sessões expiradas (apenas admin)
        if ($acao === 'limpar') {
            if (!isset($_SESSION['usuario_permissao']) || $_SESSION['usuario_permissao'] !== 'admin') {
                http_response_code(403);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Permissão negada'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Limpar sessões expiradas
            $sql = "DELETE FROM sessoes_usuarios WHERE data_expiracao < NOW() AND ativo = 1";
            $resultado = $conexao->query($sql);
            $total_removidas = $conexao->affected_rows;
            
            registrar_log('SESSOES_LIMPAS', "Sessões expiradas removidas: $total_removidas", $_SESSION['usuario_nome'] ?? 'Sistema');
            
            http_response_code(200);
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Limpeza realizada com sucesso',
                'total_removidas' => $total_removidas
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Ação não reconhecida
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não reconhecida'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Método não permitido
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Exception $e) {
    error_log('Erro em api_usuario_logado.php: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao processar requisição',
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} finally {
    // Fechar conexão
    if (isset($conexao)) {
        fechar_conexao($conexao);
    }
}
?>
