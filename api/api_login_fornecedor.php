<?php
// ob_start() captura qualquer output acidental (warnings, notices, BOM)
// antes dos headers JSON — evita 'Unexpected non-whitespace character'
ob_start();

/**
 * =====================================================
 * API DE LOGIN DO FORNECEDOR
 * =====================================================
 * CORREÇÃO CRÍTICA: session_start() DEVE ser a primeira
 * chamada, antes de qualquer require_once, para garantir
 * que o cookie de sessão seja enviado corretamente.
 *
 * BUG ANTERIOR: auth_loop_detector.php chamava session_start()
 * no construtor __construct(), ANTES do session_start() do
 * bloco de login — criando duas sessões diferentes e fazendo
 * os dados de $_SESSION serem perdidos após o redirect.
 * =====================================================
 */

// ✅ SESSÃO CENTRALIZADA: session_helper garante path='/' e configurações
// idênticas em TODAS as APIs do fornecedor, evitando que o session_id
// mude entre requisições e destrua a sessão recém-criada.
require_once 'session_helper.php';
iniciar_sessao_fornecedor();   // session_set_cookie_params + session_start

require_once 'config.php';
require_once 'auth_helper.php';

// CORS centralizado (obrigatório com credentials: include)
configurar_cors_fornecedor();
header('Content-Type: application/json; charset=utf-8');

$acao   = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

if ($acao === 'registrar_erro' && $metodo === 'POST') {
    $email   = trim($_POST['email'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? 'Erro de login do cliente');
    $contexto = trim($_POST['contexto'] ?? 'login_fornecedor');
    $dados    = $_POST['dados'] ?? null;

    log_fornecedor('LOGIN_CLIENT_ERROR', 'Erro de login reportado pelo cliente: ' . $mensagem, $email ?: null, [
        'contexto'   => $contexto,
        'dados'      => $dados,
        'session_id' => session_id(),
    ]);

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Erro registrado para auditoria'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================
// FUNÇÃO CENTRALIZADA DE LOG DE DEBUG DO FORNECEDOR
// =====================================================
/**
 * Registra log detalhado de debug para todas as operações do fornecedor.
 * Grava em logs_sistema (banco) E em arquivo físico para redundância.
 *
 * @param string      $tipo      Tipo do evento (LOGIN_TENTATIVA, LOGIN_SUCESSO, LOGIN_ERRO, etc.)
 * @param string      $descricao Descrição detalhada do evento
 * @param string|null $usuario   E-mail ou identificador do fornecedor
 * @param array       $extra     Dados extras de debug (hash_prefix, session_id, etc.)
 */
function log_fornecedor(string $tipo, string $descricao, ?string $usuario = null, array $extra = []): void {
    $ip         = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
    $session_id = session_id() ?: 'sem-sessao';
    $timestamp  = date('Y-m-d H:i:s');
    $url        = $_SERVER['REQUEST_URI']     ?? '';

    // --- 1. Gravar no banco de dados (logs_sistema) ---
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$conn->connect_error) {
            $conn->set_charset('utf8mb4');

            // Descrição enriquecida com dados de debug
            $desc_completa = $descricao;
            if (!empty($extra)) {
                $desc_completa .= ' | DEBUG: ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
            }
            $desc_completa .= ' | UA: ' . substr($user_agent, 0, 120);
            $desc_completa .= ' | SID: ' . $session_id;

            $stmt = $conn->prepare(
                "INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora) VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('sssss', $tipo, $desc_completa, $usuario, $ip, $timestamp);
                $stmt->execute();
                $stmt->close();
            }
            $conn->close();
        }
    } catch (Exception $e) {
        error_log('[log_fornecedor] Falha ao gravar no banco: ' . $e->getMessage());
    }

    // --- 2. Gravar em arquivo físico (redundância) ---
    $log_dir  = '/var/log/erp_auth/';
    $log_file = $log_dir . 'fornecedor_' . date('Y-m-d') . '.log';

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $linha = json_encode([
        'timestamp'  => $timestamp,
        'tipo'       => $tipo,
        'usuario'    => $usuario,
        'ip'         => $ip,
        'session_id' => $session_id,
        'descricao'  => $descricao,
        'extra'      => $extra,
        'user_agent' => $user_agent,
        'url'        => $url,
    ], JSON_UNESCAPED_UNICODE) . "\n";

    @file_put_contents($log_file, $linha, FILE_APPEND | LOCK_EX);
}

// =====================================================
// CONTROLE DE TENTATIVAS FALHAS (sem auth_loop_detector)
// =====================================================
function verificar_ip_bloqueado_fornecedor(string $ip): bool {
    $arquivo = '/var/log/erp_auth/bloqueados_fornecedor.txt';
    if (!file_exists($arquivo)) return false;

    $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$linhas) return false;

    foreach ($linhas as $linha) {
        $b = json_decode($linha, true);
        if (!$b) continue;
        if ($b['ip'] === $ip && time() < ($b['expiracao'] ?? 0)) {
            return true;
        }
    }
    return false;
}

