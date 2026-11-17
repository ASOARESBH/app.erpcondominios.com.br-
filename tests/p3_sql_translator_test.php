<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/db/MysqlToPostgresSqlTranslator.php';

use ERP\Database\MysqlToPostgresSqlTranslator;
use ERP\Database\SqlTranslationException;

$passed = 0;
$failed = 0;

$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
};

$translation = static function (string $sql): string {
    return MysqlToPostgresSqlTranslator::translate($sql);
};

$conflictResolver = static function (string $table, array $columns, bool $replace): ?array {
    if ($table !== 'crm_sequencia' || !in_array('ano', $columns, true)) {
        return null;
    }
    return [
        'target' => ['ano'],
        'replace_columns' => $replace ? ['ano', 'ultimo'] : [],
    ];
};

$check(
    'tradução de IFNULL e IF aninhado',
    $translation("SELECT IFNULL(`apelido`, IF(`ativo` = 1, 'sim', 'não')) FROM `moradores`")
        === "SELECT COALESCE(apelido, CASE WHEN ativo = 1 THEN 'sim' ELSE 'não' END) FROM moradores"
);
$check(
    'tradução de NOW, CURDATE, CURTIME e DATABASE',
    $translation('SELECT NOW(), CURDATE(), CURTIME(), DATABASE()')
        === 'SELECT LOCALTIMESTAMP(0), CURRENT_DATE, LOCALTIME(0), current_schema()'
);
$check(
    'usa current_schema para DATABASE() na consulta do catálogo',
    $translation('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
        === 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()'
);
$check(
    'preserva nomes de funções em string, comentário e identificador quoted',
    $translation("SELECT 'IFNULL(x,y)', `IFNULL`, /* NOW() */ NOW()")
        === "SELECT 'IFNULL(x,y)', ifnull, /* NOW() */ LOCALTIMESTAMP(0)"
);
$check(
    'converte comentário MySQL # sem confundir operador JSON PostgreSQL',
    $translation("SELECT data #>> '{nome}' FROM registros # comentário\n")
        === "SELECT data #>> '{nome}' FROM registros -- comentário\n"
);
$check(
    'preserva comments e dollar quoted strings com texto MySQL',
    $translation("SELECT \$tag\$IFNULL(a,b)\$tag\$, IFNULL(x, 0)")
        === "SELECT \$tag\$IFNULL(a,b)\$tag\$, COALESCE(x, 0)"
);
$multi = MysqlToPostgresSqlTranslator::translateStatements("SELECT ';'; SELECT NOW() -- ;\n; SELECT IFNULL(1, 0)");
$check(
    'multi_query lexer ignora delimitadores em strings/comentários e cacheia a tradução',
    $multi === ["SELECT ';'", 'SELECT LOCALTIMESTAMP(0) -- ;', 'SELECT COALESCE(1, 0)']
        && $multi === MysqlToPostgresSqlTranslator::translateStatements("SELECT ';'; SELECT NOW() -- ;\n; SELECT IFNULL(1, 0)")
);
$check(
    'preserva SQL PostgreSQL e placeholders positionalmente',
    $translation('SELECT a::text, COALESCE(a, ?), data #> \'{x}\' FROM t LIMIT ? OFFSET ?')
        === 'SELECT a::text, COALESCE(a, ?), data #> \'{x}\' FROM t LIMIT ? OFFSET ?'
);
$check(
    'converte SUM(boolean) para CASE simples preservando NULL e SUM não booleano',
    $translation("SELECT SUM(nivel = 'ERRO'), SUM(status IN ('aberto', 'andamento')), SUM(a OR b), SUM(amount), SUM(CASE WHEN a=1 THEN 1 ELSE 0 END), SUM(DISTINCT status = 'x') FROM t")
        === "SELECT SUM(CASE nivel = 'ERRO' WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(CASE status IN ('aberto', 'andamento') WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(CASE a OR b WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(amount), SUM(CASE WHEN a=1 THEN 1 ELSE 0 END), SUM(DISTINCT CASE status = 'x' WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END) FROM t"
);
$preparedBooleanSum = $translation('SELECT SUM(status = ?) FROM t');
$check(
    'mantém um único placeholder em SUM(boolean) preparado',
    $preparedBooleanSum === 'SELECT SUM(CASE status = ? WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END) FROM t'
        && substr_count($preparedBooleanSum, '?') === 1
);
$check(
    'converte literal, CASE, COALESCE e IF com resultado booleano em SUM',
    $translation('SELECT SUM(TRUE), SUM(CASE WHEN ativo = 1 THEN TRUE ELSE FALSE END), SUM(COALESCE(ativo = TRUE, FALSE)), SUM(IF(ativo = 1, TRUE, FALSE)) FROM t')
        === 'SELECT SUM(CASE TRUE WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(CASE CASE WHEN ativo = 1 THEN TRUE ELSE FALSE END WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(CASE COALESCE(ativo = TRUE, FALSE) WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END), SUM(CASE CASE WHEN ativo = 1 THEN TRUE ELSE FALSE END WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END) FROM t'
);
$check(
    'IF com comparador composto permanece booleano e mantém placeholder único',
    $translation('SELECT IF(a <= ?, TRUE, FALSE) FROM t') === 'SELECT CASE WHEN a <= ? THEN TRUE ELSE FALSE END FROM t'
);
$check(
    'traduz LIMIT offset,count com inteiros literais',
    $translation('SELECT id FROM t LIMIT 10, 20') === 'SELECT id FROM t LIMIT 20 OFFSET 10'
);
$check(
    'converte DATE_FORMAT com mapa de especificadores acordado',
    $translation("SELECT DATE_FORMAT(criado, '%Y-%m-%d %H:%i:%s %y %M %b %W %a %e %c %j %p') FROM t")
        === "SELECT to_char(criado, 'YYYY\"-\"MM\"-\"DD\" \"HH24\":\"MI\":\"SS\" \"YY\" \"FMMonth\" \"FMMon\" \"FMDay\" \"FMDy\" \"FMDD\" \"FMMM\" \"DDD\" \"AM') FROM t"
);
$check(
    'converte DATE_ADD e DATE_SUB com INTERVAL de unidade simples',
    $translation('SELECT DATE_ADD(criado, INTERVAL 2 DAY), DATE_SUB(criado, INTERVAL 1 MONTH) FROM t')
        === "SELECT (criado + (2 * INTERVAL '1 day')), (criado - (1 * INTERVAL '1 month')) FROM t"
);
$check(
    'preserva intervalo literal PostgreSQL em SQL já portável',
    $translation("SELECT NOW() - INTERVAL '1 day'") === "SELECT LOCALTIMESTAMP(0) - INTERVAL '1 day'"
);
$check(
    'traduz intervalo MySQL com quantidade numérica quoted',
    $translation("SELECT DATE_ADD(criado, INTERVAL '2' DAY) FROM t")
        === "SELECT (criado + (2 * INTERVAL '1 day')) FROM t"
);
$check(
    'converte SHOW TABLES com filtro parametrizado para information_schema',
    str_contains($translation('SHOW TABLES LIKE ?'), 'FROM information_schema.tables WHERE table_schema = current_schema()')
        && str_ends_with($translation('SHOW TABLES LIKE ?'), 'table_name LIKE ?')
);
$check(
    'converte SHOW COLUMNS LIKE e DESCRIBE para pg_catalog com aliases legados',
    str_contains($translation("SHOW COLUMNS FROM `registros_acesso` LIKE 'registro_titular_id'"), 'a.attname LIKE \'registro_titular_id\'')
        && str_contains($translation('DESCRIBE `chatin_conversas`'), 'AS "Field"')
        && str_contains($translation('DESCRIBE `chatin_conversas`'), 'AS "Type"')
);
$check(
    'converte SHOW INDEX para pg_index preservando Key_name',
    str_contains($translation("SHOW INDEX FROM `email_alertas` WHERE Key_name = 'uq_email_alertas_tenant_codigo'"), 'JOIN pg_catalog.pg_index')
        && str_contains($translation("SHOW INDEX FROM `email_alertas` WHERE Key_name = 'uq_email_alertas_tenant_codigo'"), "idx.relname = 'uq_email_alertas_tenant_codigo'")
);
$check(
    'converte diferenças de data, timestamp, Unix time e extratores numéricos',
    $translation('SELECT DATEDIFF(a, b), TIMESTAMPDIFF(DAY, a, b), UNIX_TIMESTAMP(), FROM_UNIXTIME(42), DATE(a), YEAR(a), WEEKDAY(a), DAYOFWEEK(a)')
        === "SELECT (a::date - b::date), TRUNC(EXTRACT(EPOCH FROM (CAST((b) AS timestamp) - CAST((a) AS timestamp))) / 86400)::bigint, EXTRACT(EPOCH FROM CURRENT_TIMESTAMP)::bigint, (to_timestamp(42) AT TIME ZONE 'America/Sao_Paulo'), (a)::date, EXTRACT(YEAR FROM a)::int, (EXTRACT(ISODOW FROM a)::int - 1), (EXTRACT(DOW FROM a)::int + 1)"
);
$check(
    'usa EXTRACT EPOCH em TIMESTAMP e intervalo válido para DATE menos DATE',
    $translation("SELECT UNIX_TIMESTAMP(criado), TIMESTAMPDIFF(DAY, DATE '2024-01-01', DATE '2024-01-03') FROM t")
        === "SELECT EXTRACT(EPOCH FROM criado)::bigint, TRUNC(EXTRACT(EPOCH FROM (CAST((DATE '2024-01-03') AS timestamp) - CAST((DATE '2024-01-01') AS timestamp))) / 86400)::bigint FROM t"
);
$check(
    'converte operador null-safe e LAST_INSERT_ID sem parâmetros',
    $translation('SELECT a <=> b, LAST_INSERT_ID()') === 'SELECT a IS NOT DISTINCT FROM b, lastval()'
);
$check(
    'converte REGEXP e RLIKE para regex case-insensitive PostgreSQL',
    $translation("SELECT nome FROM t WHERE nome REGEXP '^a' OR codigo RLIKE '^[0-9]+$'")
        === "SELECT nome FROM t WHERE nome ~* '^a' OR codigo ~* '^[0-9]+$'"
);
$check(
    'converte NOT REGEXP e NOT RLIKE para operadores negativos',
    $translation("SELECT nome FROM t WHERE nome NOT REGEXP '^a' AND codigo NOT RLIKE '^[0-9]+$'")
        === "SELECT nome FROM t WHERE nome !~* '^a' AND codigo !~* '^[0-9]+$'"
);
$check(
    'preserva REGEXP e RLIKE usados como coluna ou alias',
    $translation('SELECT regexp FROM t WHERE regexp = 1') === 'SELECT regexp FROM t WHERE regexp = 1'
        && $translation('SELECT x AS rlike FROM t') === 'SELECT x AS rlike FROM t'
);
$check(
    'converte GROUP_CONCAT com ORDER BY e SEPARATOR',
    $translation("SELECT GROUP_CONCAT(nome ORDER BY nome DESC SEPARATOR ';') FROM t")
        === "SELECT string_agg(nome::text, ';' ORDER BY nome DESC) FROM t"
);
$check(
    'converte INSERT IGNORE para ON CONFLICT DO NOTHING',
    $translation('INSERT IGNORE INTO t (id) VALUES (?)') === 'INSERT INTO t (id) VALUES (?) ON CONFLICT DO NOTHING'
);
$upsertSql = 'INSERT INTO crm_sequencia (ano, ultimo) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo=VALUES(ultimo)+1';
$upsertTranslated = MysqlToPostgresSqlTranslator::translate($upsertSql, $conflictResolver);
$check(
    'converte ON DUPLICATE por alvo único e preserva placeholders',
    $upsertTranslated === 'INSERT INTO crm_sequencia (ano, ultimo) VALUES (?, ?) ON CONFLICT ("ano") DO UPDATE SET ultimo = EXCLUDED.ultimo+1'
        && substr_count($upsertTranslated, '?') === substr_count($upsertSql, '?')
);
$replaceTranslated = MysqlToPostgresSqlTranslator::translate(
    'REPLACE INTO crm_sequencia (ano, ultimo) VALUES (?, ?)',
    $conflictResolver
);
$check(
    'converte REPLACE restrito para upsert com todas as colunas',
    $replaceTranslated === 'INSERT INTO crm_sequencia (ano, ultimo) VALUES (?, ?) ON CONFLICT ("ano") DO UPDATE SET "ano" = EXCLUDED."ano", "ultimo" = EXCLUDED."ultimo"'
);
$check(
    'multi_query traduz upsert com resolver sem reutilizar cache sem catálogo',
    MysqlToPostgresSqlTranslator::translateStatements($upsertSql, $conflictResolver) === [$upsertTranslated]
);
$check(
    'converte FIND_IN_SET preservando NULL e índice ausente igual a zero',
    $translation('SELECT FIND_IN_SET(valor, lista) FROM t')
        === "SELECT (CASE WHEN valor IS NULL OR lista IS NULL THEN NULL ELSE COALESCE(array_position(string_to_array(lista::text, ','), valor::text), 0) END) FROM t"
);
$check(
    'converte FIELD para array_position 1-based com fallback zero',
    $translation("SELECT FIELD('B', 'A', 'B') FROM t")
        === "SELECT (SELECT CASE WHEN field_expr.value IS NULL THEN 0 ELSE COALESCE(array_position(ARRAY[('A')::text, ('B')::text], field_expr.value), 0) END FROM (VALUES ('B'::text)) AS field_expr(value)) FROM t"
);
$check(
    'FIELD mantém a posição quando a lista contém NULL',
    str_contains($translation("SELECT FIELD('A', NULL, 'A') FROM t"), "array_position(ARRAY[NULL::text, ('A')::text], field_expr.value)")
);
$check(
    'FIELD usa comparação numérica quando expressão numérica encontra strings numéricas',
    str_contains($translation("SELECT FIELD(1, '01', '1')"), "array_position(ARRAY[('01')::numeric, ('1')::numeric], field_expr.value)")
);
$check(
    'preserva CONCAT e CONCAT_WS como funções PostgreSQL disponíveis',
    $translation("SELECT CONCAT(nome, ' ', sobrenome), CONCAT_WS('-', codigo, unidade) FROM t")
        === "SELECT ((nome)::text || (' ')::text || (sobrenome)::text), CONCAT_WS('-', codigo, unidade) FROM t"
);
$check(
    'preserva DESC de ORDER BY como SQL PostgreSQL válido',
    $translation('SELECT id FROM t ORDER BY atualizado_em DESC') === 'SELECT id FROM t ORDER BY atualizado_em DESC'
);
$check(
    'GROUP BY completo mantém projeção agrupada e agregados',
    $translation('SELECT status, COUNT(*) AS total FROM t GROUP BY status')
        === 'SELECT status, COUNT(*) AS total FROM t GROUP BY status'
);
$check(
    'GROUP BY por alias resolve a expressão projetada',
    $translation('SELECT status AS s, COUNT(*) AS total FROM t GROUP BY s')
        === 'SELECT status AS s, COUNT(*) AS total FROM t GROUP BY s'
);

$runtimeDdlCalls = [];
$runtimeDdlResolver = static function (string $kind, ?string $schema, string $table, ?string $column) use (&$runtimeDdlCalls): bool {
    $runtimeDdlCalls[] = [$kind, $schema, $table, $column];
    return ($kind === 'table' && $table === 'configuracao_smtp')
        || ($kind === 'column' && $table === 'configuracao_smtp' && $column === 'host')
        || ($kind === 'index' && $table === 'os_chamados' && $column === 'idx_os_tenant_unidade');
};
$runtimeDdlNoop = 'DO $erp_runtime_ddl$ BEGIN NULL; END $erp_runtime_ddl$';
$check(
    'CREATE TABLE existente no DDL versionado vira no-op após lookup',
    MysqlToPostgresSqlTranslator::translate(
        'CREATE TABLE IF NOT EXISTS configuracao_smtp (id BIGINT)',
        null,
        $runtimeDdlResolver
    ) === $runtimeDdlNoop && $runtimeDdlCalls[0] === ['table', null, 'configuracao_smtp', null]
);
$check(
    'ALTER ADD COLUMN existente no DDL versionado vira no-op',
    MysqlToPostgresSqlTranslator::translate(
        'ALTER TABLE configuracao_smtp ADD COLUMN IF NOT EXISTS host VARCHAR(255)',
        null,
        $runtimeDdlResolver
    ) === $runtimeDdlNoop && end($runtimeDdlCalls) === ['column', null, 'configuracao_smtp', 'host']
);
$runtimeDdlMissingCode = null;
try {
    MysqlToPostgresSqlTranslator::translate(
        'ALTER TABLE configuracao_smtp ADD COLUMN segredo TEXT',
        null,
        $runtimeDdlResolver
    );
} catch (SqlTranslationException $exception) {
    $runtimeDdlMissingCode = $exception->patternCode;
}
$check('ALTER com coluna não provada gera SQL_NAO_TRADUZIDO', $runtimeDdlMissingCode === 'MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
$runtimeDdlMulti = MysqlToPostgresSqlTranslator::translateStatements(
    'ALTER TABLE configuracao_smtp ADD COLUMN host VARCHAR(255); SELECT 1',
    null,
    $runtimeDdlResolver
);
$check('multi_query usa o mesmo resolver runtime DDL antes da execução', $runtimeDdlMulti === [$runtimeDdlNoop, 'SELECT 1']);
$caseSensitiveResolver = static fn(string $kind, ?string $schema, string $table, ?string $column): bool =>
    $kind === 'column' && $schema === 'app' && $table === 'Thing' && $column === 'Extra';
$quotedRuntimeSql = 'ALTER TABLE `app`.`Thing` ADD COLUMN IF NOT EXISTS `Extra` INT';
$check(
    'multi_query preserva caixa quoted no resolver runtime DDL',
    MysqlToPostgresSqlTranslator::translateStatements($quotedRuntimeSql, null, $caseSensitiveResolver) === [$runtimeDdlNoop]
);
$quotedCaseMismatchRejected = false;
try {
    MysqlToPostgresSqlTranslator::translateStatements(
        'ALTER TABLE `APP`.`Thing` ADD COLUMN IF NOT EXISTS `Extra` INT',
        null,
        $caseSensitiveResolver
    );
} catch (SqlTranslationException) {
    $quotedCaseMismatchRejected = true;
}
$check('multi_query rejeita schema quoted com caixa divergente', $quotedCaseMismatchRejected);
$runtimeDdlTailRejected = false;
try {
    MysqlToPostgresSqlTranslator::translate(
        'ALTER TABLE os_chamados ADD INDEX idx_os_tenant_unidade (tenant_id), ADD COLUMN extra INT',
        null,
        $runtimeDdlResolver
    );
} catch (SqlTranslationException) {
    $runtimeDdlTailRejected = true;
}
$check('ALTER ADD INDEX com segunda ação não é reduzido a no-op', $runtimeDdlTailRejected);
$runtimeDdlCreateTailRejected = false;
try {
    MysqlToPostgresSqlTranslator::translate(
        'CREATE TABLE IF NOT EXISTS configuracao_smtp (id BIGINT) ENGINE=InnoDB',
        null,
        $runtimeDdlResolver
    );
} catch (SqlTranslationException) {
    $runtimeDdlCreateTailRejected = true;
}
$check('CREATE runtime com cláusula trailing não analisada falha fechado', $runtimeDdlCreateTailRejected);
$runtimeDdlPreambleRejected = false;
try {
    MysqlToPostgresSqlTranslator::translate(
        'CREATE TABLE IF NOT EXISTS configuracao_smtp unexpected (id BIGINT)',
        null,
        $runtimeDdlResolver
    );
} catch (SqlTranslationException) {
    $runtimeDdlPreambleRejected = true;
}
$check('CREATE runtime com token não analisado antes da definição falha fechado', $runtimeDdlPreambleRejected);

$rejected = [
    'REPLACE sem resolver de metadados bloqueado' => 'REPLACE INTO t (id) VALUES (?)',
    'ON DUPLICATE KEY sem resolver de metadados bloqueado' => 'INSERT INTO t (id) VALUES (?) ON DUPLICATE KEY UPDATE id=VALUES(id)',
    'comentário executável MySQL com WHERE em UPDATE bloqueado' => 'UPDATE accounts SET locked = TRUE /*! WHERE id = 42 */',
    'comentário executável MariaDB com WHERE em UPDATE bloqueado' => 'UPDATE accounts SET locked = TRUE /*M!100100 WHERE id = 42 */',
    'LIMIT offset,count bloqueado para evitar reordenação de parâmetros' => 'SELECT id FROM t LIMIT ?, ?',
    'DATE_FORMAT com especificador desconhecido bloqueado' => "SELECT DATE_FORMAT(criado, '%r') FROM t",
    'CREATE TABLE sem IF NOT EXISTS bloqueado no runtime' => 'CREATE TABLE configuracao_smtp (id BIGINT)',
    'LOCK IN SHARE MODE MySQL não chega ao PostgreSQL' => 'SELECT id FROM t LOCK IN SHARE MODE',
    'DDL runtime bloqueado' => 'ALTER TABLE t ADD COLUMN x integer',
    'CALL a procedure bloqueado' => 'CALL rotina_legada()',
    'SQL_CALC_FOUND_ROWS bloqueado em strict' => 'SELECT SQL_CALC_FOUND_ROWS id FROM t',
    'SHOW DATABASES fora do subconjunto bloqueado' => 'SHOW DATABASES',
    'SHOW TABLES FROM outro schema rejeitado sem SQL residual' => 'SHOW TABLES FROM outro_schema',
    'SHOW COLUMNS com schema qualificado rejeitado' => 'SHOW COLUMNS FROM tenant.contas',
    'SHOW INDEX com filtro extra rejeitado' => "SHOW INDEX FROM t WHERE Key_name = 'idx' AND 1 = 1",
    'aspas duplas de MySQL dependentes de ANSI_QUOTES bloqueadas' => 'SELECT "nome" FROM t',
    'backslash string MySQL ambígua bloqueada' => "SELECT 'a\\\\b'",
    'identificador com caractere complexo bloqueado' => 'SELECT `nome``legal` FROM t',
    'identificador quoted que é palavra reservada bloqueado' => 'SELECT `order` FROM `order`',
    'identificador quoted OVER reservado bloqueado' => 'SELECT `over` FROM t',
    'identificador quoted LOCALTIME reservado bloqueado' => 'SELECT `localtime` FROM t',
    'identificador quoted OVERLAPS reservado bloqueado' => 'SELECT `overlaps` FROM t',
    'identificador quoted FREEZE reservado bloqueado' => 'SELECT `freeze` FROM t',
    'FIELD com comparação mista ambígua bloqueado' => "SELECT FIELD(1, 'A', 'B')",
    'FIELD com coluna de tipo não comprovado bloqueado' => "SELECT FIELD(id, '01', '1') FROM t",
    'FIELD com coluna texto e candidatos numéricos bloqueado' => 'SELECT FIELD(name, 1, 2) FROM t',
    'GROUP_CONCAT com mais de uma expressão bloqueado' => "SELECT GROUP_CONCAT(nome, email) FROM t",
    'GROUP_CONCAT DISTINCT com ORDER BY bloqueado' => 'SELECT GROUP_CONCAT(DISTINCT score ORDER BY score) FROM t',
    'GROUP BY incompleto rejeita coluna não agrupada' => 'SELECT id, status FROM t GROUP BY id',
    'GROUP BY incompleto rejeita referência dentro de expressão' => "SELECT CONCAT(status, ':', tenant_id) FROM t GROUP BY status",
    'GROUP BY incompleto rejeita wildcard' => 'SELECT * FROM t GROUP BY id',
    'GROUP BY incompleto rejeita wildcard qualificado' => 'SELECT t.* FROM t GROUP BY id',
    'GROUP BY em UNION exige análise por bloco' => 'SELECT id FROM t GROUP BY id UNION SELECT status FROM t GROUP BY id',
    'INTERVAL quoted não numérico falha strict' => "SELECT DATE_ADD(criado, INTERVAL 'x' DAY) FROM t",
    'SUM CASE com sufixo numérico é ambíguo' => 'SELECT SUM(CASE WHEN a=1 THEN TRUE ELSE FALSE END + 1) FROM t',
    'SUM IFNULL com tipo de coluna não comprovado é ambíguo' => 'SELECT SUM(IFNULL(flag, FALSE)) FROM t',
    'SUM CASE com placeholder booleano não tipado é ambíguo' => 'SELECT SUM(CASE WHEN a=1 THEN ? ELSE FALSE END) FROM t',
];
foreach ($rejected as $name => $sql) {
    try {
        $translation($sql);
        $check($name, false);
    } catch (SqlTranslationException) {
        $check($name, true);
    }
}

$groupByErrorCode = null;
try {
    $translation('SELECT id, status FROM t GROUP BY id');
} catch (SqlTranslationException $exception) {
    $groupByErrorCode = $exception->patternCode;
}
$check('GROUP BY incompleto gera código strict estável', $groupByErrorCode === 'MYSQL_GROUP_BY_INCOMPLETE');

printf("TOTAL=%d PASS=%d FAIL=%d\n", $passed + $failed, $passed, $failed);
exit($failed === 0 ? 0 : 1);
