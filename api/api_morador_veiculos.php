<?php
// =====================================================
// API PARA GERENCIAR VEÍCULOS DO MORADOR LOGADO
// =====================================================

session_start();

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

// Limpar buffer e definir headers
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

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Verificar se o morador está logado
if (!isset($_SESSION['morador_logado']) || $_SESSION['morador_logado'] !== true) {
    retornar_json(false, "Sessão inválida. Faça login novamente.");
}

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$morador_id = $_SESSION['morador_id'];

// ========== LISTAR VEÍCULOS DO MORADOR ==========
if ($metodo === 'GET') {
    $sql = "SELECT id, placa, modelo, cor, tag, ativo, 
            DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro 
            FROM veiculos 
            WHERE morador_id = ? 
            ORDER BY data_cadastro DESC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $veiculos = array();
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $veiculos[] = $row;
        }
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, "Veículos listados com sucesso", $veiculos);
}

// ========== CRIAR VEÍCULO ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $placa = strtoupper(sanitizar($conexao, $dados['placa'] ?? ''));
    $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
    $cor = sanitizar($conexao, $dados['cor'] ?? '');
    
    // Validações
    if (empty($placa) || empty($modelo) || empty($cor)) {
        retornar_json(false, "Todos os campos são obrigatórios");
    }
    
    // Verificar se a placa já existe
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE placa = ?");
    $stmt->bind_param("s", $placa);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Placa já cadastrada no sistema");
    }
    $stmt->close();
    
    // Gerar TAG automática (TAGM001, TAGM002, etc.)
    // Buscar o último número de TAG do morador
    $stmt = $conexao->prepare("SELECT tag FROM veiculos WHERE morador_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $proximo_numero = 1;
    if ($resultado->num_rows > 0) {
        $ultima_tag = $resultado->fetch_assoc()['tag'];
        // Extrair número da TAG (ex: TAGM001 -> 001)
        if (preg_match('/TAGM(\d+)/', $ultima_tag, $matches)) {
            $proximo_numero = intval($matches[1]) + 1;
        }
    }
    $stmt->close();
    
    // Formatar TAG com zeros à esquerda
    $tag = 'TAGM' . str_pad($proximo_numero, 3, '0', STR_PAD_LEFT);
    
    // Verificar se a TAG gerada já existe (segurança adicional)
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE tag = ?");
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        // Se já existe, gerar uma TAG única baseada em timestamp
        $tag = 'TAGM' . time();
    }
    $stmt->close();
    
    // Inserir veículo
    $stmt = $conexao->prepare("INSERT INTO veiculos (placa, modelo, cor, tag, morador_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $placa, $modelo, $cor, $tag, $morador_id);
    
    if ($stmt->execute()) {
        $id_inserido = $conexao->insert_id;
        registrar_log('VEICULO_MORADOR_CRIADO', "Morador {$_SESSION['morador_nome']} cadastrou veículo: $placa - $modelo", $_SESSION['morador_nome']);
        
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Veículo cadastrado com sucesso! TAG: $tag", array('id' => $id_inserido, 'tag' => $tag));
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Erro ao cadastrar veículo: " . $stmt->error);
    }
}

// ========== ATUALIZAR VEÍCULO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($dados['id'] ?? 0);
    $placa = strtoupper(sanitizar($conexao, $dados['placa'] ?? ''));
    $modelo = sanitizar($conexao, $dados['modelo'] ?? '');
    $cor = sanitizar($conexao, $dados['cor'] ?? '');
    
    // Validações
    if ($id <= 0 || empty($placa) || empty($modelo) || empty($cor)) {
        retornar_json(false, "Dados inválidos para atualização");
    }
    
    // Verificar se o veículo pertence ao morador logado
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Veículo não encontrado ou não pertence a você");
    }
    $stmt->close();
    
    // Verificar se a placa já existe em outro veículo
    $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE placa = ? AND id != ?");
    $stmt->bind_param("si", $placa, $id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Placa já cadastrada para outro veículo");
    }
    $stmt->close();
    
    // Atualizar veículo
    $stmt = $conexao->prepare("UPDATE veiculos SET placa=?, modelo=?, cor=? WHERE id=? AND morador_id=?");
    $stmt->bind_param("sssii", $placa, $modelo, $cor, $id, $morador_id);
    
    if ($stmt->execute()) {
        registrar_log('VEICULO_MORADOR_ATUALIZADO', "Morador {$_SESSION['morador_nome']} atualizou veículo: $placa - $modelo", $_SESSION['morador_nome']);
        
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Veículo atualizado com sucesso!");
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Erro ao atualizar veículo: " . $stmt->error);
    }
}

// ========== EXCLUIR VEÍCULO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, "ID inválido");
    }
    
    // Verificar se o veículo pertence ao morador logado e buscar dados
    $stmt = $conexao->prepare("SELECT placa, modelo FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Veículo não encontrado ou não pertence a você");
    }
    
    $veiculo = $resultado->fetch_assoc();
    $stmt->close();
    
    // Excluir veículo
    $stmt = $conexao->prepare("DELETE FROM veiculos WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $id, $morador_id);
    
    if ($stmt->execute()) {
        registrar_log('VEICULO_MORADOR_EXCLUIDO', "Morador {$_SESSION['morador_nome']} excluiu veículo: {$veiculo['placa']} - {$veiculo['modelo']}", $_SESSION['morador_nome']);
        
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Veículo excluído com sucesso!");
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Erro ao excluir veículo: " . $stmt->error);
    }
}

fechar_conexao($conexao);
retornar_json(false, "Método não permitido");

