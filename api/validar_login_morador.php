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

// Incluir arquivo de configuração
require_once 'config.php';

// Incluir detector de loop de autenticação
require_once 'auth_loop_detector.php';
$detector = new AuthLoopDetector();

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
    
    // Buscar morador removendo formatação do CPF no banco também
    $stmt = $conexao->prepare("
        SELECT id, nome, cpf, senha, unidade, email, ativo 
        FROM moradores 
        WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $cpf);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    // Verificar se o morador existe
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        
        // Registrar tentativa de login falha
        registrar_log('LOGIN_MORADOR_FALHA', "Tentativa de login com CPF não cadastrado: {$cpf}");
        
        // Registrar tentativa no detector de loop
        $detector->registrarTentativa('morador', $cpf, $_SERVER['REMOTE_ADDR'], false, 'CPF não cadastrado');
        
        retornar_json(false, 'CPF ou senha incorretos!');
    }
    
    // Obter dados do morador
    $morador = $resultado->fetch_assoc();
    $stmt->close();
    
    // Verificar se o morador está ativo
    if ($morador['ativo'] != 1) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com morador inativo
        registrar_log('LOGIN_MORADOR_FALHA', "Tentativa de login com morador inativo: {$cpf}", $morador['nome']);
        
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
            $stmt_update_senha = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
            $stmt_update_senha->bind_param("si", $senha_bcrypt, $morador['id']);
            $stmt_update_senha->execute();
            $stmt_update_senha->close();
            
            // Log da atualização
            registrar_log('SENHA_ATUALIZADA', "Senha do morador {$morador['nome']} atualizada de SHA1 para BCRYPT", $morador['nome']);
        }
    }
    
    if (!$senha_valida) {
        fechar_conexao($conexao);
        
        // Registrar tentativa de login com senha incorreta
        registrar_log('LOGIN_MORADOR_FALHA', "Tentativa de login com senha incorreta: {$cpf}", $morador['nome']);
        
        // Registrar tentativa no detector de loop
        $detector->registrarTentativa('morador', $cpf, $_SERVER['REMOTE_ADDR'], false, 'Senha incorreta');
        
        retornar_json(false, 'CPF ou senha incorretos!');
    }
    
    // ========== CORREÇÃO: GERAR TOKEN PARA O PORTAL ==========
    
    // Gerar token único
    $token = bin2hex(random_bytes(32));
    $data_expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Verificar se a tabela sessoes_portal existe, se não, usar sessão PHP
    $tabela_existe = false;
    $resultado_tabela = $conexao->query("SHOW TABLES LIKE 'sessoes_portal'");
    if ($resultado_tabela && $resultado_tabela->num_rows > 0) {
        $tabela_existe = true;
    }
    
    if ($tabela_existe) {
        // Limpar tokens antigos do morador
        $stmt_limpar = $conexao->prepare("DELETE FROM sessoes_portal WHERE morador_id = ?");
        $stmt_limpar->bind_param("i", $morador['id']);
        $stmt_limpar->execute();
        $stmt_limpar->close();
        
        // Salvar nova sessão com token
        $stmt_sessao = $conexao->prepare("INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao) VALUES (?, ?, ?, ?, ?)");
        $stmt_sessao->bind_param("issss", $morador['id'], $token, $ip_address, $user_agent, $data_expiracao);
        $stmt_sessao->execute();
        $stmt_sessao->close();
    }
    
    // Login bem-sucedido - criar sessão PHP também (compatibilidade)
    $_SESSION['morador_id'] = $morador['id'];
    $_SESSION['morador_nome'] = $morador['nome'];
    $_SESSION['morador_cpf'] = $morador['cpf'];
    $_SESSION['morador_unidade'] = $morador['unidade'];
    $_SESSION['morador_email'] = $morador['email'];
    $_SESSION['morador_logado'] = true;
    $_SESSION['login_timestamp'] = time();
    $_SESSION['tipo_usuario'] = 'morador';
    
    // Atualizar último acesso do morador
    $stmt_update = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW(), data_atualizacao = NOW() WHERE id = ?");
    $stmt_update->bind_param("i", $morador['id']);
    $stmt_update->execute();
    $stmt_update->close();
    
    fechar_conexao($conexao);
    
    // Registrar login bem-sucedido
    registrar_log('LOGIN_MORADOR_SUCESSO', "Login de morador realizado: {$cpf} - {$morador['nome']}", $morador['nome']);
    
    // Registrar tentativa bem-sucedida no detector de loop
    $detector->registrarTentativa('morador', $cpf, $_SERVER['REMOTE_ADDR'], true, '');
    
    // Retornar sucesso COM TOKEN
    retornar_json(true, 'Login realizado com sucesso!', array(
        'nome' => $morador['nome'],
        'unidade' => $morador['unidade'],
        'email' => $morador['email'],
        'token' => $token,
        'morador_id' => $morador['id']
    ));
    
} catch (Exception $e) {
    error_log("Erro no login de morador: " . $e->getMessage());
    retornar_json(false, 'Erro ao processar login. Tente novamente.');
}
