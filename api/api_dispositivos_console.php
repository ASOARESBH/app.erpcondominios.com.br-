<?php
/**
 * API DE GERENCIAMENTO DE DISPOSITIVOS DO CONSOLE
 * Gerencia autenticação e autorização de dispositivos
 */

require_once 'config.php';
require_once 'auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conexao = conectar_banco();
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ========================================
// LISTAR DISPOSITIVOS
// ========================================
if ($metodo === 'GET' && empty($action)) {
    $sql = "SELECT * FROM dispositivos_console ORDER BY data_cadastro DESC";
    $resultado = $conexao->query($sql);
    
    $dispositivos = [];
    while ($row = $resultado->fetch_assoc()) {
        $dispositivos[] = $row;
    }
    
    retornar_json(true, "Dispositivos obtidos com sucesso", $dispositivos);
}

// ========================================
// OBTER DISPOSITIVO POR ID
// ========================================
if ($metodo === 'GET' && $action === 'obter') {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do dispositivo não fornecido");
    }
    
    $stmt = $conexao->prepare("SELECT * FROM dispositivos_console WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $dispositivo = $resultado->fetch_assoc();
        retornar_json(true, "Dispositivo obtido com sucesso", $dispositivo);
    } else {
        retornar_json(false, "Dispositivo não encontrado");
    }
}

// ========================================
// CADASTRAR DISPOSITIVO
// ========================================
if ($metodo === 'POST' && empty($action)) {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome_dispositivo = $dados['nome_dispositivo'] ?? '';
    $tipo_dispositivo = $dados['tipo_dispositivo'] ?? 'Tablet';
    $token_acesso = $dados['token_acesso'] ?? '';
    $ativo = $dados['ativo'] ?? 1;
    
    // Validações
    if (!$nome_dispositivo) {
        retornar_json(false, "Nome do dispositivo é obrigatório");
    }
    
    if (!$tipo_dispositivo) {
        retornar_json(false, "Tipo do dispositivo é obrigatório");
    }
    
    if (!$token_acesso) {
        retornar_json(false, "Token de acesso é obrigatório");
    }
    
    if (strlen($token_acesso) !== 12) {
        retornar_json(false, "Token deve ter exatamente 12 caracteres");
    }
    
    // Verificar se token já existe
    $stmt_check = $conexao->prepare("SELECT id FROM dispositivos_console WHERE token_acesso = ?");
    $stmt_check->bind_param("s", $token_acesso);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows > 0) {
        retornar_json(false, "Token já está em uso. Gere um novo token.");
    }
    
    // Inserir no banco
    $stmt = $conexao->prepare("
        INSERT INTO dispositivos_console 
        (nome_dispositivo, token_acesso, tipo_dispositivo, ativo, data_cadastro)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("sssi", $nome_dispositivo, $token_acesso, $tipo_dispositivo, $ativo);
    
    if ($stmt->execute()) {
        $dispositivo_id = $conexao->insert_id;
        
        retornar_json(true, "Dispositivo cadastrado com sucesso", [
            'id' => $dispositivo_id,
            'token_acesso' => $token_acesso,
            'nome_dispositivo' => $nome_dispositivo,
            'tipo_dispositivo' => $tipo_dispositivo,
            'ativo' => $ativo
        ]);
    } else {
        retornar_json(false, "Erro ao cadastrar dispositivo: " . $stmt->error);
    }
}

// ========================================
// ATUALIZAR DISPOSITIVO
// ========================================
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = $dados['id'] ?? 0;
    $nome_dispositivo = $dados['nome_dispositivo'] ?? '';
    $tipo_dispositivo = $dados['tipo_dispositivo'] ?? 'tablet';
    $localizacao = $dados['localizacao'] ?? null;
    $responsavel = $dados['responsavel'] ?? null;
    $ativo = $dados['ativo'] ?? 1;
    $observacao = $dados['observacao'] ?? null;
    
    if (!$id) {
        retornar_json(false, "ID do dispositivo não fornecido");
    }
    
    if (!$nome_dispositivo) {
        retornar_json(false, "Nome do dispositivo é obrigatório");
    }
    
    $stmt = $conexao->prepare("
        UPDATE dispositivos_console 
        SET nome_dispositivo = ?, tipo_dispositivo = ?, localizacao = ?, responsavel = ?, ativo = ?, observacao = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssisi", $nome_dispositivo, $tipo_dispositivo, $localizacao, $responsavel, $ativo, $observacao, $id);
    
    if ($stmt->execute()) {
        registrar_log('DISPOSITIVO_ATUALIZADO', "Dispositivo atualizado", "ID: {$id}, Nome: {$nome_dispositivo}");
        retornar_json(true, "Dispositivo atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar dispositivo: " . $stmt->error);
    }
}

