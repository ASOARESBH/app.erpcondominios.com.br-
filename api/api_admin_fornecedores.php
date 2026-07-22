<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_clean();

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

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function resposta($sucesso, $mensagem, $dados = null) {
    if (ob_get_length()) ob_clean();
    $response = array(
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    verificarAutenticacao(true, 'admin');
$tenant_id = exigirTenantId();
    verificarPermissao('admin');
    
    $conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conexao) {
        resposta(false, 'Erro ao conectar ao banco de dados');
    }
    mysqli_set_charset($conexao, 'utf8mb4');
    
    $acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
    
    if (empty($acao)) {
        resposta(false, 'Acao nao especificada');
    }
    
    switch ($acao) {
        case 'listar_todos':
            listarTodos($conexao);
            break;
        case 'alternar_status':
            alternarStatus($conexao);
            break;
        case 'alternar_aprovacao':
            alternarAprovacao($conexao);
            break;
        case 'estatisticas':
            obterEstatisticas($conexao);
            break;
        case 'cadastrar':
            cadastrarFornecedor($conexao);
            break;
        case 'atualizar':
            atualizarFornecedor($conexao);
            break;
        case 'buscar':
            buscarFornecedor($conexao);
            break;
        case 'deletar':
            deletarFornecedor($conexao);
            break;
        default:
            resposta(false, 'Acao invalida: ' . $acao);
    }
    
    mysqli_close($conexao);
    
} catch (Exception $e) {
    error_log('Erro em api_admin_fornecedores.php: ' . $e->getMessage());
    http_response_code(500);
    resposta(false, 'Erro ao processar requisicao: ' . $e->getMessage());
}

function listarTodos($conexao) {
    $sql = 'SELECT f.id, f.cpf_cnpj, f.nome_estabelecimento, f.nome_responsavel, f.ramo_atividade_id, r.nome as ramo_atividade, r.icone as ramo_icone, f.endereco, f.telefone, f.email, f.logo, f.descricao_negocio, f.horario_funcionamento, f.ativo, f.aprovado, DATE_FORMAT(f.data_cadastro, \'%d/%m/%Y %H:%i\') as data_cadastro, DATE_FORMAT(f.data_atualizacao, \'%d/%m/%Y %H:%i\') as data_atualizacao, DATE_FORMAT(f.ultimo_acesso, \'%d/%m/%Y %H:%i\') as ultimo_acesso FROM fornecedores f LEFT JOIN ramos_atividade r ON f.ramo_atividade_id = r.id ORDER BY f.data_cadastro DESC';
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar fornecedores: ' . mysqli_error($conexao));
    }
    
    $fornecedores = array();
    while ($row = mysqli_fetch_assoc($resultado)) {
        $fornecedores[] = $row;
    }
    
    resposta(true, 'Fornecedores listados com sucesso', $fornecedores);
}

function alternarStatus($conexao) {
    // Suporta FormData ($_POST) e JSON (php://input)
    if (!empty($_POST['id'])) {
        $id = intval($_POST['id']);
    } else {
        $dados = json_decode(file_get_contents('php://input'), true);
        if (!is_array($dados) || !isset($dados['id'])) {
            resposta(false, 'Dados invalidos');
        }
        $id = intval($dados['id']);
    }
    
    if ($id <= 0) {
        resposta(false, 'ID invalido');
    }
    
    $stmt = mysqli_prepare($conexao, 'SELECT ativo, nome_estabelecimento FROM fornecedores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Fornecedor nao encontrado');
    }
    
    $fornecedor = mysqli_fetch_assoc($resultado);
    $novo_status = $fornecedor['ativo'] == 1 ? 0 : 1;
    mysqli_stmt_close($stmt);
    
    $stmt = mysqli_prepare($conexao, 'UPDATE fornecedores SET ativo = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $novo_status, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        $mensagem = $novo_status == 1 ? 'Fornecedor ativado!' : 'Fornecedor desativado!';
        resposta(true, $mensagem, array('id' => $id, 'novo_status' => $novo_status));
    } else {
        resposta(false, 'Erro ao alterar status: ' . mysqli_error($conexao));
    }
    
    mysqli_stmt_close($stmt);
}

function alternarAprovacao($conexao) {
    // Suporta FormData ($_POST) e JSON (php://input)
    if (!empty($_POST['id'])) {
        $id = intval($_POST['id']);
    } else {
        $dados = json_decode(file_get_contents('php://input'), true);
        if (!is_array($dados) || !isset($dados['id'])) {
            resposta(false, 'Dados invalidos');
        }
        $id = intval($dados['id']);
    }
    
    if ($id <= 0) {
        resposta(false, 'ID invalido');
    }
    
    $stmt = mysqli_prepare($conexao, 'SELECT aprovado, nome_estabelecimento FROM fornecedores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($resultado) == 0) {
        resposta(false, 'Fornecedor nao encontrado');
    }
    
    $fornecedor = mysqli_fetch_assoc($resultado);
    $novo_status = $fornecedor['aprovado'] == 1 ? 0 : 1;
    mysqli_stmt_close($stmt);
    
    $stmt = mysqli_prepare($conexao, 'UPDATE fornecedores SET aprovado = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $novo_status, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        $mensagem = $novo_status == 1 ? 'Fornecedor aprovado!' : 'Aprovacao removida!';
        resposta(true, $mensagem, array('id' => $id, 'novo_status' => $novo_status));
    } else {
        resposta(false, 'Erro ao alterar aprovacao: ' . mysqli_error($conexao));
    }
    
    mysqli_stmt_close($stmt);
}

