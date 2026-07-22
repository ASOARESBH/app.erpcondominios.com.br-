<?php
// =====================================================
// API PARA CRUD DE HIDRÔMETROS
// =====================================================

ob_start();
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

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== BUSCAR HISTÓRICO (deve vir ANTES do GET genérico) ==========
if ($metodo === 'GET' && isset($_GET['historico'])) {
    $hidrometro_id = intval($_GET['historico']);

    $sql = "SELECT *, DATE_FORMAT(data_alteracao, '%d/%m/%Y %H:%i:%s') as data_formatada
            FROM hidrometros_historico
            WHERE hidrometro_id = $hidrometro_id
            ORDER BY data_alteracao DESC";

    $resultado = $conexao->query($sql);
    $historico = array();

    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $historico[] = $row;
        }
    }

    retornar_json(true, "Histórico carregado", $historico);
}

// ========== BUSCAR HIDRÔMETRO POR ID ESPECÍFICO ==========
if ($metodo === 'GET' && isset($_GET['id']) && !isset($_GET['historico'])) {
    $id = intval($_GET['id']);
    if ($id <= 0) retornar_json(false, "ID inválido");

    $stmt = $conexao->prepare(
        "SELECT h.*, m.nome as morador_nome, m.cpf as morador_cpf,
         DATE_FORMAT(h.data_instalacao, '%d/%m/%Y %H:%i') as data_instalacao_formatada,
         DATE_FORMAT(h.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_formatada,
         (SELECT leitura_atual FROM leituras WHERE hidrometro_id = h.id ORDER BY data_leitura DESC LIMIT 1) as ultima_leitura
         FROM hidrometros h
         LEFT JOIN moradores m ON h.morador_id = m.id
         WHERE h.id = ?"
    );
    if (!$stmt) retornar_json(false, "Erro ao preparar query: " . $conexao->error);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        retornar_json(true, "Hidrômetro encontrado", $row);
    } else {
        retornar_json(false, "Hidrômetro não encontrado");
    }
}

// ========== LISTAR HIDRÔMETROS (Pesquisa Avançada) ==========
if ($metodo === 'GET') {
    $busca         = isset($_GET['busca'])        ? trim($_GET['busca'])        : '';
    // ?ativos=1 é usado por outras telas (leitura individual/coletiva) para "somente ativos";
    // mantido intacto por compatibilidade. ?status=1|0 é o novo filtro da pesquisa avançada
    // (Todos/Ativos/Inativos) e só é aplicado quando ?ativos não está presente.
    $apenas_ativos = isset($_GET['ativos'])       ? true : false;
    $status_fil    = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : '';
    $morador_id    = isset($_GET['morador_id'])   ? intval($_GET['morador_id'])  : 0;
    $unidade_fil   = isset($_GET['unidade'])      ? trim($_GET['unidade'])       : '';
    $data_inicial  = isset($_GET['data_inicial']) ? trim($_GET['data_inicial'])  : '';
    $data_final    = isset($_GET['data_final'])   ? trim($_GET['data_final'])    : '';

    $where  = ['1=1'];
    $params = [];
    $tipos  = '';

    if ($apenas_ativos) {
        $where[] = 'h.ativo = 1';
    } elseif ($status_fil !== '') {
        $where[]  = 'h.ativo = ?';
        $params[] = intval($status_fil);
        $tipos   .= 'i';
    }

    if ($morador_id > 0) {
        $where[]  = 'h.morador_id = ?';
        $params[] = $morador_id;
        $tipos   .= 'i';
    }

    if ($unidade_fil !== '') {
        $where[]  = 'h.unidade = ?';
        $params[] = $unidade_fil;
        $tipos   .= 's';
    }

    // Filtro por período de instalação (data_instalacao)
    if ($data_inicial !== '') {
        $where[]  = 'DATE(h.data_instalacao) >= ?';
        $params[] = $data_inicial;
        $tipos   .= 's';
    }
    if ($data_final !== '') {
        $where[]  = 'DATE(h.data_instalacao) <= ?';
        $params[] = $data_final;
        $tipos   .= 's';
    }

    // Pesquisa livre: número do hidrômetro, número do lacre, unidade ou nome do morador
    if ($busca !== '') {
        $where[]  = '(h.numero_hidrometro LIKE ? OR h.numero_lacre LIKE ? OR h.unidade LIKE ? OR m.nome LIKE ?)';
        $coringa  = '%' . $busca . '%';
        $params[] = $coringa;
        $params[] = $coringa;
        $params[] = $coringa;
        $params[] = $coringa;
        $tipos   .= 'ssss';
    }

    $where_sql = implode(' AND ', $where);

    // Apenas os campos utilizados na tela (evita SELECT *)
    $sql = "SELECT h.id, h.morador_id, h.unidade, h.numero_hidrometro, h.numero_lacre,
                   h.ativo, h.inventario_id, m.nome as morador_nome,
                   DATE_FORMAT(h.data_instalacao, '%d/%m/%Y %H:%i') as data_instalacao_formatada,
                   (SELECT leitura_atual FROM leituras WHERE hidrometro_id = h.id ORDER BY data_leitura DESC LIMIT 1) as ultima_leitura
            FROM hidrometros h
            LEFT JOIN moradores m ON h.morador_id = m.id
            WHERE $where_sql
            ORDER BY CAST(h.unidade AS UNSIGNED) ASC, h.unidade ASC, m.nome ASC";

    if ($params) {
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();
    } else {
        $resultado = $conexao->query($sql);
    }

    $hidrometros = array();
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $hidrometros[] = $row;
        }
    }

    if (isset($stmt)) $stmt->close();

    retornar_json(true, "Hidrômetros listados com sucesso", $hidrometros);
}

