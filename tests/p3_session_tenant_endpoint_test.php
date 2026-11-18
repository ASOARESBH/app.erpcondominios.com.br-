<?php
declare(strict_types=1);

namespace ERP\Database {
    final class MysqliDropInBootstrap
    {
        public static function installed(): bool
        {
            return true;
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    if (($argv[1] ?? null) === '--probe') {
        $fixtureDirectory = $argv[2];
        $case = $argv[3];
        $host = $argv[4];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = $host;
        $_GET = in_array($case, ['base_query', 'base_query_valid_context'], true)
            ? ['tenant' => 'nao-confiavel']
            : [];

        session_save_path($fixtureDirectory . '/sessions');
        session_id('p3sess' . getmypid());
        session_start();
        $_SESSION = [
            'usuario_logado' => true,
            'usuario_id' => 73,
            '_rbac_sessao_id' => 31,
            '_rbac_cache' => ['modulo' => 'condominio-sintetico'],
        ];
        if ($case !== 'base_query') {
            $_SESSION['tenant_id'] = 9;
            $_SESSION['tenant_slug'] = 'predio';
            $_SESSION['tenant_nome'] = 'Condomínio sintético';
        }
        $oldSessionId = session_id();
        $oldSessionFile = $fixtureDirectory . '/sessions/sess_' . $oldSessionId;

        register_shutdown_function(static function () use ($oldSessionId, $oldSessionFile): void {
            $status = http_response_code();
            fwrite(STDERR, 'PROBE_STATUS=' . (is_int($status) ? $status : 200) . "\n");
            fwrite(STDERR, 'PROBE_USER_SESSION=' . (($_SESSION['usuario_logado'] ?? false) === true ? 'present' : 'cleared') . "\n");
            fwrite(STDERR, 'PROBE_TENANT_SESSION=' . (isset($_SESSION['tenant_id']) ? 'present' : 'cleared') . "\n");
            $rbacPresent = isset($_SESSION['_rbac_sessao_id']) || isset($_SESSION['_rbac_cache']);
            fwrite(STDERR, 'PROBE_RBAC_SESSION=' . ($rbacPresent ? 'present' : 'cleared') . "\n");
            fwrite(STDERR, 'PROBE_SESSION_EMPTY=' . ($_SESSION === [] ? 'yes' : 'no') . "\n");
            $oldIdRetired = session_id() !== $oldSessionId && !is_file($oldSessionFile);
            fwrite(STDERR, 'PROBE_OLD_ID_RETIRED=' . ($oldIdRetired ? 'yes' : 'no') . "\n");
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        });

        chdir($root . '/api');
        require $root . '/api/verificar_sessao.php';
        exit;
    }

    function assertProbe(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $temporaryRoot = sys_get_temp_dir() . '/erp-p3-session-probe-' . bin2hex(random_bytes(8));
    $configPath = $temporaryRoot . '/erp_config.php';
    $config = <<<'PHP_CONFIG'
<?php
define('ERP_DB_DRIVER', 'pgsql');
define('ERP_PG_HOST', '127.0.0.1');
define('ERP_PG_NAME', 'synthetic');
define('ERP_PG_USER', 'synthetic');
define('ERP_PG_PASS', 'synthetic-only');
define('ERP_PG_SCHEMA', 'public');
define('ERP_TRUSTED_TENANT_DOMAINS', ['erpcondominios.com.br']);
define('ERP_TIMEZONE', 'UTC');
PHP_CONFIG;
    umask(0077);
    $cases = [
        ['base_query', 'app.erpcondominios.com.br', 409, 'cleared', 'cleared', 'yes'],
        ['base_query_valid_context', 'app.erpcondominios.com.br', 200, 'present', 'present', 'no'],
        ['untrusted_host', 'nao-confiavel.example', 403, 'cleared', 'cleared', 'yes'],
        ['mismatched_subdomain', 'outro.erpcondominios.com.br', 409, 'cleared', 'cleared', 'yes'],
        ['valid_subdomain', 'predio.erpcondominios.com.br', 200, 'present', 'present', 'no'],
    ];
    $passed = 0;
    try {
        if (!mkdir($temporaryRoot, 0700) || !mkdir($temporaryRoot . '/sessions', 0700)) {
            throw new RuntimeException('Não foi possível preparar a fixture isolada.');
        }
        if (file_put_contents($configPath, $config) === false || !chmod($configPath, 0600)) {
            throw new RuntimeException('Não foi possível preparar a configuração sintética.');
        }

        foreach ($cases as [$case, $host, $expectedStatus, $expectedUser, $expectedTenant, $expectedOldId]) {
            $expectedRbac = $expectedUser;
            $expectedEmpty = $expectedUser === 'cleared' ? 'yes' : 'no';
            $command = [PHP_BINARY, __FILE__, '--probe', $temporaryRoot, $case, $host];
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $environment = getenv();
            $environment['ERP_CONFIG_PATH'] = $configPath;
            $process = proc_open($command, $descriptors, $pipes, $root, $environment);
            if (!is_resource($process)) {
                throw new RuntimeException('Não foi possível iniciar a fixture isolada.');
            }
            fclose($pipes[0]);
            $body = stream_get_contents($pipes[1]);
            $diagnostics = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $response = json_decode($body, true);
            preg_match('/^PROBE_STATUS=(\d+)$/m', $diagnostics, $statusMatch);
            preg_match('/^PROBE_USER_SESSION=(present|cleared)$/m', $diagnostics, $userMatch);
            preg_match('/^PROBE_TENANT_SESSION=(present|cleared)$/m', $diagnostics, $tenantMatch);
            preg_match('/^PROBE_RBAC_SESSION=(present|cleared)$/m', $diagnostics, $rbacMatch);
            preg_match('/^PROBE_SESSION_EMPTY=(yes|no)$/m', $diagnostics, $emptyMatch);
            preg_match('/^PROBE_OLD_ID_RETIRED=(yes|no)$/m', $diagnostics, $oldIdMatch);

            assertProbe($exitCode === 0, "Probe {$case} encerrou com erro PHP.");
            assertProbe(is_array($response), "Probe {$case} não retornou JSON válido.");
            $actualStatus = isset($statusMatch[1]) ? (int)$statusMatch[1] : null;
            assertProbe($actualStatus === $expectedStatus, "Probe {$case} retornou status {$actualStatus}; esperado {$expectedStatus}.");
            assertProbe(isset($userMatch[1]) && $userMatch[1] === $expectedUser, "Probe {$case} deixou estado de usuário inesperado.");
            assertProbe(isset($tenantMatch[1]) && $tenantMatch[1] === $expectedTenant, "Probe {$case} deixou estado de tenant inesperado.");
            assertProbe(isset($rbacMatch[1]) && $rbacMatch[1] === $expectedRbac, "Probe {$case} deixou estado RBAC inesperado.");
            assertProbe(isset($emptyMatch[1]) && $emptyMatch[1] === $expectedEmpty, "Probe {$case} deixou chaves de sessão residuais.");
            assertProbe(isset($oldIdMatch[1]) && $oldIdMatch[1] === $expectedOldId, "Probe {$case} deixou o ID de sessão anterior em estado inesperado.");
            if (str_starts_with($case, 'valid_') || $case === 'base_query_valid_context') {
                assertProbe(($response['sucesso'] ?? false) === true, 'Contexto confiável e válido deve continuar aceito.');
                assertProbe(($response['dados']['tenant']['id'] ?? null) === 9, 'A querystring não deve alterar o tenant já vinculado à sessão.');
                assertProbe(($response['dados']['tenant']['slug'] ?? null) === 'predio', 'A querystring não deve alterar o slug já vinculado à sessão.');
            } else {
                assertProbe(($response['sucesso'] ?? true) === false, 'Contexto inválido não pode ser aceito.');
            }
            $passed++;
            echo "PASS {$case}\n";
        }
    } finally {
        foreach (glob($temporaryRoot . '/sessions/*') ?: [] as $sessionFile) {
            if (is_file($sessionFile)) unlink($sessionFile);
        }
        if (is_file($configPath)) unlink($configPath);
        @rmdir($temporaryRoot . '/sessions');
        @rmdir($temporaryRoot);
    }

    echo "OK: {$passed} cenários sintéticos do endpoint de sessão PostgreSQL; sem conexão a banco.\n";
}
