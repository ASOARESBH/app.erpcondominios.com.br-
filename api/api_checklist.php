<?php
// =====================================================
// API DE CHECKLIST VEICULAR
// =====================================================

session_start();
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
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação
verificarAutenticacao(true, 'operador');

$conexao = conectar_banco();
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

// Verificar permissão para ações de escrita
$acoes_escrita = ['criar', 'atualizar', 'fechar', 'deletar'];
if (in_array($acao, $acoes_escrita)) {
    verificarPermissao('operador');
}

switch ($acao) {
    case 'listar':
        listar_checklists($conexao);
        break;
    
    case 'listar_abertos':
        listar_checklists_abertos($conexao);
        break;
    
    case 'buscar':
        buscar_checklist($conexao);
        break;
    
    case 'criar':
        criar_checklist($conexao);
        break;
    
    case 'atualizar':
        atualizar_checklist($conexao);
        break;
    
    case 'fechar':
        fechar_checklist($conexao);
        break;
    
    case 'deletar':
        deletar_checklist($conexao);
        break;
    
    case 'listar_veiculos':
        listar_veiculos($conexao);
        break;
    
    case 'listar_operadores':
        listar_operadores($conexao);
        break;
    
    default:
        retornar_json(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES
// =====================================================

function listar_checklists($conexao) {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_veiculo = $_GET['veiculo_id'] ?? '';
    $filtro_data_inicio = $_GET['data_inicio'] ?? '';
    $filtro_data_fim = $_GET['data_fim'] ?? '';
    
    $sql = "SELECT 
                c.id,
                c.veiculo_id,
                v.placa,
                v.modelo as veiculo_modelo,
                v.ano as veiculo_ano,
                c.operador_id,
                u.nome as operador_nome,
                c.km_inicial,
                c.km_final,
                c.data_hora_abertura,
                c.data_hora_fechamento,
                c.status,
                c.observacao_abertura,
                c.observacao_fechamento,
                CASE 
                    WHEN c.km_final IS NOT NULL THEN (c.km_final - c.km_inicial)
                    ELSE NULL
                END as km_percorrido
            FROM checklist_veicular c
            INNER JOIN abastecimento_veiculos v ON c.veiculo_id = v.id
            INNER JOIN usuarios u ON c.operador_id = u.id
            WHERE 1=1";
    
    if ($filtro_status) {
        $sql .= " AND c.status = '" . sanitizar($conexao, $filtro_status) . "'";
    }
    
    if ($filtro_veiculo) {
        $sql .= " AND c.veiculo_id = " . intval($filtro_veiculo);
    }
    
    if ($filtro_data_inicio) {
        $sql .= " AND DATE(c.data_hora_abertura) >= '" . sanitizar($conexao, $filtro_data_inicio) . "'";
    }
    
    if ($filtro_data_fim) {
        $sql .= " AND DATE(c.data_hora_abertura) <= '" . sanitizar($conexao, $filtro_data_fim) . "'";
    }
    
    $sql .= " ORDER BY c.data_hora_abertura DESC LIMIT 100";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $checklists = array();
        while ($row = $resultado->fetch_assoc()) {
            $checklists[] = $row;
        }
        retornar_json(true, 'Checklists listados com sucesso', $checklists);
    } else {
        retornar_json(false, 'Erro ao listar checklists: ' . $conexao->error);
    }
}

function listar_checklists_abertos($conexao) {
    $sql = "SELECT 
                c.id,
                c.veiculo_id,
                v.placa,
                v.modelo as veiculo_modelo,
                c.operador_id,
                u.nome as operador_nome,
                c.km_inicial,
                c.data_hora_abertura,
                c.observacao_abertura
            FROM checklist_veicular c
            INNER JOIN abastecimento_veiculos v ON c.veiculo_id = v.id
            INNER JOIN usuarios u ON c.operador_id = u.id
            WHERE c.status = 'aberto'
            ORDER BY c.data_hora_abertura DESC";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $checklists = array();
        while ($row = $resultado->fetch_assoc()) {
            $checklists[] = $row;
        }
        retornar_json(true, 'Checklists abertos listados com sucesso', $checklists);
    } else {
        retornar_json(false, 'Erro ao listar checklists abertos: ' . $conexao->error);
    }
}

