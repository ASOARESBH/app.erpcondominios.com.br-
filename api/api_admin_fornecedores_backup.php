<?php
/**
 * API Administrativa para Gerenciamento de Fornecedores - VERSÃO FUNCIONAL
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_clean();

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

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
    case 'listar_todos':
        listarTodos($conexao);
        break;
    
    case 'alternar_status':
        alternarStatus($conexao);
        break;
    
    case 'alternar_aprovacao':
        alternarAprovacao($conexao);
        break;
    
    case 'estatisticas':
        obterEstatisticas($conexao);
        break;
    
    default:
        resposta(false, 'Ação inválida');
}

// =====================================================
// FUNÇÕES CORRIGIDAS
// =====================================================

/**
 * Listar todos os fornecedores - VERSÃO SIMPLIFICADA
 */
function listarTodos($conexao) {
    // REMOVIDOS TODOS OS JOINS PROBLEMÁTICOS
    $sql = "SELECT 
                f.id,
                f.cpf_cnpj,
                f.nome_estabelecimento,
                f.nome_responsavel,
                f.ramo_atividade_id,
                r.nome as ramo_atividade,
                r.icone as ramo_icone,
                f.endereco,
                f.telefone,
                f.email,
                f.logo,
                f.descricao_negocio,
                f.horario_funcionamento,
                f.ativo,
                f.aprovado,
                f.data_cadastro,
                f.data_atualizacao,
                f.ultimo_acesso
            FROM fornecedores f
            LEFT JOIN ramos_atividade r ON f.ramo_atividade_id = r.id
            ORDER BY f.data_cadastro DESC";
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar fornecedores: ' . mysqli_error($conexao));
    }
    
    $fornecedores = [];
    while ($row = mysqli_fetch_assoc($resultado)) {
        // Formatar dados
        $row['data_cadastro_formatada'] = date('d/m/Y H:i', strtotime($row['data_cadastro']));
        $row['ultimo_acesso_formatado'] = $row['ultimo_acesso'] ? 
            date('d/m/Y H:i', strtotime($row['ultimo_acesso'])) : 'Nunca';
        
        $fornecedores[] = $row;
    }
    
    resposta(true, 'Fornecedores carregados', $fornecedores);
}

/**
 * Alternar status ativo/inativo de um fornecedor
 */
function alternarStatus($conexao) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    // Buscar status atual
    $sql = "SELECT ativo, nome_estabelecimento FROM fornecedores WHERE id = $id";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado || mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Fornecedor não encontrado');
    }
    
    $fornecedor = mysqli_fetch_assoc($resultado);
    $novo_status = $fornecedor['ativo'] == 1 ? 0 : 1;
    
    // Atualizar status
    $sql_update = "UPDATE fornecedores SET ativo = $novo_status WHERE id = $id";
    
    if (mysqli_query($conexao, $sql_update)) {
        $mensagem = $novo_status == 1 
            ? "Fornecedor '{$fornecedor['nome_estabelecimento']}' ativado com sucesso!" 
            : "Fornecedor '{$fornecedor['nome_estabelecimento']}' inativado com sucesso!";
        
        resposta(true, $mensagem, ['id' => $id, 'novo_status' => $novo_status]);
    } else {
        resposta(false, 'Erro ao alterar status: ' . mysqli_error($conexao));
    }
}

/**
 * Alternar aprovação de um fornecedor
 */
function alternarAprovacao($conexao) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    // Buscar status atual
    $sql = "SELECT aprovado, nome_estabelecimento FROM fornecedores WHERE id = $id";
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado || mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Fornecedor não encontrado');
    }
    
    $fornecedor = mysqli_fetch_assoc($resultado);
    $novo_status = $fornecedor['aprovado'] == 1 ? 0 : 1;
    
    // Atualizar aprovação
    $sql_update = "UPDATE fornecedores SET aprovado = $novo_status WHERE id = $id";
    
    if (mysqli_query($conexao, $sql_update)) {
        $mensagem = $novo_status == 1 
            ? "Fornecedor '{$fornecedor['nome_estabelecimento']}' aprovado com sucesso!" 
            : "Aprovação do fornecedor '{$fornecedor['nome_estabelecimento']}' removida!";
        
        resposta(true, $mensagem, ['id' => $id, 'novo_status' => $novo_status]);
    } else {
        resposta(false, 'Erro ao alterar aprovação: ' . mysqli_error($conexao));
    }
}

/**
 * Obter estatísticas gerais do marketplace
 */
function obterEstatisticas($conexao) {
    $sql = "SELECT 
                COUNT(*) as total_fornecedores,
                SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as fornecedores_ativos,
                SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) as fornecedores_inativos,
                SUM(CASE WHEN aprovado = 1 THEN 1 ELSE 0 END) as fornecedores_aprovados,
                SUM(CASE WHEN aprovado = 0 THEN 1 ELSE 0 END) as fornecedores_pendentes
            FROM fornecedores";
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar estatísticas: ' . mysqli_error($conexao));
    }
    
    $stats = mysqli_fetch_assoc($resultado);
    
    resposta(true, 'Estatísticas carregadas', $stats);
}

mysqli_close($conexao);
?>