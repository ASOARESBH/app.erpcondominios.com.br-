<?php
// =====================================================
// API - CONTAS A RECEBER
// =====================================================

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

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

// Para operações de escrita, verificar permissão
if ($metodo !== 'GET') {
    verificarPermissao('gerente');
}

$conexao = conectar_banco();

// ========== LISTAR CONTAS A RECEBER ==========
if ($acao === 'listar' && $metodo === 'GET') {
    // Autenticação já verificada acima
    $status = $_GET['status'] ?? '';
    $limite = intval($_GET['limite'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    $sql = "SELECT * FROM contas_receber WHERE ativo = 1";
    $params = [];
    $types = "";
    
    if (!empty($status)) {
        $sql .= " AND status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    $sql .= " ORDER BY data_vencimento ASC LIMIT ? OFFSET ?";
    $params[] = $limite;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conexao->prepare($sql);
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $contas = [];
    while ($row = $result->fetch_assoc()) {
        $row['valor_original'] = (float)$row['valor_original'];
        $row['valor_recebido'] = (float)$row['valor_recebido'];
        $row['saldo_devedor'] = (float)$row['saldo_devedor'];
        $contas[] = $row;
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    retornar_json(true, 'Contas a receber carregadas', $contas);
}

// ========== BUSCAR CONTA A RECEBER ==========
if ($acao === 'buscar' && $metodo === 'GET') {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    $stmt = $conexao->prepare("SELECT * FROM contas_receber WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conta = $result->fetch_assoc();
        $conta['valor_original'] = (float)$conta['valor_original'];
        $conta['valor_recebido'] = (float)$conta['valor_recebido'];
        $conta['saldo_devedor'] = (float)$conta['saldo_devedor'];
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Conta encontrada', $conta);
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Conta não encontrada');
    }
}

// ========== CADASTRAR CONTA A RECEBER ==========
if ($acao === 'cadastrar' && $metodo === 'POST') {
    $numero_documento = trim($_POST['numero_documento'] ?? '');
    $morador_nome = trim($_POST['morador_nome'] ?? '');
    $unidade_numero = trim($_POST['unidade_numero'] ?? '');
    $plano_conta_id = intval($_POST['plano_conta_id'] ?? 0);
    $descricao = trim($_POST['descricao'] ?? '');
    $valor_original = floatval($_POST['valor_original'] ?? 0);
    $data_emissao = trim($_POST['data_emissao'] ?? '');
    $data_vencimento = trim($_POST['data_vencimento'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    // Validações
    if (empty($numero_documento)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Número do documento é obrigatório');
    }
    
    if (empty($morador_nome)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Nome do morador é obrigatório');
    }
    
    if ($plano_conta_id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'Plano de contas é obrigatório');
    }
    
    if ($valor_original <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'Valor deve ser maior que zero');
    }
    
    if (empty($data_vencimento)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Data de vencimento é obrigatória');
    }
    
    // Verificar se documento já existe
    $stmt_check = $conexao->prepare("SELECT id FROM contas_receber WHERE numero_documento = ?");
    $stmt_check->bind_param("s", $numero_documento);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $stmt_check->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Documento já existe no sistema');
    }
    
    $stmt_check->close();
    
    // Calcular saldo devedor
    $saldo_devedor = $valor_original;
    $status = 'PENDENTE';
    
    // Inserir conta
    $sql_insert = "INSERT INTO contas_receber 
                   (numero_documento, morador_nome, unidade_numero, plano_conta_id, descricao, valor_original, valor_recebido, saldo_devedor, 
                    data_emissao, data_vencimento, status, observacoes, ativo, data_criacao) 
                   VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 1, NOW())";
    
    $stmt_insert = $conexao->prepare($sql_insert);
    $stmt_insert->bind_param(
        "ssissddsss",
        $numero_documento,
        $morador_nome,
        $unidade_numero,
        $plano_conta_id,
        $descricao,
        $valor_original,
        $saldo_devedor,
        $data_emissao,
        $data_vencimento,
        $status,
        $observacoes
    );
    
    if ($stmt_insert->execute()) {
        $novo_id = $stmt_insert->insert_id;
        $stmt_insert->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Conta a receber cadastrada com sucesso', ['id' => $novo_id]);
    } else {
        $erro = $stmt_insert->error;
        $stmt_insert->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao cadastrar: ' . $erro);
    }
}

// ========== REGISTRAR RECEBIMENTO ==========
if ($acao === 'receber' && $metodo === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $valor_recebido = floatval($_POST['valor_recebido'] ?? 0);
    $data_recebimento = trim($_POST['data_recebimento'] ?? date('Y-m-d'));
    $forma_pagamento = trim($_POST['forma_pagamento'] ?? '');
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    if ($valor_recebido <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'Valor deve ser maior que zero');
    }
    
    // Buscar conta
    $stmt_busca = $conexao->prepare("SELECT * FROM contas_receber WHERE id = ?");
    $stmt_busca->bind_param("i", $id);
    $stmt_busca->execute();
    $result_busca = $stmt_busca->get_result();
    
    if ($result_busca->num_rows === 0) {
        $stmt_busca->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Conta não encontrada');
    }
    
    $conta = $result_busca->fetch_assoc();
    $stmt_busca->close();
    
    // Calcular novo saldo
    $novo_valor_recebido = (float)$conta['valor_recebido'] + $valor_recebido;
    $novo_saldo = (float)$conta['valor_original'] - $novo_valor_recebido;
    
    if ($novo_valor_recebido > (float)$conta['valor_original']) {
        fechar_conexao($conexao);
        retornar_json(false, 'Valor de recebimento não pode ser maior que o saldo devedor');
    }
    
    // Determinar novo status
    $novo_status = 'PENDENTE';
    if ($novo_saldo <= 0) {
        $novo_status = 'RECEBIDO';
        $data_recebimento_final = $data_recebimento;
    } else {
        $novo_status = 'PARCIAL';
        $data_recebimento_final = NULL;
    }
    
    // Atualizar conta
    $sql_update = "UPDATE contas_receber SET valor_recebido = ?, saldo_devedor = ?, status = ?, data_recebimento = ?, forma_pagamento = ?, data_atualizacao = NOW() WHERE id = ?";
    
    $stmt_update = $conexao->prepare($sql_update);
    $stmt_update->bind_param("ddsssi", $novo_valor_recebido, $novo_saldo, $novo_status, $data_recebimento_final, $forma_pagamento, $id);
    
    if ($stmt_update->execute()) {
        $stmt_update->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Recebimento registrado com sucesso');
    } else {
        $erro = $stmt_update->error;
        $stmt_update->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao registrar recebimento: ' . $erro);
    }
}

// ========== ATUALIZAR CONTA A RECEBER ==========
if ($acao === 'atualizar' && $metodo === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $numero_documento = trim($_POST['numero_documento'] ?? '');
    $morador_nome     = trim($_POST['morador_nome'] ?? '');
    $unidade_numero   = trim($_POST['unidade_numero'] ?? '');
    $plano_conta_id   = intval($_POST['plano_conta_id'] ?? 0);
    $descricao        = trim($_POST['descricao'] ?? '');
    $valor_original   = floatval($_POST['valor_original'] ?? 0);
    $data_emissao     = trim($_POST['data_emissao'] ?? '');
    $data_vencimento  = trim($_POST['data_vencimento'] ?? '');
    $observacoes      = trim($_POST['observacoes'] ?? '');

    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    if (empty($numero_documento) || empty($morador_nome) || $plano_conta_id <= 0 || $valor_original <= 0 || empty($data_vencimento)) {
        fechar_conexao($conexao);
        retornar_json(false, 'Preencha todos os campos obrigatórios');
    }

    $sql_upd = "UPDATE contas_receber SET numero_documento=?, morador_nome=?, unidade_numero=?, plano_conta_id=?, descricao=?, valor_original=?, data_emissao=?, data_vencimento=?, observacoes=?, data_atualizacao=NOW() WHERE id=?";
    $stmt_upd = $conexao->prepare($sql_upd);
    $stmt_upd->bind_param('sssisdsssi',
        $numero_documento, $morador_nome, $unidade_numero, $plano_conta_id,
        $descricao, $valor_original, $data_emissao,
        $data_vencimento, $observacoes, $id
    );

    if ($stmt_upd->execute()) {
        $stmt_upd->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Conta atualizada com sucesso');
    } else {
        $erro = $stmt_upd->error;
        $stmt_upd->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao atualizar: ' . $erro);
    }
}

// ========== DELETAR CONTA A RECEBER ==========
if ($acao === 'deletar' && $metodo === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        fechar_conexao($conexao);
        retornar_json(false, 'ID inválido');
    }
    
    $sql_delete = "UPDATE contas_receber SET ativo = 0, data_atualizacao = NOW() WHERE id = ?";
    
    $stmt_delete = $conexao->prepare($sql_delete);
    $stmt_delete->bind_param("i", $id);
    
    if ($stmt_delete->execute()) {
        $stmt_delete->close();
        fechar_conexao($conexao);
        retornar_json(true, 'Conta deletada com sucesso');
    } else {
        $erro = $stmt_delete->error;
        $stmt_delete->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao deletar: ' . $erro);
    }
}

