<?php
/**
 * API DE FACE ID
 * Cadastro e validação de descritores faciais para visitantes
 */

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();

// Função auxiliar para retornar JSON
function retornar_json($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Obter método e ação
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Para operações de escrita, verificar permissão
if ($metodo !== 'GET') {
    verificarPermissao('operador');
}

$conexao = conectar_banco();

// ========================================
// GERAR TOKEN Único PARA CADASTRO
// ========================================
if ($metodo === 'POST' && $action === 'gerar_token') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $visitante_id = $dados['visitante_id'] ?? 0;
    $acesso_id = $dados['acesso_id'] ?? null;
    $validade_horas = $dados['validade_horas'] ?? 48; // Padrão: 48 horas
    
    if (!$visitante_id) {
        retornar_json(false, "Visitante não informado");
    }
    
    // Verificar se visitante existe
    $stmt = $conexao->prepare("SELECT id, nome FROM visitantes WHERE id = ?");
    $stmt->bind_param("i", $visitante_id);
    $stmt->execute();
    $visitante = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$visitante) {
        retornar_json(false, "Visitante não encontrado");
    }
    
    // Gerar token único
    $token = bin2hex(random_bytes(32)); // 64 caracteres
    $data_expiracao = date('Y-m-d H:i:s', strtotime("+{$validade_horas} hours"));
    
    // Inserir token
    $stmt = $conexao->prepare("
        INSERT INTO face_descriptors 
        (visitante_id, acesso_id, descritor, token_cadastro, data_expiracao)
        VALUES (?, ?, '', ?, ?)
    ");
    $stmt->bind_param("iiss", $visitante_id, $acesso_id, $token, $data_expiracao);
    
    if ($stmt->execute()) {
        $face_id = $conexao->insert_id;
        
        // Gerar link de cadastro
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        $link_cadastro = $base_url . "/cadastro_face_id.html?token=" . $token;
        
        retornar_json(true, "Token gerado com sucesso", [
            'id' => $face_id,
            'token' => $token,
            'link_cadastro' => $link_cadastro,
            'visitante_nome' => $visitante['nome'],
            'validade' => $data_expiracao,
            'validade_horas' => $validade_horas
        ]);
    } else {
        retornar_json(false, "Erro ao gerar token: " . $stmt->error);
    }
}

// ========================================
// VALIDAR TOKEN E OBTER DADOS
// ========================================
if ($metodo === 'GET' && $action === 'validar_token') {
    $token = $_GET['token'] ?? '';
    
    if (!$token) {
        retornar_json(false, "Token não informado");
    }
    
    // Buscar token
    $stmt = $conexao->prepare("
        SELECT 
            fd.id,
            fd.visitante_id,
            v.nome AS visitante_nome,
            v.documento AS visitante_documento,
            v.telefone AS visitante_telefone,
            fd.acesso_id,
            fd.token_usado,
            fd.data_expiracao,
            fd.ativo
        FROM face_descriptors fd
        INNER JOIN visitantes v ON fd.visitante_id = v.id
        WHERE fd.token_cadastro = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) {
        retornar_json(false, "Token inválido");
    }
    
    if ($result['token_usado'] == 1) {
        retornar_json(false, "Token já foi utilizado");
    }
    
    if ($result['data_expiracao'] && strtotime($result['data_expiracao']) < time()) {
        retornar_json(false, "Token expirado");
    }
    
    if ($result['ativo'] != 1) {
        retornar_json(false, "Token inativo");
    }
    
    retornar_json(true, "Token válido", $result);
}

// ========================================
// CADASTRAR DESCRITOR FACIAL
// ========================================
if ($metodo === 'POST' && $action === 'cadastrar_descritor') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $token = $dados['token'] ?? '';
    $descritor = $dados['descritor'] ?? null;
    $foto_base64 = $dados['foto'] ?? null;
    
    if (!$token || !$descritor) {
        retornar_json(false, "Token e descritor são obrigatórios");
    }
    
    // Validar token
    $stmt = $conexao->prepare("
        SELECT id, visitante_id, token_usado, data_expiracao, ativo
        FROM face_descriptors
        WHERE token_cadastro = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $face = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$face) {
        retornar_json(false, "Token inválido");
    }
    
    if ($face['token_usado'] == 1) {
        retornar_json(false, "Token já foi utilizado");
    }
    
    if ($face['data_expiracao'] && strtotime($face['data_expiracao']) < time()) {
        retornar_json(false, "Token expirado");
    }
    
    // Salvar foto (opcional)
    $foto_url = null;
    if ($foto_base64) {
        $foto_dir = 'uploads/face_id/';
        if (!is_dir($foto_dir)) {
            mkdir($foto_dir, 0755, true);
        }
        
        $foto_nome = $face['visitante_id'] . '_' . time() . '.jpg';
        $foto_path = $foto_dir . $foto_nome;
        
        // Remover prefixo data:image/jpeg;base64,
        $foto_data = preg_replace('/^data:image\/\w+;base64,/', '', $foto_base64);
        $foto_data = base64_decode($foto_data);
        
        if (file_put_contents($foto_path, $foto_data)) {
            $foto_url = $foto_path;
        }
    }
    
    // Converter descritor para JSON
    $descritor_json = json_encode($descritor);
    
    // Atualizar descritor
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conexao->prepare("
        UPDATE face_descriptors 
        SET descritor = ?,
            foto_url = ?,
            token_usado = 1,
            ip_cadastro = ?,
            user_agent = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $descritor_json, $foto_url, $ip, $user_agent, $face['id']);
    
    if ($stmt->execute()) {
        retornar_json(true, "Descritor cadastrado com sucesso", [
            'id' => $face['id'],
            'visitante_id' => $face['visitante_id'],
            'foto_url' => $foto_url
        ]);
    } else {
        retornar_json(false, "Erro ao cadastrar descritor: " . $stmt->error);
    }
}

