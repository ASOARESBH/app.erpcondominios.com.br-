<?php
declare(strict_types=1);

use ERP\Database\MysqliDropInBootstrap;
use ERP\Database\PgsqlMysqliConnection;
use ERP\Database\PdoMysqliConnection;
use ERP\Database\PdoMysqliStatement;

require_once __DIR__ . '/../api/db/MysqliDropIn.php';
require_once __DIR__ . '/../api/db/PdoMysqliConnection.php';

function acceptsMysqliType(mysqli $connection): bool
{
    return $connection instanceof mysqli;
}

function acceptsMysqliStatementType(mysqli_stmt $statement): bool
{
    return $statement instanceof mysqli_stmt;
}

function acceptsMysqliResultType(mysqli_result $result): bool
{
    return $result instanceof mysqli_result;
}

$dropInAssertionCount = 0;

function assertDropIn(bool $condition, string $message): void
{
    global $dropInAssertionCount;
    $dropInAssertionCount++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class DropInFakePdoStatement extends PDOStatement
{
    private array $bindings = [];
    private array $rows = [];

    public function __construct(private DropInFakePdo $owner, private string $query)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;
        $this->owner->lastBindingTypes[$param] = $type;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->owner->queryFailure === $this->query) {
            $exception = new PDOException('synthetic raw driver detail');
            $exception->errorInfo = ['HY000', 1205, 'synthetic-secret detail'];
            throw $exception;
        }
        if ($params !== null) {
            $this->bindings = $params;
        }
        $capturedBindings = $this->bindings;
        foreach ($capturedBindings as $index => $value) {
            if (is_resource($value)) {
                rewind($value);
                $capturedBindings[$index] = stream_get_contents($value);
            }
        }
        $normalizedQuery = preg_replace(
            '/\A(?:(?:\s+)|(?:--[ \t\r\n][^\r\n]*)|(?:\#[^\r\n]*)|(?:\/\*.*?\*\/))*/s',
            '',
            $this->query
        ) ?? $this->query;
        if (str_contains(strtolower($this->query), 'sql_mode')
            && str_contains(strtoupper($this->query), 'NO_BACKSLASH_ESCAPES')) {
            $this->owner->sqlMode = 'NO_BACKSLASH_ESCAPES';
            $this->rows = [];
        } elseif ($normalizedQuery === "SET SESSION sql_mode=''") {
            $this->owner->sqlMode = '';
            $this->rows = [];
        } elseif ($normalizedQuery === 'SET SESSION sql_mode = ?') {
            $this->owner->sqlMode = (string)($capturedBindings[1] ?? '');
            $this->rows = [];
        } elseif ($this->query === 'SELECT @@SESSION.sql_mode AS sql_mode') {
            $this->rows = [['sql_mode' => $this->owner->sqlMode]];
        } elseif (str_contains($this->query, 'pg_get_serial_sequence')) {
            $this->rows = [[
                'column_name' => $this->owner->primaryKeyColumn,
                'sequence_name' => $this->owner->sequenceName,
            ]];
        } elseif (str_contains($this->query, ' AS insert_id')) {
            $this->rows = [['insert_id' => $this->owner->insertedRowId]];
        } else {
            $this->rows = $this->owner->rowsByQuery[$this->query] ?? [];
        }
        $this->owner->lastBindings = $capturedBindings;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_BOTH) {
            return array_map(static function (array $row): array {
                $both = $row;
                foreach (array_values($row) as $index => $value) {
                    $both[$index] = $value;
                }
                return $both;
            }, $this->rows);
        }
        return $this->rows;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!isset($this->rows[0])) {
            return false;
        }
        $values = array_values($this->rows[0]);
        return $values[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->owner->affectedRows;
    }

    public function columnCount(): int
    {
        if (str_contains($this->query, ' AS insert_id')) {
            return 1;
        }
        if (!str_starts_with(strtoupper(ltrim($this->query)), 'SELECT')) {
            return 0;
        }
        $row = $this->owner->rowsByQuery[$this->query][0] ?? null;
        return is_array($row) && $row !== [] ? count($row) : 1;
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        return true;
    }
}

final class DropInFakePdo extends PDO
{
    public array $rowsByQuery = [];
    public array $lastBindings = [];
    public array $lastBindingTypes = [];
    public array $queryCalls = [];
    public int $affectedRows = 1;
    public array $transactionEvents = [];
    public ?string $transactionFailure = null;
    public int $quoteCalls = 0;
    public string $sqlMode = '';
    public string $sequenceName = 'public.synthetic_id_seq';
    public string $primaryKeyColumn = 'id';
    public string $insertedRowId = '23';
    public ?string $queryFailure = null;
    public ?string $prepareFailure = null;

    public function __construct(public string $driver = 'pgsql')
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_CLIENT_VERSION => 'synthetic-' . $this->driver,
            default => null,
        };
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->prepareFailure === $query) {
            $exception = new PDOException('synthetic raw driver detail');
            $exception->errorInfo = ['HY000', 1064, 'synthetic-secret detail'];
            throw $exception;
        }
        return new DropInFakePdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCalls[] = $query;
        $statement = new DropInFakePdoStatement($this, $query);
        $statement->execute();
        return $statement;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        $this->quoteCalls++;
        return "'" . str_replace("'", "\\'", $string) . "'";
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '42';
    }

    public function beginTransaction(): bool
    {
        $this->transactionEvents[] = 'begin';
        if ($this->transactionFailure === 'begin') {
            throw new PDOException('synthetic raw driver detail');
        }
        return true;
    }

    public function commit(): bool
    {
        $this->transactionEvents[] = 'commit';
        if ($this->transactionFailure === 'commit') {
            throw new PDOException('synthetic raw driver detail');
        }
        return true;
    }

    public function rollBack(): bool
    {
        $this->transactionEvents[] = 'rollback';
        if ($this->transactionFailure === 'rollback') {
            throw new PDOException('synthetic raw driver detail');
        }
        return true;
    }
}

function writeSyntheticConfig(string $driver, bool $includeDriver = true, ?string $directory = null, bool $emitOutput = false, bool $includeTrustedDomains = true, ?string $timezone = null): string
{
    $path = tempnam($directory ?? sys_get_temp_dir(), 'erp-p3-dropin-');
    if (!is_string($path)) {
        throw new RuntimeException('Não foi possível criar fixture temporária.');
    }
    chmod($path, 0600);
    $body = "<?php\n"
        . ($emitOutput ? "echo 'synthetic-only';\n" : '')
        . ($includeDriver ? "define('ERP_DB_DRIVER', " . var_export($driver, true) . ");\n" : '');
    if ($driver === 'mysql') {
        $body .= "define('ERP_DB_HOST', 'invalid host for synthetic test');\n"
            . "define('ERP_DB_NAME', 'synthetic');\n"
            . "define('ERP_DB_USER', 'synthetic');\n"
            . "define('ERP_DB_PASS', 'synthetic-only');\n"
            . "define('ERP_DB_CHARSET', 'utf8mb4');\n";
    }
    if ($driver === 'pgsql') {
        $body .= "define('ERP_PG_HOST', '127.0.0.1');\n"
            . "define('ERP_PG_PORT', 5432);\n"
            . "define('ERP_PG_NAME', 'synthetic');\n"
            . "define('ERP_PG_USER', 'synthetic');\n"
            . "define('ERP_PG_PASS', 'synthetic-only');\n"
            . "define('ERP_PG_SCHEMA', 'erp');\n";
        if ($includeTrustedDomains) {
            $body .= "define('ERP_TRUSTED_TENANT_DOMAINS', ['erpcondominios.com.br']);\n";
        }
    }
    if ($timezone !== null) {
        $body .= "define('ERP_TIMEZONE', " . var_export($timezone, true) . ");\n";
    }
    if (file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
        unlink($path);
        throw new RuntimeException('Não foi possível gravar fixture temporária.');
    }
    return $path;
}

