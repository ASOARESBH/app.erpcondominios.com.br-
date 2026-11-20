<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/DatabaseDialect.php';

if (!function_exists('erp_tenant_file_encode_for_storage')) {
    /** Encode somente na fronteira do campo tenant_arquivos.conteudo. */
    function erp_tenant_file_encode_for_storage(mixed $connection, string $bytes): string
    {
        $driver = \ERP\Database\DatabaseDialect::driver($connection);
        if ($driver === 'mysql') {
            return $bytes;
        }
        if ($driver === 'pgsql') {
            return base64_encode($bytes);
        }
        throw new RuntimeException('Driver de arquivo não suportado.');
    }
}

if (!function_exists('erp_tenant_file_decode_from_storage')) {
    /** Retorna sempre o binário original; valida tamanho e SHA-256 quando disponíveis. */
    function erp_tenant_file_decode_from_storage(
        mixed $connection,
        string $stored,
        int|string|null $expectedSize = null,
        ?string $expectedSha256 = null
    ): string {
        $driver = \ERP\Database\DatabaseDialect::driver($connection);
        if ($driver === 'mysql') {
            $bytes = $stored;
        } elseif ($driver === 'pgsql') {
            $decoded = base64_decode($stored, true);
            if (!is_string($decoded) || base64_encode($decoded) !== $stored) {
                throw new UnexpectedValueException('Conteúdo Base64 de arquivo inválido.');
            }
            $bytes = $decoded;
        } else {
            throw new RuntimeException('Driver de arquivo não suportado.');
        }

        if ($expectedSize !== null) {
            if (!is_int($expectedSize) && !(is_string($expectedSize) && preg_match('/\A[0-9]+\z/D', $expectedSize))) {
                throw new UnexpectedValueException('Tamanho de arquivo inválido.');
            }
            if ((int)$expectedSize !== strlen($bytes)) {
                throw new UnexpectedValueException('Tamanho de arquivo divergente.');
            }
        }
        if ($expectedSha256 !== null && $expectedSha256 !== '') {
            if (preg_match('/\A[a-f0-9]{64}\z/iD', $expectedSha256) !== 1
                || !hash_equals(strtolower($expectedSha256), hash('sha256', $bytes))) {
                throw new UnexpectedValueException('Hash de arquivo divergente.');
            }
        }
        return $bytes;
    }
}
