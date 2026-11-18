<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/helpers/ged_unidade_order.php';

$cases = [
    [['nome' => 'Bloco B 10', 'bloco' => 'B'], 10],
    [['nome' => 'Bloco B 02', 'bloco' => 'B'], 2],
    [['nome' => 'Bloco ADMIN 1', 'bloco' => 'ADMIN'], 9999],
    [['nome' => 'Bloco admin 1', 'bloco' => 'admin'], 9999],
    [['nome' => 'Bloco ADMIN 1', 'bloco' => 'ADMIN '], 9999],
    [['nome' => 'Bloco B sem-numero', 'bloco' => 'B'], 0],
    [['nome' => 'Torre 2B', 'bloco' => 'B'], 2],
    [['nome' => 'Unidade ' . PHP_INT_MAX, 'bloco' => 'B'], PHP_INT_MAX],
    [['nome' => 'Unidade 999999999999999999999999', 'bloco' => 'B'], PHP_INT_MAX],
];

$assertions = 0;
foreach ($cases as [$row, $expected]) {
    if (erpGedUnidadeNumeroOrdenacao($row) !== $expected) {
        fwrite(STDERR, "FAIL: numeric ordering key mismatch\n");
        exit(1);
    }
    $assertions++;
}

$input = [
    ['nome' => 'Torre 10', 'bloco' => 'A', 'id' => 10],
    ['nome' => 'Torre 2', 'bloco' => 'A', 'id' => 2],
    ['nome' => 'Administrativo', 'bloco' => 'admin', 'id' => 99],
    ['nome' => 'Torre 02', 'bloco' => 'A', 'id' => 20],
    ['nome' => 'Sem número', 'bloco' => 'A', 'id' => 30],
];
$ordered = erpGedOrdenarUnidades($input);
$ids = array_column($ordered, 'id');
if ($ids !== [30, 2, 20, 10, 99]) {
    fwrite(STDERR, "FAIL: unit order mismatch\n");
    exit(1);
}
$assertions++;

$equal_key_order = erpGedOrdenarUnidades([
    ['nome' => 'Torre 02', 'bloco' => 'A', 'id' => 1],
    ['nome' => 'Torre 2', 'bloco' => 'A', 'id' => 2],
]);
if (array_column($equal_key_order, 'id') !== [1, 2]) {
    fwrite(STDERR, "FAIL: stable tie order mismatch\n");
    exit(1);
}
$assertions++;

echo "PASS: {$assertions} GED unit ordering assertions\n";
