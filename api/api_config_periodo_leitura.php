<?php
// =====================================================
// API PARA CONFIGURAÇÃO DE PERÍODO DE LEITURA
// =====================================================

ob_start();
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

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== BUSCAR CONFIGURAÇÃO ATUAL ==========
if ($metodo === 'GET') {
    $sql = "SELECT * FROM config_periodo_leitura WHERE ativo = 1 LIMIT 1";
    $resultado = $conexao->query($sql);
    
    if ($resultado && $resultado->num_rows > 0) {
        $config = $resultado->fetch_assoc();
        
        // Verificar se está no período
        $dia_atual = date('d');
        $esta_no_periodo = ($dia_atual >= $config['dia_inicio'] && $dia_atual <= $config['dia_fim']);
        $config['esta_no_periodo'] = $esta_no_periodo;
        $config['dia_atual'] = $dia_atual;
        
        retornar_json(true, "Configuração encontrada", $config);
    } else {
        // Retornar configuração padrão
        retornar_json(true, "Configuração padrão", array(
            'dia_inicio' => 1,
            'dia_fim' => 10,
            'ativo' => 1,
            'morador_pode_lancar' => 1,
            'esta_no_periodo' => false,
            'dia_atual' => date('d')
        ));
    }
}

// ========== ATUALIZAR CONFIGURAÇÃO ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $dia_inicio = intval($dados['dia_inicio'] ?? 1);
    $dia_fim = intval($dados['dia_fim'] ?? 10);
    $morador_pode_lancar = intval($dados['morador_pode_lancar'] ?? 1);
    
    // Validações
    if ($dia_inicio < 1 || $dia_inicio > 31) {
        retornar_json(false, "Dia inicial deve estar entre 1 e 31");
    }
    
    if ($dia_fim < 1 || $dia_fim > 31) {
        retornar_json(false, "Dia final deve estar entre 1 e 31");
    }
    
    if ($dia_inicio > $dia_fim) {
        retornar_json(false, "Dia inicial não pode ser maior que dia final");
    }
    
    // Verificar se já existe configuração
    $sql_check = "SELECT id FROM config_periodo_leitura WHERE ativo = 1 LIMIT 1";
    $resultado_check = $conexao->query($sql_check);
    
    if ($resultado_check && $resultado_check->num_rows > 0) {
        // Atualizar configuração existente
        $config = $resultado_check->fetch_assoc();
        $config_id = $config['id'];
        
        $stmt = $conexao->prepare("UPDATE config_periodo_leitura SET dia_inicio = ?, dia_fim = ?, morador_pode_lancar = ? WHERE id = ?");
        $stmt->bind_param("iiii", $dia_inicio, $dia_fim, $morador_pode_lancar, $config_id);
        
        if ($stmt->execute()) {
            registrar_log($conexao, 'INFO', "Configuração de período atualizada: Dia $dia_inicio a $dia_fim");
            retornar_json(true, "Configuração atualizada com sucesso", array(
                'dia_inicio' => $dia_inicio,
                'dia_fim' => $dia_fim,
                'morador_pode_lancar' => $morador_pode_lancar
            ));
        } else {
            retornar_json(false, "Erro ao atualizar configuração: " . $stmt->error);
        }
        
        $stmt->close();
    } else {
        // Inserir nova configuração
        $stmt = $conexao->prepare("INSERT INTO config_periodo_leitura (dia_inicio, dia_fim, morador_pode_lancar, ativo) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("iii", $dia_inicio, $dia_fim, $morador_pode_lancar);
        
        if ($stmt->execute()) {
            registrar_log($conexao, 'INFO', "Configuração de período criada: Dia $dia_inicio a $dia_fim");
            retornar_json(true, "Configuração criada com sucesso", array(
                'dia_inicio' => $dia_inicio,
                'dia_fim' => $dia_fim,
                'morador_pode_lancar' => $morador_pode_lancar
            ));
        } else {
            retornar_json(false, "Erro ao criar configuração: " . $stmt->error);
        }
        
        $stmt->close();
    }
}

fechar_conexao($conexao);
