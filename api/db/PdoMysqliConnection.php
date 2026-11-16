<?php
declare(strict_types=1);

namespace ERP\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/PgsqlMysqliConnection.php';
require_once __DIR__ . '/PgsqlMysqliStatement.php';
require_once __DIR__ . '/PgsqlMysqliResult.php';
require_once __DIR__ . '/PdoMysqliStatement.php';
require_once __DIR__ . '/PdoMysqliResult.php';

final class PdoMysqliConnection
{
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int|string $insert_id = 0;
    public ?string $connect_error = null;
    public int $connect_errno = 0;
    public string $host_info = '';
    public string $client_info = '';
    public string $server_info = '';
    public int|string $thread_id = 0;

    private ?PDO $pdo = null;
    private ?PgsqlMysqliConnection $pgsql = null;
    private string $selectedDriver = '';
    private bool $mysqlNoBackslashEscapes = false;
    private bool $mysqlSqlModeRefreshRequired = false;
    private string $charset = 'utf8mb4';
    private array $multiQueries = [];
    private int $multiQueryIndex = -1;
    private ?PdoMysqliResult $multiQueryResult = null;

    public function __construct(
        ?string $hostname = null,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null
    ) {
        $this->connectFromSelectedConfig($hostname, $username, $password, $database, $port, $socket);
    }

    public function driver(): string
    {
        return $this->selectedDriver;
    }

    public function mysqlUsesNoBackslashEscapes(): bool
    {
        return $this->mysqlNoBackslashEscapes;
    }

    private static function sqlModeUsesNoBackslashEscapes(mixed $sqlMode): bool
    {
        if (!is_string($sqlMode)) {
            return false;
        }
        return in_array('NO_BACKSLASH_ESCAPES', array_map('trim', explode(',', strtoupper($sqlMode))), true);
    }

    public function noteSessionSqlModeChange(string $query): void
    {
        if ($this->selectedDriver !== 'mysql') {
            return;
        }
        if (preg_match('/(?<![A-Za-z0-9_])sql_mode(?![A-Za-z0-9_])/i', $query) === 1) {
            $this->mysqlSqlModeRefreshRequired = true;
        }
    }

