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
 * Resposta:
 *   { "sucesso": true, "tipo": "erp|morador|ambos", "dados": {...} }
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

// Função auxiliar JSON local
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    registrar_log('LOGIN_FALHA', "Campo vazio: email='" . (empty($email) ? 'VAZIO' : 'ok') . "' ua='" . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "'");
    retornar_json(false, 'E-mail e senha são obrigatórios');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registrar_log('LOGIN_FALHA', "Email inválido: '{$email}'");
    retornar_json(false, 'E-mail inválido');
}

try {
    $conexao = conectar_banco();

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
            registrar_log('LOGIN_BLOQUEADO', "Conta inativa: {$email}", $usuario['nome']);
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
                if ($senha_valida_morador) {
                    $nova_senha = password_hash($senha, PASSWORD_BCRYPT);
                    $stmt_upd = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
                    $stmt_upd->bind_param('si', $nova_senha, $morador['id']);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                    registrar_log('SENHA_ATUALIZADA', "SHA1→BCRYPT: {$morador['nome']}", $morador['nome']);
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
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
        registrar_log('LOGIN_FALHA', "Login inválido: {$email} | ua={$ua}");
        retornar_json(false, 'E-mail ou senha incorretos!');
    }

    // ─── 4. Resolver tipo de login ────────────────────────────────────────
    $tipo_escolhido = isset($dados['tipo_escolhido']) ? trim($dados['tipo_escolhido']) : '';

    if ($encontrou_erp && $encontrou_morador && empty($tipo_escolhido)) {
        registrar_log('LOGIN_AMBOS', "Email em ambas as tabelas: {$email}");
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

        // 5a. Identificar tenant pelo subdomínio da requisição
        $tenant_slug = resolverTenantSlugDaUrl();
        $tenant = null;

        if ($tenant_slug) {
            // Modo subdomínio: buscar tenant pelo slug
            $tenant = carregarTenantPorSlug($conexao, $tenant_slug);
        }

        if (!$tenant) {
            // Modo domínio único: buscar o tenant vinculado ao usuário
            // Se o usuário tiver vínculo com apenas 1 tenant, usa automaticamente
            // Se tiver múltiplos, retorna lista para seleção
            $stmt_t = $conexao->prepare(
                "SELECT t.id, t.slug, t.razao_social, t.nome_fantasia, t.cnpj,
                        t.plano, t.status, t.logo_url, t.email_principal,
                        t.modulos_habilitados, ut.permissao
                 FROM tenants t
                 INNER JOIN usuario_tenant ut ON ut.tenant_id = t.id
                 WHERE ut.usuario_id = ? AND t.status = 'ativo' AND ut.ativo = 1
                 ORDER BY t.nome_fantasia ASC"
            );
            $stmt_t->bind_param('i', $dados_erp['id']);
            $stmt_t->execute();
            $res_t = $stmt_t->get_result();
            $tenants_usuario = $res_t->fetch_all(MYSQLI_ASSOC);
            $stmt_t->close();

            if (count($tenants_usuario) === 0) {
                // Fallback: usuário não tem vínculo na usuario_tenant
                // Compatibilidade com instalações antigas — usa tenant_id = 1
                $tenant = carregarTenantPorId($conexao, 1);
                if ($tenant) {
                    // Criar vínculo automaticamente para não repetir este fallback
                    $stmt_ins = $conexao->prepare(
                        "INSERT IGNORE INTO usuario_tenant (usuario_id, tenant_id, permissao, ativo)
                         VALUES (?, 1, ?, 1)"
                    );
                    $stmt_ins->bind_param('is', $dados_erp['id'], $dados_erp['permissao']);
                    $stmt_ins->execute();
                    $stmt_ins->close();
                }
            } elseif (count($tenants_usuario) === 1) {
                // Apenas 1 tenant: usar automaticamente
                $tenant = $tenants_usuario[0];
                // Atualizar permissão da sessão com a permissão do tenant
                if (!empty($tenants_usuario[0]['permissao'])) {
                    $dados_erp['permissao'] = $tenants_usuario[0]['permissao'];
                }
            } else {
                // Múltiplos tenants: retornar lista para seleção
                fechar_conexao($conexao);
                $lista = array_map(fn($t) => [
                    'id'           => $t['id'],
                    'slug'         => $t['slug'],
                    'nome'         => $t['nome_fantasia'] ?? $t['razao_social'],
                    'logo_url'     => $t['logo_url'],
                    'permissao'    => $t['permissao']
                ], $tenants_usuario);
                echo json_encode([
                    'sucesso'  => true,
                    'tipo'     => 'selecionar_tenant',
                    'mensagem' => 'Selecione o condomínio que deseja acessar.',
                    'dados'    => [
                        'nome'     => $dados_erp['nome'],
                        'tenants'  => $lista
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // 5b. Validar que o tenant foi encontrado
        if (!$tenant) {
            registrar_log('LOGIN_FALHA', "Tenant não encontrado para: {$email} | slug={$tenant_slug}");
            retornar_json(false, 'Condomínio não encontrado ou inativo. Verifique o endereço de acesso.');
        }

        // 5c. Criar sessão ERP com tenant_id
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

        session_regenerate_id(true);

        registrar_log('LOGIN_ERP_SUCESSO', "Login ERP: {$email} | tenant={$tenant['slug']}", $dados_erp['nome']);

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
                'redirect'     => '/frontend/layout-base.html?page=dashboard'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── 6. AUTENTICAÇÃO MORADOR ─────────────────────────────────────────
    if ($tipo_final === 'morador') {
        $token     = bin2hex(random_bytes(32));
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
        if ($stmt_tm) {
            $stmt_tm->bind_param('i', $dados_morador['id']);
            $stmt_tm->execute();
            $res_tm = $stmt_tm->get_result();
            if ($res_tm->num_rows > 0) {
                $tenant_morador = $res_tm->fetch_assoc();
            }
            $stmt_tm->close();
        }
        // Fallback: tenant 1
        if (!$tenant_morador) {
            $tenant_morador = carregarTenantPorId($conexao, 1);
        }

        // Limpar tokens antigos e inserir novo
        $chk = $conexao->query("SHOW TABLES LIKE 'sessoes_portal'");
        if ($chk && $chk->num_rows > 0) {
            $stmt_del = $conexao->prepare("DELETE FROM sessoes_portal WHERE morador_id = ?");
            $stmt_del->bind_param('i', $dados_morador['id']);
            $stmt_del->execute();
            $stmt_del->close();

            $stmt_ins = $conexao->prepare(
                "INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt_ins->bind_param('issss', $dados_morador['id'], $token, $ip, $ua, $expiracao);
            $stmt_ins->execute();
            $stmt_ins->close();
        }

        $stmt_upd = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW() WHERE id = ?");
        $stmt_upd->bind_param('i', $dados_morador['id']);
        $stmt_upd->execute();
        $stmt_upd->close();

        fechar_conexao($conexao);

        // Sessão PHP do morador
        $_SESSION['morador_id']      = $dados_morador['id'];
        $_SESSION['morador_nome']    = $dados_morador['nome'];
        $_SESSION['morador_email']   = $dados_morador['email'];
        $_SESSION['morador_cpf']     = $dados_morador['cpf'];
        $_SESSION['morador_unidade'] = $dados_morador['unidade'];
        $_SESSION['morador_logado']  = true;
        $_SESSION['login_timestamp'] = time();
        $_SESSION['tipo_usuario']    = 'morador';

        // ✅ MULTI-TENANT: Injetar tenant do morador
        if ($tenant_morador) {
            injetarTenantNaSessao($tenant_morador);
        }

        $log_tipo = $dados_morador['senha_temporaria'] ? 'SENHA_TEMPORARIA_LOGIN' : 'LOGIN_MORADOR_SUCESSO';
        registrar_log($log_tipo, "Login Portal: {$email}", $dados_morador['nome']);

        echo json_encode([
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
        exit;
    }

} catch (Exception $e) {
    error_log('[api_verificar_tipo_login] Erro: ' . $e->getMessage());
    retornar_json(false, 'Erro ao processar login. Tente novamente.');
}
?>