// ========================================
// EXCLUIR DISPOSITIVO
// ========================================
if ($metodo === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do dispositivo não fornecido");
    }
    
    // Buscar dados antes de excluir
    $stmt = $conexao->prepare("SELECT nome_dispositivo FROM dispositivos_console WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $dispositivo = $stmt->get_result()->fetch_assoc();
    
    if (!$dispositivo) {
        retornar_json(false, "Dispositivo não encontrado");
    }
    
    // Excluir
    $stmt = $conexao->prepare("DELETE FROM dispositivos_console WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        registrar_log('DISPOSITIVO_EXCLUIDO', "Dispositivo excluído", "Nome: {$dispositivo['nome_dispositivo']}");
        retornar_json(true, "Dispositivo excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir dispositivo: " . $stmt->error);
    }
}

// ========================================
// AUTENTICAR DISPOSITIVO (PRIMEIRO ACESSO)
// ========================================
if ($metodo === 'POST' && $action === 'autenticar') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $token = strtoupper(trim($dados['token'] ?? ''));
    $user_agent = $dados['user_agent'] ?? null;
    
    if (!$token) {
        retornar_json(false, "Token não fornecido");
    }
    
    // Buscar dispositivo pelo token
    $stmt = $conexao->prepare("SELECT * FROM dispositivos_console WHERE token_acesso = ? AND ativo = 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        registrar_log('AUTH_DISPOSITIVO_NEGADO', "Tentativa de autenticação com token inválido", "Token: {$token}");
        retornar_json(false, "Token inválido ou dispositivo inativo");
    }
    
    $dispositivo = $resultado->fetch_assoc();
    
    // Atualizar último acesso
    $ip_acesso = $_SERVER['REMOTE_ADDR'] ?? null;
    $data_acesso = date('Y-m-d H:i:s');
    
    $stmt_update = $conexao->prepare("
        UPDATE dispositivos_console 
        SET user_agent = ?, ip_ultimo_acesso = ?, data_ultimo_acesso = ?, total_acessos = total_acessos + 1
        WHERE id = ?
    ");
    $stmt_update->bind_param("sssi", $user_agent, $ip_acesso, $data_acesso, $dispositivo['id']);
    $stmt_update->execute();
    
    registrar_log('AUTH_DISPOSITIVO_SUCESSO', "Dispositivo autenticado com sucesso", "Nome: {$dispositivo['nome_dispositivo']}, IP: {$ip_acesso}");
    
    retornar_json(true, "Dispositivo autenticado com sucesso", [
        'id' => $dispositivo['id'],
        'nome_dispositivo' => $dispositivo['nome_dispositivo'],
        'tipo_dispositivo' => $dispositivo['tipo_dispositivo'],
        'localizacao' => $dispositivo['localizacao']
    ]);
}

// ========================================
// VALIDAR TOKEN (GET)
// ========================================
if ($metodo === 'GET' && $action === 'validar_token') {
    $token = $_GET['token'] ?? '';
    
    if (!$token) {
        retornar_json(false, "Token não fornecido");
    }
    
    $stmt = $conexao->prepare("SELECT * FROM dispositivos_console WHERE token_acesso = ? AND ativo = 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Token inválido ou dispositivo inativo");
    }
    
    $dispositivo = $resultado->fetch_assoc();
    
    // Atualizar último acesso
    $ip_acesso = $_SERVER['REMOTE_ADDR'] ?? null;
    $data_acesso = date('Y-m-d H:i:s');
    
    $stmt_update = $conexao->prepare("
        UPDATE dispositivos_console 
        SET ip_ultimo_acesso = ?, data_ultimo_acesso = ?
        WHERE id = ?
    ");
    $stmt_update->bind_param("ssi", $ip_acesso, $data_acesso, $dispositivo['id']);
    $stmt_update->execute();
    
    retornar_json(true, "Token válido", [
        'dispositivo_id' => $dispositivo['id'],
        'id' => $dispositivo['id'],
        'nome_dispositivo' => $dispositivo['nome_dispositivo'],
        'tipo_dispositivo' => $dispositivo['tipo_dispositivo']
    ]);
}