    public function prepare(string $query): PdoMysqliStatement|false
    {
        if ($this->multiQueries !== []) {
            $this->setError('Há resultados multi-query pendentes.', 2014);
            return false;
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }

        if ($this->selectedDriver === 'pgsql') {
            $statement = $this->pgsql?->prepare($query);
            if ($statement === false || $statement === null) {
                $this->syncPgState();
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            return PdoMysqliStatement::fromPostgres($this, $statement);
        }

        try {
            $statement = $this->pdo?->prepare($query);
            if (!$statement instanceof PDOStatement) {
                $this->setError('Falha ao preparar consulta.', 1);
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            $this->clearError();
            return PdoMysqliStatement::fromPdo($this, $statement, $query);
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }
    }

    public function query(string $query, int $resultMode = 0): PdoMysqliResult|bool
    {
        $this->affected_rows = 0;
        $this->insert_id = 0;
        if ($this->multiQueries !== []) {
            $this->setError('Há resultados multi-query pendentes.', 2014);
            return false;
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        if ($resultMode !== MYSQLI_STORE_RESULT) {
            $this->setError('Modo de resultado não bufferizado não é suportado.', 1);
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }

        if ($this->selectedDriver === 'pgsql') {
            $result = $this->pgsql?->query(self::translateSessionTimezone($query));
            $this->syncPgState();
            if ($result === false || $result === null) {
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            if ($result instanceof PgsqlMysqliResult) {
                return new PdoMysqliResult($result->allRows(), $result->field_count);
            }
            return $result;
        }

        try {
            $isInsert = preg_match('/\A\s*INSERT\b/i', $query) === 1;
            $insertSequenceState = $isInsert && $this->selectedDriver === 'pgsql'
                ? $this->captureInsertSequenceState($query)
                : null;
            $statement = $this->pdo?->query($query);
            if (!$statement instanceof PDOStatement) {
                $this->setError('Falha ao executar consulta.', 1);
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            $this->clearError();
            $this->noteSessionSqlModeChange($query);
            $fieldCount = $statement->columnCount();
            if ($fieldCount > 0) {
                $rows = $statement->fetchAll(PDO::FETCH_BOTH);
                $this->affected_rows = count($rows);
                if ($isInsert && PgsqlMysqliConnection::hasReturningClause($query)) {
                    $this->insert_id = PgsqlMysqliConnection::returnedInsertId($rows);
                }
                return new PdoMysqliResult($rows, $fieldCount);
            }
            $this->affected_rows = $statement->rowCount();
            if ($isInsert && $this->affected_rows > 0) {
                $this->insert_id = $this->lastInsertIdAfterInsert($insertSequenceState);
            }
            return true;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            $this->affected_rows = 0;
            $this->insert_id = 0;
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }
    }

    public function multi_query(string $query): bool
    {
        $this->affected_rows = 0;
        $this->insert_id = 0;
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        if ($this->selectedDriver !== 'mysql') {
            $this->setError('SQL multi-statement não suportado neste driver.', 1);
            return self::reportResult(false, $this->error, $this->errno);
        }
        if ($this->multiQueries !== []) {
            $this->setError('Há resultados multi-query pendentes.', 2014);
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (!$this->refreshMysqlSqlModeIfNeeded()) {
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (preg_match('/(?<![A-Za-z0-9_])sql_mode(?![A-Za-z0-9_])/i', $query) === 1) {
            $this->setError('Multi-query com referência a sql_mode não é suportada.', 1);
            return self::reportResult(false, $this->error, $this->errno);
        }

        $queries = self::splitMysqlStatements($query, $this->mysqlNoBackslashEscapes);
        if ($queries === null) {
            $this->setError('Formato de SQL multi-statement não suportado.', 1);
            return self::reportResult(false, $this->error, $this->errno);
        }
        if ($queries === []) {
            $this->clearError();
            return true;
        }

        $this->multiQueries = $queries;
        $this->multiQueryIndex = 0;
        if (!$this->executeMultiQueryAtIndex()) {
            $this->resetMultiQueryState();
            return false;
        }
        return true;
    }

    public function more_results(): bool
    {
        if ($this->multiQueries === []) {
            return false;
        }
        if ($this->multiQueryIndex + 1 < count($this->multiQueries)) {
            return true;
        }
        $this->resetMultiQueryState();
        return false;
    }

    public function next_result(): bool
    {
        if ($this->multiQueries === [] || $this->multiQueryIndex + 1 >= count($this->multiQueries)) {
            $this->resetMultiQueryState();
            return false;
        }
        $this->multiQueryIndex++;
        if (!$this->executeMultiQueryAtIndex()) {
            $this->resetMultiQueryState();
            return false;
        }
        return true;
    }

    public function store_result(): PdoMysqliResult|false
    {
        if ($this->multiQueries === [] || $this->multiQueryResult === null) {
            return false;
        }
        $result = $this->multiQueryResult;
        $this->multiQueryResult = null;
        return $result;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if ($this->rejectPendingMultiQuery()) {
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        $this->clearError();
        if ($this->selectedDriver === 'pgsql') {
            $ok = $this->pgsql?->begin_transaction($flags, $name) ?? false;
            $this->syncPgState();
        } elseif ($flags !== 0 || $name !== null) {
            $this->setError('Opções de transação não suportadas.', 1);
            $ok = false;
        } else {
            try {
                $ok = $this->pdo?->beginTransaction() ?? false;
                if ($ok) {
                    $this->clearError();
                } else {
                    $this->setError('Falha ao iniciar transação.', 1);
                }
            } catch (PDOException $exception) {
                $this->setErrorFromException($exception);
                $ok = false;
            }
        }
        return self::reportResult($ok, $this->error, $this->errno);
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        if ($this->rejectPendingMultiQuery()) {
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        $this->clearError();
        if ($this->selectedDriver === 'pgsql') {
            $ok = $this->pgsql?->commit($flags, $name) ?? false;
            $this->syncPgState();
        } elseif ($flags !== 0 || $name !== null) {
            $this->setError('Opções de transação não suportadas.', 1);
            $ok = false;
        } else {
            try {
                $ok = $this->pdo?->commit() ?? false;
                if ($ok) {
                    $this->clearError();
                } else {
                    $this->setError('Falha ao confirmar transação.', 1);
                }
            } catch (PDOException $exception) {
                $this->setErrorFromException($exception);
                $ok = false;
            }
        }
        return self::reportResult($ok, $this->error, $this->errno);
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        if ($this->rejectPendingMultiQuery()) {
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        $this->clearError();
        if ($this->selectedDriver === 'pgsql') {
            $ok = $this->pgsql?->rollback($flags, $name) ?? false;
            $this->syncPgState();
        } elseif ($flags !== 0 || $name !== null) {
            $this->setError('Opções de transação não suportadas.', 1);
            $ok = false;
        } else {
            try {
                $ok = $this->pdo?->rollBack() ?? false;
                if ($ok) {
                    $this->clearError();
                } else {
                    $this->setError('Falha ao reverter transação.', 1);
                }
            } catch (PDOException $exception) {
                $this->setErrorFromException($exception);
                $ok = false;
            }
        }
        return self::reportResult($ok, $this->error, $this->errno);
    }

    public function set_charset(string $charset): bool
    {
        if ($this->rejectPendingMultiQuery()) {
            return self::reportResult(false, $this->error, $this->errno);
        }
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        if (!in_array(strtolower($charset), ['utf8', 'utf8mb4'], true)) {
            $this->setError('Charset não suportado.', 2019);
            return self::reportResult(false, $this->error, $this->errno);
        }
        if ($this->selectedDriver === 'pgsql') {
            $ok = $this->pgsql?->set_charset($charset) ?? false;
            $this->syncPgState();
            if ($ok) {
                $this->charset = strtolower($charset);
            }
            return self::reportResult($ok, $this->error, $this->errno);
        }
        try {
            $this->pdo?->exec('SET NAMES ' . strtolower($charset));
            $this->charset = strtolower($charset);
            $this->clearError();
            return true;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return self::reportResult(false, $this->error, $this->errno);
        }
    }

    public function character_set_name(): string|false
    {
        return $this->isConnected() ? $this->charset : false;
    }

    public function real_escape_string(string $value): string|false
    {
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        if ($this->selectedDriver === 'pgsql') {
            $escaped = $this->pgsql?->real_escape_string($value) ?? false;
            $this->syncPgState();
            if ($escaped === false) {
                MysqliDropInState::reportFailure($this->error, $this->errno);
            }
            return $escaped;
        }
        if (!$this->refreshMysqlSqlModeIfNeeded()) {
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }
        if ($this->mysqlNoBackslashEscapes) {
            if (str_contains($value, "\0")) {
                $this->setError('Valor contém byte NUL não suportado no SQL mode ativo.', 1);
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            $this->clearError();
            return str_replace("'", "''", $value);
        }
        try {
            $quoted = $this->pdo?->quote($value, PDO::PARAM_STR);
            if (!is_string($quoted) || strlen($quoted) < 2) {
                $this->setError('Falha ao escapar valor.', 1);
                MysqliDropInState::reportFailure($this->error, $this->errno);
                return false;
            }
            $this->clearError();
            return substr($quoted, 1, -1);
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }
    }

    private function refreshMysqlSqlModeIfNeeded(): bool
    {
        if (!$this->mysqlSqlModeRefreshRequired) {
            return true;
        }
        try {
            $statement = $this->pdo?->query('SELECT @@SESSION.sql_mode AS sql_mode');
            if (!$statement instanceof PDOStatement) {
                $this->setError('Não foi possível confirmar o SQL mode da sessão.', 1);
                return false;
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !is_string($row['sql_mode'] ?? null)) {
                $this->setError('Não foi possível confirmar o SQL mode da sessão.', 1);
                return false;
            }
            $this->mysqlNoBackslashEscapes = self::sqlModeUsesNoBackslashEscapes($row['sql_mode']);
            $this->mysqlSqlModeRefreshRequired = false;
            $this->clearError();
            return true;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            return false;
        }
    }

    public function ping(): bool
    {
        if (!$this->isConnected()) {
            return $this->connectionFailure();
        }
        if ($this->selectedDriver === 'pgsql') {
            $ok = $this->pgsql?->ping() ?? false;
            $this->syncPgState();
            return self::reportResult($ok, $this->error, $this->errno);
        }
        return $this->query('SELECT 1') !== false;
    }

    public function close(): bool
    {
        $this->resetMultiQueryState();
        $this->pgsql?->close();
        $this->pgsql = null;
        $this->pdo = null;
        $this->selectedDriver = '';
        return true;
    }

    public function preparePdoStatement(PDOStatement $statement, string $query): PdoMysqliStatement
    {
        return PdoMysqliStatement::fromPdo($this, $statement, $query);
    }

    public function recordResult(int $affectedRows, int|string $insertId = 0): void
    {
        $this->affected_rows = $affectedRows;
        $this->insert_id = $insertId;
        $this->pgsql?->recordResult($affectedRows, $insertId);
    }

    public function setError(string $message, int $errno): void
    {
        $this->error = $message;
        $this->errno = $errno;
        $this->pgsql?->setError($message, $errno);
    }

    public function clearError(): void
    {
        $this->error = '';
        $this->errno = 0;
        $this->pgsql?->clearError();
    }

    public function captureInsertSequenceState(string $query): ?array
    {
        return $this->selectedDriver === 'pgsql' ? $this->pgsql?->captureInsertSequenceState($query) : null;
    }

    public function lastInsertIdAfterInsert(?array $state = null): int|string
    {
        if (!$this->isConnected()) {
            return 0;
        }
        if ($this->selectedDriver === 'pgsql') {
            $id = $this->pgsql?->lastInsertIdAfterInsert($state) ?? 0;
            $this->insert_id = $id;
            return $id;
        }
        try {
            $value = $this->pdo?->lastInsertId();
            return self::normalizeInsertId($value);
        } catch (PDOException) {
            return 0;
        }
    }

    public function pdo(): ?PDO
    {
        return $this->pdo;
    }

    private function connectFromSelectedConfig(
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket
    ): void
    {
        try {
            $config = MysqliDropInBootstrap::configuration();
            $this->selectedDriver = MysqliDropInBootstrap::selectedDriver($config);
            if ($this->selectedDriver === 'mysql') {
                self::assertMysqlArgumentsMatchConfig($config, $hostname, $username, $password, $database, $port, $socket);
            } else {
                self::assertPostgresArgumentsMatchConfig($config, $hostname, $username, $password, $database, $port, $socket);
            }
            if ($this->selectedDriver === 'pgsql') {
                $this->pgsql = PgsqlMysqliConnection::connect([
                    'host' => $config['pg_host'],
                    'port' => $config['pg_port'],
                    'database' => $config['pg_name'],
                    'user' => $config['pg_user'],
                    'password' => $config['pg_password'],
                ]);
                $searchPath = 'SET search_path TO "' . $config['pg_schema'] . '"';
                if ($this->pgsql->query($searchPath) !== true) {
                    throw new RuntimeException('Não foi possível selecionar o schema PostgreSQL.');
                }
                $this->pdo = null;
                $this->error =& $this->pgsql->error;
                $this->errno =& $this->pgsql->errno;
                $this->affected_rows =& $this->pgsql->affected_rows;
                $this->insert_id =& $this->pgsql->insert_id;
                $this->charset = 'utf8';
                $this->host_info = 'PostgreSQL via PDO';
                $this->client_info = $this->pgsql->clientInfo();
                $metadata = $this->pgsql->query('SELECT version() AS server_info, pg_backend_pid() AS thread_id');
                $row = $metadata instanceof PgsqlMysqliResult ? $metadata->fetch_assoc() : null;
                $this->server_info = is_string($row['server_info'] ?? null) ? $row['server_info'] : '';
                $this->thread_id = $row['thread_id'] ?? 0;
                $metadata?->free();
                $this->pgsql->clearError();
            } else {
                $this->pdo = self::connectMysql($config);
                $this->client_info = self::clientInfo($this->pdo);
                $metadata = $this->pdo->query('SELECT VERSION() AS server_info, CONNECTION_ID() AS thread_id, @@SESSION.sql_mode AS sql_mode');
                $row = $metadata->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->server_info = is_string($row['server_info'] ?? null) ? $row['server_info'] : '';
                $id = $row['thread_id'] ?? 0;
                $this->thread_id = is_int($id) || (is_string($id) && preg_match('/\A[0-9]+\z/D', $id) === 1) ? $id : 0;
                $this->mysqlNoBackslashEscapes = self::sqlModeUsesNoBackslashEscapes($row['sql_mode'] ?? null);
                $this->charset = strtolower($config['mysql_charset'] ?? 'utf8mb4');
                $this->host_info = 'MySQL via PDO';
            }
            $this->connect_error = null;
            $this->connect_errno = 0;
            MysqliDropInState::setLastConnectError(null);
        } catch (Throwable $exception) {
            $this->pgsql?->close();
            $this->pgsql = null;
            $this->pdo = null;
            $this->selectedDriver = '';
            $this->connect_errno = self::connectErrnoFromThrowable($exception);
            $this->connect_error = 'Falha ao estabelecer conexão com o banco de dados.';
            $this->error = $this->connect_error;
            $this->errno = $this->connect_errno;
            $this->affected_rows = 0;
            $this->insert_id = 0;
            $this->host_info = '';
            $this->client_info = '';
            $this->server_info = '';
            $this->thread_id = 0;
            MysqliDropInState::setLastConnectError($this->connect_error);
            if (MysqliDropInState::throwsOnError()) {
                throw new MysqliCompatSqlException($this->connect_error, $this->connect_errno);
            }
            if (MysqliDropInState::reportsErrors()) {
                trigger_error($this->connect_error, E_USER_WARNING);
            }
        }
    }

    private static function connectMysql(array $config): PDO
    {
        $host = $config['mysql_host'] ?? null;
        $database = $config['mysql_name'] ?? null;
        $user = $config['mysql_user'] ?? null;
        $password = $config['mysql_password'] ?? null;
        $port = $config['mysql_port'] ?? 3306;
        $charset = $config['mysql_charset'] ?? 'utf8mb4';
        $validHost = is_string($host) && preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D', $host) === 1;
        $validIp = is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ((!$validHost && !$validIp)
            || !is_string($database) || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1
            || !is_string($user) || $user === '' || !is_string($password)
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($charset) || !in_array(strtolower($charset), ['utf8', 'utf8mb4'], true)) {
            throw new RuntimeException('Configuração MySQL inválida.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, strtolower($charset));
        $pdo = new PDO($dsn, $user, $password, self::pdoOptions());
        $pdo->exec("SET time_zone = '-03:00'");
        return $pdo;
    }

    private static function assertMysqlArgumentsMatchConfig(
        array $config,
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket
    ): void {
        $matches = ($hostname === null || $hostname === $config['mysql_host'])
            && ($username === null || $username === $config['mysql_user'])
            && ($password === null || $password === $config['mysql_password'])
            && ($database === null || $database === $config['mysql_name'])
            && ($port === null || $port === $config['mysql_port'])
            && ($socket === null || $socket === '');
        if (!$matches) {
            throw new RuntimeException('Argumentos de conexão incompatíveis com a configuração ativa.');
        }
    }

    private static function assertPostgresArgumentsMatchConfig(
        array $config,
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket
    ): void {
        $matches = ($hostname === null || $hostname === $config['pg_host'])
            && ($username === null || $username === $config['pg_user'])
            && ($password === null || $password === $config['pg_password'])
            && ($database === null || $database === $config['pg_name'])
            && ($port === null || $port === $config['pg_port'])
            && ($socket === null || $socket === '');
        if (!$matches) {
            throw new RuntimeException('Argumentos de conexão incompatíveis com a configuração ativa.');
        }
    }

    private static function pdoOptions(): array
    {
        return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    }

    private static function connectErrnoFromThrowable(Throwable $exception): int
    {
        $driverErrno = $exception instanceof PDOException ? ($exception->errorInfo[1] ?? null) : null;
        if ((is_int($driverErrno) || (is_string($driverErrno) && preg_match('/\A[0-9]+\z/D', $driverErrno) === 1))
            && (int)$driverErrno > 0) {
            return (int)$driverErrno;
        }
        return 2002;
    }

    private static function clientInfo(?PDO $pdo): string
    {
        if ($pdo === null) {
            return '';
        }
        try {
            $value = $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
            return is_string($value) ? $value : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function isConnected(): bool
    {
        return $this->selectedDriver === 'pgsql' ? $this->pgsql !== null && !$this->pgsql->isClosed() : $this->pdo !== null;
    }

    private function connectionFailure(): false
    {
        $this->setError('Conexão indisponível.', $this->connect_errno > 0 ? $this->connect_errno : 2006);
        MysqliDropInState::reportFailure($this->error, $this->errno);
        return false;
    }

    private function syncPgState(): void
    {
        if ($this->pgsql === null) {
            return;
        }
        $this->error = $this->pgsql->error;
        $this->errno = $this->pgsql->errno;
        $this->affected_rows = $this->pgsql->affected_rows;
        $this->insert_id = $this->pgsql->insert_id;
    }

    private static function reportResult(bool $ok, string $error, int $errno): bool
    {
        if (!$ok && $error !== '') {
            MysqliDropInState::reportFailure($error, $errno);
        }
        return $ok;
    }

    private function executeMultiQueryAtIndex(): bool
    {
        $query = $this->multiQueries[$this->multiQueryIndex] ?? null;
        if (!is_string($query) || $this->pdo === null) {
            $this->setError('Consulta multi-statement indisponível.', 2006);
            return false;
        }
        $this->multiQueryResult = null;
        $this->affected_rows = 0;
        $this->insert_id = 0;
        try {
            $statement = $this->pdo->query($query);
            if (!$statement instanceof PDOStatement) {
                $this->setError('Falha ao executar consulta multi-statement.', 1);
                return false;
            }
            $this->clearError();
            $this->noteSessionSqlModeChange($query);
            $fieldCount = $statement->columnCount();
            if ($fieldCount > 0) {
                $rows = $statement->fetchAll(PDO::FETCH_BOTH);
                $this->affected_rows = count($rows);
                $this->multiQueryResult = new PdoMysqliResult($rows, $fieldCount);
                return true;
            }
            $this->affected_rows = $statement->rowCount();
            if ($this->affected_rows > 0 && preg_match('/\A\s*INSERT\b/i', $query) === 1) {
                $this->insert_id = self::normalizeInsertId($this->pdo->lastInsertId());
            }
            return true;
        } catch (PDOException $exception) {
            $this->setErrorFromException($exception);
            $this->affected_rows = 0;
            $this->insert_id = 0;
            $this->multiQueryResult = null;
            MysqliDropInState::reportFailure($this->error, $this->errno);
            return false;
        }
    }

    private function resetMultiQueryState(): void
    {
        $this->multiQueries = [];
        $this->multiQueryIndex = -1;
        $this->multiQueryResult = null;
    }

    private function rejectPendingMultiQuery(): bool
    {
        if ($this->multiQueries === []) {
            return false;
        }
        $this->setError('Há resultados multi-query pendentes.', 2014);
        return true;
    }

    private static function splitMysqlStatements(string $sql, bool $noBackslashEscapes): ?array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $current .= $char;
                if (!$noBackslashEscapes && $char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && (($sql[$i + 2] ?? '') === '' || ord($sql[$i + 2]) <= 32))) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                if (self::unsupportedMultiQueryStatement($current)) {
                    return null;
                }
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($quote !== null || self::unsupportedMultiQueryStatement($current)) {
            return null;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }

    private static function unsupportedMultiQueryStatement(string $sql): bool
    {
        $statement = ltrim($sql);
        if (preg_match('/\A\/\*(?:!|M!)\d*\s*(?:DELIMITER\b|CALL\b|CREATE\b)/i', $statement) === 1) {
            return true;
        }
        do {
            $previous = $statement;
            $statement = preg_replace('/\A(?:\x2D\x2D[ \t][^\r\n]*|#[^\r\n]*)(?:\r?\n|\z)\s*/', '', $statement) ?? $statement;
            $statement = preg_replace('/\A\/\*.*?\*\/\s*/s', '', $statement) ?? $statement;
            $statement = ltrim($statement);
        } while ($statement !== $previous);

        return preg_match(
            '/\A(?:DELIMITER\b|CALL\b|CREATE\s+(?:(?:DEFINER\s*=\s*(?:\x27(?:\x27\x27|[^\x27])*\x27|\x60[^\x60]*\x60|[^\s]+)(?:\s*@\s*(?:\x27(?:\x27\x27|[^\x27])*\x27|\x60[^\x60]*\x60|[^\s]+))?\s+)|(?:OR\s+REPLACE\s+))*(?:PROCEDURE|FUNCTION|TRIGGER|EVENT)\b)/i',
            $statement
        ) === 1;
    }

    private function setErrorFromException(PDOException $exception): void
    {
        $info = $exception->errorInfo ?? [];
        $sqlState = is_array($info) && isset($info[0]) ? (string)$info[0] : (string)$exception->getCode();
        $driverErrno = is_array($info) && isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : 0;
        $this->setError('Falha na consulta ao banco (SQLSTATE ' . $sqlState . ').', $driverErrno > 0 ? $driverErrno : PgsqlMysqliConnection::mysqlErrnoForSqlState($sqlState));
    }

    private static function normalizeInsertId(mixed $value): int|string
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1)) {
            return 0;
        }
        $digits = (string)$value;
        if ($digits === '0') {
            return 0;
        }
        $integer = filter_var($digits, FILTER_VALIDATE_INT);
        return $integer === false ? $digits : $integer;
    }

    private static function translateSessionTimezone(string $query): string
    {
        if (preg_match("/\A\s*SET\s+time_zone\s*=\s*'([^']+)'\s*\z/i", $query, $matches) !== 1) {
            return $query;
        }
        return "SET TIME ZONE '" . str_replace("'", "''", $matches[1]) . "'";
    }
}
