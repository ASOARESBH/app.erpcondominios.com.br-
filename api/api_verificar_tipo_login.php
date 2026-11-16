<?php
/**
 * =====================================================
 * API: VERIFICAR TIPO DE LOGIN — MULTI-TENANT
 * =====================================================
 *
 * Verifica se o email/senha pertencem a:
 *   - Apenas usuário ERP (tabela usuarios)
 *   - Apenas morador (tabela moradores)
 *   - Ambos (retorna 'ambos' para exibir popup de seleção)
 *
 * MULTI-TENANT: Após autenticar, identifica o tenant (condomínio)
 * via subdomínio ou pelo vínculo do usuário na tabela usuario_tenant,
 * e injeta tenant_id + dados do tenant na sessão PHP.
 *
 * Endpoint: POST /api/api_verificar_tipo_login.php
 * Body: { "email": "...", "senha": "...", "tipo_escolhido"?: "erp|portal" }
 *
 * Resposta PostgreSQL: sucesso/tipo/dados; contexto de tenant ambíguo retorna HTTP 409.
 * O comportamento histórico do MySQL para múltiplos vínculos permanece inalterado.
 *
 * @version 2.0.0 (Multi-Tenant)
 * @date 2026-07-22
 */

// Configurações de sessão
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 28800); // 8 horas

session_start();

// Headers CORS dinâmico (Multi-Tenant: aceita qualquer subdomínio do sistema)
header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin) ||
    preg_match('/^https?:\/\/127\.0\.0\.1(:\d+)?$/', $origin)
) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/db/DatabaseDialect.php';

// Função auxiliar JSON local
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function iniciar_sessao_autenticada_limpa(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Não foi possível iniciar a sessão autenticada.');
    }
    if (!session_regenerate_id(true)) {
        $_SESSION = [];
        session_destroy();
        throw new RuntimeException('Não foi possível iniciar a sessão autenticada.');
    }
    $_SESSION = [];
}

function invalidar_sessao_anterior_login_pg(): void {
    require_once __DIR__ . '/auth_helper.php';
    erp_auth_limpar_sessao_operacional();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    retornar_json(false, 'Método não permitido');
}

// Receber dados (JSON ou FormData)
$input = file_get_contents('php://input');
$dados = json_decode($input, true);
if (!$dados) $dados = $_POST;

$email = isset($dados['email']) ? strtolower(trim($dados['email'])) : '';
$senha = isset($dados['senha']) ? $dados['senha'] : '';

if (empty($email) || empty($senha)) {
    registrar_log('LOGIN_FALHA', 'CAMPOS_LOGIN_AUSENTES', null, false);
    retornar_json(false, 'E-mail e senha são obrigatórios');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registrar_log('LOGIN_FALHA', 'EMAIL_INVALIDO', null, false);
    retornar_json(false, 'E-mail inválido');
}

$novaSessaoAutenticada = false;
$tokenPortalCriado = null;
$moradorTokenCriadoId = null;
$portalTransactionStarted = false;
$portalTransactionCommitted = false;

