<?php
// ob_start() captura qualquer output acidental (warnings, notices, BOM)
// antes dos headers JSON — evita 'Unexpected non-whitespace character'
ob_start();

/**
 * API de Gerenciamento de Sessão do Fornecedor
 *
 * Ações disponíveis:
 * - verificar: Verifica se fornecedor está logado
 * - dados: Retorna dados do fornecedor logado
 * - logout: Faz logout do fornecedor
 * - atualizar_perfil: Atualiza email/senha/telefone/endereco
 */

// ✅ SESSÃO CENTRALIZADA: mesma configuração do api_login_fornecedor.php
// Garante que o PHPSESSID seja lido corretamente (path='/', SameSite=Lax)
require_once 'session_helper.php';
iniciar_sessao_fornecedor();

require_once 'config.php';
require_once 'auth_helper.php';

// CORS centralizado
configurar_cors_fornecedor();
header('Content-Type: application/json; charset=utf-8');

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Função de log de debug do fornecedor (reutilizável em todas as APIs)
if (!function_exists('log_fornecedor')) {
    function log_fornecedor(string $tipo, string $descricao, ?string $usuario = null, array $extra = []): void {
        $ip         = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
        $session_id = session_id() ?: 'sem-sessao';
        $timestamp  = date('Y-m-d H:i:s');

        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                $desc_completa = $descricao;
                if (!empty($extra)) $desc_completa .= ' | DEBUG: ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
                $desc_completa .= ' | UA: ' . substr($user_agent, 0, 120) . ' | SID: ' . $session_id;
                $stmt = $conn->prepare("INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('sssss', $tipo, $desc_completa, $usuario, $ip, $timestamp);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->close();
            }
        } catch (Exception $e) {
            error_log('[log_fornecedor] ' . $e->getMessage());
        }

        $log_dir = '/var/log/erp_auth/';
        if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
        $linha = json_encode([
            'timestamp' => $timestamp, 'tipo' => $tipo, 'usuario' => $usuario,
            'ip' => $ip, 'session_id' => $session_id, 'descricao' => $descricao,
            'extra' => $extra, 'user_agent' => $user_agent,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($log_dir . 'fornecedor_' . date('Y-m-d') . '.log', $linha, FILE_APPEND | LOCK_EX);
    }
}

// Obter ação
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($acao) {
        case 'verificar':
            log_fornecedor('SESSAO_VERIFICAR', 'Verificação de sessão solicitada',
                $_SESSION['fornecedor_email'] ?? null, [
                    'session_id'        => session_id(),
                    'fornecedor_id'     => $_SESSION['fornecedor_id']     ?? 'não definido',
                    'fornecedor_logado' => $_SESSION['fornecedor_logado'] ?? 'não definido',
                ]);
            verificarSessao();
            break;

        case 'dados':
            log_fornecedor('SESSAO_DADOS', 'Dados do fornecedor solicitados',
                $_SESSION['fornecedor_email'] ?? null, ['session_id' => session_id()]);
            obterDadosFornecedor();
            break;

        case 'logout':
            log_fornecedor('LOGOUT', 'Logout solicitado',
                $_SESSION['fornecedor_email'] ?? null, ['session_id' => session_id()]);
            fazerLogout();
            break;

        case 'atualizar_perfil':
            log_fornecedor('PERFIL_ATUALIZAR', 'Atualização de perfil solicitada',
                $_SESSION['fornecedor_email'] ?? null, ['session_id' => session_id()]);
            atualizarPerfil();
            break;

        default:
            log_fornecedor('ACAO_INVALIDA', "Ação inválida na sessão: '$acao'",
                $_SESSION['fornecedor_email'] ?? null, [
                    'metodo'  => $_SERVER['REQUEST_METHOD'],
                    'get'     => $_GET,
                    'post'    => array_keys($_POST),
                ]);
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida ou não especificada'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    log_fornecedor('EXCECAO', 'Exceção não tratada na sessão: ' . $e->getMessage(),
        $_SESSION['fornecedor_email'] ?? null, [
            'acao' => $acao,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no servidor: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Verifica se fornecedor está logado
 */
function verificarSessao() {
    if (!isset($_SESSION['fornecedor_id'])) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'logado' => false,
            'mensagem' => 'Fornecedor não autenticado'
        ]);
        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'logado' => true,
        'fornecedor_id' => $_SESSION['fornecedor_id'],
        'email' => $_SESSION['fornecedor_email'] ?? null,
        'nome' => $_SESSION['fornecedor_nome'] ?? null
    ]);
}

/**
 * Obtém dados do fornecedor logado
 */
function obterDadosFornecedor() {
    if (!isset($_SESSION['fornecedor_id'])) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Fornecedor não autenticado'
        ]);
        exit;
    }

    $conn = conectar_banco();
    $fornecedor_id = $_SESSION['fornecedor_id'];

    // Preparar query
    $stmt = $conn->prepare("
        SELECT 
            f.id,
            f.cpf_cnpj,
            f.nome_estabelecimento,
            f.nome_responsavel,
            f.email,
            f.telefone,
            f.endereco,
            f.cidade,
            f.estado,
            f.cep,
            f.ramo_atividade_id,
            COALESCE(r.nome, 'N\u00e3o informado') AS ramo_atividade,
            f.data_cadastro,
            f.ativo,
            f.aprovado
        FROM fornecedores f
        LEFT JOIN ramos_atividade r ON r.id = f.ramo_atividade_id
        WHERE f.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao preparar query: ' . $conn->error
        ]);
        exit;
    }

    $stmt->bind_param('i', $fornecedor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Fornecedor não encontrado'
        ]);
        $stmt->close();
        exit;
    }

    $fornecedor = $result->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'sucesso' => true,
        'dados' => $fornecedor
    ]);
}

