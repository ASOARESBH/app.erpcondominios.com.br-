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

// Resolver tenant se não estiver na sessão (compatibilidade)
$tenant_id = $_SESSION['tenant_id'] ?? null;
if (empty($tenant_id)) {
    $slug = resolverTenantSlugDaUrl();
    if ($slug) {
        $conexao = conectar_banco();
        $tenant  = carregarTenantPorSlug($conexao, $slug);
        if ($tenant) injetarTenantNaSessao($tenant);
        fechar_conexao($conexao);
    }
}

echo json_encode([
    'sucesso' => true,
    'mensagem'=> 'Sessão válida',
    'dados'   => [
        'usuario' => [
            'id'          => $_SESSION['usuario_id']          ?? null,
            'nome'        => $_SESSION['usuario_nome']         ?? '',
            'email'       => $_SESSION['usuario_email']        ?? '',
            'funcao'      => $_SESSION['usuario_funcao']       ?? '',
            'departamento'=> $_SESSION['usuario_departamento'] ?? '',
            'permissao'   => $_SESSION['usuario_permissao']    ?? 'operador',
        ],
        'tenant' => [
            'id'    => (int)($_SESSION['tenant_id']   ?? 1),
            'slug'  => $_SESSION['tenant_slug']        ?? '',
            'nome'  => $_SESSION['tenant_nome']        ?? '',
            'plano' => $_SESSION['tenant_plano']       ?? 'basico',
            'logo'  => $_SESSION['tenant_logo_url']    ?? null,
        ]
    ]
], JSON_UNESCAPED_UNICODE);
?>