// ========== CONCILIAR MOVIMENTAÇÃO BANCÁRIA ==========
if ($acao === 'conciliar_movimentacao' && $metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $titulo_id = intval($body['titulo_id'] ?? 0);
    $mov_id    = intval($body['mov_id']    ?? 0);
    if (!$titulo_id || !$mov_id) { retornar_json(false, 'titulo_id e mov_id obrigatórios'); }

    $conexao->begin_transaction();
    try {
        $stmt = $conexao->prepare("UPDATE contas_receber SET status='RECEBIDO', data_recebimento=CURDATE() WHERE id=? AND ativo=1");
        $stmt->bind_param('i', $titulo_id);
        $stmt->execute();

        $stmt2 = $conexao->prepare("UPDATE movimentacoes_bancarias SET status='conciliado' WHERE id=?");
        $stmt2->bind_param('i', $mov_id);
        $stmt2->execute();

        $conexao->commit();
        fechar_conexao($conexao);
        retornar_json(true, 'Conciliação registrada');
    } catch (Exception $e) {
        $conexao->rollback();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao conciliar: ' . $e->getMessage());
    }
}

// ========== DESFAZER CONCILIAÇÃO ==========
if ($acao === 'desfazer_conciliacao' && $metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $titulo_id = intval($body['titulo_id'] ?? 0);
    $mov_id    = intval($body['mov_id']    ?? 0);
    if (!$titulo_id || !$mov_id) { retornar_json(false, 'titulo_id e mov_id obrigatórios'); }

    $conexao->begin_transaction();
    try {
        $stmt = $conexao->prepare("UPDATE contas_receber SET status='PENDENTE', data_recebimento=NULL WHERE id=? AND ativo=1");
        $stmt->bind_param('i', $titulo_id);
        $stmt->execute();

        $stmt2 = $conexao->prepare("UPDATE movimentacoes_bancarias SET status='pendente', conciliacao_id=NULL WHERE id=?");
        $stmt2->bind_param('i', $mov_id);
        $stmt2->execute();

        $conexao->commit();
        fechar_conexao($conexao);
        retornar_json(true, 'Conciliação desfeita');
    } catch (Exception $e) {
        $conexao->rollback();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao desfazer: ' . $e->getMessage());
    }
}

fechar_conexao($conexao);
retornar_json(false, 'Ação inválida');
?>
