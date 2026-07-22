<?php
/**
 * API para Gerenciamento de Ramos de Atividade
 * 
 * Gerencia categorias de fornecedores do marketplace
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 29/12/2025
 */

// Limpar qualquer output anterior
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_clean();

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
require_once 'config.php';
require_once 'auth_helper.php';

// Função para resposta JSON
function resposta($sucesso, $mensagem, $dados = null) {
    if (ob_get_length()) ob_clean();
    
    $response = [
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Conectar ao banco
$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    resposta(false, 'Erro ao conectar ao banco de dados');
}
mysqli_set_charset($conexao, 'utf8mb4');

// Obter ação
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

switch ($acao) {
    case 'listar':
        listarAtivos($conexao);
        break;
    
    case 'listar_todos':
        listarTodos($conexao);
        break;
    
    case 'cadastrar':
        cadastrar($conexao);
        break;
    
    case 'atualizar':
        atualizar($conexao);
        break;
    
    case 'alternar_status':
        alternarStatus($conexao);
        break;
    
    case 'buscar':
        buscar($conexao);
        break;
    
    default:
        resposta(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES
// =====================================================

/**
 * Listar apenas ramos ativos (para seleção pública)
 */
function listarAtivos($conexao) {
    $sql = "SELECT * FROM ramos_atividade WHERE ativo = 1 ORDER BY nome";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar ramos: ' . mysqli_error($conexao));
    }
    
    $ramos = [];
    while ($row = mysqli_fetch_assoc($resultado)) {
        $ramos[] = $row;
    }
    
    resposta(true, 'Ramos carregados', $ramos);
}

/**
 * Listar todos os ramos (ativos e inativos) para administração
 */
function listarTodos($conexao) {
    $sql = "SELECT 
                r.*,
                COUNT(f.id) as total_fornecedores
            FROM ramos_atividade r
            LEFT JOIN fornecedores f ON r.id = f.ramo_atividade_id
            GROUP BY r.id
            ORDER BY r.nome";
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar ramos: ' . mysqli_error($conexao));
    }
    
    $ramos = [];
    while ($row = mysqli_fetch_assoc($resultado)) {
        $row['data_criacao_formatada'] = date('d/m/Y H:i', strtotime($row['data_criacao']));
        $ramos[] = $row;
    }
    
    resposta(true, 'Ramos carregados', $ramos);
}

/**
 * Cadastrar novo ramo de atividade
 */
function cadastrar($conexao) {
    $nome = mysqli_real_escape_string($conexao, trim($_POST['nome'] ?? ''));
    $descricao = mysqli_real_escape_string($conexao, trim($_POST['descricao'] ?? ''));
    $icone = mysqli_real_escape_string($conexao, trim($_POST['icone'] ?? 'fa-briefcase'));
    
    // Validar
    if (empty($nome)) {
        resposta(false, 'Nome é obrigatório');
    }
    
    // Verificar se já existe
    $sql_check = "SELECT id FROM ramos_atividade WHERE nome = '$nome'";
    $resultado = mysqli_query($conexao, $sql_check);
    
    if (mysqli_num_rows($resultado) > 0) {
        resposta(false, 'Já existe um ramo com este nome');
    }
    
    // Inserir
    $sql = "INSERT INTO ramos_atividade (nome, descricao, icone, ativo) 
            VALUES ('$nome', '$descricao', '$icone', 1)";
    
    if (mysqli_query($conexao, $sql)) {
        $id = mysqli_insert_id($conexao);
        resposta(true, 'Ramo cadastrado com sucesso!', ['id' => $id]);
    } else {
        resposta(false, 'Erro ao cadastrar: ' . mysqli_error($conexao));
    }
}

/**
 * Atualizar ramo de atividade existente
 */
function atualizar($conexao) {
    $id = intval($_POST['id'] ?? 0);
    $nome = mysqli_real_escape_string($conexao, trim($_POST['nome'] ?? ''));
    $descricao = mysqli_real_escape_string($conexao, trim($_POST['descricao'] ?? ''));
    $icone = mysqli_real_escape_string($conexao, trim($_POST['icone'] ?? 'fa-briefcase'));
    
    // Validar
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    if (empty($nome)) {
        resposta(false, 'Nome é obrigatório');
    }
    
    // Verificar se existe outro ramo com o mesmo nome
    $sql_check = "SELECT id FROM ramos_atividade WHERE nome = '$nome' AND id != $id";
    $resultado = mysqli_query($conexao, $sql_check);
    
    if (mysqli_num_rows($resultado) > 0) {
        resposta(false, 'Já existe outro ramo com este nome');
    }
    
    // Atualizar
    $sql = "UPDATE ramos_atividade 
            SET nome = '$nome', descricao = '$descricao', icone = '$icone' 
            WHERE id = $id";
    
    if (mysqli_query($conexao, $sql)) {
        resposta(true, 'Ramo atualizado com sucesso!');
    } else {
        resposta(false, 'Erro ao atualizar: ' . mysqli_error($conexao));
    }
}

/**
 * Alternar status ativo/inativo
 */
function alternarStatus($conexao) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    // Buscar status atual
    $sql = "SELECT ativo, nome FROM ramos_atividade WHERE id = $id";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado || mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Ramo não encontrado');
    }
    
    $ramo = mysqli_fetch_assoc($resultado);
    $novo_status = $ramo['ativo'] == 1 ? 0 : 1;
    
    // Atualizar status
    $sql_update = "UPDATE ramos_atividade SET ativo = $novo_status WHERE id = $id";
    
    if (mysqli_query($conexao, $sql_update)) {
        $mensagem = $novo_status == 1 
            ? "Ramo '{$ramo['nome']}' ativado com sucesso!" 
            : "Ramo '{$ramo['nome']}' inativado com sucesso!";
        
        resposta(true, $mensagem, ['id' => $id, 'novo_status' => $novo_status]);
    } else {
        resposta(false, 'Erro ao alterar status: ' . mysqli_error($conexao));
    }
}

/**
 * Buscar ramo específico
 */
function buscar($conexao) {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    $sql = "SELECT * FROM ramos_atividade WHERE id = $id";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar ramo: ' . mysqli_error($conexao));
    }
    
    if (mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Ramo não encontrado');
    }
    
    $ramo = mysqli_fetch_assoc($resultado);
    resposta(true, 'Ramo encontrado', $ramo);
}

mysqli_close($conexao);
?>
