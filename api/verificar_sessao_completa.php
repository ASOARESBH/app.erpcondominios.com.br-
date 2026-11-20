<?php
// =====================================================
// VERIFICAÇÃO DE SESSÃO COMPLETA E CENTRALIZADA v3.1
// =====================================================
// CORREÇÕES v3.1 (2026-05-13):
// - Timeout padrão: 60min total, sem inatividade (0 = desabilitado)
// - Suporte a sessao_inativa: se ativo, nunca faz logout automático
// - Busca sessao_inativa da tabela usuarios (ERP) e moradores (portal)
// - Retorna sessao_inativa no JSON para o frontend
//
// CORREÇÕES v3.0 (2026-05-10):
// - Usa token Bearer da tabela sessoes_portal (portal do morador)
//   em vez de $_SESSION (que nunca era preenchido pelo login do portal)
// - Suporte a timeout personalizado por usuário (admin configura)
// - Controle de inatividade: atualiza ultimo_ativo a cada verificação
// - Retorna dados do morador (nome, unidade, etc.) para o menu superior
// - Log de debug em logs/sessao.txt
// =====================================================
ob_start();
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ob_end_clean();

// Garantir JSON válido mesmo em erros fatais — NUNCA retornar HTTP 500
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql' ? 503 : 200);
        }
        echo json_encode([
            'sucesso' => false,
            'sessao_ativa' => false,
            'mensagem' => 'Erro interno — sessão não verificada',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json; charset=utf-8');
$allowed_origins = [
    'https://app.erpcondominios.com.br','http://app.erpcondominios.com.br',
    'https://erpcondominios.com.br','http://erpcondominios.com.br',
    'http://localhost','http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://app.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/rbac_helper.php';
require_once __DIR__ . '/db/DatabaseDialect.php';

// Padrões: 60 min total, 0 = sem timeout de inatividade
define('SESSAO_TIMEOUT_TOTAL_MIN',       60);
define('SESSAO_TIMEOUT_INATIVIDADE_MIN',  0);
define('SESSAO_AVISO_EXPIRACAO_MIN',      5);

function log_sessao($msg, $nivel = 'INFO') {
    try {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/sessao.txt',
            '[' . date('Y-m-d H:i:s') . '] [' . $nivel . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { /* silenciar — log nunca deve quebrar a resposta */ }
}

function obter_token_bearer() {
    $auth = '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (empty($auth)) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!empty($auth) && preg_match('/Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    if (defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql') return '';
    if (!empty($_COOKIE['portal_token'])) return trim($_COOKIE['portal_token']);
    return trim($_POST['token'] ?? $_GET['token'] ?? '');
}

function obter_config_timeout($conexao) {
    $cfg = ['total' => SESSAO_TIMEOUT_TOTAL_MIN, 'inatividade' => SESSAO_TIMEOUT_INATIVIDADE_MIN, 'aviso' => SESSAO_AVISO_EXPIRACAO_MIN];
    try {
        $stmt = $conexao->prepare("SELECT chave, valor FROM config_sessao WHERE chave IN ('timeout_total_min','timeout_inatividade_min','aviso_expiracao_min')");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if ($row['chave'] === 'timeout_total_min')       $cfg['total']       = (int)$row['valor'];
                if ($row['chave'] === 'timeout_inatividade_min') $cfg['inatividade'] = (int)$row['valor'];
                if ($row['chave'] === 'aviso_expiracao_min')     $cfg['aviso']       = (int)$row['valor'];
            }
            $stmt->close();
        }
    } catch (Throwable $e) { log_sessao('Falha ao carregar configuração de sessão.', 'WARN'); }
    return $cfg;
}

function verificar_sessao_portal($conexao, $token, $cfg) {
    if (empty($token)) { log_sessao('Token vazio', 'WARN'); return null; }
    try {
        $isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
        if (!\ERP\Database\DatabaseDialect::tableExists($conexao, 'sessoes_portal')) return null;
        $sessionHasTenant = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
        $residentHasTenant = \ERP\Database\DatabaseDialect::columnExists($conexao, 'moradores', 'tenant_id');
        if ($isPostgres && (!$sessionHasTenant || !$residentHasTenant
            || !\ERP\Database\DatabaseDialect::tableExists($conexao, 'tenants'))) {
            log_sessao('Schema tenant do Portal indisponível.', 'WARN');
            return null;
        }
        $tenantJoin = $residentHasTenant ? " INNER JOIN tenants t ON t.id=m.tenant_id AND t.status='ativo'" : '';
        $tenantSelect = $residentHasTenant ? ', m.tenant_id AS morador_tenant_id' : ', 0 AS morador_tenant_id';
        $sessionTenantSelect = $sessionHasTenant ? ', sp.tenant_id AS sessao_tenant_id' : ', 0 AS sessao_tenant_id';
        $tenantMatch = ($sessionHasTenant && $residentHasTenant) ? ' AND sp.tenant_id=m.tenant_id' : '';
        $stmt = $conexao->prepare(
            "SELECT sp.id AS sessao_id, sp.morador_id, sp.data_login, sp.data_expiracao, sp.ativo,
                    sp.ultimo_ativo, sp.timeout_total_min, sp.timeout_inatividade_min,
                    m.nome AS morador_nome, m.email AS morador_email,
                    m.unidade AS morador_unidade, m.cpf AS morador_cpf,
                    m.telefone AS morador_telefone, m.celular AS morador_celular,
                    COALESCE(m.sessao_personalizada, 0) AS sessao_personalizada,
                    m.timeout_total_min AS m_timeout_total,
                    m.timeout_inatividade_min AS m_timeout_inatividade{$tenantSelect}{$sessionTenantSelect}
             FROM sessoes_portal sp INNER JOIN moradores m ON m.id=sp.morador_id{$tenantJoin}
             WHERE sp.token=? AND sp.ativo=1 AND m.ativo=1{$tenantMatch} LIMIT 1"
        );
        if (!$stmt || !$stmt->bind_param('s', $token) || !$stmt->execute()) {
            if ($stmt) $stmt->close();
            log_sessao('Falha ao consultar sessão do Portal.', 'WARN');
            return null;
        }
        $result = $stmt->get_result();
        $sess = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $stmt->close();
        if (!$sess) { log_sessao('Sessão do Portal não encontrada.', 'WARN'); return null; }

        $tenantId = (int)($sess['morador_tenant_id'] ?? 0);
        if ($isPostgres && ($tenantId <= 0 || (int)($sess['sessao_tenant_id'] ?? 0) !== $tenantId)) return null;
        if (empty($sess['data_expiracao']) || strtotime((string)$sess['data_expiracao']) <= time()) {
            $expire = $sessionHasTenant
                ? $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE id=? AND token=? AND morador_id=? AND tenant_id=? AND ativo=1')
                : $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE id=? AND token=? AND morador_id=? AND ativo=1');
            if (!$expire) return null;
            if ($sessionHasTenant) $expire->bind_param('isii', $sess['sessao_id'], $token, $sess['morador_id'], $tenantId);
            else $expire->bind_param('isi', $sess['sessao_id'], $token, $sess['morador_id']);
            $expiredOk = $expire->execute() && $expire->affected_rows > 0;
            $expire->close();
            return $expiredOk ? ['expirou' => true, 'motivo' => 'token_expirado'] : null;
        }

        $usa_custom = (int)($sess['sessao_personalizada'] ?? 0);
        if ($usa_custom && !empty($sess['m_timeout_total'])) {
            $t_total = (int)$sess['m_timeout_total'];
            $t_inativ = (int)($sess['m_timeout_inatividade'] ?? $cfg['inatividade']);
        } else {
            $t_total = (int)($sess['timeout_total_min'] ?: $cfg['total']);
            $t_inativ = (int)($sess['timeout_inatividade_min'] ?: $cfg['inatividade']);
        }
        $agora = time();
        $login = strtotime((string)$sess['data_login']);
        $ativo = $sess['ultimo_ativo'] ? strtotime((string)$sess['ultimo_ativo']) : $login;
        if ($login === false || $ativo === false) return null;
        $seg_login = $agora - $login;
        $seg_inativ = $agora - $ativo;
        $lim_total = $t_total * 60;
        $motivoExpiracao = ($lim_total > 0 && $seg_login >= $lim_total) ? 'timeout_total'
            : (($t_inativ > 0 && $seg_inativ >= $t_inativ * 60) ? 'inatividade' : null);
        if ($motivoExpiracao !== null) {
            $expire = $sessionHasTenant
                ? $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE id=? AND token=? AND morador_id=? AND tenant_id=? AND ativo=1')
                : $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE id=? AND token=? AND morador_id=? AND ativo=1');
            if (!$expire) return null;
            if ($sessionHasTenant) $expire->bind_param('isii', $sess['sessao_id'], $token, $sess['morador_id'], $tenantId);
            else $expire->bind_param('isi', $sess['sessao_id'], $token, $sess['morador_id']);
            $expiredOk = $expire->execute() && $expire->affected_rows > 0;
            $expire->close();
            return $expiredOk ? ['expirou' => true, 'motivo' => $motivoExpiracao] : null;
        }

        $updateSql = $sessionHasTenant
            ? 'UPDATE sessoes_portal SET ultimo_ativo=NOW() WHERE id=? AND token=? AND morador_id=? AND tenant_id=? AND ativo=1'
            : 'UPDATE sessoes_portal SET ultimo_ativo=NOW() WHERE id=? AND token=? AND morador_id=? AND ativo=1';
        $update = $conexao->prepare($updateSql);
        if (!$update) return null;
        if ($sessionHasTenant) $update->bind_param('isii', $sess['sessao_id'], $token, $sess['morador_id'], $tenantId);
        else $update->bind_param('isi', $sess['sessao_id'], $token, $sess['morador_id']);
        $updateExecuted = $update->execute();
        $updated = $updateExecuted && (!$isPostgres || $update->affected_rows > 0);
        $update->close();
        if (!$updated) return null;

        $rest_total = $lim_total > 0 ? max(0, $lim_total - $seg_login) : 9999999;
        $rest_inativ = $t_inativ > 0 ? max(0, $t_inativ * 60 - $seg_inativ) : 9999999;
        $tempo_rest = min($rest_total, $rest_inativ);
        log_sessao('Sessão Portal válida.', 'INFO');
        return [
            'expirou' => false, 'sessao_id' => (int)$sess['sessao_id'],
            'morador_id' => (int)$sess['morador_id'], 'tenant_id' => $tenantId,
            'nome' => $sess['morador_nome'], 'email' => $sess['morador_email'],
            'unidade' => $sess['morador_unidade'], 'cpf' => $sess['morador_cpf'],
            'telefone' => $sess['morador_telefone'], 'celular' => $sess['morador_celular'],
            'data_login' => $sess['data_login'], 'timeout_total_min' => $t_total,
            'timeout_inatividade_min' => $t_inativ, 'tempo_restante_seg' => $tempo_rest,
            'restante_total_seg' => $rest_total, 'restante_inatividade_seg' => $rest_inativ,
        ];
    } catch (Throwable $e) {
        log_sessao('Falha ao validar sessão do Portal.', 'WARN');
        return null;
    }
}

function verificar_sessao_erp() {
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) return false;
    // FIX CRITICO: usar is_numeric() em vez de empty() — empty(0) = TRUE em PHP!
    // usuario_id pode ser 0 (super_admin sem AUTO_INCREMENT), e empty(0) retornaria FALSE incorretamente.
    if (!isset($_SESSION['usuario_id']) || !is_numeric($_SESSION['usuario_id'])) return false;

    // Se sessao_inativa está ativa, nunca expira
    if (!empty($_SESSION['sessao_inativa'])) return true;

    if (isset($_SESSION['login_timestamp'])) {
        $d = time() - $_SESSION['login_timestamp'];
        if ($d > SESSAO_TIMEOUT_TOTAL_MIN * 60) return false;
        // Renovar timestamp a cada 5 min de atividade
        if ($d > 300) $_SESSION['login_timestamp'] = time();
    }
    return true;
}

function exigir_sessao_erp_postgres_valida($conexao) {
    if (!defined('ERP_DB_DRIVER') || ERP_DB_DRIVER !== 'pgsql' || !verificar_sessao_erp()) return;
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if ($uid < 0 || $tenantId <= 0) {
        $_SESSION = [];
        session_destroy();
        retornar_erro_sessao('A sessão não contém um contexto válido. Faça login novamente.', 401);
    }
    try {
        if (!usuarioTemAcessoTenantAtivo($conexao, $uid, $tenantId)) {
            $_SESSION = [];
            session_destroy();
            retornar_erro_sessao('O acesso a este condomínio foi revogado. Faça login novamente.', 403);
        }
        if (!rbacTabelasDisponiveis($conexao) || !rbacValidarSessaoAtual($conexao, $uid, $tenantId)) {
            $_SESSION = [];
            session_destroy();
            retornar_erro_sessao('A sessão RBAC não está mais ativa. Faça login novamente.', 401);
        }
    } catch (Throwable $e) {
        log_sessao('Falha ao validar tenant e sessão RBAC no PostgreSQL.', 'ERROR');
        retornar_erro_sessao('Não foi possível validar a sessão. Tente novamente mais tarde.', 503);
    }
}

function retornar_erro_sessao($msg, $code = 401) {
    http_response_code($code);
    echo json_encode(['sucesso'=>false,'sessao_ativa'=>false,'mensagem'=>$msg,'timestamp'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
    exit;
}
function retornar_sucesso_sessao($dados) {
    echo json_encode(array_merge(['sucesso'=>true,'timestamp'=>date('Y-m-d H:i:s')], $dados), JSON_UNESCAPED_UNICODE);
    exit;
}

$conexao = conectar_banco();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = obter_token_bearer();
    // Uma sessão ERP válida deve prevalecer sobre portal_token/Bearer residual.
    // O mesmo navegador pode já ter acessado o Portal do Morador; priorizar o
    // token nesse caso devolvia 401 ao SessionManager enquanto o AuthGuard,
    // baseado no cookie PHPSESSID, já havia autorizado o layout.
    $sessao_erp_valida = verificar_sessao_erp();
    $postgresSelecionado = defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql';
    if ($postgresSelecionado) exigir_sessao_erp_postgres_valida($conexao);
    if (!$postgresSelecionado && $sessao_erp_valida && !empty($_SESSION['tenant_id'])) {
        try {
            if (rbacTabelasDisponiveis($conexao)) {
                $uid_rbac = (int)($_SESSION['usuario_id'] ?? 0);
                if ($uid_rbac > 0 && !rbacValidarSessaoAtual($conexao, $uid_rbac, (int)$_SESSION['tenant_id'])) {
                    $_SESSION = [];
                    session_destroy();
                    $sessao_erp_valida = false;
                }
            }
        } catch (Throwable $e) {
            error_log('[sessao] Falha ao verificar a sessão RBAC.');
        }
    }

    if (!$sessao_erp_valida && !empty($token)) {
        $config = obter_config_timeout($conexao);
        $sessao = verificar_sessao_portal($conexao, $token, $config);
        if (!$sessao) retornar_erro_sessao('Token inválido ou sessão não encontrada');
        if (!empty($sessao['expirou'])) {
            $msg = $sessao['motivo'] === 'inatividade'
                ? 'Sessão encerrada por inatividade'
                : (($sessao['motivo'] ?? '') === 'token_expirado' ? 'Sessão encerrada por tempo máximo atingido' : 'Sessão encerrada por tempo máximo atingido');
            retornar_erro_sessao($msg);
        }
        $tr = $sessao['tempo_restante_seg'];
        // Se tempo_rest é 9999999, é sessão sem expiração
        $sessao_inativa_flag = ($tr >= 9999999);
        if ($sessao_inativa_flag) $tr = null; // null = ∞ no frontend
        $mm = $tr !== null ? floor($tr/60) : 0;
        $ss = $tr !== null ? ($tr % 60)    : 0;
        retornar_sucesso_sessao([
            'sessao_ativa' => true, 'tipo' => 'portal_morador',
            'usuario' => [
                'id' => $sessao['morador_id'], 'nome' => $sessao['nome'],
                'email' => $sessao['email'], 'unidade' => $sessao['unidade'],
                'cpf' => $sessao['cpf'], 'tipo' => 'morador',
            ],
            'sessao' => [
                'tempo_restante' => $tr,
                'tempo_formatado' => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
                'restante_total_seg' => $sessao['restante_total_seg'],
                'restante_inatividade_seg' => $sessao['restante_inatividade_seg'],
                'timeout_total_min' => $sessao['timeout_total_min'],
                'timeout_inatividade_min' => $sessao['timeout_inatividade_min'],
                'aviso_expiracao_min' => $config['aviso'],
                'sessao_inativa' => $sessao_inativa_flag,
            ],
            'tempo_restante_segundos'  => $tr,
            'tempo_restante_formatado' => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
        ]);
    }

    if ($sessao_erp_valida) {
        $sessao_inativa_flag = !empty($_SESSION['sessao_inativa']);
        $d  = time() - ($_SESSION['login_timestamp'] ?? time());
        $tr = $sessao_inativa_flag ? null : max(0, SESSAO_TIMEOUT_TOTAL_MIN * 60 - $d);
        $mm = $tr !== null ? floor($tr/60) : 0;
        $ss = $tr !== null ? ($tr % 60)    : 0;

        // Buscar sessao_inativa atualizado do banco — com try/catch para não quebrar
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        if ($uid >= 0) {
            try {
                $stmt_u = $conexao->prepare("SELECT sessao_inativa FROM usuarios WHERE id = ? LIMIT 1");
                if ($stmt_u) {
                    $stmt_u->bind_param('i', $uid);
                    $stmt_u->execute();
                    $row_u = $stmt_u->get_result()->fetch_assoc();
                    $stmt_u->close();
                    if ($row_u) {
                        $sessao_inativa_flag = (bool)$row_u['sessao_inativa'];
                        $_SESSION['sessao_inativa'] = $sessao_inativa_flag ? 1 : 0;
                        if ($sessao_inativa_flag) $tr = null;
                    }
                }
            } catch (Throwable $e) {
                log_sessao('Falha ao consultar estado de sessão ERP.', 'WARN');
                // Continuar com valor da sessão PHP — não quebrar
            }
        }

        retornar_sucesso_sessao([
            'sessao_ativa' => true, 'tipo' => 'erp_interno',
            'usuario' => [
                'id'          => $_SESSION['usuario_id']         ?? null,
                'nome'        => $_SESSION['usuario_nome']        ?? null,
                'email'       => $_SESSION['usuario_email']       ?? null,
                'funcao'      => $_SESSION['usuario_funcao']      ?? null,
                'departamento'=> $_SESSION['usuario_departamento']?? null,
                'permissao'   => $_SESSION['usuario_permissao']   ?? null,
                'tipo'        => 'erp',
            ],
            'tenant' => [
                'id'    => $_SESSION['tenant_id'] ?? null,
                'slug'  => $_SESSION['tenant_slug'] ?? null,
                'nome'  => $_SESSION['tenant_nome'] ?? null,
                'plano' => $_SESSION['tenant_plano'] ?? null,
            ],
            'sessao' => [
                'tempo_restante'         => $tr,
                'tempo_formatado'        => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
                'timeout_total_min'      => SESSAO_TIMEOUT_TOTAL_MIN,
                'timeout_inatividade_min'=> SESSAO_TIMEOUT_INATIVIDADE_MIN,
                'aviso_expiracao_min'    => SESSAO_AVISO_EXPIRACAO_MIN,
                'sessao_inativa'         => $sessao_inativa_flag,
            ],
            'tempo_restante_segundos'  => $tr,
            'tempo_restante_formatado' => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
        ]);
    }

    retornar_erro_sessao('Nenhuma sessão ativa encontrada');
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao  = trim($_POST['acao'] ?? '');
    $token = obter_token_bearer();
    if (empty($acao)) retornar_erro_sessao('Ação não especificada', 400);
    $postgresSelecionado = defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql';
    if ($postgresSelecionado && in_array($acao, ['renovar', 'ping'], true)) {
        exigir_sessao_erp_postgres_valida($conexao);
    }

    if ($acao === 'renovar' || $acao === 'ping') {
        if (!empty($token)) {
            $config = obter_config_timeout($conexao);
            $sessaoPortal = verificar_sessao_portal($conexao, $token, $config);
            if (!$sessaoPortal || !empty($sessaoPortal['expirou'])) retornar_erro_sessao('Sessão não encontrada para renovação');
            log_sessao('Sessão Portal renovada ou verificada.', 'INFO');
            $tr_renovado = $config['inatividade'] > 0 ? $config['inatividade'] * 60 : null;
            retornar_sucesso_sessao(['mensagem'=>'Sessão renovada','novo_ultimo_ativo'=>date('Y-m-d H:i:s'),'tempo_restante_segundos'=>$tr_renovado]);
        } elseif (verificar_sessao_erp()) {
            if (empty($_SESSION['sessao_inativa'])) {
                $_SESSION['login_timestamp'] = time();
            }
            retornar_sucesso_sessao(['mensagem'=>'Sessão ERP renovada','novo_timestamp'=>$_SESSION['login_timestamp'] ?? time()]);
        } else {
            retornar_erro_sessao('Nenhuma sessão para renovar');
        }
    }

    if ($acao === 'logout') {
        if (!empty($token)) {
            $config = obter_config_timeout($conexao);
            $sessaoPortal = verificar_sessao_portal($conexao, $token, $config);
            if (!$sessaoPortal || !empty($sessaoPortal['expirou'])) retornar_erro_sessao('Token inválido ou sessão não encontrada');
            $hasTenant = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
            $stmt = $hasTenant
                ? $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1')
                : $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND ativo=1');
            if (!$stmt) retornar_erro_sessao('Sessão não encontrada para encerramento');
            if ($hasTenant) $stmt->bind_param('sii', $token, $sessaoPortal['morador_id'], $sessaoPortal['tenant_id']);
            else $stmt->bind_param('si', $token, $sessaoPortal['morador_id']);
            $revoked = $stmt->execute() && $stmt->affected_rows > 0;
            $stmt->close();
            if (!$revoked) retornar_erro_sessao('Sessão não encontrada para encerramento');
            log_sessao('Logout do Portal realizado.', 'INFO');
        }
        if (rbacTabelasDisponiveis($conexao)) rbacEncerrarSessaoAtual($conexao, 'LOGOUT_USUARIO');
        if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION=[]; session_destroy(); }
        retornar_sucesso_sessao(['mensagem'=>'Logout realizado com sucesso']);
    }

    retornar_erro_sessao('Ação não reconhecida: ' . htmlspecialchars($acao), 400);
}

http_response_code(405);
echo json_encode(['sucesso'=>false,'mensagem'=>'Método não permitido.'], JSON_UNESCAPED_UNICODE);
?>
