<?php
/**
 * API DE LOGO DO TENANT ATIVO
 *
 * Regra de branding Multi-Tenant:
 * - Tela de login: usa somente /assets/img/logos/logo_padrao.png.
 * - Aplicação autenticada: recebe somente a logo do tenant da sessão.
 * - Esta API nunca seleciona um tenant por fallback, parâmetro GET ou "primeiro ativo".
 */

ob_start();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origens_permitidas = [
    'https://app.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];
if (in_array($origin, $origens_permitidas, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
ob_end_clean();

// O Portal do Morador possui sessão PHP própria, sem usuario_logado de
// backoffice. Ambos os fluxos recebem o tenant exclusivamente da sessão;
// nunca de GET/POST/header enviado pelo navegador.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['morador_logado']) && is_numeric($_SESSION['tenant_id'] ?? null)) {
    $tenant_id = (int)$_SESSION['tenant_id'];
} else {
    $auth = verificarAutenticacao(true);
    $tenant_id = (int)$auth['tenant_id'];
}
$base_dir = dirname(__DIR__);

function responder_logo($sucesso, $mensagem, $dados = [], $status = 200) {
    http_response_code($status);
    echo json_encode(array_merge([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem
    ], $dados), JSON_UNESCAPED_UNICODE);
    exit;
}

function caminho_logo_valido($base_dir, $url) {
    if (!$url || !is_string($url)) return null;
    $url = ltrim(trim($url), '/');
    if (strpos($url, '..') !== false || strpos($url, '://') !== false) return null;
    $caminho = $base_dir . '/' . $url;
    $real_arquivo = realpath($caminho);
    $real_diretorio_logo = realpath($base_dir . '/uploads/logo');
    if (!$real_arquivo || !$real_diretorio_logo || !is_file($real_arquivo)) return null;
    if (strpos($real_arquivo, $real_diretorio_logo . DIRECTORY_SEPARATOR) !== 0) return null;
    return $url;
}

function url_logo_banco($conexao, $tenant_id, $caminho_legado) {
    if (!$caminho_legado || !is_string($caminho_legado)) return null;
    $stmt = $conexao->prepare('SELECT id FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? AND ativo = 1 LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('is', $tenant_id, $caminho_legado);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? '/api/api_arquivos_tenant.php?acao=conteudo&id=' . (int)$row['id'] : null;
}

try {
    $conexao = conectar_banco();
    if (!$conexao) {
        throw new RuntimeException('Não foi possível conectar ao banco de dados.');
    }

    // Fonte primária: cadastro mestre do tenant ativo.
    $stmt = $conexao->prepare(
        'SELECT id, slug, logo_url, nome_fantasia, razao_social
         FROM tenants
         WHERE id = ? AND status = \'ativo\'
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta do tenant.');
    }
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        $conexao->close();
        responder_logo(false, 'Tenant ativo não localizado.', [], 404);
    }

    $logo_url = url_logo_banco($conexao, $tenant_id, $tenant['logo_url'] ?? null);
    $fonte = $logo_url ? 'tenants_banco' : 'tenants';
    if (!$logo_url) $logo_url = caminho_logo_valido($base_dir, $tenant['logo_url'] ?? null);

    // Compatibilidade com dados detalhados do mesmo tenant, sem cruzar empresas.
    if (!$logo_url) {
        $stmt = $conexao->prepare(
            'SELECT logo_url, nome_fantasia, razao_social
             FROM empresa
             WHERE tenant_id = ? AND situacao = \'ativo\'
             ORDER BY id ASC
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('i', $tenant_id);
            $stmt->execute();
            $empresa = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $logo_url = url_logo_banco($conexao, $tenant_id, $empresa['logo_url'] ?? null);
            if ($logo_url) {
                $fonte = 'empresa_banco';
            } else {
                $logo_url = caminho_logo_valido($base_dir, $empresa['logo_url'] ?? null);
                if ($logo_url) $fonte = 'empresa_legado';
            }
        }
    }

    $conexao->close();

    if (!$logo_url) {
        responder_logo(true, 'Tenant sem logo personalizada; usando marca institucional.', [
            'logo_url' => 'assets/img/logos/logo_padrao.png',
            'fonte' => 'plataforma_fallback',
            'tenant_id' => $tenant_id,
            'tenant_slug' => $tenant['slug'],
            'nome_empresa' => $tenant['nome_fantasia'] ?: $tenant['razao_social']
        ]);
    }

    responder_logo(true, 'Logo do tenant ativo obtida com sucesso.', [
        'logo_url' => $logo_url,
        'fonte' => $fonte,
        'tenant_id' => $tenant_id,
        'tenant_slug' => $tenant['slug'],
        'nome_empresa' => $tenant['nome_fantasia'] ?: $tenant['razao_social']
    ]);
} catch (Throwable $e) {
    error_log('[TENANT_LOGO] tenant=' . $tenant_id . ' erro=' . $e->getMessage());
    responder_logo(false, 'Não foi possível carregar a identidade visual do condomínio.', [], 500);
}