/**
 * Faz logout do fornecedor
 */
function fazerLogout() {
    // Registrar logout em logs (apenas se a conexão estiver disponível)
    if (isset($_SESSION['fornecedor_id']) && isset($GLOBALS['conn'])) {
        global $conn;
        
        // Verificar se a conexão é válida
        if ($conn !== null && $conn->ping()) {
            $fornecedor_id = $_SESSION['fornecedor_id'];
            $data_hora = date('Y-m-d H:i:s');
            
            // Registrar em logs_sistema se tabela existir
            // Usar try-catch para evitar erros se a tabela não existir
            try {
                $stmt = $conn->prepare("
                    INSERT INTO logs_sistema (tipo, descricao, usuario_id, data_hora)
                    VALUES ('logout', 'Fornecedor fez logout', ?, ?)
                ");
                
                if ($stmt) {
                    $stmt->bind_param('is', $fornecedor_id, $data_hora);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Exception $e) {
                // Silenciosamente ignora erros de log (não impede o logout)
                error_log("Erro ao registrar log de logout: " . $e->getMessage());
            }
        }
    }

    // Destruir sessão
    $_SESSION = [];
    
    // Se estiver usando cookies de sessão, limpe também
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
     session_destroy();
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Logout realizado com sucesso',
        'redirecionar' => 'login_fornecedor.html'
    ]);
}

/**
 * Atualiza dados editáveis do perfil do fornecedor logado
 * Campos editáveis: email, telefone, endereco, nome_responsavel
 * Troca de senha: requer senha_atual + nova_senha (mín. 6 chars)
 * Campos somente leitura (não alteráveis): cpf_cnpj, nome_estabelecimento, ramo_atividade_id
 */
function atualizarPerfil() {
    if (!isset($_SESSION['fornecedor_id'])) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Fornecedor não autenticado']);
        exit;
    }

    $conn = conectar_banco();
    $fornecedor_id = intval($_SESSION['fornecedor_id']);

    // Coletar campos editáveis
    $email           = trim($_POST['email'] ?? '');
    $telefone        = trim($_POST['telefone'] ?? '');
    $endereco        = trim($_POST['endereco'] ?? '');
    $nome_responsavel = trim($_POST['nome_responsavel'] ?? '');
    $senha_atual     = $_POST['senha_atual'] ?? '';
    $nova_senha      = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';

    // Validação de e-mail
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail válido é obrigatório.']);
        exit;
    }

    // Verificar se e-mail já está em uso por outro fornecedor
    $stmt = $conn->prepare('SELECT id FROM fornecedores WHERE email = ? AND id != ? LIMIT 1');
    $stmt->bind_param('si', $email, $fornecedor_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este e-mail já está em uso por outro fornecedor.']);
        exit;
    }
    $stmt->close();

    // Lógica de troca de senha
    $trocar_senha = !empty($nova_senha) || !empty($senha_atual);
    $nova_senha_hash = null;

    if ($trocar_senha) {
        // Buscar senha atual do banco
        $stmt = $conn->prepare('SELECT senha FROM fornecedores WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $fornecedor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Fornecedor não encontrado.']);
            exit;
        }

        if (empty($senha_atual)) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Informe a senha atual para alterar a senha.']);
            exit;
        }

        if (!password_verify($senha_atual, $row['senha'])) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Senha atual incorreta.']);
            exit;
        }

        if (strlen($nova_senha) < 6) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'A nova senha deve ter no mínimo 6 caracteres.']);
            exit;
        }

        if ($nova_senha !== $confirmar_senha) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'A nova senha e a confirmação não coincidem.']);
            exit;
        }

        $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
    }

    // Montar UPDATE dinâmico
    if ($nova_senha_hash) {
        $sql = 'UPDATE fornecedores SET email = ?, telefone = ?, endereco = ?, nome_responsavel = ?, senha = ?, data_atualizacao = NOW() WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssssi', $email, $telefone, $endereco, $nome_responsavel, $nova_senha_hash, $fornecedor_id);
    } else {
        $sql = 'UPDATE fornecedores SET email = ?, telefone = ?, endereco = ?, nome_responsavel = ?, data_atualizacao = NOW() WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssi', $email, $telefone, $endereco, $nome_responsavel, $fornecedor_id);
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao preparar atualização: ' . $conn->error]);
        exit;
    }

    $stmt->execute();
    $stmt->close();

    // Atualizar e-mail na sessão
    $_SESSION['fornecedor_email'] = $email;

    // Registrar log de auditoria
    $descricao = 'Fornecedor atualizou perfil' . ($nova_senha_hash ? ' (senha alterada)' : '');
    $data_hora = date('Y-m-d H:i:s');
    $log_stmt = $conn->prepare("INSERT IGNORE INTO logs_sistema (tipo, descricao, usuario_id, data_hora) VALUES ('perfil', ?, ?, ?)");
    if ($log_stmt) {
        $log_stmt->bind_param('sis', $descricao, $fornecedor_id, $data_hora);
        $log_stmt->execute();
        $log_stmt->close();
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $nova_senha_hash ? 'Perfil e senha atualizados com sucesso!' : 'Perfil atualizado com sucesso!'
    ]);
}
