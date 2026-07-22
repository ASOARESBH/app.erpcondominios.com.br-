<?php
/**
 * API COMPLETA DO PORTAL DO MORADOR
 * Endpoints: perfil, visitantes, hidrometro
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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conexao = conectar_banco();
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ========== FUNÇÃO PARA OBTER TOKEN ==========
function obter_token_portal() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

// ========== FUNÇÃO PARA VERIFICAR SESSÃO ==========
function verificar_sessao_portal($conexao, $token) {
    if (!$token) {
        return false;
    }
    
    $stmt = $conexao->prepare("
        SELECT morador_id 
        FROM sessoes_portal 
        WHERE token = ? AND data_expiracao > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $sessao = $resultado->fetch_assoc();
        return $sessao['morador_id'];
    }
    
    return false;
}

// ========== MIDDLEWARE: VERIFICAR AUTENTICAÇÃO ==========
$token = obter_token_portal();
$morador_id = verificar_sessao_portal($conexao, $token);

if (!$morador_id && $action !== 'login') {
    http_response_code(401);
    retornar_json(false, "Não autorizado. Faça login novamente.");
}

// ========================================
// PERFIL DO MORADOR
// ========================================

// GET: Obter dados do perfil
if ($action === 'perfil' && $metodo === 'GET') {
    $stmt = $conexao->prepare("
        SELECT id, nome, cpf, unidade, email, telefone, celular, ativo, ultimo_acesso
        FROM moradores 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $morador = $resultado->fetch_assoc();
        retornar_json(true, "Perfil obtido com sucesso", $morador);
    } else {
        retornar_json(false, "Morador não encontrado");
    }
}

// PUT: Atualizar telefone e/ou senha
if ($action === 'perfil' && $metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $telefone = $dados['telefone'] ?? null;
    $celular = $dados['celular'] ?? null;
    $senha_atual = $dados['senha_atual'] ?? null;
    $senha_nova = $dados['senha_nova'] ?? null;
    
    // Atualizar telefone/celular
    if ($telefone !== null || $celular !== null) {
        $campos = [];
        $tipos = "";
        $valores = [];
        
        if ($telefone !== null) {
            $campos[] = "telefone = ?";
            $tipos .= "s";
            $valores[] = $telefone;
        }
        
        if ($celular !== null) {
            $campos[] = "celular = ?";
            $tipos .= "s";
            $valores[] = $celular;
        }
        
        $valores[] = $morador_id;
        $tipos .= "i";
        
        $sql = "UPDATE moradores SET " . implode(", ", $campos) . " WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$valores);
        
        if ($stmt->execute()) {
            registrar_log('PERFIL_ATUALIZADO', "Morador atualizou telefone/celular", "Morador ID: {$morador_id}");
            retornar_json(true, "Telefone atualizado com sucesso");
        } else {
            retornar_json(false, "Erro ao atualizar telefone");
        }
    }
    
    // Atualizar senha
    if ($senha_atual && $senha_nova) {
        // Buscar senha atual do morador
        $stmt = $conexao->prepare("SELECT senha FROM moradores WHERE id = ?");
        $stmt->bind_param("i", $morador_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $morador = $resultado->fetch_assoc();
        
        // Verificar senha atual
        $senha_valida = false;
        
        if (password_verify($senha_atual, $morador['senha'])) {
            $senha_valida = true;
        } elseif (strlen($morador['senha']) === 40 && sha1($senha_atual) === $morador['senha']) {
            $senha_valida = true;
        }
        
        if (!$senha_valida) {
            retornar_json(false, "Senha atual incorreta");
        }
        
        // Atualizar para nova senha e limpar flag de senha temporária
        $senha_hash = password_hash($senha_nova, PASSWORD_DEFAULT);
        $stmt = $conexao->prepare(
            "UPDATE moradores SET senha = ?, senha_temporaria = 0, data_atualizacao = NOW() WHERE id = ?"
        );
        $stmt->bind_param("si", $senha_hash, $morador_id);

        if ($stmt->execute()) {
            // Marcar como utilizada no log de recuperação (se houver registro pendente)
            try {
                $stmt_util = $conexao->prepare(
                    "UPDATE senha_recuperacao_logs
                     SET utilizada = 1, data_utilizacao = NOW()
                     WHERE morador_id = ? AND utilizada = 0"
                );
                $stmt_util->bind_param('i', $morador_id);
                $stmt_util->execute();
                $stmt_util->close();
            } catch (Exception $e) {
                // Tabela pode não existir em instalações sem o módulo de recuperação
            }

            registrar_log('SENHA_TEMPORARIA_TROCADA', "Morador trocou senha temporária", "Morador ID: {$morador_id}");
            retornar_json(true, "Senha alterada com sucesso");
        } else {
            retornar_json(false, "Erro ao alterar senha");
        }
    }
}

// ========================================
// VISITANTES
// ========================================

// GET: Listar visitantes do morador
if ($action === 'visitantes' && $metodo === 'GET') {
    // Verificar se existe campo morador_id na tabela visitantes
    $colunas = $conexao->query("SHOW COLUMNS FROM visitantes LIKE 'morador_id'");
    
    if ($colunas->num_rows > 0) {
        // Tabela tem campo morador_id
        $stmt = $conexao->prepare("
            SELECT id, nome_completo, documento, tipo_documento, telefone, celular, 
                   email, observacao, ativo, data_cadastro
            FROM visitantes 
            WHERE morador_id = ?
            ORDER BY data_cadastro DESC
        ");
        $stmt->bind_param("i", $morador_id);
    } else {
        // Tabela não tem campo morador_id (retornar vazio)
        retornar_json(true, "Nenhum visitante cadastrado", []);
        exit;
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();
    $visitantes = [];
    
    while ($row = $resultado->fetch_assoc()) {
        $visitantes[] = $row;
    }
    
    retornar_json(true, "Visitantes obtidos com sucesso", $visitantes);
}

// POST: Cadastrar novo visitante
if ($action === 'visitantes' && $metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome_completo = $dados['nome_completo'] ?? '';
    $documento = $dados['documento'] ?? '';
    $tipo_documento = $dados['tipo_documento'] ?? 'CPF';
    $telefone = $dados['telefone'] ?? '';
    $celular = $dados['celular'] ?? '';
    $email = $dados['email'] ?? '';
    $observacao = $dados['observacao'] ?? '';
    
    if (empty($nome_completo) || empty($documento)) {
        retornar_json(false, "Nome e documento são obrigatórios");
    }
    
    // Verificar se existe campo morador_id
    $colunas = $conexao->query("SHOW COLUMNS FROM visitantes LIKE 'morador_id'");
    
    if ($colunas->num_rows > 0) {
        // Tabela tem campo morador_id
        $stmt = $conexao->prepare("
            INSERT INTO visitantes (morador_id, nome_completo, documento, tipo_documento, telefone, celular, email, observacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssss", $morador_id, $nome_completo, $documento, $tipo_documento, $telefone, $celular, $email, $observacao);
    } else {
        // Tabela não tem campo morador_id (adicionar coluna primeiro)
        retornar_json(false, "Tabela visitantes precisa ser atualizada. Contate o administrador.");
        exit;
    }
    
    if ($stmt->execute()) {
        $visitante_id = $conexao->insert_id;
        registrar_log('VISITANTE_CADASTRADO', "Morador cadastrou visitante: {$nome_completo}", "Morador ID: {$morador_id}");
        retornar_json(true, "Visitante cadastrado com sucesso", ['id' => $visitante_id]);
    } else {
        retornar_json(false, "Erro ao cadastrar visitante");
    }
}

// DELETE: Excluir visitante
if ($action === 'visitantes' && $metodo === 'DELETE') {
    $visitante_id = $_GET['id'] ?? 0;
    
    if (!$visitante_id) {
        retornar_json(false, "ID do visitante não fornecido");
    }
    
    // Verificar se o visitante pertence ao morador
    $stmt = $conexao->prepare("SELECT nome_completo FROM visitantes WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $visitante_id, $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Visitante não encontrado ou não pertence a você");
    }
    
    $visitante = $resultado->fetch_assoc();
    
    // Excluir visitante
    $stmt = $conexao->prepare("DELETE FROM visitantes WHERE id = ? AND morador_id = ?");
    $stmt->bind_param("ii", $visitante_id, $morador_id);
    
    if ($stmt->execute()) {
        registrar_log('VISITANTE_EXCLUIDO', "Morador excluiu visitante: {$visitante['nome_completo']}", "Morador ID: {$morador_id}");
        retornar_json(true, "Visitante excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir visitante");
    }
}

// ========================================
// HIDRÔMETRO
// ========================================

// GET: Obter hidrômetro e histórico de leituras
if ($action === 'hidrometro' && $metodo === 'GET') {
    // Buscar hidrômetro do morador
    $stmt = $conexao->prepare("
        SELECT id, numero_hidrometro, numero_lacre, data_instalacao, ativo
        FROM hidrometros 
        WHERE morador_id = ? AND ativo = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        retornar_json(false, "Hidrômetro não encontrado para sua unidade");
    }
    
    $hidrometro = $resultado->fetch_assoc();
    $hidrometro_id = $hidrometro['id'];
    
    // Buscar histórico de leituras
    $stmt = $conexao->prepare("
        SELECT id, leitura_anterior, leitura_atual, consumo, 
               valor_metro_cubico, valor_minimo, valor_total, 
               data_leitura, observacao
        FROM leituras 
        WHERE hidrometro_id = ?
        ORDER BY data_leitura DESC
        LIMIT 12
    ");
    $stmt->bind_param("i", $hidrometro_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $leituras = [];
    while ($row = $resultado->fetch_assoc()) {
        $leituras[] = $row;
    }
    
    retornar_json(true, "Dados do hidrômetro obtidos com sucesso", [
        'hidrometro' => $hidrometro,
        'leituras' => $leituras
    ]);
}

// ========================================
// VEÍCULOS DO MORADOR (somente leitura)
// ========================================
if ($action === 'veiculos' && $metodo === 'GET') {
    $stmt = $conexao->prepare("
        SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.ativo,
               v.dependente_id,
               d.nome_completo AS dependente_nome,
               DATE_FORMAT(v.data_cadastro, '%d/%m/%Y') AS data_cadastro
        FROM veiculos v
        LEFT JOIN dependentes d ON v.dependente_id = d.id
        WHERE v.morador_id = ?
        ORDER BY v.ativo DESC, v.data_cadastro DESC
    ");
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar consulta de veículos: ' . $conexao->error);
    }
    $stmt->bind_param('i', $morador_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $veiculos = [];
    while ($row = $resultado->fetch_assoc()) {
        $veiculos[] = $row;
    }
    $stmt->close();
    retornar_json(true, 'Veículos obtidos com sucesso.', ['veiculos' => $veiculos]);
}

// ========================================
// AÇÃO NÃO ENCONTRADA
// ========================================
http_response_code(404);
retornar_json(false, "Ação não encontrada: {$action}");