// ─── CADASTRAR FORNECEDOR (pelo admin) ────────────────────────────────────────
function cadastrarFornecedor($conexao) {
    $cpf_cnpj             = trim($_POST['cpf_cnpj'] ?? '');
    $nome_estabelecimento = trim($_POST['nome_estabelecimento'] ?? '');
    $nome_responsavel     = trim($_POST['nome_responsavel'] ?? '');
    $ramo_atividade_id    = intval($_POST['ramo_atividade_id'] ?? 0);
    $email                = trim($_POST['email'] ?? '');
    $senha                = trim($_POST['senha'] ?? '');
    $telefone             = trim($_POST['telefone'] ?? '');
    $endereco             = trim($_POST['endereco'] ?? '');
    $ativo                = intval($_POST['ativo'] ?? 1);
    $aprovado             = intval($_POST['aprovado'] ?? 0);

    if (empty($cpf_cnpj))             { resposta(false, 'CPF/CNPJ e obrigatorio.'); }
    if (empty($nome_estabelecimento)) { resposta(false, 'Nome do estabelecimento e obrigatorio.'); }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { resposta(false, 'E-mail valido e obrigatorio.'); }
    if (strlen($senha) < 6)           { resposta(false, 'Senha deve ter no minimo 6 caracteres.'); }
    if ($ramo_atividade_id <= 0)      { resposta(false, 'Ramo de atividade e obrigatorio.'); }

    $stmt = mysqli_prepare($conexao, 'SELECT id FROM fornecedores WHERE cpf_cnpj = ?');
    mysqli_stmt_bind_param($stmt, 's', $cpf_cnpj);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) { mysqli_stmt_close($stmt); resposta(false, 'CPF/CNPJ ja cadastrado.'); }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conexao, 'SELECT id FROM fornecedores WHERE email = ?');
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) { mysqli_stmt_close($stmt); resposta(false, 'E-mail ja cadastrado.'); }
    mysqli_stmt_close($stmt);

    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    $sql = 'INSERT INTO fornecedores (cpf_cnpj, nome_estabelecimento, nome_responsavel, ramo_atividade_id, email, senha, telefone, endereco, ativo, aprovado, data_cadastro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $stmt = mysqli_prepare($conexao, $sql);
    if (!$stmt) { resposta(false, 'Erro ao preparar cadastro: ' . mysqli_error($conexao)); }
    mysqli_stmt_bind_param($stmt, 'sssissssii',
        $cpf_cnpj, $nome_estabelecimento, $nome_responsavel,
        $ramo_atividade_id, $email, $senha_hash,
        $telefone, $endereco, $ativo, $aprovado
    );
    if (mysqli_stmt_execute($stmt)) {
        $novo_id = mysqli_insert_id($conexao);
        registrar_log('FORNECEDOR_CADASTRO_ADMIN', 'Admin cadastrou fornecedor: ' . $nome_estabelecimento . ' (' . $email . ')');
        resposta(true, 'Fornecedor cadastrado com sucesso!', array('id' => $novo_id));
    } else {
        resposta(false, 'Erro ao cadastrar: ' . mysqli_error($conexao));
    }
    mysqli_stmt_close($stmt);
}

