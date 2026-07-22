<?php
/**
 * API para Gerenciamento de Templates de E-mail
 * 
 * Ações disponíveis:
 * - listar: Lista todos os templates
 * - buscar: Busca um template específico por ID
 * - salvar: Salva/atualiza um template
 * - ativar: Ativa/desativa um template
 */

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
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar conexão
if (!$conn) {
    resposta(false, 'Erro de conexão com o banco de dados');
}

// Pegar ação
$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_POST['acao']) ? $_POST['acao'] : '');

// Processar ação
switch ($acao) {
    
    case 'listar':
        listarTemplates($conn);
        break;
    
    case 'buscar':
        buscarTemplate($conn);
        break;
    
    case 'salvar':
        salvarTemplate($conn);
        break;
    
    case 'ativar':
        ativarTemplate($conn);
        break;
    
    default:
        resposta(false, 'Ação inválida');
}

/**
 * Listar todos os templates
 */
function listarTemplates($conn) {
    $sql = "SELECT 
                id,
                tipo,
                assunto,
                corpo,
                variaveis,
                ativo,
                data_criacao,
                data_atualizacao
            FROM email_templates
            ORDER BY 
                CASE tipo
                    WHEN 'recuperacao_senha' THEN 1
                    WHEN 'boas_vindas' THEN 2
                    WHEN 'notificacao' THEN 3
                    ELSE 4
                END,
                id";
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        resposta(false, 'Erro ao buscar templates: ' . mysqli_error($conn));
    }
    
    $templates = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $templates[] = $row;
    }
    
    resposta(true, 'Templates carregados com sucesso', $templates);
}

/**
 * Buscar template específico
 */
function buscarTemplate($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    $sql = "SELECT * FROM email_templates WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        resposta(true, 'Template encontrado', $row);
    } else {
        resposta(false, 'Template não encontrado');
    }
}

/**
 * Salvar/atualizar template
 */
function salvarTemplate($conn) {
    // Validar dados
    $id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
    $assunto = isset($_POST['assunto']) ? trim($_POST['assunto']) : '';
    $corpo = isset($_POST['corpo']) ? trim($_POST['corpo']) : '';
    $ativo = isset($_POST['ativo']) ? intval($_POST['ativo']) : 1;
    
    // Validações
    if (empty($assunto)) {
        resposta(false, 'O assunto é obrigatório');
    }
    
    if (empty($corpo)) {
        resposta(false, 'O corpo do e-mail é obrigatório');
    }
    
    if ($id <= 0) {
        resposta(false, 'ID do template inválido');
    }
    
    // Atualizar template
    $sql = "UPDATE email_templates 
            SET assunto = ?,
                corpo = ?,
                ativo = ?,
                data_atualizacao = NOW()
            WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        resposta(false, 'Erro ao preparar consulta: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, 'ssii', $assunto, $corpo, $ativo, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Buscar template atualizado
        $sql_select = "SELECT * FROM email_templates WHERE id = ?";
        $stmt_select = mysqli_prepare($conn, $sql_select);
        mysqli_stmt_bind_param($stmt_select, 'i', $id);
        mysqli_stmt_execute($stmt_select);
        $result = mysqli_stmt_get_result($stmt_select);
        $template = mysqli_fetch_assoc($result);
        
        resposta(true, 'Template salvo com sucesso', $template);
    } else {
        resposta(false, 'Erro ao salvar template: ' . mysqli_error($conn));
    }
}

/**
 * Ativar/desativar template
 */
function ativarTemplate($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $ativo = isset($_POST['ativo']) ? intval($_POST['ativo']) : 0;
    
    if ($id <= 0) {
        resposta(false, 'ID inválido');
    }
    
    $sql = "UPDATE email_templates 
            SET ativo = ?,
                data_atualizacao = NOW()
            WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $ativo, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        $status = $ativo ? 'ativado' : 'desativado';
        resposta(true, "Template {$status} com sucesso");
    } else {
        resposta(false, 'Erro ao atualizar status: ' . mysqli_error($conn));
    }
}

// Fechar conexão
mysqli_close($conn);
?>
