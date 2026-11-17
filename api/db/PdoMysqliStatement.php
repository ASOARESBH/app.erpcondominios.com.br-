<?php
declare(strict_types=1);

namespace ERP\Database;

use PDO;
use PDOException;
use PDOStatement;

final class PdoMysqliStatement
{
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int|string $insert_id = 0;
    public int $num_rows = 0;
    public int $param_count = 0;
    public int $field_count = 0;

    private ?PgsqlMysqliStatement $pgsqlStatement = null;
    private ?PDOStatement $pdoStatement = null;
    private array $boundParams = [];
    private array $longDataBuffers = [];
    private array $boundResults = [];
    private array $rows = [];
    private int $cursor = 0;
    private bool $closed = false;

    private function __construct(private readonly PdoMysqliConnection $connection, private readonly string $query)
    {
        $this->param_count = $connection->driver() === 'pgsql'
            ? 0
            : self::countMysqlParameters($query, $connection->mysqlUsesNoBackslashEscapes());
    }

    public static function fromPostgres(PdoMysqliConnection $connection, PgsqlMysqliStatement $statement): self
    {
        $wrapper = new self($connection, '');
        $wrapper->pgsqlStatement = $statement;
        $wrapper->error =& $statement->error;
        $wrapper->errno =& $statement->errno;
        $wrapper->affected_rows =& $statement->affected_rows;
        $wrapper->insert_id =& $statement->insert_id;
        $wrapper->num_rows =& $statement->num_rows;
        $wrapper->param_count =& $statement->param_count;
        $wrapper->field_count =& $statement->field_count;
        return $wrapper;
    }

    public static function fromPdo(PdoMysqliConnection $connection, PDOStatement $statement, string $query): self
    {
        $wrapper = new self($connection, $query);
        $wrapper->pdoStatement = $statement;
        return $wrapper;
    }

    public function bind_param(string $types, mixed &...$variables): bool
    {
        $this->clearLongDataBuffers();
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->bind_param($types, ...$variables);
            $this->syncPgState();
            return $this->reportResult($ok);
        }
        if ($this->pdoStatement === null
            || strlen($types) !== count($variables)
            || count($variables) !== $this->param_count
            || preg_match('/\A[idsb]*\z/D', $types) !== 1) {
            $this->boundParams = [];
            $this->resetResultState();
            return $this->fail('Parâmetros preparados inválidos.', 2031);
        }

