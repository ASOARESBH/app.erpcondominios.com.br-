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
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed_origins = [
    'https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br',
    'https://erpcondominios.com.br','http://erpcondominios.com.br',
    'http://localhost','http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';

// Padrões: 60 min total, 0 = sem timeout de inatividade
define('SESSAO_TIMEOUT_TOTAL_MIN',       60);
define('SESSAO_TIMEOUT_INATIVIDADE_MIN',  0);
define('SESSAO_AVISO_EXPIRACAO_MIN',      5);

function log_sessao($msg, $nivel = 'INFO') {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/sessao.txt',
        '[' . date('Y-m-d H:i:s') . '] [' . $nivel . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX);
}

function obter_token_bearer() {
    $auth = '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (empty($auth)) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!empty($auth) && preg_match('/Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
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
    } catch (Exception $e) { log_sessao('Erro config_sessao: ' . $e->getMessage(), 'WARN'); }
    return $cfg;
}

function verificar_sessao_portal($conexao, $token, $cfg) {
    if (empty($token)) { log_sessao('Token vazio', 'WARN'); return null; }
    $stmt = $conexao->prepare("
        SELECT sp.id AS sessao_id, sp.morador_id, sp.data_login, sp.ativo,
               sp.ultimo_ativo, sp.timeout_total_min, sp.timeout_inatividade_min,
               m.nome AS morador_nome, m.email AS morador_email,
               m.unidade AS morador_unidade, m.cpf AS morador_cpf,
               m.telefone AS morador_telefone, m.celular AS morador_celular,
               COALESCE(m.sessao_personalizada, 0) AS sessao_personalizada,
               m.timeout_total_min AS m_timeout_total,
               m.timeout_inatividade_min AS m_timeout_inatividade
        FROM sessoes_portal sp
        INNER JOIN moradores m ON m.id = sp.morador_id
        WHERE sp.token = ? AND sp.ativo = 1 LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $sess = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sess) { log_sessao('Token nao encontrado: ' . substr($token,0,8), 'WARN'); return null; }

    $usa_custom = (int)($sess['sessao_personalizada'] ?? 0);
    if ($usa_custom && !empty($sess['m_timeout_total'])) {
        $t_total  = (int)$sess['m_timeout_total'];
        $t_inativ = (int)($sess['m_timeout_inatividade'] ?? $cfg['inatividade']);
    } else {
        $t_total  = (int)($sess['timeout_total_min']       ?: $cfg['total']);
        $t_inativ = (int)($sess['timeout_inatividade_min'] ?: $cfg['inatividade']);
    }

    $agora      = time();
    $login      = strtotime($sess['data_login']);
    $ativo      = $sess['ultimo_ativo'] ? strtotime($sess['ultimo_ativo']) : $login;
    $seg_login  = $agora - $login;
    $seg_inativ = $agora - $ativo;
    $lim_total  = $t_total * 60;

    // Verificar expiração por tempo total
    if ($lim_total > 0 && $seg_login >= $lim_total) {
        log_sessao("Expirou timeout total ({$t_total}min) — {$sess['morador_nome']}", 'INFO');
        $conexao->query("UPDATE sessoes_portal SET ativo=0 WHERE id={$sess['sessao_id']}");
        return ['expirou' => true, 'motivo' => 'timeout_total'];
    }

    // Verificar expiração por inatividade (0 = desabilitado)
    if ($t_inativ > 0) {
        $lim_inativ = $t_inativ * 60;
        if ($seg_inativ >= $lim_inativ) {
            log_sessao("Expirou inatividade ({$t_inativ}min) — {$sess['morador_nome']}", 'INFO');
            $conexao->query("UPDATE sessoes_portal SET ativo=0 WHERE id={$sess['sessao_id']}");
            return ['expirou' => true, 'motivo' => 'inatividade'];
        }
    }

    $conexao->query("UPDATE sessoes_portal SET ultimo_ativo=NOW() WHERE id={$sess['sessao_id']}");
    $rest_total  = $lim_total > 0 ? max(0, $lim_total - $seg_login) : 9999999;
    $rest_inativ = $t_inativ > 0  ? max(0, $t_inativ * 60 - $seg_inativ) : 9999999;
    $tempo_rest  = min($rest_total, $rest_inativ);
    log_sessao("Sessao valida — {$sess['morador_nome']} | restante:{$tempo_rest}s", 'INFO');

    return [
        'expirou' => false, 'sessao_id' => (int)$sess['sessao_id'],
        'morador_id' => (int)$sess['morador_id'],
        'nome' => $sess['morador_nome'], 'email' => $sess['morador_email'],
        'unidade' => $sess['morador_unidade'], 'cpf' => $sess['morador_cpf'],
        'telefone' => $sess['morador_telefone'], 'celular' => $sess['morador_celular'],
        'data_login' => $sess['data_login'],
        'timeout_total_min' => $t_total, 'timeout_inatividade_min' => $t_inativ,
        'tempo_restante_seg' => $tempo_rest,
        'restante_total_seg' => $rest_total, 'restante_inatividade_seg' => $rest_inativ,
    ];
}

