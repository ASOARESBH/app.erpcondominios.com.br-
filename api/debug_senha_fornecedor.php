<?php
/**
 * SCRIPT DE DIAGNÓSTICO — Verificação de senha do fornecedor
 * REMOVER APÓS USO
 */

// Chave de segurança para evitar acesso não autorizado
$chave = $_GET['chave'] ?? '';
if ($chave !== 'diag_erp_2026') {
    http_response_code(403);
    die(json_encode(['erro' => 'Acesso negado']));
}

require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$email  = $_GET['email']  ?? '';
$senha  = $_GET['senha']  ?? '';

if (empty($email)) {
    die(json_encode(['erro' => 'Informe o email via ?email=...']));
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die(json_encode(['erro' => 'Conexão falhou: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');

    $stmt = $conn->prepare("SELECT id, email, senha, nome_estabelecimento, ativo, aprovado FROM fornecedores WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die(json_encode(['erro' => 'Fornecedor não encontrado com email: ' . $email]));
    }

    $f = $result->fetch_assoc();
    $stmt->close();

    $hash        = $f['senha'] ?? '';
    $hash_info   = password_get_info($hash);
    $hash_prefix = substr($hash, 0, 10);
    $hash_len    = strlen($hash);

    $resultado = [
        'id'                 => $f['id'],
        'email'              => $f['email'],
        'nome'               => $f['nome_estabelecimento'],
        'ativo'              => $f['ativo'],
        'aprovado'           => $f['aprovado'],
        'hash_prefix'        => $hash_prefix,
        'hash_length'        => $hash_len,
        'hash_algoritmo'     => $hash_info['algoName'] ?? 'desconhecido',
        'hash_eh_bcrypt'     => str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$'),
        'hash_eh_md5'        => (strlen($hash) === 32 && ctype_xdigit($hash)),
        'hash_eh_sha1'       => (strlen($hash) === 40 && ctype_xdigit($hash)),
    ];

    // Se senha foi fornecida, testar
    if (!empty($senha)) {
        $resultado['senha_testada']       = $senha;
        $resultado['bcrypt_verify']       = password_verify($senha, $hash);
        $resultado['md5_verify']          = (md5($senha) === $hash);
        $resultado['sha1_verify']         = (sha1($senha) === $hash);
        $resultado['plain_verify']        = ($senha === $hash);
        $resultado['md5_upper_verify']    = (strtoupper(md5($senha)) === strtoupper($hash));

        // Testar variações comuns
        $variacoes = [
            $senha,
            trim($senha),
            strtolower($senha),
            strtoupper($senha),
        ];
        $resultado['variacoes_bcrypt'] = [];
        foreach ($variacoes as $v) {
            $resultado['variacoes_bcrypt'][$v] = password_verify($v, $hash);
        }
    }

    $conn->close();
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
