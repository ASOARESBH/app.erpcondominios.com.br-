<?php
/**
 * API do Portal do Morador - VERSÃO CORRIGIDA
 * Correção: getallheaders() pode não existir em alguns servidores
 */

require_once 'config.php';
require_once 'auth_helper.php';

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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conexao = conectar_banco();
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ========== LOGIN ==========
if ($action === 'login' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $cpf = preg_replace('/[^0-9]/', '', $dados['cpf'] ?? '');
    $senha = $dados['senha'] ?? '';
    
    if (empty($cpf) || empty($senha)) {
        retornar_json(false, "CPF e senha são obrigatórios");
    }
    
    // Buscar morador (busca com ou sem pontuação)
    $stmt = $conexao->prepare("SELECT * FROM moradores WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') = ? AND ativo = 1");
    $stmt->bind_param("s", $cpf);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "CPF não encontrado");
    }
    
    $morador = $resultado->fetch_assoc();
    
    // Verificar senha (suporta texto plano OU hash bcrypt)
    $senha_valida = false;
    
    if (strpos($morador['senha'], '$2y$') === 0) {
        // Hash bcrypt
        $senha_valida = password_verify($senha, $morador['senha']);
    } else {
        // Texto plano
        $senha_valida = ($senha === $morador['senha']);
    }
    
    if (!$senha_valida) {
        retornar_json(false, "Senha incorreta");
    }
    
    // Gerar token
    $token = bin2hex(random_bytes(32));
    $data_expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Salvar sessão
    $stmt = $conexao->prepare("INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $morador['id'], $token, $ip_address, $user_agent, $data_expiracao);
    $stmt->execute();
    
    // Atualizar último acesso
    $stmt = $conexao->prepare("UPDATE moradores SET ultimo_acesso = NOW() WHERE id = ?");
    $stmt->bind_param("i", $morador['id']);
    $stmt->execute();
    
    registrar_log('PORTAL_LOGIN', "Morador {$morador['nome']} acessou o portal", $morador['nome']);
    
    retornar_json(true, "Login realizado com sucesso", array(
        'token' => $token,
        'morador_id' => $morador['id'],
        'morador_nome' => $morador['nome'],
        'unidade' => $morador['unidade']
    ));
}

// ========== VERIFICAR SESSÃO ==========
if ($action === 'verificar_sessao') {
    $token = obter_token();
    
    if (!$token) {
        http_response_code(401);
        retornar_json(false, "Token não fornecido");
    }
    
    $morador_id = verificar_sessao($conexao, $token);
    
    if ($morador_id) {
        retornar_json(true, "Sessão válida", array('morador_id' => $morador_id));
    } else {
        http_response_code(401);
        retornar_json(false, "Sessão inválida ou expirada");
    }
}

