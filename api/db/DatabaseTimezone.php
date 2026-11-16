<?php
declare(strict_types=1);

namespace ERP\Database;

use RuntimeException;
use DateTimeZone;

final class DatabaseTimezone
{
    public static function resolve(mixed $configured): string
    {
        $isSupportedIdentifier = is_string($configured)
            && in_array($configured, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        $isUtc = $configured === 'UTC';
        $isRegionalZone = is_string($configured)
            && str_contains($configured, '/')
            && !str_starts_with($configured, 'Etc/');

        if (!is_string($configured)
            || $configured === ''
            || trim($configured) !== $configured
            || !$isSupportedIdentifier
            || (!$isUtc && !$isRegionalZone)) {
            throw new RuntimeException('Fuso horário inválido.');
        }
        return $configured;
    }
}
