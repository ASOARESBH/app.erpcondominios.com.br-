<?php
declare(strict_types=1);

final class P3AuditTestStatement
{
    private array $parameters = [];

    public function __construct(private P3AuditTestConnection $connection)
    {
    }

    public function bind_param(string $types, &...$values): bool
    {
        foreach ($values as &$value) {
            $this->parameters[] = &$value;
        }
        unset($value);
        return true;
    }

    public function execute(): bool
    {
        $this->connection->persistedRows[] = array_map(static fn(&$value) => $value, $this->parameters);
        return true;
    }

    public function close(): void
    {
    }
}

final class P3AuditTestConnection
{
    public int $connect_errno = 0;
    public array $persistedRows = [];
    public string $charset = '';

    public function set_charset(string $charset): bool
    {
        $this->charset = $charset;
        return true;
    }

    public function prepare(string $query): P3AuditTestStatement|false
    {
        if (!str_contains($query, 'INSERT INTO logs_sistema (tipo, descricao, usuario, ip)')) {
            return false;
        }
        return new P3AuditTestStatement($this);
    }

    public function close(): void
    {
    }
}

function p3AuditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryRoot = sys_get_temp_dir() . '/erp-p3-audit-' . bin2hex(random_bytes(8));
umask(0077);
if (!mkdir($temporaryRoot, 0700)) {
    throw new RuntimeException('Não foi possível preparar a fixture isolada.');
}
$configPath = $temporaryRoot . '/erp_config.php';
$config = <<<'PHP_CONFIG'
<?php
define('ERP_DB_HOST', '127.0.0.1');
define('ERP_DB_NAME', 'synthetic');
define('ERP_DB_USER', 'synthetic');
define('ERP_DB_PASS', 'synthetic-only');
define('ERP_DB_CHARSET', 'utf8mb4');
PHP_CONFIG;
try {
    if (file_put_contents($configPath, $config) === false || !chmod($configPath, 0600)) {
        throw new RuntimeException('Não foi possível preparar a configuração sintética.');
    }
    putenv('ERP_DB_DRIVER=');
    putenv('ERP_CONFIG_PATH=' . $configPath);
    require dirname(__DIR__) . '/api/config.php';

    $_SERVER['REMOTE_ADDR'] = '192.0.2.77';
    $withoutIp = new P3AuditTestConnection();
    p3AuditAssert(erp_registrar_log_em_conexao($withoutIp, 'LOGIN_TEST', 'synthetic-event', null, false), 'O registro sintético sem IP deve ser aceito.');
    p3AuditAssert($withoutIp->persistedRows[0][3] === null, 'O banco deve receber NULL no campo IP quando a opção estiver desativada.');
    p3AuditAssert($withoutIp->persistedRows[0][0] === 'LOGIN_TEST' && $withoutIp->persistedRows[0][1] === 'synthetic-event', 'O registro deve manter somente os códigos sintéticos esperados.');
    echo "PASS IP omitido é persistido como NULL\n";

    $withIp = new P3AuditTestConnection();
    p3AuditAssert(erp_registrar_log_em_conexao($withIp, 'LOGIN_TEST', 'synthetic-event', null), 'O registro sintético com IP padrão deve ser aceito.');
    p3AuditAssert($withIp->persistedRows[0][3] === '192.0.2.77', 'O comportamento padrão de IP legado deve permanecer disponível.');
    echo "PASS comportamento legado com IP padrão\n";
    echo "OK: 2 cenários comportamentais da auditoria; sem conexão a banco.\n";
} finally {
    if (is_file($configPath)) {
        unlink($configPath);
    }
    @rmdir($temporaryRoot);
}
