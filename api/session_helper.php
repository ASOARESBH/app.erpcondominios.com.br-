<?php
/**
 * =====================================================
 * SESSION HELPER — CONFIGURAÇÃO CENTRALIZADA DE SESSÃO
 * =====================================================
 * Inclua este arquivo NO INÍCIO de qualquer API que
 * precise de sessão PHP, ANTES de session_start().
 *
 * PROBLEMA RESOLVIDO:
 * Sem session_set_cookie_params(path='/'), o PHP cria
 * o cookie PHPSESSID com path=/api/ — o browser não
 * envia esse cookie para /frontend/, fazendo a sessão
 * aparecer vazia em cada nova requisição.
 *
 * USO:
 *   require_once 'session_helper.php';
 *   iniciar_sessao_fornecedor();
 * =====================================================
 */

/**
 * Inicia a sessão PHP com configurações corretas para
 * o portal do fornecedor (cookie válido em todo o domínio).
 */
function iniciar_sessao_fornecedor() {
    if (session_status() !== PHP_SESSION_NONE) {
        return; // Sessão já iniciada — não fazer nada
    }

    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 7200,       // 2 horas
        'path'     => '/',        // ← CRÍTICO: válido para TODO o domínio
        'domain'   => '',         // domínio atual
        'secure'   => $is_https,  // HTTPS quando disponível
        'httponly' => true,       // não acessível via JS
        'samesite' => 'Lax',      // proteção CSRF básica
    ]);

    session_start();
}

/**
 * Configura os headers CORS corretos para APIs que usam
 * credentials: 'include' no frontend.
 *
 * PROBLEMA RESOLVIDO:
 * Access-Control-Allow-Origin: * é INVÁLIDO quando
 * credentials: 'include' está ativo — o browser rejeita
 * o cookie silenciosamente. É obrigatório usar o domínio
 * específico da requisição.
 */
function configurar_cors_fornecedor() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    $allowed = [
        'https://asl.erpcondominios.com.br',
        'http://asl.erpcondominios.com.br',
        'https://www.asl.erpcondominios.com.br',
        'http://localhost',
        'http://localhost:3000',
        'http://localhost:8080',
    ];

    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    // Responder preflight OPTIONS imediatamente
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Verifica se o fornecedor está autenticado na sessão.
 * Retorna o fornecedor_id ou encerra com 401.
 *
 * @param bool $encerrar_se_nao_autenticado
 * @return int|false
 */
function verificar_sessao_fornecedor($encerrar_se_nao_autenticado = true) {
    if (!isset($_SESSION['fornecedor_id']) || (int)$_SESSION['fornecedor_id'] <= 0) {
        if ($encerrar_se_nao_autenticado) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'sucesso'  => false,
                'logado'   => false,
                'mensagem' => 'Fornecedor não autenticado. Faça login novamente.',
                'codigo'   => 'AUTH_REQUIRED',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return false;
    }
    return (int)$_SESSION['fornecedor_id'];
}
