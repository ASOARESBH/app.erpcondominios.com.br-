<?php
declare(strict_types=1);

namespace ERP\Database;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DatabaseTimezone.php';

final class MysqliCompatSqlException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, private readonly string $sqlState = 'HY000')
    {
        parent::__construct($message, $code, $previous);
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}

final class MysqliDropInState
{
    private static int $reportMode = 3;
    private static ?string $lastConnectError = null;

    public static function setReportMode(int $flags): bool
    {
        if (($flags & ~3) !== 0) {
            return false;
        }
        self::$reportMode = $flags;
        return true;
    }

    public static function reportMode(): int
    {
        return self::$reportMode;
    }

    public static function throwsOnError(): bool
    {
        return (self::$reportMode & 2) !== 0;
    }

    public static function reportsErrors(): bool
    {
        return (self::$reportMode & 1) !== 0;
    }

    public static function setLastConnectError(?string $message): void
    {
        self::$lastConnectError = $message;
    }

    public static function lastConnectError(): ?string
    {
        return self::$lastConnectError;
    }

    public static function reportFailure(string $message, int $code = 0): void
    {
        if (!self::reportsErrors() && !self::throwsOnError()) {
            return;
        }
        $sqlState = preg_match('/\bSQLSTATE ([A-Z0-9]{5})\b/', $message, $matches) === 1 ? $matches[1] : 'HY000';
        $safeMessage = 'Falha na operação de banco de dados (SQLSTATE ' . $sqlState . ').';
        if (self::throwsOnError()) {
            throw new MysqliCompatSqlException($safeMessage, $code, null, $sqlState);
        }
        trigger_error($safeMessage, E_USER_WARNING);
    }

    public static function reportTranslationFailure(string $patternCode): string
    {
        if (preg_match('/\A[A-Z0-9_]{1,80}\z/D', $patternCode) !== 1) {
            $patternCode = 'UNKNOWN_PATTERN';
        }
        $safeMessage = 'SQL_NAO_TRADUZIDO:' . $patternCode;
        error_log($safeMessage);
        if (self::throwsOnError()) {
            throw new MysqliCompatSqlException($safeMessage, 1, null, 'HY000');
        }
        if (self::reportsErrors()) {
            trigger_error($safeMessage, E_USER_WARNING);
        }
        return $safeMessage;
    }
}

final class MysqliDropInBootstrap
{
    private static bool $installed = false;
    private static ?array $configuration = null;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::assertSymbolsAvailable();
        self::$configuration = self::loadConfiguration();
        $pdoDriver = self::$configuration['driver'] === 'pgsql' ? 'pgsql' : 'mysql';
        if (!class_exists(\PDO::class) || !in_array($pdoDriver, \PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Shim mysqli recusado: driver PDO selecionado indisponível.');
        }
        require_once __DIR__ . '/PgsqlMysqliConnection.php';
        require_once __DIR__ . '/PgsqlMysqliStatement.php';
        require_once __DIR__ . '/PgsqlMysqliResult.php';
        require_once __DIR__ . '/PdoMysqliConnection.php';
        require_once __DIR__ . '/PdoMysqliStatement.php';
        require_once __DIR__ . '/PdoMysqliResult.php';

        self::defineConstants();
        if (!class_alias(PdoMysqliConnection::class, 'mysqli', false)
            || !class_alias(PdoMysqliStatement::class, 'mysqli_stmt', false)
            || !class_alias(PdoMysqliResult::class, 'mysqli_result', false)
            || !class_alias(MysqliCompatSqlException::class, 'mysqli_sql_exception', false)) {
            throw new RuntimeException('Shim mysqli indisponível por conflito de símbolos.');
        }

        require_once __DIR__ . '/MysqliDropInFunctions.php';
        self::$installed = true;
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function configuration(): array
    {
        if (self::$configuration === null) {
            self::$configuration = self::loadConfiguration();
        }
        return self::$configuration;
    }

    public static function selectedDriver(?array $config = null): string
    {
        $config ??= self::configuration();
        return $config['driver'];
    }

    public static function assertSymbolsAvailable(): void
    {
        $classes = ['mysqli', 'mysqli_stmt', 'mysqli_result', 'mysqli_sql_exception'];
        $functions = [
            'mysqli_affected_rows', 'mysqli_close', 'mysqli_connect', 'mysqli_connect_error',
            'mysqli_error', 'mysqli_fetch_assoc', 'mysqli_insert_id', 'mysqli_num_rows',
            'mysqli_more_results', 'mysqli_multi_query', 'mysqli_next_result', 'mysqli_prepare', 'mysqli_query',
            'mysqli_real_escape_string', 'mysqli_report', 'mysqli_set_charset', 'mysqli_character_set_name',
            'mysqli_store_result', 'mysqli_stmt_affected_rows', 'mysqli_stmt_bind_param',
            'mysqli_stmt_close', 'mysqli_stmt_execute', 'mysqli_stmt_get_result',
            'mysqli_stmt_num_rows', 'mysqli_stmt_send_long_data', 'mysqli_stmt_store_result',
        ];
        $constants = [
            'MYSQLI_ASSOC', 'MYSQLI_BOTH', 'MYSQLI_NUM', 'MYSQLI_REPORT_OFF',
            'MYSQLI_REPORT_ERROR', 'MYSQLI_REPORT_STRICT', 'MYSQLI_STORE_RESULT', 'MYSQLI_USE_RESULT',
        ];

        if (extension_loaded('mysqli')) {
            throw new RuntimeException('Shim mysqli recusado: a extensão mysqli está carregada.');
        }
        foreach ($classes as $class) {
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                throw new RuntimeException('Shim mysqli recusado: conflito de classe.');
            }
        }
        foreach ($functions as $function) {
            if (function_exists($function)) {
                throw new RuntimeException('Shim mysqli recusado: conflito de função.');
            }
        }
        foreach ($constants as $constant) {
            if (defined($constant)) {
                throw new RuntimeException('Shim mysqli recusado: conflito de constante.');
            }
        }
    }

