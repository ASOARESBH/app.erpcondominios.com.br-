<?php

declare(strict_types=1);

function os_adquirir_lock_numeracao(mysqli $db, int $ano): bool
{
    $nome = 'erp-os-num-' . $ano;
    $sql = \ERP\Database\DatabaseDialect::isPostgres($db)
        ? 'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS adquirido'
        : 'SELECT GET_LOCK(?, 5) AS adquirido';
    $stmt = $db->prepare($sql);
    if (!$stmt || !$stmt->bind_param('s', $nome) || !$stmt->execute()) return false;
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return in_array(strtolower((string)($row['adquirido'] ?? '')), ['1', 't', 'true'], true);
}

function os_liberar_lock_numeracao(mysqli $db, int $ano): bool
{
    $nome = 'erp-os-num-' . $ano;
    $sql = \ERP\Database\DatabaseDialect::isPostgres($db)
        ? 'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS liberado'
        : 'SELECT RELEASE_LOCK(?) AS liberado';
    $stmt = $db->prepare($sql);
    if (!$stmt || !$stmt->bind_param('s', $nome) || !$stmt->execute()) return false;
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return in_array(strtolower((string)($row['liberado'] ?? '')), ['1', 't', 'true'], true);
}

function os_sequencia_numero_anual(array $numeros, int $ano): int
{
    $maior = 0;
    foreach ($numeros as $numero) {
        if (is_array($numero)) $numero = $numero['numero'] ?? '';
        if (!is_string($numero) || !preg_match('/^.+-' . preg_quote((string)$ano, '/') . '-([0-9]+)$/D', $numero, $match)) continue;
        $maior = max($maior, (int)$match[1]);
    }
    return $maior + 1;
}

function os_formatar_numero_anual(int $ano, int $sequencia, string $prefixo): string
{
    if ($ano < 2000 || $sequencia < 1 || !in_array($prefixo, ['O.S-', 'OS-'], true)) {
        throw new InvalidArgumentException('OS_NUMBER_ARGUMENT_INVALID');
    }
    return $prefixo . $ano . '-' . str_pad((string)$sequencia, 4, '0', STR_PAD_LEFT);
}
