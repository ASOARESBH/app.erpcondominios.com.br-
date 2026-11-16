<?php
declare(strict_types=1);

namespace ERP\Database;

final class PrivateConfigPath
{
    public static function resolve(?string $legacyFallbackPath = null): ?string
    {
        $configuredPath = getenv('ERP_CONFIG_PATH');
        if ($configuredPath === false) {
            $configuredPath = $legacyFallbackPath ?? (dirname(__DIR__, 3) . '/erpcondominios-private/erp_config.php');
        } elseif (!is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        clearstatcache(true, $configuredPath);
        $realPath = realpath($configuredPath);
        if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $permissions = fileperms($realPath);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            return null;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $hasDocumentRoot = is_string($documentRoot) && trim($documentRoot) !== '';
        if ((PHP_SAPI !== 'cli' || $hasDocumentRoot)
            && !self::isOutsideDocumentRoot($realPath, $hasDocumentRoot ? $documentRoot : null, true)) {
            return null;
        }

        return $realPath;
    }

    private static function isOutsideDocumentRoot(string $configPath, ?string $documentRoot, bool $required): bool
    {
        if (!is_string($documentRoot) || trim($documentRoot) === '') {
            return !$required;
        }
        $realDocumentRoot = realpath($documentRoot);
        if (!is_string($realDocumentRoot)) {
            return false;
        }
        $rootPrefix = rtrim($realDocumentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $configPath !== $realDocumentRoot && !str_starts_with($configPath, $rootPrefix);
    }
}