    private static function defineConstants(): void
    {
        foreach ([
            'MYSQLI_ASSOC' => 1,
            'MYSQLI_NUM' => 2,
            'MYSQLI_BOTH' => 3,
            'MYSQLI_REPORT_OFF' => 0,
            'MYSQLI_REPORT_ERROR' => 1,
            'MYSQLI_REPORT_STRICT' => 2,
            'MYSQLI_STORE_RESULT' => 0,
            'MYSQLI_USE_RESULT' => 1,
        ] as $name => $value) {
            if (!define($name, $value)) {
                throw new RuntimeException('Shim mysqli indisponível por conflito de constante.');
            }
        }
    }

    private static function loadConfiguration(): array
    {
        if (getenv('ERP_CONFIG_PATH') === false) {
            throw new RuntimeException('Configuração externa indisponível.');
        }
        require_once __DIR__ . '/PrivateConfigPath.php';
        $realConfigPath = PrivateConfigPath::resolve();
        if (!is_string($realConfigPath)) {
            throw new RuntimeException('Configuração externa indisponível.');
        }
        require_once $realConfigPath;

        $driver = defined('ERP_DB_DRIVER') ? constant('ERP_DB_DRIVER') : getenv('ERP_DB_DRIVER');
        if ($driver === false || $driver === null || (is_string($driver) && trim($driver) === '')) {
            $driver = 'mysql';
        }
        if (!is_string($driver)) {
            throw new RuntimeException('Driver de banco inválido.');
        }
        $driver = strtolower(trim($driver));
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Driver de banco inválido.');
        }

        try {
            $timezone = DatabaseTimezone::resolve(defined('ERP_TIMEZONE') ? constant('ERP_TIMEZONE') : 'America/Sao_Paulo');
        } catch (\Throwable) {
            throw new RuntimeException('Fuso horário inválido.');
        }

        if ($driver === 'mysql') {
            foreach (['ERP_DB_HOST', 'ERP_DB_NAME', 'ERP_DB_USER', 'ERP_DB_PASS'] as $name) {
                if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
                    throw new RuntimeException('Configuração MySQL incompleta.');
                }
            }
            return [
                'driver' => 'mysql',
                'mysql_host' => constant('ERP_DB_HOST'),
                'mysql_name' => constant('ERP_DB_NAME'),
                'mysql_user' => constant('ERP_DB_USER'),
                'mysql_password' => constant('ERP_DB_PASS'),
                'mysql_port' => defined('ERP_DB_PORT') ? self::portValue(constant('ERP_DB_PORT'), 3306) : 3306,
                'mysql_charset' => defined('ERP_DB_CHARSET') ? constant('ERP_DB_CHARSET') : 'utf8mb4',
            ];
        }

        foreach (['ERP_PG_HOST', 'ERP_PG_NAME', 'ERP_PG_USER', 'ERP_PG_PASS', 'ERP_PG_SCHEMA'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
                throw new RuntimeException('Configuração PostgreSQL incompleta.');
            }
        }
        $trustedTenantDomains = defined('ERP_TRUSTED_TENANT_DOMAINS')
            ? constant('ERP_TRUSTED_TENANT_DOMAINS')
            : null;
        if (!is_array($trustedTenantDomains) || !array_is_list($trustedTenantDomains) || $trustedTenantDomains === []) {
            throw new RuntimeException('Domínios confiáveis do tenant indisponíveis.');
        }
        foreach ($trustedTenantDomains as $trustedDomain) {
            if (!is_string($trustedDomain)
                || filter_var($trustedDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new RuntimeException('Domínios confiáveis do tenant inválidos.');
            }
        }
        $port = defined('ERP_PG_PORT') ? self::portValue(constant('ERP_PG_PORT'), 5432) : 5432;
        $host = constant('ERP_PG_HOST');
        $database = constant('ERP_PG_NAME');
        $schema = constant('ERP_PG_SCHEMA');
        $validHostname = preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D', $host) === 1;
        $validIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ((!$validHostname && !$validIp)
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1
            || $port < 1 || $port > 65535
            || preg_match('/\A[a-z_][a-z0-9_]*\z/iD', $schema) !== 1) {
            throw new RuntimeException('Configuração PostgreSQL inválida.');
        }
        return [
            'driver' => 'pgsql',
            'pg_host' => $host,
            'pg_port' => $port,
            'pg_name' => $database,
            'pg_user' => constant('ERP_PG_USER'),
            'pg_password' => constant('ERP_PG_PASS'),
            'pg_schema' => $schema,
            'pg_trusted_tenant_domains' => array_map('strtolower', $trustedTenantDomains),
            'pg_timezone' => $timezone,
        ];
    }

    private static function portValue(mixed $value, int $default): int
    {
        $port = null;
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]{1,5}\z/D', $value) === 1) {
            $port = (int)$value;
        }
        if ($port === null || $port < 1 || $port > 65535) {
            throw new RuntimeException('Porta de banco inválida.');
        }
        return $port;
    }
}
