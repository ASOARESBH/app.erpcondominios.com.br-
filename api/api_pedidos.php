<?php
// =====================================================
// API DE PEDIDOS E AVALIAÇÕES
// =====================================================
// Gerencia pedidos, aprovações e avaliações

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

// Função de log de debug do fornecedor
if (!function_exists('log_fornecedor')) {
    function log_fornecedor(string $tipo, string $descricao, ?string $usuario = null, array $extra = []): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $session_id = session_id() ?: 'sem-sessao';
        $timestamp  = date('Y-m-d H:i:s');
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                $desc = $descricao . (!empty($extra) ? ' | DEBUG: ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : '') . ' | SID: ' . $session_id;
                $stmt = $conn->prepare("INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) { $stmt->bind_param('sssss', $tipo, $desc, $usuario, $ip, $timestamp); $stmt->execute(); $stmt->close(); }
                $conn->close();
            }
        } catch (Exception $e) { error_log('[log_fornecedor] ' . $e->getMessage()); }
        $log_dir = '/var/log/erp_auth/';
        if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
        @file_put_contents($log_dir . 'fornecedor_' . date('Y-m-d') . '.log',
            json_encode(['timestamp' => $timestamp, 'tipo' => $tipo, 'usuario' => $usuario,
                'ip' => $ip, 'session_id' => $session_id, 'descricao' => $descricao, 'extra' => $extra,
            ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

// Verificar autenticação: aceita sessão do ERP (usuario_logado) OU sessão do fornecedor
$is_erp        = isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true;
$is_fornecedor = isset($_SESSION['fornecedor_id'])  && intval($_SESSION['fornecedor_id']) > 0;

if (!$is_erp && !$is_fornecedor) {
    log_fornecedor('PEDIDOS_AUTH_ERRO', "Acesso negado sem sessão válida. Acao: $acao", null, [
        'session_id'        => session_id(),
        'session_keys'      => array_keys($_SESSION),
        'usuario_logado'    => $_SESSION['usuario_logado']    ?? 'não definido',
        'fornecedor_id'     => $_SESSION['fornecedor_id']     ?? 'não definido',
        'fornecedor_logado' => $_SESSION['fornecedor_logado'] ?? 'não definido',
    ]);
    http_response_code(401);
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => 'Autenticação necessária. Faça login novamente.',
        'codigo'   => 'AUTH_REQUIRED'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Para operações de escrita do ERP, verificar permissão
if ($metodo !== 'GET' && $is_erp) {
    verificarPermissao('operador');
}

try {
    $conexao = conectar_banco();
    
    // ========== LISTAR PEDIDOS DO FORNECEDOR ==========
    if ($acao === 'listar_fornecedor' && $metodo === 'GET') {
        if (!isset($_SESSION['fornecedor_id'])) {
            throw new Exception('Fornecedor não autenticado');
        }
        
        $fornecedor_id = $_SESSION['fornecedor_id'];
        $status = $_GET['status'] ?? '';
        
        $sql = "SELECT 
                    p.id,
                    p.morador_id,
                    p.fornecedor_id,
                    p.produto_servico_id,
                    p.descricao_pedido,
                    p.valor_proposto,
                    p.status,
                    p.motivo_recusa,
                    p.data_pedido,
                    p.data_aceite,
                    p.data_inicio_execucao,
                    p.data_finalizacao,
                    ps.nome as produto_nome,
                    ps.tipo as produto_tipo,
                    m.nome as morador_nome,
                    m.email as morador_email
                FROM pedidos p
                JOIN produtos_servicos ps ON p.produto_servico_id = ps.id
                JOIN moradores m ON p.morador_id = m.id
                WHERE p.fornecedor_id = ?";
        
        $params = [$fornecedor_id];
        $types = "i";
        
        if (!empty($status)) {
            $sql .= " AND p.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $sql .= " ORDER BY p.data_pedido DESC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $pedidos = [];
        while ($row = $result->fetch_assoc()) {
            $pedidos[] = $row;
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedidos listados com sucesso',
            'dados' => $pedidos,
            'total' => count($pedidos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== LISTAR PEDIDOS DO MORADOR ==========
    if ($acao === 'listar_morador' && $metodo === 'GET') {
        if (!isset($_SESSION['morador_id'])) {
            throw new Exception('Morador não autenticado');
        }
        
        $morador_id = $_SESSION['morador_id'];
        $status = $_GET['status'] ?? '';
        
        $sql = "SELECT 
                    p.id,
                    p.morador_id,
                    p.fornecedor_id,
                    p.produto_servico_id,
                    p.descricao_pedido,
                    p.valor_proposto,
                    p.status,
                    p.motivo_recusa,
                    p.data_pedido,
                    p.data_aceite,
                    p.data_inicio_execucao,
                    p.data_finalizacao,
                    ps.nome as produto_nome,
                    ps.tipo as produto_tipo,
                    f.nome_estabelecimento as fornecedor_nome,
                    f.email as fornecedor_email
                FROM pedidos p
                JOIN produtos_servicos ps ON p.produto_servico_id = ps.id
                JOIN fornecedores f ON p.fornecedor_id = f.id
                WHERE p.morador_id = ?";
        
        $params = [$morador_id];
        $types = "i";
        
        if (!empty($status)) {
            $sql .= " AND p.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $sql .= " ORDER BY p.data_pedido DESC";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $pedidos = [];
        while ($row = $result->fetch_assoc()) {
            $pedidos[] = $row;
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedidos listados com sucesso',
            'dados' => $pedidos,
            'total' => count($pedidos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== CRIAR NOVO PEDIDO ==========
    if ($acao === 'criar' && $metodo === 'POST') {
        if (!isset($_SESSION['morador_id'])) {
            throw new Exception('Morador não autenticado');
        }
        
        $morador_id = $_SESSION['morador_id'];
        $produto_servico_id = intval($_POST['produto_servico_id'] ?? 0);
        $descricao_pedido = trim($_POST['descricao_pedido'] ?? '');
        $valor_proposto = floatval($_POST['valor_proposto'] ?? 0);
        
        if ($produto_servico_id <= 0) {
            throw new Exception('Produto/serviço inválido');
        }
        
        // Obter dados do produto
        $produto_stmt = $conexao->prepare(
            "SELECT fornecedor_id, preco_venda FROM produtos_servicos WHERE id = ? AND ativo = 1"
        );
        $produto_stmt->bind_param("i", $produto_servico_id);
        $produto_stmt->execute();
        $produto_result = $produto_stmt->get_result();
        
        if ($produto_result->num_rows === 0) {
            throw new Exception('Produto não encontrado ou inativo');
        }
        
        $produto = $produto_result->fetch_assoc();
        $fornecedor_id = $produto['fornecedor_id'];
        $produto_stmt->close();
        
        // Se valor não foi informado, usar preço do produto
        if ($valor_proposto <= 0) {
            $valor_proposto = $produto['preco_venda'];
        }
        
        // Inserir pedido
        $sql = "INSERT INTO pedidos 
                (morador_id, fornecedor_id, produto_servico_id, descricao_pedido, valor_proposto, status)
                VALUES (?, ?, ?, ?, ?, 'aguardando')";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param(
            "iiids",
            $morador_id,
            $fornecedor_id,
            $produto_servico_id,
            $descricao_pedido,
            $valor_proposto
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao criar pedido: ' . $stmt->error);
        }
        
        $pedido_id = $stmt->insert_id;
        $stmt->close();
        
        // Registrar log
        registrar_log_pedido($conexao, $pedido_id, 'Pedido criado', $morador_id);
        
        http_response_code(201);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedido criado com sucesso',
            'dados' => [
                'id' => $pedido_id,
                'status' => 'aguardando'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== APROVAR PEDIDO (FORNECEDOR) ==========
    if ($acao === 'aprovar' && $metodo === 'POST') {
        if (!isset($_SESSION['fornecedor_id'])) {
            throw new Exception('Fornecedor não autenticado');
        }
        
        $fornecedor_id = $_SESSION['fornecedor_id'];
        $pedido_id = intval($_POST['pedido_id'] ?? 0);
        
        if ($pedido_id <= 0) {
            throw new Exception('Pedido inválido');
        }
        
        // Verificar se pedido pertence ao fornecedor
        $check_stmt = $conexao->prepare(
            "SELECT id FROM pedidos WHERE id = ? AND fornecedor_id = ? AND status = 'aguardando'"
        );
        $check_stmt->bind_param("ii", $pedido_id, $fornecedor_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Pedido não encontrado ou já foi processado');
        }
        $check_stmt->close();
        
        // Atualizar status para 'executando'
        $sql = "UPDATE pedidos SET status = 'executando', data_aceite = NOW(), data_inicio_execucao = NOW() WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $pedido_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao aprovar pedido: ' . $stmt->error);
        }
        $stmt->close();
        
        registrar_log_pedido($conexao, $pedido_id, 'Pedido aprovado pelo fornecedor', $fornecedor_id);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedido aprovado com sucesso',
            'novo_status' => 'executando'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== RECUSAR PEDIDO (FORNECEDOR) ==========
    if ($acao === 'recusar' && $metodo === 'POST') {
        if (!isset($_SESSION['fornecedor_id'])) {
            throw new Exception('Fornecedor não autenticado');
        }
        
        $fornecedor_id = $_SESSION['fornecedor_id'];
        $pedido_id = intval($_POST['pedido_id'] ?? 0);
        $motivo_recusa = trim($_POST['motivo_recusa'] ?? '');
        
        if ($pedido_id <= 0) {
            throw new Exception('Pedido inválido');
        }
        
        // Verificar se pedido pertence ao fornecedor
        $check_stmt = $conexao->prepare(
            "SELECT id FROM pedidos WHERE id = ? AND fornecedor_id = ? AND status = 'aguardando'"
        );
        $check_stmt->bind_param("ii", $pedido_id, $fornecedor_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Pedido não encontrado ou já foi processado');
        }
        $check_stmt->close();
        
        // Atualizar status para 'cancelado'
        $sql = "UPDATE pedidos SET status = 'cancelado', motivo_recusa = ? WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("si", $motivo_recusa, $pedido_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao recusar pedido: ' . $stmt->error);
        }
        $stmt->close();
        
        registrar_log_pedido($conexao, $pedido_id, 'Pedido recusado: ' . $motivo_recusa, $fornecedor_id);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedido recusado com sucesso',
            'novo_status' => 'cancelado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== FINALIZAR PEDIDO ==========
    if ($acao === 'finalizar' && $metodo === 'POST') {
        $pedido_id = intval($_POST['pedido_id'] ?? 0);
        $usuario_id = $_SESSION['fornecedor_id'] ?? $_SESSION['morador_id'] ?? 0;
        
        if ($pedido_id <= 0 || $usuario_id <= 0) {
            throw new Exception('Dados inválidos');
        }
        
        // Verificar se pedido pode ser finalizado
        $check_stmt = $conexao->prepare(
            "SELECT id, status FROM pedidos WHERE id = ? AND (fornecedor_id = ? OR morador_id = ?)"
        );
        $check_stmt->bind_param("iii", $pedido_id, $usuario_id, $usuario_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Pedido não encontrado');
        }
        
        $pedido = $check_result->fetch_assoc();
        if ($pedido['status'] !== 'executando') {
            throw new Exception('Apenas pedidos em execução podem ser finalizados');
        }
        $check_stmt->close();
        
        // Atualizar status
        $sql = "UPDATE pedidos SET status = 'finalizado', data_finalizacao = NOW() WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $pedido_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao finalizar pedido: ' . $stmt->error);
        }
        $stmt->close();
        
        registrar_log_pedido($conexao, $pedido_id, 'Pedido finalizado', $usuario_id);
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pedido finalizado com sucesso',
            'novo_status' => 'finalizado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== REGISTRAR AVALIAÇÃO ==========
    if ($acao === 'avaliar' && $metodo === 'POST') {
        $pedido_id = intval($_POST['pedido_id'] ?? 0);
        $nota = intval($_POST['nota'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');
        $tipo_avaliador = $_POST['tipo'] ?? '';  // 'morador' ou 'fornecedor'
        
        if ($pedido_id <= 0 || $nota < 1 || $nota > 5) {
            throw new Exception('Dados de avaliação inválidos');
        }
        
        if (!in_array($tipo_avaliador, ['morador', 'fornecedor'])) {
            throw new Exception('Tipo de avaliador inválido');
        }
        
        // Obter dados do pedido
        $pedido_stmt = $conexao->prepare(
            "SELECT morador_id, fornecedor_id, produto_servico_id, status FROM pedidos WHERE id = ?"
        );
        $pedido_stmt->bind_param("i", $pedido_id);
        $pedido_stmt->execute();
        $pedido_result = $pedido_stmt->get_result();
        
        if ($pedido_result->num_rows === 0) {
            throw new Exception('Pedido não encontrado');
        }
        
        $pedido = $pedido_result->fetch_assoc();
        if ($pedido['status'] !== 'finalizado') {
            throw new Exception('Apenas pedidos finalizados podem ser avaliados');
        }
        $pedido_stmt->close();
        
        // Verificar se avaliação já existe
        $check_stmt = $conexao->prepare(
            "SELECT id FROM avaliacoes WHERE pedido_id = ?"
        );
        $check_stmt->bind_param("i", $pedido_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_stmt->close();
        
        if ($check_result->num_rows === 0) {
            // Criar nova avaliação
            $sql = "INSERT INTO avaliacoes 
                    (pedido_id, morador_id, fornecedor_id, produto_servico_id)
                    VALUES (?, ?, ?, ?)";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param(
                "iiii",
                $pedido_id,
                $pedido['morador_id'],
                $pedido['fornecedor_id'],
                $pedido['produto_servico_id']
            );
            $stmt->execute();
            $stmt->close();
        }
        
        // Atualizar avaliação
        if ($tipo_avaliador === 'morador') {
            $sql = "UPDATE avaliacoes SET nota_morador = ?, comentario_morador = ?, data_avaliacao_morador = NOW() WHERE pedido_id = ?";
        } else {
            $sql = "UPDATE avaliacoes SET nota_fornecedor = ?, comentario_fornecedor = ?, data_avaliacao_fornecedor = NOW() WHERE pedido_id = ?";
        }
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("ssi", $nota, $comentario, $pedido_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Erro ao registrar avaliação: ' . $stmt->error);
        }
        $stmt->close();
        
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Avaliação registrada com sucesso'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== OBTER MÉDIA DE AVALIAÇÃO DO FORNECEDOR ==========
    if ($acao === 'media_fornecedor' && $metodo === 'GET') {
        // Aceita fornecedor_id via GET (admin ERP) ou via sessão do fornecedor logado
        $fornecedor_id = intval($_GET['fornecedor_id'] ?? $_SESSION['fornecedor_id'] ?? 0);
        
        if ($fornecedor_id <= 0) {
            throw new Exception('Fornecedor inválido');
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
            $stats['total_avaliacoes'] = 0;
            $stats['media_nota_morador'] = 0;
            $stats['media_nota_fornecedor'] = 0;
            $stats['media_geral'] = 0;
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

// ========== FUNÇÃO AUXILIAR ==========
function registrar_log_pedido($conexao, $pedido_id, $descricao, $usuario_id) {
    try {
        $tipo = 'PEDIDO_MARKETPLACE';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $data_hora = date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("sssss", $tipo, $descricao, $usuario_id, $ip, $data_hora);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log('Erro ao registrar log de pedido: ' . $e->getMessage());
    }
}

fechar_conexao($conexao);
?>
