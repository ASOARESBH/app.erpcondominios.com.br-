<?php
/**
 * API DO CONSOLE DE ACESSO
 * Validação de QR Codes, gerenciamento de acessos temporários
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'funcoes_log.php';

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

// ========================================
// VALIDAR QR CODE
// ========================================
if ($metodo === 'POST' && $action === 'validar_qrcode') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $qr_code = $dados['qr_code'] ?? '';
    $console_usuario = $dados['console_usuario'] ?? 'Console';
    $dispositivo_token = $dados['dispositivo_token'] ?? '';
    
    if (!$qr_code) {
        retornar_json(false, "QR Code não informado");
    }
    
    // ========================================
    // VALIDAÇÃO DO DISPOSITIVO (TABLET)
    // ========================================
    if (!$dispositivo_token) {
        registrar_log('ACESSO_NEGADO', "Dispositivo não identificado", "QR Code: {$qr_code}");
        retornar_json(false, "Dispositivo não autorizado: Token não fornecido");
    }
    
    // Verificar se o dispositivo existe e está ativo
    $stmt_dispositivo = $conexao->prepare("
        SELECT id, nome_dispositivo, ativo, total_acessos
        FROM dispositivos_console
        WHERE token_acesso = ?
    ");
    $stmt_dispositivo->bind_param("s", $dispositivo_token);
    $stmt_dispositivo->execute();
    $dispositivo = $stmt_dispositivo->get_result()->fetch_assoc();
    $stmt_dispositivo->close();
    
    if (!$dispositivo) {
        registrar_log('ACESSO_NEGADO', "Dispositivo não encontrado", "Token: {$dispositivo_token}, QR Code: {$qr_code}");
        retornar_json(false, "Dispositivo não autorizado: Token inválido");
    }
    
    if ($dispositivo['ativo'] != 1) {
        registrar_log('ACESSO_NEGADO', "Dispositivo inativo", "Dispositivo: {$dispositivo['nome_dispositivo']}, QR Code: {$qr_code}");
        retornar_json(false, "Dispositivo não autorizado: Dispositivo inativo");
    }
    
    // Atualizar último acesso e total de validações do dispositivo
    $novo_total = $dispositivo['total_acessos'] + 1;
    $stmt_update_disp = $conexao->prepare("
        UPDATE dispositivos_console 
        SET data_ultimo_acesso = NOW(), 
            total_acessos = ?,
            ip_ultimo_acesso = ?
        WHERE id = ?
    ");
    $ip_validacao_temp = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt_update_disp->bind_param("isi", $novo_total, $ip_validacao_temp, $dispositivo['id']);
    $stmt_update_disp->execute();
    $stmt_update_disp->close();
    
    $dispositivo_id = $dispositivo['id'];
    $dispositivo_nome = $dispositivo['nome_dispositivo'];
    
    error_log("[VALIDACAO] Dispositivo autorizado: {$dispositivo_nome} (ID: {$dispositivo_id})");
    // ========================================
    
    $ip_validacao = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $data_hora = date('Y-m-d H:i:s');
    $data_atual = date('Y-m-d');
    $hora_atual = date('H:i:s');
    
    // Verificar se é QR Code de visitante
    $stmt = $conexao->prepare("
        SELECT 
            a.*,
            v.nome_completo AS visitante_nome,
            v.documento AS visitante_documento,
            m.nome AS morador_nome,
            m.unidade AS morador_unidade
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        LEFT JOIN moradores m ON a.morador_id = m.id
        WHERE a.qr_code = ? AND a.ativo = 1
    ");
    $stmt->bind_param("s", $qr_code);
    $stmt->execute();
    $acesso = $stmt->get_result()->fetch_assoc();
    
    if ($acesso) {
        // Verificar se é temporário (delivery)
        if ($acesso['temporario'] == 1) {
            // Validar hora
            if ($acesso['data_acesso'] != $data_atual) {
                registrarValidacao($conexao, 'visitante', $acesso['id'], null, $qr_code, $acesso['token_acesso'], 'negado', 'Data expirada', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
                retornar_json(false, "Acesso negado: Data expirada");
            }
            
            if ($hora_atual < $acesso['hora_inicial'] || $hora_atual > $acesso['hora_final']) {
                registrarValidacao($conexao, 'visitante', $acesso['id'], null, $qr_code, $acesso['token_acesso'], 'negado', 'Fora do horário permitido', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
                retornar_json(false, "Acesso negado: Fora do horário permitido ({$acesso['hora_inicial']} - {$acesso['hora_final']})");
            }
        } else {
            // Validar período normal
            if ($data_atual < $acesso['data_inicial'] || $data_atual > $acesso['data_final']) {
                registrarValidacao($conexao, 'visitante', $acesso['id'], null, $qr_code, $acesso['token_acesso'], 'negado', 'Período expirado', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
                retornar_json(false, "Acesso negado: Período expirado");
            }
        }
        
        // Validar token se existir
        if ($acesso['token_acesso']) {
            $token_valido = validarToken($acesso['token_acesso'], $acesso['data_final']);
            if (!$token_valido) {
                registrarValidacao($conexao, 'visitante', $acesso['id'], null, $qr_code, $acesso['token_acesso'], 'negado', 'Token expirado', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
                retornar_json(false, "Acesso negado: Token expirado");
            }
        }
        
        // ACESSO PERMITIDO
        registrarValidacao($conexao, 'visitante', $acesso['id'], null, $qr_code, $acesso['token_acesso'], 'permitido', null, $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
        
        // Registrar no controle de acesso
        registrarControleAcessoValidacao($conexao, [
            'placa' => $acesso['placa'],
            'modelo' => $acesso['modelo'],
            'cor' => $acesso['cor'],
            'tipo' => ucfirst($acesso['tipo_visitante']),
            'morador_id' => $acesso['morador_id'],
            'nome_visitante' => $acesso['visitante_nome'],
            'unidade_destino' => $acesso['unidade_destino'],
            'dias_permanencia' => $acesso['dias_permanencia'],
            'status' => 'Acesso liberado via QR Code',
            'liberado' => 1,
            'observacao' => $acesso['temporario'] == 1 ? "Acesso temporário: {$acesso['hora_inicial']} - {$acesso['hora_final']}" : "Tipo de acesso: {$acesso['tipo_acesso']}"
        ]);
        
        registrar_log('ACESSO_PERMITIDO', "Acesso liberado para: {$acesso['visitante_nome']}", "QR Code: {$qr_code}, Console: {$console_usuario}");
        
        $tipo_texto = $acesso['temporario'] == 1 ? 'DELIVERY' : strtoupper($acesso['tipo_visitante']);
        
        retornar_json(true, "✅ ACESSO PERMITIDO", [
            'tipo' => 'visitante',
            'visitante' => $acesso['visitante_nome'],
            'documento' => $acesso['visitante_documento'],
            'tipo_visitante' => $tipo_texto,
            'morador' => $acesso['morador_nome'],
            'unidade' => $acesso['morador_unidade'] ?? $acesso['unidade_destino'],
            'tipo_acesso' => strtoupper($acesso['tipo_acesso']),
            'temporario' => $acesso['temporario'] == 1,
            'horario' => $acesso['temporario'] == 1 ? "{$acesso['hora_inicial']} - {$acesso['hora_final']}" : null,
            'veiculo' => $acesso['placa'] ? "{$acesso['placa']} - {$acesso['modelo']} {$acesso['cor']}" : null,
            'valido_ate' => $acesso['temporario'] == 1 ? $acesso['data_acesso'] : $acesso['data_final']
        ]);
    }
    
    // Verificar se é QR Code temporário
    $stmt_temp = $conexao->prepare("
        SELECT * FROM qrcodes_temporarios
        WHERE qr_code = ? AND ativo = 1
    ");
    $stmt_temp->bind_param("s", $qr_code);
    $stmt_temp->execute();
    $temp = $stmt_temp->get_result()->fetch_assoc();
    
    if ($temp) {
        // Validar data
        if ($temp['data_acesso'] != $data_atual) {
            registrarValidacao($conexao, 'temporario', null, $temp['id'], $qr_code, $temp['token'], 'negado', 'Data expirada', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
            retornar_json(false, "Acesso negado: Data expirada");
        }
        
        // Validar hora
        if ($hora_atual < $temp['hora_inicial'] || $hora_atual > $temp['hora_final']) {
            registrarValidacao($conexao, 'temporario', null, $temp['id'], $qr_code, $temp['token'], 'negado', 'Fora do horário permitido', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
            retornar_json(false, "Acesso negado: Fora do horário permitido ({$temp['hora_inicial']} - {$temp['hora_final']})");
        }
        
        // Validar se já foi usado
        if ($temp['usado'] == 1) {
            registrarValidacao($conexao, 'temporario', null, $temp['id'], $qr_code, $temp['token'], 'negado', 'QR Code já utilizado', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
            retornar_json(false, "Acesso negado: QR Code já utilizado em {$temp['data_uso']}");
        }
        
        // Validar token
        $token_valido = validarToken($temp['token'], $temp['data_acesso']);
        if (!$token_valido) {
            registrarValidacao($conexao, 'temporario', null, $temp['id'], $qr_code, $temp['token'], 'negado', 'Token expirado', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
            retornar_json(false, "Acesso negado: Token expirado");
        }
        
        // ACESSO PERMITIDO
        // Marcar como usado
        $stmt_update = $conexao->prepare("UPDATE qrcodes_temporarios SET usado = 1, data_uso = ?, ip_uso = ? WHERE id = ?");
        $stmt_update->bind_param("ssi", $data_hora, $ip_validacao, $temp['id']);
        $stmt_update->execute();
        
        registrarValidacao($conexao, 'temporario', null, $temp['id'], $qr_code, $temp['token'], 'permitido', null, $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
        
        // Registrar no controle de acesso
        registrarControleAcessoValidacao($conexao, [
            'placa' => $temp['placa'],
            'modelo' => null,
            'cor' => null,
            'tipo' => 'Prestador',
            'morador_id' => null,
            'nome_visitante' => $temp['nome_entregador'] ?? 'Entregador',
            'unidade_destino' => $temp['unidade_destino'],
            'dias_permanencia' => null,
            'status' => 'Acesso temporário liberado',
            'liberado' => 1,
            'observacao' => "Delivery: {$temp['empresa']}, Horário: {$temp['hora_inicial']} - {$temp['hora_final']}"
        ]);
        
        registrar_log('ACESSO_PERMITIDO', "Acesso temporário liberado: {$temp['nome_entregador']}", "QR Code: {$qr_code}, Empresa: {$temp['empresa']}, Console: {$console_usuario}");
        
        retornar_json(true, "✅ ACESSO PERMITIDO (DELIVERY)", [
            'tipo' => 'temporario',
            'entregador' => $temp['nome_entregador'] ?? 'Entregador',
            'empresa' => $temp['empresa'],
            'telefone' => $temp['telefone'],
            'unidade' => $temp['unidade_destino'],
            'tipo_acesso' => strtoupper($temp['tipo_acesso']),
            'horario' => "{$temp['hora_inicial']} - {$temp['hora_final']}",
            'veiculo' => $temp['placa'] ?? null,
            'valido_ate' => $temp['data_acesso']
        ]);
    }
    
    // QR Code não encontrado
    registrarValidacao($conexao, 'visitante', null, null, $qr_code, null, 'negado', 'QR Code não encontrado', $data_hora, $ip_validacao, $user_agent, $console_usuario, $dispositivo_id);
    registrar_log('ACESSO_NEGADO', "QR Code não encontrado", "QR Code: {$qr_code}, Console: {$console_usuario}");
    retornar_json(false, "❌ Acesso negado: QR Code não encontrado ou inválido");
}

// ========================================
// CRIAR QR CODE TEMPORÁRIO (DELIVERY)
// ========================================
if ($metodo === 'POST' && $action === 'criar_temporario') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome_entregador = $dados['nome_entregador'] ?? null;
    $empresa = $dados['empresa'] ?? null;
    $telefone = $dados['telefone'] ?? null;
    $placa = $dados['placa'] ?? null;
    $unidade_destino = $dados['unidade_destino'] ?? null;
    $hora_inicial = $dados['hora_inicial'] ?? null;
    $hora_final = $dados['hora_final'] ?? null;
    $data_acesso = $dados['data_acesso'] ?? date('Y-m-d');
    $tipo_acesso = $dados['tipo_acesso'] ?? 'portaria';
    
    if (!$hora_inicial || !$hora_final) {
        retornar_json(false, "Hora inicial e final são obrigatórias");
    }
    
    // Gerar QR Code e Token únicos
    $qr_code = 'TEMP-' . strtoupper(uniqid()) . '-' . time();
    $token = bin2hex(random_bytes(32));
    
    $stmt = $conexao->prepare("
        INSERT INTO qrcodes_temporarios 
        (qr_code, token, nome_entregador, empresa, telefone, placa, unidade_destino, hora_inicial, hora_final, data_acesso, tipo_acesso)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssssssss", $qr_code, $token, $nome_entregador, $empresa, $telefone, $placa, $unidade_destino, $hora_inicial, $hora_final, $data_acesso, $tipo_acesso);
    
    if ($stmt->execute()) {
        $temp_id = $conexao->insert_id;
        
        registrar_log('QRCODE_TEMPORARIO_CRIADO', "QR Code temporário criado", "Empresa: {$empresa}, Horário: {$hora_inicial} - {$hora_final}");
        
        retornar_json(true, "QR Code temporário criado com sucesso", [
            'id' => $temp_id,
            'qr_code' => $qr_code,
            'token' => $token,
            'valido_ate' => "{$data_acesso} {$hora_final}"
        ]);
    } else {
        retornar_json(false, "Erro ao criar QR Code temporário: " . $stmt->error);
    }
}

// ========================================
// LISTAR VALIDAÇÕES RECENTES
// ========================================
if ($metodo === 'GET' && $action === 'validacoes') {
    $limite = $_GET['limite'] ?? 50;
    
    $stmt = $conexao->prepare("
        SELECT * FROM validacoes_acesso
        ORDER BY data_hora DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limite);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $validacoes = [];
    while ($row = $result->fetch_assoc()) {
        $validacoes[] = $row;
    }
    
    retornar_json(true, "Validações obtidas com sucesso", $validacoes);
}

// ========================================
// ESTATÍSTICAS DO CONSOLE
// ========================================
if ($metodo === 'GET' && $action === 'estatisticas') {
    $data_hoje = date('Y-m-d');
    
    // Total de validações hoje
    $stmt_total = $conexao->prepare("SELECT COUNT(*) as total FROM validacoes_acesso WHERE DATE(data_hora) = ?");
    $stmt_total->bind_param("s", $data_hoje);
    $stmt_total->execute();
    $total = $stmt_total->get_result()->fetch_assoc()['total'];
    
    // Acessos permitidos hoje
    $stmt_permitidos = $conexao->prepare("SELECT COUNT(*) as total FROM validacoes_acesso WHERE DATE(data_hora) = ? AND resultado = 'permitido'");
    $stmt_permitidos->bind_param("s", $data_hoje);
    $stmt_permitidos->execute();
    $permitidos = $stmt_permitidos->get_result()->fetch_assoc()['total'];
    
    // Acessos negados hoje
    $stmt_negados = $conexao->prepare("SELECT COUNT(*) as total FROM validacoes_acesso WHERE DATE(data_hora) = ? AND resultado = 'negado'");
    $stmt_negados->bind_param("s", $data_hoje);
    $stmt_negados->execute();
    $negados = $stmt_negados->get_result()->fetch_assoc()['total'];
    
    // Acessos ativos agora
    $hora_atual = date('H:i:s');
    $stmt_ativos = $conexao->prepare("
        SELECT COUNT(*) as total FROM acessos_visitantes
        WHERE ativo = 1 
        AND ? BETWEEN data_inicial AND data_final
        AND (temporario = 0 OR (temporario = 1 AND ? BETWEEN hora_inicial AND hora_final))
    ");
    $stmt_ativos->bind_param("ss", $data_hoje, $hora_atual);
    $stmt_ativos->execute();
    $ativos = $stmt_ativos->get_result()->fetch_assoc()['total'];
    
    retornar_json(true, "Estatísticas obtidas com sucesso", [
        'total_validacoes' => $total,
        'acessos_permitidos' => $permitidos,
        'acessos_negados' => $negados,
        'acessos_ativos' => $ativos
    ]);
}

// ========================================
// FUNÇÕES AUXILIARES
// ========================================

function validarToken($token, $data_validade) {
    // Token é válido se a data de validade ainda não passou
    $data_atual = date('Y-m-d');
    return $data_atual <= $data_validade;
}

function registrarValidacao($conexao, $tipo, $acesso_id, $qrcode_temp_id, $qr_code, $token, $resultado, $motivo, $data_hora, $ip, $user_agent, $console_usuario, $dispositivo_id = null) {
    try {
        $stmt = $conexao->prepare("
            INSERT INTO validacoes_acesso 
            (tipo_validacao, acesso_id, qrcode_temporario_id, qr_code, token, resultado, motivo, data_hora, ip_validacao, user_agent, console_usuario, dispositivo_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("siissssssssi", $tipo, $acesso_id, $qrcode_temp_id, $qr_code, $token, $resultado, $motivo, $data_hora, $ip, $user_agent, $console_usuario, $dispositivo_id);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Erro ao registrar validação: " . $e->getMessage());
        return false;
    }
}

function registrarControleAcessoValidacao($conexao, $dados) {
    try {
        $data_hora = date('Y-m-d H:i:s');
        $placa = $dados['placa'] ?? null;
        $modelo = $dados['modelo'] ?? null;
        $cor = $dados['cor'] ?? null;
        $tipo = $dados['tipo'] ?? 'Visitante';
        $morador_id = $dados['morador_id'] ?? null;
        $nome_visitante = $dados['nome_visitante'] ?? null;
        $unidade_destino = $dados['unidade_destino'] ?? null;
        $dias_permanencia = $dados['dias_permanencia'] ?? null;
        $status = $dados['status'] ?? 'Liberado';
        $liberado = $dados['liberado'] ?? 1;
        $observacao = $dados['observacao'] ?? null;
        
        $stmt = $conexao->prepare("
            INSERT INTO registros_acesso 
            (data_hora, placa, modelo, cor, tipo, morador_id, nome_visitante, unidade_destino, dias_permanencia, status, liberado, observacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "ssssissisiss",
            $data_hora, $placa, $modelo, $cor, $tipo, $morador_id,
            $nome_visitante, $unidade_destino, $dias_permanencia,
            $status, $liberado, $observacao
        );
        
        $stmt->execute();
        return $conexao->insert_id;
    } catch (Exception $e) {
        error_log("Erro ao registrar controle de acesso: " . $e->getMessage());
        return null;
    }
}

// ========================================
// AÇÃO NÃO ENCONTRADA
// ========================================
http_response_code(404);
retornar_json(false, "Ação não encontrada");
