<?php
require_once __DIR__ . '/../api/helpers/os_numbering.php';

function os_numbering_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

os_numbering_assert(os_sequencia_numero_anual([], 2026) === 1, 'sequência inicial');
os_numbering_assert(
    os_sequencia_numero_anual([
        ['numero' => 'O.S-2026-0001'],
        ['numero' => 'OS-2026-0008'],
        ['numero' => 'OS-2025-9000'],
        ['numero' => 'inválido'],
        ['numero' => 'OS-2026-X'],
    ], 2026) === 9,
    'máximo anual combina formatos O.S. e Portal sem contar anos ou sufixos inválidos'
);
os_numbering_assert(os_formatar_numero_anual(2026, 9, 'O.S-') === 'O.S-2026-0009', 'formato interno');
os_numbering_assert(os_formatar_numero_anual(2026, 9, 'OS-') === 'OS-2026-0009', 'formato Portal');

try {
    os_formatar_numero_anual(2026, 1, 'outro-');
    os_numbering_assert(false, 'prefixo fora da allowlist deve falhar');
} catch (InvalidArgumentException $e) {
    os_numbering_assert($e->getMessage() === 'OS_NUMBER_ARGUMENT_INVALID', 'erro de prefixo sanitizado');
}

fwrite(STDOUT, "PASS: sequência anual global de O.S.\n");