// ========== CRIAR HIDRÔMETRO ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $morador_id        = intval($dados['morador_id']        ?? 0);
    $unidade           = sanitizar($conexao, $dados['unidade']           ?? '');
    $numero_hidrometro = sanitizar($conexao, $dados['numero_hidrometro'] ?? '');
    $numero_lacre      = sanitizar($conexao, $dados['numero_lacre']      ?? '');
    $data_instalacao   = sanitizar($conexao, $dados['data_instalacao']   ?? '');
    $inventario_id     = isset($dados['inventario_id']) && $dados['inventario_id'] > 0
                         ? intval($dados['inventario_id']) : null;

    // Validações
    if ($morador_id <= 0) {
        retornar_json(false, "Morador é obrigatório");
    }
    
    if (empty($numero_hidrometro)) {
        retornar_json(false, "Número do hidrômetro é obrigatório");
    }
    
    if (empty($data_instalacao)) {
        retornar_json(false, "Data de instalação é obrigatória");
    }
    
    // Verificar se número já existe
    $stmt = $conexao->prepare("SELECT id FROM hidrometros WHERE numero_hidrometro = ?");
    $stmt->bind_param("s", $numero_hidrometro);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        retornar_json(false, "Número de hidrômetro já cadastrado no sistema");
    }
    $stmt->close();
    
    // Inserir hidrômetro
    if ($inventario_id !== null) {
        $stmt = $conexao->prepare("INSERT INTO hidrometros (morador_id, unidade, numero_hidrometro, numero_lacre, data_instalacao, inventario_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssi", $morador_id, $unidade, $numero_hidrometro, $numero_lacre, $data_instalacao, $inventario_id);
    } else {
        $stmt = $conexao->prepare("INSERT INTO hidrometros (morador_id, unidade, numero_hidrometro, numero_lacre, data_instalacao) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $morador_id, $unidade, $numero_hidrometro, $numero_lacre, $data_instalacao);
    }
    
    if ($stmt->execute()) {
        $id = $conexao->insert_id;
        registrar_log($conexao, 'INFO', "Hidrômetro cadastrado: $numero_hidrometro (ID: $id)");
        retornar_json(true, "Hidrômetro cadastrado com sucesso", array('id' => $id));
    } else {
        retornar_json(false, "Erro ao cadastrar hidrômetro: " . $stmt->error);
    }
    
    $stmt->close();
}

