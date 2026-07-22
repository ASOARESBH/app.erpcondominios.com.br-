<?php
// =====================================================
// API PARA INTEGRAÇÃO COM RFID CONTROL ID iDUHF - CORRIGIDA
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

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
$tenant_id = exigirTenantId();

// ========== VERIFICAR TAG RFID ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'verificar_tag') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $tag = sanitizar($conexao, $dados['tag'] ?? '');
    
    if (empty($tag)) {
        retornar_json(false, "TAG RFID não informada");
    }
    
    // Buscar veículo pela TAG com dados de dependente
    $stmt = $conexao->prepare("SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.ativo, v.dependente_id,
                              m.id as morador_id, m.nome as morador_nome, m.unidade as morador_unidade,
                              d.id as dependente_id_check, d.nome_completo as dependente_nome
                              FROM veiculos v
                              INNER JOIN moradores m ON v.morador_id = m.id
                              LEFT JOIN dependentes d ON v.dependente_id = d.id
                              WHERE v.tag = ? AND v.ativo = 1 AND m.ativo = 1");
    
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $veiculo = $resultado->fetch_assoc();
        
        $data_hora = date('Y-m-d H:i:s');
        $placa = $veiculo['placa'];
        $modelo = $veiculo['modelo'];
        $cor = $veiculo['cor'];
        $morador_id = $veiculo['morador_id'];
        $dependente_id = $veiculo['dependente_id'] ?? null;
        
        // Determinar tipo de acesso
        if (!empty($dependente_id) && !empty($veiculo['dependente_nome'])) {
            $tipo = 'Dependente';
            $status = "✅ Acesso liberado - " . $veiculo['dependente_nome'] . " (dependente de " . $veiculo['morador_nome'] . ")";
            $mensagem = "✅ SEJA BEM-VINDO, " . strtoupper($veiculo['dependente_nome']) . ", dependente de " . strtoupper($veiculo['morador_nome']) . "!";
        } else {
            $tipo = 'Morador';
            $status = "✅ Acesso liberado - " . $veiculo['morador_nome'];
            $mensagem = "✅ SEJA BEM-VINDO, " . strtoupper($veiculo['morador_nome']) . "!";
        }
        
        $unidade = $veiculo['morador_unidade'];
        $liberado = 1;
        
        $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso 
                                           (data_hora, placa, modelo, cor, tag, tipo, morador_id, 
                                            unidade_destino, status, liberado) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt_registro->bind_param("ssssssissi", 
            $data_hora, $placa, $modelo, $cor, $tag, $tipo, $morador_id, $unidade, $status, $liberado
        );
        
        $stmt_registro->execute();
        $stmt_registro->close();
        
        $nome_acesso = $veiculo['dependente_nome'] ?? $veiculo['morador_nome'];
        registrar_log('ACESSO_RFID', "Acesso via RFID: $placa (TAG RFID: $tag) - " . $nome_acesso);
        
        retornar_json(true, "Acesso liberado", array(
            'liberado' => true,
            'morador' => $veiculo['morador_nome'],
            'dependente' => $veiculo['dependente_nome'] ?? null,
            'unidade' => $veiculo['morador_unidade'],
            'tag_rfid' => $veiculo['tag'],
            'placa' => $veiculo['placa'],
            'modelo' => $veiculo['modelo'],
            'tipo' => $tipo,
            'mensagem' => $mensagem
        ));
        
    } else {
        registrar_log('ACESSO_NEGADO_RFID', "Acesso negado via RFID: TAG RFID $tag não cadastrada ou inativa");
        
        retornar_json(false, "Acesso negado", array(
            'liberado' => false,
            'mensagem' => "❌ ACESSO NEGADO - TAG RFID NÃO CADASTRADA"
        ));
    }
    
    $stmt->close();
}

// ========== WEBHOOK PARA RECEBER LEITURAS DO RFID ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'webhook') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $tag = sanitizar($conexao, $dados['tag'] ?? $dados['card'] ?? $dados['rfid'] ?? '');
    $timestamp = $dados['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (empty($tag)) {
        registrar_log('WEBHOOK_RFID_ERRO', "Webhook RFID recebido sem TAG RFID válida");
        retornar_json(false, "TAG RFID não identificada no webhook");
    }
    
    $stmt = $conexao->prepare("SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.ativo, v.dependente_id,
                              m.id as morador_id, m.nome as morador_nome, m.unidade as morador_unidade,
                              d.id as dependente_id_check, d.nome_completo as dependente_nome
                              FROM veiculos v
                              INNER JOIN moradores m ON v.morador_id = m.id
                              LEFT JOIN dependentes d ON v.dependente_id = d.id
                              WHERE v.tag = ? AND v.ativo = 1 AND m.ativo = 1");
    
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $veiculo = $resultado->fetch_assoc();
        
        $placa = $veiculo['placa'];
        $modelo = $veiculo['modelo'];
        $cor = $veiculo['cor'];
        $morador_id = $veiculo['morador_id'];
        $dependente_id = $veiculo['dependente_id'] ?? null;
        
        if (!empty($dependente_id) && !empty($veiculo['dependente_nome'])) {
            $tipo = 'Dependente';
            $status = "✅ Acesso liberado via RFID - " . $veiculo['dependente_nome'] . " (dependente de " . $veiculo['morador_nome'] . ")";
        } else {
            $tipo = 'Morador';
            $status = "✅ Acesso liberado via RFID - " . $veiculo['morador_nome'];
        }
        
        $unidade = $veiculo['morador_unidade'];
        $liberado = 1;
        
        $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso 
                                           (data_hora, placa, modelo, cor, tag, tipo, morador_id, 
                                            unidade_destino, status, liberado) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt_registro->bind_param("ssssssissi", 
            $timestamp, $placa, $modelo, $cor, $tag, $tipo, $morador_id, $unidade, $status, $liberado
        );
        
        $stmt_registro->execute();
        $stmt_registro->close();
        
        $nome_acesso = $veiculo['dependente_nome'] ?? $veiculo['morador_nome'];
        registrar_log('WEBHOOK_RFID_SUCESSO', "Webhook RFID: $placa (TAG RFID: $tag) - " . $nome_acesso);
        
        retornar_json(true, "Acesso liberado", array(
            'action' => 'open_gate',
            'duration' => 5,
            'message' => "Bem-vindo, " . ($veiculo['dependente_nome'] ?? $veiculo['morador_nome'])
        ));
        
    } else {
        registrar_log('WEBHOOK_RFID_NEGADO', "Webhook RFID negado: TAG RFID $tag");
        
        retornar_json(false, "Acesso negado", array(
            'action' => 'deny',
            'message' => "Acesso negado"
        ));
    }
    
    $stmt->close();
}

