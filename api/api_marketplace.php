<?php
// =====================================================
// API DE MARKETPLACE
// =====================================================
// Gerencia listagem de produtos/serviços para o marketplace

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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $conexao = conectar_banco();
    
    // ========== LISTAR PRODUTOS/SERVIÇOS ATIVOS ==========
    if ($acao === 'listar' && $metodo === 'GET') {
        $nome = trim($_GET['nome'] ?? '');
        $tipo = trim($_GET['tipo'] ?? '');
        $fornecedor_id = intval($_GET['fornecedor_id'] ?? 0);
        
        $sql = "SELECT 
                    ps.id,
                    ps.fornecedor_id,
                    ps.tipo,
                    ps.codigo_sequencial,
                    ps.nome,
                    ps.descricao,
                    ps.preco_venda,
                    ps.quantidade_disponivel,
                    ps.observacao,
                    ps.link_compartilhamento,
                    f.nome_estabelecimento,
                    f.email as fornecedor_email,
                    ROUND(AVG(a.nota_morador), 2) as media_nota,
                    COUNT(DISTINCT a.id) as total_avaliacoes
                FROM produtos_servicos ps
                JOIN fornecedores f ON ps.fornecedor_id = f.id
                LEFT JOIN avaliacoes a ON ps.id = a.produto_servico_id
                WHERE ps.ativo = 1";
        
        $params = [];
        $types = "";
        
        if (!empty($nome)) {
            $sql .= " AND ps.nome LIKE ?";
            $params[] = "%{$nome}%";
            $types .= "s";
        }
        
        if (!empty($tipo)) {
            $sql .= " AND ps.tipo = ?";
            $params[] = $tipo;
            $types .= "s";
        }
        
        if ($fornecedor_id > 0) {
            $sql .= " AND ps.fornecedor_id = ?";
            $params[] = $fornecedor_id;
            $types .= "i";
        }
        
        $sql .= " GROUP BY ps.id, ps.fornecedor_id, ps.tipo, ps.codigo_sequencial, ps.nome, ps.descricao, ps.preco_venda, ps.quantidade_disponivel, ps.observacao, ps.link_compartilhamento, f.nome_estabelecimento, f.email";
        $sql .= " ORDER BY ps.data_criacao DESC";
        
        $stmt = $conexao->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
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
            'mensagem' => 'Produtos listados com sucesso',
            'dados' => $produtos,
            'total' => count($produtos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== LISTAR FORNECEDORES COM PRODUTOS ATIVOS ==========
    if ($acao === 'listar_fornecedores' && $metodo === 'GET') {
        $sql = "SELECT DISTINCT 
                    f.id,
                    f.nome_estabelecimento,
                    f.email,
                    COUNT(DISTINCT ps.id) as total_produtos,
                    ROUND(AVG(a.nota_morador), 2) as media_nota
                FROM fornecedores f
                LEFT JOIN produtos_servicos ps ON f.id = ps.fornecedor_id AND ps.ativo = 1
                LEFT JOIN avaliacoes a ON f.id = a.fornecedor_id
                WHERE f.ativo = 1 AND f.aprovado = 1
                GROUP BY f.id, f.nome_estabelecimento, f.email
                HAVING total_produtos > 0
                ORDER BY f.nome_estabelecimento ASC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $fornecedores = [];
        while ($row = $result->fetch_assoc()) {
            $fornecedores[] = $row;
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Fornecedores listados com sucesso',
            'dados' => $fornecedores,
            'total' => count($fornecedores)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER DETALHES DE UM PRODUTO ==========
    if ($acao === 'obter' && $metodo === 'GET') {
        $produto_id = intval($_GET['id'] ?? 0);
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        $sql = "SELECT 
                    ps.id,
                    ps.fornecedor_id,
                    ps.tipo,
                    ps.codigo_sequencial,
                    ps.nome,
                    ps.descricao,
                    ps.preco_venda,
                    ps.quantidade_disponivel,
                    ps.observacao,
                    ps.link_compartilhamento,
                    ps.data_criacao,
                    f.nome_estabelecimento,
                    f.email as fornecedor_email,
                    f.telefone as fornecedor_telefone,
                    f.endereco as fornecedor_endereco,
                    ROUND(AVG(a.nota_morador), 2) as media_nota,
                    COUNT(DISTINCT a.id) as total_avaliacoes
                FROM produtos_servicos ps
                JOIN fornecedores f ON ps.fornecedor_id = f.id
                LEFT JOIN avaliacoes a ON ps.id = a.produto_servico_id
                WHERE ps.id = ? AND ps.ativo = 1
                GROUP BY ps.id";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Produto não encontrado ou inativo');
        }
        
        $produto = $result->fetch_assoc();
        $stmt->close();
        
        // Obter avaliações recentes
        $avaliacoes_sql = "SELECT 
                            a.id,
                            a.nota_morador,
                            a.comentario_morador,
                            a.data_avaliacao_morador,
                            m.nome as morador_nome
                        FROM avaliacoes a
                        JOIN moradores m ON a.morador_id = m.id
                        WHERE a.produto_servico_id = ? AND a.nota_morador IS NOT NULL
                        ORDER BY a.data_avaliacao_morador DESC
                        LIMIT 5";
        
        $avaliacoes_stmt = $conexao->prepare($avaliacoes_sql);
        $avaliacoes_stmt->bind_param("i", $produto_id);
        $avaliacoes_stmt->execute();
        $avaliacoes_result = $avaliacoes_stmt->get_result();
        
        $avaliacoes = [];
        while ($row = $avaliacoes_result->fetch_assoc()) {
            $avaliacoes[] = $row;
        }
        $avaliacoes_stmt->close();
        
        $produto['avaliacoes'] = $avaliacoes;
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Produto obtido com sucesso',
            'dados' => $produto
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER PRODUTOS POR FORNECEDOR ==========
    if ($acao === 'listar_por_fornecedor' && $metodo === 'GET') {
        $fornecedor_id = intval($_GET['fornecedor_id'] ?? 0);
        
        if ($fornecedor_id <= 0) {
            throw new Exception('ID do fornecedor inválido');
        }
        
        $sql = "SELECT 
                    ps.id,
                    ps.tipo,
                    ps.codigo_sequencial,
                    ps.nome,
                    ps.descricao,
                    ps.preco_venda,
                    ps.quantidade_disponivel,
                    ROUND(AVG(a.nota_morador), 2) as media_nota,
                    COUNT(DISTINCT a.id) as total_avaliacoes
                FROM produtos_servicos ps
                LEFT JOIN avaliacoes a ON ps.id = a.produto_servico_id
                WHERE ps.fornecedor_id = ? AND ps.ativo = 1
                GROUP BY ps.id
                ORDER BY ps.data_criacao DESC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $fornecedor_id);
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
            'mensagem' => 'Produtos do fornecedor listados com sucesso',
            'dados' => $produtos,
            'total' => count($produtos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER AVALIAÇÕES DE UM PRODUTO ==========
    if ($acao === 'avaliacoes' && $metodo === 'GET') {
        $produto_id = intval($_GET['id'] ?? 0);
        
        if ($produto_id <= 0) {
            throw new Exception('ID do produto inválido');
        }
        
        $sql = "SELECT 
                    a.id,
                    a.nota_morador,
                    a.comentario_morador,
                    a.data_avaliacao_morador,
                    m.nome as morador_nome
                FROM avaliacoes a
                JOIN moradores m ON a.morador_id = m.id
                WHERE a.produto_servico_id = ? AND a.nota_morador IS NOT NULL
                ORDER BY a.data_avaliacao_morador DESC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $avaliacoes = [];
        while ($row = $result->fetch_assoc()) {
            $avaliacoes[] = $row;
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Avaliações obtidas com sucesso',
            'dados' => $avaliacoes,
            'total' => count($avaliacoes)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER MÉDIA DE AVALIAÇÃO DO FORNECEDOR ==========
    if ($acao === 'media_fornecedor' && $metodo === 'GET') {
        $fornecedor_id = intval($_GET['fornecedor_id'] ?? 0);
        
        if ($fornecedor_id <= 0) {
            throw new Exception('ID do fornecedor inválido');
        }
        
        $sql = "SELECT 
                    COUNT(a.id) as total_avaliacoes,
                    ROUND(AVG(a.nota_morador), 2) as media_nota_morador,
                    ROUND(AVG(a.nota_fornecedor), 2) as media_nota_fornecedor,
                    ROUND((AVG(a.nota_morador) + AVG(a.nota_fornecedor)) / 2, 2) as media_geral
                FROM avaliacoes a
                WHERE a.fornecedor_id = ?";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $fornecedor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        $stmt->close();
        
        if (!$stats['total_avaliacoes']) {
            $stats = [
                'total_avaliacoes' => 0,
                'media_nota_morador' => 0,
                'media_nota_fornecedor' => 0,
                'media_geral' => 0
            ];
        }
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Média de avaliação obtida',
            'dados' => $stats
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    throw new Exception('Ação inválida');
    
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
