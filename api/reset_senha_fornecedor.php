<?php
/**
 * SCRIPT DE RESET DE SENHA DO FORNECEDOR
 * =====================================================
 * Uso: ?chave=reset_erp_2026&email=...&nova_senha=...
 * REMOVER DO SERVIDOR APÓS O USO!
 * =====================================================
 */

$chave = $_GET['chave'] ?? '';
if ($chave !== 'reset_erp_2026') {
    http_response_code(403);
    die(json_encode(['erro' => 'Acesso negado']));
}

require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$email      = trim($_GET['email']      ?? '');
$nova_senha = trim($_GET['nova_senha'] ?? '');

if (empty($email) || empty($nova_senha)) {
    die(json_encode(['erro' => 'Informe ?email=...&nova_senha=...']));
}

if (strlen($nova_senha) < 6) {
    die(json_encode(['erro' => 'Senha deve ter ao menos 6 caracteres']));
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die(json_encode(['erro' => 'Conexão falhou: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');

    // Verificar se fornecedor existe
    $stmt = $conn->prepare("SELECT id, email, nome_estabelecimento, ativo, aprovado FROM fornecedores WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die(json_encode(['erro' => 'Fornecedor não encontrado: ' . $email]));
    }

    $f = $result->fetch_assoc();
    $stmt->close();

    // Gerar novo hash bcrypt
    $hash = password_hash($nova_senha, PASSWORD_BCRYPT, ['cost' => 12]);

    // Verificar que o hash funciona
    $verificacao = password_verify($nova_senha, $hash);
    if (!$verificacao) {
        die(json_encode(['erro' => 'Falha ao gerar hash — verificação falhou']));
    }

    // Atualizar no banco
    $stmt2 = $conn->prepare("UPDATE fornecedores SET senha = ? WHERE email = ? LIMIT 1");
    $stmt2->bind_param('ss', $hash, $email);
    $stmt2->execute();
    $affected = $stmt2->affected_rows;
    $stmt2->close();
    $conn->close();

    echo json_encode([
        'sucesso'          => true,
        'mensagem'         => 'Senha atualizada com sucesso!',
        'fornecedor_id'    => $f['id'],
        'email'            => $f['email'],
        'nome'             => $f['nome_estabelecimento'],
        'ativo'            => $f['ativo'],
        'aprovado'         => $f['aprovado'],
        'linhas_afetadas'  => $affected,
        'hash_prefix'      => substr($hash, 0, 10),
        'hash_algoritmo'   => password_get_info($hash)['algoName'],
        'verificacao_ok'   => $verificacao,
        'aviso'            => 'REMOVA ESTE ARQUIVO DO SERVIDOR APÓS O USO!',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
