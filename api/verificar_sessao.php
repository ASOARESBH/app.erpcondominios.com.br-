<?php
/**
 * =====================================================
 * API: VERIFICAÇÃO DE SESSÃO — MULTI-TENANT
 * =====================================================
 * Verifica se o usuário está autenticado e retorna
 * os dados da sessão incluindo o tenant ativo.
 *
 * @version 2.0.0 (Multi-Tenant)
 * @date 2026-07-22
 */

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)
) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'config.php';
require_once 'tenant_helper.php';
require_once 'auth_helper.php';

// Verificar se está logado
if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar timeout (8 horas)
$sessao_inativa = (int)($_SESSION['sessao_inativa'] ?? 0);
if ($sessao_inativa !== 1 && isset($_SESSION['login_timestamp'])) {
    if ((time() - $_SESSION['login_timestamp']) > 28800) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['login_timestamp'] = time();
}

if (ERP_DB_DRIVER === 'pgsql') {
    $hostTenant = resolverContextoHostTenantPostgres();
    $tenantIdSessao = filter_var($_SESSION['tenant_id'] ?? null, FILTER_VALIDATE_INT);
    $tenantSlugSessao = $_SESSION['tenant_slug'] ?? null;
    $contextoSessaoValido = $tenantIdSessao !== false && $tenantIdSessao !== null && $tenantIdSessao > 0
        && is_string($tenantSlugSessao) && $tenantSlugSessao !== ''
        && ($hostTenant['slug'] === null || $hostTenant['slug'] === $tenantSlugSessao);

    if (!$hostTenant['trusted'] || !$contextoSessaoValido) {
        erp_auth_limpar_sessao_operacional();
        http_response_code($hostTenant['trusted'] ? 409 : 403);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Contexto do condomínio inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $usuarioAutenticado = [
        'id' => $_SESSION['usuario_id'] ?? null,
        'nome' => $_SESSION['usuario_nome'] ?? '',
        'email' => $_SESSION['usuario_email'] ?? '',
        'funcao' => $_SESSION['usuario_funcao'] ?? '',
        'departamento' => $_SESSION['usuario_departamento'] ?? '',
        'permissao' => $_SESSION['usuario_permissao'] ?? 'operador',
        'tenant_id' => (int)$tenantIdSessao,
    ];
} else {
    // MySQL usa o mesmo vínculo ativo obrigatório; querystring não escolhe tenant.
    $usuarioAutenticado = verificarAutenticacao(true);
}

echo json_encode([
    'sucesso' => true,
    'mensagem'=> 'Sessão válida',
    'dados'   => [
        'usuario' => [
            'id'          => $usuarioAutenticado['id'],
            'nome'        => $usuarioAutenticado['nome'] ?? '',
            'email'       => $usuarioAutenticado['email'] ?? '',
            'funcao'      => $usuarioAutenticado['funcao'] ?? '',
            'departamento'=> $usuarioAutenticado['departamento'] ?? '',
            'permissao'   => $usuarioAutenticado['permissao'] ?? 'operador',
        ],
        'tenant' => [
            'id'    => (int)$usuarioAutenticado['tenant_id'],
            'slug'  => $_SESSION['tenant_slug']        ?? '',
            'nome'  => $_SESSION['tenant_nome']        ?? '',
            'plano' => $_SESSION['tenant_plano']       ?? 'basico',
            'logo'  => $_SESSION['tenant_logo_url']    ?? null,
        ]
    ]
], JSON_UNESCAPED_UNICODE);
?>
