<?php
declare(strict_types=1);

namespace ERP\Database;

use PDO;
use PDOException;
use RuntimeException;

final class PgsqlMysqliConnection
{
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int|string $insert_id = 0;

    private ?PDO $pdo;
    private string $driver;

    public function __construct(PDO $pdo)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Driver PDO não suportado pela fachada.');
        }
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function clientInfo(): string
    {
        if ($this->pdo === null) {
            return '';
        }
        try {
            $value = $this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
            return is_string($value) ? $value : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public static function dsnFromConfig(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $database = $config['database'] ?? $config['dbname'] ?? null;
        $port = filter_var($config['port'] ?? 5432, FILTER_VALIDATE_INT);

        $validHostname = is_string($host) && preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D', $host) === 1;
        $validIp = is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$validHostname && !$validIp) {
            throw new RuntimeException('Configuração PostgreSQL inválida.');
        }
        if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1) {
            throw new RuntimeException('Configuração PostgreSQL inválida.');
        }
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('Configuração PostgreSQL inválida.');
        }

        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
    }

    public static function connect(array $config): self
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('O driver PDO PostgreSQL não está instalado.');
        }
        $user = $config['user'] ?? null;
        $password = $config['password'] ?? null;
        if (!is_string($user) || $user === '' || !is_string($password)) {
            throw new RuntimeException('Configuração PostgreSQL inválida.');
        }

        try {
            $pdo = new PDO(self::dsnFromConfig($config), $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET client_encoding TO 'UTF8'");
            $pdo->exec('SET standard_conforming_strings TO on');
        } catch (PDOException) {
            throw new RuntimeException('Não foi possível conectar ao PostgreSQL.');
        }

        return new self($pdo);
    }

    public function prepare(string $query): PgsqlMysqliStatement|false
    {
        if ($this->pdo === null) {
            $this->setError('Conexão encerrada.', 2006);
            return false;
        }

        try {
            $hasInternalInsertId = false;
            if (preg_match('/\A\s*INSERT\b/i', $query) === 1
                && !self::hasReturningClause($query)
                && self::isSingleValuesInsert($query)) {
                [$query, $hasInternalInsertId] = $this->appendInternalInsertIdReturning($query);
            }
            $statement = $this->pdo->prepare($query);
            if ($statement === false) {
                $this->setError('Falha ao preparar a consulta.', 1);
                return false;
            }
            $this->clearError();
            return new PgsqlMysqliStatement($this, $this->pdo, $statement, $query, $hasInternalInsertId);
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function query(string $query, int $resultMode = 0): PgsqlMysqliResult|bool
    {
        $this->affected_rows = 0;
        $this->insert_id = 0;
        $isInsert = preg_match('/\A\s*INSERT\b/i', $query) === 1;
        $hasExplicitReturning = $isInsert && self::hasReturningClause($query);
        if ($resultMode !== 0) {
            $this->setError('Modo de resultado não bufferizado não é suportado.', 1);
            return false;
        }
        if ($this->pdo === null) {
            $this->setError('Conexão encerrada.', 2006);
            return false;
        }
        $hasInternalInsertId = false;
        if ($isInsert && !$hasExplicitReturning && self::isSingleValuesInsert($query)) {
            [$query, $hasInternalInsertId] = $this->appendInternalInsertIdReturning($query);
        }

        try {
            $statement = $this->pdo->query($query);
            if ($statement === false) {
                $this->setError('Falha ao executar a consulta.', 1);
                return false;
            }
            $this->clearError();
            $this->affected_rows = $statement->rowCount();
            if ($statement->columnCount() > 0) {
                $columnCount = $statement->columnCount();
                $rows = $statement->fetchAll(PDO::FETCH_BOTH);
                if ($hasExplicitReturning || $hasInternalInsertId) {
                    $this->insert_id = self::returnedInsertId($rows);
                }
                if ($hasInternalInsertId) {
                    return true;
                }
                return new PgsqlMysqliResult($rows, $columnCount);
            }
            return true;
        } catch (PDOException $exception) {
            $this->affected_rows = 0;
            $this->insert_id = 0;
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if ($this->pdo === null || $flags !== 0 || $name !== null) {
            $this->setError($this->pdo === null ? 'Conexão encerrada.' : 'Opções de transação não suportadas.', $this->pdo === null ? 2006 : 1);
            return false;
        }
        try {
            $result = $this->pdo->beginTransaction();
            $this->clearError();
            return $result;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        if ($this->pdo === null || $flags !== 0 || $name !== null) {
            $this->setError($this->pdo === null ? 'Conexão encerrada.' : 'Opções de transação não suportadas.', $this->pdo === null ? 2006 : 1);
            return false;
        }
        try {
            $result = $this->pdo->commit();
            $this->clearError();
            return $result;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        if ($this->pdo === null || $flags !== 0 || $name !== null) {
            $this->setError($this->pdo === null ? 'Conexão encerrada.' : 'Opções de transação não suportadas.', $this->pdo === null ? 2006 : 1);
            return false;
        }
        try {
            $result = $this->pdo->rollBack();
            $this->clearError();
            return $result;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function set_charset(string $charset): bool
    {
        if ($this->pdo === null || !in_array(strtolower($charset), ['utf8', 'utf8mb4'], true)) {
            $this->setError($this->pdo === null ? 'Conexão encerrada.' : 'Charset não suportado.', $this->pdo === null ? 2006 : 2019);
            return false;
        }
        try {
            $this->pdo->exec("SET client_encoding TO 'UTF8'");
            $this->clearError();
            return true;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function real_escape_string(string $value): string|false
    {
        if ($this->pdo === null) {
            $this->setError('Conexão encerrada.', 2006);
            return false;
        }
        try {
            $quoted = $this->pdo->quote($value, PDO::PARAM_STR);
            if (!is_string($quoted) || strlen($quoted) < 2 || $quoted[0] !== "'" || $quoted[strlen($quoted) - 1] !== "'") {
                $this->setError('Falha ao escapar o valor.', 1);
                return false;
            }
            $this->clearError();
            return substr($quoted, 1, -1);
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function ping(): bool
    {
        return $this->query('SELECT 1') !== false;
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->affected_rows = 0;
        $this->insert_id = 0;
    }

    public function isClosed(): bool
    {
        return $this->pdo === null;
    }

    public function recordResult(int $affectedRows, int|string $insertId = 0): void
    {
        $this->affected_rows = $affectedRows;
        $this->insert_id = $insertId;
    }

    private function appendInternalInsertIdReturning(string $query): array
    {
        if ($this->pdo === null) {
            return [$query, false];
        }
        $target = self::insertTarget($query);
        if ($target === null) {
            return [$query, false];
        }
        try {
            $statement = $this->pdo->prepare(
                "WITH target AS ("
                . "SELECT n.nspname, c.relname FROM pg_class c "
                . "JOIN pg_namespace n ON n.oid = c.relnamespace "
                . "WHERE c.oid = to_regclass(CASE WHEN NULLIF(CAST(? AS text), '') IS NULL "
                . "THEN format('%I', CAST(? AS text)) ELSE format('%I.%I', CAST(? AS text), CAST(? AS text)) END) "
                . "AND c.relkind IN ('r', 'p')) "
                . "SELECT kcu.column_name, "
                . "pg_get_serial_sequence(format('%I.%I', tc.table_schema, tc.table_name), kcu.column_name) AS sequence_name "
                . "FROM target "
                . "JOIN information_schema.table_constraints tc "
                . "ON tc.table_schema = target.nspname AND tc.table_name = target.relname "
                . "JOIN information_schema.key_column_usage kcu "
                . "ON kcu.constraint_catalog = tc.constraint_catalog "
                . "AND kcu.constraint_schema = tc.constraint_schema "
                . "AND kcu.constraint_name = tc.constraint_name "
                . "AND kcu.table_schema = tc.table_schema "
                . "AND kcu.table_name = tc.table_name "
                . "WHERE tc.constraint_type = 'PRIMARY KEY' "
                . "AND kcu.ordinal_position = 1 LIMIT 1"
            );
            $schema = $target['schema'] ?? '';
            $statement->execute([$schema, $target['table'], $schema, $target['table']]);
            $primaryKey = $statement->fetch(PDO::FETCH_ASSOC);
            $sequence = is_array($primaryKey) ? ($primaryKey['sequence_name'] ?? null) : null;
            $column = is_array($primaryKey) ? ($primaryKey['column_name'] ?? null) : null;
            if (!is_string($sequence) || $sequence === '' || !is_string($column) || $column === ''
                || !self::insertColumnListOmits($query, $column)) {
                return [$query, false];
            }
            $trimmed = rtrim($query);
            $hasSemicolon = str_ends_with($trimmed, ';');
            if ($hasSemicolon) {
                $trimmed = rtrim(substr($trimmed, 0, -1));
            }
            $quotedColumn = '"' . str_replace('"', '""', $column) . '"';
            return [$trimmed . ' RETURNING ' . $quotedColumn . ' AS insert_id' . ($hasSemicolon ? ';' : ''), true];
        } catch (\Throwable) {
            return [$query, false];
        }
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return $byte !== '' && preg_match('/\A[A-Za-z0-9_$\x80-\xFF]\z/D', $byte) === 1;
    }

    public static function hasReturningClause(string $sql): bool
    {
        return preg_match('/(?<![A-Za-z0-9_$\x80-\xFF])RETURNING(?![A-Za-z0-9_$\x80-\xFF])/i', self::maskSqlLiteralsAndComments($sql)) === 1;
    }

    public static function isSingleValuesInsert(string $sql): bool
    {
        $masked = self::maskSqlLiteralsAndComments($sql);
        $identifier = '(?:"((?:[^"]|"")*)"|([A-Za-z_][A-Za-z0-9_$]*))';
        $targetPattern = '/\A\s*INSERT\s+INTO\s+(?:ONLY\s+)?' . $identifier . '(?:\s*\.\s*' . $identifier . ')?/i';
        if (preg_match($targetPattern, $sql, $target) !== 1) {
            return false;
        }

        $position = strlen($target[0]);
        $position = self::skipSqlWhitespace($masked, $position);
        if (($masked[$position] ?? '') === '(') {
            $columnListEnd = self::parenthesizedExpressionEnd($masked, $position);
            if ($columnListEnd === null) {
                return false;
            }
            $position = self::skipSqlWhitespace($masked, $columnListEnd);
        }
        if (preg_match('/\GVALUES\b/i', $masked, $values, 0, $position) !== 1) {
            return false;
        }
        $position += strlen($values[0]);
        $position = self::skipSqlWhitespace($masked, $position);
        $tupleEnd = self::parenthesizedExpressionEnd($masked, $position);
        if ($tupleEnd === null) {
            return false;
        }

        $suffix = trim(substr($masked, $tupleEnd));
        if ($suffix !== '' && $suffix !== ';') {
            return false;
        }
        return preg_match('/\A\s*;?\s*\z/D', substr($sql, $tupleEnd)) === 1;
    }

    private static function skipSqlWhitespace(string $sql, int $position): int
    {
        return $position + strspn($sql, " \t\n\r\f\v", $position);
    }

    private static function parenthesizedExpressionEnd(string $sql, int $position): ?int
    {
        if (($sql[$position] ?? '') !== '(') {
            return null;
        }
        $depth = 0;
        $length = strlen($sql);
        for (; $position < $length; $position++) {
            if ($sql[$position] === '(') {
                $depth++;
            } elseif ($sql[$position] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position + 1;
                }
            }
        }
        return null;
    }

    private static function maskSqlLiteralsAndComments(string $sql): string
    {
        $masked = $sql;
        $length = strlen($sql);
        $blankRange = static function (int $start, int $end) use (&$masked): void {
            for ($position = $start; $position < $end; $position++) {
                if ($masked[$position] !== "\n" && $masked[$position] !== "\r") {
                    $masked[$position] = ' ';
                }
            }
        };

        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $blankRange($i, $end);
                $i = $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $start = $i;
                $depth = 1;
                $i += 2;
                while ($i < $length && $depth > 0) {
                    if (($sql[$i] ?? '') === '/' && ($sql[$i + 1] ?? '') === '*') {
                        $depth++;
                        $i += 2;
                    } elseif (($sql[$i] ?? '') === '*' && ($sql[$i + 1] ?? '') === '/') {
                        $depth--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
                $blankRange($start, $i);
                continue;
            }

            if ($char === "'") {
                $start = $i;
                $previous = $sql[$i - 1] ?? '';
                $escapeString = ($previous === 'E' || $previous === 'e')
                    && !self::isIdentifierByte($sql[$i - 2] ?? '');
                $i++;
                while ($i < $length) {
                    if ($escapeString && $sql[$i] === '\\') {
                        $i += min(2, $length - $i);
                    } elseif ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") {
                        $i += 2;
                    } elseif ($sql[$i] === "'") {
                        $i++;
                        break;
                    } else {
                        $i++;
                    }
                }
                $blankRange($start, $i);
                continue;
            }

            if ($char === '"') {
                $start = $i++;
                while ($i < $length) {
                    if ($sql[$i] === '"' && ($sql[$i + 1] ?? '') === '"') {
                        $i += 2;
                    } elseif ($sql[$i] === '"') {
                        $i++;
                        break;
                    } else {
                        $i++;
                    }
                }
                $blankRange($start, $i);
                continue;
            }

            if ($char === '$' && !self::isIdentifierByte($sql[$i - 1] ?? '')
                && preg_match('/\G\$(?:[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*)?\$/', $sql, $match, 0, $i) === 1) {
                $start = $i;
                $delimiter = $match[0];
                $contentStart = $i + strlen($delimiter);
                $closing = strpos($sql, $delimiter, $contentStart);
                $i = $closing === false ? $length : $closing + strlen($delimiter);
                $blankRange($start, $i);
                continue;
            }

            $i++;
        }

        return $masked;
    }

    private static function insertTarget(string $query): ?array
    {
        $identifier = '(?:"((?:[^"]|"")*)"|([A-Za-z_][A-Za-z0-9_$]*))';
        $pattern = '/\A\s*INSERT\s+INTO\s+(?:ONLY\s+)?' . $identifier . '(?:\s*\.\s*' . $identifier . ')?/i';
        if (preg_match($pattern, $query, $matches) !== 1) {
            return null;
        }
        $first = ($matches[1] ?? '') !== '' ? str_replace('""', '"', $matches[1]) : strtolower($matches[2] ?? '');
        $hasSchema = array_key_exists(3, $matches) && (($matches[3] ?? '') !== '' || ($matches[4] ?? '') !== '');
        if (!$hasSchema) {
            return $first !== '' ? ['table' => $first] : null;
        }
        $table = ($matches[3] ?? '') !== '' ? str_replace('""', '"', $matches[3]) : strtolower($matches[4] ?? '');
        return $first !== '' && $table !== '' ? ['schema' => $first, 'table' => $table] : null;
    }

    private static function insertColumnListOmits(string $query, string $primaryKey): bool
    {
        $identifier = '(?:"((?:[^"]|"")*)"|([A-Za-z_][A-Za-z0-9_$]*))';
        $target = '/\A\s*INSERT\s+INTO\s+(?:ONLY\s+)?(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)(?:\s*\.\s*(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*))?/i';
        if (preg_match($target, $query, $match) !== 1) {
            return false;
        }
        $position = self::skipSqlWhitespace($query, strlen($match[0]));
        if (($query[$position] ?? '') !== '(') {
            return false;
        }
        $masked = self::maskSqlLiteralsAndComments($query);
        $end = self::parenthesizedExpressionEnd($masked, $position);
        if ($end === null) {
            return false;
        }
        $columns = substr($query, $position + 1, $end - $position - 2);
        foreach (explode(',', $columns) as $candidate) {
            if (preg_match('/\A\s*' . $identifier . '\s*\z/D', $candidate, $columnMatch) !== 1) {
                return false;
            }
            $name = ($columnMatch[1] ?? '') !== ''
                ? str_replace('""', '"', $columnMatch[1])
                : strtolower($columnMatch[2] ?? '');
            if ($name === $primaryKey) {
                return false;
            }
        }
        return true;
    }

    public function setError(string $message, int $errno): void
    {
        $this->error = $message;
        $this->errno = $errno;
    }

    public function clearError(): void
    {
        $this->error = '';
        $this->errno = 0;
    }

    private function setErrorFromException(PDOException $exception): void
    {
        $info = $exception->errorInfo ?? [];
        $sqlState = is_array($info) && isset($info[0]) ? (string)$info[0] : (string)$exception->getCode();
        $this->setError('Falha na consulta PostgreSQL (SQLSTATE ' . $sqlState . ').', self::mysqlErrnoForSqlState($sqlState));
    }

    public static function mysqlErrnoForSqlState(string $sqlState): int
    {
        return match ($sqlState) {
            '23505' => 1062,
            '42P01' => 1146,
            '42703' => 1054,
            '23503' => 1452,
            '23502' => 1048,
            '22001' => 1406,
            '22007', '22008' => 1292,
            default => 1,
        };
    }

    public static function returnedInsertId(array $rows): int|string
    {
        if (!isset($rows[0]) || !is_array($rows[0])) {
            return 0;
        }
        $value = $rows[0]['insert_id'] ?? $rows[0]['id'] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }
        $digits = (string)$value;
        if (preg_match('/\A[0-9]+\z/D', $digits) !== 1 || $digits === '0') {
            return 0;
        }
        $insertId = filter_var($digits, FILTER_VALIDATE_INT);
        return $insertId === false ? $digits : $insertId;
    }
}
