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
    public int $insert_id = 0;

    private ?PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
            $statement = $this->pdo->prepare($query);
            if ($statement === false) {
                $this->setError('Falha ao preparar a consulta.', 1);
                return false;
            }
            $this->clearError();
            return new PgsqlMysqliStatement($this, $this->pdo, $statement, $query);
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function query(string $query, int $resultMode = 0): PgsqlMysqliResult|bool
    {
        $this->affected_rows = 0;
        $this->insert_id = 0;
        if ($resultMode !== 0) {
            $this->setError('Modo de resultado não bufferizado não é suportado.', 1);
            return false;
        }
        if ($this->pdo === null) {
            $this->setError('Conexão encerrada.', 2006);
            return false;
        }

        try {
            $statement = $this->pdo->query($query);
            if ($statement === false) {
                $this->setError('Falha ao executar a consulta.', 1);
                return false;
            }
            $this->clearError();
            $this->affected_rows = $statement->rowCount();
            $isInsert = preg_match('/\A\s*INSERT\b/i', $query) === 1;
            if ($statement->columnCount() > 0) {
                $columnCount = $statement->columnCount();
                $rows = $statement->fetchAll(PDO::FETCH_BOTH);
                if ($isInsert && preg_match('/\bRETURNING\b/i', $query) === 1) {
                    $this->insert_id = self::returnedInsertId($rows);
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

    public function recordResult(int $affectedRows, int $insertId = 0): void
    {
        $this->affected_rows = $affectedRows;
        $this->insert_id = $insertId;
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

    public static function returnedInsertId(array $rows): int
    {
        if (!isset($rows[0]) || !is_array($rows[0]) || !array_key_exists('insert_id', $rows[0])) {
            return 0;
        }
        $value = $rows[0]['insert_id'];
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }
        $insertId = filter_var($value, FILTER_VALIDATE_INT);
        return $insertId === false ? 0 : $insertId;
    }
}
