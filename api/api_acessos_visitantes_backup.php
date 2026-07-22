<?php
/**
 * API DE ACESSOS DE VISITANTES
 * Gerencia períodos de permanência, tipos de acesso e QR Codes
 */

require_once 'config.php';

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
// LISTAR ACESSOS
// ========================================
if ($metodo === 'GET' && empty($action)) {
    $visitante_id = $_GET['visitante_id'] ?? null;
    
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
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $visitante_id = $dados['visitante_id'] ?? 0;
    $data_inicial = $dados['data_inicial'] ?? '';
    $data_final = $dados['data_final'] ?? '';
    $tipo_acesso = $dados['tipo_acesso'] ?? '';
    
    // Validações
    if (!$visitante_id || !$data_inicial || !$data_final || !$tipo_acesso) {
        retornar_json(false, "Todos os campos são obrigatórios");
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
    
    // Gerar código único para QR Code
    $qr_code = 'ACESSO-' . strtoupper(uniqid()) . '-' . time();
    
    // Inserir no banco
    $stmt = $conexao->prepare("
        INSERT INTO acessos_visitantes 
        (visitante_id, data_inicial, data_final, dias_permanencia, tipo_acesso, qr_code)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ississ", $visitante_id, $data_inicial, $data_final, $dias_permanencia, $tipo_acesso, $qr_code);
    
    if ($stmt->execute()) {
        $acesso_id = $conexao->insert_id;
        
        // Buscar dados do visitante para log
        $stmt_visitante = $conexao->prepare("SELECT nome_completo FROM visitantes WHERE id = ?");
        $stmt_visitante->bind_param("i", $visitante_id);
        $stmt_visitante->execute();
        $visitante = $stmt_visitante->get_result()->fetch_assoc();
        
        registrar_log('ACESSO_CADASTRADO', "Acesso cadastrado para visitante: {$visitante['nome_completo']}", "Tipo: {$tipo_acesso}, Período: {$data_inicial} a {$data_final}");
        
        retornar_json(true, "Acesso cadastrado com sucesso", [
            'id' => $acesso_id,
            'qr_code' => $qr_code,
            'dias_permanencia' => $dias_permanencia
        ]);
    } else {
        retornar_json(false, "Erro ao cadastrar acesso: " . $stmt->error);
    }
}

// ========================================
// ATUALIZAR ACESSO
// ========================================
if ($metodo === 'PUT') {
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
    
    $sql = "UPDATE acessos_visitantes SET " . implode(", ", $campos) . " WHERE id = ?";
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
// EXCLUIR ACESSO
// ========================================
if ($metodo === 'DELETE') {
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
    
    // Excluir
    $stmt = $conexao->prepare("DELETE FROM acessos_visitantes WHERE id = ?");
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
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        retornar_json(false, "ID do acesso não fornecido");
    }
    
    // Buscar dados do acesso
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
        retornar_json(false, "Acesso não encontrado");
    }
    
    // Dados para o QR Code (JSON)
    $qr_data = json_encode([
        'codigo' => $acesso['qr_code'],
        'visitante' => $acesso['nome_completo'],
        'documento' => $acesso['documento'],
        'tipo_acesso' => $acesso['tipo_acesso'],
        'data_inicial' => $acesso['data_inicial'],
        'data_final' => $acesso['data_final'],
        'valido_ate' => $acesso['data_final']
    ]);
    
    // URL da API do Google Charts para gerar QR Code
    $qr_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_data) . '&choe=UTF-8';
    
    // Buscar imagem
    $qr_image = @file_get_contents($qr_url);
    
    if ($qr_image === false) {
        retornar_json(false, "Erro ao gerar QR Code");
    }
    
    // Converter para base64
    $qr_base64 = 'data:image/png;base64,' . base64_encode($qr_image);
    
    // Salvar no banco (opcional)
    $stmt_update = $conexao->prepare("UPDATE acessos_visitantes SET qr_code_imagem = ? WHERE id = ?");
    $stmt_update->bind_param("si", $qr_base64, $id);
    $stmt_update->execute();
    
    retornar_json(true, "QR Code gerado com sucesso", [
        'qr_code_imagem' => $qr_base64,
        'qr_data' => $qr_data,
        'acesso' => $acesso
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
// AÇÃO NÃO ENCONTRADA
// ========================================
http_response_code(404);
retornar_json(false, "Ação não encontrada");
