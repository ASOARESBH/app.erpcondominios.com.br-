<?php
// =====================================================
// SISTEMA DE CONTROLE DE ACESSO - VALIDAÇÃO DE LOGIN MORADOR
// =====================================================

// Configurações de sessão ANTES de qualquer output
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas

// Iniciar sessão ANTES de incluir config.php
session_start();

function portal_morador_iniciar_sessao_autenticada(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
            session_write_close();
        }
        throw new RuntimeException('Não foi possível iniciar a sessão autenticada do Portal.');
    }
    $_SESSION = [];
}

// Incluir arquivo de configuração
require_once 'config.php';
require_once __DIR__ . '/db/DatabaseDialect.php';

// Incluir detector de loop de autenticação
require_once 'auth_loop_detector.php';
$detector = new AuthLoopDetector();
$portalSessaoAutenticada = false;
$tokenPortalCriado = null;
$tokenPortalPersistido = false;

// Verificar se IP está bloqueado
if ($detector->verificarIPBloqueado($_SERVER['REMOTE_ADDR'], 'morador')) {
    retornar_json(false, 'IP bloqueado temporariamente por múltiplas tentativas falhadas. Tente novamente em 5 minutos.');
}

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    retornar_json(false, 'Método não permitido');
}

// Receber dados do formulário
$cpf = isset($_POST['cpf']) ? trim($_POST['cpf']) : '';
$senha = isset($_POST['senha']) ? trim($_POST['senha']) : '';

// Remover formatação do CPF (deixar apenas números)
$cpf = preg_replace('/[^0-9]/', '', $cpf);

// Validar campos obrigatórios
if (empty($cpf) || empty($senha)) {
    retornar_json(false, 'Preencha todos os campos!');
}

// Validar CPF (11 dígitos)
if (strlen($cpf) !== 11) {
    retornar_json(false, 'CPF inválido!');
}

