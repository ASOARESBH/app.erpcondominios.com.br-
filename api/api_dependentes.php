<?php
/**
 * =====================================================
 * API PARA CRUD DE DEPENDENTES - VERSÃO CORRIGIDA
 * =====================================================
 * 
 * Baseada na estrutura de api_moradores.php
 * Correções:
 * 1. Sem dependência de Controller/Model complexo
 * 2. Queries diretas com prepared statements
 * 3. Sempre retorna JSON válido
 * 4. Logging de erros
 * 5. Tratamento robusto de exceções
 */

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'error_logger.php';

// Limpar buffer e definir headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
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
$tenant_id = exigirTenantId();
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    $conexao = conectar_banco();
    
    if (!$conexao) {
        throw new Exception("Erro ao conectar ao banco de dados");
    }
    
    // Para operações de escrita, verificar permissão de admin
    if ($metodo !== 'GET') {
        verificarPermissao('admin');
    }
    
    // ========== OBTER DEPENDENTE ESPECÍFICO ==========
    if ($metodo === 'GET' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        
        if ($id <= 0) {
            $errorLogger->registrarAviso("ID inválido fornecido: $id");
            retornar_json(false, "ID inválido");
        }
        
        try {
            $stmt = $conexao->prepare("SELECT d.id, d.morador_id, d.nome_completo, d.cpf, d.email, d.telefone, d.celular, d.parentesco, d.observacao, d.ativo, m.nome as morador_nome FROM dependentes d LEFT JOIN moradores m ON d.morador_id = m.id WHERE d.id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param("i", $id);
            
            if (!$stmt->execute()) {
                throw new Exception("Erro ao executar query: " . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            
            if ($resultado && $resultado->num_rows > 0) {
                $dependente = $resultado->fetch_assoc();
                $stmt->close();
                $errorLogger->registrarInfo("Dependente obtido", array('id' => $id, 'nome' => $dependente['nome_completo']));
                retornar_json(true, "Dependente obtido com sucesso", $dependente);
            } else {
                $stmt->close();
                $errorLogger->registrarAviso("Dependente nao encontrado com ID: $id");
                retornar_json(false, "Dependente não encontrado");
            }
        } catch (Exception $e) {
            $errorLogger->registrarErroAPI('obter', $e->getMessage(), array('id' => $id), $e);
            throw $e;
        }
    }
    
    // ========== LISTAR DEPENDENTES ==========
    if ($metodo === 'GET') {
        // Obter filtros de busca
        $filtro_morador = isset($_GET['morador_id']) ? trim($_GET['morador_id']) : '';
        $filtro_nome    = isset($_GET['nome'])       ? trim($_GET['nome'])       : '';
        $filtro_cpf     = isset($_GET['cpf'])        ? trim($_GET['cpf'])        : '';
        $filtro_busca   = isset($_GET['busca'])      ? trim($_GET['busca'])      : '';

        // Paginação
        $por_pagina = isset($_GET['por_pagina']) ? max(1, min(200, (int)$_GET['por_pagina'])) : 25;
        $pagina     = isset($_GET['pagina'])     ? max(1, (int)$_GET['pagina'])               : 1;
        $sem_paginacao = ($por_pagina === 0 || isset($_GET['sem_paginacao']));
        if ($sem_paginacao) $por_pagina = 99999;
        $offset = ($pagina - 1) * $por_pagina;

        // Base da query de filtros (reutilizada para COUNT e SELECT)
        $sql_where = " FROM dependentes d
                LEFT JOIN moradores m ON d.morador_id = m.id
                WHERE 1=1";

        $tipos_param = "";
        $params = array();

        // Busca unificada: nome, CPF, morador ou unidade
        if ($filtro_busca !== '') {
            $cpf_busca = preg_replace('/[^0-9]/', '', $filtro_busca);
            if ($cpf_busca !== '' && strlen($cpf_busca) >= 3) {
                $sql_where .= " AND (d.nome_completo LIKE ? OR REPLACE(REPLACE(REPLACE(d.cpf, '.', ''), '-', ''), ' ', '') LIKE ? OR m.nome LIKE ? OR m.unidade LIKE ?)";
                $tipos_param .= "ssss";
                $params[] = "%" . $filtro_busca . "%";
                $params[] = "%" . $cpf_busca . "%";
                $params[] = "%" . $filtro_busca . "%";
                $params[] = "%" . $filtro_busca . "%";
            } else {
                $sql_where .= " AND (d.nome_completo LIKE ? OR m.nome LIKE ? OR m.unidade LIKE ?)";
                $tipos_param .= "sss";
                $params[] = "%" . $filtro_busca . "%";
                $params[] = "%" . $filtro_busca . "%";
                $params[] = "%" . $filtro_busca . "%";
            }
        }

        // Filtros individuais (retrocompatibilidade)
        if ($filtro_morador) {
            $sql_where .= " AND d.morador_id = ?";
            $tipos_param .= "i";
            $params[] = (int)$filtro_morador;
        }
        if ($filtro_nome) {
            $sql_where .= " AND d.nome_completo LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $filtro_nome . "%";
        }
        if ($filtro_cpf) {
            $cpf_limpo = preg_replace('/[^0-9]/', '', $filtro_cpf);
            $sql_where .= " AND REPLACE(REPLACE(REPLACE(d.cpf, '.', ''), '-', ''), ' ', '') LIKE ?";
            $tipos_param .= "s";
            $params[] = "%" . $cpf_limpo . "%";
        }

        // ── Contar total ──────────────────────────────────────
        $sql_count = "SELECT COUNT(*) as total" . $sql_where;
        $stmt_c = $conexao->prepare($sql_count);
        if (!$stmt_c) throw new Exception("Erro ao preparar COUNT: " . $conexao->error);
        if (count($params) > 0) $stmt_c->bind_param($tipos_param, ...$params);
        if (!$stmt_c->execute()) throw new Exception("Erro ao executar COUNT: " . $stmt_c->error);
        $res_c = $stmt_c->get_result();
        $total = $res_c ? (int)$res_c->fetch_assoc()['total'] : 0;
        $stmt_c->close();

        // ── Buscar registros paginados ─────────────────────────
        $sql = "SELECT d.id, d.morador_id, d.nome_completo, d.cpf, d.email, d.telefone, d.celular,
                d.parentesco, d.ativo,
                DATE_FORMAT(d.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro,
                m.nome as morador_nome, m.unidade as morador_unidade"
             . $sql_where
             . " ORDER BY d.nome_completo ASC LIMIT ? OFFSET ?";

        $params_pag   = $params;
        $tipos_pag    = $tipos_param . "ii";
        $params_pag[] = $por_pagina;
        $params_pag[] = $offset;

        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new Exception("Erro ao preparar query: " . $conexao->error);
        if (count($params_pag) > 0) $stmt->bind_param($tipos_pag, ...$params_pag);
        if (!$stmt->execute()) throw new Exception("Erro ao executar query: " . $stmt->error);

        $resultado   = $stmt->get_result();
        $dependentes = array();
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) $dependentes[] = $row;
        }
        $stmt->close();

        $total_paginas = $sem_paginacao ? 1 : (int)ceil($total / $por_pagina);

        $errorLogger->registrarInfo("Dependentes listados", array('total' => $total, 'pagina' => $pagina));
        retornar_json(true, "Dependentes listados com sucesso", [
            'dados'          => $dependentes,
            'total'          => $total,
            'pagina'         => $pagina,
            'por_pagina'     => $por_pagina,
            'total_paginas'  => $total_paginas,
        ]);
    }
    
    // ========== CRIAR DEPENDENTE ==========
    if ($metodo === 'POST') {
        $errorLogger->registrarInfo('Iniciando criacao de dependente', array('metodo' => 'POST'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $morador_id = isset($dados['morador_id']) ? (int)$dados['morador_id'] : 0;
        $nome_completo = sanitizar($conexao, $dados['nome_completo'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        $data_nascimento = sanitizar($conexao, $dados['data_nascimento'] ?? '');
        $parentesco = sanitizar($conexao, $dados['parentesco'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        
        // Validações
        if ($morador_id <= 0 || empty($nome_completo) || empty($cpf)) {
            retornar_json(false, "Morador, nome completo e CPF são obrigatórios");
        }
        
        // Verificar se morador existe
        $stmt = $conexao->prepare("SELECT id FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("i", $morador_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows == 0) {
            $stmt->close();
            retornar_json(false, "Morador não encontrado");
        }
        $stmt->close();
        
        // Verificar se CPF já existe
        $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE tenant_id = $tenant_id AND cpf = ?");
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
        
        // Inserir dependente
        $stmt = $conexao->prepare("INSERT INTO dependentes (morador_id, nome_completo, cpf, email, telefone, celular, data_nascimento, parentesco, observacao, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        if (!$stmt) {
            throw new Exception("Erro ao preparar insert: " . $conexao->error);
        }
        $stmt->bind_param("issssssss", $morador_id, $nome_completo, $cpf, $email, $telefone, $celular, $data_nascimento, $parentesco, $observacao);
        
        if ($stmt->execute()) {
            $id_inserido = $conexao->insert_id;
            registrar_log('DEPENDENTE_CRIADO', "Dependente criado: $nome_completo (ID: $id_inserido)", $nome_completo);
            $errorLogger->registrarInfo('Dependente criado com sucesso', array('id' => $id_inserido, 'nome' => $nome_completo, 'cpf' => $cpf, 'morador_id' => $morador_id));
            retornar_json(true, "Dependente cadastrado com sucesso", array('id' => $id_inserido));
        } else {
            $errorLogger->registrarErroAPI('criar', "Erro ao cadastrar dependente: " . $stmt->error, array('nome' => $nome_completo, 'cpf' => $cpf, 'morador_id' => $morador_id));
            throw new Exception("Erro ao cadastrar dependente: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== ATUALIZAR DEPENDENTE ==========
    if ($metodo === 'PUT') {
        $errorLogger->registrarInfo('Iniciando atualizacao de dependente', array('metodo' => 'PUT'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = isset($dados['id']) ? (int)$dados['id'] : 0;
        $nome_completo = sanitizar($conexao, $dados['nome_completo'] ?? '');
        $cpf = sanitizar($conexao, $dados['cpf'] ?? '');
        $email = sanitizar($conexao, $dados['email'] ?? '');
        $telefone = sanitizar($conexao, $dados['telefone'] ?? '');
        $celular = sanitizar($conexao, $dados['celular'] ?? '');
        $data_nascimento = sanitizar($conexao, $dados['data_nascimento'] ?? '');
        $parentesco = sanitizar($conexao, $dados['parentesco'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        
        // Validações
        if ($id <= 0 || empty($nome_completo)) {
            retornar_json(false, "ID e nome completo são obrigatórios");
        }
        
        // Atualizar dependente
        $stmt = $conexao->prepare("UPDATE dependentes SET nome_completo = ?, cpf = ?, email = ?, telefone = ?, celular = ?, data_nascimento = ?, parentesco = ?, observacao = ? WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar update: " . $conexao->error);
        }
        $stmt->bind_param("ssssssssi", $nome_completo, $cpf, $email, $telefone, $celular, $data_nascimento, $parentesco, $observacao, $id);
        
        if ($stmt->execute()) {
            registrar_log('DEPENDENTE_ATUALIZADO', "Dependente atualizado: $nome_completo (ID: $id)", $nome_completo);
            $errorLogger->registrarInfo('Dependente atualizado com sucesso', array('id' => $id, 'nome' => $nome_completo));
            retornar_json(true, "Dependente atualizado com sucesso");
        } else {
            $errorLogger->registrarErroAPI('atualizar', "Erro ao atualizar dependente: " . $stmt->error, array('id' => $id, 'nome' => $nome_completo));
            throw new Exception("Erro ao atualizar dependente: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // ========== DELETAR DEPENDENTE ==========
    if ($metodo === 'DELETE') {
        $errorLogger->registrarInfo('Iniciando delecao de dependente', array('metodo' => 'DELETE'));
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = isset($dados['id']) ? (int)$dados['id'] : 0;
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        // Obter nome do dependente para log
        $stmt = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $dependente = $resultado->fetch_assoc();
        $nome_dependente = $dependente['nome_completo'] ?? 'Desconhecido';
        $stmt->close();
        
        // Excluir dependente
        $stmt = $conexao->prepare("DELETE FROM dependentes WHERE tenant_id = $tenant_id AND id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar delete: " . $conexao->error);
        }
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            registrar_log('DEPENDENTE_EXCLUIDO', "Dependente excluído: $nome_dependente (ID: $id)", $nome_dependente);
            $errorLogger->registrarInfo('Dependente excluido com sucesso', array('id' => $id, 'nome' => $nome_dependente));
            retornar_json(true, "Dependente excluído com sucesso");
        } else {
            $errorLogger->registrarErroAPI('deletar', "Erro ao excluir dependente: " . $stmt->error, array('id' => $id, 'nome' => $nome_dependente));
            throw new Exception("Erro ao excluir dependente: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    // Método não suportado
    retornar_json(false, "Método não suportado");
    
} catch (Exception $e) {
    error_log('Erro em api_dependentes.php: ' . $e->getMessage());
    registrar_log('DEPENDENTE_ERRO', "Erro: " . $e->getMessage(), 'Sistema');
    $errorLogger->registrarErroAPI('geral', $e->getMessage(), array(), $e);
    
    http_response_code(500);
    retornar_json(false, "Erro ao processar requisição: " . $e->getMessage());
    
} finally {
    if (isset($conexao)) {
        fechar_conexao($conexao);
    }
}
?>
