<?php
/**
 * =====================================================
 * API: DEPENDENTES DO PORTAL DO MORADOR
 * =====================================================
 * 
 * Gerencia dependentes do morador logado no portal.
 * Autenticação via token Bearer (sessoes_portal) ou sessão PHP.
 * 
 * Endpoints:
 *   GET    ?action=listar       → Lista dependentes do morador
 *   POST   ?action=criar        → Cadastra novo dependente
 *   PUT    ?action=atualizar&id=X → Atualiza dependente
 *   DELETE ?action=excluir&id=X → Exclui dependente
 * 
 * @author Sistema ERP Serra da Liberdade
 * @version 1.0
 * @date 2026-03-22
 */

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

// Função JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conexao = conectar_banco();
$metodo  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';

// ─── Autenticação: Token Bearer OU Sessão PHP ─────────────────────────────
function autenticar_morador_portal($conexao) {
    // 1. Tentar via token Bearer
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
    }

    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
        $stmt  = $conexao->prepare(
            "SELECT morador_id FROM sessoes_portal
             WHERE token = ? AND ativo = 1 AND data_expiracao > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $stmt->close();
            return (int)$row['morador_id'];
        }
        $stmt->close();
    }

    // 2. Tentar via sessão PHP
    if (isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
        return (int)$_SESSION['morador_id'];
    }

    http_response_code(401);
    retornar_json(false, 'Sessão inválida. Faça login novamente.');
}

$morador_id = autenticar_morador_portal($conexao);

// ─── Verificar/criar tabela dependentes ───────────────────────────────────
$chk = $conexao->query("SHOW TABLES LIKE 'dependentes'");
if (!$chk || $chk->num_rows === 0) {
    $sql_create = "CREATE TABLE IF NOT EXISTS `dependentes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `morador_id` int(11) NOT NULL,
        `nome_completo` varchar(200) NOT NULL,
        `cpf` varchar(14) DEFAULT NULL,
        `parentesco` varchar(50) DEFAULT NULL,
        `data_nascimento` date DEFAULT NULL,
        `email` varchar(200) DEFAULT NULL,
        `telefone` varchar(20) DEFAULT NULL,
        `celular` varchar(20) DEFAULT NULL,
        `observacao` text DEFAULT NULL,
        `ativo` tinyint(1) DEFAULT 1,
        `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `morador_id` (`morador_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conexao->query($sql_create);
}

// ─── GET: Listar dependentes ───────────────────────────────────────────────
if ($metodo === 'GET' && $action === 'listar') {
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo, cpf, parentesco,
                DATE_FORMAT(data_nascimento, '%d/%m/%Y') as data_nascimento,
                email, telefone, celular, observacao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
         FROM dependentes
         WHERE morador_id = ?
         ORDER BY nome_completo ASC"
    );
    $stmt->bind_param('i', $morador_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $dependentes = [];
    while ($row = $res->fetch_assoc()) {
        $dependentes[] = $row;
    }
    $stmt->close();
    fechar_conexao($conexao);

    retornar_json(true, 'Dependentes carregados', $dependentes);
}