// ========================================
// VALIDAR DISPOSITIVO (ACESSOS SUBSEQUENTES)
// ========================================
if ($metodo === 'POST' && $action === 'validar') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $token = strtoupper(trim($dados['token'] ?? ''));
    $dispositivo_id = $dados['dispositivo_id'] ?? 0;
    
    if (!$token || !$dispositivo_id) {
        retornar_json(false, "Token e ID do dispositivo são obrigatórios");
    }
    
    // Buscar dispositivo
    $stmt = $conexao->prepare("SELECT * FROM dispositivos_console WHERE id = ? AND token_acesso = ? AND ativo = 1");
    $stmt->bind_param("is", $dispositivo_id, $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Dispositivo não autorizado ou inativo");
    }
    
    $dispositivo = $resultado->fetch_assoc();
    
    // Atualizar último acesso
    $ip_acesso = $_SERVER['REMOTE_ADDR'] ?? null;
    $data_acesso = date('Y-m-d H:i:s');
    
    $stmt_update = $conexao->prepare("
        UPDATE dispositivos_console 
        SET ip_ultimo_acesso = ?, data_ultimo_acesso = ?, total_acessos = total_acessos + 1
        WHERE id = ?
    ");
    $stmt_update->bind_param("ssi", $ip_acesso, $data_acesso, $dispositivo['id']);
    $stmt_update->execute();
    
    retornar_json(true, "Dispositivo validado com sucesso", [
        'id' => $dispositivo['id'],
        'nome_dispositivo' => $dispositivo['nome_dispositivo'],
        'tipo_dispositivo' => $dispositivo['tipo_dispositivo'],
        'localizacao' => $dispositivo['localizacao']
    ]);
}

// ========================================
// GERAR TOKEN ÚNICO (SEM ID)
// ========================================
if ($metodo === 'GET' && $action === 'gerar_token') {
    require_once 'gerar_token_dispositivo.php';
    $token = gerarTokenUnico($conexao, 12);
    retornar_json(true, 'Token gerado com sucesso', ['token' => $token]);
}

// ========================================
// GERAR NOVO TOKEN (COM ID)
// ========================================
if ($metodo === 'POST' && $action === 'gerar_token') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = $dados['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do dispositivo não fornecido");
    }
    
    // Gerar novo token
    $novo_token = gerarTokenSimples();
    
    // Atualizar no banco
    $stmt = $conexao->prepare("UPDATE dispositivos_console SET token_acesso = ? WHERE id = ?");
    $stmt->bind_param("si", $novo_token, $id);
    
    if ($stmt->execute()) {
        registrar_log('DISPOSITIVO_TOKEN_GERADO', "Novo token gerado para dispositivo", "ID: {$id}, Novo Token: {$novo_token}");
        retornar_json(true, "Novo token gerado com sucesso", ['token_acesso' => $novo_token]);
    } else {
        retornar_json(false, "Erro ao gerar novo token: " . $stmt->error);
    }
}

// ========================================
// ESTATÍSTICAS DE DISPOSITIVOS
// ========================================
if ($metodo === 'GET' && $action === 'estatisticas') {
    $sql_total = "SELECT COUNT(*) as total FROM dispositivos_console";
    $sql_ativos = "SELECT COUNT(*) as total FROM dispositivos_console WHERE ativo = 1";
    $sql_inativos = "SELECT COUNT(*) as total FROM dispositivos_console WHERE ativo = 0";
    $sql_acessos_hoje = "SELECT SUM(total_acessos) as total FROM dispositivos_console WHERE DATE(data_ultimo_acesso) = CURDATE()";
    
    $total = $conexao->query($sql_total)->fetch_assoc()['total'];
    $ativos = $conexao->query($sql_ativos)->fetch_assoc()['total'];
    $inativos = $conexao->query($sql_inativos)->fetch_assoc()['total'];
    $acessos_hoje = $conexao->query($sql_acessos_hoje)->fetch_assoc()['total'] ?? 0;
    
    retornar_json(true, "Estatísticas obtidas com sucesso", [
        'total' => $total,
        'ativos' => $ativos,
        'inativos' => $inativos,
        'acessos_hoje' => $acessos_hoje
    ]);
}

// ========================================
// FUNÇÃO AUXILIAR: GERAR TOKEN SIMPLES
// ========================================
function gerarTokenSimples() {
    // Gerar token de 6-8 caracteres alfanuméricos
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sem I, O, 0, 1 para evitar confusão
    $tamanho = rand(6, 8);
    $token = '';
    
    for ($i = 0; $i < $tamanho; $i++) {
        $token .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    
    return $token;
}

// ========================================
// FUNÇÃO AUXILIAR: RETORNAR JSON
// ========================================
function retornar_json($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================
// FUNÇÃO AUXILIAR: REGISTRAR LOG
// ========================================
function registrar_log($tipo, $acao, $detalhes = null) {
    global $conexao;
    
    $usuario = $_SESSION['usuario_nome'] ?? 'Sistema';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $stmt = $conexao->prepare("
        INSERT INTO logs_sistema (tipo, usuario, acao, detalhes, ip)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssss", $tipo, $usuario, $acao, $detalhes, $ip);
    $stmt->execute();
}
?>