// ========== LOGOUT ==========
if ($action === 'logout') {
    $token = obter_token();
    
    if ($token) {
        $stmt = $conexao->prepare("UPDATE sessoes_portal SET ativo = 0 WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
    }
    
    retornar_json(true, "Logout realizado com sucesso");
}

// ========== OBTER PERFIL ==========
if ($action === 'perfil' && $metodo === 'GET') {
    $morador_id = autenticar_morador($conexao);
    
    $stmt = $conexao->prepare("SELECT id, nome, cpf, email, telefone, celular, unidade, data_cadastro, ultimo_acesso FROM moradores WHERE id = ?");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $morador = $resultado->fetch_assoc();
    
    retornar_json(true, "Perfil carregado", $morador);
}

// ========== ATUALIZAR PERFIL ==========
if ($action === 'perfil' && $metodo === 'PUT') {
    $morador_id = autenticar_morador($conexao);
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $email = sanitizar($conexao, $dados['email'] ?? '');
    $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
    $celular = sanitizar($conexao, $dados['celular'] ?? '');
    
    $stmt = $conexao->prepare("UPDATE moradores SET nome = ?, email = ?, telefone = ?, celular = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $nome, $email, $telefone, $celular, $morador_id);
    $stmt->execute();
    
    registrar_log('PORTAL_PERFIL_ATUALIZADO', "Morador ID $morador_id atualizou perfil", $nome);
    
    retornar_json(true, "Perfil atualizado com sucesso");
}

// ========== ALTERAR SENHA ==========
if ($action === 'alterar_senha' && $metodo === 'POST') {
    $morador_id = autenticar_morador($conexao);
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $senha_atual = $dados['senha_atual'] ?? '';
    $senha_nova = $dados['senha_nova'] ?? '';
    
    // Buscar senha atual
    $stmt = $conexao->prepare("SELECT senha, nome FROM moradores WHERE id = ?");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $morador = $resultado->fetch_assoc();
    
    // Verificar senha atual
    $senha_valida = false;
    if (strpos($morador['senha'], '$2y$') === 0) {
        $senha_valida = password_verify($senha_atual, $morador['senha']);
    } else {
        $senha_valida = ($senha_atual === $morador['senha']);
    }
    
    if (!$senha_valida) {
        retornar_json(false, "Senha atual incorreta");
    }
    
    // Atualizar senha (sempre salvar como hash)
    $senha_hash = password_hash($senha_nova, PASSWORD_BCRYPT);
    $stmt = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
    $stmt->bind_param("si", $senha_hash, $morador_id);
    $stmt->execute();
    
    registrar_log('PORTAL_SENHA_ALTERADA', "Morador ID $morador_id alterou senha", $morador['nome']);
    
    retornar_json(true, "Senha alterada com sucesso");
}

// ========== HIDROMETRO ==========
if ($action === 'hidrometro' && $metodo === 'GET') {
    $morador_id = autenticar_morador($conexao);
    
    // Buscar unidade do morador
    $stmt = $conexao->prepare("SELECT unidade FROM moradores WHERE id = ?");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $morador = $resultado->fetch_assoc();
    
    // Buscar hidrometro
    $stmt = $conexao->prepare("SELECT * FROM hidrometro WHERE unidade = ?");
    $stmt->bind_param("s", $morador['unidade']);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $hidrometro = $resultado->fetch_assoc();
    
    if ($hidrometro) {
        retornar_json(true, "Hidrometro carregado", $hidrometro);
    } else {
        retornar_json(false, "Hidrometro não encontrado");
    }
}

// ========== LANÇAMENTOS DE ÁGUA ==========
if ($action === 'lancamentos_agua' && $metodo === 'GET') {
    $morador_id = autenticar_morador($conexao);
    
    // Buscar unidade do morador
    $stmt = $conexao->prepare("SELECT unidade FROM moradores WHERE id = ?");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $morador = $resultado->fetch_assoc();
    
    // Buscar lançamentos
    $stmt = $conexao->prepare("SELECT * FROM lancamentos_agua WHERE unidade = ? ORDER BY data_leitura DESC LIMIT 12");
    $stmt->bind_param("s", $morador['unidade']);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $lancamentos = array();
    while ($row = $resultado->fetch_assoc()) {
        $lancamentos[] = $row;
    }
    
    retornar_json(true, "Lançamentos carregados", $lancamentos);
}

// ========== VEÍCULOS ==========
if ($action === 'veiculos' && $metodo === 'GET') {
    $morador_id = autenticar_morador($conexao);
    
    $stmt = $conexao->prepare("SELECT * FROM veiculos WHERE morador_id = ? ORDER BY id DESC");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $veiculos = array();
    while ($row = $resultado->fetch_assoc()) {
        $veiculos[] = $row;
    }
    
    retornar_json(true, "Veículos carregados", $veiculos);
}

if ($action === 'veiculos' && $metodo === 'POST') {
    $morador_id = autenticar_morador($conexao);
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $placa = sanitizar($conexao, $dados['placa'] ?? '');
    $tipo = sanitizar($conexao, $dados['tipo'] ?? '');
    $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
    $cor = sanitizar($conexao, $dados['cor'] ?? '');
    $tag = sanitizar($conexao, $dados['tag'] ?? '');
    
    $stmt = $conexao->prepare("INSERT INTO veiculos (morador_id, placa, tipo, modelo, cor, tag) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $morador_id, $placa, $tipo, $modelo, $cor, $tag);
    $stmt->execute();
    
    registrar_log('PORTAL_VEICULO_CADASTRADO', "Morador ID $morador_id cadastrou veículo $placa", '');
    
    retornar_json(true, "Veículo cadastrado com sucesso");
}

if ($action === 'veiculos' && $metodo === 'PUT') {
    $morador_id = autenticar_morador($conexao);
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($dados['id'] ?? 0);
    $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
    $cor = sanitizar($conexao, $dados['cor'] ?? '');
    
    // Verificar se o veículo pertence ao morador
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Veículo não encontrado");
    }
    
    // Atualizar apenas modelo e cor
    $stmt = $conexao->prepare("UPDATE veiculos SET modelo = ?, cor = ? WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ssii", $modelo, $cor, $id, $morador_id);
    $stmt->execute();
    
    registrar_log('PORTAL_VEICULO_ATUALIZADO', "Morador ID $morador_id atualizou veículo ID $id", '');
    
    retornar_json(true, "Veículo atualizado com sucesso");
}

if ($action === 'veiculos' && $metodo === 'DELETE') {
    $morador_id = autenticar_morador($conexao);
    $id = intval($_GET['id'] ?? 0);
    
    // Verificar se o veículo pertence ao morador
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Veículo não encontrado");
    }
    
    $stmt = $conexao->prepare("DELETE FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    $stmt->execute();
    
    registrar_log('PORTAL_VEICULO_EXCLUIDO', "Morador ID $morador_id excluiu veículo ID $id", '');
    
    retornar_json(true, "Veículo excluído com sucesso");
}

// ========== VISITANTES ==========
if ($action === 'cadastrar_visitante' && $metodo === 'POST') {
    $morador_id = autenticar_morador($conexao);
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $cpf = preg_replace('/[^0-9]/', '', $dados['cpf'] ?? '');
    $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
    $tipo = sanitizar($conexao, $dados['tipo'] ?? 'Visitante');
    
    // Verificar se visitante já existe
    $stmt = $conexao->prepare("SELECT nome FROM visitantes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') = ?");
    $stmt->bind_param("s", $cpf);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $visitante = $resultado->fetch_assoc();
        retornar_json(false, "Visitante já cadastrado no sistema: " . $visitante['nome']);
    }
    
    // Cadastrar visitante
    $cpf_formatado = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    
    $stmt = $conexao->prepare("INSERT INTO visitantes (nome, cpf, telefone, tipo) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $nome, $cpf_formatado, $telefone, $tipo);
    $stmt->execute();
    
    registrar_log('PORTAL_VISITANTE_CADASTRADO', "Morador ID $morador_id cadastrou visitante $nome", '');
    
    retornar_json(true, "Visitante cadastrado com sucesso");
}

// ========== FUNÇÕES AUXILIARES ==========

/**
 * Obter token do header Authorization
 * CORREÇÃO: getallheaders() pode não existir
 */
function obter_token() {
    // Método 1: getallheaders() (se existir)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? '';
    } else {
        // Método 2: apache_request_headers() (alternativa)
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $auth = $headers['Authorization'] ?? '';
        } else {
            // Método 3: $_SERVER (fallback universal)
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            
            // Tentar também com redirect
            if (empty($auth)) {
                $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            }
        }
    }
    
    // Extrair token do formato "Bearer TOKEN"
    if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
        return $matches[1];
    }
    
    return null;
}

function verificar_sessao($conexao, $token) {
    $stmt = $conexao->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $sessao = $resultado->fetch_assoc();
        return $sessao['morador_id'];
    }
    
    return null;
}

function autenticar_morador($conexao) {
    $token = obter_token();
    
    if (!$token) {
        http_response_code(401);
        retornar_json(false, "Token não fornecido");
        exit;
    }
    
    $morador_id = verificar_sessao($conexao, $token);
    
    if (!$morador_id) {
        http_response_code(401);
        retornar_json(false, "Sessão inválida ou expirada");
        exit;
    }
    
    return $morador_id;
}

// Ação não encontrada
http_response_code(404);
retornar_json(false, "Ação não encontrada: $action");
