<?php
/**
 * Executar este arquivo UMA VEZ após o deploy para popular o Manual do Sistema.
 * Acesse: https://asl.erpcondominios.com.br/api/run_manual_seed.php?token=SEED_MANUAL_2026
 * APAGUE este arquivo após a execução!
 */

$token_esperado = 'SEED_MANUAL_2026';
$token_recebido = $_GET['token'] ?? '';

if ($token_recebido !== $token_esperado) {
    http_response_code(403);
    die(json_encode(['erro' => 'Acesso negado. Token inválido.']));
}

require_once 'config.php';

$conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conexao->connect_error) {
    die(json_encode(['erro' => 'Erro de conexão: ' . $conexao->connect_error]));
}
$conexao->set_charset("utf8mb4");

// Tentar encontrar o seed_manual.sql em locais possíveis
$possiveis = [
    dirname(__DIR__) . '/seed_manual.sql',
    __DIR__ . '/../seed_manual.sql',
    $_SERVER['DOCUMENT_ROOT'] . '/seed_manual.sql',
];

$sql_file = null;
foreach ($possiveis as $p) {
    if (file_exists($p)) {
        $sql_file = $p;
        break;
    }
}

if (!$sql_file) {
    die(json_encode([
        'erro' => 'Arquivo seed_manual.sql não encontrado.',
        'tentativas' => $possiveis
    ]));
}

$sql = file_get_contents($sql_file);
if (!$sql) {
    die(json_encode(['erro' => 'Não foi possível ler o arquivo SQL.']));
}

$erros = [];
$queries_ok = 0;

if ($conexao->multi_query($sql)) {
    do {
        if ($res = $conexao->store_result()) {
            $res->free();
        }
        $queries_ok++;
        if ($conexao->errno) {
            $erros[] = 'Erro na query ' . $queries_ok . ': ' . $conexao->error;
        }
    } while ($conexao->more_results() && $conexao->next_result());
} else {
    $erros[] = 'Erro ao executar SQL: ' . $conexao->error;
}

// Aguardar conexão liberar
while ($conexao->more_results()) {
    $conexao->next_result();
}

// Contar registros
$counts = [];
foreach (['manual_modulos', 'manual_categorias', 'manual_artigos'] as $tabela) {
    $r = $conexao->query("SELECT COUNT(*) as total FROM $tabela");
    if ($r) {
        $row = $r->fetch_assoc();
        $counts[$tabela] = (int)$row['total'];
    } else {
        $counts[$tabela] = 'erro: ' . $conexao->error;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status'       => empty($erros) ? 'sucesso' : 'parcial',
    'mensagem'     => empty($erros) ? 'Seed executado com sucesso!' : 'Seed executado com alguns erros.',
    'sql_file'     => $sql_file,
    'queries_ok'   => $queries_ok,
    'contagem'     => $counts,
    'erros'        => $erros,
    'aviso'        => 'APAGUE este arquivo (run_manual_seed.php) após a execução!'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