// ─── POST: Criar dependente ────────────────────────────────────────────────
if ($metodo === 'POST' && $action === 'criar') {
    $dados = json_decode(file_get_contents('php://input'), true);
    if (!$dados) $dados = $_POST;

    $nome_completo   = trim($dados['nome_completo'] ?? '');
    $cpf             = preg_replace('/[^0-9]/', '', $dados['cpf'] ?? '');
    $parentesco      = trim($dados['parentesco'] ?? '');
    $data_nascimento = trim($dados['data_nascimento'] ?? '');
    $email           = trim($dados['email'] ?? '');
    $telefone        = trim($dados['telefone'] ?? '');
    $celular         = trim($dados['celular'] ?? '');
    $observacao      = trim($dados['observacao'] ?? '');

    if (empty($nome_completo)) {
        retornar_json(false, 'O nome completo é obrigatório');
    }

    // Verificar CPF duplicado (se informado)
    if (!empty($cpf)) {
        $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE cpf = ? AND id != 0");
        $stmt->bind_param('s', $cpf);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, 'CPF já cadastrado no sistema');
        }
        $stmt->close();
    }

    // Formatar CPF para salvar
    $cpf_fmt = '';
    if (strlen($cpf) === 11) {
        $cpf_fmt = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    } else {
        $cpf_fmt = $dados['cpf'] ?? '';
    }

    // Data nascimento
    $dn = null;
    if (!empty($data_nascimento)) {
        // Aceita dd/mm/yyyy ou yyyy-mm-dd
        if (strpos($data_nascimento, '/') !== false) {
            $partes = explode('/', $data_nascimento);
            if (count($partes) === 3) {
                $dn = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
            }
        } else {
            $dn = $data_nascimento;
        }
    }

    $stmt = $conexao->prepare(
        "INSERT INTO dependentes (morador_id, nome_completo, cpf, parentesco, data_nascimento, email, telefone, celular, observacao, ativo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->bind_param('issssssss', $morador_id, $nome_completo, $cpf_fmt, $parentesco, $dn, $email, $telefone, $celular, $observacao);

    if ($stmt->execute()) {
        $id = $conexao->insert_id;
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_CRIADO', "Dependente criado: {$nome_completo} (Morador ID: {$morador_id})", $nome_completo);
        retornar_json(true, 'Dependente cadastrado com sucesso', ['id' => $id]);
    } else {
        $erro = $stmt->error;
        $stmt->close();
        fechar_conexao($conexao);
        error_log('[api_portal_dependentes] Erro ao inserir: ' . $erro);
        retornar_json(false, 'Erro ao cadastrar dependente');
    }
}

// ─── PUT: Atualizar dependente ─────────────────────────────────────────────
if ($metodo === 'PUT' && $action === 'atualizar') {
    $id    = (int)($_GET['id'] ?? 0);
    $dados = json_decode(file_get_contents('php://input'), true);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    // Verificar se o dependente pertence ao morador
    $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE id = ? AND morador_id = ?");
    $stmt->bind_param('ii', $id, $morador_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Dependente não encontrado');
    }
    $stmt->close();

    $nome_completo   = trim($dados['nome_completo'] ?? '');
    $parentesco      = trim($dados['parentesco'] ?? '');
    $data_nascimento = trim($dados['data_nascimento'] ?? '');
    $email           = trim($dados['email'] ?? '');
    $telefone        = trim($dados['telefone'] ?? '');
    $celular         = trim($dados['celular'] ?? '');
    $observacao      = trim($dados['observacao'] ?? '');

    if (empty($nome_completo)) {
        retornar_json(false, 'O nome completo é obrigatório');
    }

    // Data nascimento
    $dn = null;
    if (!empty($data_nascimento)) {
        if (strpos($data_nascimento, '/') !== false) {
            $partes = explode('/', $data_nascimento);
            if (count($partes) === 3) {
                $dn = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
            }
        } else {
            $dn = $data_nascimento;
        }
    }

    $stmt = $conexao->prepare(
        "UPDATE dependentes SET nome_completo = ?, parentesco = ?, data_nascimento = ?,
         email = ?, telefone = ?, celular = ?, observacao = ?
         WHERE id = ? AND morador_id = ?"
    );
    $stmt->bind_param('sssssssii', $nome_completo, $parentesco, $dn, $email, $telefone, $celular, $observacao, $id, $morador_id);

    if ($stmt->execute()) {
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_ATUALIZADO', "Dependente atualizado ID: {$id}", $nome_completo);
        retornar_json(true, 'Dependente atualizado com sucesso');
    } else {
        $erro = $stmt->error;
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao atualizar dependente');
    }
}

// ─── DELETE: Excluir dependente ────────────────────────────────────────────
if ($metodo === 'DELETE' && $action === 'excluir') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    // Verificar se pertence ao morador
    $stmt = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE id = ? AND morador_id = ?");
    $stmt->bind_param('ii', $id, $morador_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Dependente não encontrado');
    }
    $dep = $res->fetch_assoc();
    $stmt->close();

    $stmt = $conexao->prepare("DELETE FROM dependentes WHERE id = ? AND morador_id = ?");
    $stmt->bind_param('ii', $id, $morador_id);

    if ($stmt->execute()) {
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_EXCLUIDO', "Dependente excluído ID: {$id} - {$dep['nome_completo']}", $dep['nome_completo']);
        retornar_json(true, 'Dependente excluído com sucesso');
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Erro ao excluir dependente');
    }
}

fechar_conexao($conexao);
retornar_json(false, "Ação '{$action}' não encontrada ou método incorreto");
?>
