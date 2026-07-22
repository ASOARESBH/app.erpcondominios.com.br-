<?php
// =====================================================
// API DE ALERTAS DO CHECKLIST VEICULAR
// =====================================================

session_start();
require_once 'config.php';
require_once 'auth_helper.php';

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    retornar_json(false, 'Usuário não autenticado');
}

$conexao = conectar_banco();
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

switch ($acao) {
    case 'listar_config':
        listar_configuracoes($conexao);
        break;
    
    case 'salvar_config':
        salvar_configuracao($conexao);
        break;
    
    case 'atualizar_config':
        atualizar_configuracao($conexao);
        break;
    
    case 'ativar_desativar':
        ativar_desativar_alerta($conexao);
        break;
    
    case 'listar_alertas':
        listar_alertas_gerados($conexao);
        break;
    
    case 'resolver_alerta':
        resolver_alerta($conexao);
        break;
    
    case 'ignorar_alerta':
        ignorar_alerta($conexao);
        break;
    
    case 'estatisticas':
        obter_estatisticas($conexao);
        break;
    
    default:
        retornar_json(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES - CONFIGURAÇÕES
// =====================================================

function listar_configuracoes($conexao) {
    $sql = "SELECT * FROM checklist_alertas_config ORDER BY categoria";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $configs = array();
        while ($row = $resultado->fetch_assoc()) {
            $configs[] = $row;
        }
        retornar_json(true, 'Configurações listadas com sucesso', $configs);
    } else {
        retornar_json(false, 'Erro ao listar configurações: ' . $conexao->error);
    }
}

function salvar_configuracao($conexao) {
    $categoria = sanitizar($conexao, $_POST['categoria'] ?? '');
    $nome_alerta = sanitizar($conexao, $_POST['nome_alerta'] ?? '');
    $km_alerta = intval($_POST['km_alerta'] ?? 0);
    $descricao = sanitizar($conexao, $_POST['descricao'] ?? '');
    
    if (empty($categoria) || empty($nome_alerta) || $km_alerta <= 0) {
        retornar_json(false, 'Dados incompletos');
    }
    
    $stmt = $conexao->prepare("INSERT INTO checklist_alertas_config 
                               (categoria, nome_alerta, km_alerta, descricao, ativo) 
                               VALUES (?, ?, ?, ?, 1)");
    
    $stmt->bind_param("ssis", $categoria, $nome_alerta, $km_alerta, $descricao);
    
    if ($stmt->execute()) {
        retornar_json(true, 'Configuração salva com sucesso', array('id' => $conexao->insert_id));
    } else {
        retornar_json(false, 'Erro ao salvar configuração: ' . $stmt->error);
    }
}

function atualizar_configuracao($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $nome_alerta = sanitizar($conexao, $_POST['nome_alerta'] ?? '');
    $km_alerta = intval($_POST['km_alerta'] ?? 0);
    $descricao = sanitizar($conexao, $_POST['descricao'] ?? '');
    
    if ($id <= 0 || empty($nome_alerta) || $km_alerta <= 0) {
        retornar_json(false, 'Dados incompletos');
    }
    
    $stmt = $conexao->prepare("UPDATE checklist_alertas_config 
                               SET nome_alerta = ?, km_alerta = ?, descricao = ? 
                               WHERE id = ?");
    
    $stmt->bind_param("sisi", $nome_alerta, $km_alerta, $descricao, $id);
    
    if ($stmt->execute()) {
        retornar_json(true, 'Configuração atualizada com sucesso');
    } else {
        retornar_json(false, 'Erro ao atualizar configuração: ' . $stmt->error);
    }
}

function ativar_desativar_alerta($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $ativo = intval($_POST['ativo'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("UPDATE checklist_alertas_config SET ativo = ? WHERE id = ?");
    $stmt->bind_param("ii", $ativo, $id);
    
    if ($stmt->execute()) {
        $status = $ativo ? 'ativado' : 'desativado';
        retornar_json(true, "Alerta $status com sucesso");
    } else {
        retornar_json(false, 'Erro ao atualizar status: ' . $stmt->error);
    }
}

// =====================================================
// FUNÇÕES - ALERTAS GERADOS
// =====================================================

function listar_alertas_gerados($conexao) {
    $filtro_status = $_GET['status'] ?? 'pendente';
    $filtro_veiculo = $_GET['veiculo_id'] ?? '';
    
    $sql = "SELECT 
                a.id,
                a.checklist_id,
                a.veiculo_id,
                v.placa,
                v.modelo as veiculo_modelo,
                a.categoria,
                ac.nome_alerta,
                a.descricao,
                a.km_atual,
                a.km_limite,
                (a.km_atual - a.km_limite) as km_excedido,
                a.status,
                a.data_geracao,
                a.data_resolucao,
                a.observacao_resolucao,
                u.nome as operador_nome,
                ur.nome as resolvido_por_nome
            FROM checklist_alertas_gerados a
            INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
            INNER JOIN checklist_alertas_config ac ON a.alerta_config_id = ac.id
            INNER JOIN checklist_veicular c ON a.checklist_id = c.id
            INNER JOIN usuarios u ON c.operador_id = u.id
            LEFT JOIN usuarios ur ON a.resolvido_por = ur.id
            WHERE 1=1";
    
    if ($filtro_status) {
        $sql .= " AND a.status = '" . sanitizar($conexao, $filtro_status) . "'";
    }
    
    if ($filtro_veiculo) {
        $sql .= " AND a.veiculo_id = " . intval($filtro_veiculo);
    }
    
    $sql .= " ORDER BY a.data_geracao DESC LIMIT 200";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $alertas = array();
        while ($row = $resultado->fetch_assoc()) {
            $alertas[] = $row;
        }
        retornar_json(true, 'Alertas listados com sucesso', $alertas);
    } else {
        retornar_json(false, 'Erro ao listar alertas: ' . $conexao->error);
    }
}

function resolver_alerta($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $observacao = sanitizar($conexao, $_POST['observacao'] ?? '');
    $usuario_id = $_SESSION['usuario_id'];
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("UPDATE checklist_alertas_gerados 
                               SET status = 'resolvido', 
                                   data_resolucao = NOW(), 
                                   resolvido_por = ?, 
                                   observacao_resolucao = ? 
                               WHERE id = ?");
    
    $stmt->bind_param("isi", $usuario_id, $observacao, $id);
    
    if ($stmt->execute()) {
        retornar_json(true, 'Alerta marcado como resolvido');
    } else {
        retornar_json(false, 'Erro ao resolver alerta: ' . $stmt->error);
    }
}

function ignorar_alerta($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $observacao = sanitizar($conexao, $_POST['observacao'] ?? '');
    $usuario_id = $_SESSION['usuario_id'];
    
    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("UPDATE checklist_alertas_gerados 
                               SET status = 'ignorado', 
                                   data_resolucao = NOW(), 
                                   resolvido_por = ?, 
                                   observacao_resolucao = ? 
                               WHERE id = ?");
    
    $stmt->bind_param("isi", $usuario_id, $observacao, $id);
    
    if ($stmt->execute()) {
        retornar_json(true, 'Alerta marcado como ignorado');
    } else {
        retornar_json(false, 'Erro ao ignorar alerta: ' . $stmt->error);
    }
}

function obter_estatisticas($conexao) {
    // Total de alertas por status
    $sql_status = "SELECT 
                    status, 
                    COUNT(*) as total 
                   FROM checklist_alertas_gerados 
                   GROUP BY status";
    
    $resultado_status = $conexao->query($sql_status);
    $stats_status = array();
    
    while ($row = $resultado_status->fetch_assoc()) {
        $stats_status[$row['status']] = $row['total'];
    }
    
    // Alertas por categoria
    $sql_categoria = "SELECT 
                        categoria, 
                        COUNT(*) as total 
                      FROM checklist_alertas_gerados 
                      WHERE status = 'pendente'
                      GROUP BY categoria 
                      ORDER BY total DESC";
    
    $resultado_categoria = $conexao->query($sql_categoria);
    $stats_categoria = array();
    
    while ($row = $resultado_categoria->fetch_assoc()) {
        $stats_categoria[] = $row;
    }
    
    // Veículos com mais alertas pendentes
    $sql_veiculos = "SELECT 
                        v.placa, 
                        v.modelo, 
                        COUNT(a.id) as total_alertas 
                     FROM checklist_alertas_gerados a
                     INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
                     WHERE a.status = 'pendente'
                     GROUP BY v.id, v.placa, v.modelo 
                     ORDER BY total_alertas DESC 
                     LIMIT 5";
    
    $resultado_veiculos = $conexao->query($sql_veiculos);
    $stats_veiculos = array();
    
    while ($row = $resultado_veiculos->fetch_assoc()) {
        $stats_veiculos[] = $row;
    }
    
    $estatisticas = array(
        'por_status' => $stats_status,
        'por_categoria' => $stats_categoria,
        'veiculos_criticos' => $stats_veiculos
    );
    
    retornar_json(true, 'Estatísticas obtidas com sucesso', $estatisticas);
}

fechar_conexao($conexao);
?>