try {
    $conexao = conectar_banco();
    if (\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
        require_once __DIR__ . '/rbac_helper.php';
        rbacTabelasDisponiveis($conexao);
    }

    $encontrou_erp     = false;
    $encontrou_morador = false;
    $dados_erp         = null;
    $dados_morador     = null;

    // ─── 1. Verificar tabela USUARIOS (ERP) ───────────────────────────────
    $stmt = $conexao->prepare(
        "SELECT id, nome, email, senha, funcao, departamento, permissao, ativo,
                COALESCE(sessao_inativa, 0) AS sessao_inativa
         FROM usuarios WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $usuario = $res->fetch_assoc();
        $stmt->close();

        if ((int)$usuario['ativo'] === 0) {
            registrar_log('LOGIN_BLOQUEADO', 'CONTA_INATIVA', null, false);
            retornar_json(false, 'Sua conta está desativada. Entre em contato com o administrador.');
        }

        if (password_verify($senha, $usuario['senha'])) {
            $encontrou_erp = true;
            $dados_erp = [
                'id'             => $usuario['id'],
                'nome'           => $usuario['nome'],
                'email'          => $usuario['email'],
                'funcao'         => $usuario['funcao'],
                'departamento'   => $usuario['departamento'],
                'permissao'      => $usuario['permissao'],
                'sessao_inativa' => (int)($usuario['sessao_inativa'] ?? 0)
            ];
        }
    } else {
        $stmt->close();
    }

    // ─── 2. Verificar tabela MORADORES (Portal) ────────────────────────────
    $stmt = $conexao->prepare(
        "SELECT id, nome, email, senha, cpf, unidade, ativo, senha_temporaria
         FROM moradores WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $morador = null;

    if ($res->num_rows > 0) {
        $morador = $res->fetch_assoc();
        $stmt->close();

        if ($morador['ativo'] == 1) {
            $senha_valida_morador = false;

            if (strpos($morador['senha'], '$2y$') === 0) {
                $senha_valida_morador = password_verify($senha, $morador['senha']);
            }
            if (!$senha_valida_morador && strlen($morador['senha']) === 40) {
                $senha_valida_morador = (sha1($senha) === $morador['senha']);
                if ($senha_valida_morador && !\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
                    $nova_senha = password_hash($senha, PASSWORD_BCRYPT);
                    $stmt_upd = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
                    $stmt_upd->bind_param('si', $nova_senha, $morador['id']);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                    registrar_log('SENHA_ATUALIZADA', 'CREDENCIAL_LEGADA_ATUALIZADA', null, false);
                }
            }
            if (!$senha_valida_morador) {
                $senha_valida_morador = ($senha === $morador['senha']);
            }

            if ($senha_valida_morador) {
                $encontrou_morador = true;
                $dados_morador = [
                    'id'               => $morador['id'],
                    'nome'             => $morador['nome'],
                    'email'            => $morador['email'],
                    'cpf'              => $morador['cpf'],
                    'unidade'          => $morador['unidade'],
                    'senha_temporaria' => (int)($morador['senha_temporaria'] ?? 0)
                ];
            }
        }
    } else {
        $stmt->close();
    }

    // ─── 3. Nenhum perfil encontrado ──────────────────────────────────────
    if (!$encontrou_erp && !$encontrou_morador) {
        registrar_log('LOGIN_FALHA', 'CREDENCIAIS_INVALIDAS', null, false);
        retornar_json(false, 'E-mail ou senha incorretos!');
    }

    // ─── 4. Resolver tipo de login ────────────────────────────────────────
    $tipo_escolhido = isset($dados['tipo_escolhido']) ? trim($dados['tipo_escolhido']) : '';

    if ($encontrou_erp && $encontrou_morador && empty($tipo_escolhido)) {
        registrar_log('LOGIN_AMBOS', 'MULTIPLOS_PERFIS', null, false);
        echo json_encode([
            'sucesso'  => true,
            'tipo'     => 'ambos',
            'mensagem' => 'Múltiplos perfis encontrados.',
            'dados'    => [
                'nome_erp'     => $dados_erp['nome'],
                'nome_morador' => $dados_morador['nome']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tipo_final = '';
    if ($encontrou_erp && $encontrou_morador) {
        $tipo_final = ($tipo_escolhido === 'portal') ? 'morador' : 'erp';
    } elseif ($encontrou_erp) {
        $tipo_final = 'erp';
    } else {
        $tipo_final = 'morador';
    }

    // ─── 5. AUTENTICAÇÃO ERP + RESOLUÇÃO DE TENANT ───────────────────────
    if ($tipo_final === 'erp') {
        // A permissão global é a autoridade para Super-Admin. Um vínculo local
        // em usuario_tenant nunca pode reduzir super_admin para admin.
        $permissao_global = strtolower(trim((string)($dados_erp['permissao'] ?? 'operador')));
        $dados_erp['permissao'] = $permissao_global;

        // 5a. Identificar tenant pelo subdomínio da requisição
        $isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
        if ($isPostgres && !resolverContextoHostTenantPostgres()['trusted']) {
            fechar_conexao($conexao);
            invalidar_sessao_anterior_login_pg();
            http_response_code(403);
            retornar_json(false, 'Acesso ao domínio não autorizado.');
        }
        $tenant_slug = resolverTenantSlugDaUrl(!$isPostgres);
        $tenant = null;

        if ($tenant_slug) {
            if ($isPostgres) {
                $superAdminClause = " OR ? = 'super_admin'";
                $stmt_slug = $conexao->prepare(
                    "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj,
                            t.plano, t.status, t.logo_url, t.email_principal, t.modulos_habilitados,
                            ut.permissao
                     FROM tenants t
                     LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id
                         AND ut.usuario_id = ? AND ut.ativo = 1
                     WHERE t.status = 'ativo' AND t.slug = ?
                       AND (ut.usuario_id IS NOT NULL{$superAdminClause})
                     LIMIT 1"
                );
                if (!$stmt_slug) {
                    throw new RuntimeException('Não foi possível validar o vínculo do tenant.');
                }
                $stmt_slug->bind_param('iss', $dados_erp['id'], $tenant_slug, $permissao_global);
                if (!$stmt_slug->execute()) {
                    $stmt_slug->close();
                    throw new RuntimeException('Não foi possível validar o vínculo do tenant.');
                }
                $res_slug = $stmt_slug->get_result();
                $tenant = $res_slug ? $res_slug->fetch_assoc() : null;
                $stmt_slug->close();
                if (!$tenant) {
                    fechar_conexao($conexao);
                    invalidar_sessao_anterior_login_pg();
                    http_response_code(403);
                    retornar_json(false, 'Acesso ao condomínio não autorizado.');
                }
            } else {
                // Modo subdomínio MySQL legado: buscar tenant pelo slug.
                $tenant = carregarTenantPorSlug($conexao, $tenant_slug);
            }
        }

        if (!$tenant) {
            // Domínio único: usa somente um vínculo ativo inequívoco.
            // Ambiguidade exige contexto server-side; não devolver catálogo para seleção client-side.
            $stmt_t = $conexao->prepare(
                "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj,
                        t.plano, t.status, t.logo_url, t.email_principal,
                        t.modulos_habilitados, ut.permissao
                 FROM tenants t
                 INNER JOIN usuario_tenant ut ON ut.tenant_id = t.id
                 WHERE ut.usuario_id = ? AND t.status = 'ativo' AND ut.ativo = 1
                 ORDER BY t.nome_fantasia ASC"
            );
            if (!$stmt_t) {
                throw new RuntimeException('Não foi possível validar o tenant do usuário.');
            }
            $stmt_t->bind_param('i', $dados_erp['id']);
            if (!$stmt_t->execute()) {
                $stmt_t->close();
                throw new RuntimeException('Não foi possível validar o tenant do usuário.');
            }
            $res_t = $stmt_t->get_result();
            if (!$res_t) {
                $stmt_t->close();
                throw new RuntimeException('Não foi possível validar o tenant do usuário.');
            }
            $tenants_usuario = $res_t->fetch_all(MYSQLI_ASSOC);
            $stmt_t->close();

            if (count($tenants_usuario) === 0) {
                // Fallback: usuário não tem vínculo na usuario_tenant
                // Compatibilidade com instalações antigas — usa tenant_id = 1
                $tenant = null;
                if (!$isPostgres) {
                    $tenant = carregarTenantPorId($conexao, 1);
                }
                if ($tenant && !$isPostgres) {
                    // Criar vínculo automaticamente para não repetir este fallback
                    $sql_vinculo = \ERP\Database\DatabaseDialect::idempotentInsertSql(
                        $conexao,
                        'INSERT INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo) VALUES (?, 1, ?, 1)'
                    );
                    $stmt_ins = $conexao->prepare($sql_vinculo);
                    $stmt_ins->bind_param('is', $dados_erp['id'], $dados_erp['permissao']);
                    $stmt_ins->execute();
                    $stmt_ins->close();
                }
            } elseif (count($tenants_usuario) === 1) {
                // Apenas 1 tenant: usar automaticamente
                $tenant = $tenants_usuario[0];
                $dados_erp['permissao'] = erpPermissaoLoginEfetiva(
                    $permissao_global,
                    isset($tenants_usuario[0]['permissao']) ? (string)$tenants_usuario[0]['permissao'] : null
                );
            } else {
                if ($isPostgres) {
                    fechar_conexao($conexao);
                    invalidar_sessao_anterior_login_pg();
                    http_response_code(409);
                    retornar_json(false, 'O contexto do condomínio não pôde ser determinado para esta conta.');
                }
                // Compatibilidade: a resposta legado multi-tenant MySQL já existia na base.
                fechar_conexao($conexao);
                $lista = array_map(fn($t) => [
                    'id'        => $t['id'],
                    'slug'      => $t['slug'],
                    'nome'      => $t['nome_fantasia'] ?? $t['razao_social'],
                    'logo_url'  => $t['logo_url'],
                    'permissao' => $t['permissao']
                ], $tenants_usuario);
                echo json_encode([
                    'sucesso'  => true,
                    'tipo'     => 'selecionar_tenant',
                    'mensagem' => 'Selecione o condomínio que deseja acessar.',
                    'dados'    => [
                        'nome'    => $dados_erp['nome'],
                        'tenants' => $lista
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if ($tenant && $isPostgres) {
            $dados_erp['permissao'] = erpPermissaoLoginEfetiva(
                $permissao_global,
                isset($tenant['permissao']) ? (string)$tenant['permissao'] : null
            );
        }

        // 5b. Validar que o tenant foi encontrado
        if (!$tenant) {
            if ($isPostgres) {
                invalidar_sessao_anterior_login_pg();
            }
            registrar_log('LOGIN_FALHA', 'TENANT_NAO_DISPONIVEL', null, false);
            retornar_json(false, 'Condomínio não encontrado ou inativo. Verifique o endereço de acesso.');
        }

        // 5c. Criar sessão ERP com tenant_id
        iniciar_sessao_autenticada_limpa();
        $novaSessaoAutenticada = true;
        $_SESSION['usuario_id']           = $dados_erp['id'];
        $_SESSION['usuario_nome']         = $dados_erp['nome'];
        $_SESSION['usuario_email']        = $dados_erp['email'];
        $_SESSION['usuario_funcao']       = $dados_erp['funcao'];
        $_SESSION['usuario_departamento'] = $dados_erp['departamento'];
        $_SESSION['usuario_permissao']    = $dados_erp['permissao'];
        $_SESSION['usuario_logado']       = true;
        $_SESSION['login_timestamp']      = time();
        $_SESSION['sessao_inativa']       = (int)($dados_erp['sessao_inativa'] ?? 0);
        $_SESSION['tipo_usuario']         = 'erp';

        // ✅ MULTI-TENANT: Injetar dados do tenant na sessão
        injetarTenantNaSessao($tenant);

        registrar_log('LOGIN_ERP_SUCESSO', 'LOGIN_ERP_CONFIRMADO', null, false);

        fechar_conexao($conexao);

        echo json_encode([
            'sucesso'  => true,
            'tipo'     => 'erp',
            'mensagem' => 'Login realizado com sucesso!',
            'dados'    => [
                'nome'         => $dados_erp['nome'],
                'email'        => $dados_erp['email'],
                'permissao'    => $dados_erp['permissao'],
                'funcao'       => $dados_erp['funcao'],
                'tenant_id'    => $tenant['id'],
                'tenant_nome'  => $tenant['nome_fantasia'] ?? $tenant['razao_social'],
                'tenant_slug'  => $tenant['slug'],
                'is_super_admin' => ($dados_erp['permissao'] === 'super_admin'),
                'redirect'     => ($dados_erp['permissao'] === 'super_admin')
                    ? '/frontend/layout-base.html?page=superadmin'
                    : '/frontend/layout-base.html?page=dashboard'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── 6. AUTENTICAÇÃO MORADOR ─────────────────────────────────────────
    if ($tipo_final === 'morador') {
        if (\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
            http_response_code(503);
            retornar_json(false, 'O login do Portal do Morador ainda não foi homologado para este driver.');
        }
        iniciar_sessao_autenticada_limpa();
        $novaSessaoAutenticada = true;
        $token     = bin2hex(random_bytes(32));
        $tokenPortalCriado = $token;
        $moradorTokenCriadoId = (int)$dados_morador['id'];
        $expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Resolver tenant do morador pelo tenant_id da tabela moradores
        $tenant_morador = null;
        $stmt_tm = $conexao->prepare(
            "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.logo_url, t.plano
             FROM tenants t
             INNER JOIN moradores m ON m.tenant_id = t.id
             WHERE m.id = ? AND t.status = 'ativo'
             LIMIT 1"
        );
        if (!$stmt_tm) throw new RuntimeException('Não foi possível validar o tenant do morador.');
        $stmt_tm->bind_param('i', $dados_morador['id']);
        if (!$stmt_tm->execute()) {
            $stmt_tm->close();
            throw new RuntimeException('Não foi possível validar o tenant do morador.');
        }
        $res_tm = $stmt_tm->get_result();
        if ($res_tm && $res_tm->num_rows > 0) {
            $tenant_morador = $res_tm->fetch_assoc();
        }
        $stmt_tm->close();
        // Fallback: tenant 1
        if (!$tenant_morador) {
            $tenant_morador = carregarTenantPorId($conexao, 1);
        }

        // Limpar tokens antigos e inserir novo
        $sessoesPortalDisponivel = \ERP\Database\DatabaseDialect::tableExists($conexao, 'sessoes_portal');
        if (!$sessoesPortalDisponivel) {
            throw new RuntimeException('Schema de sessões do Portal indisponível.');
        }
        if (!$conexao->begin_transaction()) {
            throw new RuntimeException('Não foi possível iniciar a transação da sessão Portal.');
        }
        $portalTransactionStarted = true;
        $stmt_del = $conexao->prepare("DELETE FROM sessoes_portal WHERE morador_id = ?");
        if (!$stmt_del) throw new RuntimeException('Não foi possível preparar a limpeza de sessões do Portal.');
        $stmt_del->bind_param('i', $dados_morador['id']);
        if (!$stmt_del->execute()) {
            $stmt_del->close();
            throw new RuntimeException('Não foi possível limpar sessões anteriores do Portal.');
        }
        $stmt_del->close();

        $portalSessionHasTenant = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
        if ($portalSessionHasTenant && (int)($tenant_morador['id'] ?? 0) <= 0) {
            throw new RuntimeException('Não foi possível identificar o condomínio da sessão do Portal.');
        }
        $stmt_ins = $portalSessionHasTenant
            ? $conexao->prepare(
                "INSERT INTO sessoes_portal (morador_id, tenant_id, token, ip_address, user_agent, data_expiracao)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )
            : $conexao->prepare(
                "INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao)
                 VALUES (?, ?, ?, ?, ?)"
            );
        if (!$stmt_ins) throw new RuntimeException('Não foi possível preparar a sessão do Portal.');
        if ($portalSessionHasTenant) {
            $tenantSessaoPortalId = (int)$tenant_morador['id'];
            $stmt_ins->bind_param('iissss', $dados_morador['id'], $tenantSessaoPortalId, $token, $ip, $ua, $expiracao);
        } else {
            $stmt_ins->bind_param('issss', $dados_morador['id'], $token, $ip, $ua, $expiracao);
        }
        if (!$stmt_ins->execute()) {
            $stmt_ins->close();
            throw new RuntimeException('Não foi possível persistir a sessão do Portal.');
        }
        $stmt_ins->close();

        $stmt_upd = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW() WHERE id = ?");
        if (!$stmt_upd) throw new RuntimeException('Não foi possível preparar o registro do último acesso.');
        $stmt_upd->bind_param('i', $dados_morador['id']);
        if (!$stmt_upd->execute()) {
            $stmt_upd->close();
            throw new RuntimeException('Não foi possível registrar o último acesso.');
        }
        $stmt_upd->close();

        // Sessão PHP do morador
        $_SESSION['morador_id']      = $dados_morador['id'];
        $_SESSION['morador_nome']    = $dados_morador['nome'];
        $_SESSION['morador_email']   = $dados_morador['email'];
        $_SESSION['morador_cpf']     = $dados_morador['cpf'];
        $_SESSION['morador_unidade'] = $dados_morador['unidade'];
        $_SESSION['morador_logado']  = true;
        $_SESSION['login_timestamp'] = time();
        $_SESSION['tipo_usuario']    = 'morador';
        $_SESSION['morador_portal_token'] = $token;

        // ✅ MULTI-TENANT: Injetar tenant do morador
        if ($tenant_morador) {
            injetarTenantNaSessao($tenant_morador);
        }

        $log_tipo = $dados_morador['senha_temporaria'] ? 'SENHA_TEMPORARIA_LOGIN' : 'LOGIN_MORADOR_SUCESSO';
        $respostaPortal = json_encode([
            'sucesso'  => true,
            'tipo'     => 'morador',
            'mensagem' => 'Login realizado com sucesso!',
            'dados'    => [
                'token'            => $token,
                'morador_id'       => $dados_morador['id'],
                'nome'             => $dados_morador['nome'],
                'email'            => $dados_morador['email'],
                'unidade'          => $dados_morador['unidade'],
                'senha_temporaria' => $dados_morador['senha_temporaria'],
                'redirect'         => '/frontend/portal_morador.html'
            ]
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($respostaPortal)) throw new RuntimeException('Não foi possível gerar a resposta do Portal.');
        if (!$conexao->commit()) throw new RuntimeException('Não foi possível confirmar a transação da sessão Portal.');
        $portalTransactionCommitted = true;
        $portalTransactionStarted = false;
        try {
            registrar_log($log_tipo, 'LOGIN_PORTAL_CONFIRMADO', null, false);
        } catch (Throwable) {
            error_log('[api_verificar_tipo_login] Falha na auditoria final do Portal.');
        }
        try {
            fechar_conexao($conexao);
        } catch (Throwable) {
            error_log('[api_verificar_tipo_login] Falha ao fechar conexão do Portal.');
        }
        $conexao = null;
        echo $respostaPortal;
        exit;
    }

} catch (Throwable $e) {
    if ($portalTransactionCommitted && isset($respostaPortal) && is_string($respostaPortal)) {
        if (isset($conexao) && $conexao !== null) {
            try {
                fechar_conexao($conexao);
            } catch (Throwable) {
                try { error_log('[api_verificar_tipo_login] Falha ao fechar conexão após commit Portal.'); } catch (Throwable) {}
            }
        }
        echo $respostaPortal;
        exit;
    }
    try { error_log('[api_verificar_tipo_login] Falha interna ao processar login.'); } catch (Throwable) {}
    if ($portalTransactionStarted && isset($conexao) && $conexao !== null) {
        try {
            if (!$conexao->rollback()) {
                try { error_log('[api_verificar_tipo_login] Falha ao reverter transação Portal.'); } catch (Throwable) {}
            }
        } catch (Throwable) {
            try { error_log('[api_verificar_tipo_login] Falha ao reverter transação Portal.'); } catch (Throwable) {}
        }
        $portalTransactionStarted = false;
    }
    if ($tokenPortalCriado !== null && $moradorTokenCriadoId !== null) {
        $conexaoRevogacao = null;
        try {
            $conexaoRevogacao = conectar_banco();
            $stmt_revogar = $conexaoRevogacao->prepare('DELETE FROM sessoes_portal WHERE morador_id = ? AND token = ?');
            if (!$stmt_revogar) throw new RuntimeException('Não foi possível preparar revogação do token Portal.');
            $stmt_revogar->bind_param('is', $moradorTokenCriadoId, $tokenPortalCriado);
            if (!$stmt_revogar->execute()) throw new RuntimeException('Não foi possível revogar o token Portal.');
            $stmt_revogar->close();
            fechar_conexao($conexaoRevogacao);
        } catch (Throwable) {
            try { error_log('[api_verificar_tipo_login] Falha ao revogar sessão parcial do Portal.'); } catch (Throwable) {}
            if ($conexaoRevogacao !== null) {
                try { fechar_conexao($conexaoRevogacao); } catch (Throwable) {}
            }
        }
    }
    if ($novaSessaoAutenticada && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
        session_write_close();
    } elseif (($isPostgres ?? false) && !empty($encontrou_erp)) {
        invalidar_sessao_anterior_login_pg();
    }
    if (isset($conexao) && $conexao !== null) {
        try {
            fechar_conexao($conexao);
        } catch (Throwable) {
            try { error_log('[api_verificar_tipo_login] Falha ao fechar conexão após login incompleto.'); } catch (Throwable) {}
        }
    }
    if (defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql') http_response_code(503);
    retornar_json(false, 'Erro ao processar login. Tente novamente.');
}
?>
