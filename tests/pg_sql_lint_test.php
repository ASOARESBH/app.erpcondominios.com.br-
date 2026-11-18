<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Não foi possível resolver a raiz do repositório.\n");
    exit(2);
}

$linter = $root . '/tools/pg_sql_lint.php';
$tempDirectory = $root . '/.pg_sql_lint_test_' . getmypid();
if (file_exists($tempDirectory) || !mkdir($tempDirectory, 0700)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário do teste.\n");
    exit(2);
}

$files = [];
$cleanup = static function () use (&$files, $tempDirectory): void {
    foreach ($files as $file) {
        if (is_file($file) && !is_link($file)) {
            unlink($file);
        }
    }
    if (is_dir($tempDirectory) && !is_link($tempDirectory)) {
        rmdir($tempDirectory);
    }
};
register_shutdown_function($cleanup);

$run = static function (array $arguments) use ($linter, $root): array {
    $command = array_merge([PHP_BINARY, $linter], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o linter.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$writeFixture = static function (string $name, string $contents) use (&$files, $tempDirectory): string {
    $path = $tempDirectory . '/' . $name;
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Não foi possível criar fixture sintética.');
    }
    $files[] = $path;
    return $path;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $portablePhp = $writeFixture('portable.php', "<?php\n// DATE_FORMAT is documented only in this comment.\n\$sql = 'SELECT COALESCE(nome, :fallback), NOW() FROM moradores LIMIT 10 OFFSET 0';\n");
    $result = $run([$portablePhp]);
    $assert($result['exit'] === 0, 'SQL portátil em PHP deve passar.');
    $assert(str_contains($result['stdout'], 'nenhum padrão não portátil'), 'Saída positiva deve confirmar lint limpo.');

    $phpSeparator = $writeFixture('separator.php', "<?php\n\$log = \"---\\n\";\n");
    $result = $run([$phpSeparator]);
    $assert($result['exit'] === 0, 'Separador de log sem SQL não deve parecer comentário MySQL não portável.');

    $phpAmbiguousComment = $writeFixture('ambiguous_comment.php', "<?php\n\$sql = 'SELECT valor---coluna FROM tabela';\n");
    $result = $run([$phpAmbiguousComment]);
    $assert($result['exit'] === 1, 'Operador ambíguo em SQL PHP continua sinalizado.');
    $assert(str_contains($result['stderr'], 'NONPORTABLE_DASH_DASH_COMMENT'), 'A exceção de separador não pode mascarar SQL ambíguo.');

    $mysqlPhp = $writeFixture('mysql.php', "<?php\n\$sql = \"SELECT * FROM `usuarios` WHERE criado = DATE_FORMAT(NOW(), '%Y-%m-%d') LIMIT 0, 10\";\n");
    $result = $run([$mysqlPhp]);
    $assert($result['exit'] === 1, 'Sintaxe MySQL em PHP deve falhar.');
    $assert(str_contains($result['stderr'], 'MYSQL_BACKTICK_IDENTIFIER'), 'O linter deve identificar identificador com crase.');
    $assert(str_contains($result['stderr'], 'MYSQL_LIMIT_COMMA'), 'O linter deve identificar LIMIT offset,count.');
    $assert(str_contains($result['stderr'], 'MYSQL_DATE_FORMAT'), 'O linter deve identificar DATE_FORMAT.');
    $assert(!str_contains($result['stderr'], 'SELECT * FROM'), 'A saída nunca deve revelar SQL bruto.');

    $portableSql = $writeFixture('portable.sql', "-- DATE_FORMAT is only a comment.\n# DATE_FORMAT is also only a comment.\nSELECT COALESCE('INSERT IGNORE', nome), payload #> '{a}', payload #>> '{b}', payload #- '{c}' FROM moradores;\n");
    $result = $run([$portableSql]);
    $assert($result['exit'] === 0, 'Comentário e literais SQL não devem gerar falso positivo.');

    $mysqlSql = $writeFixture('mysql.sql', "INSERT IGNORE INTO fila (id) VALUES (1);\nALTER TABLE fila MODIFY atualizado_em TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;\n");
    $result = $run([$mysqlSql]);
    $assert($result['exit'] === 1, 'SQL com INSERT IGNORE deve falhar.');
    $assert(str_contains($result['stderr'], 'MYSQL_INSERT_IGNORE'), 'O linter deve identificar INSERT IGNORE.');
    $assert(str_contains($result['stderr'], 'MYSQL_ALTER_MODIFY'), 'O linter deve identificar ALTER TABLE MODIFY.');
    $assert(str_contains($result['stderr'], 'MYSQL_ON_UPDATE_TIMESTAMP'), 'O linter deve identificar ON UPDATE CURRENT_TIMESTAMP.');
    $assert(!str_contains($result['stderr'], 'VALUES (1)'), 'A saída do linter deve omitir valores SQL.');

    $upsertPhp = $writeFixture('upsert.php', "<?php\n\$sql = 'INSERT INTO notificacoes (tenant_id, chave) VALUES (?, ?) ON DUPLICATE KEY UPDATE chave = VALUES(chave)';\n");
    $result = $run([$upsertPhp]);
    $assert($result['exit'] === 1, 'Upsert MySQL continua sinalizado pela regra específica.');
    $assert(str_contains($result['stderr'], 'MYSQL_ON_DUPLICATE_KEY'), 'O linter deve identificar ON DUPLICATE KEY.');
    $assert(!str_contains($result['stderr'], 'MYSQL_INSERT_SET'), 'SET no upsert não é INSERT ... SET.');

    $insertSetPhp = $writeFixture('insert_set.php', "<?php\n\$sql = 'INSERT INTO fila SET estado = ?';\n");
    $result = $run([$insertSetPhp]);
    $assert($result['exit'] === 1, 'INSERT ... SET segue bloqueado como sintaxe MySQL.');
    $assert(str_contains($result['stderr'], 'MYSQL_INSERT_SET'), 'O linter deve identificar INSERT ... SET real.');

    $dashDashSql = $writeFixture('dash_dash.sql', "--DATE_FORMAT(created, '%Y-%m-%d')\nSELECT 1;\n");
    $result = $run([$dashDashSql]);
    $assert($result['exit'] === 1, 'Comentários `--` sem separador devem ser marcados como ambíguos entre os drivers.');
    $assert(str_contains($result['stderr'], 'NONPORTABLE_DASH_DASH_COMMENT'), 'O linter deve sinalizar o comentário `--` não portável.');
    $assert(!str_contains($result['stderr'], 'MYSQL_DATE_FORMAT'), 'O conteúdo de comentário ambíguo não deve gerar diagnósticos secundários.');

    $operatorSql = $writeFixture('operators.sql', "SELECT payload #> ARRAY['a'], DATE_FORMAT(criado, '%Y');\nSELECT payload #>> ARRAY['a'], DATE_FORMAT(criado, '%Y');\nSELECT payload #- ARRAY['a'], DATE_FORMAT(criado, '%Y');\n");
    $result = $run([$operatorSql]);
    $assert($result['exit'] === 1, 'Os operadores JSON PostgreSQL não devem ocultar sintaxe posterior na linha.');
    $assert(substr_count($result['stderr'], 'MYSQL_DATE_FORMAT') === 3, 'O linter deve continuar lendo cada linha após os operadores JSON PostgreSQL.');

    $result = $run([]);
    $assert($result['exit'] === 2, 'A ausência de arquivos explícitos deve falhar com uso.');

    $result = $run(['/etc/hosts']);
    $assert($result['exit'] === 2, 'Arquivos fora do repositório devem ser recusados.');
    $assert(!str_contains($result['stderr'], '127.0.0.1'), 'Erro de path não deve imprimir conteúdo externo.');

    echo "PASS: 12 cenários sintéticos do linter SQL portátil.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