function createLegacyFallbackTree(bool $emitOutput = false, int $permissions = 0600): string
{
    $root = sys_get_temp_dir() . '/erp-p3-legacy-fallback-' . bin2hex(random_bytes(8));
    $temporaryConfig = null;
    try {
        $directories = [
            $root,
            $root . '/worktrees',
            $root . '/worktrees/project',
            $root . '/worktrees/project/api',
            $root . '/worktrees/project/api/db',
            $root . '/worktrees/erpcondominios-private',
            $root . '/document-root',
        ];
        foreach ($directories as $directory) {
            if (!mkdir($directory, 0700)) {
                throw new RuntimeException('Não foi possível criar a árvore sintética de configuração.');
            }
        }
        if (!copy(__DIR__ . '/../api/config.php', $root . '/worktrees/project/api/config.php')
            || !copy(__DIR__ . '/../api/db/PrivateConfigPath.php', $root . '/worktrees/project/api/db/PrivateConfigPath.php')
            || !copy(__DIR__ . '/../api/db/DatabaseDriverSelector.php', $root . '/worktrees/project/api/db/DatabaseDriverSelector.php')
            || !copy(__DIR__ . '/../api/db/DatabaseTimezone.php', $root . '/worktrees/project/api/db/DatabaseTimezone.php')) {
            throw new RuntimeException('Não foi possível copiar os arquivos versionados de bootstrap.');
        }
        $temporaryConfig = writeSyntheticConfig('mysql', true, $root . '/worktrees/erpcondominios-private', $emitOutput);
        $fallbackConfig = $root . '/worktrees/erpcondominios-private/erp_config.php';
        if (!rename($temporaryConfig, $fallbackConfig)) {
            throw new RuntimeException('Não foi possível preparar o caminho de fallback sintético.');
        }
        if (!chmod($fallbackConfig, $permissions)
            || (fileperms($fallbackConfig) & 0777) !== ($permissions & 0777)) {
            throw new RuntimeException('Não foi possível configurar o modo sintético solicitado.');
        }
        return $root;
    } catch (Throwable $exception) {
        if (is_string($temporaryConfig) && is_file($temporaryConfig)) {
            unlink($temporaryConfig);
        }
        if (!removeLegacyFallbackTree($root)) {
            throw new RuntimeException('A limpeza da árvore sintética falhou.', 0, $exception);
        }
        throw $exception;
    }
}

function removeLegacyFallbackTree(string $root): bool
{
    $paths = [
            $root . '/worktrees/erpcondominios-private/erp_config.php',
            $root . '/document-root',
            $root . '/worktrees/erpcondominios-private',
            $root . '/worktrees/project/api/db/DatabaseTimezone.php',
            $root . '/worktrees/project/api/db/DatabaseDriverSelector.php',
            $root . '/worktrees/project/api/db/PrivateConfigPath.php',
        $root . '/worktrees/project/api/config.php',
        $root . '/worktrees/project/api/db',
        $root . '/worktrees/project/api',
        $root . '/worktrees/project',
        $root . '/worktrees',
        $root,
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            if (!unlink($path)) {
                return false;
            }
        } elseif (is_dir($path)) {
            if (!rmdir($path)) {
                return false;
            }
        }
    }
    return !file_exists($root);
}

function runChild(array $arguments, ?array $environment = null): array
{
    $extensionDir = ini_get('extension_dir');
    if (!is_string($extensionDir) || $extensionDir === '') {
        throw new RuntimeException('Diretório de extensões PHP indisponível.');
    }
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=pdo',
        '-d', 'extension=' . $extensionDir . '/mysqlnd.so',
        '-d', 'extension=' . $extensionDir . '/pdo_mysql.so',
        __FILE__,
        ...$arguments,
    ];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o processo PHP isolado.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [$status, trim((string)$stdout), trim((string)$stderr)];
}