// ========================================
// VALIDAR FACE ID (COMPARAR DESCRITORES)
// ========================================
if ($metodo === 'POST' && $action === 'validar_face') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $descritor_capturado = $dados['descritor'] ?? null;
    $dispositivo_token = $dados['dispositivo_token'] ?? '';
    $threshold = $dados['threshold'] ?? 0.6; // Padrão: 0.6
    
    if (!$descritor_capturado) {
        retornar_json(false, "Descritor não informado");
    }
    
    // Validar dispositivo (opcional)
    $dispositivo_id = null;
    if ($dispositivo_token) {
        $stmt = $conexao->prepare("SELECT id FROM dispositivos_console WHERE token_acesso = ? AND ativo = 1");
        $stmt->bind_param("s", $dispositivo_token);
        $stmt->execute();
        $dispositivo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($dispositivo) {
            $dispositivo_id = $dispositivo['id'];
        }
    }
    
    // Buscar todos os descritores ativos
    $stmt = $conexao->prepare("
        SELECT 
            fd.id,
            fd.visitante_id,
            v.nome AS visitante_nome,
            v.documento AS visitante_documento,
            fd.descritor,
            fd.acesso_id,
            a.placa,
            a.modelo,
            a.cor,
            a.tipo_acesso,
            a.data_inicial,
            a.data_final,
            m.nome AS morador_nome,
            m.unidade AS morador_unidade
        FROM face_descriptors fd
        INNER JOIN visitantes v ON fd.visitante_id = v.id
        LEFT JOIN acessos_visitantes a ON fd.acesso_id = a.id
        LEFT JOIN moradores m ON v.morador_id = m.id
        WHERE fd.ativo = 1 
        AND fd.token_usado = 1
        AND fd.descritor != ''
    ");
    $stmt->execute();
    $descritores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($descritores)) {
        retornar_json(false, "Nenhum descritor cadastrado no sistema");
    }
    
    // Comparar descritores (será feito no JavaScript)
    // Aqui apenas retornamos os descritores para comparação no frontend
    retornar_json(true, "Descritores obtidos para comparação", [
        'total_descritores' => count($descritores),
        'descritores' => $descritores,
        'threshold' => $threshold
    ]);
}

// ========================================
// REGISTRAR VALIDAÇÃO DE FACE ID
// ========================================
if ($metodo === 'POST' && $action === 'registrar_validacao') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $face_descriptor_id = $dados['face_descriptor_id'] ?? 0;
    $visitante_id = $dados['visitante_id'] ?? 0;
    $acesso_id = $dados['acesso_id'] ?? null;
    $dispositivo_id = $dados['dispositivo_id'] ?? null;
    $similaridade = $dados['similaridade'] ?? 0;
    $threshold_usado = $dados['threshold_usado'] ?? 0.6;
    $resultado = $dados['resultado'] ?? 'falha';
    $motivo_falha = $dados['motivo_falha'] ?? null;
    
    if (!$face_descriptor_id || !$visitante_id) {
        retornar_json(false, "Dados insuficientes para registrar validação");
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $stmt = $conexao->prepare("
        INSERT INTO validacoes_face_id 
        (face_descriptor_id, visitante_id, acesso_id, dispositivo_id, similaridade, threshold_usado, resultado, motivo_falha, ip_validacao)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiiddsss", $face_descriptor_id, $visitante_id, $acesso_id, $dispositivo_id, $similaridade, $threshold_usado, $resultado, $motivo_falha, $ip);
    
    if ($stmt->execute()) {
        retornar_json(true, "Validação registrada com sucesso", [
            'id' => $conexao->insert_id
        ]);
    } else {
        retornar_json(false, "Erro ao registrar validação: " . $stmt->error);
    }
}

// ========================================
// LISTAR DESCRITORES DE UM VISITANTE
// ========================================
if ($metodo === 'GET' && $action === 'listar_descritores') {
    $visitante_id = $_GET['visitante_id'] ?? 0;
    
    if (!$visitante_id) {
        retornar_json(false, "Visitante não informado");
    }
    
    $stmt = $conexao->prepare("
        SELECT 
            fd.id,
            fd.token_cadastro,
            fd.token_usado,
            fd.data_cadastro,
            fd.data_expiracao,
            fd.ativo,
            fd.foto_url,
            CASE 
                WHEN fd.data_expiracao IS NULL THEN 'Sem expiração'
                WHEN fd.data_expiracao < NOW() THEN 'Expirado'
                ELSE 'Válido'
            END AS status_token
        FROM face_descriptors fd
        WHERE fd.visitante_id = ?
        ORDER BY fd.data_cadastro DESC
    ");
    $stmt->bind_param("i", $visitante_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $descritores = [];
    while ($row = $result->fetch_assoc()) {
        $descritores[] = $row;
    }
    
    retornar_json(true, "Descritores obtidos com sucesso", $descritores);
}

// ========================================
// ESTATÍSTICAS DE FACE ID
// ========================================
if ($metodo === 'GET' && $action === 'estatisticas') {
    $stmt = $conexao->query("SELECT * FROM vw_estatisticas_face_id");
    $stats = $stmt->fetch_assoc();
    
    retornar_json(true, "Estatísticas obtidas com sucesso", $stats);
}

// ========================================
// AÇÃO NÃO ENCONTRADA
// ========================================
retornar_json(false, "Ação não encontrada: {$action}");
?>