try {
    // Conectar ao banco de dados
    $conexao = conectar_banco();
    $isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
    $temTenantMorador = \ERP\Database\DatabaseDialect::columnExists($conexao, 'moradores', 'tenant_id');
    if ($isPostgres && !$temTenantMorador) {
        throw new RuntimeException('Schema de moradores sem tenant_id.');
    }
    $campoTenantMorador = $temTenantMorador ? ', tenant_id' : '';

    // Buscar morador removendo formatação do CPF no banco também.
    $stmt = $conexao->prepare("
        SELECT id, nome, cpf, senha, unidade, email, ativo$campoTenantMorador
        FROM moradores
        WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?
        LIMIT 2
    ");
    $stmt->bind_param("s", $cpf);
    if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar o cadastro do morador.');
    $resultado = $stmt->get_result();
    if (!$resultado) throw new RuntimeException('Não foi possível consultar o cadastro do morador.');

    if ($resultado->num_rows > 1) {
        $stmt->close();
        fechar_conexao($conexao);
        http_response_code(409);
        retornar_json(false, 'Não foi possível processar o login. Tente novamente.');
    }
    
    // Verificar se o morador existe
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        
        // Registrar tentativa de login falha
        registrar_log('LOGIN_MORADOR_FALHA', 'CREDENCIAL_NAO_RECONHECIDA', null, false);
        
        // Registrar tentativa no detector de loop
        // O detector legado persiste CPF, IP e identificador de sessão; não o invocar nesta rota.
        
        retornar_json(false, 'CPF ou senha incorretos!');
    }
    
    // Obter dados do morador
    $morador = $resultado->fetch_assoc();
    $stmt->close();
    $tenant_id = (int)($morador['tenant_id'] ?? 0);
    if ($temTenantMorador && $tenant_id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'Não foi possível identificar o condomínio deste morador. Entre em contato com a administração.');
    }
    if ($isPostgres && !$temTenantMorador) {
        fechar_conexao($conexao);
        retornar_json(false, 'Não foi possível identificar o condomínio deste morador. Entre em contato com a administração.');
    }
    if (!$temTenantMorador) $tenant_id = 1; // compatibilidade histórica MySQL.
    
    // Verificar se o morador está ativo
    if ($morador['ativo'] != 1) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com morador inativo
        registrar_log('LOGIN_MORADOR_FALHA', 'CONTA_INATIVA', null, false);
        
        retornar_json(false, 'Morador inativo. Entre em contato com a administração.');
    }
    
    // Verificar senha com suporte a SHA1 e BCRYPT
    $senha_valida = false;
    
    // 1. Tentar BCRYPT primeiro (para senhas já atualizadas ou novas)
    if (password_verify($senha, $morador['senha'])) {
        $senha_valida = true;
    }
    
    // 2. Se não funcionar com BCRYPT, tentar SHA1 (senhas antigas)
    if (!$senha_valida && strlen($morador['senha']) === 40) {
        // Hash SHA1 tem exatamente 40 caracteres hexadecimais
        $senha_sha1 = sha1($senha);
        if ($senha_sha1 === $morador['senha']) {
            $senha_valida = true;
            
            // Atualizar automaticamente para BCRYPT (mais seguro)
            $senha_bcrypt = password_hash($senha, PASSWORD_DEFAULT);
            if (!$isPostgres) {
                $stmt_update_senha = $temTenantMorador
                    ? $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ? AND tenant_id = ? AND ativo = 1")
                    : $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ? AND ativo = 1");
                if (!$stmt_update_senha) throw new RuntimeException('Não foi possível preparar atualização de credencial.');
                if ($temTenantMorador) $stmt_update_senha->bind_param("sii", $senha_bcrypt, $morador['id'], $tenant_id);
                else $stmt_update_senha->bind_param("si", $senha_bcrypt, $morador['id']);
                if (!$stmt_update_senha->execute()) {
                    $stmt_update_senha->close();
                    throw new RuntimeException('Não foi possível atualizar credencial.');
                }
                $stmt_update_senha->close();
            }
            
            // Log da atualização
            registrar_log('SENHA_ATUALIZADA', 'CREDENCIAL_LEGADA_ATUALIZADA', null, false);
        }
    }
    
    if (!$senha_valida) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com senha incorreta
        registrar_log('LOGIN_MORADOR_FALHA', 'CREDENCIAL_INVALIDA', null, false);
        
        // Registrar tentativa no detector de loop
        // O detector legado persiste CPF, IP e identificador de sessão; não o invocar nesta rota.
        
        retornar_json(false, 'CPF ou senha incorretos!');
    }
    
    // ========== CORREÇÃO: GERAR TOKEN PARA O PORTAL ==========
    
    // Gerar token único
    $token = bin2hex(random_bytes(32));
    $tokenPortalCriado = $token;
    $data_expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Persistir o Bearer; indisponibilidade do schema falha fechada.
    $tabela_existe = \ERP\Database\DatabaseDialect::tableExists($conexao, 'sessoes_portal');
    if (!$tabela_existe) throw new RuntimeException('Schema de sessões do Portal indisponível.');

    if ($tabela_existe) {
        $temTenantSessao = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
        if ($isPostgres && !$temTenantSessao) throw new RuntimeException('Schema de sessões do Portal sem tenant_id.');
        $tenantAtivo = null;
        $stmtAtivo = $temTenantMorador
            ? $conexao->prepare("SELECT t.id FROM moradores m INNER JOIN tenants t ON t.id=m.tenant_id WHERE m.id=? AND m.tenant_id=? AND m.ativo=1 AND t.status='ativo' LIMIT 1")
            : ($isPostgres ? false : $conexao->prepare("SELECT id FROM tenants WHERE id=1 AND status='ativo' LIMIT 1"));
        if (!$stmtAtivo) throw new RuntimeException('Não foi possível validar o tenant do morador.');
        if ($temTenantMorador) $stmtAtivo->bind_param('ii', $morador['id'], $tenant_id);
        if (!$stmtAtivo->execute()) { $stmtAtivo->close(); throw new RuntimeException('Não foi possível validar o tenant do morador.'); }
        $tenantAtivoResult = $stmtAtivo->get_result();
        $tenantAtivo = $tenantAtivoResult ? $tenantAtivoResult->fetch_assoc() : null;
        $stmtAtivo->close();
        if (!$tenantAtivo || (int)$tenantAtivo['id'] !== $tenant_id) throw new RuntimeException('Tenant ativo do morador indisponível.');

        if (!$conexao->begin_transaction()) throw new RuntimeException('Não foi possível iniciar transação da sessão Portal.');
        $portalTransactionStarted = true;

        // Limpar tokens antigos apenas no contexto do tenant, quando disponível.
        if ($temTenantSessao) {
            $stmt_limpar = $conexao->prepare("DELETE FROM sessoes_portal WHERE morador_id = ? AND tenant_id = ?");
            $stmt_limpar->bind_param("ii", $morador['id'], $tenant_id);
        } else {
            $stmt_limpar = $conexao->prepare("DELETE FROM sessoes_portal WHERE morador_id = ?");
            $stmt_limpar->bind_param("i", $morador['id']);
        }
        if (!$stmt_limpar->execute()) { $stmt_limpar->close(); throw new RuntimeException('Não foi possível substituir sessões anteriores.'); }
        $stmt_limpar->close();

        // Persistir explicitamente tenant_id para que o Bearer possa ser
        // revalidado por tenant nas APIs do Portal.
        if ($temTenantSessao) {
            $stmt_sessao = $conexao->prepare("INSERT INTO sessoes_portal (morador_id, tenant_id, token, ip_address, user_agent, data_expiracao) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_sessao->bind_param("iissss", $morador['id'], $tenant_id, $token, $ip_address, $user_agent, $data_expiracao);
        } else {
            $stmt_sessao = $conexao->prepare("INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao) VALUES (?, ?, ?, ?, ?)");
            $stmt_sessao->bind_param("issss", $morador['id'], $token, $ip_address, $user_agent, $data_expiracao);
        }
        if (!$stmt_sessao->execute()) { $stmt_sessao->close(); throw new RuntimeException('Não foi possível persistir a sessão Portal.'); }
        $tokenPortalPersistido = true;
        $stmt_sessao->close();
    }
    
    // Login bem-sucedido - criar sessão PHP também (compatibilidade)
    portal_morador_iniciar_sessao_autenticada();
    $portalSessaoAutenticada = true;
    $_SESSION['morador_id'] = $morador['id'];
    $_SESSION['morador_nome'] = $morador['nome'];
    $_SESSION['morador_cpf'] = $morador['cpf'];
    $_SESSION['morador_unidade'] = $morador['unidade'];
    $_SESSION['morador_email'] = $morador['email'];
    $_SESSION['morador_logado'] = true;
    $_SESSION['tenant_id'] = $tenant_id;
    $_SESSION['morador_portal_token'] = $token;
    $_SESSION['login_timestamp'] = time();
    $_SESSION['tipo_usuario'] = 'morador';
    
    // Atualizar último acesso do morador
    if ($temTenantMorador) {
        $stmt_update = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW(), data_atualizacao = NOW() WHERE id = ? AND tenant_id = ? AND ativo = 1");
        $stmt_update->bind_param("ii", $morador['id'], $tenant_id);
    } else {
        $stmt_update = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW(), data_atualizacao = NOW() WHERE id = ?");
        $stmt_update->bind_param("i", $morador['id']);
    }
    if (!$stmt_update->execute()) { $stmt_update->close(); throw new RuntimeException('Não foi possível atualizar último acesso.'); }
    $stmt_update->close();
    if (isset($portalTransactionStarted) && $portalTransactionStarted) {
        if (!$conexao->commit()) throw new RuntimeException('Não foi possível confirmar transação da sessão Portal.');
        $portalTransactionStarted = false;
    }
    
    fechar_conexao($conexao);
    
    // Registrar login bem-sucedido
    registrar_log('LOGIN_MORADOR_SUCESSO', 'LOGIN_PORTAL_CONFIRMADO', null, false);
    
    // Registrar tentativa bem-sucedida no detector de loop
    // O detector legado persiste dados pessoais e identificadores de sessão; a auditoria acima é agregada.
    
    // Retornar sucesso COM TOKEN
    retornar_json(true, 'Login realizado com sucesso!', array(
        'nome' => $morador['nome'],
        'unidade' => $morador['unidade'],
        'email' => $morador['email'],
        'token' => $token,
        'morador_id' => $morador['id']
    ));
    
} catch (Throwable $e) {
    if (!empty($portalTransactionStarted) && isset($conexao) && $conexao) {
        try { $conexao->rollback(); } catch (Throwable) {}
    }
    if (isset($conexao) && $conexao) {
        try { fechar_conexao($conexao); } catch (Throwable) {}
        $conexao = null;
    }
    if ($tokenPortalPersistido && $tokenPortalCriado !== null) {
        $conexaoRevogacao = null;
        try {
            $conexaoRevogacao = conectar_banco();
            $temTenantRevogacao = \ERP\Database\DatabaseDialect::columnExists($conexaoRevogacao, 'sessoes_portal', 'tenant_id');
            if ($isPostgres && !$temTenantRevogacao) throw new RuntimeException('PORTAL_SESSION_TENANT_SCHEMA_UNAVAILABLE');
            if ($temTenantRevogacao) {
                $stmtRevogacao = $conexaoRevogacao->prepare('DELETE FROM sessoes_portal WHERE token=? AND morador_id=? AND tenant_id=?');
                $moradorIdRevogacao = (int)$morador['id'];
                $tenantIdRevogacao = (int)$tenant_id;
                $stmtRevogacao->bind_param('sii', $tokenPortalCriado, $moradorIdRevogacao, $tenantIdRevogacao);
            } else {
                $stmtRevogacao = $conexaoRevogacao->prepare('DELETE FROM sessoes_portal WHERE token=? AND morador_id=?');
                $moradorIdRevogacao = (int)$morador['id'];
                $stmtRevogacao->bind_param('si', $tokenPortalCriado, $moradorIdRevogacao);
            }
            if (!$stmtRevogacao || !$stmtRevogacao->execute()) {
                if ($stmtRevogacao) $stmtRevogacao->close();
                throw new RuntimeException('PORTAL_SESSION_COMPENSATION_FAILED');
            }
            $stmtRevogacao->close();
        } catch (Throwable) {
            error_log('[validar_login_morador] Falha na revogação compensatória da sessão Portal.');
        } finally {
            if ($conexaoRevogacao) {
                try { fechar_conexao($conexaoRevogacao); } catch (Throwable) {}
            }
        }
    }
    if ($portalSessaoAutenticada && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
        session_write_close();
    }
    error_log('[validar_login_morador] Falha interna no login.');
    http_response_code(503);
    retornar_json(false, 'Erro ao processar login. Tente novamente.');
}
