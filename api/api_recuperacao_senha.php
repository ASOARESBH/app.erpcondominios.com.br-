<?php
/**
 * API DE RECUPERAÇÃO DE SENHA
 * 
 * Gerencia solicitações de recuperação de senha
 * Utiliza a classe EmailSender para envio de e-mails
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 29/12/2025
 */

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

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'auth_helper.php';

// Função para sanitizar entrada
if (!function_exists('sanitizar')) {
function sanitizar($conexao, $valor) {
    return mysqli_real_escape_string($conexao, trim($valor));
}
}

// Função para resposta JSON
function resposta($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Conectar ao banco
$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    resposta(false, 'Erro ao conectar ao banco de dados');
}
mysqli_set_charset($conexao, 'utf8mb4');

// Obter ação
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

switch ($acao) {
    case 'solicitar':
        solicitarRecuperacao($conexao);
        break;
    
    case 'validar_token':
        validarToken($conexao);
        break;
    
    case 'redefinir':
        redefinirSenha($conexao);
        break;
    
    default:
        resposta(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES
// =====================================================

function solicitarRecuperacao($conexao) {
    $cpf = sanitizar($conexao, $_GET['cpf'] ?? '');
    
    if (empty($cpf)) {
        resposta(false, 'CPF não informado');
    }
    
    // Buscar morador pelo CPF
    $sql = "SELECT id, nome, email, ativo FROM moradores WHERE cpf = '$cpf' LIMIT 1";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado || mysqli_num_rows($resultado) == 0) {
        resposta(false, 'CPF não encontrado no sistema');
    }
    
    $morador = mysqli_fetch_assoc($resultado);
    
    // Verificar se está ativo
    if ($morador['ativo'] != 1) {
        resposta(false, 'Morador inativo. Entre em contato com a administração.');
    }
    
    // Verificar se tem e-mail cadastrado
    if (empty($morador['email'])) {
        resposta(false, 'Não há e-mail cadastrado para este CPF. Entre em contato com a administração.');
    }
    
    // Gerar token único
    $token = bin2hex(random_bytes(32));
    $morador_id = $morador['id'];
    $email = $morador['email'];
    $data_expiracao = date('Y-m-d H:i:s', strtotime('+1 hour')); // Expira em 1 hora
    
    // Obter IP e User Agent
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $user_agent = sanitizar($conexao, $user_agent);
    
    // Invalidar tokens anteriores deste morador
    $sql_invalidar = "UPDATE recuperacao_senha_tokens SET usado = 1 WHERE morador_id = $morador_id AND usado = 0";
    mysqli_query($conexao, $sql_invalidar);
    
    // Inserir novo token
    $sql_insert = "INSERT INTO recuperacao_senha_tokens 
                   (morador_id, token, email, data_expiracao, ip_solicitacao, user_agent)
                   VALUES 
                   ($morador_id, '$token', '$email', '$data_expiracao', '$ip', '$user_agent')";
    
    if (!mysqli_query($conexao, $sql_insert)) {
        resposta(false, 'Erro ao gerar token de recuperação');
    }
    
    // Enviar e-mail usando EmailSender
    try {
        require_once 'EmailSender.php';
        
        $emailSender = new EmailSender($conexao);
        $enviado = $emailSender->enviarRecuperacaoSenha(
            $morador['email'], 
            $morador['nome'], 
            $token, 
            $morador['id']
        );
        
        if ($enviado) {
            resposta(true, 'Link de recuperação enviado para o e-mail cadastrado. Verifique sua caixa de entrada.');
        } else {
            resposta(false, 'Erro ao enviar e-mail. Tente novamente mais tarde.');
        }
        
    } catch (Exception $e) {
        error_log("Erro ao enviar e-mail de recuperação: " . $e->getMessage());
        resposta(false, 'Erro ao enviar e-mail: ' . $e->getMessage());
    }
}

function validarToken($conexao) {
    $token = sanitizar($conexao, $_GET['token'] ?? '');
    
    if (empty($token)) {
        resposta(false, 'Token não informado');
    }
    
    $sql = "SELECT t.*, m.nome, m.email 
            FROM recuperacao_senha_tokens t
            INNER JOIN moradores m ON t.morador_id = m.id
            WHERE t.token = '$token' 
              AND t.usado = 0 
              AND t.data_expiracao > NOW()
            LIMIT 1";
    
    $resultado = mysqli_query($conexao, $sql);
    
    if ($resultado && mysqli_num_rows($resultado) > 0) {
        $dados = mysqli_fetch_assoc($resultado);
        resposta(true, 'Token válido', $dados);
    } else {
        resposta(false, 'Token inválido ou expirado');
    }
}

function redefinirSenha($conexao) {
    $token = sanitizar($conexao, $_POST['token'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    if (empty($token) || empty($senha)) {
        resposta(false, 'Dados incompletos');
    }
    
    if (strlen($senha) < 6) {
        resposta(false, 'A senha deve ter no mínimo 6 caracteres');
    }
    
    // Validar token
    $sql = "SELECT morador_id 
            FROM recuperacao_senha_tokens 
            WHERE token = '$token' 
              AND usado = 0 
              AND data_expiracao > NOW()
            LIMIT 1";
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado || mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Token inválido ou expirado');
    }
    
    $row = mysqli_fetch_assoc($resultado);
    $morador_id = $row['morador_id'];
    
    // Criptografar senha (usando MD5 para compatibilidade)
    $senha_hash = md5($senha);
    
    // Atualizar senha do morador
    $sql_update = "UPDATE moradores SET senha = '$senha_hash' WHERE id = $morador_id";
    
    if (!mysqli_query($conexao, $sql_update)) {
        resposta(false, 'Erro ao atualizar senha');
    }
    
    // Marcar token como usado
    $sql_marcar = "UPDATE recuperacao_senha_tokens SET usado = 1 WHERE token = '$token'";
    mysqli_query($conexao, $sql_marcar);
    
    // Registrar no log
    $sql_log = "INSERT INTO email_log (morador_id, destinatario, assunto, tipo, status) 
                SELECT $morador_id, email, 'Senha Redefinida', 'recuperacao_senha', 'enviado' 
                FROM moradores WHERE id = $morador_id";
    mysqli_query($conexao, $sql_log);
    
    resposta(true, 'Senha redefinida com sucesso!');
}

mysqli_close($conexao);
?>