// ========== TESTAR CONEXÃO COM EQUIPAMENTO RFID ==========
if ($metodo === 'GET' && isset($_GET['acao']) && $_GET['acao'] === 'testar_conexao') {
    
    $stmt = $conexao->prepare("SELECT chave, valor FROM configuracoes WHERE tenant_id = $tenant_id AND chave LIKE 'rfid_%'");
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $config = array();
    while ($row = $resultado->fetch_assoc()) {
        $config[$row['chave']] = $row['valor'];
    }
    $stmt->close();
    
    $ip = $config['rfid_ip'] ?? '';
    $porta = $config['rfid_porta'] ?? '3000';
    
    if (empty($ip)) {
        retornar_json(false, "IP do equipamento RFID não configurado");
    }
    
    $url = "http://$ip:$porta/api/status";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    
    if ($http_code === 200 || $http_code === 401) {
        retornar_json(true, "Conexão com equipamento RFID estabelecida", array(
            'ip' => $ip,
            'porta' => $porta,
            'status' => 'online'
        ));
    } else {
        retornar_json(false, "Não foi possível conectar ao equipamento RFID", array(
            'ip' => $ip,
            'porta' => $porta,
            'erro' => $erro,
            'status' => 'offline'
        ));
    }
}

// ========== LISTAR ÚLTIMOS ACESSOS ==========
if ($metodo === 'GET' && isset($_GET['acao']) && $_GET['acao'] === 'ultimos_acessos') {
    $limite = intval($_GET['limite'] ?? 10);
    if ($limite <= 0) $limite = 10;
    if ($limite > 100) $limite = 100;

    $tem_origem = false;
    $res_coluna_origem = $conexao->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_acesso' AND COLUMN_NAME = 'origem'");
    if ($res_coluna_origem) {
        $tem_origem = intval($res_coluna_origem->fetch_assoc()['c'] ?? 0) > 0;
    }

    $origem_expr = $tem_origem ? "r.origem" : "NULL";
    
    $sql = "SELECT 
            r.id,
            r.data_hora,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y') as data,
            DATE_FORMAT(r.data_hora, '%H:%i:%s') as hora,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') as data_hora_formatada,
            COALESCE(v_tag.tag, v_placa.tag, r.tag) as tag_rfid,
            COALESCE(v_tag.tag, v_placa.tag, r.tag) as tag,
            COALESCE(v_tag.placa, v_placa.placa, r.placa) as placa,
            COALESCE(v_tag.modelo, v_placa.modelo, r.modelo) as modelo,
            COALESCE(v_tag.cor, v_placa.cor, r.cor) as cor,
            r.tipo,
            r.unidade_destino as unidade,
            COALESCE(m.nome, r.nome_visitante, r.tipo) as morador,
            d.nome_completo as dependente,
            $origem_expr as origem,
            CASE
                WHEN LOWER(COALESCE($origem_expr, '')) = 'controlid' THEN 'ControleID'
                WHEN LOWER(COALESCE($origem_expr, r.status, r.observacao, '')) LIKE '%manual%' THEN 'Registro Manual'
                WHEN LOWER(COALESCE($origem_expr, '')) = 'portal' THEN 'Visitante'
                WHEN LOWER(COALESCE(r.tipo, '')) LIKE '%visitante%' THEN 'Visitante'
                WHEN LOWER(COALESCE(r.status, r.observacao, r.tipo, '')) LIKE '%rfid%' THEN 'TAG RFID'
                ELSE NULL
            END as origem_liberacao,
            NULL as usuario_liberou,
            r.liberado, r.status
            FROM registros_acesso r
            LEFT JOIN moradores m ON r.morador_id = m.id
            LEFT JOIN veiculos v_tag ON r.tag IS NOT NULL AND r.tag <> '' AND v_tag.tag = r.tag
            LEFT JOIN veiculos v_placa ON (r.tag IS NULL OR r.tag = '') AND r.placa IS NOT NULL AND r.placa <> '' AND v_placa.placa = r.placa
            LEFT JOIN dependentes d ON d.id = COALESCE(v_tag.dependente_id, v_placa.dependente_id)
            WHERE r.liberado = 1
            ORDER BY r.data_hora DESC
            LIMIT ?";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $limite);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $acessos = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $acessos[] = $row;
        }
    }
    
    $stmt->close();
    retornar_json(true, "Últimos acessos listados", $acessos);
}

fechar_conexao($conexao);
