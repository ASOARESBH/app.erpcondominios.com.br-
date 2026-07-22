<?php
/**
 * API de Validação de Tokens de QR Code
 * Para uso em cancelas, portarias e pontos de controle
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'qrcode_token_manager.php';
require_once 'dispositivo_token_manager.php';

// Função para retornar JSON
function retornar_json($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Criar gerenciadores
$tokenManager = new QRCodeTokenManager($conexao);
$dispositivoManager = new DispositivoTokenManager($conexao);

// ========================================
// VALIDAR TOKEN (COM AUTENTICAÇÃO DE DISPOSITIVO)
// ========================================
if ($action === 'validar') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $dispositivo_token = $_GET['dispositivo_token'] ?? $_POST['dispositivo_token'] ?? '';
    $local_validacao = $_GET['local'] ?? $_POST['local'] ?? 'portaria';
    
    if (empty($token)) {
        retornar_json(false, 'Token não fornecido');
    }
    
    // VALIDAÇÃO EM CAMADAS: 1º Dispositivo, 2º QR Code
    if (!empty($dispositivo_token)) {
        error_log("[VALIDAR TOKEN] Validando dispositivo: $dispositivo_token");
        
        $validacao_dispositivo = $dispositivoManager->validarDispositivo($dispositivo_token);
        
        if (!$validacao_dispositivo['valido']) {
            error_log("[VALIDAR TOKEN] Dispositivo não autorizado: {$validacao_dispositivo['motivo']}");
            retornar_json(false, 'Dispositivo não autorizado', [
                'motivo' => $validacao_dispositivo['motivo'],
                'mensagem_dispositivo' => $validacao_dispositivo['mensagem']
            ]);
        }
        
        error_log("[VALIDAR TOKEN] Dispositivo autorizado: {$validacao_dispositivo['nome']}");
    }
    
    error_log("[VALIDAR TOKEN] Validando token: $token (Local: $local_validacao)");
    
    // Validar token
    $resultado = $tokenManager->validarToken($token);
    
    if (!$resultado['valido']) {
        error_log("[VALIDAR TOKEN] Token inválido: {$resultado['motivo']}");
        retornar_json(false, $resultado['mensagem'], [
            'motivo' => $resultado['motivo'],
            'detalhes' => $resultado
        ]);
    }
    
    error_log("[VALIDAR TOKEN] Token válido! Visitante: {$resultado['visitante']['nome']}");
    
    // Token válido - retornar dados
    retornar_json(true, 'Token válido', [
        'token_id' => $resultado['token_id'],
        'acesso_id' => $resultado['acesso_id'],
        'visitante' => $resultado['visitante'],
        'acesso' => $resultado['acesso'],
        'expira_em' => $resultado['expira_em'],
        'local_validacao' => $local_validacao
    ]);
}

// ========================================
// VALIDAR E MARCAR COMO USADO (COM AUTENTICAÇÃO DE DISPOSITIVO)
// ========================================
if ($action === 'validar_e_usar') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $dispositivo_token = $_GET['dispositivo_token'] ?? $_POST['dispositivo_token'] ?? '';
    $local_validacao = $_GET['local'] ?? $_POST['local'] ?? 'portaria';
    
    // VALIDAÇÃO EM CAMADAS: 1º Dispositivo, 2º QR Code
    $dispositivo_id = null;
    if (!empty($dispositivo_token)) {
        error_log("[VALIDAR E USAR] Validando dispositivo: $dispositivo_token");
        
        $validacao_dispositivo = $dispositivoManager->validarDispositivo($dispositivo_token);
        
        if (!$validacao_dispositivo['valido']) {
            error_log("[VALIDAR E USAR] Dispositivo não autorizado: {$validacao_dispositivo['motivo']}");
            
            // Registrar tentativa de acesso não autorizado
            if (isset($validacao_dispositivo['dispositivo_id'])) {
                $dispositivoManager->registrarValidacao(
                    $validacao_dispositivo['dispositivo_id'],
                    $token,
                    null,
                    'falha',
                    'Dispositivo inativo ou não autorizado'
                );
            }
            
            retornar_json(false, 'Dispositivo não autorizado', [
                'motivo' => $validacao_dispositivo['motivo'],
                'mensagem_dispositivo' => $validacao_dispositivo['mensagem']
            ]);
        }
        
        $dispositivo_id = $validacao_dispositivo['dispositivo_id'];
        error_log("[VALIDAR E USAR] Dispositivo autorizado: {$validacao_dispositivo['nome']}");
    }
    
    if (empty($token)) {
        retornar_json(false, 'Token não fornecido');
    }
    
    error_log("[VALIDAR E USAR] Validando token: $token (Local: $local_validacao)");
    
    // Validar token
    $resultado = $tokenManager->validarToken($token);
    
    if (!$resultado['valido']) {
        error_log("[VALIDAR E USAR] Token inválido: {$resultado['motivo']}");
        retornar_json(false, $resultado['mensagem'], [
            'motivo' => $resultado['motivo'],
            'detalhes' => $resultado
        ]);
    }
    
    // Token válido - marcar como usado
    $resultado_uso = $tokenManager->marcarComoUsado($token);
    
    if (!$resultado_uso['sucesso']) {
        error_log("[VALIDAR E USAR] Erro ao marcar como usado: {$resultado_uso['mensagem']}");
        retornar_json(false, 'Erro ao registrar uso do token', $resultado_uso);
    }
    
    error_log("[VALIDAR E USAR] Token usado com sucesso! Visitante: {$resultado['visitante']['nome']}");
    
    // Registrar validação do dispositivo (se fornecido)
    if ($dispositivo_id) {
        $dispositivoManager->registrarValidacao(
            $dispositivo_id,
            $token,
            $resultado['acesso_id'],
            'sucesso',
            null
        );
        error_log("[VALIDAR E USAR] Validação registrada para dispositivo ID: $dispositivo_id");
    }
    
    // Registrar no log de acessos (adicional)
    $stmt = $conexao->prepare("
        INSERT INTO logs_sistema 
        (usuario_id, acao, tabela, descricao, ip_address, data_hora)
        VALUES (?, 'VALIDAR_TOKEN', 'qrcode_tokens', ?, ?, NOW())
    ");
    
    $usuario_id = 1; // Sistema
    $descricao = "Token validado e usado - Visitante: {$resultado['visitante']['nome']} - Local: $local_validacao";
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt->bind_param("iss", $usuario_id, $descricao, $ip);
    $stmt->execute();
    $stmt->close();
    
    // Retornar sucesso
    retornar_json(true, 'Acesso autorizado', [
        'token_id' => $resultado['token_id'],
        'acesso_id' => $resultado['acesso_id'],
        'visitante' => $resultado['visitante'],
        'acesso' => $resultado['acesso'],
        'local_validacao' => $local_validacao,
        'usado_em' => date('Y-m-d H:i:s')
    ]);
}

// ========================================
// INVALIDAR TOKEN MANUALMENTE
// ========================================
if ($action === 'invalidar') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    
    if (empty($token)) {
        retornar_json(false, 'Token não fornecido');
    }
    
    error_log("[INVALIDAR TOKEN] Invalidando token: $token");
    
    $resultado = $tokenManager->invalidarToken($token);
    
    if ($resultado['sucesso']) {
        error_log("[INVALIDAR TOKEN] Token invalidado com sucesso");
        retornar_json(true, 'Token invalidado com sucesso');
    } else {
        error_log("[INVALIDAR TOKEN] Erro ao invalidar token");
        retornar_json(false, 'Erro ao invalidar token');
    }
}

// ========================================
// ESTATÍSTICAS DE TOKENS
// ========================================
if ($action === 'estatisticas') {
    error_log("[ESTATÍSTICAS] Buscando estatísticas de tokens");
    
    $stats = $tokenManager->obterEstatisticas();
    
    retornar_json(true, 'Estatísticas obtidas com sucesso', $stats);
}

// ========================================
// LIMPAR TOKENS EXPIRADOS
// ========================================
if ($action === 'limpar_expirados') {
    error_log("[LIMPAR] Limpando tokens expirados");
    
    $resultado = $tokenManager->limparTokensExpirados();
    
    retornar_json(true, $resultado['mensagem'], [
        'tokens_removidos' => $resultado['tokens_removidos']
    ]);
}

// ========================================
// LISTAR TOKENS ATIVOS
// ========================================
if ($action === 'listar_ativos') {
    error_log("[LISTAR] Listando tokens ativos");
    
    $stmt = $conexao->query("
        SELECT 
            t.id,
            t.token,
            t.acesso_id,
            t.expira_em,
            t.criado_em,
            a.qr_code,
            a.tipo_acesso,
            v.nome_completo as visitante_nome,
            v.documento as visitante_documento,
            TIMESTAMPDIFF(HOUR, NOW(), t.expira_em) as horas_restantes
        FROM qrcode_tokens t
        INNER JOIN acessos_visitantes a ON t.acesso_id = a.id
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE t.usado = 0 
        AND t.expira_em > NOW()
        AND CURDATE() BETWEEN a.data_inicial AND a.data_final
        ORDER BY t.expira_em ASC
        LIMIT 100
    ");
    
    $tokens = [];
    while ($row = $stmt->fetch_assoc()) {
        $tokens[] = $row;
    }
    
    retornar_json(true, 'Tokens ativos listados com sucesso', [
        'total' => count($tokens),
        'tokens' => $tokens
    ]);
}

// ========================================
// VERIFICAR STATUS DO TOKEN
// ========================================
if ($action === 'status') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    
    if (empty($token)) {
        retornar_json(false, 'Token não fornecido');
    }
    
    error_log("[STATUS] Verificando status do token: $token");
    
    $stmt = $conexao->prepare("
        SELECT 
            t.*,
            a.qr_code,
            a.tipo_acesso,
            v.nome_completo as visitante_nome,
            v.documento as visitante_documento,
            CASE 
                WHEN t.usado = 1 THEN 'usado'
                WHEN t.expira_em < NOW() THEN 'expirado'
                WHEN CURDATE() < a.data_inicial OR CURDATE() > a.data_final THEN 'fora_periodo'
                ELSE 'ativo'
            END as status
        FROM qrcode_tokens t
        INNER JOIN acessos_visitantes a ON t.acesso_id = a.id
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE t.token = ?
    ");
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$resultado) {
        retornar_json(false, 'Token não encontrado');
    }
    
    retornar_json(true, 'Status do token', $resultado);
}

// ========================================
// AÇÃO NÃO RECONHECIDA
// ========================================
retornar_json(false, 'Ação não reconhecida', [
    'action' => $action,
    'acoes_disponiveis' => [
        'validar' => 'Valida token sem marcar como usado',
        'validar_e_usar' => 'Valida e marca token como usado (uso único)',
        'invalidar' => 'Invalida token manualmente',
        'estatisticas' => 'Retorna estatísticas de tokens',
        'limpar_expirados' => 'Remove tokens expirados há mais de 30 dias',
        'listar_ativos' => 'Lista tokens ativos',
        'status' => 'Verifica status de um token específico'
    ]
]);
?>
