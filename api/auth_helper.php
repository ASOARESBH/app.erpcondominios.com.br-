<?php
/**
 * =====================================================
 * AUTH HELPER — AUTENTICAÇÃO E AUTORIZAÇÃO MULTI-TENANT
 * =====================================================
 *
 * Inclua este arquivo no início de qualquer API protegida:
 *   require_once 'auth_helper.php';
 *   verificarAutenticacao();                  // Verifica login + tenant
 *   verificarAutenticacao(true, 'gerente');   // Exige nível mínimo
 *
 * MULTI-TENANT: Além de validar o login, garante que tenant_id
 * esteja presente na sessão. Toda API que chamar verificarAutenticacao()
 * terá o contexto de tenant garantido.
 *
 * @version 2.0.0 (Multi-Tenant)
 * @date 2026-07-22
 */

require_once __DIR__ . '/tenant_helper.php';

function verificarAutenticacao($exigir_autenticacao = true, $permissao_minima = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $usuario_logado = isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true;
    $usuario_id     = $_SESSION['usuario_id'] ?? null;

    if (!$usuario_logado || empty($usuario_id)) {
        if ($exigir_autenticacao) {
            http_response_code(401);
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Autenticação necessária. Faça login novamente.',
                'codigo'   => 'AUTH_REQUIRED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return false;
    }

    $sessao_inativa = (int)($_SESSION['sessao_inativa'] ?? 0);
    if ($sessao_inativa !== 1 && isset($_SESSION['login_timestamp'])) {
        $tempo_decorrido = time() - $_SESSION['login_timestamp'];
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
        $_SESSION['login_timestamp'] = time();
    }

    $tenant_id = $_SESSION['tenant_id'] ?? null;
    if (empty($tenant_id)) {
        $tenant_id = _tentarResolverTenantLegado((int)$usuario_id);
        if (empty($tenant_id) && $exigir_autenticacao) {
            http_response_code(403);
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Contexto de condomínio não identificado. Faça login novamente.',
                'codigo'   => 'TENANT_REQUIRED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($permissao_minima !== null) {
        verificarPermissao($permissao_minima);
    }

    return [
        'id'           => $usuario_id,
        'nome'         => $_SESSION['usuario_nome']         ?? null,
        'email'        => $_SESSION['usuario_email']        ?? null,
        'funcao'       => $_SESSION['usuario_funcao']       ?? null,
        'departamento' => $_SESSION['usuario_departamento'] ?? null,
        'permissao'    => $_SESSION['usuario_permissao']    ?? 'operador',
        'tenant_id'    => (int)($tenant_id ?? 1),
        'tenant_nome'  => $_SESSION['tenant_nome']          ?? '',
        'tenant_slug'  => $_SESSION['tenant_slug']          ?? '',
    ];
}

function _tentarResolverTenantLegado($usuario_id) {
    try {
        require_once __DIR__ . '/config.php';
        $conexao = conectar_banco();

        $slug = resolverTenantSlugDaUrl();
        if ($slug) {
            $tenant = carregarTenantPorSlug($conexao, $slug);
            if ($tenant) {
                injetarTenantNaSessao($tenant);
                fechar_conexao($conexao);
                return (int)$tenant['id'];
            }
        }

        $stmt = $conexao->prepare(
            "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj,
                    t.plano, t.status, t.logo_url, t.email_principal, t.modulos_habilitados
             FROM tenants t
             INNER JOIN usuario_tenant ut ON ut.tenant_id = t.id
             WHERE ut.usuario_id = ? AND t.status = 'ativo' AND ut.ativo = 1
             ORDER BY t.id ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $usuario_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $tenant = $res->fetch_assoc();
                $stmt->close();
                injetarTenantNaSessao($tenant);
                fechar_conexao($conexao);
                return (int)$tenant['id'];
            }
            $stmt->close();
        }

        $tenant = carregarTenantPorId($conexao, 1);
        fechar_conexao($conexao);
        if ($tenant) {
            injetarTenantNaSessao($tenant);
            return 1;
        }
    } catch (Exception $e) {
        error_log('[auth_helper] Erro ao resolver tenant legado: ' . $e->getMessage());
    }
    return null;
}

function verificarPermissao($permissao_necessaria) {
    $permissao_usuario = $_SESSION['usuario_permissao'] ?? 'operador';
    $hierarquia = [
        'visualizador' => 1,
        'operador'     => 2,
        'gerente'      => 3,
        'admin'        => 4,
        'super_admin'  => 5
    ];
    $nivel_usuario    = $hierarquia[$permissao_usuario]    ?? 1;
    $nivel_necessario = $hierarquia[$permissao_necessaria] ?? 1;
    if ($nivel_usuario < $nivel_necessario) {
        http_response_code(403);
        echo json_encode([
            'sucesso'              => false,
            'mensagem'             => 'Permissão insuficiente para realizar esta ação.',
            'codigo'               => 'PERMISSION_DENIED',
            'permissao_necessaria' => $permissao_necessaria,
            'permissao_usuario'    => $permissao_usuario
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return true;
}

function obterUsuarioAutenticado() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        return null;
    }
    return [
        'id'           => $_SESSION['usuario_id']          ?? null,
        'nome'         => $_SESSION['usuario_nome']         ?? null,
        'email'        => $_SESSION['usuario_email']        ?? null,
        'funcao'       => $_SESSION['usuario_funcao']       ?? null,
        'departamento' => $_SESSION['usuario_departamento'] ?? null,
        'permissao'    => $_SESSION['usuario_permissao']    ?? 'operador',
        'tenant_id'    => (int)($_SESSION['tenant_id']      ?? 1),
        'tenant_nome'  => $_SESSION['tenant_nome']          ?? '',
        'tenant_slug'  => $_SESSION['tenant_slug']          ?? '',
    ];
}

function ehMetodo($metodo) {
    return strtoupper($_SERVER['REQUEST_METHOD']) === strtoupper($metodo);
}

function retornarErro($mensagem, $codigo = 400, $dados = null) {
    http_response_code($codigo);
    $resposta = ['sucesso' => false, 'mensagem' => $mensagem];
    if ($dados !== null) $resposta['dados'] = $dados;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}

function retornarSucesso($mensagem, $dados = null, $codigo = 200) {
    http_response_code($codigo);
    $resposta = ['sucesso' => true, 'mensagem' => $mensagem];
    if ($dados !== null) $resposta['dados'] = $dados;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}