        $this->boundParams = [];
        foreach ($variables as $index => &$variable) {
            $this->boundParams[$index] = ['type' => $types[$index], 'value' => &$variable];
        }
        unset($variable);
        $this->clearError();
        return true;
    }

    public function send_long_data(int $param_num, string $data): bool
    {
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->send_long_data($param_num, $data);
            $this->syncPgState();
            return $this->reportResult($ok);
        }
        if (!isset($this->boundParams[$param_num]) || $param_num < 0 || $this->boundParams[$param_num]['type'] !== 'b') {
            return $this->fail('Parâmetro binário não vinculado.', 2031);
        }
        if (!isset($this->longDataBuffers[$param_num])) {
            $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (!is_resource($stream)) {
                return $this->fail('Buffer binário indisponível.', 1);
            }
            $this->longDataBuffers[$param_num] = $stream;
        }
        $stream = $this->longDataBuffers[$param_num];
        if (fwrite($stream, $data) !== strlen($data)) {
            $this->clearLongDataBuffers();
            return $this->fail('Falha ao armazenar conteúdo binário.', 1);
        }
        $this->clearError();
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->execute($params);
            $this->syncPgState();
            return $this->reportResult($ok);
        }
        if ($this->pdoStatement === null || $this->connection->pdo() === null) {
            return $this->fail('Conexão ou statement indisponível.', 2006);
        }
        $this->resetResultState();
        if ($params !== null && (!array_is_list($params) || count($params) !== $this->param_count || $this->boundParams !== [])) {
            $this->clearLongDataBuffers();
            return $this->fail('Quantidade ou forma de parâmetros incompatível.', 2031);
        }
        if ($params === null && $this->param_count > 0 && count($this->boundParams) !== $this->param_count) {
            $this->clearLongDataBuffers();
            return $this->fail('Os parâmetros devem ser vinculados antes da execução.', 2031);
        }
        try {
            if ($params !== null) {
                foreach ($params as $index => $value) {
                    $this->pdoStatement->bindValue($index + 1, $value, self::pdoTypeFromValue($value));
                }
                $ok = $this->pdoStatement->execute();
            } else {
                foreach ($this->boundParams as $index => $binding) {
                    $value = $this->longDataBuffers[$index] ?? $binding['value'];
                    if (is_resource($value)) {
                        rewind($value);
                    }
                    $type = isset($this->longDataBuffers[$index]) ? PDO::PARAM_LOB : $this->pdoType($binding['type'], $value);
                    $this->pdoStatement->bindValue($index + 1, $value, $type);
                }
                $ok = $this->pdoStatement->execute();
            }
            if (!$ok) {
                return $this->fail('Falha ao executar consulta preparada.', 1);
            }
            $this->connection->noteSessionSqlModeChange($this->query);
            $this->field_count = $this->pdoStatement->columnCount();
            if ($this->field_count > 0) {
                $this->rows = $this->pdoStatement->fetchAll(PDO::FETCH_BOTH);
                $this->num_rows = count($this->rows);
                $this->affected_rows = $this->num_rows;
            } else {
                $this->affected_rows = $this->pdoStatement->rowCount();
                if ($this->affected_rows > 0 && preg_match('/\A\s*INSERT\b/i', $this->query) === 1) {
                    $this->insert_id = $this->connection->lastInsertIdAfterInsert();
                }
            }
            $this->connection->recordResult($this->affected_rows, $this->insert_id);
            $this->clearError();
            return true;
        } catch (PDOException $exception) {
            $this->resetResultState();
            $this->setErrorFromException($exception);
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        } finally {
            $this->clearLongDataBuffers();
        }
    }

    public function get_result(): PdoMysqliResult|false
    {
        if ($this->closed || $this->field_count === 0) {
            return false;
        }
        if ($this->pgsqlStatement !== null) {
            $result = $this->pgsqlStatement->get_result();
            return $result instanceof PgsqlMysqliResult
                ? new PdoMysqliResult($result->allRows(), $result->field_count)
                : false;
        }
        return new PdoMysqliResult($this->rows, $this->field_count);
    }

    public function bind_result(mixed &...$variables): bool
    {
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->bind_result(...$variables);
            $this->syncPgState();
            return $this->reportResult($ok);
        }
        if (count($variables) !== $this->field_count) {
            return $this->fail('Quantidade de variáveis de resultado incompatível.', 2031);
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
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->fetch();
            $this->syncPgState();
            return $this->reportResult($ok);
        }
        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }
        if (count($this->boundResults) !== $this->field_count) {
            return $this->fail('Chame bind_result antes de fetch.', 2031);
        }
        $values = self::numericValues($this->rows[$this->cursor++]);
        foreach ($this->boundResults as $index => &$target) {
            $target = $values[$index] ?? null;
        }
        unset($target);
        $this->clearError();
        return true;
    }

    public function store_result(): bool
    {
        if ($this->closed) {
            return $this->fail('Statement encerrado.', 2006);
        }
        if ($this->pgsqlStatement !== null) {
            $ok = $this->pgsqlStatement->store_result();
            $this->syncPgState();
            return $this->reportResult($ok);
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
        $this->clearLongDataBuffers();
        $this->boundResults = [];
        $this->rows = [];
        $this->cursor = 0;
        if ($this->pgsqlStatement !== null) {
            return $this->pgsqlStatement->close();
        }
        if ($this->pdoStatement !== null) {
            try {
                return $this->pdoStatement->closeCursor();
            } catch (PDOException $exception) {
                $this->setErrorFromException($exception);
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
        }
        return true;
    }

    private function resetResultState(): void
    {
        $this->rows = [];
        $this->cursor = 0;
        $this->num_rows = 0;
        $this->field_count = 0;
        $this->affected_rows = 0;
        $this->insert_id = 0;
        $this->connection->recordResult(0, 0);
    }

    private function clearLongDataBuffers(): void
    {
        foreach ($this->longDataBuffers as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->longDataBuffers = [];
    }

    private function syncPgState(): void
    {
        if ($this->pgsqlStatement === null) {
            return;
        }
        $this->connection->recordResult($this->pgsqlStatement->affected_rows, $this->pgsqlStatement->insert_id);
    }

    private function pdoType(string $type, mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        return match ($type) {
            'i' => PDO::PARAM_INT,
            'b' => PDO::PARAM_LOB,
            'd', 's' => PDO::PARAM_STR,
            default => PDO::PARAM_STR,
        };
    }

    private static function pdoTypeFromValue(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }

    private static function countMysqlParameters(string $sql, bool $noBackslashEscapes = false): int
    {
        $count = 0;
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) {
                if ($char === "\n" || $char === "\r") {
                    $lineComment = false;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }
            if ($quote !== null) {
                if (!$noBackslashEscapes && $char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || preg_match('/[\x00-\x20]/D', $sql[$i + 2]) === 1)) || $char === '#') {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }
            if ($char === '?') {
                $count++;
            }
        }
        return $count;
    }

    private function fail(string $message, int $errno): false
    {
        $this->error = $message;
        $this->errno = $errno;
        $this->connection->setError($message, $errno);
        MysqliDropInState::reportFailure($message, $errno);
        return false;
    }

    private function reportResult(bool $ok): bool
    {
        if (!$ok && $this->error !== '') {
            MysqliDropInState::reportFailure($this->error, $this->errno);
        }
        return $ok;
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
        $state = is_array($info) && isset($info[0]) ? (string)$info[0] : (string)$exception->getCode();
        $driverErrno = is_array($info) && isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : 0;
        $this->error = 'Falha na consulta ao banco (SQLSTATE ' . $state . ').';
        $this->errno = $driverErrno > 0 ? $driverErrno : PgsqlMysqliConnection::mysqlErrnoForSqlState($state);
        $this->connection->setError($this->error, $this->errno);
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
}
