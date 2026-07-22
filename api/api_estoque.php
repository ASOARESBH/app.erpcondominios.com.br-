<?php
/**
 * API de Gestão de Estoque
 * Condomínio Serra da Liberdade
 */

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
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Para operações de escrita, verificar permissão
if ($metodo !== 'GET') {
    verificarPermissao('operador');
}

// ========== CATEGORIAS ==========

// Listar categorias
if ($action === 'categorias' && $metodo === 'GET') {
    $stmt = $conexao->prepare("SELECT * FROM categorias_estoque WHERE ativo = 1 ORDER BY nome");
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $categorias = array();
    while ($row = $resultado->fetch_assoc()) {
        $categorias[] = $row;
    }
    
    retornar_json(true, "Categorias carregadas", $categorias);
}

// Criar categoria
if ($action === 'categorias' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $descricao = sanitizar($conexao, $dados['descricao'] ?? '');
    $cor = sanitizar($conexao, $dados['cor'] ?? '#667eea');
    
    $stmt = $conexao->prepare("INSERT INTO categorias_estoque (nome, descricao, cor) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $nome, $descricao, $cor);
    $stmt->execute();
    
    registrar_log('CATEGORIA_CRIADA', "Categoria $nome criada", '');
    retornar_json(true, "Categoria criada com sucesso");
}

// ========== PRODUTOS ==========

// Listar produtos
if ($action === 'produtos' && $metodo === 'GET') {
    $busca = $_GET['busca'] ?? '';
    $categoria_id = $_GET['categoria_id'] ?? '';
    $estoque_baixo = $_GET['estoque_baixo'] ?? '';
    
    $sql = "SELECT p.*, c.nome AS categoria_nome, c.cor AS categoria_cor 
            FROM produtos_estoque p 
            LEFT JOIN categorias_estoque c ON p.categoria_id = c.id 
            WHERE p.ativo = 1";
    
    if (!empty($busca)) {
        $sql .= " AND (p.nome LIKE ? OR p.codigo LIKE ? OR p.descricao LIKE ?)";
    }
    
    if (!empty($categoria_id)) {
        $sql .= " AND p.categoria_id = ?";
    }
    
    if ($estoque_baixo === '1') {
        $sql .= " AND p.quantidade_estoque <= p.estoque_minimo";
    }
    
    $sql .= " ORDER BY p.nome";
    
    $stmt = $conexao->prepare($sql);
    
    if (!empty($busca) && !empty($categoria_id)) {
        $busca_param = "%$busca%";
        $stmt->bind_param("sssi", $busca_param, $busca_param, $busca_param, $categoria_id);
    } elseif (!empty($busca)) {
        $busca_param = "%$busca%";
        $stmt->bind_param("sss", $busca_param, $busca_param, $busca_param);
    } elseif (!empty($categoria_id)) {
        $stmt->bind_param("i", $categoria_id);
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $produtos = array();
    while ($row = $resultado->fetch_assoc()) {
        $row['valor_total_estoque'] = $row['quantidade_estoque'] * $row['preco_unitario'];
        $row['alerta_estoque'] = $row['quantidade_estoque'] <= $row['estoque_minimo'];
        $produtos[] = $row;
    }
    
    retornar_json(true, "Produtos carregados", $produtos);
}

// Obter produto por ID
if ($action === 'produto' && $metodo === 'GET') {
    $id = intval($_GET['id'] ?? 0);
    
    $stmt = $conexao->prepare("SELECT p.*, c.nome AS categoria_nome FROM produtos_estoque p LEFT JOIN categorias_estoque c ON p.categoria_id = c.id WHERE p.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $produto = $resultado->fetch_assoc();
        $produto['valor_total_estoque'] = $produto['quantidade_estoque'] * $produto['preco_unitario'];
        retornar_json(true, "Produto encontrado", $produto);
    } else {
        retornar_json(false, "Produto não encontrado");
    }
}

// Criar produto
if ($action === 'produtos' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    // Gerar código automático se não fornecido
    $codigo = $dados['codigo'] ?? '';
    if (empty($codigo)) {
        $stmt = $conexao->prepare("SELECT MAX(CAST(SUBSTRING(codigo, 6) AS UNSIGNED)) AS ultimo FROM produtos_estoque WHERE codigo LIKE 'PROD-%'");
        $stmt->execute();
        $resultado = $stmt->get_result();
        $row = $resultado->fetch_assoc();
        $proximo = ($row['ultimo'] ?? 0) + 1;
        $codigo = 'PROD-' . str_pad($proximo, 3, '0', STR_PAD_LEFT);
    }
    
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $categoria_id = !empty($dados['categoria_id']) ? intval($dados['categoria_id']) : NULL;
    $unidade_medida = sanitizar($conexao, $dados['unidade_medida'] ?? 'Unidade');
    $descricao = sanitizar($conexao, $dados['descricao'] ?? '');
    $preco_unitario = floatval($dados['preco_unitario'] ?? 0);
    $quantidade_estoque = floatval($dados['quantidade_estoque'] ?? 0);
    $estoque_minimo = floatval($dados['estoque_minimo'] ?? 0);
    $estoque_maximo = floatval($dados['estoque_maximo'] ?? 0);
    $localizacao = sanitizar($conexao, $dados['localizacao'] ?? '');
    $fornecedor = sanitizar($conexao, $dados['fornecedor'] ?? '');
    $observacoes = sanitizar($conexao, $dados['observacoes'] ?? '');
    
    $stmt = $conexao->prepare("INSERT INTO produtos_estoque (codigo, nome, categoria_id, unidade_medida, descricao, preco_unitario, quantidade_estoque, estoque_minimo, estoque_maximo, localizacao, fornecedor, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissddddsss", $codigo, $nome, $categoria_id, $unidade_medida, $descricao, $preco_unitario, $quantidade_estoque, $estoque_minimo, $estoque_maximo, $localizacao, $fornecedor, $observacoes);
    $stmt->execute();
    
    $produto_id = $stmt->insert_id;
    
    // Se quantidade inicial > 0, registrar entrada
    if ($quantidade_estoque > 0) {
        $stmt = $conexao->prepare("INSERT INTO movimentacoes_estoque (produto_id, tipo_movimentacao, quantidade, quantidade_anterior, quantidade_posterior, usuario_responsavel, motivo, valor_unitario, valor_total) VALUES (?, 'Entrada', ?, 0, ?, 'Sistema', 'Estoque inicial', ?, ?)");
        $valor_total = $quantidade_estoque * $preco_unitario;
        $stmt->bind_param("idddd", $produto_id, $quantidade_estoque, $quantidade_estoque, $preco_unitario, $valor_total);
        $stmt->execute();
    }
    
    registrar_log('PRODUTO_CRIADO', "Produto $nome ($codigo) criado", '');
    retornar_json(true, "Produto criado com sucesso", array('codigo' => $codigo, 'id' => $produto_id));
}

// Atualizar produto
if ($action === 'produtos' && $metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($dados['id'] ?? 0);
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $categoria_id = !empty($dados['categoria_id']) ? intval($dados['categoria_id']) : NULL;
    $unidade_medida = sanitizar($conexao, $dados['unidade_medida'] ?? 'Unidade');
    $descricao = sanitizar($conexao, $dados['descricao'] ?? '');
    $preco_unitario = floatval($dados['preco_unitario'] ?? 0);
    $estoque_minimo = floatval($dados['estoque_minimo'] ?? 0);
    $estoque_maximo = floatval($dados['estoque_maximo'] ?? 0);
    $localizacao = sanitizar($conexao, $dados['localizacao'] ?? '');
    $fornecedor = sanitizar($conexao, $dados['fornecedor'] ?? '');
    $observacoes = sanitizar($conexao, $dados['observacoes'] ?? '');
    
    $stmt = $conexao->prepare("UPDATE produtos_estoque SET nome = ?, categoria_id = ?, unidade_medida = ?, descricao = ?, preco_unitario = ?, estoque_minimo = ?, estoque_maximo = ?, localizacao = ?, fornecedor = ?, observacoes = ? WHERE id = ?");
    $stmt->bind_param("sissdddsssi", $nome, $categoria_id, $unidade_medida, $descricao, $preco_unitario, $estoque_minimo, $estoque_maximo, $localizacao, $fornecedor, $observacoes, $id);
    $stmt->execute();
    
    registrar_log('PRODUTO_ATUALIZADO', "Produto ID $id atualizado", '');
    retornar_json(true, "Produto atualizado com sucesso");
}

// Deletar produto
if ($action === 'produtos' && $metodo === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    
    // Verificar se há movimentações
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM movimentacoes_estoque WHERE produto_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $row = $resultado->fetch_assoc();
    
    if ($row['total'] > 0) {
        // Não deletar, apenas inativar
        $stmt = $conexao->prepare("UPDATE produtos_estoque SET ativo = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        registrar_log('PRODUTO_INATIVADO', "Produto ID $id inativado", '');
        retornar_json(true, "Produto inativado com sucesso (há movimentações vinculadas)");
    } else {
        // Deletar permanentemente
        $stmt = $conexao->prepare("DELETE FROM produtos_estoque WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        registrar_log('PRODUTO_EXCLUIDO', "Produto ID $id excluído", '');
        retornar_json(true, "Produto excluído com sucesso");
    }
}

// ========== MOVIMENTAÇÕES ==========

// Listar movimentações
if ($action === 'movimentacoes' && $metodo === 'GET') {
    $produto_id = $_GET['produto_id'] ?? '';
    $tipo = $_GET['tipo'] ?? '';
    $data_inicio = $_GET['data_inicio'] ?? '';
    $data_fim = $_GET['data_fim'] ?? '';
    $morador_id = $_GET['morador_id'] ?? '';
    $limit = intval($_GET['limit'] ?? 100);
    
    $sql = "SELECT m.*, p.codigo AS produto_codigo, p.nome AS produto_nome, p.unidade_medida, 
            mo.nome AS morador_nome, mo.unidade AS morador_unidade
            FROM movimentacoes_estoque m
            INNER JOIN produtos_estoque p ON m.produto_id = p.id
            LEFT JOIN moradores mo ON m.morador_id = mo.id
            WHERE 1=1";
    
    $params = array();
    $types = '';
    
    if (!empty($produto_id)) {
        $sql .= " AND m.produto_id = ?";
        $params[] = intval($produto_id);
        $types .= 'i';
    }
    
    if (!empty($tipo)) {
        $sql .= " AND m.tipo_movimentacao = ?";
        $params[] = $tipo;
        $types .= 's';
    }
    
    if (!empty($data_inicio)) {
        $sql .= " AND DATE(m.data_movimentacao) >= ?";
        $params[] = $data_inicio;
        $types .= 's';
    }
    
    if (!empty($data_fim)) {
        $sql .= " AND DATE(m.data_movimentacao) <= ?";
        $params[] = $data_fim;
        $types .= 's';
    }
    
    if (!empty($morador_id)) {
        $sql .= " AND m.morador_id = ?";
        $params[] = intval($morador_id);
        $types .= 'i';
    }
    
    $sql .= " ORDER BY m.data_movimentacao DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $conexao->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $movimentacoes = array();
    while ($row = $resultado->fetch_assoc()) {
        $movimentacoes[] = $row;
    }
    
    retornar_json(true, "Movimentações carregadas", $movimentacoes);
}

// Registrar entrada
if ($action === 'entrada' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $produto_id = intval($dados['produto_id'] ?? 0);
    $quantidade = floatval($dados['quantidade'] ?? 0);
    $usuario_responsavel = sanitizar($conexao, $dados['usuario_responsavel'] ?? 'Admin');
    $motivo = sanitizar($conexao, $dados['motivo'] ?? '');
    $nota_fiscal = sanitizar($conexao, $dados['nota_fiscal'] ?? '');
    $valor_unitario = floatval($dados['valor_unitario'] ?? 0);
    $fornecedor = sanitizar($conexao, $dados['fornecedor'] ?? '');
    $observacoes = sanitizar($conexao, $dados['observacoes'] ?? '');
    
    // Obter quantidade anterior
    $stmt = $conexao->prepare("SELECT quantidade_estoque, preco_unitario FROM produtos_estoque WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Produto não encontrado");
    }
    
    $produto = $resultado->fetch_assoc();
    $quantidade_anterior = $produto['quantidade_estoque'];
    $quantidade_posterior = $quantidade_anterior + $quantidade;
    
    // Se valor unitário não informado, usar do produto
    if ($valor_unitario == 0) {
        $valor_unitario = $produto['preco_unitario'];
    }
    
    $valor_total = $quantidade * $valor_unitario;
    
    // Registrar movimentação
    $stmt = $conexao->prepare("INSERT INTO movimentacoes_estoque (produto_id, tipo_movimentacao, quantidade, quantidade_anterior, quantidade_posterior, usuario_responsavel, motivo, nota_fiscal, valor_unitario, valor_total, fornecedor, observacoes) VALUES (?, 'Entrada', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("idddsssddss", $produto_id, $quantidade, $quantidade_anterior, $quantidade_posterior, $usuario_responsavel, $motivo, $nota_fiscal, $valor_unitario, $valor_total, $fornecedor, $observacoes);
    $stmt->execute();
    
    // Atualizar estoque
    $stmt = $conexao->prepare("UPDATE produtos_estoque SET quantidade_estoque = ? WHERE id = ?");
    $stmt->bind_param("di", $quantidade_posterior, $produto_id);
    $stmt->execute();
    
    registrar_log('ENTRADA_ESTOQUE', "Entrada de $quantidade unidades do produto ID $produto_id", $usuario_responsavel);
    retornar_json(true, "Entrada registrada com sucesso", array('quantidade_nova' => $quantidade_posterior));
}

// Registrar saída
if ($action === 'saida' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $produto_id = intval($dados['produto_id'] ?? 0);
    $quantidade = floatval($dados['quantidade'] ?? 0);
    $tipo_destino = sanitizar($conexao, $dados['tipo_destino'] ?? 'Administracao');
    $morador_id = !empty($dados['morador_id']) ? intval($dados['morador_id']) : NULL;
    $usuario_responsavel = sanitizar($conexao, $dados['usuario_responsavel'] ?? 'Admin');
    $motivo = sanitizar($conexao, $dados['motivo'] ?? '');
    $observacoes = sanitizar($conexao, $dados['observacoes'] ?? '');
    
    // Obter quantidade anterior
    $stmt = $conexao->prepare("SELECT quantidade_estoque, preco_unitario, nome FROM produtos_estoque WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Produto não encontrado");
    }
    
    $produto = $resultado->fetch_assoc();
    $quantidade_anterior = $produto['quantidade_estoque'];
    
    // Verificar se há estoque suficiente
    if ($quantidade > $quantidade_anterior) {
        retornar_json(false, "Estoque insuficiente. Disponível: $quantidade_anterior");
    }
    
    $quantidade_posterior = $quantidade_anterior - $quantidade;
    $valor_unitario = $produto['preco_unitario'];
    $valor_total = $quantidade * $valor_unitario;
    
    // Registrar movimentação
    $stmt = $conexao->prepare("INSERT INTO movimentacoes_estoque (produto_id, tipo_movimentacao, quantidade, quantidade_anterior, quantidade_posterior, tipo_destino, morador_id, usuario_responsavel, motivo, valor_unitario, valor_total, observacoes) VALUES (?, 'Saida', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("idddsissdds", $produto_id, $quantidade, $quantidade_anterior, $quantidade_posterior, $tipo_destino, $morador_id, $usuario_responsavel, $motivo, $valor_unitario, $valor_total, $observacoes);
    $stmt->execute();
    
    // Atualizar estoque
    $stmt = $conexao->prepare("UPDATE produtos_estoque SET quantidade_estoque = ? WHERE id = ?");
    $stmt->bind_param("di", $quantidade_posterior, $produto_id);
    $stmt->execute();
    
    registrar_log('SAIDA_ESTOQUE', "Saída de $quantidade unidades do produto {$produto['nome']} para $tipo_destino", $usuario_responsavel);
    retornar_json(true, "Saída registrada com sucesso", array('quantidade_nova' => $quantidade_posterior));
}

// ========== DASHBOARD ==========

// Estatísticas do dashboard
if ($action === 'dashboard' && $metodo === 'GET') {
    $stats = array();
    
    // Total de produtos
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM produtos_estoque WHERE ativo = 1");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['total_produtos'] = $resultado->fetch_assoc()['total'];
    
    // Valor total do estoque
    $stmt = $conexao->prepare("SELECT SUM(quantidade_estoque * preco_unitario) AS valor_total FROM produtos_estoque WHERE ativo = 1");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['valor_total_estoque'] = floatval($resultado->fetch_assoc()['valor_total']);
    
    // Produtos com estoque baixo
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM produtos_estoque WHERE quantidade_estoque <= estoque_minimo AND ativo = 1");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['produtos_estoque_baixo'] = $resultado->fetch_assoc()['total'];
    
    // Produtos zerados
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM produtos_estoque WHERE quantidade_estoque = 0 AND ativo = 1");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['produtos_zerados'] = $resultado->fetch_assoc()['total'];
    
    // Movimentações do mês
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM movimentacoes_estoque WHERE MONTH(data_movimentacao) = MONTH(NOW()) AND YEAR(data_movimentacao) = YEAR(NOW())");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['movimentacoes_mes'] = $resultado->fetch_assoc()['total'];
    
    // Entradas do mês
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total, SUM(valor_total) AS valor FROM movimentacoes_estoque WHERE tipo_movimentacao = 'Entrada' AND MONTH(data_movimentacao) = MONTH(NOW()) AND YEAR(data_movimentacao) = YEAR(NOW())");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $row = $resultado->fetch_assoc();
    $stats['entradas_mes'] = $row['total'];
    $stats['valor_entradas_mes'] = floatval($row['valor']);
    
    // Saídas do mês
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total, SUM(valor_total) AS valor FROM movimentacoes_estoque WHERE tipo_movimentacao = 'Saida' AND MONTH(data_movimentacao) = MONTH(NOW()) AND YEAR(data_movimentacao) = YEAR(NOW())");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $row = $resultado->fetch_assoc();
    $stats['saidas_mes'] = $row['total'];
    $stats['valor_saidas_mes'] = floatval($row['valor']);
    
    // Produtos mais movimentados
    $stmt = $conexao->prepare("SELECT p.nome, COUNT(m.id) AS total_movimentacoes FROM movimentacoes_estoque m INNER JOIN produtos_estoque p ON m.produto_id = p.id WHERE MONTH(m.data_movimentacao) = MONTH(NOW()) GROUP BY p.id ORDER BY total_movimentacoes DESC LIMIT 5");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['produtos_mais_movimentados'] = array();
    while ($row = $resultado->fetch_assoc()) {
        $stats['produtos_mais_movimentados'][] = $row;
    }
    
    // Alertas não lidos
    $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM alertas_estoque WHERE lido = 0");
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stats['alertas_nao_lidos'] = $resultado->fetch_assoc()['total'];
    
    retornar_json(true, "Dashboard carregado", $stats);
}

// ========== ALERTAS ==========

// Listar alertas
if ($action === 'alertas' && $metodo === 'GET') {
    $stmt = $conexao->prepare("SELECT a.*, p.codigo, p.nome AS produto_nome FROM alertas_estoque a INNER JOIN produtos_estoque p ON a.produto_id = p.id WHERE a.lido = 0 ORDER BY a.data_alerta DESC LIMIT 50");
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $alertas = array();
    while ($row = $resultado->fetch_assoc()) {
        $alertas[] = $row;
    }
    
    retornar_json(true, "Alertas carregados", $alertas);
}

// Marcar alerta como lido
if ($action === 'alertas' && $metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);
    
    $stmt = $conexao->prepare("UPDATE alertas_estoque SET lido = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    retornar_json(true, "Alerta marcado como lido");
}

// ========== RELATÓRIOS ==========

// Relatório de consumo por morador
if ($action === 'relatorio_consumo_morador' && $metodo === 'GET') {
    $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-m-t');
    
    $stmt = $conexao->prepare("SELECT mo.id, mo.nome, mo.unidade, COUNT(m.id) AS total_retiradas, SUM(m.quantidade) AS quantidade_total, SUM(m.valor_total) AS valor_total FROM moradores mo INNER JOIN movimentacoes_estoque m ON mo.id = m.morador_id WHERE m.tipo_movimentacao = 'Saida' AND DATE(m.data_movimentacao) BETWEEN ? AND ? GROUP BY mo.id ORDER BY valor_total DESC");
    $stmt->bind_param("ss", $data_inicio, $data_fim);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $relatorio = array();
    while ($row = $resultado->fetch_assoc()) {
        $relatorio[] = $row;
    }
    
    retornar_json(true, "Relatório gerado", $relatorio);
}

// Relatório de movimentação por período
if ($action === 'relatorio_movimentacao' && $metodo === 'GET') {
    $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-m-t');
    $tipo = $_GET['tipo'] ?? '';
    
    $sql = "SELECT m.*, p.codigo, p.nome AS produto_nome, p.unidade_medida, mo.nome AS morador_nome, mo.unidade AS morador_unidade FROM movimentacoes_estoque m INNER JOIN produtos_estoque p ON m.produto_id = p.id LEFT JOIN moradores mo ON m.morador_id = mo.id WHERE DATE(m.data_movimentacao) BETWEEN ? AND ?";
    
    if (!empty($tipo)) {
        $sql .= " AND m.tipo_movimentacao = ?";
    }
    
    $sql .= " ORDER BY m.data_movimentacao DESC";
    
    $stmt = $conexao->prepare($sql);
    
    if (!empty($tipo)) {
        $stmt->bind_param("sss", $data_inicio, $data_fim, $tipo);
    } else {
        $stmt->bind_param("ss", $data_inicio, $data_fim);
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $relatorio = array();
    $total_entradas = 0;
    $total_saidas = 0;
    $valor_entradas = 0;
    $valor_saidas = 0;
    
    while ($row = $resultado->fetch_assoc()) {
        if ($row['tipo_movimentacao'] === 'Entrada') {
            $total_entradas += $row['quantidade'];
            $valor_entradas += $row['valor_total'];
        } else {
            $total_saidas += $row['quantidade'];
            $valor_saidas += $row['valor_total'];
        }
        $relatorio[] = $row;
    }
    
    retornar_json(true, "Relatório gerado", array(
        'movimentacoes' => $relatorio,
        'resumo' => array(
            'total_entradas' => $total_entradas,
            'total_saidas' => $total_saidas,
            'valor_entradas' => $valor_entradas,
            'valor_saidas' => $valor_saidas
        )
    ));
}

// Ação não encontrada
http_response_code(404);
retornar_json(false, "Ação não encontrada: $action");
