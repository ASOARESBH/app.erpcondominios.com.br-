<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Não foi possível resolver a raiz do repositório.\n");
    exit(2);
}

$rules = [
    'MYSQL_BACKTICK_IDENTIFIER' => '/`[^`\r\n]+`/',
    'MYSQL_LIMIT_COMMA' => '/\bLIMIT\s+(?:\?|\d+|:[A-Za-z_]\w*|\$[A-Za-z_]\w*|[A-Za-z_]\w*)\s*,\s*(?:\?|\d+|:[A-Za-z_]\w*|\$[A-Za-z_]\w*|[A-Za-z_]\w*)/i',
    'MYSQL_DATE_FORMAT' => '/\bDATE_FORMAT\s*\(/i',
    'MYSQL_DATE_ADD_SUB' => '/\bDATE_(?:ADD|SUB)\s*\(/i',
    'MYSQL_CURDATE' => '/\bCURDATE\s*\(/i',
    'MYSQL_CURTIME' => '/\bCURTIME\s*\(/i',
    'MYSQL_DATEDIFF' => '/\bDATEDIFF\s*\(/i',
    'MYSQL_TIMESTAMPDIFF' => '/\bTIMESTAMPDIFF\s*\(/i',
    'MYSQL_STR_TO_DATE' => '/\bSTR_TO_DATE\s*\(/i',
    'MYSQL_CONVERT_TZ' => '/\bCONVERT_TZ\s*\(/i',
    'MYSQL_UNIX_TIME_FUNCTION' => '/\b(?:UNIX_TIMESTAMP|FROM_UNIXTIME)\s*\(/i',
    'MYSQL_IFNULL' => '/\bIFNULL\s*\(/i',
    'MYSQL_IF_FUNCTION' => '/\bIF\s*\(/i',
    'MYSQL_INSERT_IGNORE' => '/\bINSERT\s+IGNORE\b/i',
    'MYSQL_ON_DUPLICATE_KEY' => '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i',
    'MYSQL_REPLACE_INTO' => '/\bREPLACE\s+INTO\b/i',
    'MYSQL_INSERT_SET' => '/\bINSERT\s+INTO\s+[^;]+?\bSET\s+/i',
    'MYSQL_GROUP_CONCAT' => '/\bGROUP_CONCAT\s*\(/i',
    'MYSQL_FIND_IN_SET' => '/\bFIND_IN_SET\s*\(/i',
    'MYSQL_FIELD_FUNCTION' => '/\bFIELD\s*\(/i',
    'MYSQL_REGEX_OPERATOR' => '/\b(?:REGEXP|RLIKE)\b/i',
    'MYSQL_JSON_FUNCTION' => '/\bJSON_(?:EXTRACT|UNQUOTE)\s*\(/i',
    'MYSQL_EPOCH_FUNCTION' => '/\bLAST_INSERT_ID\s*\(/i',
    'MYSQL_DATABASE_FUNCTION' => '/\bDATABASE\s*\(/i',
    'MYSQL_METADATA_COMMAND' => '/\bSHOW\s+(?:TABLES|COLUMNS|FIELDS|INDEX|INDEXES)\b/i',
    'MYSQL_SHOW_CREATE' => '/\bSHOW\s+CREATE\s+(?:TABLE|VIEW|PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i',
    'MYSQL_INDEX_HINT' => '/\b(?:USE|FORCE|IGNORE)\s+INDEX\s*\(/i',
    'MYSQL_LOCK_IN_SHARE_MODE' => '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
    'MYSQL_AUTO_INCREMENT' => '/\bAUTO_INCREMENT\b/i',
    'MYSQL_ON_UPDATE_TIMESTAMP' => '/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\s*\(\s*\d*\s*\))?/i',
    'MYSQL_ALTER_MODIFY' => '/\bALTER\s+TABLE\b[^;]*\bMODIFY\s+(?:COLUMN\s+)?/i',
    'MYSQL_ALTER_CHANGE' => '/\bALTER\s+TABLE\b[^;]*\bCHANGE\s+(?:COLUMN\s+)?/i',
    'MYSQL_DROP_INDEX_ON_TABLE' => '/\bDROP\s+INDEX\s+[^;]+?\s+ON\s+/i',
    'MYSQL_LOCK_TABLES' => '/\bLOCK\s+TABLES\b/i',
    'MYSQL_UNSIGNED_TYPE' => '/\bUNSIGNED\b/i',
    'MYSQL_ENGINE_CLAUSE' => '/\bENGINE\s*=\s*[A-Za-z_]+/i',
    'MYSQL_ENUM_TYPE' => '/\bENUM\s*\(/i',
    'MYSQL_COLLATION_NAME' => '/\bCOLLATE\s+utf8(?:mb4)?_[A-Za-z0-9_]+/i',
    'MYSQL_SQL_CALC_FOUND_ROWS' => '/\bSQL_CALC_FOUND_ROWS\b/i',
    'MYSQL_STRAIGHT_JOIN' => '/\bSTRAIGHT_JOIN\b/i',
    'MYSQL_DELIMITER_COMMAND' => '/^\s*DELIMITER\s+/im',
    'MYSQL_DEFINER_CLAUSE' => '/\bDEFINER\s*=\s*[^\s;]+/i',
    'NONPORTABLE_DASH_DASH_COMMENT' => '/\x01\x02/',
];