function bloquear_ip_fornecedor(string $ip): void {
    $log_dir = '/var/log/erp_auth/';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

    $arquivo  = $log_dir . 'bloqueados_fornecedor.txt';
    $bloqueio = json_encode([
        'ip'        => $ip,
        'timestamp' => time(),
        'expiracao' => time() + 300, // 5 minutos
    ]) . "\n";
    @file_put_contents($arquivo, $bloqueio, FILE_APPEND | LOCK_EX);
}

function registrar_tentativa_falha_fornecedor(string $email, string $ip, string $motivo): void {
    $chave = "falhas_fornecedor_{$ip}";
    $_SESSION[$chave] = ($_SESSION[$chave] ?? 0) + 1;

    if ($_SESSION[$chave] >= 5) {
        bloquear_ip_fornecedor($ip);
        log_fornecedor('IP_BLOQUEADO', "IP bloqueado após 5 tentativas falhas. Motivo: $motivo", $email, [
            'tentativas' => $_SESSION[$chave],
        ]);
    }
}

// =====================================================
// AÇÃO: LOGIN
// =====================================================
if ($acao === 'login' && $metodo === 'POST') {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    log_fornecedor('LOGIN_TENTATIVA', 'Tentativa de login iniciada', $email, [
        'session_id_antes' => session_id(),
        'session_status'   => session_status(),
    ]);

    try {
        // Verificar bloqueio de IP
        if (verificar_ip_bloqueado_fornecedor($ip)) {
            log_fornecedor('LOGIN_BLOQUEADO', 'IP bloqueado tentou login', $email, ['ip' => $ip]);
            http_response_code(429);
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'IP bloqueado temporariamente por múltiplas tentativas falhas. Tente novamente em 5 minutos.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Validar campos
        if (empty($email) || empty($senha)) {
            log_fornecedor('LOGIN_ERRO', 'Campos obrigatórios vazios', $email);
            registrar_tentativa_falha_fornecedor($email, $ip, 'campos vazios');
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail e senha são obrigatórios'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            log_fornecedor('LOGIN_ERRO', 'Formato de e-mail inválido', $email);
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conexao = conectar_banco();

        // Buscar fornecedor
        $stmt = $conexao->prepare(
            "SELECT id, email, senha, nome_estabelecimento, ativo, aprovado FROM fornecedores WHERE email = ? LIMIT 1"
        );
        if (!$stmt) {
            throw new Exception('Erro ao preparar consulta: ' . $conexao->error);
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            log_fornecedor('LOGIN_ERRO', 'E-mail não encontrado no banco', $email);
            registrar_tentativa_falha_fornecedor($email, $ip, 'email não encontrado');
            fechar_conexao($conexao);
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail ou senha incorretos'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fornecedor = $result->fetch_assoc();
        $stmt->close();

        log_fornecedor('LOGIN_DEBUG', 'Fornecedor encontrado no banco', $email, [
            'id'          => $fornecedor['id'],
            'ativo'       => $fornecedor['ativo'],
            'aprovado'    => $fornecedor['aprovado'],
            'hash_prefix' => substr($fornecedor['senha'] ?? '', 0, 7),
            'hash_length' => strlen($fornecedor['senha'] ?? ''),
        ]);

        // Verificar status ativo
        if (!(int)$fornecedor['ativo']) {
            log_fornecedor('LOGIN_ERRO', 'Fornecedor inativo tentou login', $email, ['id' => $fornecedor['id']]);
            registrar_tentativa_falha_fornecedor($email, $ip, 'conta inativa');
            fechar_conexao($conexao);
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Sua conta foi desativada. Contate o administrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar aprovação
        if (!(int)$fornecedor['aprovado']) {
            log_fornecedor('LOGIN_ERRO', 'Fornecedor não aprovado tentou login', $email, ['id' => $fornecedor['id']]);
            registrar_tentativa_falha_fornecedor($email, $ip, 'conta não aprovada');
            fechar_conexao($conexao);
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Sua conta ainda não foi aprovada. Aguarde a análise do administrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar senha com BCRYPT
        $senha_hash   = $fornecedor['senha'] ?? '';
        $senha_valida = password_verify($senha, $senha_hash);

        log_fornecedor('LOGIN_DEBUG', 'Verificação de senha executada', $email, [
            'hash_prefix'  => substr($senha_hash, 0, 7),
            'hash_length'  => strlen($senha_hash),
            'senha_valida' => $senha_valida ? 'SIM' : 'NÃO',
            'algo'         => password_get_info($senha_hash)['algoName'] ?? 'desconhecido',
        ]);

        if (!$senha_valida) {
            log_fornecedor('LOGIN_ERRO', 'Senha incorreta fornecida', $email, [
                'id'          => $fornecedor['id'],
                'hash_prefix' => substr($senha_hash, 0, 7),
            ]);
            registrar_tentativa_falha_fornecedor($email, $ip, 'senha incorreta');
            fechar_conexao($conexao);
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail ou senha incorretos'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ✅ LOGIN BEM-SUCEDIDO — gravar na sessão (já iniciada no topo do arquivo)
        $_SESSION['fornecedor_id']     = (int)$fornecedor['id'];
        $_SESSION['fornecedor_email']  = $fornecedor['email'];
        $_SESSION['fornecedor_nome']   = $fornecedor['nome_estabelecimento'];
        $_SESSION['fornecedor_logado'] = true;
        $_SESSION['login_time']        = time();

        // Limpar contador de falhas do IP
        unset($_SESSION["falhas_fornecedor_{$ip}"]);

        log_fornecedor('LOGIN_SUCESSO', 'Login realizado com sucesso', $email, [
            'id'         => $fornecedor['id'],
            'session_id' => session_id(),
            'sessao_gravada' => [
                'fornecedor_id'     => $_SESSION['fornecedor_id'],
                'fornecedor_logado' => $_SESSION['fornecedor_logado'],
            ],
        ]);

        fechar_conexao($conexao);

        http_response_code(200);
        echo json_encode([
            'sucesso'  => true,
            'mensagem' => 'Login realizado com sucesso!',
            'dados'    => [
                'id'    => $fornecedor['id'],
                'email' => $fornecedor['email'],
                'nome'  => $fornecedor['nome_estabelecimento'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        log_fornecedor('LOGIN_EXCECAO', 'Exceção não tratada: ' . $e->getMessage(), $email ?? null, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        fechar_conexao($conexao ?? null);
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor. Tente novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =====================================================
// AÇÃO: LOGOUT
// =====================================================
if ($acao === 'logout' && $metodo === 'POST') {
    $email = $_SESSION['fornecedor_email'] ?? 'desconhecido';
    log_fornecedor('LOGOUT', 'Logout realizado', $email, ['session_id' => session_id()]);
    session_unset();
    session_destroy();
    http_response_code(200);
    echo json_encode(['sucesso' => true, 'mensagem' => 'Logout realizado com sucesso'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================
// AÇÃO: VERIFICAR SESSÃO
// =====================================================
if ($acao === 'verificar_sessao' && $metodo === 'GET') {
    $logado = isset($_SESSION['fornecedor_logado']) && $_SESSION['fornecedor_logado'] === true
           && isset($_SESSION['fornecedor_id'])     && (int)$_SESSION['fornecedor_id'] > 0;

    log_fornecedor('SESSAO_VERIFICAR', $logado ? 'Sessão válida' : 'Sessão inválida/ausente',
        $_SESSION['fornecedor_email'] ?? null, [
            'session_id'        => session_id(),
            'fornecedor_logado' => $_SESSION['fornecedor_logado'] ?? 'não definido',
            'fornecedor_id'     => $_SESSION['fornecedor_id']     ?? 'não definido',
        ]
    );

    if (!$logado) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tempo = time() - ($_SESSION['login_time'] ?? time());
    http_response_code(200);
    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Sessão ativa',
        'dados'    => [
            'id'              => $_SESSION['fornecedor_id'],
            'email'           => $_SESSION['fornecedor_email'],
            'nome'            => $_SESSION['fornecedor_nome'],
            'tempo_decorrido' => $tempo,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================
// AÇÃO: OBTER DADOS
// =====================================================
if ($acao === 'obter_dados' && $metodo === 'GET') {
    if (!isset($_SESSION['fornecedor_logado']) || !$_SESSION['fornecedor_logado']) {
        log_fornecedor('OBTER_DADOS_ERRO', 'Tentativa sem autenticação', null, ['session_id' => session_id()]);
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $conexao = conectar_banco();
        $id      = (int)$_SESSION['fornecedor_id'];
        $stmt    = $conexao->prepare(
            "SELECT id, email, nome_estabelecimento, telefone, endereco, ramo_atividade_id, ativo, aprovado FROM fornecedores WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception('Fornecedor não encontrado');
        }

        $dados = $result->fetch_assoc();
        $stmt->close();
        fechar_conexao($conexao);

        http_response_code(200);
        echo json_encode(['sucesso' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        log_fornecedor('OBTER_DADOS_ERRO', $e->getMessage(), $_SESSION['fornecedor_email'] ?? null);
        fechar_conexao($conexao ?? null);
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =====================================================
// AÇÃO: RENOVAR SESSÃO
// =====================================================
if ($acao === 'renovar_sessao' && $metodo === 'POST') {
    if (!isset($_SESSION['fornecedor_logado']) || !$_SESSION['fornecedor_logado']) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['login_time'] = time();
    log_fornecedor('SESSAO_RENOVADA', 'Sessão renovada', $_SESSION['fornecedor_email'] ?? null);
    http_response_code(200);
    echo json_encode(['sucesso' => true, 'mensagem' => 'Sessão renovada'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================
// AÇÃO INVÁLIDA
// =====================================================
log_fornecedor('ACAO_INVALIDA', "Ação inválida: '$acao'", null, [
    'metodo' => $metodo,
    'get'    => $_GET,
    'post'   => array_keys($_POST),
]);
http_response_code(400);
echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida ou método não permitido'], JSON_UNESCAPED_UNICODE);
exit;
