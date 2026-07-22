<?php
require_once 'config.php';

echo "=== TESTE DE DADOS ===\n\n";

// Buscar unidades
$stmt = $conn->prepare("SELECT * FROM unidades LIMIT 5");
$stmt->execute();
$unidades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "UNIDADES:\n";
foreach ($unidades as $u) {
    echo "ID: {$u['id']}, Nome: '{$u['nome']}'\n";
}

echo "\n";

// Buscar moradores
$stmt = $conn->prepare("SELECT id, nome, unidade FROM moradores LIMIT 10");
$stmt->execute();
$moradores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "MORADORES:\n";
foreach ($moradores as $m) {
    echo "ID: {$m['id']}, Nome: '{$m['nome']}', Unidade: '{$m['unidade']}'\n";
}
