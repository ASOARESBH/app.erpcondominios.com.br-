<?php
declare(strict_types=1);

namespace ERP\Database;

use RuntimeException;

final class DatabaseDriverSelector
{
    public static function resolve(mixed $configured): string
    {
        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return 'mysql';
        }
        if (!is_string($configured)) {
            throw new RuntimeException('Driver de banco inválido.');
        }

        $driver = strtolower(trim($configured));
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Driver de banco inválido.');
        }

        return $driver;
    }
}
