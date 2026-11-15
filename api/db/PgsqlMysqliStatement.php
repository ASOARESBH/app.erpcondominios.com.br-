<?php
declare(strict_types=1);

namespace ERP\Database;

use PDO;
use PDOException;
use PDOStatement;

final class PgsqlMysqliStatement
{
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int $insert_id = 0;
    public int $num_rows = 0;
    public int $param_count = 0;
    public int $field_count = 0;

    private array $boundParams = [];
    private array $boundResults = [];
    private array $rows = [];
    private int $cursor = 0;
    private bool $requiresExplicitParams = false;
    private ?string $parameterMode = null;
    private bool $closed = false;

    public function __construct(
        private readonly PgsqlMysqliConnection $connection,
        private readonly PDO $pdo,
        private readonly PDOStatement $statement,
        private readonly string $query
    ) {
        $this->param_count = self::countPositionalParameters($query);
    }

    public function bind_param(string $types, mixed &...$variables): bool
    {
        if ($this->closed || $this->connection->isClosed()) {
            $this->setError('Statement ou conexão encerrada.', 2006);
            return false;
        }
        if ($this->parameterMode === 'execute') {
            $this->setError('Parâmetros preparados inválidos.', 2031);
            return false;
        }
        if (strlen($types) !== count($variables) || count($variables) !== $this->param_count || preg_match('/\A[idsb]*\z/', $types) !== 1) {
            $this->boundParams = [];
            $this->requiresExplicitParams = $this->param_count > 0;
            $this->parameterMode = 'bind_param';
            $this->setError('Parâmetros preparados inválidos.', 2031);
            return false;
        }

        $this->boundParams = [];
        foreach ($variables as $index => &$variable) {
            $this->boundParams[$index] = [
                'type' => $types[$index],
                'value' => &$variable,
            ];
        }
        unset($variable);
        $this->requiresExplicitParams = false;
        $this->parameterMode = 'bind_param';
        $this->clearError();
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->resetResultState();
        if ($this->closed || $this->connection->isClosed()) {
            $this->setError('Statement ou conexão encerrada.', 2006);
            return false;
        }

        if ($params !== null) {
            if ($this->parameterMode === 'bind_param' || !array_is_list($params) || count($params) !== $this->param_count) {
                $this->setError('Quantidade ou forma de parâmetros incompatível.', 2031);
                return false;
            }
            $this->parameterMode = 'execute';
        } elseif (($this->requiresExplicitParams && $this->param_count > 0)
            || ($this->param_count > 0 && count($this->boundParams) !== $this->param_count)) {
            $this->setError('Os parâmetros devem ser vinculados antes da execução.', 2031);
            return false;
        }

        try {
            if ($params !== null) {
                $executed = $this->statement->execute($params);
                $this->requiresExplicitParams = $this->param_count > 0;
            } else {
                foreach ($this->boundParams as $index => $binding) {
                    $value = $binding['value'];
                    $this->statement->bindValue($index + 1, $value, $this->pdoTypeForMysqliType($binding['type']));
                }
                $executed = $this->statement->execute();
            }

            if (!$executed) {
                $this->setError('Falha ao executar a consulta preparada.', 1);
                return false;
            }

            $this->field_count = $this->statement->columnCount();
            $this->affected_rows = $this->statement->rowCount();
            $this->rows = $this->field_count > 0 ? $this->statement->fetchAll(PDO::FETCH_BOTH) : [];
            $this->num_rows = count($this->rows);
            if (count($this->boundResults) !== $this->field_count) {
                $this->boundResults = [];
            }

            if ($this->field_count > 0
                && preg_match('/\A\s*INSERT\b/i', $this->query) === 1
                && preg_match('/\bRETURNING\b/i', $this->query) === 1) {
                $this->insert_id = PgsqlMysqliConnection::returnedInsertId($this->rows);
            }
            $this->connection->recordResult($this->affected_rows, $this->insert_id);
            $this->clearError();
            return true;
        } catch (PDOException $exception) {
            $this->resetResultState();
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function get_result(): PgsqlMysqliResult|false
    {
        if ($this->closed || $this->connection->isClosed() || $this->field_count === 0) {
            return false;
        }
        return new PgsqlMysqliResult($this->rows, $this->field_count);
    }

    public function bind_result(mixed &...$variables): bool
    {
        if ($this->closed || $this->connection->isClosed()) {
            $this->setError('Statement ou conexão encerrada.', 2006);
            return false;
        }
        if (count($variables) !== $this->field_count) {
            $this->setError('Quantidade de variáveis de resultado incompatível.', 2031);
            return false;
        }
        $this->boundResults = [];
        foreach ($variables as $index => &$variable) {
            $this->boundResults[$index] = &$variable;
        }
        unset($variable);
        $this->cursor = 0;
        $this->clearError();
        return true;
    }

    public function fetch(): bool
    {
        if ($this->closed || $this->connection->isClosed()) {
            $this->setError('Statement ou conexão encerrada.', 2006);
            return false;
        }
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        if ($this->boundResults === []) {
            $this->setError('Chame bind_result antes de fetch.', 2031);
            return false;
        }

        $values = self::numericValues($this->rows[$this->cursor]);
        foreach ($this->boundResults as $index => &$target) {
            $target = $values[$index] ?? null;
        }
        unset($target);
        $this->cursor++;
        $this->clearError();
        return true;
    }

    public function store_result(): bool
    {
        if ($this->closed || $this->connection->isClosed()) {
            $this->setError('Statement ou conexão encerrada.', 2006);
            return false;
        }
        $this->num_rows = count($this->rows);
        return true;
    }

    public function close(): bool
    {
        if ($this->closed) {
            return true;
        }
        $this->closed = true;
        $this->boundParams = [];
        $this->resetResultState(true, false);
        try {
            return $this->statement->closeCursor();
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    private function resetResultState(bool $clearBindings = false, bool $updateConnection = true): void
    {
        $this->rows = [];
        $this->cursor = 0;
        if ($clearBindings) {
            $this->boundResults = [];
        }
        $this->insert_id = 0;
        $this->num_rows = 0;
        $this->field_count = 0;
        $this->affected_rows = 0;
        if ($updateConnection) {
            $this->connection->recordResult(0, 0);
        }
    }

    private static function numericValues(array $row): array
    {
        $values = [];
        foreach ($row as $key => $value) {
            if (is_int($key)) {
                $values[$key] = $value;
            }
        }
        if ($values === []) {
            return array_values($row);
        }
        ksort($values);
        return array_values($values);
    }

    private static function countPositionalParameters(string $sql): int
    {
        $count = 0;
        $length = strlen($sql);
        $state = 'normal';
        $blockDepth = 0;
        $dollarDelimiter = '';
        $escapeString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($state === 'single') {
                if ($escapeString && $char === '\\') {
                    $i++;
                } elseif ($char === "'" && $next === "'") {
                    $i++;
                } elseif ($char === "'") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'double') {
                if ($char === '"' && $next === '"') {
                    $i++;
                } elseif ($char === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'line') {
                if ($char === "\n" || $char === "\r") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block') {
                if ($char === '/' && $next === '*') {
                    $blockDepth++;
                    $i++;
                } elseif ($char === '*' && $next === '/') {
                    $blockDepth--;
                    $i++;
                    if ($blockDepth === 0) {
                        $state = 'normal';
                    }
                }
                continue;
            }
            if ($state === 'dollar') {
                if (substr_compare($sql, $dollarDelimiter, $i, strlen($dollarDelimiter)) === 0) {
                    $i += strlen($dollarDelimiter) - 1;
                    $state = 'normal';
                    $dollarDelimiter = '';
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $state = 'line';
                $i++;
            } elseif ($char === '/' && $next === '*') {
                $state = 'block';
                $blockDepth = 1;
                $i++;
            } elseif ($char === "'") {
                $previous = $sql[$i - 1] ?? '';
                $escapeString = ($previous === 'E' || $previous === 'e')
                    && !self::isIdentifierByte($sql[$i - 2] ?? '');
                $state = 'single';
            } elseif ($char === '"') {
                $state = 'double';
            } elseif ($char === '$'
                && !self::isIdentifierByte($sql[$i - 1] ?? '')
                && preg_match('/\G\$(?:[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*)?\$/', $sql, $match, 0, $i) === 1) {
                $dollarDelimiter = $match[0];
                $i += strlen($dollarDelimiter) - 1;
                $state = 'dollar';
            } elseif ($char === '?' && $next === '?') {
                $i++;
            } elseif ($char === '?') {
                $count++;
            }
        }

        return $count;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return $byte !== '' && preg_match('/\A[A-Za-z0-9_$\x80-\xFF]\z/D', $byte) === 1;
    }

    private function pdoTypeForMysqliType(string $type): int
    {
        return match ($type) {
            'i' => PDO::PARAM_INT,
            'b' => PDO::PARAM_LOB,
            'd', 's' => PDO::PARAM_STR,
            default => PDO::PARAM_STR,
        };
    }

    private function setError(string $message, int $errno): void
    {
        $this->error = $message;
        $this->errno = $errno;
        $this->connection->setError($message, $errno);
    }

    private function clearError(): void
    {
        $this->error = '';
        $this->errno = 0;
        $this->connection->clearError();
    }

    private function setErrorFromException(PDOException $exception): void
    {
        $info = $exception->errorInfo ?? [];
        $sqlState = is_array($info) && isset($info[0]) ? (string)$info[0] : (string)$exception->getCode();
        $errno = PgsqlMysqliConnection::mysqlErrnoForSqlState($sqlState);
        $message = 'Falha na consulta PostgreSQL (SQLSTATE ' . $sqlState . ').';
        $this->error = $message;
        $this->errno = $errno;
        $this->connection->setError($message, $errno);
    }
}