function pgLintMaskSqlCommentsAndStrings(string $source): string
{
    $length = strlen($source);
    $masked = $source;
    $i = 0;

    $blankRange = static function (int $start, int $end) use (&$masked): void {
        for ($position = $start; $position < $end; $position++) {
            if ($masked[$position] !== "\n" && $masked[$position] !== "\r") {
                $masked[$position] = ' ';
            }
        }
    };

    while ($i < $length) {
        if ($source[$i] === '-' && $i + 1 < $length && $source[$i + 1] === '-' && ($i + 2 >= $length || ctype_space($source[$i + 2]))) {
            $end = strpos($source, "\n", $i);
            $end = $end === false ? $length : $end;
            $blankRange($i, $end);
            $i = $end;
            continue;
        }

        if ($source[$i] === '-' && $i + 1 < $length && $source[$i + 1] === '-' && $i + 2 < $length && !ctype_space($source[$i + 2])) {
            $end = strpos($source, "\n", $i);
            $end = $end === false ? $length : $end;
            $masked[$i] = "\x01";
            $masked[$i + 1] = "\x02";
            $blankRange($i + 2, $end);
            $i = $end;
            continue;
        }

        if ($source[$i] === '#' && ($i + 1 >= $length || !in_array($source[$i + 1], ['>', '-'], true))) {
            $end = strpos($source, "\n", $i);
            $end = $end === false ? $length : $end;
            $blankRange($i, $end);
            $i = $end;
            continue;
        }

        if ($source[$i] === '/' && $i + 1 < $length && $source[$i + 1] === '*') {
            $close = strpos($source, '*/', $i + 2);
            $end = $close === false ? $length : $close + 2;
            $blankRange($i, $end);
            $i = $end;
            continue;
        }

        if ($source[$i] === "'" || $source[$i] === '"') {
            $quote = $source[$i];
            $start = $i;
            $i++;
            while ($i < $length) {
                if ($source[$i] === '\\' && $i + 1 < $length) {
                    $i += 2;
                    continue;
                }
                if ($source[$i] === $quote) {
                    if ($i + 1 < $length && $source[$i + 1] === $quote) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            $blankRange($start, $i);
            continue;
        }

        $i++;
    }

    return $masked;
}

function pgLintFindViolations(string $text, int $startLine, string $path, array $rules): array
{
    $violations = [];
    foreach ($rules as $rule => $pattern) {
        $matched = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $line = $startLine + substr_count(substr($text, 0, $match[1]), "\n");
            $violations[] = [
                'path' => $path,
                'line' => $line,
                'rule' => $rule,
            ];
        }
    }
    return $violations;
}

$args = array_slice($argv, 1);
if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDERR, "Uso: php tools/pg_sql_lint.php <arquivo.php|arquivo.sql> [... ]\nInforme explicitamente os arquivos; o linter não varre o repositório.\n");
    exit($args === [] ? 2 : 0);
}

$files = [];
foreach ($args as $argument) {
    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, "Opção não reconhecida. Use --help para obter o uso.\n");
        exit(2);
    }
    $candidate = str_starts_with($argument, DIRECTORY_SEPARATOR) ? $argument : $root . DIRECTORY_SEPARATOR . $argument;
    $realPath = realpath($candidate);
    if ($realPath === false || !is_file($realPath) || is_link($candidate)) {
        fwrite(STDERR, "Entrada inexistente ou não regular.\n");
        exit(2);
    }
    if (!str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)) {
        fwrite(STDERR, "Entrada fora da raiz do repositório.\n");
        exit(2);
    }
    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['php', 'sql'], true)) {
        fwrite(STDERR, "Extensão não suportada; informe somente arquivos PHP ou SQL.\n");
        exit(2);
    }
    $files[$realPath] = $extension;
}

$violations = [];
foreach ($files as $realPath => $extension) {
    $source = file_get_contents($realPath);
    if ($source === false) {
        fwrite(STDERR, "Não foi possível ler um arquivo de entrada.\n");
        exit(2);
    }
    $relativePath = substr($realPath, strlen($root) + 1);

    if ($extension === 'sql') {
        $scanText = pgLintMaskSqlCommentsAndStrings($source);
        $violations = array_merge($violations, pgLintFindViolations($scanText, 1, $relativePath, $rules));
        continue;
    }

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $tokenText, $tokenLine] = $token;
        if (!in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        if ($tokenId === T_CONSTANT_ENCAPSED_STRING && strlen($tokenText) >= 2) {
            $tokenText = substr($tokenText, 1, -1);
        }
        $tokenText = pgLintMaskSqlCommentsAndStrings($tokenText);
        $violations = array_merge($violations, pgLintFindViolations($tokenText, $tokenLine, $relativePath, $rules));
    }
}

usort($violations, static fn(array $left, array $right): int => [$left['path'], $left['line'], $left['rule']] <=> [$right['path'], $right['line'], $right['rule']]);
if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, sprintf("%s:%d: %s\n", $violation['path'], $violation['line'], $violation['rule']));
    }
    fwrite(STDERR, sprintf("Falha: %d ocorrência(s) de sintaxe MySQL não portátil em %d arquivo(s).\n", count($violations), count($files)));
    exit(1);
}

echo sprintf("OK: %d arquivo(s) PHP/SQL verificado(s); nenhum padrão não portátil detectado.\n", count($files));