// ========== ATUALIZAR HIDRÔMETRO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($dados['id'] ?? 0);
    $morador_id = intval($dados['morador_id'] ?? 0);
    $unidade = sanitizar($conexao, $dados['unidade'] ?? '');
    $numero_hidrometro = sanitizar($conexao, $dados['numero_hidrometro'] ?? '');
    $numero_lacre = sanitizar($conexao, $dados['numero_lacre'] ?? '');
    $data_instalacao = sanitizar($conexao, $dados['data_instalacao'] ?? '');
    $ativo = isset($dados['ativo']) ? intval($dados['ativo']) : 1;
    $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
    $inventario_id = isset($dados['inventario_id']) && $dados['inventario_id'] > 0
                     ? intval($dados['inventario_id']) : null;
    
    if ($id <= 0) {
        retornar_json(false, "ID inválido");
    }
    
    if (empty($observacao)) {
        retornar_json(false, "Observação é obrigatória para edição");
    }
    
    // Buscar dados anteriores
    $stmt = $conexao->prepare("SELECT * FROM hidrometros WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $anterior = $resultado->fetch_assoc();
    $stmt->close();
    
    if (!$anterior) {
        retornar_json(false, "Hidrômetro não encontrado");
    }
    
    // Verificar se número já existe em outro hidrômetro
    $stmt = $conexao->prepare("SELECT id FROM hidrometros WHERE numero_hidrometro = ? AND id != ?");
    $stmt->bind_param("si", $numero_hidrometro, $id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        retornar_json(false, "Número de hidrômetro já cadastrado");
    }
    $stmt->close();
    
    // Registrar histórico de alterações
    $campos_alterados = array();
    
    if ($anterior['morador_id'] != $morador_id) {
        $campos_alterados[] = array('campo' => 'morador_id', 'anterior' => $anterior['morador_id'], 'novo' => $morador_id);
    }
    if ($anterior['numero_hidrometro'] != $numero_hidrometro) {
        $campos_alterados[] = array('campo' => 'numero_hidrometro', 'anterior' => $anterior['numero_hidrometro'], 'novo' => $numero_hidrometro);
    }
    if ($anterior['numero_lacre'] != $numero_lacre) {
        $campos_alterados[] = array('campo' => 'numero_lacre', 'anterior' => $anterior['numero_lacre'], 'novo' => $numero_lacre);
    }
    if ($anterior['ativo'] != $ativo) {
        $campos_alterados[] = array('campo' => 'ativo', 'anterior' => $anterior['ativo'], 'novo' => $ativo);
    }
    $inventario_anterior = $anterior['inventario_id'] ?? null;
    if ($inventario_anterior != $inventario_id) {
        $campos_alterados[] = array('campo' => 'inventario_id', 'anterior' => $inventario_anterior ?? '', 'novo' => $inventario_id ?? '');
    }
    
    // Inserir histórico
    foreach ($campos_alterados as $campo) {
        $stmt = $conexao->prepare("INSERT INTO hidrometros_historico (hidrometro_id, campo_alterado, valor_anterior, valor_novo, observacao) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $id, $campo['campo'], $campo['anterior'], $campo['novo'], $observacao);
        $stmt->execute();
        $stmt->close();
    }
    
    // Atualizar hidrômetro
    if ($inventario_id !== null) {
        $stmt = $conexao->prepare("UPDATE hidrometros SET morador_id = ?, unidade = ?, numero_hidrometro = ?, numero_lacre = ?, data_instalacao = ?, ativo = ?, inventario_id = ? WHERE id = ?");
        $stmt->bind_param("issssiii", $morador_id, $unidade, $numero_hidrometro, $numero_lacre, $data_instalacao, $ativo, $inventario_id, $id);
    } else {
        $stmt = $conexao->prepare("UPDATE hidrometros SET morador_id = ?, unidade = ?, numero_hidrometro = ?, numero_lacre = ?, data_instalacao = ?, ativo = ?, inventario_id = NULL WHERE id = ?");
        $stmt->bind_param("issssii", $morador_id, $unidade, $numero_hidrometro, $numero_lacre, $data_instalacao, $ativo, $id);
    }
    
    if ($stmt->execute()) {
        registrar_log($conexao, 'INFO', "Hidrômetro atualizado: $numero_hidrometro (ID: $id) - Motivo: $observacao");
        retornar_json(true, "Hidrômetro atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar hidrômetro: " . $stmt->error);
    }
    
    $stmt->close();
}

// Endpoint de histórico movido para antes do GET genérico (acima)

fechar_conexao($conexao);
