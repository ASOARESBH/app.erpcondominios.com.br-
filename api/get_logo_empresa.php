<?php
/**
 * =====================================================
 * GET LOGO EMPRESA — Multi-Tenant v2.0
 * =====================================================
 * Hierarquia de busca:
 *   1. tenants.logo_url (fonte primária Multi-Tenant)
 *   2. empresa.logo_url (fallback legado)
 *   3. uploads/logo/tenant_{id}/logo.* (arquivo físico por tenant)
 *   4. uploads/logo/logo.* (legado)
 *   5. uploads/logo/logoerp.png (fallback ERP)
 *   6. assets/img/logos/logo_padrao.png (fallback estático)
 *
 * GET /api/get_logo_empresa.php
 * GET /api/get_logo_empresa.php?tenant_id=X (para relatórios PDF)
 */

ob_start();

$_gl_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_gl_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_gl_origin)) {
    header('Access-Control-Allow-Origin: ' . $_gl_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}
ob_end_clean();

$base_dir = dirname(__DIR__);

function retornar_logo($logo_url, $fonte, $nome_empresa = null) {
    echo json_encode([
        'sucesso'      => true,
        'logo_url'     => $logo_url,
        'fonte'        => $fonte,
        'nome_empresa' => $nome_empresa,
        'timestamp'    => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Determinar tenant_id ──────────────────────────────────────────────────
$tenant_id = null;

// 1. Parâmetro GET explícito (relatórios PDF passam ?tenant_id=X)
if (!empty($_GET['tenant_id']) && is_numeric($_GET['tenant_id'])) {
    $tenant_id = (int)$_GET['tenant_id'];
}

// 2. Sessão PHP (usuário logado)
if (!$tenant_id) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

// ── Buscar no banco ───────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/config.php';
    $conexao = conectar_banco();

    // REGRA 1: tabela tenants (fonte primária Multi-Tenant)
    if ($tenant_id) {
        $stmt = $conexao->prepare(
            "SELECT logo_url, nome_fantasia, razao_social FROM tenants WHERE id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $tenant_id);
            $stmt->execute();
            $tenant = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($tenant && !empty($tenant['logo_url'])) {
                $caminho = $base_dir . '/' . ltrim($tenant['logo_url'], '/');
                if (file_exists($caminho)) {
                    $conexao->close();
                    retornar_logo(
                        $tenant['logo_url'],
                        'tenants',
                        $tenant['nome_fantasia'] ?: $tenant['razao_social']
                    );
                }
            }
        }
    }

    // REGRA 2: tabela empresa (fallback legado)
    if ($tenant_id) {
        $stmt = $conexao->prepare(
            "SELECT logo_url, nome_fantasia, razao_social FROM empresa WHERE tenant_id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $tenant_id);
            $stmt->execute();
            $empresa = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($empresa && !empty($empresa['logo_url'])) {
                $caminho = $base_dir . '/' . ltrim($empresa['logo_url'], '/');
                if (file_exists($caminho)) {
                    $conexao->close();
                    retornar_logo(
                        $empresa['logo_url'],
                        'empresa',
                        $empresa['nome_fantasia'] ?: $empresa['razao_social']
                    );
                }
            }
        }
    } else {
        // Sem tenant_id: buscar o primeiro registro (compatibilidade)
        // Sem tenant_id: buscar o primeiro tenant ativo (compatibilidade)
        $res = $conexao->query("SELECT logo_url, nome_fantasia, razao_social FROM tenants WHERE status = 'ativo' LIMIT 1");
        if ($res) {
            $tenant_row = $res->fetch_assoc();
            if ($tenant_row && !empty($tenant_row['logo_url'])) {
                $caminho = $base_dir . '/' . ltrim($tenant_row['logo_url'], '/');
                if (file_exists($caminho)) {
                    $conexao->close();
                    retornar_logo(
                        $tenant_row['logo_url'],
                        'tenant_sem_sessao',
                        $tenant_row['nome_fantasia'] ?: $tenant_row['razao_social']
                    );
                }
            }
        }
    }

    $conexao->close();
} catch (Exception $e) {
    error_log("[GET_LOGO] Erro banco: " . $e->getMessage());
}

// REGRA 3: arquivo físico na pasta do tenant
if ($tenant_id) {
    foreach (['png','jpg','jpeg','gif','webp'] as $ext) {
        $caminho = $base_dir . '/uploads/logo/tenant_' . $tenant_id . '/logo.' . $ext;
        if (file_exists($caminho)) {
            retornar_logo('uploads/logo/tenant_' . $tenant_id . '/logo.' . $ext, 'arquivo_tenant');
        }
    }
}

// REGRA 4: arquivo físico legado (sem tenant)
foreach (['png','jpg','jpeg','gif','webp','svg'] as $ext) {
    $caminho = $base_dir . '/uploads/logo/logo.' . $ext;
    if (file_exists($caminho)) {
        retornar_logo('uploads/logo/logo.' . $ext, 'arquivo_legado');
    }
}

// REGRA 5: fallback logoerp.png
if (file_exists($base_dir . '/uploads/logo/logoerp.png')) {
    retornar_logo('uploads/logo/logoerp.png', 'fallback_erp', 'ERP Condomínio');
}

// REGRA 6: fallback estático
if (file_exists($base_dir . '/assets/img/logos/logo_padrao.png')) {
    retornar_logo('assets/img/logos/logo_padrao.png', 'fallback_estatico', 'ERP Condomínio');
}

// Nenhuma logo encontrada
echo json_encode([
    'sucesso'      => true,
    'logo_url'     => null,
    'fonte'        => 'nenhuma',
    'nome_empresa' => null,
    'timestamp'    => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);
