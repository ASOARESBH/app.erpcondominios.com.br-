<?php
/**
 * API PARA CRUD DE MORADORES - VERSÃO FINAL CORRIGIDA
 * 
 * Correções:
 * 1. Tratamento robusto de erros
 * 2. Sempre retorna JSON válido
 * 3. Prepared statements em todas as queries
 * 4. Validação completa de entrada
 * 5. Logging de erros
 */

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'error_logger.php';

// Limpar buffer e definir headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array(
            'sucesso' => $sucesso,
            'mensagem' => $mensagem
        );
        if ($dados !== null) {
            $resposta['dados'] = $dados;
        }
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // Verificar autenticação
    verificarAutenticacao(true, 'operador');
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    $conexao = conectar_banco();
    
    if (!$conexao) {
        throw new Exception("Erro ao conectar ao banco de dados");
    }
    
    // Para operações de escrita, verificar permissão de admin
    if ($metodo !== 'GET') {
        verificarPermissao('admin');
    }
    
    // ========== OBTER MORADOR ESPECÍFICO ==========
    if ($metodo === 'GET' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        
        if ($id <= 0) {
            $errorLogger->registrarAviso("ID inválido fornecido: $id");
            retornar_json(false, "ID inválido");
        }
        
        try {
            $stmt = $conexao->prepare("SELECT id, nome, cpf, unidade, email, telefone, celular, ativo, observacao, DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro FROM moradores WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("i", $id);
            
            if (!$stmt->execute()) {
                throw new Exception("Erro ao executar query: " . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            
            if ($resultado && $resultado->num_rows > 0) {
                $morador = $resultado->fetch_assoc();
                $stmt->close();
                $errorLogger->registrarInfo("Morador obtido", array('id' => $id, 'nome' => $morador['nome']));
                retornar_json(true, "Morador obtido com sucesso", $morador);
            } else {
                $stmt->close();
                $errorLogger->registrarAviso("Morador nao encontrado com ID: $id");
                retornar_json(false, "Morador não encontrado");
            }
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('obter', $e->getMessage(), array('id' => $id), $e);
            throw $e;
        }
    }
    
    // ========== LISTAR MORADORES ==========
    if ($metodo === 'GET') {
        // Obter filtros de busca
        $filtro_unidade = isset($_GET['unidade']) ? trim($_GET['unidade']) : '';
        $filtro_nome    = isset($_GET['nome'])    ? trim($_GET['nome'])    : '';
        $filtro_email   = isset($_GET['email'])   ? trim($_GET['email'])   : '';
        $filtro_cpf     = isset($_GET['cpf'])     ? trim($_GET['cpf'])     : '';
        // Busca genérica: pesquisa em nome, CPF e unidade simultaneamente (OR)
        $filtro_busca   = isset($_GET['busca'])   ? trim($_GET['busca'])   : '';
        // Filtro ativo: 1=somente ativos, 0=somente inativos, vazio=todos
        $filtro_ativo   = isset($_GET['ativo']) && $_GET['ativo'] !== '' ? trim($_GET['ativo']) : '';

        // Paginação — por_pagina=0 retorna todos (retrocompatibilidade para selects)
        $por_pagina = isset($_GET['por_pagina']) ? max(0, intval($_GET['por_pagina'])) : 25;
        $pagina     = isset($_GET['pagina'])     ? max(1, intval($_GET['pagina']))     : 1;

        // ── Montar cláusula WHERE ──────────────────────────────────────
        $where       = "WHERE 1=1";
        $tipos_param = "";
        $params      = array();

        if ($filtro_unidade) {
            $where .= " AND unidade = ?";
            $tipos_param .= "s";
            $params[] = $filtro_unidade;
        }
        if ($filtro_ativo !== '') {
            $where .= " AND ativo = ?";
            $tipos_param .= "i";
            $params[] = intval($filtro_ativo);
        }
        if ($filtro_busca) {
            // Busca genérica: nome, unidade (número ou "Gleba X") ou CPF
            $like_busca = "%" . $filtro_busca . "%";
            $cpf_busca  = preg_replace('/[^0-9]/', '', $filtro_busca);
            if ($cpf_busca !== '') {
                $where .= " AND (nome LIKE ? OR unidade LIKE ? OR REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') LIKE ?)";
                $tipos_param .= "sss";
                $params[] = $like_busca;
                $params[] = $like_busca;
                $params[] = "%" . $cpf_busca . "%";
            } else {
                $where .= " AND (nome LIKE ? OR unidade LIKE ?)";
                $tipos_param .= "ss";
                $params[] = $like_busca;
                $params[] = $like_busca;
            }
        } elseif ($filtro_nome) {
            $where .= " AND nome LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $filtro_nome . "%";
        }
        if ($filtro_email) {
            $where .= " AND email LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $filtro_email . "%";
        }
        if ($filtro_cpf) {
            $cpf_limpo = preg_replace('/[^0-9]/', '', $filtro_cpf);
            $where .= " AND REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $cpf_limpo . "%";
        }

        // ── Contar total de registros ──────────────────────────────────
        $sql_count = "SELECT COUNT(*) AS total FROM moradores $where";
        $stmt_c = $conexao->prepare($sql_count);
        if (!$stmt_c) throw new Exception("Erro ao preparar contagem: " . $conexao->error);
        if (count($params) > 0) $stmt_c->bind_param($tipos_param, ...$params);
        if (!$stmt_c->execute()) throw new Exception("Erro ao executar contagem: " . $stmt_c->error);
        $res_c = $stmt_c->get_result();
        $total = (int)($res_c->fetch_assoc()['total'] ?? 0);
        $stmt_c->close();

        // ── Montar query principal com LIMIT/OFFSET ────────────────────
        $sql = "SELECT id, nome, cpf, unidade, email, telefone, celular, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') AS data_cadastro
                FROM moradores $where ORDER BY nome ASC";

        $tipos_pag   = $tipos_param;
        $params_pag  = $params;

        if ($por_pagina > 0) {
            $offset      = ($pagina - 1) * $por_pagina;
            $sql        .= " LIMIT ? OFFSET ?";
            $tipos_pag  .= "ii";
            $params_pag[] = $por_pagina;
            $params_pag[] = $offset;
            $total_paginas = (int)ceil($total / $por_pagina);
        } else {
            // Sem paginação (retrocompatibilidade)
            $total_paginas = 1;
            $pagina        = 1;
        }

        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new Exception("Erro ao preparar query: " . $conexao->error);
        if (count($params_pag) > 0) $stmt->bind_param($tipos_pag, ...$params_pag);
        if (!$stmt->execute()) throw new Exception("Erro ao executar query: " . $stmt->error);

        $resultado = $stmt->get_result();
        $moradores = array();
        while ($row = $resultado->fetch_assoc()) {
            $moradores[] = $row;
        }
        $stmt->close();

        // Retorna dados + metadados de paginação
        retornar_json(true, "Moradores listados com sucesso", array(
            'itens'         => $moradores,
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $por_pagina,
            'total_paginas' => $total_paginas,
        ));
    }
    
    // ========== CRIAR MORADOR ==========
    if ($metodo === 'POST') {
        $errorLogger->registrarInfo('Iniciando criacao de morador', array('metodo' => 'POST'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $nome = sanitizar($conexao, $dados['nome'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $unidade = sanitizar($conexao, $dados['unidade'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $senha = $dados['senha'] ?? '';
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        
        // Validações
        if (empty($nome) || empty($cpf) || empty($unidade) || empty($email) || empty($senha)) {
            retornar_json(false, "Todos os campos obrigatórios devem ser preenchidos");
        }
        
        // Verificar se CPF já existe
        $stmt = $conexao->prepare("SELECT id FROM moradores WHERE cpf = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("s", $cpf);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            retornar_json(false, "CPF já cadastrado no sistema");
        }
        $stmt->close();
        
        // Criptografar senha
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        
        // Inserir morador
        $stmt = $conexao->prepare("INSERT INTO moradores (nome, cpf, unidade, email, senha, telefone, celular, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Erro ao preparar insert: " . $conexao->error);
        }
        $stmt->bind_param("ssssssss", $nome, $cpf, $unidade, $email, $senha_hash, $telefone, $celular, $observacao);
        
        if ($stmt->execute()) {
            $id_inserido = $conexao->insert_id;
            registrar_log('MORADOR_CRIADO', "Morador criado: $nome (ID: $id_inserido)", $nome);
            $errorLogger->registrarInfo('Morador criado com sucesso', array('id' => $id_inserido, 'nome' => $nome, 'cpf' => $cpf));
            retornar_json(true, "Morador cadastrado com sucesso", array('id' => $id_inserido));
        } else {
            $errorLogger->registrarErroAPI('criar', "Erro ao cadastrar morador: " . $stmt->error, array('nome' => $nome, 'cpf' => $cpf));
            throw new Exception("Erro ao cadastrar morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== ATUALIZAR MORADOR ==========
    if ($metodo === 'PUT') {
        $errorLogger->registrarInfo('Iniciando atualizacao de morador', array('metodo' => 'PUT'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        $nome = sanitizar($conexao, $dados['nome'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $unidade = sanitizar($conexao, $dados['unidade'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        
        // Validações
        if ($id <= 0 || empty($nome) || empty($cpf) || empty($unidade) || empty($email)) {
            retornar_json(false, "Dados inválidos para atualização");
        }
        
        // Verificar se CPF já existe em outro morador
        $stmt = $conexao->prepare("SELECT id FROM moradores WHERE cpf = ? AND id != ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("si", $cpf, $id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            retornar_json(false, "CPF já cadastrado para outro morador");
        }
        $stmt->close();
        
        // Verificar se a senha foi enviada para atualização
        if (isset($dados['senha']) && !empty($dados['senha'])) {
            $senha = $dados['senha'];
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            
            // Atualizar morador com senha
            $stmt = $conexao->prepare("UPDATE moradores SET nome=?, cpf=?, unidade=?, email=?, telefone=?, celular=?, senha=?, observacao=? WHERE id=?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("ssssssssi", $nome, $cpf, $unidade, $email, $telefone, $celular, $senha_hash, $observacao, $id);
        } else {
            // Atualizar morador sem senha
            $stmt = $conexao->prepare("UPDATE moradores SET nome=?, cpf=?, unidade=?, email=?, telefone=?, celular=?, observacao=? WHERE id=?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            $stmt->bind_param("sssssssi", $nome, $cpf, $unidade, $email, $telefone, $celular, $observacao, $id);
        }
        
        if ($stmt->execute()) {
            registrar_log('MORADOR_ATUALIZADO', "Morador atualizado: $nome (ID: $id)", $nome);
            $errorLogger->registrarInfo('Morador atualizado com sucesso', array('id' => $id, 'nome' => $nome));
            retornar_json(true, "Morador atualizado com sucesso");
        } else {
            $errorLogger->registrarErroAPI('atualizar', "Erro ao atualizar morador: " . $stmt->error, array('id' => $id, 'nome' => $nome));
            throw new Exception("Erro ao atualizar morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== DELETAR MORADOR ==========
    if ($metodo === 'DELETE') {
        $errorLogger->registrarInfo('Iniciando delecao de morador', array('metodo' => 'DELETE'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        // Buscar nome do morador antes de excluir
        $stmt = $conexao->prepare("SELECT nome FROM moradores WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $morador = $resultado->fetch_assoc();
        $nome_morador = $morador['nome'] ?? 'Desconhecido';
        $stmt->close();
        
        // Excluir morador (veículos serão excluídos automaticamente por CASCADE)
        $stmt = $conexao->prepare("DELETE FROM moradores WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar delete: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            registrar_log('MORADOR_EXCLUIDO', "Morador excluído: $nome_morador (ID: $id)", $nome_morador);
            $errorLogger->registrarInfo('Morador excluido com sucesso', array('id' => $id, 'nome' => $nome_morador));
            retornar_json(true, "Morador excluído com sucesso");
        } else {
            $errorLogger->registrarErroAPI('deletar', "Erro ao excluir morador: " . $stmt->error, array('id' => $id, 'nome' => $nome_morador));
            throw new Exception("Erro ao excluir morador: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // Método não suportado
    retornar_json(false, "Método não suportado");
    
} catch (Exception $e) {
    error_log('Erro em api_moradores.php: ' . $e->getMessage());
    registrar_log('MORADOR_ERRO', "Erro: " . $e->getMessage(), 'Sistema');
    $errorLogger->registrarErroAPI('geral', $e->getMessage(), array(), $e);
    
    http_response_code(500);
    retornar_json(false, "Erro ao processar requisição: " . $e->getMessage());
    
} finally {
    if (isset($conexao)) {
        fechar_conexao($conexao);
    }
}
?>
