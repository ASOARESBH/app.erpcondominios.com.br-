<?php
require_once 'config.php';
$conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conexao->set_charset("utf8mb4");
$sql = file_get_contents('../seed_manual.sql');
if ($conexao->multi_query($sql)) {
    do {
        if ($res = $conexao->store_result()) { $res->free(); }
    } while ($conexao->more_results() && $conexao->next_result());
    echo "Seed executado com sucesso!";
} else {
    echo "Erro: " . $conexao->error;
}
?>