function runAutoPrepend(?string $configPath): array
{
    $extensionDir = ini_get('extension_dir');
    if (!is_string($extensionDir) || $extensionDir === '') {
        throw new RuntimeException('Diretório de extensões PHP indisponível.');
    }
    $script = tempnam(sys_get_temp_dir(), 'erp-p3-autoprepend-');
    if (!is_string($script)) {
        throw new RuntimeException('Não foi possível criar script sintético de auto_prepend.');
    }
    chmod($script, 0600);
    $body = '<?php echo class_exists("mysqli", false) ? "AUTO_INSTALLED" : "MISSING";';
    if (file_put_contents($script, $body, LOCK_EX) !== strlen($body)) {
        unlink($script);
        throw new RuntimeException('Não foi possível gravar script sintético de auto_prepend.');
    }
    $command = [PHP_BINARY, '-n', '-d', 'extension=pdo', '-d', 'extension=' . $extensionDir . '/mysqlnd.so',
        '-d', 'extension=' . $extensionDir . '/pdo_mysql.so',
        '-d', 'auto_prepend_file=' . __DIR__ . '/../api/db/MysqliDropInAutoPrepend.php', $script];
    $pipes = [];
    $environment = ['ERP_CONFIG_PATH' => $configPath ?? ''];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        unlink($script);
        throw new RuntimeException('Não foi possível iniciar o auto_prepend isolado.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($script);
    return [$status, trim((string)$stdout), trim((string)$stderr)];
}

if (($argv[1] ?? '') === '--child-install') {
    putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    MysqliDropInBootstrap::install();
    assertDropIn(MysqliDropInBootstrap::installed(), 'Bootstrap deveria marcar instalação concluída.');
    $expectedDriver = $argv[3] ?? 'mysql';
    assertDropIn(MysqliDropInBootstrap::selectedDriver() === $expectedDriver, 'Bootstrap deve usar somente o driver configurado ou o default MySQL documentado.');
    assertDropIn(!extension_loaded('mysqli'), 'O processo filho não deveria carregar a extensão mysqli.');
    foreach ([
        'MYSQLI_ASSOC' => 1,
        'MYSQLI_NUM' => 2,
        'MYSQLI_BOTH' => 3,
        'MYSQLI_REPORT_OFF' => 0,
        'MYSQLI_REPORT_ERROR' => 1,
        'MYSQLI_REPORT_STRICT' => 2,
        'MYSQLI_STORE_RESULT' => 0,
        'MYSQLI_USE_RESULT' => 1,
    ] as $constant => $expected) {
        assertDropIn(defined($constant) && constant($constant) === $expected, $constant . ' incompatível.');
    }
    assertDropIn(class_exists('mysqli', false) && class_exists('mysqli_stmt', false)
        && class_exists('mysqli_result', false) && class_exists('mysqli_sql_exception', false), 'Aliases de classe ausentes.');
    foreach ([
        'mysqli_affected_rows', 'mysqli_close', 'mysqli_connect', 'mysqli_connect_error',
        'mysqli_character_set_name', 'mysqli_error', 'mysqli_fetch_assoc', 'mysqli_insert_id', 'mysqli_more_results',
        'mysqli_multi_query', 'mysqli_next_result', 'mysqli_num_rows', 'mysqli_prepare', 'mysqli_query',
        'mysqli_real_escape_string', 'mysqli_report', 'mysqli_set_charset', 'mysqli_store_result',
        'mysqli_stmt_affected_rows', 'mysqli_stmt_bind_param', 'mysqli_stmt_close', 'mysqli_stmt_execute',
        'mysqli_stmt_get_result', 'mysqli_stmt_num_rows', 'mysqli_stmt_send_long_data', 'mysqli_stmt_store_result',
    ] as $function) {
        assertDropIn(function_exists($function), 'Função do shim ausente.');
    }
    assertDropIn((new ReflectionFunction('mysqli_report'))->getNumberOfRequiredParameters() === 1, 'mysqli_report deve exigir flags como a API nativa.');
    assertDropIn(mysqli::class === 'mysqli', 'mysqli::class deve manter o nome legado.');
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = new mysqli('invalid host for synthetic test', 'synthetic', 'synthetic-only', 'synthetic');
    assertDropIn($connection instanceof mysqli, 'new mysqli deve retornar a fachada aliasada.');
    assertDropIn(acceptsMysqliType($connection), 'Type hint mysqli declarado antes do loader deve aceitar a facade.');
    assertDropIn($connection->connect_errno === 2002, 'Conexão sintética deve falhar com estado compatível.');
    assertDropIn($connection->connect_error === 'Falha ao estabelecer conexão com o banco de dados.', 'Erro de conexão deve ser sanitizado.');
    assertDropIn(!str_contains($connection->connect_error, 'synthetic-only'), 'Credenciais sintéticas não podem vazar na mensagem.');
    assertDropIn(mysqli_connect_error() === $connection->connect_error, 'mysqli_connect_error deve expor somente erro sanitizado.');
    assertDropIn(mysqli_connect('host alternativo', 'usuario alternativo', 'segredo alternativo', 'outro banco') === false, 'mysqli_connect deve retornar false com REPORT_OFF quando a conexão falha.');
    assertDropIn(mysqli_connect_error() === 'Falha ao estabelecer conexão com o banco de dados.', 'Erro global de conexão deve permanecer sanitizado.');
    $mysqlDestinationGuard = new ReflectionMethod(PdoMysqliConnection::class, 'assertMysqlArgumentsMatchConfig');
    $mysqlDestinationGuard->setAccessible(true);
    $mysqlDestinationGuard->invoke(null, [
        'mysql_host' => 'synthetic-db', 'mysql_user' => 'synthetic-user', 'mysql_password' => 'synthetic-secret',
        'mysql_name' => 'synthetic-db-name', 'mysql_port' => 3306,
    ], 'synthetic-db', 'synthetic-user', 'synthetic-secret', 'synthetic-db-name', null, null);
    $mysqlDestinationRejected = false;
    try {
        $mysqlDestinationGuard->invoke(null, [
            'mysql_host' => 'synthetic-db', 'mysql_user' => 'synthetic-user', 'mysql_password' => 'synthetic-secret',
            'mysql_name' => 'synthetic-db-name', 'mysql_port' => 3306,
        ], 'other-db', 'synthetic-user', 'synthetic-secret', 'synthetic-db-name', null, null);
    } catch (ReflectionException|RuntimeException) {
        $mysqlDestinationRejected = true;
    }
    assertDropIn($mysqlDestinationRejected, 'Construtor MySQL não pode conectar a host diferente do perfil.');
    $pgDestinationGuard = new ReflectionMethod(PdoMysqliConnection::class, 'assertPostgresArgumentsMatchConfig');
    $pgDestinationGuard->setAccessible(true);
    $pgConfig = [
        'pg_host' => '127.0.0.1', 'pg_user' => 'synthetic-user', 'pg_password' => 'synthetic-secret',
        'pg_name' => 'synthetic-db-name', 'pg_port' => 5432,
    ];
    $pgDestinationGuard->invoke(null, $pgConfig, '127.0.0.1', 'synthetic-user', 'synthetic-secret', 'synthetic-db-name', null, null);
    $pgDestinationRejected = false;
    try {
        $pgDestinationGuard->invoke(null, $pgConfig, 'other-host', 'synthetic-user', 'synthetic-secret', 'synthetic-db-name', null, null);
    } catch (ReflectionException|RuntimeException) {
        $pgDestinationRejected = true;
    }
    assertDropIn($pgDestinationRejected, 'Construtor PostgreSQL não pode conectar a host diferente do perfil.');
    $connectErrnoMethod = new ReflectionMethod(PdoMysqliConnection::class, 'connectErrnoFromThrowable');
    $connectErrnoMethod->setAccessible(true);
    $pdoException = new PDOException('synthetic-secret');
    $pdoException->errorInfo = ['HY000', 1045, 'synthetic-secret'];
    assertDropIn($connectErrnoMethod->invoke(null, $pdoException) === 1045, 'errno PDO deve preservar somente o código numérico do driver.');
    assertDropIn($connectErrnoMethod->invoke(null, new RuntimeException('synthetic-secret')) === 2002, 'Falha sem errno do driver deve usar o código genérico de conexão.');

    $result = new ERP\Database\PdoMysqliResult([['valor' => 'fixture']]);
    assertDropIn($result instanceof mysqli_result, 'Resultset deve satisfazer identidade mysqli_result.');
    assertDropIn(acceptsMysqliResultType($result), 'Type hint mysqli_result deve aceitar a facade bufferizada.');
    assertDropIn(mysqli_num_rows($result) === 1, 'mysqli_num_rows deve ler contagem do resultset sintético.');
    assertDropIn(mysqli_fetch_assoc($result) === ['valor' => 'fixture'], 'mysqli_fetch_assoc deve retornar a linha sintética.');

    $pdo = new DropInFakePdo();
    $pdo->rowsByQuery['SELECT ? AS valor'] = [['valor' => '19']];
    $innerConnection = new PgsqlMysqliConnection($pdo);
    $sessionSetup = new ReflectionMethod(PdoMysqliConnection::class, 'configurePostgresSession');
    $sessionSetup->setAccessible(true);
    $pdo->queryCalls = [];
    $sessionSetup->invoke(null, $innerConnection, ['pg_schema' => 'erp', 'pg_timezone' => 'America/Sao_Paulo']);
    assertDropIn($pdo->queryCalls === ['SET search_path TO "erp"', "SET TIME ZONE 'America/Sao_Paulo'"], 'Conexão PostgreSQL deve fixar schema e timezone para todo caller, inclusive auditoria direta.');
    $connectionReflection = new ReflectionClass(ERP\Database\PdoMysqliConnection::class);
    $pgFacadeConnection = $connectionReflection->newInstanceWithoutConstructor();
    $pgsqlProperty = $connectionReflection->getProperty('pgsql');
    $pgsqlProperty->setValue($pgFacadeConnection, $innerConnection);
    $driverProperty = $connectionReflection->getProperty('selectedDriver');
    $driverProperty->setValue($pgFacadeConnection, 'pgsql');
    $wrappedStatement = mysqli_prepare($pgFacadeConnection, 'SELECT ? AS valor');
    assertDropIn($wrappedStatement instanceof mysqli_stmt, 'Statement deve satisfazer identidade mysqli_stmt.');
    assertDropIn(acceptsMysqliStatementType($wrappedStatement), 'Type hint mysqli_stmt deve aceitar a facade do statement.');
    $boundValue = 7;
    assertDropIn(mysqli_stmt_bind_param($wrappedStatement, 'i', $boundValue), 'Binding procedural por referência deveria funcionar.');
    $boundValue = 19;
    assertDropIn(mysqli_stmt_execute($wrappedStatement), 'Execução procedural sintética deveria funcionar.');
    assertDropIn($wrappedStatement->affected_rows === 1, 'Statement sintético deveria preservar affected_rows.');
    $statementResult = mysqli_stmt_get_result($wrappedStatement);
    assertDropIn($statementResult instanceof mysqli_result, 'get_result deve retornar mysqli_result aliasado.');
    assertDropIn(mysqli_fetch_assoc($statementResult) === ['valor' => '19'], 'Fetch procedural deveria recuperar a linha sintética.');
    $insertStatement = mysqli_prepare($pgFacadeConnection, 'INSERT INTO synthetic(value) VALUES (?)');
    $insertValue = 'fixture';
    assertDropIn(mysqli_stmt_bind_param($insertStatement, 's', $insertValue), 'INSERT PostgreSQL deve aceitar binding procedural.');
    assertDropIn(mysqli_stmt_execute($insertStatement), 'INSERT PostgreSQL sem RETURNING deve ser executado.');
    assertDropIn(mysqli_insert_id($pgFacadeConnection) === 23, 'mysqli_insert_id PostgreSQL deve usar a PK retornada internamente para INSERT simples.');
    assertDropIn($insertStatement->field_count === 0 && mysqli_stmt_get_result($insertStatement) === false, 'RETURNING auxiliar do adapter não deve aparecer como resultset público.');

    $mysqlPdo = new DropInFakePdo('mysql');
    $mysqlPdo->rowsByQuery['SELECT ? AS valor'] = [['valor' => '31']];
    $mysqlPdo->rowsByQuery['SELECT valor'] = [['valor' => 'query-row']];
    $mysqlConnection = $connectionReflection->newInstanceWithoutConstructor();
    $clientInfoMethod = new ReflectionMethod(PdoMysqliConnection::class, 'clientInfo');
    assertDropIn($clientInfoMethod->invoke(null, $mysqlPdo) === 'synthetic-mysql', 'client_info MySQL deve usar PDO::ATTR_CLIENT_VERSION.');
    assertDropIn(property_exists(PdoMysqliConnection::class, 'client_info'), 'Facade deve expor a propriedade client_info usada pelo código legado.');
    $pdoProperty = $connectionReflection->getProperty('pdo');
    $pdoProperty->setValue($mysqlConnection, $mysqlPdo);
    $driverProperty->setValue($mysqlConnection, 'mysql');
    assertDropIn($mysqlConnection->begin_transaction() && $mysqlConnection->commit() && $mysqlConnection->rollback(), 'Transações procedural MySQL deveriam delegar ao PDO.');
    assertDropIn($mysqlPdo->transactionEvents === ['begin', 'commit', 'rollback'], 'Ordem e quantidade de operações transacionais incorretas.');
    $mysqlPdo->transactionFailure = 'begin';
    assertDropIn($mysqlConnection->begin_transaction() === false, 'Falha PDO de transação deve resultar em false com REPORT_OFF.');
    assertDropIn(!str_contains($mysqlConnection->error, 'synthetic raw driver detail'), 'Texto bruto de exceção não pode sair na propriedade error.');
    $mysqlPdo->transactionFailure = null;
    $placeholderSql = "SELECT '?' AS a, ? AS b, \"?\" AS c, `?` AS d -- ?\n, /* ? */ ?";
    $placeholderStatement = $mysqlConnection->preparePdoStatement($mysqlPdo->prepare($placeholderSql), $placeholderSql);
    assertDropIn($placeholderStatement->param_count === 2, 'Contador MySQL deve ignorar placeholders em literais e comentários.');
    $noBackslashSql = "SELECT 'a\\' AS texto, ? AS valor";
    $noBackslashMode = $connectionReflection->getProperty('mysqlNoBackslashEscapes');
    $noBackslashMode->setValue($mysqlConnection, true);
    $noBackslashStatement = $mysqlConnection->preparePdoStatement($mysqlPdo->prepare($noBackslashSql), $noBackslashSql);
    assertDropIn($noBackslashStatement->param_count === 1, 'Parser deve respeitar SQL_MODE NO_BACKSLASH_ESCAPES.');
    $quoteCallsBeforeNoBackslashEscape = $mysqlPdo->quoteCalls;
    assertDropIn(
        mysqli_real_escape_string($mysqlConnection, "O'Reilly\\safe") === "O''Reilly\\safe",
        'real_escape_string deve duplicar aspas e preservar barras no modo NO_BACKSLASH_ESCAPES.'
    );
    assertDropIn($mysqlPdo->quoteCalls === $quoteCallsBeforeNoBackslashEscape, 'NO_BACKSLASH_ESCAPES não deve delegar a PDO::quote.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "invalid\0value") === false, 'NUL deve falhar fechado no escape MySQL.');
    echo "PASS real_escape_string NO_BACKSLASH_ESCAPES\n";
    $noBackslashMode->setValue($mysqlConnection, false);
    $defaultModeStatement = $mysqlConnection->preparePdoStatement($mysqlPdo->prepare($noBackslashSql), $noBackslashSql);
    assertDropIn($defaultModeStatement->param_count === 0, 'Parser deve respeitar escapes por barra invertida no modo MySQL padrão.');
    assertDropIn(mysqli_set_charset($mysqlConnection, 'utf8mb4'), 'mysqli_set_charset deve ser suportado.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly") === "O\\'Reilly", 'real_escape_string deve delegar ao quote do driver.');
    assertDropIn($mysqlConnection->query("SET /* interno */ SESSION sql_mode='NO_BACKSLASH_ESCAPES'") === true, 'Query direta com comentário entre tokens deve permitir fixture de alteração de SQL mode.');
    $mysqlPdo->queryCalls = [];
    $mysqlPdo->queryFailure = 'SELECT @@SESSION.sql_mode AS sql_mode';
    assertDropIn(mysqli_multi_query($mysqlConnection, "SELECT 'a\\'; SELECT 2") === false
        && $mysqlPdo->queryCalls === ['SELECT @@SESSION.sql_mode AS sql_mode'], 'Falha no refresh deve impedir que multi-query envie qualquer statement do lote.');
    $mysqlPdo->queryFailure = null;
    $mysqlPdo->queryCalls = [];
    assertDropIn(mysqli_multi_query($mysqlConnection, "SELECT 'a\\'; SELECT 2"), 'multi_query deve sincronizar SQL mode antes de dividir statements.');
    assertDropIn($mysqlPdo->queryCalls === ['SELECT @@SESSION.sql_mode AS sql_mode', "SELECT 'a\\'"], 'O divisor deve reconhecer barra literal antes da aspa em NO_BACKSLASH_ESCAPES.');
    assertDropIn(mysqli_next_result($mysqlConnection) && $mysqlPdo->queryCalls === ['SELECT @@SESSION.sql_mode AS sql_mode', "SELECT 'a\\'", 'SELECT 2'], 'multi_query deve avançar para a segunda consulta com o modo sincronizado.');
    assertDropIn(mysqli_more_results($mysqlConnection) === false, 'Sequência multi-query deve ser finalizada após a segunda consulta.');
    assertDropIn($mysqlConnection->query("SET SESSION sql_mode=''") === true, 'Query direta deve permitir fixture de retorno ao SQL mode padrão.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly") === "O\\'Reilly" && !$mysqlConnection->mysqlUsesNoBackslashEscapes(), 'Escape deve sincronizar novamente o modo padrão após query direta.');
    assertDropIn($mysqlConnection->query("/*!50000 SET SESSION sql_mode='NO_BACKSLASH_ESCAPES' */") === true, 'Fake deve modelar comentário executável MySQL que altera o SQL mode.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly\\safe") === "O''Reilly\\safe" && $mysqlConnection->mysqlUsesNoBackslashEscapes(), 'Comentário executável com sql_mode deve invalidar e atualizar o modo antes do escape.');
    assertDropIn($mysqlConnection->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'") === true, 'Query direta deve permitir fixture de alteração de SQL mode.');
    $mysqlPdo->queryCalls = [];
    assertDropIn(mysqli_multi_query($mysqlConnection, "SET SESSION sql_mode=''; SELECT 1") === false
        && $mysqlPdo->queryCalls === ['SELECT @@SESSION.sql_mode AS sql_mode'], 'Multi-query que altera sql_mode deve ser recusada após refresh e antes de enviar o lote.');
    $mysqlConnection->clearError();
    assertDropIn($mysqlConnection->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'") === true, 'Query deve marcar o SQL mode novamente antes da falha de refresh.');
    $mysqlPdo->queryFailure = 'SELECT @@SESSION.sql_mode AS sql_mode';
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly") === false
        && $mysqlConnection->errno === 1205 && !str_contains($mysqlConnection->error, 'synthetic-secret'), 'Falha ao atualizar SQL mode deve abortar o escape sem expor erro bruto (errno=' . $mysqlConnection->errno . '; ' . $mysqlConnection->error . ').');
    $mysqlPdo->queryFailure = null;
    $quoteCallsBeforeModeSwitch = $mysqlPdo->quoteCalls;
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly\\safe") === "O''Reilly\\safe", 'Escape deve atualizar o modo após SET SESSION sql_mode direto.');
    assertDropIn($mysqlConnection->mysqlUsesNoBackslashEscapes() && $mysqlPdo->quoteCalls === $quoteCallsBeforeModeSwitch, 'Modo atualizado deve evitar PDO::quote com backslash escapes.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "invalid\0value") === false
        && $mysqlConnection->errno === 1 && $mysqlConnection->error !== '', 'NUL deve falhar fechado e registrar errno/error.');
    mysqli_report(MYSQLI_REPORT_STRICT);
    $strictNulException = null;
    try {
        mysqli_real_escape_string($mysqlConnection, "invalid\0value");
    } catch (mysqli_sql_exception $exception) {
        $strictNulException = $exception;
    }
    assertDropIn($strictNulException instanceof mysqli_sql_exception, 'REPORT_STRICT deve lançar exceção ao escapar NUL.');
    mysqli_report(MYSQLI_REPORT_OFF);
    assertDropIn(mysqli_real_escape_string($mysqlConnection, 'safe') === 'safe'
        && $mysqlConnection->errno === 0 && $mysqlConnection->error === '', 'Escape válido deve limpar o erro anterior.');
    $defaultSqlModeStatement = $mysqlConnection->prepare('SET SESSION sql_mode = ?');
    assertDropIn($defaultSqlModeStatement instanceof mysqli_stmt && $defaultSqlModeStatement->execute(['']), 'Statement preparado deve permitir fixture de retorno ao SQL mode padrão.');
    assertDropIn(mysqli_real_escape_string($mysqlConnection, "O'Reilly\\safe") === "O\\'Reilly\\safe"
        && !$mysqlConnection->mysqlUsesNoBackslashEscapes(), 'Escape deve atualizar o modo após SET SESSION sql_mode preparado.');
    $queryResult = mysqli_query($mysqlConnection, 'SELECT valor');
    assertDropIn($queryResult instanceof mysqli_result, 'mysqli_query de leitura deve retornar mysqli_result.');
    assertDropIn(mysqli_num_rows($queryResult) === 1 && mysqli_fetch_assoc($queryResult) === ['valor' => 'query-row'], 'Funções procedurais de fetch devem funcionar.');
    assertDropIn(mysqli_query($mysqlConnection, 'SELECT valor', MYSQLI_USE_RESULT) === false, 'Modo streaming não suportado deve falhar sem banco.');
    assertDropIn(mysqli_error($mysqlConnection) !== '', 'mysqli_error deve retornar erro sanitizado do último comando.');
    mysqli_report(MYSQLI_REPORT_STRICT);
    $strictException = null;
    try {
        mysqli_query($mysqlConnection, 'SELECT valor', MYSQLI_USE_RESULT);
    } catch (mysqli_sql_exception $exception) {
        $strictException = $exception;
    }
    assertDropIn($strictException instanceof mysqli_sql_exception && $strictException->getSqlState() === 'HY000', 'Alias mysqli_sql_exception deve preservar getSqlState no modo strict.');
    $sanitizedException = null;
    try {
        ERP\Database\MysqliDropInState::reportFailure('synthetic-secret SQLSTATE 23505 raw-detail', 1);
    } catch (mysqli_sql_exception $exception) {
        $sanitizedException = $exception;
    }
    assertDropIn($sanitizedException instanceof mysqli_sql_exception
        && $sanitizedException->getSqlState() === '23505'
        && !str_contains($sanitizedException->getMessage(), 'synthetic-secret'), 'Exceção procedural deve expor apenas mensagem sanitizada e SQLSTATE válido.');
    mysqli_report(MYSQLI_REPORT_OFF);
    assertDropIn(mysqli_query($mysqlConnection, 'UPDATE synthetic SET value=1') === true, 'mysqli_query de escrita deve retornar true.');
    assertDropIn(mysqli_affected_rows($mysqlConnection) === 1, 'mysqli_affected_rows deve refletir o comando executado.');
    assertDropIn(mysqli_query($mysqlConnection, 'INSERT INTO synthetic(value) VALUES (1)') === true, 'INSERT direto deve ser executado pelo PDO fake.');
    assertDropIn(mysqli_insert_id($mysqlConnection) === 42, 'mysqli_insert_id deve usar o último ID do driver MySQL.');
    $mysqlWrappedStatement = mysqli_prepare($mysqlConnection, 'SELECT ? AS valor');
    $mysqlBoundValue = 31;
    assertDropIn(mysqli_stmt_bind_param($mysqlWrappedStatement, 'i', $mysqlBoundValue), 'MySQL PDO bind_param deve aceitar a variável por referência.');
    assertDropIn(mysqli_stmt_execute($mysqlWrappedStatement), 'MySQL PDO execute deve funcionar com statement sintético.');
    assertDropIn($mysqlPdo->lastBindings === [1 => 31], 'Binding MySQL deve preservar índice e valor.');
    assertDropIn(mysqli_stmt_store_result($mysqlWrappedStatement), 'store_result deve concluir o buffer em statement MySQL.');
    assertDropIn(mysqli_stmt_num_rows($mysqlWrappedStatement) === 1, 'stmt_num_rows deve retornar o total bufferizado.');
    assertDropIn(mysqli_stmt_affected_rows($mysqlWrappedStatement) === 1, 'stmt_affected_rows deve expor linhas sintéticas afetadas.');
    $mysqlResult = mysqli_stmt_get_result($mysqlWrappedStatement);
    assertDropIn($mysqlResult instanceof mysqli_result, 'Statement MySQL deve retornar o alias global mysqli_result.');
    assertDropIn(mysqli_fetch_assoc($mysqlResult) === ['valor' => '31'], 'Fetch MySQL PDO deve manter nomes associativos.');
    $blobStatement = mysqli_prepare($mysqlConnection, 'INSERT INTO synthetic(payload) VALUES (?)');
    $blobPlaceholder = null;
    assertDropIn(mysqli_stmt_bind_param($blobStatement, 'b', $blobPlaceholder), 'Binding BLOB deve aceitar parâmetro binário por referência.');
    assertDropIn(mysqli_stmt_send_long_data($blobStatement, 0, "\x00\x01A")
        && mysqli_stmt_send_long_data($blobStatement, 0, "B\xFF"), 'send_long_data deve acumular fragmentos binários sem conversão de texto.');
    assertDropIn(mysqli_stmt_execute($blobStatement), 'Statement BLOB MySQL deve executar com stream temporário.');
    assertDropIn($mysqlPdo->lastBindings === [1 => "\x00\x01AB\xFF"]
        && $mysqlPdo->lastBindingTypes === [1 => PDO::PARAM_LOB], 'send_long_data deve preservar bytes e PDO::PARAM_LOB.');
    assertDropIn(mysqli_stmt_send_long_data($blobStatement, 0, 'x') && mysqli_stmt_execute($blobStatement), 'Statement reutilizado deve iniciar um buffer binário limpo.');
    assertDropIn($mysqlPdo->lastBindings === [1 => 'x'], 'Execução reutilizada não deve carregar bytes do ciclo BLOB anterior.');
    assertDropIn($mysqlConnection->character_set_name() === 'utf8mb4', 'character_set_name deve refletir o charset inicial MySQL.');
    assertDropIn(mysqli_character_set_name($mysqlConnection) === 'utf8mb4', 'mysqli_character_set_name deve delegar ao charset ativo.');
    assertDropIn($mysqlConnection->set_charset('utf8') && $mysqlConnection->character_set_name() === 'utf8', 'set_charset e character_set_name devem permanecer sincronizados.');
    $mysqlPdo->queryCalls = [];
    $mysqlPdo->rowsByQuery['SELECT 1 AS value'] = [['value' => 1]];
    $multiSql = "UPDATE synthetic SET value='semi;colon'; SELECT 1 AS value; UPDATE synthetic SET value=2";
    assertDropIn(mysqli_multi_query($mysqlConnection, $multiSql), 'multi_query MySQL deve iniciar a sequência de statements.');
    assertDropIn($mysqlPdo->queryCalls === ["UPDATE synthetic SET value='semi;colon'"], 'O separador dentro de string não deve dividir statement.');
    assertDropIn(mysqli_store_result($mysqlConnection) === false && mysqli_more_results($mysqlConnection), 'Primeiro resultado DML deve permitir avançar.');
    assertDropIn($mysqlConnection->query('SELECT blocked') === false && $mysqlConnection->errno === 2014, 'Consulta concorrente deve ser bloqueada com resultados multi-query pendentes.');
    assertDropIn($mysqlConnection->commit() === false && $mysqlConnection->errno === 2014, 'Commit deve ser bloqueado enquanto há resultados multi-query pendentes.');
    assertDropIn(mysqli_next_result($mysqlConnection), 'next_result deve avançar para o SELECT.');
    $multiResult = mysqli_store_result($mysqlConnection);
    assertDropIn($multiResult instanceof mysqli_result && $multiResult->fetch_assoc() === ['value' => 1], 'store_result deve entregar o resultset buffered atual.');
    assertDropIn(mysqli_next_result($mysqlConnection) && mysqli_store_result($mysqlConnection) === false, 'O último DML deve ser consumido como resultado sem colunas.');
    assertDropIn(!mysqli_more_results($mysqlConnection), 'more_results deve terminar e liberar a sequência.');
    assertDropIn($mysqlPdo->queryCalls === ["UPDATE synthetic SET value='semi;colon'", 'SELECT 1 AS value', 'UPDATE synthetic SET value=2'], 'Statements multi-query devem executar exatamente uma vez e na ordem recebida.');
    $mysqlPdo->queryCalls = [];
    $mysqlPdo->queryFailure = 'SELECT failing';
    assertDropIn(mysqli_multi_query($mysqlConnection, 'SELECT 1 AS value; SELECT failing; SELECT 2') , 'Multi-query deve iniciar antes do statement posterior falhar.');
    assertDropIn(mysqli_store_result($mysqlConnection) instanceof mysqli_result && mysqli_more_results($mysqlConnection), 'Primeiro resultset deve continuar legível antes de avançar.');
    assertDropIn(mysqli_next_result($mysqlConnection) === false && !mysqli_more_results($mysqlConnection), 'Falha intermediária deve encerrar a sequência sem deixar resultados pendentes.');
    assertDropIn($mysqlConnection->error !== '' && !str_contains($mysqlConnection->error, 'synthetic-secret'), 'Erro intermediário deve ser preservado apenas em forma sanitizada.');
    $mysqlPdo->queryFailure = null;
    assertDropIn($pgFacadeConnection->multi_query('SELECT 1') === false, 'multi_query deve falhar fechado no driver PostgreSQL.');
    $mysqlPdo->rowsByQuery['SELECT left_col, right_col'] = [['left_col' => 'alpha', 'right_col' => 'beta']];
    $boundResultStatement = mysqli_prepare($mysqlConnection, 'SELECT left_col, right_col');
    assertDropIn(mysqli_stmt_execute($boundResultStatement), 'SELECT sem parâmetros deve executar em PDO MySQL.');
    $left = $right = null;
    assertDropIn($boundResultStatement->bind_result($left, $right), 'bind_result MySQL PDO deve aceitar referências compatíveis.');
    assertDropIn($boundResultStatement->fetch() && $left === 'alpha' && $right === 'beta', 'fetch MySQL PDO deve preencher as referências na ordem das colunas.');
    $mysqlPdo->rowsByQuery['SELECT 1'] = [['1' => 1]];
    assertDropIn($mysqlConnection->ping(), 'ping MySQL PDO deve executar consulta de saúde sintética.');
    $mysqlPdo->queryFailure = 'SELECT query-failure';
    assertDropIn($mysqlConnection->query('SELECT query-failure') === false, 'Erro PDO de query deve retornar false com REPORT_OFF.');
    assertDropIn(!str_contains($mysqlConnection->error, 'synthetic-secret') && !str_contains($mysqlConnection->error, 'raw driver detail'), 'Erro SQL MySQL não pode expor mensagem bruta nem valor sintético.');
    $mysqlPdo->queryFailure = null;
    $mysqlPdo->prepareFailure = 'SELECT prepare-failure';
    assertDropIn($mysqlConnection->prepare('SELECT prepare-failure') === false, 'Erro PDO de prepare deve retornar false com REPORT_OFF.');
    assertDropIn(!str_contains($mysqlConnection->error, 'synthetic-secret') && !str_contains($mysqlConnection->error, 'raw driver detail'), 'Falha de prepare não pode expor mensagem bruta.');
    $mysqlPdo->prepareFailure = null;
    assertDropIn(mysqli_stmt_execute($mysqlWrappedStatement, []) === false, 'Execute MySQL com cardinalidade incorreta deve falhar.');
    assertDropIn($mysqlWrappedStatement->num_rows === 0 && $mysqlWrappedStatement->field_count === 0
        && $mysqlWrappedStatement->insert_id === 0 && mysqli_stmt_get_result($mysqlWrappedStatement) === false,
        'Falha de validação MySQL não pode manter resultado ou ID anterior.');
    $mysqlPdo->rowsByQuery['SELECT ? AS valor'] = [['valor' => null]];
    $nullableValue = null;
    assertDropIn(mysqli_stmt_bind_param($mysqlWrappedStatement, 's', $nullableValue), 'Binding nullable por referência deveria ser aceito.');
    assertDropIn(mysqli_stmt_execute($mysqlWrappedStatement), 'Statement nullable deveria executar.');
    assertDropIn($mysqlPdo->lastBindings === [1 => null] && $mysqlPdo->lastBindingTypes === [1 => PDO::PARAM_NULL], 'Valor null deve usar PDO::PARAM_NULL.');
    assertDropIn(mysqli_stmt_close($mysqlWrappedStatement), 'mysqli_stmt_close deve fechar o statement.');
    assertDropIn(mysqli_close($mysqlConnection), 'mysqli_close deve fechar a conexão.');
echo "PASS aliases, constantes, funções procedurais, bindings e resultsets sem mysqli\n";
    exit(0);
}

if (($argv[1] ?? '') === '--child-pg-fail-closed') {
    putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    $rejectedForMissingPdoPgsql = false;
    try {
        MysqliDropInBootstrap::install();
    } catch (RuntimeException $exception) {
        $rejectedForMissingPdoPgsql = str_contains($exception->getMessage(), 'driver PDO selecionado indisponível');
    }
    assertDropIn($rejectedForMissingPdoPgsql, 'Fixture PostgreSQL válida deve falhar especificamente por ausência do driver PDO.');
    assertDropIn(!MysqliDropInBootstrap::installed(), 'Bootstrap não pode marcar shim parcialmente instalado.');
    assertDropIn(!class_exists('mysqli', false) && !function_exists('mysqli_query') && !defined('MYSQLI_ASSOC'), 'Falha deve ocorrer antes de instalar aliases/símbolos.');
    echo "PASS falha fechada sem pdo_pgsql sem aliases parciais\n";
    exit(0);
}

if (($argv[1] ?? '') === '--child-config-fail-closed') {
    if (($argv[2] ?? '') === '__UNSET__') {
        putenv('ERP_CONFIG_PATH');
    } else {
        putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    }
    if (isset($argv[3]) && $argv[3] !== '') {
        $_SERVER['DOCUMENT_ROOT'] = $argv[3];
    }
    $failed = false;
    try {
        MysqliDropInBootstrap::install();
    } catch (Throwable) {
        $failed = true;
    }
    assertDropIn($failed, 'Configuração ausente ou driver inválido deveria falhar fechado.');
    assertDropIn(!MysqliDropInBootstrap::installed(), 'Configuração inválida não pode marcar instalação concluída.');
    assertDropIn(!class_exists('mysqli', false) && !function_exists('mysqli_query') && !defined('MYSQLI_ASSOC'), 'Falha de configuração não pode instalar aliases parciais.');
    echo "PASS configuração/driver inválidos falham fechados sem aliases\n";
    exit(0);
}

if (($argv[1] ?? '') === '--child-install-env') {
    putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    $failedForMissingPgDriver = false;
    try {
        MysqliDropInBootstrap::install();
    } catch (RuntimeException $exception) {
        $failedForMissingPgDriver = str_contains($exception->getMessage(), 'driver PDO selecionado indisponível');
    }
    assertDropIn($failedForMissingPgDriver
        && MysqliDropInBootstrap::selectedDriver() === 'pgsql'
        && !MysqliDropInBootstrap::installed()
        && !class_exists('mysqli', false), 'Auto-prepend deve respeitar o driver ambiental PG e falhar fechado antes de instalar aliases.');
    echo "PASS seletor de driver ambiental PG alinhado ao auto-prepend e fail-closed\n";
    exit(0);
}

if (($argv[1] ?? '') === '--child-api-config') {
    putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    $expectedDriver = $argv[3] ?? '';
    if ($expectedDriver === 'pgsql') {
        $installed = new ReflectionProperty(MysqliDropInBootstrap::class, 'installed');
        $installed->setValue(null, true);
    }
    require __DIR__ . '/../api/config.php';
    assertDropIn($expectedDriver === 'pgsql'
        ? (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')
            && !defined('ERP_DB_HOST') && !defined('ERP_DB_PASS') && defined('ERP_PG_PASS')
            && DB_HOST === ERP_PG_HOST && DB_PASS === ERP_PG_PASS)
        : (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')
            && defined('ERP_DB_HOST') && DB_HOST === ERP_DB_HOST && DB_PASS === ERP_DB_PASS),
        'Bootstrap API deve manter credenciais separadas e não publicar aliases MySQL no perfil PostgreSQL.');
    echo "PASS configuração API por driver sem conexão\n";
    exit(0);
}

if (($argv[1] ?? '') === '--child-api-config-output') {
    putenv('ERP_CONFIG_PATH=' . ($argv[2] ?? ''));
    register_shutdown_function(static function (): void {
        echo '|HTTP_STATUS=' . http_response_code();
    });
    require __DIR__ . '/../api/config.php';
    echo 'UNEXPECTED_CONFIG_CONTINUE';
    exit(0);
}

if (($argv[1] ?? '') === '--child-api-config-invalid-explicit-path') {
    putenv('ERP_CONFIG_PATH=/path/that/does/not/exist/erp-config.php');
    require __DIR__ . '/../api/config.php';
    echo 'UNEXPECTED_FALLBACK';
    exit(0);
}

if (($argv[1] ?? '') === '--child-api-config-legacy-fallback') {
    $fallbackRoot = $argv[2] ?? '';
    putenv('ERP_CONFIG_PATH');
    $_SERVER['DOCUMENT_ROOT'] = $argv[3] ?? ($fallbackRoot . '/document-root');
    register_shutdown_function(static function (): void {
        echo '|HTTP_STATUS=' . http_response_code();
    });
    require $fallbackRoot . '/worktrees/project/api/config.php';
    assertDropIn(defined('DB_HOST') && DB_HOST === ERP_DB_HOST && defined('ERP_DB_PASS'), 'Configuração fallback deve ser carregada do caminho sintético esperado.');
    echo 'PASS fallback MySQL resolvido sem conexão';
    exit(0);
}

require_once __DIR__ . '/../api/db/PrivateConfigPath.php';

if (extension_loaded('mysqli')) {
    $nativeExtensionRejected = false;
    try {
        MysqliDropInBootstrap::assertSymbolsAvailable();
    } catch (RuntimeException $exception) {
        $nativeExtensionRejected = str_contains($exception->getMessage(), 'a extensão mysqli está carregada');
    }
    assertDropIn($nativeExtensionRejected, 'A preflight deve recusar especificamente a extensão mysqli carregada.');
    assertDropIn(!MysqliDropInBootstrap::installed(), 'Conflito não pode deixar shim parcialmente instalado.');
    echo "PASS extensão mysqli nativa detectada e instalação recusada\n";
} else {
    MysqliDropInBootstrap::assertSymbolsAvailable();
    echo "PASS runtime sem mysqli disponível para instalação\n";
}

$splitMysqlStatements = new ReflectionMethod(PdoMysqliConnection::class, 'splitMysqlStatements');
$splitMysqlStatements->setAccessible(true);
assertDropIn($splitMysqlStatements->invoke(null, '/*!40101 SET NAMES utf8mb4 */; SELECT 1', false) === ['/*!40101 SET NAMES utf8mb4 */', 'SELECT 1'], 'Comentário executável benigno deve continuar compatível com scripts MySQL.');
assertDropIn($splitMysqlStatements->invoke(null, "-- seed\nCREATE DEFINER=`app`@`localhost` PROCEDURE p() BEGIN SELECT 1; END", false) === null, 'Stored procedure com DEFINER e comentário deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, '/* seed */ CREATE FUNCTION f() RETURNS INT RETURN 1;', false) === null, 'Stored function após comentário deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, 'CALL p()', false) === null, 'Chamada a stored procedure deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, '/*!50003 CREATE PROCEDURE p() BEGIN SELECT 1; END */', false) === null, 'DDL de stored program em comentário executável deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, '/*!50003 CALL p() */', false) === null, 'CALL em comentário executável MySQL deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, '/*M!100100 CALL p() */', false) === null, 'CALL em comentário executável MariaDB deve falhar fechado.');
assertDropIn($splitMysqlStatements->invoke(null, 'DELIMITER //; SELECT 1', false) === null, 'Diretiva de cliente DELIMITER deve falhar fechado.');
$timezoneAccepted = static function (mixed $configured): bool {
    try {
        \ERP\Database\DatabaseTimezone::resolve($configured);
        return true;
    } catch (Throwable) {
        return false;
    }
};
assertDropIn($timezoneAccepted('UTC'), 'Timezone UTC deve ser aceito.');
assertDropIn($timezoneAccepted('America/Sao_Paulo'), 'Zona regional IANA deve ser aceita.');
assertDropIn(!$timezoneAccepted('Etc/GMT+5'), 'Alias Etc/GMT+5 de offset fixo deve ser recusado.');
assertDropIn(!$timezoneAccepted('Etc/GMT-5'), 'Alias Etc/GMT-5 de offset fixo deve ser recusado.');
assertDropIn(!$timezoneAccepted('Etc/UTC'), 'Alias Etc/UTC deve ser recusado pela política de timezone.');
$documentRootCheck = new ReflectionMethod(\ERP\Database\PrivateConfigPath::class, 'isOutsideDocumentRoot');
$documentRootCheck->setAccessible(true);
assertDropIn($documentRootCheck->invoke(null, '/srv/private/erp_config.php', '/var/www/html', true) === true, 'Config fora do document root deve ser aceita pelo verificador puro.');
assertDropIn($documentRootCheck->invoke(null, '/var/www/html/erp_config.php', '/var/www/html', true) === false, 'Config dentro do document root deve ser recusada.');
assertDropIn($documentRootCheck->invoke(null, '/srv/private/erp_config.php', null, true) === false, 'Ausência do document root obrigatório deve falhar fechado.');
assertDropIn($documentRootCheck->invoke(null, '/srv/private/erp_config.php', '/path/that/does/not/exist', true) === false, 'Document root inválido deve falhar fechado.');

$configs = [];
$documentRoot = null;
$fallbackRoots = [];
try {
    $configs['mysql'] = writeSyntheticConfig('mysql');
    $configs['default-mysql'] = writeSyntheticConfig('mysql', false);
    $configs['legacy-fallback'] = writeSyntheticConfig('mysql');
    $configs['pgsql'] = writeSyntheticConfig('pgsql');
    $configs['pgsql-env'] = writeSyntheticConfig('pgsql', false);
    $configs['pgsql-missing-domains'] = writeSyntheticConfig('pgsql', true, null, false, false);
    $configs['pgsql-invalid-timezone'] = writeSyntheticConfig('pgsql', true, null, false, true, 'Invalid/Timezone');
    $configs['pgsql-fixed-offset-timezone'] = writeSyntheticConfig('pgsql', true, null, false, true, '+05:00');
    $configs['pgsql-fixed-zone-alias'] = writeSyntheticConfig('pgsql', true, null, false, true, 'Etc/GMT+5');
    $configs['pgsql-null-timezone'] = writeSyntheticConfig('pgsql');
    file_put_contents($configs['pgsql-null-timezone'], "define('ERP_TIMEZONE', null);\n", FILE_APPEND | LOCK_EX);
    $configs['mysql-invalid-timezone'] = writeSyntheticConfig('mysql', true, null, false, true, 'Invalid/Timezone');
    $configs['mysql-fixed-offset-timezone'] = writeSyntheticConfig('mysql', true, null, false, true, '+05:00');
    $configs['mysql-fixed-zone-alias'] = writeSyntheticConfig('mysql', true, null, false, true, 'Etc/GMT+5');
    $configs['mysql-null-timezone'] = writeSyntheticConfig('mysql');
    file_put_contents($configs['mysql-null-timezone'], "define('ERP_TIMEZONE', null);\n", FILE_APPEND | LOCK_EX);
    $configs['invalid'] = writeSyntheticConfig('not-a-driver');
    $configs['world-readable'] = writeSyntheticConfig('mysql');
    chmod($configs['world-readable'], 0644);
    $configs['group-readable'] = writeSyntheticConfig('mysql');
    chmod($configs['group-readable'], 0640);
    $configs['output-config'] = writeSyntheticConfig('mysql', true, null, true);
    putenv('ERP_CONFIG_PATH');
    assertDropIn(\ERP\Database\PrivateConfigPath::resolve($configs['legacy-fallback']) === realpath($configs['legacy-fallback']), 'Fallback MySQL sem ERP_CONFIG_PATH deve usar o validador privado comum.');
    chmod($configs['legacy-fallback'], 0644);
    assertDropIn(\ERP\Database\PrivateConfigPath::resolve($configs['legacy-fallback']) === null, 'Fallback MySQL com permissões de grupo/outros deve falhar fechado.');
    $configs['noisy-invalid'] = tempnam(sys_get_temp_dir(), 'erp-p3-noisy-config-');
    if (!is_string($configs['noisy-invalid'])) {
        throw new RuntimeException('Não foi possível criar fixture temporária.');
    }
    chmod($configs['noisy-invalid'], 0600);
    file_put_contents($configs['noisy-invalid'], "<?php\necho 'synthetic-only';\ndefine('ERP_DB_DRIVER', 'invalid');\n");
    putenv('ERP_DB_DRIVER');
    [$status, $stdout] = runChild(['--child-install', $configs['mysql']]);
    assertDropIn($status === 0, 'Processo sem mysqli deveria instalar aliases; saída de erro não é reproduzida por segurança.');
    assertDropIn(str_contains($stdout, 'PASS aliases, constantes, funções procedurais, bindings e resultsets sem mysqli'), 'Processo sem mysqli não confirmou o contrato procedural.');
    assertDropIn(str_contains($stdout, 'PASS real_escape_string NO_BACKSLASH_ESCAPES'), 'Escape MySQL sob NO_BACKSLASH_ESCAPES não foi confirmado pelo processo filho.');
    [$status, $stdout] = runChild(['--child-install', $configs['default-mysql']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS aliases, constantes, funções procedurais, bindings e resultsets sem mysqli'), 'Ausência do seletor deve manter o default MySQL, sem envolver PostgreSQL.');
    [$status, $stdout] = runChild(['--child-api-config', $configs['mysql'], 'mysql']);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração API por driver sem conexão'), 'Bootstrap API MySQL deveria manter as constantes históricas.');
    [$status, $stdout] = runChild(['--child-api-config', $configs['pgsql'], 'pgsql']);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração API por driver sem conexão'), 'Bootstrap API PG deve funcionar sem exigir ERP_DB_* ou copiar credenciais MySQL.');
    [$status, $stdout] = runChild(['--child-api-config-output', $configs['mysql-invalid-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500') && !str_contains($stdout, 'Invalid/Timezone'), 'Bootstrap API MySQL deve falhar fechado para timezone inválido.');
    [$status, $stdout] = runChild(['--child-api-config-output', $configs['mysql-null-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500'), 'Bootstrap API MySQL deve falhar fechado para timezone nulo.');
    [$status, $stdout] = runChild(['--child-install-env', $configs['pgsql-env']], ['ERP_DB_DRIVER' => 'pgsql']);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS seletor de driver ambiental PG alinhado ao auto-prepend e fail-closed'), 'Auto-prepend deve usar o mesmo ERP_DB_DRIVER por ambiente que api/config.php.');
    [$status, $stdout] = runChild(['--child-api-config-output', $configs['output-config']]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500')
        && !str_contains($stdout, 'synthetic-only') && !str_contains($stdout, 'UNEXPECTED_CONFIG_CONTINUE'), 'Bootstrap API deve recusar e suprimir output da configuração externa.');
    [$status, $stdout] = runChild(['--child-api-config-invalid-explicit-path']);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível') && !str_contains($stdout, 'UNEXPECTED_FALLBACK'), 'Caminho explícito inválido deve falhar fechado, sem carregar fallback legado.');
    $fallbackRoots['valid'] = createLegacyFallbackTree();
    [$status, $stdout] = runChild(['--child-api-config-legacy-fallback', $fallbackRoots['valid']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS fallback MySQL resolvido sem conexão'), 'api/config.php deve carregar o fallback legado validado sem conexão real.');
    $fallbackRoots['noisy'] = createLegacyFallbackTree(true);
    [$status, $stdout] = runChild(['--child-api-config-legacy-fallback', $fallbackRoots['noisy']]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500') && !str_contains($stdout, 'synthetic-only')
        && !str_contains($stdout, 'PASS fallback'), 'Output inesperado pelo fallback legado deve ser suprimido e invalidar o bootstrap.');
    $fallbackRoots['world-readable'] = createLegacyFallbackTree(false, 0644);
    [$status, $stdout] = runChild(['--child-api-config-legacy-fallback', $fallbackRoots['world-readable']]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500') && !str_contains($stdout, 'PASS fallback'), 'Fallback legado com permissões de grupo/outros deve falhar fechado no bootstrap completo.');
    $fallbackRoots['inside-document-root'] = createLegacyFallbackTree();
    [$status, $stdout] = runChild([
        '--child-api-config-legacy-fallback',
        $fallbackRoots['inside-document-root'],
        $fallbackRoots['inside-document-root'] . '/worktrees',
    ]);
    assertDropIn($status === 0 && str_contains($stdout, 'Configuração de serviço indisponível')
        && str_contains($stdout, '|HTTP_STATUS=500') && !str_contains($stdout, 'PASS fallback'), 'Fallback convencional dentro do document root deve falhar fechado no bootstrap completo.');
    [$status, $stdout] = runAutoPrepend($configs['mysql']);
    assertDropIn($status === 0 && $stdout === 'AUTO_INSTALLED', 'auto_prepend deve instalar o shim antes do script com configuração válida.');
    [$status, $stdout] = runAutoPrepend($configs['mysql-fixed-offset-timezone']);
    assertDropIn($status === 0 && str_contains($stdout, 'Serviço temporariamente indisponível.')
        && !str_contains($stdout, '+05:00'), 'Auto-prepend MySQL deve recusar offset literal sem revelar configuração.');
    [$status, $stdout] = runAutoPrepend($configs['mysql-fixed-zone-alias']);
    assertDropIn($status === 0 && str_contains($stdout, 'Serviço temporariamente indisponível.')
        && !str_contains($stdout, 'Etc/GMT+5'), 'Auto-prepend MySQL deve recusar alias Etc/GMT sem revelar configuração.');
    [$status, $stdout, $stderr] = runAutoPrepend(null);
    assertDropIn($status === 0 && str_contains($stdout, 'Serviço temporariamente indisponível.')
        && !str_contains($stdout, 'AUTO_INSTALLED') && !str_contains($stderr, 'synthetic-only'), 'auto_prepend deve encerrar em falha pré-script com resposta genérica.');
    [$status, $stdout] = runAutoPrepend($configs['noisy-invalid']);
    assertDropIn($status === 0 && str_contains($stdout, 'Serviço temporariamente indisponível.')
        && !str_contains($stdout, 'synthetic-only'), 'Saída da configuração inválida não pode vazar na resposta fail-closed.');

    [$status, $stdout] = runChild(['--child-pg-fail-closed', $configs['pgsql']]);
    assertDropIn($status === 0, 'Bootstrap pgsql sem driver local deveria falhar fechado; saída não é reproduzida por segurança.');
    assertDropIn(str_contains($stdout, 'PASS falha fechada sem pdo_pgsql sem aliases parciais'), 'Falha fechada PostgreSQL não foi confirmada.');

    [$status, $stdout] = runChild(['--child-config-fail-closed', '__UNSET__']);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'ERP_CONFIG_PATH ausente deveria falhar sem fallback.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', '']);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'ERP_CONFIG_PATH explicitamente vazio deveria falhar sem fallback.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['invalid']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Driver desconhecido deveria falhar sem instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['pgsql-missing-domains']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Configuração PG sem domínios confiáveis deve falhar sem instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['pgsql-invalid-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Timezone PostgreSQL inválido deve falhar sem instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['pgsql-fixed-offset-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Timezone por offset fixo não aceito pelo PHP deve falhar antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['pgsql-fixed-zone-alias']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Timezone Etc/GMT com offset fixo deve falhar antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['pgsql-null-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Timezone PostgreSQL explicitamente nulo deve falhar antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['mysql-invalid-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Auto-prepend MySQL deve falhar fechado para timezone inválido antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['mysql-null-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Auto-prepend MySQL deve falhar fechado para timezone nulo antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['mysql-fixed-offset-timezone']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Instalação MySQL deve falhar antes de aliases quando o timezone é offset literal.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['mysql-fixed-zone-alias']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Instalação MySQL deve falhar antes de aliases para alias Etc/GMT.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['world-readable']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Configuração legível por qualquer usuário deveria ser recusada antes de instalar aliases.');
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['group-readable']]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'Configuração legível por grupo deveria ser recusada antes de instalar aliases.');
    $documentRoot = sys_get_temp_dir() . '/erp-p3-dropin-root-' . bin2hex(random_bytes(8));
    if (!mkdir($documentRoot, 0700)) {
        throw new RuntimeException('Não foi possível criar document root sintético.');
    }
    $configs['in-document-root'] = writeSyntheticConfig('mysql', true, $documentRoot);
    [$status, $stdout] = runChild(['--child-config-fail-closed', $configs['in-document-root'], $documentRoot]);
    assertDropIn($status === 0 && str_contains($stdout, 'PASS configuração/driver inválidos falham fechados sem aliases'), 'ERP_CONFIG_PATH dentro do document root deve ser recusado antes de instalar aliases.');
} finally {
    foreach ($configs as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    $cleanupSucceeded = true;
    foreach ($fallbackRoots as $root) {
        $cleanupSucceeded = removeLegacyFallbackTree($root) && $cleanupSucceeded;
    }
    if (is_string($documentRoot) && is_dir($documentRoot)) {
        $cleanupSucceeded = rmdir($documentRoot) && $cleanupSucceeded;
    }
    if (!$cleanupSucceeded) {
        throw new RuntimeException('Uma árvore sintética do harness não foi removida.');
    }
}

echo "OK: {$dropInAssertionCount} assertions verificadas no processo pai; checks dos processos filhos são validados pelos marcadores PASS. Nenhum banco real.\n";