function buscar_checklist($conexao) {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    $sql = "SELECT 
                c.*,
                v.placa,
                v.modelo as veiculo_modelo,
                v.ano as veiculo_ano,
                v.cor as veiculo_cor,
                u.nome as operador_nome,
                u.funcao as operador_funcao
            FROM checklist_veicular c
            INNER JOIN abastecimento_veiculos v ON c.veiculo_id = v.id
            INNER JOIN usuarios u ON c.operador_id = u.id
            WHERE c.id = $id";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado && $resultado->num_rows > 0) {
        $checklist = $resultado->fetch_assoc();
        retornar_json(true, 'Checklist encontrado', $checklist);
    } else {
        retornar_json(false, 'Checklist não encontrado');
    }
}

function criar_checklist($conexao) {
    $veiculo_id = intval($_POST['veiculo_id'] ?? 0);
    $operador_id = intval($_POST['operador_id'] ?? 0);
    $km_inicial = intval($_POST['km_inicial'] ?? 0);
    $data_hora_abertura = $_POST['data_hora_abertura'] ?? date('Y-m-d H:i:s');
    $observacao_abertura = $_POST['observacao_abertura'] ?? '';
    
    // Validações
    if ($veiculo_id <= 0) {
        retornar_json(false, 'Veículo não selecionado');
    }
    
    if ($operador_id <= 0) {
        retornar_json(false, 'Operador não selecionado');
    }
    
    if ($km_inicial <= 0) {
        retornar_json(false, 'KM inicial inválido');
    }
    
    // Verificar se existe checklist aberto para este veículo
    $sql_verifica = "SELECT id FROM checklist_veicular 
                     WHERE veiculo_id = $veiculo_id AND status = 'aberto'";
    $resultado_verifica = $conexao->query($sql_verifica);
    
    if ($resultado_verifica && $resultado_verifica->num_rows > 0) {
        retornar_json(false, 'Já existe um checklist aberto para este veículo. Finalize-o antes de criar um novo.');
    }
    
    // Inserir checklist
    $stmt = $conexao->prepare("INSERT INTO checklist_veicular 
                               (veiculo_id, operador_id, km_inicial, data_hora_abertura, observacao_abertura, status) 
                               VALUES (?, ?, ?, ?, ?, 'aberto')");
    
    $stmt->bind_param("iiiss", $veiculo_id, $operador_id, $km_inicial, $data_hora_abertura, $observacao_abertura);
    
    if ($stmt->execute()) {
        $checklist_id = $conexao->insert_id;
        retornar_json(true, 'Checklist criado com sucesso', array('id' => $checklist_id));
    } else {
        retornar_json(false, 'Erro ao criar checklist: ' . $stmt->error);
    }
}

function atualizar_checklist($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $observacao_abertura = $_POST['observacao_abertura'] ?? '';
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("UPDATE checklist_veicular 
                               SET observacao_abertura = ? 
                               WHERE id = ? AND status = 'aberto'");
    
    $stmt->bind_param("si", $observacao_abertura, $id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            retornar_json(true, 'Checklist atualizado com sucesso');
        } else {
            retornar_json(false, 'Checklist não encontrado ou já foi fechado');
        }
    } else {
        retornar_json(false, 'Erro ao atualizar checklist: ' . $stmt->error);
    }
}

function fechar_checklist($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $km_final = intval($_POST['km_final'] ?? 0);
    $data_hora_fechamento = $_POST['data_hora_fechamento'] ?? date('Y-m-d H:i:s');
    $observacao_fechamento = $_POST['observacao_fechamento'] ?? '';
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    if ($km_final <= 0) {
        retornar_json(false, 'KM final inválido');
    }
    
    // Buscar checklist
    $sql_busca = "SELECT km_inicial, operador_id FROM checklist_veicular WHERE id = $id AND status = 'aberto'";
    $resultado = $conexao->query($sql_busca);
    
    if (!$resultado || $resultado->num_rows == 0) {
        retornar_json(false, 'Checklist não encontrado ou já foi fechado');
    }
    
    $checklist = $resultado->fetch_assoc();
    
    // Validar KM final
    if ($km_final <= $checklist['km_inicial']) {
        retornar_json(false, 'KM final deve ser maior que KM inicial (' . $checklist['km_inicial'] . ' km)');
    }
    
    // Atualizar checklist
    $stmt = $conexao->prepare("UPDATE checklist_veicular 
                               SET km_final = ?, 
                                   data_hora_fechamento = ?, 
                                   observacao_fechamento = ?, 
                                   status = 'fechado' 
                               WHERE id = ?");
    
    $stmt->bind_param("issi", $km_final, $data_hora_fechamento, $observacao_fechamento, $id);
    
    if ($stmt->execute()) {
        // Verificar e gerar alertas
        verificar_alertas($conexao, $id);
        
        retornar_json(true, 'Checklist fechado com sucesso');
    } else {
        retornar_json(false, 'Erro ao fechar checklist: ' . $stmt->error);
    }
}

function verificar_alertas($conexao, $checklist_id) {
    // Buscar dados do checklist
    $sql = "SELECT veiculo_id, km_inicial, km_final FROM checklist_veicular WHERE id = $checklist_id";
    $resultado = $conexao->query($sql);
    
    if (!$resultado || $resultado->num_rows == 0) {
        return;
    }
    
    $checklist = $resultado->fetch_assoc();
    $km_percorrido = $checklist['km_final'] - $checklist['km_inicial'];
    
    // Buscar configurações de alertas ativos
    $sql_alertas = "SELECT * FROM checklist_alertas_config WHERE ativo = 1";
    $resultado_alertas = $conexao->query($sql_alertas);
    
    while ($alerta_config = $resultado_alertas->fetch_assoc()) {
        // Buscar ou criar registro de KM acumulado
        $sql_km = "SELECT km_acumulado FROM checklist_km_acumulado 
                   WHERE veiculo_id = {$checklist['veiculo_id']} 
                   AND categoria = '{$alerta_config['categoria']}'";
        
        $resultado_km = $conexao->query($sql_km);
        
        if ($resultado_km && $resultado_km->num_rows > 0) {
            $km_acum = $resultado_km->fetch_assoc();
            $km_total = $km_acum['km_acumulado'];
        } else {
            $km_total = 0;
        }
        
        // Verificar se atingiu o limite
        if ($km_total >= $alerta_config['km_alerta']) {
            // Gerar alerta
            $stmt = $conexao->prepare("INSERT INTO checklist_alertas_gerados 
                                       (checklist_id, alerta_config_id, veiculo_id, km_atual, km_limite, categoria, descricao, data_geracao) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $stmt->bind_param("iiiisss", 
                $checklist_id, 
                $alerta_config['id'], 
                $checklist['veiculo_id'], 
                $km_total, 
                $alerta_config['km_alerta'], 
                $alerta_config['categoria'], 
                $alerta_config['descricao']
            );
            
            $stmt->execute();
            
            // Resetar contador de KM para esta categoria
            $conexao->query("UPDATE checklist_km_acumulado 
                            SET km_acumulado = 0, ultimo_checklist_id = $checklist_id 
                            WHERE veiculo_id = {$checklist['veiculo_id']} 
                            AND categoria = '{$alerta_config['categoria']}'");
        }
    }
}

function deletar_checklist($conexao) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    // Verificar se o checklist está aberto
    $sql_verifica = "SELECT status FROM checklist_veicular WHERE id = $id";
    $resultado = $conexao->query($sql_verifica);
    
    if ($resultado && $resultado->num_rows > 0) {
        $checklist = $resultado->fetch_assoc();
        
        if ($checklist['status'] == 'fechado') {
            retornar_json(false, 'Não é possível deletar um checklist já fechado');
        }
    }
    
    $sql = "DELETE FROM checklist_veicular WHERE id = $id";
    
    if ($conexao->query($sql)) {
        retornar_json(true, 'Checklist deletado com sucesso');
    } else {
        retornar_json(false, 'Erro ao deletar checklist: ' . $conexao->error);
    }
}

function listar_veiculos($conexao) {
    $sql = "SELECT id, placa, modelo, ano, cor FROM abastecimento_veiculos ORDER BY placa";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $veiculos = array();
        while ($row = $resultado->fetch_assoc()) {
            $veiculos[] = $row;
        }
        retornar_json(true, 'Veículos listados com sucesso', $veiculos);
    } else {
        retornar_json(false, 'Erro ao listar veículos: ' . $conexao->error);
    }
}

function listar_operadores($conexao) {
    $sql = "SELECT id, nome, funcao, departamento FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $operadores = array();
        while ($row = $resultado->fetch_assoc()) {
            $operadores[] = $row;
        }
        retornar_json(true, 'Operadores listados com sucesso', $operadores);
    } else {
        retornar_json(false, 'Erro ao listar operadores: ' . $conexao->error);
    }
}

fechar_conexao($conexao);
?>
