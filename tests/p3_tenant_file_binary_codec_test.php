<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/db/PdoMysqliConnection.php';
require_once __DIR__ . '/../api/helpers/tenant_file_binary_codec.php';

use ERP\Database\PdoMysqliConnection;

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fake_connection_for_driver(string $driver): PdoMysqliConnection
{
    $reflection = new ReflectionClass(PdoMysqliConnection::class);
    /** @var PdoMysqliConnection $connection */
    $connection = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('selectedDriver');
    $property->setAccessible(true);
    $property->setValue($connection, $driver);
    return $connection;
}

$mysql = fake_connection_for_driver('mysql');
$pgsql = fake_connection_for_driver('pgsql');
$original = "\x00\xffERP-file\x80\x00";
$sha = hash('sha256', $original);
$size = strlen($original);

$mysqlStored = erp_tenant_file_encode_for_storage($mysql, $original);
test_assert($mysqlStored === $original, 'MySQL fallback changed the original bytes.');
test_assert(erp_tenant_file_decode_from_storage($mysql, $mysqlStored, $size, $sha) === $original, 'MySQL fallback round-trip failed.');

$pgsqlStored = erp_tenant_file_encode_for_storage($pgsql, $original);
test_assert($pgsqlStored === base64_encode($original), 'PostgreSQL storage is not standard Base64.');
test_assert(preg_match('/\A[A-Za-z0-9+\/=]*\z/D', $pgsqlStored) === 1, 'Encoded payload is not ASCII Base64.');
test_assert(erp_tenant_file_decode_from_storage($pgsql, $pgsqlStored, $size, $sha) === $original, 'PostgreSQL Base64 round-trip failed.');

test_assert(
    hash('sha256', erp_tenant_file_decode_from_storage($pgsql, $pgsqlStored, $size)) === $sha,
    'Decoded SHA-256 differs from original.'
);

$cases = [
    static fn() => erp_tenant_file_decode_from_storage($pgsql, '%%%bad%%%'),
    static fn() => erp_tenant_file_decode_from_storage($pgsql, $pgsqlStored, $size + 1),
    static fn() => erp_tenant_file_decode_from_storage($pgsql, $pgsqlStored, $size, str_repeat('0', 64)),
    static fn() => erp_tenant_file_decode_from_storage($pgsql, $pgsqlStored . 'A'),
];
foreach ($cases as $index => $case) {
    try {
        $case();
        throw new RuntimeException('Malformed payload or integrity mismatch was accepted: case ' . $index);
    } catch (UnexpectedValueException $expected) {
        // Expected: strict Base64, size and hash validation fail closed.
    }
}

echo "PASS codec MySQL/PG round-trip, size/hash validation, malformed Base64 rejection\n";