// ─── ATUALIZAR FORNECEDOR ─────────────────────────────────────────────────────
function atualizarFornecedor($conexao) {
    $id                   = intval($_POST['id'] ?? 0);
    $cpf_cnpj             = trim($_POST['cpf_cnpj'] ?? '');
    $nome_estabelecimento = trim($_POST['nome_estabelecimento'] ?? '');
    $nome_responsavel     = trim($_POST['nome_responsavel'] ?? '');
    $ramo_atividade_id    = intval($_POST['ramo_atividade_id'] ?? 0);
    $email                = trim($_POST['email'] ?? '');
    $senha                = trim($_POST['senha'] ?? '');
    $telefone             = trim($_POST['telefone'] ?? '');
    $endereco             = trim($_POST['endereco'] ?? '');
    $ativo                = intval($_POST['ativo'] ?? 1);
    $aprovado             = intval($_POST['aprovado'] ?? 0);

    if ($id <= 0)                     { resposta(false, 'ID invalido.'); }
    if (empty($nome_estabelecimento)) { resposta(false, 'Nome do estabelecimento e obrigatorio.'); }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { resposta(false, 'E-mail valido e obrigatorio.'); }

    $stmt = mysqli_prepare($conexao, 'SELECT id FROM fornecedores WHERE email = ? AND id != ?');
    mysqli_stmt_bind_param($stmt, 'si', $email, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) { mysqli_stmt_close($stmt); resposta(false, 'E-mail ja em uso por outro fornecedor.'); }
    mysqli_stmt_close($stmt);

    if (!empty($senha)) {
        if (strlen($senha) < 6) { resposta(false, 'Nova senha deve ter no minimo 6 caracteres.'); }
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $sql = 'UPDATE fornecedores SET cpf_cnpj=?, nome_estabelecimento=?, nome_responsavel=?, ramo_atividade_id=?, email=?, senha=?, telefone=?, endereco=?, ativo=?, aprovado=?, data_atualizacao=NOW() WHERE id=?';
        $stmt = mysqli_prepare($conexao, $sql);
        mysqli_stmt_bind_param($stmt, 'sssissssiii',
            $cpf_cnpj, $nome_estabelecimento, $nome_responsavel,
            $ramo_atividade_id, $email, $senha_hash,
            $telefone, $endereco, $ativo, $aprovado, $id
        );
    } else {
        $sql = 'UPDATE fornecedores SET cpf_cnpj=?, nome_estabelecimento=?, nome_responsavel=?, ramo_atividade_id=?, email=?, telefone=?, endereco=?, ativo=?, aprovado=?, data_atualizacao=NOW() WHERE id=?';
        $stmt = mysqli_prepare($conexao, $sql);
        mysqli_stmt_bind_param($stmt, 'sssisssiii',
            $cpf_cnpj, $nome_estabelecimento, $nome_responsavel,
            $ramo_atividade_id, $email,
            $telefone, $endereco, $ativo, $aprovado, $id
        );
    }
    if (!$stmt) { resposta(false, 'Erro ao preparar atualizacao: ' . mysqli_error($conexao)); }
    if (mysqli_stmt_execute($stmt)) {
        registrar_log('FORNECEDOR_ATUALIZADO_ADMIN', 'Admin atualizou fornecedor ID ' . $id);
        resposta(true, 'Fornecedor atualizado com sucesso!');
    } else {
        resposta(false, 'Erro ao atualizar: ' . mysqli_error($conexao));
    }
    mysqli_stmt_close($stmt);
}

// ─── BUSCAR FORNECEDOR POR ID ─────────────────────────────────────────────────
function buscarFornecedor($conexao) {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { resposta(false, 'ID invalido.'); }
    $sql = 'SELECT f.*, r.nome as ramo_nome FROM fornecedores f LEFT JOIN ramos_atividade r ON f.ramo_atividade_id = r.id WHERE f.id = ?';
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $forn = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if ($forn) {
        unset($forn['senha']);
        resposta(true, 'Fornecedor encontrado.', $forn);
    } else {
        resposta(false, 'Fornecedor nao encontrado.');
    }
}

// ─── DELETAR (soft delete) ────────────────────────────────────────────────────
function deletarFornecedor($conexao) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { resposta(false, 'ID invalido.'); }
    $stmt = mysqli_prepare($conexao, 'UPDATE fornecedores SET ativo=0, data_atualizacao=NOW() WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (mysqli_stmt_execute($stmt)) {
        registrar_log('FORNECEDOR_DELETADO_ADMIN', 'Admin desativou fornecedor ID ' . $id);
        resposta(true, 'Fornecedor desativado com sucesso.');
    } else {
        resposta(false, 'Erro ao desativar: ' . mysqli_error($conexao));
    }
    mysqli_stmt_close($stmt);
}

// ─── ESTATÍSTICAS ─────────────────────────────────────────────────────────────
function obterEstatisticas($conexao) {
    $sql = 'SELECT COUNT(*) as total_fornecedores, SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as fornecedores_ativos, SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) as fornecedores_inativos, SUM(CASE WHEN aprovado = 1 THEN 1 ELSE 0 END) as fornecedores_aprovados, SUM(CASE WHEN aprovado = 0 THEN 1 ELSE 0 END) as fornecedores_pendentes FROM fornecedores';
    
    $resultado = mysqli_query($conexao, $sql);
    
    if (!$resultado) {
        resposta(false, 'Erro ao buscar estatisticas: ' . mysqli_error($conexao));
    }
    
    $stats = mysqli_fetch_assoc($resultado);
    
    $stats['total_fornecedores'] = intval($stats['total_fornecedores']);
    $stats['fornecedores_ativos'] = intval($stats['fornecedores_ativos']);
    $stats['fornecedores_inativos'] = intval($stats['fornecedores_inativos']);
    $stats['fornecedores_aprovados'] = intval($stats['fornecedores_aprovados']);
    $stats['fornecedores_pendentes'] = intval($stats['fornecedores_pendentes']);
    
    resposta(true, 'Estatisticas carregadas', $stats);
}
?>