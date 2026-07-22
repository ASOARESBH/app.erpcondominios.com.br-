<?php
/**
 * API DE ACESSOS DE VISITANTES
 * Gerencia períodos de permanência, tipos de acesso e QR Codes
 */

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

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

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação para operações de escrita
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Verificar se é requisição do Portal do Morador (token Bearer) ──
$portal_morador_id = null;
$auth_header_av = '';
if (function_exists('getallheaders')) {
    $hdrs_av = getallheaders();
    $auth_header_av = $hdrs_av['Authorization'] ?? $hdrs_av['authorization'] ?? '';
}
if (empty($auth_header_av)) {
    $auth_header_av = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}
if (preg_match('/Bearer\s+(.+)$/i', $auth_header_av, $m_av)) {
    $bearer_av = trim($m_av[1]);
    $cx_av = conectar_banco();
    $st_av = $cx_av->prepare("SELECT morador_id FROM sessoes_portal WHERE tenant_id = $tenant_id AND token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1");
    $st_av->bind_param('s', $bearer_av);
    $st_av->execute();
    $rr_av = $st_av->get_result();
    if ($rr_av->num_rows > 0) {
        $portal_morador_id = (int)$rr_av->fetch_assoc()['morador_id'];
    }
    $st_av->close();
    fechar_conexao($cx_av);
}

if ($metodo !== 'GET' && !$portal_morador_id) {
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
}

$conexao = conectar_banco();

// ========================================
// LISTAR ACESSOS (portal do morador por morador_id)
// ========================================
if ($metodo === 'GET' && empty($action)) {
    $visitante_id = $_GET['visitante_id'] ?? null;
    $morador_id_get = isset($_GET['morador_id']) ? (int)$_GET['morador_id'] : null;

    // Se for requisição do portal do morador via Bearer, usar morador_id da sessão
    if ($portal_morador_id) {
        // Segurança: morador só pode ver seus próprios acessos
        $morador_id_get = $portal_morador_id;
        $sql = "SELECT a.*, v.nome_completo as visitante_nome, v.documento
                FROM acessos_visitantes a
                INNER JOIN visitantes v ON a.visitante_id = v.id
                WHERE a.morador_id = ?
                ORDER BY a.data_cadastro DESC";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param('i', $morador_id_get);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $acessos = [];
        while ($row = $resultado->fetch_assoc()) {
            $acessos[] = $row;
        }
        retornar_json(true, "Acessos obtidos com sucesso", $acessos);
    }

    // Requisição do ERP — verificar autenticação de sessão
    verificarAutenticacao(true, 'operador');
    
    $sql = "SELECT a.*, v.nome_completo as visitante_nome, v.documento
            FROM acessos_visitantes a
            INNER JOIN visitantes v ON a.visitante_id = v.id";
    
    $params = [];
    $types = "";
    
    if ($visitante_id) {
        $sql .= " WHERE a.visitante_id = ?";
        $params[] = $visitante_id;
        $types .= "i";
    }
    
    $sql .= " ORDER BY a.data_cadastro DESC";
    
    if (!empty($params)) {
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();
    } else {
        $resultado = $conexao->query($sql);
    }
    
    $acessos = [];
    while ($row = $resultado->fetch_assoc()) {
        $acessos[] = $row;
    }
    
    retornar_json(true, "Acessos obtidos com sucesso", $acessos);
}

// ========================================
// OBTER ACESSO POR ID
// ========================================
if ($metodo === 'GET' && $action === 'obter') {
    verificarAutenticacao(true, 'operador');
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do acesso não fornecido");
    }
    
    $stmt = $conexao->prepare("
        SELECT a.*, v.nome_completo as visitante_nome, v.documento
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $acesso = $resultado->fetch_assoc();
        retornar_json(true, "Acesso obtido com sucesso", $acesso);
    } else {
        retornar_json(false, "Acesso não encontrado");
    }
}

