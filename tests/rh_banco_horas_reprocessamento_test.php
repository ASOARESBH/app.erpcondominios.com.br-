<?php

declare(strict_types=1);

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}

final class RhPeriodResultFake
{
    public function fetch_all(int $mode): array
    {
        return [];
    }
}

final class RhPeriodStatementFake
{
    public string $types = '';
    public array $params = [];

    public function bind_param(string $types, &...$values): bool
    {
        $this->types = $types;
        foreach ($values as $value) {
            $this->params[] = $value;
        }
        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    public function get_result(): RhPeriodResultFake
    {
        return new RhPeriodResultFake();
    }

    public function close(): void
    {
    }
}

if (!class_exists('mysqli', false)) {
    class mysqli
    {
        public string $lastSql = '';
        public ?RhPeriodStatementFake $lastStatement = null;

        public function prepare(string $sql): RhPeriodStatementFake
        {
            $this->lastSql = $sql;
            return $this->lastStatement = new RhPeriodStatementFake();
        }
    }
}

require_once __DIR__ . '/../api/helpers/rh_banco_horas_reprocessamento.php';

$cases = [
    '2026-11-17' => 202611,
    '2024-02-29' => 202402,
    '2025-12-01' => 202512,
];
foreach ($cases as $date => $expected) {
    if (bh_reprocess_mes_chave($date) !== $expected) {
        fwrite(STDERR, "FAIL: chave ano-mês\n");
        exit(1);
    }
}

foreach (['2025-02-29', '2026-13-01', '20261117'] as $invalid) {
    try {
        bh_reprocess_mes_chave($invalid);
        fwrite(STDERR, "FAIL: data inválida aceita\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}

$conn = new mysqli();
$rows = bh_reprocess_periodos($conn, 7, 23, '2026-11-17', '2027-02-03', true);
if ($rows !== [] || !$conn->lastStatement || $conn->lastStatement->types !== 'iiii'
    || $conn->lastStatement->params !== [7, 23, 202702, 202611]
    || !str_contains($conn->lastSql, 'p.mes BETWEEN 1 AND 12')
    || !str_contains($conn->lastSql, '(p.ano*100+p.mes)<=?')
    || !str_contains($conn->lastSql, '(p.ano*100+p.mes)>=?')
    || !str_ends_with($conn->lastSql, 'FOR UPDATE')
    || preg_match('/STR_TO_DATE|LAST_DAY|LOCK IN SHARE MODE/i', $conn->lastSql)) {
    fwrite(STDERR, "FAIL: query de períodos RH não corresponde ao contrato portável\n");
    exit(1);
}

$checks = count($cases) + 3 + 1;
echo "PASS: {$checks} verificações de chave e consulta ano-mês RH\n";
