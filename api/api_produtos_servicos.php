<?php
// =====================================================
// API DE PRODUTOS E SERVIÇOS
// =====================================================
// Gerencia cadastro, edição e listagem de produtos/serviços

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'session_helper.php';

// Iniciar sessão com cookie path=/ e CORS correto
iniciar_sessao_fornecedor();
configurar_cors_fornecedor();

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

// Verificar autenticação do fornecedor
$fornecedor_id = verificar_sessao_fornecedor();
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $conexao = conectar_banco();
    
    // ========== LISTAR PRODUTOS/SERVIÇOS DO FORNECEDOR ==========
    if ($acao === 'listar' && $metodo === 'GET') {
        $tipo = $_GET['tipo'] ?? '';  // 'produto', 'servico' ou vazio para todos
        
        $sql = "SELECT * FROM produtos_servicos WHERE fornecedor_id = ?";
        $params = [$fornecedor_id];
        $types = "i";
        
        if (!empty($tipo)) {
            $sql .= " AND tipo = ?";
            $params[] = $tipo;
            $types .= "s";
        }
        
        $sql .= " ORDER BY data_criacao DESC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Produtos/serviços listados com sucesso',
            'dados' => $produtos,
            'total' => count($produtos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== CADASTRAR NOVO PRODUTO/SERVIÇO ==========
    if ($acao === 'criar' && $metodo === 'POST') {
        $tipo = trim($_POST['tipo'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $preco_venda = floatval($_POST['preco_venda'] ?? 0);
        $quantidade_disponivel = intval($_POST['quantidade_disponivel'] ?? 0);
        $observacao = trim($_POST['observacao'] ?? '');
        
        // Validações
        if (empty($tipo) || !in_array($tipo, ['produto', 'servico'])) {
            throw new Exception('Tipo inválido. Deve ser "produto" ou "serviço"');
        }
        if (empty($nome)) {
            throw new Exception('Nome do produto/serviço é obrigatório');
        }
        if ($preco_venda <= 0) {
            throw new Exception('Preço deve ser maior que zero');
        }
        if ($quantidade_disponivel < 0) {
            throw new Exception('Quantidade não pode ser negativa');
        }
        
        // Gerar código sequencial
        $ano = date('Y');
        $count_stmt = $conexao->prepare(
            "SELECT COUNT(*) as total FROM produtos_servicos 
             WHERE fornecedor_id = ? AND YEAR(data_criacao) = ?"
        );
        $count_stmt->bind_param("ii", $fornecedor_id, $ano);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $count_stmt->close();
        
        $numero_sequencial = $count_row['total'] + 1;
        $tipo_inicial = strtoupper(substr($tipo, 0, 1));
        $codigo_sequencial = "{$tipo_inicial}-{$fornecedor_id}-{$ano}-" . str_pad($numero_sequencial, 5, '0', STR_PAD_LEFT);
        
        // Inserir produto/serviço
        $sql = "INSERT INTO produtos_servicos 
                (fornecedor_id, tipo, codigo_sequencial, nome, descricao, preco_venda, quantidade_disponivel, observacao, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
        
        $stmt = $conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception('Erro ao preparar inserção: ' . $conexao->error);
        }
        
        $stmt->bind_param(
            "issssdis",
            $fornecedor_id,
            $tipo,
            $codigo_sequencial,
            $nome,
            $descricao,
            $preco_venda,
            $quantidade_disponivel,
            $observacao
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao inserir produto/serviço: ' . $stmt->error);
        }
        
        $produto_id = $stmt->insert_id;
        $stmt->close();
        
        // Gerar link de compartilhamento
        $hash = md5($produto_id . time() . rand());
        $link_compartilhamento = "marketplace.php?produto={$produto_id}&token={$hash}";
        
        $update_stmt = $conexao->prepare(
            "UPDATE produtos_servicos SET link_compartilhamento = ? WHERE id = ?"
        );
        $update_stmt->bind_param("si", $link_compartilhamento, $produto_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        http_response_code(201);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Produto/serviço cadastrado com sucesso',
            'dados' => [
                'id' => $produto_id,
                'codigo_sequencial' => $codigo_sequencial,
                'link_compartilhamento' => $link_compartilhamento
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== ATUALIZAR PRODUTO/SERVIÇO ==========
    if ($acao === 'atualizar' && $metodo === 'PUT') {
        parse_str(file_get_contents("php://input"), $_PUT);
        
        $produto_id = intval($_PUT['id'] ?? 0);
        $nome = trim($_PUT['nome'] ?? '');
        $descricao = trim($_PUT['descricao'] ?? '');
        $preco_venda = floatval($_PUT['preco_venda'] ?? 0);
        $quantidade_disponivel = intval($_PUT['quantidade_disponivel'] ?? 0);
        $observacao = trim($_PUT['observacao'] ?? '');
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        // Verificar se produto pertence ao fornecedor
        $check_stmt = $conexao->prepare(
            "SELECT id FROM produtos_servicos WHERE id = ? AND fornecedor_id = ?"
        );
        $check_stmt->bind_param("ii", $produto_id, $fornecedor_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            throw new Exception('Produto não encontrado ou não pertence a este fornecedor');
        }
        $check_stmt->close();
        
        // Atualizar
        $sql = "UPDATE produtos_servicos 
                SET nome = ?, descricao = ?, preco_venda = ?, quantidade_disponivel = ?, observacao = ?
                WHERE id = ? AND fornecedor_id = ?";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param(
            "ssdiisi",
            $nome,
            $descricao,
            $preco_venda,
            $quantidade_disponivel,
            $observacao,
            $produto_id,
            $fornecedor_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao atualizar produto: ' . $stmt->error);
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Produto/serviço atualizado com sucesso'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== ATIVAR/INATIVAR PRODUTO ==========
    if ($acao === 'alternar_status' && $metodo === 'POST') {
        $produto_id = intval($_POST['id'] ?? 0);
        $ativo = intval($_POST['ativo'] ?? 0);
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        $sql = "UPDATE produtos_servicos SET ativo = ? WHERE id = ? AND fornecedor_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("iii", $ativo, $produto_id, $fornecedor_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao alterar status: ' . $stmt->error);
        }
        $stmt->close();
        
        $status_texto = $ativo ? 'ativado' : 'inativado';
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => "Produto/serviço {$status_texto} com sucesso"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER DETALHES DE UM PRODUTO ==========
    if ($acao === 'obter' && $metodo === 'GET') {
        $produto_id = intval($_GET['id'] ?? 0);
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        $sql = "SELECT * FROM produtos_servicos WHERE id = ? AND fornecedor_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("ii", $produto_id, $fornecedor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Produto não encontrado');
        }
        
        $produto = $result->fetch_assoc();
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Produto obtido com sucesso',
            'dados' => $produto
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== GERAR LINK DE COMPARTILHAMENTO ==========
    if ($acao === 'gerar_link' && $metodo === 'POST') {
        $produto_id = intval($_POST['id'] ?? 0);
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        // Verificar se produto pertence ao fornecedor
        $check_stmt = $conexao->prepare(
            "SELECT id FROM produtos_servicos WHERE id = ? AND fornecedor_id = ?"
        );
        $check_stmt->bind_param("ii", $produto_id, $fornecedor_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            throw new Exception('Produto não encontrado');
        }
        $check_stmt->close();
        
        // Gerar novo link
        $hash = md5($produto_id . time() . rand());
        $link_compartilhamento = "marketplace.php?produto={$produto_id}&token={$hash}";
        
        $update_stmt = $conexao->prepare(
            "UPDATE produtos_servicos SET link_compartilhamento = ? WHERE id = ?"
        );
        $update_stmt->bind_param("si", $link_compartilhamento, $produto_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Link gerado com sucesso',
            'dados' => [
                'link' => $link_compartilhamento,
                'url_completa' => $_SERVER['HTTP_HOST'] . '/new/frontend/' . $link_compartilhamento
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Ação não encontrada
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Ação inválida'
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Exception $e) {
    fechar_conexao($conexao ?? null);
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

fechar_conexao($conexao);
?>