// ========================================
// CADASTRAR ACESSO
// ========================================
if ($metodo === 'POST') {
    // Permitir acesso via token Bearer do portal do morador
    if (!$portal_morador_id) {
        verificarAutenticacao(true, 'operador');
    }
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $visitante_id = $dados['visitante_id'] ?? 0;
    $data_inicial = $dados['data_inicial'] ?? '';
    $data_final = $dados['data_final'] ?? '';
    $tipo_acesso = $dados['tipo_acesso'] ?? '';
    $placa = $dados['placa'] ?? null;
    $modelo = $dados['modelo'] ?? null;
    $cor = $dados['cor'] ?? null;
    $tipo_visitante = $dados['tipo_visitante'] ?? 'visitante';
    $morador_id = $dados['morador_id'] ?? null;
    $unidade_destino = $dados['unidade_destino'] ?? null;
    
    // Validações
    if (!$visitante_id || !$data_inicial || !$data_final || !$tipo_acesso) {
        retornar_json(false, "Todos os campos são obrigatórios");
    }
    
    // REGRA: 1 visitante = 1 acesso no sistema todo
    // Verificar se visitante já possui acesso ativo em qualquer unidade
    $stmt_check = $conexao->prepare("
        SELECT a.id, a.unidade_destino, v.nome_completo
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE a.visitante_id = ?
        AND a.data_final >= CURDATE()
        AND a.ativo = 1
        LIMIT 1
    ");
    $stmt_check->bind_param("i", $visitante_id);
    $stmt_check->execute();
    $acesso_existente = $stmt_check->get_result()->fetch_assoc();
    
    if ($acesso_existente) {
        $unidade_atual = $acesso_existente['unidade_destino'] ?? 'Unidade não informada';
        retornar_json(false, "Visitante já possui acesso ativo em outra unidade ({$unidade_atual}). Um visitante só pode ter um acesso ativo por vez.");
    }
    
    // Validar tipo de acesso
    $tipos_validos = ['portaria', 'externo', 'lagoa'];
    if (!in_array($tipo_acesso, $tipos_validos)) {
        retornar_json(false, "Tipo de acesso inválido");
    }
    
    // Validar datas
    $dt_inicial = new DateTime($data_inicial);
    $dt_final = new DateTime($data_final);
    
    if ($dt_final < $dt_inicial) {
        retornar_json(false, "Data final deve ser maior ou igual à data inicial");
    }
    
    // Calcular dias de permanência
    $intervalo = $dt_inicial->diff($dt_final);
    $dias_permanencia = $intervalo->days + 1; // +1 para incluir o dia inicial
    
    // Gerar código único para QR Code e Token
    $qr_code = 'ACESSO-' . strtoupper(uniqid()) . '-' . time();
    $token_acesso = bin2hex(random_bytes(32));
    
    // Verificar se é temporário (delivery)
    $temporario = $dados['temporario'] ?? 0;
    $hora_inicial = $dados['hora_inicial'] ?? null;
    $hora_final = $dados['hora_final'] ?? null;
    
    // Inserir no banco
    $stmt = $conexao->prepare("
        INSERT INTO acessos_visitantes 
        (visitante_id, data_inicial, data_final, dias_permanencia, tipo_acesso, placa, modelo, cor, tipo_visitante, temporario, hora_inicial, hora_final, morador_id, unidade_destino, qr_code, token_acesso)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issississississs", $visitante_id, $data_inicial, $data_final, $dias_permanencia, $tipo_acesso, $placa, $modelo, $cor, $tipo_visitante, $temporario, $hora_inicial, $hora_final, $morador_id, $unidade_destino, $qr_code, $token_acesso);
    
    if ($stmt->execute()) {
        $acesso_id = $conexao->insert_id;
        
        // Buscar dados do visitante para log
        $stmt_visitante = $conexao->prepare("SELECT nome_completo FROM visitantes WHERE tenant_id = $tenant_id AND id = ?");
        $stmt_visitante->bind_param("i", $visitante_id);
        $stmt_visitante->execute();
        $visitante = $stmt_visitante->get_result()->fetch_assoc();
        
        // Registrar no controle de acesso
        $registro_acesso_id = registrarControleAcesso($conexao, [
            'placa' => $placa,
            'modelo' => $modelo,
            'cor' => $cor,
            'tipo' => ucfirst($tipo_visitante),
            'morador_id' => $morador_id,
            'nome_visitante' => $visitante['nome_completo'],
            'unidade_destino' => $unidade_destino,
            'dias_permanencia' => $dias_permanencia,
            'status' => 'Acesso autorizado via QR Code',
            'liberado' => 1,
            'observacao' => "Tipo de acesso: {$tipo_acesso}"
        ]);
        
        // Atualizar acesso com ID do registro
        if ($registro_acesso_id) {
            $stmt_update = $conexao->prepare("UPDATE acessos_visitantes SET registro_acesso_id = ? WHERE tenant_id = $tenant_id AND id = ?");
            $stmt_update->bind_param("ii", $registro_acesso_id, $acesso_id);
            $stmt_update->execute();
        }
        
        registrar_log('ACESSO_CADASTRADO', "Acesso cadastrado para visitante: {$visitante['nome_completo']}", "Tipo: {$tipo_acesso}, Período: {$data_inicial} a {$data_final}, Placa: {$placa}");
        
        retornar_json(true, "Acesso cadastrado com sucesso", [
            'id' => $acesso_id,
            'qr_code' => $qr_code,
            'dias_permanencia' => $dias_permanencia,
            'registro_acesso_id' => $registro_acesso_id
        ]);
    } else {
        retornar_json(false, "Erro ao cadastrar acesso: " . $stmt->error);
    }
}

// ========================================
// ATUALIZAR ACESSO
// ========================================
if ($metodo === 'PUT') {
    verificarAutenticacao(true, 'operador');
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = $dados['id'] ?? 0;
    $data_inicial = $dados['data_inicial'] ?? '';
    $data_final = $dados['data_final'] ?? '';
    $tipo_acesso = $dados['tipo_acesso'] ?? '';
    $ativo = $dados['ativo'] ?? 1;
    
    if (!$id) {
        retornar_json(false, "ID do acesso não fornecido");
    }
    
    // Validar tipo de acesso
    $tipos_validos = ['portaria', 'externo', 'lagoa'];
    if ($tipo_acesso && !in_array($tipo_acesso, $tipos_validos)) {
        retornar_json(false, "Tipo de acesso inválido");
    }
    
    // Calcular dias de permanência se datas foram fornecidas
    $dias_permanencia = null;
    if ($data_inicial && $data_final) {
        $dt_inicial = new DateTime($data_inicial);
        $dt_final = new DateTime($data_final);
        
        if ($dt_final < $dt_inicial) {
            retornar_json(false, "Data final deve ser maior ou igual à data inicial");
        }
        
        $intervalo = $dt_inicial->diff($dt_final);
        $dias_permanencia = $intervalo->days + 1;
    }
    
    // Montar query de atualização
    $campos = [];
    $valores = [];
    $types = "";
    
    if ($data_inicial) {
        $campos[] = "data_inicial = ?";
        $valores[] = $data_inicial;
        $types .= "s";
    }
    
    if ($data_final) {
        $campos[] = "data_final = ?";
        $valores[] = $data_final;
        $types .= "s";
    }
    
    if ($dias_permanencia !== null) {
        $campos[] = "dias_permanencia = ?";
        $valores[] = $dias_permanencia;
        $types .= "i";
    }
    
    if ($tipo_acesso) {
        $campos[] = "tipo_acesso = ?";
        $valores[] = $tipo_acesso;
        $types .= "s";
    }
    
    $campos[] = "ativo = ?";
    $valores[] = $ativo;
    $types .= "i";
    
    $valores[] = $id;
    $types .= "i";
    
    $sql = "UPDATE acessos_visitantes SET " . implode(", ", $campos) . " WHERE tenant_id = $tenant_id AND id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$valores);
    
    if ($stmt->execute()) {
        registrar_log('ACESSO_ATUALIZADO', "Acesso atualizado", "ID: {$id}");
        retornar_json(true, "Acesso atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar acesso: " . $stmt->error);
    }
}

// ========================================
// DELETAR ACESSO
// ========================================
if ($metodo === 'DELETE') {
    // Permitir acesso via token Bearer do portal do morador
    if (!$portal_morador_id) {
        verificarAutenticacao(true, 'admin');
    }
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do acesso não fornecido");
    }
    
    // Buscar dados antes de excluir
    $stmt = $conexao->prepare("
        SELECT a.*, v.nome_completo 
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $acesso = $stmt->get_result()->fetch_assoc();
    
    if (!$acesso) {
        retornar_json(false, "Acesso não encontrado");
    }
    
    // Segurança: morador só pode excluir seus próprios acessos
    if ($portal_morador_id && (int)$acesso['morador_id'] !== $portal_morador_id) {
        http_response_code(403);
        retornar_json(false, "Sem permissão para excluir este acesso.");
    }
    
    // Excluir
    $stmt = $conexao->prepare("DELETE FROM acessos_visitantes WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        registrar_log('ACESSO_EXCLUIDO', "Acesso excluído", "Visitante: {$acesso['nome_completo']}, Tipo: {$acesso['tipo_acesso']}");
        retornar_json(true, "Acesso excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir acesso: " . $stmt->error);
    }
}

// ========================================
// GERAR QR CODE (IMAGEM)
// ========================================
if ($metodo === 'GET' && $action === 'gerar_qrcode') {
    error_log("[DEBUG QR] Iniciando geração de QR Code");
    
    $id = $_GET['id'] ?? 0;
    error_log("[DEBUG QR] ID do acesso: $id");
    
    if (!$id) {
        error_log("[DEBUG QR] ERRO: ID não fornecido");
        retornar_json(false, "ID do acesso não fornecido");
    }
    
    // Buscar dados do acesso
    error_log("[DEBUG QR] Buscando dados do acesso no banco");
    $stmt = $conexao->prepare("
        SELECT a.*, v.nome_completo, v.documento
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $acesso = $stmt->get_result()->fetch_assoc();
    
    if (!$acesso) {
        error_log("[DEBUG QR] ERRO: Acesso ID $id não encontrado no banco");
        retornar_json(false, "Acesso não encontrado");
    }
    
    error_log("[DEBUG QR] Acesso encontrado: " . $acesso['nome_completo']);
    
    // NOVO SISTEMA: Gerar token seguro
    require_once 'qrcode_token_manager.php';
    require_once 'qrcode_nativo.php';
    
    error_log("[QR NATIVO] Iniciando geração de QR Code nativo com token seguro");
    
    // Criar gerenciador de tokens
    $tokenManager = new QRCodeTokenManager($conexao);
    
    // Gerar token seguro (validade de 24 horas por padrão)
    $validade_horas = 24;
    $resultado_token = $tokenManager->gerarToken($id, $validade_horas);
    
    if (!$resultado_token['sucesso']) {
        error_log("[QR NATIVO] ERRO ao gerar token: " . $resultado_token['mensagem']);
        retornar_json(false, "Erro ao gerar token de segurança");
    }
    
    $token = $resultado_token['token'];
    error_log("[QR NATIVO] Token gerado: $token (expira em: {$resultado_token['expira_em']})");
    
    // Gerar dados para QR Code (agora com token)
    $qr_data = $tokenManager->gerarDadosQRCode($token, $id);
    
    if (!$qr_data) {
        error_log("[QR NATIVO] ERRO ao gerar dados do QR Code");
        retornar_json(false, "Erro ao preparar dados do QR Code");
    }
    
    error_log("[QR NATIVO] Dados do QR Code preparados. Tamanho: " . strlen($qr_data) . " bytes");
    
    // Gerar QR Code nativamente (PHP puro)
    $qr_base64 = QRCodeNativo::gerarPNG($qr_data);
    
    if ($qr_base64 === false) {
        error_log("[QR NATIVO] ERRO: Falha na geração nativa");
        
        // Verificar requisitos
        $requisitos = QRCodeNativo::verificarRequisitos();
        error_log("[QR NATIVO] Requisitos: " . json_encode($requisitos));
        
        if (!$requisitos['requisitos_ok']) {
            $mensagem_erro = "Erro ao gerar QR Code: ";
            if (!$requisitos['detalhes']['gd_extension']) {
                $mensagem_erro .= "Extensão GD não disponível no PHP.";
            } else if (!$requisitos['detalhes']['qrcode_lib']) {
                $mensagem_erro .= "Biblioteca qrcode_lib.php não encontrada.";
            } else {
                $mensagem_erro .= "Funções de imagem não disponíveis.";
            }
            retornar_json(false, $mensagem_erro, ['requisitos' => $requisitos]);
        }
        
        retornar_json(false, "Erro ao gerar QR Code nativo");
    }
    
    error_log("[QR NATIVO] QR Code gerado com sucesso. Tamanho base64: " . strlen($qr_base64) . " bytes");
    
    // Salvar no banco (opcional)
    error_log("[DEBUG QR] Salvando QR Code no banco de dados...");
    $stmt_update = $conexao->prepare("UPDATE acessos_visitantes SET qr_code_imagem = ? WHERE tenant_id = $tenant_id AND id = ?");
    $stmt_update->bind_param("si", $qr_base64, $id);
    
    if (!$stmt_update->execute()) {
        error_log("[DEBUG QR] AVISO: Falha ao salvar QR Code no banco: " . $stmt_update->error);
    } else {
        error_log("[DEBUG QR] QR Code salvo no banco com sucesso");
    }
    
    error_log("[QR NATIVO] Retornando QR Code para o cliente");
    retornar_json(true, "QR Code gerado com sucesso", [
        'qr_code_imagem' => $qr_base64,
        'qr_data' => $qr_data,
        'token' => [
            'token' => $token,
            'expira_em' => $resultado_token['expira_em'],
            'validade_horas' => $validade_horas
        ],
        'acesso' => $acesso,
        'metodo' => 'nativo_php'
    ]);
}

// ========================================
// VALIDAR QR CODE (PARA CANCELAS)
// ========================================
if ($metodo === 'POST' && $action === 'validar_qrcode') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $qr_code = $dados['qr_code'] ?? '';
    
    if (!$qr_code) {
        retornar_json(false, "Código QR não fornecido");
    }
    
    // Buscar acesso
    $stmt = $conexao->prepare("
        SELECT a.*, v.nome_completo, v.documento
        FROM acessos_visitantes a
        INNER JOIN visitantes v ON a.visitante_id = v.id
        WHERE a.qr_code = ? AND a.ativo = 1
    ");
    $stmt->bind_param("s", $qr_code);
    $stmt->execute();
    $acesso = $stmt->get_result()->fetch_assoc();
    
    if (!$acesso) {
        registrar_log('ACESSO_NEGADO', "QR Code inválido ou inativo", "Código: {$qr_code}");
        retornar_json(false, "Acesso negado: QR Code inválido ou inativo");
    }
    
    // Verificar período de validade
    $hoje = date('Y-m-d');
    
    if ($hoje < $acesso['data_inicial']) {
        registrar_log('ACESSO_NEGADO', "Acesso ainda não iniciado", "Visitante: {$acesso['nome_completo']}, Início: {$acesso['data_inicial']}");
        retornar_json(false, "Acesso negado: Período ainda não iniciado");
    }
    
    if ($hoje > $acesso['data_final']) {
        registrar_log('ACESSO_NEGADO', "Acesso expirado", "Visitante: {$acesso['nome_completo']}, Fim: {$acesso['data_final']}");
        retornar_json(false, "Acesso negado: Período expirado");
    }
    
    // Acesso válido
    registrar_log('ACESSO_PERMITIDO', "Acesso liberado via QR Code", "Visitante: {$acesso['nome_completo']}, Tipo: {$acesso['tipo_acesso']}");
    
    retornar_json(true, "Acesso permitido", [
        'visitante' => $acesso['nome_completo'],
        'documento' => $acesso['documento'],
        'tipo_acesso' => $acesso['tipo_acesso'],
        'valido_ate' => $acesso['data_final']
    ]);
}

// ========================================
// CALCULAR DIAS DE PERMANÊNCIA
// ========================================
if ($metodo === 'GET' && $action === 'calcular_dias') {
    $data_inicial = $_GET['data_inicial'] ?? '';
    $data_final = $_GET['data_final'] ?? '';
    
    if (!$data_inicial || !$data_final) {
        retornar_json(false, "Datas não fornecidas");
    }
    
    $dt_inicial = new DateTime($data_inicial);
    $dt_final = new DateTime($data_final);
    
    if ($dt_final < $dt_inicial) {
        retornar_json(false, "Data final deve ser maior ou igual à data inicial");
    }
    
    $intervalo = $dt_inicial->diff($dt_final);
    $dias = $intervalo->days + 1;
    
    retornar_json(true, "Dias calculados com sucesso", ['dias' => $dias]);
}

// ========================================
// FUNÇÃO AUXILIAR: REGISTRAR NO CONTROLE DE ACESSO
// ========================================
function registrarControleAcesso($conexao, $dados) {
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
        $status = $dados['status'] ?? 'Aguardando';
        $liberado = $dados['liberado'] ?? 0;
        $observacao = $dados['observacao'] ?? null;
        
        $stmt = $conexao->prepare("
            INSERT INTO registros_acesso 
            (data_hora, placa, modelo, cor, tipo, morador_id, nome_visitante, unidade_destino, dias_permanencia, status, liberado, observacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "ssssissisiss",
            $data_hora,
            $placa,
            $modelo,
            $cor,
            $tipo,
            $morador_id,
            $nome_visitante,
            $unidade_destino,
            $dias_permanencia,
            $status,
            $liberado,
            $observacao
        );
        
        if ($stmt->execute()) {
            return $conexao->insert_id;
        }
        
        return null;
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
