<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "FAIL: raiz do repositório indisponível.\n");
    exit(2);
}

$directory = sys_get_temp_dir() . '/p3_database_bootstrap_' . getmypid();
if (file_exists($directory) || !mkdir($directory, 0700)) {
    fwrite(STDERR, "FAIL: diretório temporário indisponível.\n");
    exit(2);
}

$cleanup = static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) unlink($file);
    }
    if (is_dir($directory) && !is_link($directory)) rmdir($directory);
};
register_shutdown_function($cleanup);

$run = static function (string $fixture) use ($root, $directory): array {
    $configPath = $directory . '/erp_config.php';
    if (file_put_contents($configPath, $fixture, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar configuração sintética.');
    }
    chmod($configPath, 0600);
    $script = 'class P3BootstrapTestStub { public static function installed(): bool { return true; } }'
        . 'class_alias(P3BootstrapTestStub::class, "ERP\\Database\\MysqliDropInBootstrap");'
        . 'register_shutdown_function(static function(){ echo "HTTP=" . http_response_code(); });'
        . 'require ' . var_export($root . '/api/config.php', true) . ';'
        . 'echo "DRIVER=" . ERP_DB_DRIVER . ";MYSQL=" . (defined("DB_HOST") && defined("ERP_DB_HOST") && DB_HOST === ERP_DB_HOST ? "1" : "0")'
        . ' . ";PG=" . (defined("DB_HOST") && defined("ERP_PG_HOST") && DB_HOST === ERP_PG_HOST ? "1" : "0");';
    $process = proc_open(
        [PHP_BINARY, '-r', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['ERP_CONFIG_PATH' => $configPath]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar teste sintético do bootstrap.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$base = "<?php\ndefine('ERP_DB_HOST','127.0.0.1');\ndefine('ERP_DB_NAME','fixture');\ndefine('ERP_DB_USER','fixture');\ndefine('ERP_DB_PASS','fixture');\n";

try {
    $mysql = $run($base);
    $assert($mysql['exit'] === 0 && str_contains($mysql['stdout'], 'DRIVER=mysql;MYSQL=1;PG=0'), 'Config sem seletor deve manter MySQL e constantes legadas.');
    echo "PASS bootstrap mantém MySQL quando driver não foi configurado\n";

    $pgConfig = "<?php\n"
        . "define('ERP_DB_DRIVER','pgsql');\n"
        . "define('ERP_PG_HOST','127.0.0.1');\n"
        . "define('ERP_PG_NAME','fixture');\n"
        . "define('ERP_PG_USER','fixture');\n"
        . "define('ERP_PG_PASS','fixture');\n"
        . "define('ERP_PG_SCHEMA','erp');\n"
        . "define('ERP_TRUSTED_TENANT_DOMAINS',['erpcondominios.com.br']);\n";
    $pgsql = $run($pgConfig);
    $assert($pgsql['exit'] === 0 && str_contains($pgsql['stdout'], 'DRIVER=pgsql;MYSQL=0;PG=1'), 'PG opt-in deve exigir configuração PostgreSQL separada.');
    echo "PASS bootstrap aceita somente PostgreSQL explícito com configuração separada\n";

    $missingTrustedDomains = $run(str_replace("define('ERP_TRUSTED_TENANT_DOMAINS',['erpcondominios.com.br']);\n", '', $pgConfig));
    $assert(str_contains($missingTrustedDomains['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($missingTrustedDomains['stdout'], 'HTTP=503'), 'PostgreSQL sem lista de domínios confiáveis deve falhar fechado.');
    echo "PASS bootstrap exige domínios confiáveis no PostgreSQL\n";

    $divergentAlias = $run($pgConfig . "define('DB_HOST','host-legado');\n");
    $assert(str_contains($divergentAlias['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($divergentAlias['stdout'], 'HTTP=503'), 'Alias DB_* preexistente divergente não pode ser aceito no PostgreSQL.');
    echo "PASS bootstrap recusa aliases divergentes do perfil selecionado\n";

    $invalid = $run($base . "define('ERP_DB_DRIVER','sqlite');\n");
    $assert($invalid['exit'] === 0 && !str_contains($invalid['stdout'], 'sqlite'), 'Driver inválido deve responder sem revelar o valor.');
    $assert(str_contains($invalid['stdout'], 'Configuração de serviço indisponível.'), 'Falha de configuração deve ser sanitizada.');
    echo "PASS bootstrap falha fechado e sanitizado para driver inválido\n";

    $missingSchema = $run("<?php\n"
        . "define('ERP_DB_DRIVER','pgsql');\n"
        . "define('ERP_PG_HOST','127.0.0.1');\n"
        . "define('ERP_PG_NAME','fixture');\n"
        . "define('ERP_PG_USER','fixture');\n"
        . "define('ERP_PG_PASS','fixture');\n"
        . "define('ERP_TRUSTED_TENANT_DOMAINS',['erpcondominios.com.br']);\n");
    $assert($missingSchema['exit'] === 0 && str_contains($missingSchema['stdout'], 'Configuração de serviço indisponível.'), 'PostgreSQL sem schema explícito deve falhar fechado.');
    $assert(str_contains($missingSchema['stdout'], 'HTTP=503'), 'Configuração PostgreSQL indisponível deve responder HTTP 503.');
    echo "PASS bootstrap exige schema PostgreSQL explícito\n";

    $invalidTimezone = $run($pgConfig . "define('ERP_TIMEZONE','+05:00');\n");
    $assert($invalidTimezone['exit'] === 0 && str_contains($invalidTimezone['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($invalidTimezone['stdout'], 'HTTP=503') && !str_contains($invalidTimezone['stdout'], '+05:00'), 'Offset não suportado pelo timezone padrão do PHP deve falhar fechado e sem revelar configuração.');
    echo "PASS bootstrap PostgreSQL recusa offset fixo incompatível com o PHP\n";

    $nullTimezone = $run($pgConfig . "define('ERP_TIMEZONE',null);\n");
    $assert($nullTimezone['exit'] === 0 && str_contains($nullTimezone['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($nullTimezone['stdout'], 'HTTP=503'), 'Timezone PostgreSQL explicitamente nulo deve falhar fechado.');
    echo "PASS bootstrap PostgreSQL recusa timezone nulo\n";

    $fixedZoneAlias = $run($pgConfig . "define('ERP_TIMEZONE','Etc/GMT+5');\n");
    $assert($fixedZoneAlias['exit'] === 0 && str_contains($fixedZoneAlias['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($fixedZoneAlias['stdout'], 'HTTP=503') && !str_contains($fixedZoneAlias['stdout'], 'Etc/GMT+5'), 'Alias IANA de offset fixo deve falhar fechado.');
    echo "PASS bootstrap PostgreSQL recusa alias Etc/GMT de offset fixo\n";

    $mysqlInvalidTimezone = $run($base . "define('ERP_TIMEZONE','Invalid/Timezone');\n");
    $assert($mysqlInvalidTimezone['exit'] === 0 && str_contains($mysqlInvalidTimezone['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($mysqlInvalidTimezone['stdout'], 'HTTP=500') && !str_contains($mysqlInvalidTimezone['stdout'], 'Invalid/Timezone'), 'Bootstrap MySQL com timezone inválido deve falhar fechado e sem revelar config.');
    echo "PASS bootstrap MySQL recusa timezone inválido\n";

    $mysqlFixedOffsetTimezone = $run($base . "define('ERP_TIMEZONE','+05:00');\n");
    $assert($mysqlFixedOffsetTimezone['exit'] === 0 && str_contains($mysqlFixedOffsetTimezone['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($mysqlFixedOffsetTimezone['stdout'], 'HTTP=500') && !str_contains($mysqlFixedOffsetTimezone['stdout'], '+05:00'), 'Bootstrap MySQL com offset literal deve falhar fechado e sem revelar config.');
    echo "PASS bootstrap MySQL recusa offset literal\n";

    $mysqlFixedZoneAlias = $run($base . "define('ERP_TIMEZONE','Etc/GMT+5');\n");
    $assert($mysqlFixedZoneAlias['exit'] === 0 && str_contains($mysqlFixedZoneAlias['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($mysqlFixedZoneAlias['stdout'], 'HTTP=500') && !str_contains($mysqlFixedZoneAlias['stdout'], 'Etc/GMT+5'), 'Bootstrap MySQL com alias Etc/GMT deve falhar fechado e sem revelar config.');
    echo "PASS bootstrap MySQL recusa alias Etc/GMT\n";

    $mysqlNullTimezone = $run($base . "define('ERP_TIMEZONE',null);\n");
    $assert($mysqlNullTimezone['exit'] === 0 && str_contains($mysqlNullTimezone['stdout'], 'Configuração de serviço indisponível.')
        && str_contains($mysqlNullTimezone['stdout'], 'HTTP=500'), 'Bootstrap MySQL com timezone nulo deve falhar fechado.');
    echo "PASS bootstrap MySQL recusa timezone nulo\n";

    echo "OK: 13 cenários sintéticos de bootstrap; nenhuma conexão de banco aberta.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
