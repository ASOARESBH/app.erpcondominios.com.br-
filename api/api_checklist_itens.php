<?php
// =====================================================
// API DE ITENS DO CHECKLIST VEICULAR
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
    case 'listar':
        listar_itens($conexao);
        break;
    
    case 'salvar_abertura':
        salvar_itens_abertura($conexao);
        break;
    
    case 'salvar_fechamento':
        salvar_itens_fechamento($conexao);
        break;
    
    case 'buscar_por_checklist':
        buscar_itens_por_checklist($conexao);
        break;
    
    default:
        retornar_json(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES
// =====================================================

function listar_itens($conexao) {
    $checklist_id = intval($_GET['checklist_id'] ?? 0);
    
    if ($checklist_id <= 0) {
        retornar_json(false, 'ID do checklist inválido');
    }
    
    $sql = "SELECT * FROM checklist_itens 
            WHERE checklist_id = $checklist_id 
            ORDER BY tipo_item, categoria";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $itens = array();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        retornar_json(true, 'Itens listados com sucesso', $itens);
    } else {
        retornar_json(false, 'Erro ao listar itens: ' . $conexao->error);
    }
}

function salvar_itens_abertura($conexao) {
    $checklist_id = intval($_POST['checklist_id'] ?? 0);
    $itens = $_POST['itens'] ?? '';
    
    if ($checklist_id <= 0) {
        retornar_json(false, 'ID do checklist inválido');
    }
    
    if (empty($itens)) {
        retornar_json(false, 'Nenhum item informado');
    }
    
    // Decodificar JSON dos itens
    $itens_array = json_decode($itens, true);
    
    if (!is_array($itens_array)) {
        retornar_json(false, 'Formato de itens inválido');
    }
    
    // Verificar se o checklist está aberto
    $sql_verifica = "SELECT status FROM checklist_veicular WHERE id = $checklist_id";
    $resultado = $conexao->query($sql_verifica);
    
    if (!$resultado || $resultado->num_rows == 0) {
        retornar_json(false, 'Checklist não encontrado');
    }
    
    $checklist = $resultado->fetch_assoc();
    
    if ($checklist['status'] != 'aberto') {
        retornar_json(false, 'Checklist já foi fechado');
    }
    
    // Deletar itens existentes (caso esteja reeditando)
    $conexao->query("DELETE FROM checklist_itens WHERE checklist_id = $checklist_id");
    
    // Inserir novos itens
    $stmt = $conexao->prepare("INSERT INTO checklist_itens 
                               (checklist_id, tipo_item, nome_item, categoria, valor_abertura) 
                               VALUES (?, ?, ?, ?, ?)");
    
    $sucesso = true;
    
    foreach ($itens_array as $item) {
        $tipo_item = $item['tipo_item'] ?? '';
        $nome_item = $item['nome_item'] ?? '';
        $categoria = $item['categoria'] ?? '';
        $valor_abertura = $item['valor_abertura'] ?? '';
        
        $stmt->bind_param("issss", $checklist_id, $tipo_item, $nome_item, $categoria, $valor_abertura);
        
        if (!$stmt->execute()) {
            $sucesso = false;
            break;
        }
    }
    
    if ($sucesso) {
        retornar_json(true, 'Itens salvos com sucesso');
    } else {
        retornar_json(false, 'Erro ao salvar itens: ' . $stmt->error);
    }
}

function salvar_itens_fechamento($conexao) {
    $checklist_id = intval($_POST['checklist_id'] ?? 0);
    $itens = $_POST['itens'] ?? '';
    
    if ($checklist_id <= 0) {
        retornar_json(false, 'ID do checklist inválido');
    }
    
    if (empty($itens)) {
        retornar_json(false, 'Nenhum item informado');
    }
    
    // Decodificar JSON dos itens
    $itens_array = json_decode($itens, true);
    
    if (!is_array($itens_array)) {
        retornar_json(false, 'Formato de itens inválido');
    }
    
    // Atualizar valores de fechamento
    $stmt = $conexao->prepare("UPDATE checklist_itens 
                               SET valor_fechamento = ? 
                               WHERE checklist_id = ? AND categoria = ?");
    
    $sucesso = true;
    
    foreach ($itens_array as $item) {
        $categoria = $item['categoria'] ?? '';
        $valor_fechamento = $item['valor_fechamento'] ?? '';
        
        $stmt->bind_param("sis", $valor_fechamento, $checklist_id, $categoria);
        
        if (!$stmt->execute()) {
            $sucesso = false;
            break;
        }
    }
    
    if ($sucesso) {
        retornar_json(true, 'Itens de fechamento salvos com sucesso');
    } else {
        retornar_json(false, 'Erro ao salvar itens de fechamento: ' . $stmt->error);
    }
}

function buscar_itens_por_checklist($conexao) {
    $checklist_id = intval($_GET['checklist_id'] ?? 0);
    
    if ($checklist_id <= 0) {
        retornar_json(false, 'ID do checklist inválido');
    }
    
    $sql = "SELECT 
                tipo_item,
                nome_item,
                categoria,
                valor_abertura,
                valor_fechamento
            FROM checklist_itens 
            WHERE checklist_id = $checklist_id 
            ORDER BY 
                CASE tipo_item 
                    WHEN 'nivel' THEN 1 
                    WHEN 'funcional' THEN 2 
                END,
                categoria";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        $itens = array();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        retornar_json(true, 'Itens encontrados', $itens);
    } else {
        retornar_json(false, 'Erro ao buscar itens: ' . $conexao->error);
    }
}

fechar_conexao($conexao);
?>
