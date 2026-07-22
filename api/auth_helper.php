<?php
// =====================================================
// HELPER DE AUTENTICAÇÃO - PARA USO EM TODOS OS ENDPOINTS
// =====================================================
// 
// Inclua este arquivo no início de qualquer API que necessite autenticação:
// require_once 'auth_helper.php';
// verificarAutenticacao(); // Chamada simples para verificar
//
// Ou use a função com parâmetros:
// verificarAutenticacao(true, 'admin'); // Requer admin
// =====================================================

/**
 * Verifica se o usuário está autenticado
 * 
 * @param bool $exigir_autenticacao Se true, retorna erro se não autenticado
 * @param string $permissao_minima Permissão mínima necessária ('operador', 'gerente', 'admin')
 * @return bool|array Retorna true se autenticado, array com dados se $exigir_autenticacao = false
 */
function verificarAutenticacao($exigir_autenticacao = true, $permissao_minima = null) {
    // Iniciar sessão se não estiver iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar se usuário está logado
    $usuario_logado = isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true;
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    
    if (!$usuario_logado || empty($usuario_id)) {
        if ($exigir_autenticacao) {
            http_response_code(401);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Autenticação necessária. Faça login novamente.',
                'codigo' => 'AUTH_REQUIRED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return false;
    }
    
    // Verificar timeout da sessão
    // Se sessao_inativa = 1, a sessão NUNCA expira (portaria, monitoramento 24h)
    $sessao_inativa = (int)($_SESSION['sessao_inativa'] ?? 0);
    if ($sessao_inativa !== 1 && isset($_SESSION['login_timestamp'])) {
        $tempo_decorrido = time() - $_SESSION['login_timestamp'];
        // Timeout de 8 horas (jornada de trabalho completa)
        if ($tempo_decorrido > 28800) {
            if ($exigir_autenticacao) {
                session_unset();
                session_destroy();
                http_response_code(401);
                echo json_encode([
                    'sucesso'  => false,
                    'mensagem' => 'Sessão expirada após 8 horas. Faça login novamente.',
                    'codigo'   => 'SESSION_EXPIRED'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            return false;
        }
        // Renovar timestamp a cada requisição autenticada (sliding session)
        $_SESSION['login_timestamp'] = time();
    }
    
    // Verificar permissão se necessário
    if ($permissao_minima !== null) {
        verificarPermissao($permissao_minima);
    }
    
    // Retornar dados do usuário
    return [
        'id' => $usuario_id,
        'nome' => $_SESSION['usuario_nome'] ?? null,
        'email' => $_SESSION['usuario_email'] ?? null,
        'funcao' => $_SESSION['usuario_funcao'] ?? null,
        'departamento' => $_SESSION['usuario_departamento'] ?? null,
        'permissao' => $_SESSION['usuario_permissao'] ?? 'operador'
    ];
}

/**
 * Verifica se o usuário tem a permissão necessária
 * 
 * @param string $permissao_necessaria Permissão necessária
 * @return bool
 */
function verificarPermissao($permissao_necessaria) {
    $permissao_usuario = $_SESSION['usuario_permissao'] ?? 'operador';
    
    $hierarquia = [
        'visualizador' => 1,
        'operador' => 2,
        'gerente' => 3,
        'admin' => 4
    ];
    
    $nivel_usuario = $hierarquia[$permissao_usuario] ?? 1;
    $nivel_necessario = $hierarquia[$permissao_necessaria] ?? 1;
    
    if ($nivel_usuario < $nivel_necessario) {
        http_response_code(403);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Permissão insuficiente para realizar esta ação.',
            'codigo' => 'PERMISSION_DENIED',
            'permissao_necessaria' => $permissao_necessaria,
            'permissao_usuario' => $permissao_usuario
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return true;
}

/**
 * Obter dados do usuário autenticado
 * 
 * @return array|null
 */
function obterUsuarioAutenticado() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        return null;
    }
    
    return [
        'id' => $_SESSION['usuario_id'] ?? null,
        'nome' => $_SESSION['usuario_nome'] ?? null,
        'email' => $_SESSION['usuario_email'] ?? null,
        'funcao' => $_SESSION['usuario_funcao'] ?? null,
        'departamento' => $_SESSION['usuario_departamento'] ?? null,
        'permissao' => $_SESSION['usuario_permissao'] ?? 'operador'
    ];
}

/**
 * Verificar se é uma requisição de método específico
 * 
 * @param string $metodo GET, POST, PUT, DELETE
 * @return bool
 */
function ehMetodo($metodo) {
    return strtoupper($_SERVER['REQUEST_METHOD']) === strtoupper($metodo);
}

/**
 * Retornar resposta JSON de erro
 * 
 * @param string $mensagem Mensagem de erro
 * @param int $codigo HTTP status code
 * @param array $dados Dados adicionais
 */
function retornarErro($mensagem, $codigo = 400, $dados = null) {
    http_response_code($codigo);
    
    $resposta = [
        'sucesso' => false,
        'mensagem' => $mensagem
    ];
    
    if ($dados !== null) {
        $resposta['dados'] = $dados;
    }
    
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Retornar resposta JSON de sucesso
 * 
 * @param string $mensagem Mensagem de sucesso
 * @param array $dados Dados da resposta
 * @param int $codigo HTTP status code
 */
function retornarSucesso($mensagem, $dados = null, $codigo = 200) {
    http_response_code($codigo);
    
    $resposta = [
        'sucesso' => true,
        'mensagem' => $mensagem
    ];
    
    if ($dados !== null) {
        $resposta['dados'] = $dados;
    }
    
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}