function verificar_sessao_erp() {
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) return false;
    if (!isset($_SESSION['usuario_id'])     || empty($_SESSION['usuario_id']))        return false;

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
$config  = obter_config_timeout($conexao);

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = obter_token_bearer();

    if (!empty($token)) {
        $sessao = verificar_sessao_portal($conexao, $token, $config);
        if (!$sessao) retornar_erro_sessao('Token inválido ou sessão não encontrada');
        if ($sessao['expirou']) {
            $msg = $sessao['motivo'] === 'inatividade'
                ? 'Sessão encerrada por inatividade' : 'Sessão encerrada por tempo máximo atingido';
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
                'session_id' => $sessao['sessao_id'],
                'sessao_inativa' => $sessao_inativa_flag,
            ],
            'tempo_restante_segundos'  => $tr,
            'tempo_restante_formatado' => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
            'session_id' => $sessao['sessao_id'],
        ]);
    }

    if (verificar_sessao_erp()) {
        $sessao_inativa_flag = !empty($_SESSION['sessao_inativa']);
        $d  = time() - ($_SESSION['login_timestamp'] ?? time());
        $tr = $sessao_inativa_flag ? null : max(0, SESSAO_TIMEOUT_TOTAL_MIN * 60 - $d);
        $mm = $tr !== null ? floor($tr/60) : 0;
        $ss = $tr !== null ? ($tr % 60)    : 0;

        // Buscar sessao_inativa atualizado do banco (pode ter sido alterado pelo admin)
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        if ($uid > 0) {
            $stmt_u = $conexao->prepare("SELECT sessao_inativa FROM usuarios WHERE id = ? LIMIT 1");
            if ($stmt_u) {
                $stmt_u->bind_param('i', $uid);
                $stmt_u->execute();
                $row_u = $stmt_u->get_result()->fetch_assoc();
                $stmt_u->close();
                if ($row_u) {
                    $sessao_inativa_flag = (bool)$row_u['sessao_inativa'];
                    // Atualizar sessão PHP para refletir mudança
                    $_SESSION['sessao_inativa'] = $sessao_inativa_flag ? 1 : 0;
                    if ($sessao_inativa_flag) $tr = null;
                }
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
            'sessao' => [
                'tempo_restante'         => $tr,
                'tempo_formatado'        => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
                'timeout_total_min'      => SESSAO_TIMEOUT_TOTAL_MIN,
                'timeout_inatividade_min'=> SESSAO_TIMEOUT_INATIVIDADE_MIN,
                'aviso_expiracao_min'    => SESSAO_AVISO_EXPIRACAO_MIN,
                'session_id'             => session_id(),
                'sessao_inativa'         => $sessao_inativa_flag,
            ],
            'tempo_restante_segundos'  => $tr,
            'tempo_restante_formatado' => $tr !== null ? sprintf('%02d:%02d', $mm, $ss) : '∞',
            'session_id' => session_id(),
        ]);
    }

    retornar_erro_sessao('Nenhuma sessão ativa encontrada');
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao  = trim($_POST['acao'] ?? '');
    $token = obter_token_bearer();
    if (empty($acao)) retornar_erro_sessao('Ação não especificada', 400);

    if ($acao === 'renovar' || $acao === 'ping') {
        if (!empty($token)) {
            $stmt = $conexao->prepare("UPDATE sessoes_portal SET ultimo_ativo=NOW() WHERE token=? AND ativo=1");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $af = $stmt->affected_rows; $stmt->close();
            if ($acao === 'renovar' && $af === 0) retornar_erro_sessao('Sessão não encontrada para renovação');
            log_sessao("Sessao renovada/ping — token: " . substr($token,0,8), 'INFO');
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
            $stmt = $conexao->prepare("UPDATE sessoes_portal SET ativo=0 WHERE token=?");
            $stmt->bind_param('s', $token); $stmt->execute(); $stmt->close();
            log_sessao("Logout — token: " . substr($token,0,8), 'INFO');
        }
        if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION=[]; session_destroy(); }
        retornar_sucesso_sessao(['mensagem'=>'Logout realizado com sucesso']);
    }

    retornar_erro_sessao('Ação não reconhecida: ' . htmlspecialchars($acao), 400);
}

http_response_code(405);
echo json_encode(['sucesso'=>false,'mensagem'=>'Método não permitido.'], JSON_UNESCAPED_UNICODE);
?>
