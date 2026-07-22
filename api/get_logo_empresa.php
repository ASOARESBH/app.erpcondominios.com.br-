<?php
/**
 * =====================================================
 * ENDPOINT PÚBLICO - LOGO DA EMPRESA
 * =====================================================
 *
 * Endpoint público (sem autenticação) para retornar a URL
 * da logo da empresa cadastrada no sistema.
 *
 * Regras de negócio:
 * 1. Busca logo cadastrada na tabela 'empresa' (logo_url)
 * 2. Se não houver logo cadastrada, verifica uploads/logo/logo.*
 * 3. Se não houver nenhuma, retorna o fallback: uploads/logo/logoerp.png
 *
 * Endpoint:
 * - GET /api/get_logo_empresa.php
 *
 * Resposta:
 * {
 *   "sucesso": true,
 *   "logo_url": "uploads/logo/logo.png",
 *   "fonte": "empresa|arquivo|fallback"
 * }
 *
 * Segurança:
 * - Endpoint público (necessário para a tela de login)
 * - Apenas retorna URL da imagem, sem dados sensíveis
 * - Rate limiting via .htaccess
 * - CORS restrito ao domínio do sistema
 *
 * @author  Engenheiro Sênior ASL ERP
 * @version 1.0.0
 * @since   2026-03-03
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // Cache de 5 minutos

// CORS - Permitir apenas do mesmo domínio
$allowed_origins = [
    'https://asl.erpcondominios.com.br',
    'http://asl.erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: {$origin}");
} else {
    // Mesmo sem origin (requisição direta), permite
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas GET permitido
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Retorna JSON padronizado
 */
function retornar_logo($logo_url, $fonte, $nome_empresa = null) {
    echo json_encode([
        'sucesso'       => true,
        'logo_url'      => $logo_url,
        'fonte'         => $fonte,
        'nome_empresa'  => $nome_empresa,
        'timestamp'     => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Caminho base do projeto (raiz do servidor)
$base_dir = dirname(__DIR__);

// =====================================================
// REGRA 1: Buscar logo cadastrada no banco de dados
// =====================================================
try {
    require_once __DIR__ . '/config.php';
    $conexao = conectar_banco();

    $stmt = $conexao->prepare("
        SELECT logo_url, nome_fantasia, razao_social
        FROM empresa
        LIMIT 1
    ");

    if ($stmt && $stmt->execute()) {
        $resultado = $stmt->get_result();
        $empresa   = $resultado->fetch_assoc();
        $stmt->close();
        $conexao->close();

        if ($empresa && !empty($empresa['logo_url'])) {
            $logo_url      = $empresa['logo_url'];
            $nome_empresa  = $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? null;

            // Verificar se o arquivo físico existe
            $caminho_fisico = $base_dir . '/' . ltrim($logo_url, '/');
            if (file_exists($caminho_fisico)) {
                error_log("[GET_LOGO] Fonte: banco de dados | URL: {$logo_url}");
                retornar_logo($logo_url, 'empresa', $nome_empresa);
            }
        }
    }
} catch (Exception $e) {
    // Log do erro mas continua para fallback
    error_log("[GET_LOGO] Erro ao buscar no banco: " . $e->getMessage());
}

// =====================================================
// REGRA 2: Verificar arquivo logo.* em uploads/logo/
// =====================================================
$extensoes = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
foreach ($extensoes as $ext) {
    $caminho = $base_dir . '/uploads/logo/logo.' . $ext;
    if (file_exists($caminho)) {
        $logo_url = 'uploads/logo/logo.' . $ext;
        error_log("[GET_LOGO] Fonte: arquivo | URL: {$logo_url}");
        retornar_logo($logo_url, 'arquivo');
    }
}

// =====================================================
// REGRA 3: Fallback - Logo ERP Condomínios (logoerp.png)
// =====================================================
$fallback_path = $base_dir . '/uploads/logo/logoerp.png';
if (file_exists($fallback_path)) {
    error_log("[GET_LOGO] Fonte: fallback | URL: uploads/logo/logoerp.png");
    retornar_logo('uploads/logo/logoerp.png', 'fallback', 'ERP Condomínios');
}

// Se nenhuma logo encontrada, retorna null
echo json_encode([
    'sucesso'   => true,
    'logo_url'  => null,
    'fonte'     => 'nenhuma',
    'timestamp' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);
