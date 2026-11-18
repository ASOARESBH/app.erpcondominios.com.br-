<?php
declare(strict_types=1);

namespace ERP\Database;

use RuntimeException;

final class SqlTranslationException extends RuntimeException
{
    public function __construct(public readonly string $patternCode)
    {
        parent::__construct('SQL_NAO_TRADUZIDO: ' . $patternCode);
    }
}

final class MysqlToPostgresSqlTranslator
{
    private const CACHE_LIMIT = 512;

    private static array $cache = [];

    private const UNSUPPORTED_FUNCTIONS = [
        'STR_TO_DATE', 'CONVERT_TZ', 'JSON_EXTRACT', 'JSON_UNQUOTE',
        'FOUND_ROWS', 'ISNULL',
    ];

    // Reserved words and words requiring AS, checked against PostgreSQL 18.7 Appendix C.
    private const POSTGRES_RESERVED_IDENTIFIERS = [
        'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc',
        'asymmetric', 'authorization', 'binary', 'both', 'case', 'cast', 'char', 'character',
        'check', 'collate', 'collation', 'column', 'concurrently', 'constraint', 'create', 'cross',
        'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time', 'current_timestamp', 'current_user', 'day',
        'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end', 'except',
        'false', 'fetch', 'filter', 'for', 'foreign', 'freeze', 'from', 'full',
        'grant', 'group', 'having', 'hour', 'ilike', 'in', 'initially', 'inner',
        'intersect', 'into', 'is', 'isnull', 'join', 'lateral', 'leading', 'left',
        'like', 'limit', 'localtime', 'localtimestamp', 'minute', 'month', 'natural', 'not',
        'notnull', 'null', 'offset', 'on', 'only', 'or', 'order', 'outer',
        'over', 'overlaps', 'placing', 'precision', 'primary', 'references', 'returning', 'right',
        'second', 'select', 'session_user', 'similar', 'some', 'symmetric', 'system_user', 'table',
        'tablesample', 'then', 'to', 'trailing', 'true', 'union', 'unique', 'user',
        'using', 'variadic', 'varying', 'verbose', 'when', 'where', 'window', 'with',
        'within', 'without', 'year',
    ];

    public static function translate(
        string $sql,
        ?callable $conflictResolver = null,
        ?callable $runtimeDdlResolver = null
    ): string
    {
        $tokens = self::tokenize($sql);
        [$tokens, $conflictContext] = self::rewriteUpsert($tokens, $conflictResolver);
        [$tokens, $runtimeDdlContext] = self::rewriteRuntimeDdl($tokens, $runtimeDdlResolver);
        $key = hash('sha256', "single\0" . $sql . "\0" . $conflictContext . "\0" . $runtimeDdlContext);
        if (array_key_exists($key, self::$cache)) {
            $cached = self::$cache[$key];
            if (isset($cached['error'])) {
                throw new SqlTranslationException($cached['error']);
            }
            return $cached['sql'];
        }

        try {
            $translated = self::translateTokens($tokens);
            self::cache($key, ['sql' => $translated]);
            return $translated;
        } catch (SqlTranslationException $exception) {
            self::cache($key, ['error' => $exception->patternCode]);
            throw $exception;
        }
    }

    private static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $char = $sql[$i];

            if (preg_match('/\A\s\z/', $char) === 1) {
                while ($i < $length && preg_match('/\A\s\z/', $sql[$i]) === 1) {
                    $i++;
                }
                $tokens[] = ['ws', substr($sql, $start, $i - $start)];
                continue;
            }

            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-' && ($i + 2 === $length || preg_match('/\A\s\z/', $sql[$i + 2]) === 1)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $tokens[] = ['comment', substr($sql, $start, $i - $start)];
                continue;
            }

            if ($char === '#' && ($i + 1 >= $length || !in_array($sql[$i + 1], ['>', '-'], true))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $tokens[] = ['comment', '--' . substr($sql, $start + 1, $i - $start - 1)];
                continue;
            }

            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                if (substr($sql, $i, 3) === '/*!' || preg_match('/\A\/\*M!/i', substr($sql, $i, 4)) === 1) {
                    throw new SqlTranslationException('MYSQL_EXECUTABLE_COMMENT_UNSUPPORTED');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new SqlTranslationException('SQL_COMMENT_UNTERMINATED');
                }
                $i = $end + 2;
                $tokens[] = ['comment', substr($sql, $start, $i - $start)];
                continue;
            }

            if ($char === "'") {
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === '\\') {
                        throw new SqlTranslationException('MYSQL_BACKSLASH_STRING_UNSUPPORTED');
                    }
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $length && $sql[$i + 1] === "'") {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                if ($i > $length || $sql[$i - 1] !== "'") {
                    throw new SqlTranslationException('SQL_STRING_UNTERMINATED');
                }
                $tokens[] = ['string', substr($sql, $start, $i - $start)];
                continue;
            }

            if ($char === '`') {
                $i++;
                $identifier = '';
                $closed = false;
                while ($i < $length) {
                    if ($sql[$i] === '`') {
                        if ($i + 1 < $length && $sql[$i + 1] === '`') {
                            $identifier .= '`';
                            $i += 2;
                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $identifier .= $sql[$i++];
                }
                if (!$closed || $identifier === '') {
                    throw new SqlTranslationException('MYSQL_IDENTIFIER_INVALID');
                }
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_$]*\z/D', $identifier) !== 1) {
                    throw new SqlTranslationException('MYSQL_IDENTIFIER_COMPLEX_UNSUPPORTED');
                }
                $normalizedIdentifier = strtolower($identifier);
                if (in_array($normalizedIdentifier, self::POSTGRES_RESERVED_IDENTIFIERS, true)) {
                    throw new SqlTranslationException('MYSQL_IDENTIFIER_RESERVED_UNSUPPORTED');
                }
                $tokens[] = ['identifier', $normalizedIdentifier, $identifier];
                continue;
            }

            if ($char === '"') {
                throw new SqlTranslationException('MYSQL_DOUBLE_QUOTE_MODE_AMBIGUOUS');
            }

            if ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/A', $sql, $match, 0, $i) === 1) {
                $tag = $match[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                if ($end === false) {
                    throw new SqlTranslationException('DOLLAR_QUOTE_UNTERMINATED');
                }
                $i = $end + strlen($tag);
                $tokens[] = ['string', substr($sql, $start, $i - $start)];
                continue;
            }

            if (self::isWordStart($char)) {
                $i++;
                while ($i < $length && self::isWordPart($sql[$i])) {
                    $i++;
                }
                $tokens[] = ['word', substr($sql, $start, $i - $start)];
                continue;
            }

            if (preg_match('/\A[0-9]\z/', $char) === 1) {
                $i++;
                while ($i < $length && (preg_match('/\A[0-9]\z/', $sql[$i]) === 1 || $sql[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['number', substr($sql, $start, $i - $start)];
                continue;
            }

            $operator = substr($sql, $i, 3);
            if ($operator === '<=>') {
                $i += 3;
                $tokens[] = ['symbol', $operator];
                continue;
            }
            $operator = substr($sql, $i, 2);
            if (in_array($operator, ['<=', '>=', '<>', '!='], true)) {
                $i += 2;
                $tokens[] = ['symbol', $operator];
                continue;
            }

            $i++;
            $tokens[] = ['symbol', $char];
        }

        return $tokens;
    }

    private static function cache(string $key, array $value): void
    {
        if (count(self::$cache) >= self::CACHE_LIMIT) {
            array_shift(self::$cache);
        }
        self::$cache[$key] = $value;
    }

    private static function onlyWhitespaceAndComments(array $tokens): bool
    {
        foreach ($tokens as [$kind]) {
            if (!in_array($kind, ['ws', 'comment'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function assertGroupedSelectSafe(array $tokens): void
    {
        $depth = 0;
        $select = null;
        $from = null;
        $groupBy = null;
        $nestedGroupBy = false;
        $topLevelUnion = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
                continue;
            }
            if ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($kind !== 'word') {
                continue;
            }
            $keyword = strtoupper($text);
            if ($depth > 0 && $keyword === 'GROUP' && self::nextWord($tokens, $i + 1) === 'BY') {
                $nestedGroupBy = true;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($keyword === 'SELECT' && $select === null) {
                $select = $i;
            } elseif ($keyword === 'FROM' && $select !== null && $from === null) {
                $from = $i;
            } elseif ($keyword === 'GROUP' && self::nextWord($tokens, $i + 1) === 'BY') {
                $groupBy = $i;
            } elseif ($keyword === 'UNION') {
                $topLevelUnion = true;
            }
        }
        if ($nestedGroupBy) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }
        if ($groupBy === null) {
            return;
        }
        if ($topLevelUnion) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }
        if ($select === null || $from === null || $from >= $groupBy) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }

        $projection = self::splitTopLevelTokens(array_slice($tokens, $select + 1, $from - $select - 1));
        if ($projection === []) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }
        $aliases = [];
        $projectionExpressions = [];
        foreach ($projection as $item) {
            $aliasIndex = self::lastTopLevelKeyword($item, 'AS');
            if ($aliasIndex !== null) {
                $alias = self::nextSignificant($item, $aliasIndex + 1);
                if ($alias === null || !in_array($item[$alias][0], ['word', 'identifier'], true)
                    || self::nextSignificant($item, $alias + 1) !== null) {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
                }
                $expression = array_slice($item, 0, $aliasIndex);
                $aliases[strtolower($item[$alias][1])] = $expression;
            } else {
                $expression = $item;
            }
            $projectionExpressions[] = $expression;
        }

        $groupEnd = $count;
        $depth = 0;
        for ($i = self::nextSignificant($tokens, $groupBy + 1) ?? $count; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $kind === 'word'
                && in_array(strtoupper($text), ['HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'UNION', 'RETURNING'], true)) {
                $groupEnd = $i;
                break;
            } elseif ($depth === 0 && $kind === 'symbol' && $text === ';') {
                $groupEnd = $i;
                break;
            }
        }
        $byIndex = self::nextSignificant($tokens, $groupBy + 1);
        if ($byIndex === null || strtoupper($tokens[$byIndex][1]) !== 'BY') {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }
        $groupExpressions = self::splitTopLevelTokens(array_slice($tokens, $byIndex + 1, $groupEnd - $byIndex - 1));
        if ($groupExpressions === []) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }
        if (self::containsWord($groupExpressions, ['ROLLUP', 'CUBE', 'GROUPING', 'SETS'])) {
            throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
        }

        $groupNorms = [];
        $groupColumns = [];
        foreach ($groupExpressions as $item) {
            $significant = self::significantTokens($item);
            if (count($significant) === 1 && in_array($significant[0][0], ['word', 'identifier'], true)
                && isset($aliases[strtolower($significant[0][1])])) {
                $aliasExpression = $aliases[strtolower($significant[0][1])];
                if (self::containsAggregateCall($aliasExpression)) {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
                }
                $item = $aliasExpression;
                $significant = self::significantTokens($item);
            } elseif (count($significant) === 1 && $significant[0][0] === 'number'
                && ctype_digit($significant[0][1])) {
                $ordinal = (int)$significant[0][1];
                if ($ordinal < 1 || !isset($projectionExpressions[$ordinal - 1])) {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
                }
                $item = $projectionExpressions[$ordinal - 1];
                $significant = self::significantTokens($item);
            }
            $groupNorms[self::normalizeExpression($item)] = true;
            $simple = self::simpleColumnReference($significant);
            if ($simple !== null) {
                $groupColumns[$simple] = true;
            }
        }

        foreach ($projectionExpressions as $expression) {
            if (self::containsWord([$expression], ['OVER', 'WINDOW'])) {
                throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
            }
            if (isset($groupNorms[self::normalizeExpression($expression)])) {
                continue;
            }
            foreach (self::columnReferencesOutsideAggregates($expression) as $reference) {
                if (!isset($groupColumns[$reference])) {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_INCOMPLETE');
                }
            }
        }
    }

    private static function splitTopLevelTokens(array $tokens): array
    {
        $segments = [];
        $start = 0;
        $depth = 0;
        foreach ($tokens as $i => [$kind, $text]) {
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($kind === 'symbol' && $text === ',' && $depth === 0) {
                $segments[] = array_slice($tokens, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $segments[] = array_slice($tokens, $start);
        return array_values(array_filter($segments, static fn(array $segment): bool => self::significantTokens($segment) !== []));
    }

    private static function lastTopLevelKeyword(array $tokens, string $keyword): ?int
    {
        $depth = 0;
        $found = null;
        foreach ($tokens as $i => [$kind, $text]) {
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $kind === 'word' && strtoupper($text) === $keyword) {
                $found = $i;
            }
        }
        return $found;
    }

    private static function significantTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn(array $token): bool => !in_array($token[0], ['ws', 'comment'], true)));
    }

    private static function normalizeExpression(array $tokens): string
    {
        $parts = [];
        foreach (self::significantTokens($tokens) as [$kind, $text]) {
            $parts[] = in_array($kind, ['word', 'identifier'], true) ? strtolower($text) : $text;
        }
        return implode('', $parts);
    }

    private static function simpleColumnReference(array $tokens): ?string
    {
        if ($tokens === [] || !in_array($tokens[0][0], ['word', 'identifier'], true)) {
            return null;
        }
        $parts = [strtolower($tokens[0][1])];
        for ($i = 1; $i < count($tokens); $i += 2) {
            if (($tokens[$i][0] ?? null) !== 'symbol' || ($tokens[$i][1] ?? null) !== '.'
                || !in_array($tokens[$i + 1][0] ?? null, ['word', 'identifier'], true)) {
                return null;
            }
            $parts[] = strtolower($tokens[$i + 1][1]);
        }
        return implode('.', $parts);
    }

    private static function columnReferencesOutsideAggregates(array $tokens): array
    {
        $aggregateFunctions = [
            'ANY_VALUE', 'ARRAY_AGG', 'AVG', 'BIT_AND', 'BIT_OR', 'BIT_XOR', 'BOOL_AND', 'BOOL_OR',
            'CORR', 'COUNT', 'COVAR_POP', 'COVAR_SAMP', 'EVERY', 'GROUP_CONCAT', 'JSON_AGG',
            'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'MAX', 'MIN', 'REGR_AVGX', 'REGR_AVGY', 'REGR_COUNT',
            'REGR_INTERCEPT', 'REGR_R2', 'REGR_SLOPE', 'REGR_SXX', 'REGR_SXY', 'REGR_SYY', 'STD',
            'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'STRING_AGG', 'SUM', 'VARIANCE', 'VAR_POP', 'VAR_SAMP',
        ];
        $ignoredWords = [
            'ALL', 'AND', 'AS', 'ASC', 'BETWEEN', 'BY', 'CASE', 'COLLATE', 'CURRENT_DATE',
            'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DESC', 'DISTINCT', 'ELSE', 'END', 'FALSE',
            'FILTER', 'FROM', 'GROUP', 'HAVING', 'INTERVAL', 'IS', 'LIKE', 'NOT', 'NULL', 'OFFSET',
            'OR', 'ORDER', 'OVER', 'SELECT', 'THEN', 'TRUE', 'WHEN', 'WHERE', 'YEAR', 'MONTH',
            'DAY', 'HOUR', 'MINUTE', 'SECOND',
        ];
        $typeWords = [
            'BIGINT', 'BIT', 'BOOL', 'BOOLEAN', 'CHAR', 'CHARACTER', 'DATE', 'DECIMAL', 'DOUBLE',
            'FLOAT', 'INT', 'INTEGER', 'INTERVAL', 'JSON', 'NUMERIC', 'REAL', 'SERIAL', 'SMALLINT',
            'TEXT', 'TIME', 'TIMESTAMP', 'UUID', 'VARCHAR',
        ];
        $references = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '*') {
                $previous = self::previousSignificant($tokens, $i - 1);
                $wildcard = $previous === null
                    || ($tokens[$previous][0] === 'symbol' && $tokens[$previous][1] === '.')
                    || ($tokens[$previous][0] === 'word' && in_array(strtoupper($tokens[$previous][1]), ['ALL', 'DISTINCT'], true));
                if ($wildcard) {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_INCOMPLETE');
                }
            }
            if ($kind === 'symbol' && $text === '(') {
                $next = self::nextSignificant($tokens, $i + 1);
                if ($next !== null && $tokens[$next][0] === 'word' && strtoupper($tokens[$next][1]) === 'SELECT') {
                    throw new SqlTranslationException('MYSQL_GROUP_BY_ANALYSIS_UNSUPPORTED');
                }
                continue;
            }
            if (!in_array($kind, ['word', 'identifier'], true)) {
                continue;
            }
            $upper = strtoupper($text);
            $previous = self::previousSignificant($tokens, $i - 1);
            $previousPrevious = $previous === null ? null : self::previousSignificant($tokens, $previous - 1);
            if ($kind === 'word' && $upper === 'COLLATE') {
                $collation = self::nextSignificant($tokens, $i + 1);
                if ($collation !== null) {
                    $i = $collation;
                }
                continue;
            }
            if ($kind === 'word' && in_array($upper, $typeWords, true)
                && (($previous !== null && $tokens[$previous][0] === 'symbol' && $tokens[$previous][1] === ':'
                    && $previousPrevious !== null && $tokens[$previousPrevious][0] === 'symbol' && $tokens[$previousPrevious][1] === ':')
                    || ($previous !== null && $tokens[$previous][0] === 'word' && strtoupper($tokens[$previous][1]) === 'AS'))) {
                continue;
            }
            $next = self::nextSignificant($tokens, $i + 1);
            if ($kind === 'word' && $next !== null && $tokens[$next][0] === 'symbol' && $tokens[$next][1] === '(') {
                if (in_array($upper, $aggregateFunctions, true)) {
                    $depth = 0;
                    for ($j = $next; $j < $count; $j++) {
                        if ($tokens[$j][0] === 'symbol' && $tokens[$j][1] === '(') {
                            $depth++;
                        } elseif ($tokens[$j][0] === 'symbol' && $tokens[$j][1] === ')') {
                            $depth--;
                            if ($depth === 0) {
                                $i = $j;
                                break;
                            }
                        }
                    }
                }
                continue;
            }
            if ($kind === 'word' && in_array($upper, $ignoredWords, true)) {
                continue;
            }
            if ($previous !== null && $tokens[$previous][0] === 'symbol' && $tokens[$previous][1] === '.') {
                continue;
            }
            $parts = [strtolower($text)];
            $cursor = $i;
            while (true) {
                $dot = self::nextSignificant($tokens, $cursor + 1);
                $field = $dot === null ? null : self::nextSignificant($tokens, $dot + 1);
                if ($dot === null || $field === null || $tokens[$dot][0] !== 'symbol' || $tokens[$dot][1] !== '.'
                    || !in_array($tokens[$field][0], ['word', 'identifier'], true)) {
                    break;
                }
                $parts[] = strtolower($tokens[$field][1]);
                $cursor = $field;
            }
            $references[implode('.', $parts)] = true;
            $i = $cursor;
        }
        return array_keys($references);
    }

    private static function containsWord(array $tokenGroups, array $words): bool
    {
        foreach ($tokenGroups as $tokens) {
            foreach ($tokens as [$kind, $text]) {
                if ($kind === 'word' && in_array(strtoupper($text), $words, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function containsAggregateCall(array $tokens): bool
    {
        $functions = [
            'ANY_VALUE', 'ARRAY_AGG', 'AVG', 'BIT_AND', 'BIT_OR', 'BIT_XOR', 'BOOL_AND', 'BOOL_OR',
            'CORR', 'COUNT', 'COVAR_POP', 'COVAR_SAMP', 'EVERY', 'GROUP_CONCAT', 'JSON_AGG',
            'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'MAX', 'MIN', 'REGR_AVGX', 'REGR_AVGY', 'REGR_COUNT',
            'REGR_INTERCEPT', 'REGR_R2', 'REGR_SLOPE', 'REGR_SXX', 'REGR_SXY', 'REGR_SYY', 'STD',
            'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'STRING_AGG', 'SUM', 'VARIANCE', 'VAR_POP', 'VAR_SAMP',
        ];
        foreach ($tokens as $i => [$kind, $text]) {
            if ($kind !== 'word' || !in_array(strtoupper($text), $functions, true)) {
                continue;
            }
            $next = self::nextSignificant($tokens, $i + 1);
            if ($next !== null && $tokens[$next][0] === 'symbol' && $tokens[$next][1] === '(') {
                return true;
            }
        }
        return false;
    }

    private static function rewriteUpsert(array $tokens, ?callable $conflictResolver): array
    {
        $start = self::nextSignificant($tokens, 0);
        if ($start === null || $tokens[$start][0] !== 'word') {
            return [$tokens, ''];
        }
        $statement = strtoupper($tokens[$start][1]);
        $replace = $statement === 'REPLACE';
        $insertIgnore = false;
        if (!$replace && $statement !== 'INSERT') {
            return [$tokens, ''];
        }

        $into = self::nextSignificant($tokens, $start + 1);
        if ($into !== null && $tokens[$into][0] === 'word' && strtoupper($tokens[$into][1]) === 'IGNORE') {
            $insertIgnore = true;
            $into = self::nextSignificant($tokens, $into + 1);
        }
        if ($into === null || $tokens[$into][0] !== 'word' || strtoupper($tokens[$into][1]) !== 'INTO') {
            return [$tokens, ''];
        }
        $tableIndex = self::nextSignificant($tokens, $into + 1);
        $table = $tableIndex === null ? null : self::simpleIdentifier($tokens[$tableIndex]);
        $open = $tableIndex === null ? null : self::nextSignificant($tokens, $tableIndex + 1);
        if ($table === null || $open === null || $tokens[$open] !== ['symbol', '(']) {
            if ($replace || $insertIgnore) {
                throw new SqlTranslationException($insertIgnore ? 'MYSQL_INSERT_IGNORE_UNSUPPORTED' : 'MYSQL_REPLACE_INTO_UNSUPPORTED');
            }
            return [$tokens, ''];
        }
        [$columnSegments, $close] = self::callArguments($tokens, $open);
        $insertColumns = [];
        foreach ($columnSegments as $segment) {
            $significant = self::significantTokens($segment);
            if (count($significant) !== 1 || ($column = self::simpleIdentifier($significant[0])) === null) {
                if ($replace || $insertIgnore) {
                    throw new SqlTranslationException($insertIgnore ? 'MYSQL_INSERT_IGNORE_UNSUPPORTED' : 'MYSQL_REPLACE_INTO_UNSUPPORTED');
                }
                return [$tokens, ''];
            }
            $insertColumns[] = $column;
        }
        $values = self::nextSignificant($tokens, $close + 1);
        if ($insertColumns === [] || $values === null || $tokens[$values][0] !== 'word'
            || !in_array(strtoupper($tokens[$values][1]), ['VALUES', 'VALUE'], true)) {
            if ($replace || $insertIgnore) {
                throw new SqlTranslationException($insertIgnore ? 'MYSQL_INSERT_IGNORE_UNSUPPORTED' : 'MYSQL_REPLACE_INTO_UNSUPPORTED');
            }
            return [$tokens, ''];
        }

        $duplicateIndex = null;
        $depth = 0;
        for ($i = $values + 1, $count = count($tokens); $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $kind === 'word' && strtoupper($text) === 'ON'
                && self::wordSequenceAt($tokens, $i, ['ON', 'DUPLICATE', 'KEY', 'UPDATE'])) {
                $duplicateIndex = $i;
                break;
            }
        }
        if (!$replace && $duplicateIndex === null) {
            return [$tokens, ''];
        }
        if ($insertIgnore && $duplicateIndex !== null) {
            throw new SqlTranslationException('MYSQL_INSERT_IGNORE_UPSERT_UNSUPPORTED');
        }
        if ($duplicateIndex !== null && strtoupper($tokens[$values][1]) === 'VALUE') {
            throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
        }
        if ($conflictResolver === null) {
            throw new SqlTranslationException('MYSQL_CONFLICT_TARGET_METADATA_REQUIRED');
        }

        try {
            $metadata = $conflictResolver($table, $insertColumns, $replace);
        } catch (\Throwable) {
            $metadata = null;
        }
        if (!is_array($metadata) || !is_array($metadata['target'] ?? null) || $metadata['target'] === []) {
            throw new SqlTranslationException('MYSQL_CONFLICT_TARGET_UNRESOLVED');
        }
        $target = [];
        foreach ($metadata['target'] as $column) {
            if (!is_string($column) || preg_match('/\A[a-z_][a-z0-9_]*\z/D', $column) !== 1
                || !in_array(strtolower($column), array_map('strtolower', $insertColumns), true)) {
                throw new SqlTranslationException('MYSQL_CONFLICT_TARGET_UNRESOLVED');
            }
            $target[] = strtolower($column);
        }
        $target = array_values(array_unique($target));
        if ($target === []) {
            throw new SqlTranslationException('MYSQL_CONFLICT_TARGET_UNRESOLVED');
        }

        if ($replace) {
            $replaceColumns = $metadata['replace_columns'] ?? null;
            if (!is_array($replaceColumns) || $replaceColumns === []) {
                throw new SqlTranslationException('MYSQL_REPLACE_INTO_UNSUPPORTED');
            }
            $assignments = [];
            foreach ($replaceColumns as $column) {
                if (!is_string($column) || preg_match('/\A[a-z_][a-z0-9_]*\z/D', $column) !== 1) {
                    throw new SqlTranslationException('MYSQL_REPLACE_INTO_UNSUPPORTED');
                }
                $column = strtolower($column);
                $quoted = '"' . $column . '"';
                $assignments[] = $quoted . ' = EXCLUDED.' . $quoted;
            }
            $prefix = array_slice($tokens, 0, $start);
            $prefix[] = ['word', 'INSERT'];
            $prefix = array_merge($prefix, array_slice($tokens, $start + 1));
            $semicolon = self::topLevelSemicolonIndex($prefix);
            if ($semicolon !== null) {
                $suffix = array_slice($prefix, $semicolon);
                $prefix = array_slice($prefix, 0, $semicolon);
            } else {
                $suffix = [];
            }
            $targetSql = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $target));
            $separator = self::upsertClauseSeparator($prefix);
            $rewritten = array_merge($prefix, [['ws', $separator . 'ON CONFLICT (' . $targetSql . ') DO UPDATE SET ' . implode(', ', $assignments)], ...$suffix]);
            return [$rewritten, hash('sha256', json_encode([$table, $target, $replaceColumns], JSON_THROW_ON_ERROR))];
        }

        $afterUpdate = $duplicateIndex;
        foreach (['ON', 'DUPLICATE', 'KEY', 'UPDATE'] as $_) {
            $next = self::nextSignificant($tokens, $afterUpdate);
            if ($next === null) {
                throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
            }
            $afterUpdate = $next + 1;
        }
        $assignmentTokens = array_slice($tokens, $afterUpdate);
        $semicolon = self::topLevelSemicolonIndex($assignmentTokens);
        if ($semicolon !== null) {
            $suffix = array_slice($assignmentTokens, $semicolon);
            $assignmentTokens = array_slice($assignmentTokens, 0, $semicolon);
        } else {
            $suffix = [];
        }
        $segments = self::splitTopLevelTokens($assignmentTokens);
        if ($segments === []) {
            throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
        }
        $assignmentSql = [];
        foreach ($segments as $segment) {
            $equals = self::topLevelSymbolIndex($segment, '=');
            if ($equals === null) {
                throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
            }
            $left = self::significantTokens(array_slice($segment, 0, $equals));
            if (count($left) !== 1 || ($column = self::simpleIdentifier($left[0])) === null) {
                throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
            }
            $rightTokens = array_slice($segment, $equals + 1);
            $rightSignificant = self::significantTokens($rightTokens);
            if (count($rightSignificant) === 4 && $rightSignificant[0][0] === 'word'
                && strtoupper($rightSignificant[0][1]) === 'LAST_INSERT_ID'
                && $rightSignificant[1] === ['symbol', '('] && $rightSignificant[3] === ['symbol', ')']
                && self::simpleIdentifier($rightSignificant[2]) === $column) {
                // MySQL uses id = LAST_INSERT_ID(id) to make an upsert expose
                // the existing row's id as insert_id. PostgreSQL's adapter
                // obtains that same row id from RETURNING; keep the assignment
                // idempotent and do not call lastval() inside the update.
                $right = [$rightSignificant[2]];
            } else {
                if (self::containsWord([$rightTokens], ['LAST_INSERT_ID'])) {
                    throw new SqlTranslationException('MYSQL_LAST_INSERT_ID_CONTEXT_UNSUPPORTED');
                }
                $right = self::rewriteValuesCalls($rightTokens, $insertColumns);
            }
            if (self::significantTokens($right) === []) {
                throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
            }
            $assignmentSql[] = self::translateTokens($left) . ' = ' . trim(self::translateTokens($right));
        }
        $targetSql = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $target));
        $prefix = array_slice($tokens, 0, $duplicateIndex);
        $separator = self::upsertClauseSeparator($prefix);
        $clause = [['ws', $separator . 'ON CONFLICT (' . $targetSql . ') DO UPDATE SET ' . implode(', ', $assignmentSql)]];
        return [array_merge($prefix, $clause, $suffix), hash('sha256', json_encode([$table, $target], JSON_THROW_ON_ERROR))];
    }

    private static function upsertClauseSeparator(array $prefix): string
    {
        if ($prefix === []) {
            return '';
        }
        $last = end($prefix);
        if ($last[0] === 'ws') {
            return '';
        }
        if ($last[0] === 'comment' && str_starts_with((string)$last[1], '--')) {
            return "\n";
        }
        return ' ';
    }

    private static function simpleIdentifier(array $token): ?string
    {
        if (!in_array($token[0] ?? null, ['word', 'identifier'], true) || !is_string($token[1] ?? null)
            || preg_match('/\A[a-z_][a-z0-9_]*\z/iD', $token[1]) !== 1) {
            return null;
        }
        $identifier = strtolower($token[1]);
        return in_array($identifier, self::POSTGRES_RESERVED_IDENTIFIERS, true) ? null : $identifier;
    }

    private static function topLevelSymbolIndex(array $tokens, string $symbol): ?int
    {
        $depth = 0;
        foreach ($tokens as $i => [$kind, $text]) {
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($kind === 'symbol' && $text === $symbol && $depth === 0) {
                return $i;
            }
        }
        return null;
    }

    private static function topLevelSemicolonIndex(array $tokens): ?int
    {
        return self::topLevelSymbolIndex($tokens, ';');
    }

    private static function rewriteValuesCalls(array $tokens, array $insertColumns): array
    {
        $output = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind !== 'word' || strtoupper($text) !== 'VALUES') {
                $output[] = $tokens[$i];
                continue;
            }
            $open = self::nextSignificant($tokens, $i + 1);
            if ($open === null || $tokens[$open] !== ['symbol', '(']) {
                $output[] = $tokens[$i];
                continue;
            }
            [$arguments, $close] = self::callArguments($tokens, $open);
            $significant = self::significantTokens($arguments[0] ?? []);
            if (count($arguments) !== 1 || count($significant) !== 1
                || ($column = self::simpleIdentifier($significant[0])) === null
                || !in_array($column, array_map('strtolower', $insertColumns), true)) {
                throw new SqlTranslationException('MYSQL_VALUES_FUNCTION_UNSUPPORTED');
            }
            $output[] = ['word', 'EXCLUDED'];
            $output[] = ['symbol', '.'];
            $output[] = ['identifier', $column];
            $i = $close;
        }
        return $output;
    }

    private static function rewriteRuntimeDdl(array $tokens, ?callable $resolver): array
    {
        $start = self::nextSignificant($tokens, 0);
        if ($start === null || !in_array(strtoupper((string)$tokens[$start][1]), ['CREATE', 'ALTER'], true)) {
            return [$tokens, ''];
        }
        $command = strtoupper($tokens[$start][1]);
        if (!self::hasSingleStatement($tokens)) {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_TRAILING_SYNTAX_UNSUPPORTED');
        }
        $tableWord = self::nextWord($tokens, $start + 1);
        if ($command === 'CREATE' && $tableWord !== 'TABLE') {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OR_ROUTINE_UNSUPPORTED');
        }
        if ($command === 'ALTER' && $tableWord !== 'TABLE') {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OR_ROUTINE_UNSUPPORTED');
        }
        if ($resolver === null) {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_METADATA_REQUIRED');
        }

        $tableIndex = self::nextSignificant($tokens, $start + 1);
        if ($command === 'CREATE') {
            if ($tableIndex !== null && self::isWord($tokens[$tableIndex], 'TABLE')) {
                $tableIndex = self::nextSignificant($tokens, $tableIndex + 1);
            }
            if ($tableIndex === null || !self::isWord($tokens[$tableIndex], 'IF')) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_IDEMPOTENCY_REQUIRED');
            }
            $notIndex = self::nextSignificant($tokens, $tableIndex + 1);
            $existsIndex = $notIndex === null ? null : self::nextSignificant($tokens, $notIndex + 1);
            if ($notIndex === null || !self::isWord($tokens[$notIndex], 'NOT')
                || $existsIndex === null || !self::isWord($tokens[$existsIndex], 'EXISTS')) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
            }
            $tableIndex = self::nextSignificant($tokens, $existsIndex + 1);
        } else {
            $tableIndex = self::nextSignificant($tokens, $tableIndex + 1);
        }
        if ($tableIndex === null || !in_array($tokens[$tableIndex][0], ['word', 'identifier'], true)) {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
        }
        $schema = null;
        $table = self::runtimeIdentifier($tokens[$tableIndex]);
        $afterTable = self::nextSignificant($tokens, $tableIndex + 1);
        if ($afterTable !== null && $tokens[$afterTable] === ['symbol', '.']) {
            $qualifiedTable = self::nextSignificant($tokens, $afterTable + 1);
            if ($qualifiedTable === null || !in_array($tokens[$qualifiedTable][0], ['word', 'identifier'], true)) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
            }
            $schema = $table;
            $table = self::runtimeIdentifier($tokens[$qualifiedTable]);
            $afterTable = self::nextSignificant($tokens, $qualifiedTable + 1);
        }

        if ($command === 'CREATE') {
            if (!self::hasBalancedCreateDefinition($tokens, $afterTable)) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
            }
            if (($resolver)('table', $schema, $table, null) !== true) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
            }
            return [self::tokenize('DO $erp_runtime_ddl$ BEGIN NULL; END $erp_runtime_ddl$'), "table:{$schema}:{$table}:exists"];
        }

        if ($afterTable === null || self::nextWord($tokens, $afterTable) !== 'ADD') {
            throw new SqlTranslationException('MYSQL_RUNTIME_ALTER_UNSUPPORTED');
        }
        $addIndex = self::nextSignificant($tokens, $afterTable);
        $definitionIndex = self::nextSignificant($tokens, $addIndex + 1);
        $uniqueIndex = $definitionIndex !== null && self::isWord($tokens[$definitionIndex], 'UNIQUE');
        if ($uniqueIndex) {
            $definitionIndex = self::nextSignificant($tokens, $definitionIndex + 1);
        }
        $namedIndex = $definitionIndex !== null && (self::isWord($tokens[$definitionIndex], 'INDEX')
            || self::isWord($tokens[$definitionIndex], 'KEY'));
        if ($namedIndex) {
            $indexNameIndex = self::nextSignificant($tokens, $definitionIndex + 1);
            if ($indexNameIndex === null || !in_array($tokens[$indexNameIndex][0], ['word', 'identifier'], true)
                || !self::hasBalancedAddDefinition($tokens, $indexNameIndex)) {
                throw new SqlTranslationException('MYSQL_RUNTIME_ALTER_UNSUPPORTED');
            }
            $indexName = self::runtimeIdentifier($tokens[$indexNameIndex]);
            if (($resolver)($uniqueIndex ? 'unique_index' : 'index', $schema, $table, $indexName) !== true) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
            }
            return [self::tokenize('DO $erp_runtime_ddl$ BEGIN NULL; END $erp_runtime_ddl$'), "index:{$schema}:{$table}:{$indexName}:exists"];
        }

        $columnIndex = $definitionIndex;
        if ($columnIndex !== null && self::isWord($tokens[$columnIndex], 'COLUMN')) {
            $columnIndex = self::nextSignificant($tokens, $columnIndex + 1);
        }
        if ($columnIndex !== null && self::isWord($tokens[$columnIndex], 'IF')) {
            $notIndex = self::nextSignificant($tokens, $columnIndex + 1);
            $existsIndex = $notIndex === null ? null : self::nextSignificant($tokens, $notIndex + 1);
            if ($notIndex === null || !self::isWord($tokens[$notIndex], 'NOT')
                || $existsIndex === null || !self::isWord($tokens[$existsIndex], 'EXISTS')) {
                throw new SqlTranslationException('MYSQL_RUNTIME_ALTER_UNSUPPORTED');
            }
            $columnIndex = self::nextSignificant($tokens, $existsIndex + 1);
        }
        if ($columnIndex === null || !in_array($tokens[$columnIndex][0], ['word', 'identifier'], true)) {
            throw new SqlTranslationException('MYSQL_RUNTIME_ALTER_UNSUPPORTED');
        }
        $column = self::runtimeIdentifier($tokens[$columnIndex]);
        if (in_array(strtoupper($column), ['INDEX', 'KEY', 'UNIQUE', 'CONSTRAINT', 'PRIMARY', 'FOREIGN', 'CHECK', 'FULLTEXT'], true)
            || self::hasTopLevelComma($tokens, $columnIndex + 1)) {
            throw new SqlTranslationException('MYSQL_RUNTIME_ALTER_UNSUPPORTED');
        }
        if (($resolver)('column', $schema, $table, $column) !== true) {
            throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OBJECT_UNPROVEN');
        }
        return [self::tokenize('DO $erp_runtime_ddl$ BEGIN NULL; END $erp_runtime_ddl$'), "column:{$schema}:{$table}:{$column}:exists"];
    }

    private static function hasBalancedCreateDefinition(array $tokens, ?int $afterTable): bool
    {
        if ($afterTable === null) {
            return false;
        }
        $open = self::nextSignificant($tokens, $afterTable);
        if ($open === null || $tokens[$open] !== ['symbol', '(']) {
            return false;
        }
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i][0] !== 'symbol') {
                continue;
            }
            if ($tokens[$i][1] === ';' && $depth === 0) {
                return self::nextSignificant($tokens, $i + 1) === null;
            }
            if ($tokens[$i][1] === '(') {
                $depth++;
            } elseif ($tokens[$i][1] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
                if ($depth === 0) {
                    return self::onlyTerminatorAfter($tokens, $i + 1);
                }
            }
        }
        return false;
    }

    private static function hasTopLevelComma(array $tokens, int $index): bool
    {
        $depth = 0;
        for ($i = $index, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i][0] !== 'symbol') {
                continue;
            }
            if ($tokens[$i][1] === '(') {
                $depth++;
            } elseif ($tokens[$i][1] === ')') {
                $depth--;
            } elseif ($tokens[$i][1] === ',' && $depth === 0) {
                return true;
            }
        }
        return false;
    }

    private static function hasBalancedAddDefinition(array $tokens, int $nameIndex): bool
    {
        $open = self::nextSignificant($tokens, $nameIndex + 1);
        if ($open === null || $tokens[$open] !== ['symbol', '(']) {
            return false;
        }
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i][0] !== 'symbol') {
                continue;
            }
            if ($tokens[$i][1] === ';' && $depth === 0) {
                return self::nextSignificant($tokens, $i + 1) === null;
            }
            if ($tokens[$i][1] === '(') {
                $depth++;
            } elseif ($tokens[$i][1] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
                if ($depth === 0) {
                    return self::onlyTerminatorAfter($tokens, $i + 1);
                }
            }
        }
        return false;
    }

    private static function onlyTerminatorAfter(array $tokens, int $index): bool
    {
        $next = self::nextSignificant($tokens, $index);
        if ($next === null) {
            return true;
        }
        return $tokens[$next] === ['symbol', ';']
            && self::nextSignificant($tokens, $next + 1) === null;
    }

    private static function runtimeIdentifier(array $token): string
    {
        if (($token[0] ?? null) === 'identifier' && is_string($token[2] ?? null)) {
            return $token[2];
        }
        return strtolower((string)($token[1] ?? ''));
    }

    private static function hasSingleStatement(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if ($token[0] === 'symbol' && $token[1] === ';'
                && self::nextSignificant($tokens, $index + 1) !== null) {
                return false;
            }
        }
        return true;
    }

    private static function translateTokens(array $tokens): string
    {
        self::assertGroupedSelectSafe($tokens);
        $output = '';
        $insertIgnore = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind !== 'word') {
                $output .= $text === '<=>' ? 'IS NOT DISTINCT FROM' : $text;
                continue;
            }

            $keyword = strtoupper($text);
            $next = self::nextSignificant($tokens, $i + 1);
            if ($keyword === 'NOT' && $next !== null && self::isRegexOperator($tokens, $next)) {
                $i = $next - 1;
                continue;
            }
            $hasCall = $next !== null && $tokens[$next][0] === 'symbol' && $tokens[$next][1] === '(';

            if ($keyword === 'INSERT' && self::nextWord($tokens, $i + 1) === 'IGNORE') {
                $ignoreIndex = self::nextSignificant($tokens, $i + 1);
                if ($ignoreIndex === null || self::nextWord($tokens, $ignoreIndex + 1) !== 'INTO') {
                    throw new SqlTranslationException('MYSQL_INSERT_IGNORE_UNSUPPORTED');
                }
                $insertIgnore = true;
                $output .= $text;
                $i = $ignoreIndex;
                continue;
            }
            if ($keyword === 'REPLACE' && self::nextWord($tokens, $i + 1) === 'INTO') {
                throw new SqlTranslationException('MYSQL_REPLACE_INTO_UNSUPPORTED');
            }
            if (self::isStatementStart($tokens, $i) && $keyword === 'SHOW') {
                [$showSql, $showEnd] = self::translateShow($tokens, $i);
                $output .= $showSql;
                $i = $showEnd;
                continue;
            }
            if (self::isStatementStart($tokens, $i) && $keyword === 'DESCRIBE') {
                [$describeSql, $describeEnd] = self::translateDescribe($tokens, $i);
                $output .= $describeSql;
                $i = $describeEnd;
                continue;
            }
            if (self::isStatementStart($tokens, $i) && $keyword === 'DESC') {
                [$describeSql, $describeEnd] = self::translateDescribe($tokens, $i);
                $output .= $describeSql;
                $i = $describeEnd;
                continue;
            }
            if (self::isStatementStart($tokens, $i)
                && in_array($keyword, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'CALL', 'DELIMITER'], true)) {
                throw new SqlTranslationException('MYSQL_RUNTIME_DDL_OR_ROUTINE_UNSUPPORTED');
            }
            if ($keyword === 'LOCK' && self::wordSequenceAt($tokens, $i, ['LOCK', 'IN', 'SHARE', 'MODE'])) {
                throw new SqlTranslationException('MYSQL_LOCK_IN_SHARE_MODE_UNSUPPORTED');
            }
            if ($keyword === 'SQL_CALC_' . 'FOUND_ROWS') {
                throw new SqlTranslationException('MYSQL_FOUND_ROWS_UNSUPPORTED');
            }
            if (in_array($keyword, ['REG' . 'EXP', 'R' . 'LIKE'], true)) {
                if (!self::isRegexOperator($tokens, $i)) {
                    $output .= $text;
                    continue;
                }
                $previous = self::previousSignificant($tokens, $i - 1);
                $negative = $previous !== null && $tokens[$previous][0] === 'word'
                    && strtoupper($tokens[$previous][1]) === 'NOT';
                $output .= $negative ? '!~*' : '~*';
                continue;
            }
            if (in_array($keyword, self::UNSUPPORTED_FUNCTIONS, true) && $hasCall) {
                throw new SqlTranslationException('MYSQL_FUNCTION_UNSUPPORTED');
            }

            if ($keyword === 'GROUP_CONCAT' && $hasCall) {
                [$arguments, $close] = self::callArguments($tokens, $next);
                if (count($arguments) !== 1) {
                    throw new SqlTranslationException('MYSQL_GROUP_CONCAT_MULTIPLE_EXPRESSIONS_UNSUPPORTED');
                }
                $output .= self::translateGroupConcat($arguments[0]);
                $i = $close;
                continue;
            }

            if ($keyword === 'SUM' && $hasCall) {
                [$arguments, $close] = self::callArguments($tokens, $next);
                if (count($arguments) !== 1) {
                    throw new SqlTranslationException('MYSQL_SUM_ARITY_INVALID');
                }
                $argumentTokens = $arguments[0];
                $modifier = '';
                $first = self::nextSignificant($argumentTokens, 0);
                if ($first !== null && $argumentTokens[$first][0] === 'word'
                    && in_array(strtoupper($argumentTokens[$first][1]), ['ALL', 'DISTINCT'], true)) {
                    $modifier = strtoupper($argumentTokens[$first][1]) . ' ';
                    $argumentTokens = array_slice($argumentTokens, $first + 1);
                }
                if (self::onlyWhitespaceAndComments($argumentTokens)) {
                    throw new SqlTranslationException('MYSQL_SUM_ARITY_INVALID');
                }
                $isBoolean = self::isBooleanValueExpression($argumentTokens);
                if (!$isBoolean && self::containsBooleanLiteral($argumentTokens)) {
                    throw new SqlTranslationException('MYSQL_SUM_BOOLEAN_EXPRESSION_AMBIGUOUS');
                }
                $argumentSql = trim(self::translateTokens($argumentTokens));
                if ($isBoolean) {
                    $argumentSql = 'CASE ' . $argumentSql . ' WHEN TRUE THEN 1 WHEN FALSE THEN 0 ELSE NULL END';
                }
                $output .= 'SUM(' . $modifier . $argumentSql . ')';
                $i = $close;
                continue;
            }

            if ($keyword === 'INTERVAL') {
                $intervalAmount = self::nextSignificant($tokens, $i + 1);
                if ($intervalAmount !== null && $tokens[$intervalAmount][0] === 'string') {
                    $unitIndex = self::nextSignificant($tokens, $intervalAmount + 1);
                    if ($unitIndex !== null && $tokens[$unitIndex][0] === 'word') {
                        $unit = strtoupper($tokens[$unitIndex][1]);
                        $unitSql = match ($unit) {
                            'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' => strtolower($unit),
                            'QUARTER' => '3 months',
                            default => null,
                        };
                        if ($unitSql !== null) {
                            $amountValue = self::stringLiteralValue($tokens[$intervalAmount][1]);
                            if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/D', $amountValue) !== 1) {
                                throw new SqlTranslationException('MYSQL_INTERVAL_AMOUNT_UNSUPPORTED');
                            }
                            $output .= '(' . $amountValue . ' * INTERVAL ' . self::quoteSqlString('1 ' . $unitSql) . ')';
                            $i = $unitIndex;
                            continue;
                        }
                    }
                    $output .= $text;
                    continue;
                }
                [$intervalSql, $end] = self::translateInterval($tokens, $i);
                $output .= $intervalSql;
                $i = $end;
                continue;
            }

            if ($hasCall && in_array($keyword, [
                'IF', 'IFNULL', 'NOW', 'CURDATE', 'CURTIME', 'DATABASE', 'DATE_FORMAT', 'DATE_ADD',
                'DATE_SUB', 'DATEDIFF', 'TIMESTAMPDIFF', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATE',
                'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'WEEKDAY', 'DAYOFWEEK', 'LAST_INSERT_ID',
                'FIND_IN_SET', 'FIELD', 'CONCAT', 'CONCAT_WS',
            ], true)) {
                [$arguments, $close] = self::callArguments($tokens, $next);
                if ($keyword === 'IF' && !self::isExplicitBooleanExpression($arguments[0] ?? [])) {
                    throw new SqlTranslationException('MYSQL_IF_CONDITION_AMBIGUOUS');
                }
                if ($keyword === 'DATABASE') {
                    $output .= self::translateDatabaseCall();
                    $i = $close;
                    continue;
                }
                $translatedArguments = array_map(static fn(array $argument): string => trim(self::translateTokens($argument)), $arguments);
                $output .= self::translateFunction($keyword, $translatedArguments);
                $i = $close;
                continue;
            }

            if ($keyword === 'LIMIT') {
                [$limitSql, $end] = self::translateLimit($tokens, $i);
                $output .= $limitSql;
                $i = $end;
                continue;
            }

            $output .= $keyword === 'ON' ? self::translateOnClause($tokens, $i) : $text;

            if ($keyword === 'ON') {
                $end = self::onDuplicateEnd($tokens, $i);
                if ($end !== null) {
                    $i = $end;
                }
            }
        }

        if ($insertIgnore) {
            foreach ($tokens as [$kind, $text]) {
                if ($kind === 'comment' || ($kind === 'symbol' && $text === ';')) {
                    throw new SqlTranslationException('MYSQL_INSERT_IGNORE_TRAILING_SYNTAX_UNSUPPORTED');
                }
            }
            $output .= ' ON CONFLICT DO NOTHING';
        }
        return $output;
    }

    private static function translateFunction(string $name, array $arguments): string
    {
        if ($name === 'IFNULL') {
            self::requireArity($arguments, 2, 'MYSQL_IFNULL_ARITY_INVALID');
            return 'COALESCE(' . implode(', ', $arguments) . ')';
        }
        if ($name === 'IF') {
            self::requireArity($arguments, 3, 'MYSQL_IF_ARITY_INVALID');
            return 'CASE WHEN ' . $arguments[0] . ' THEN ' . $arguments[1] . ' ELSE ' . $arguments[2] . ' END';
        }
        if ($name === 'DATABASE') {
            self::requireArity($arguments, 0, 'MYSQL_DATABASE_ARITY_INVALID');
            return 'current_database()';
        }
        if ($name === 'CURDATE') {
            self::requireArity($arguments, 0, 'MYSQL_CURDATE_ARITY_INVALID');
            return 'CURRENT_DATE';
        }
        if ($name === 'CURTIME') {
            if (count($arguments) > 1 || (isset($arguments[0]) && preg_match('/\A[0-6]\z/D', trim($arguments[0])) !== 1)) {
                throw new SqlTranslationException('MYSQL_CURTIME_PRECISION_UNSUPPORTED');
            }
            return isset($arguments[0]) ? 'LOCALTIME(' . trim($arguments[0]) . ')' : 'LOCALTIME(0)';
        }
        if ($name === 'NOW') {
            if (count($arguments) > 1 || (isset($arguments[0]) && preg_match('/\A[0-6]\z/D', trim($arguments[0])) !== 1)) {
                throw new SqlTranslationException('MYSQL_NOW_PRECISION_UNSUPPORTED');
            }
            return isset($arguments[0]) ? 'LOCALTIMESTAMP(' . trim($arguments[0]) . ')' : 'LOCALTIMESTAMP(0)';
        }
        if ($name === 'DATE_FORMAT') {
            self::requireArity($arguments, 2, 'MYSQL_DATE_FORMAT_ARITY_INVALID');
            return 'to_char(' . $arguments[0] . ', ' . self::quoteSqlString(self::postgresDateFormat(self::stringLiteralValue($arguments[1]))) . ')';
        }
        if ($name === 'DATE_ADD' || $name === 'DATE_SUB') {
            self::requireArity($arguments, 2, 'MYSQL_DATE_ARITHMETIC_ARITY_INVALID');
            if (!str_contains(strtoupper($arguments[1]), 'INTERVAL')) {
                throw new SqlTranslationException('MYSQL_DATE_INTERVAL_UNSUPPORTED');
            }
            return '(' . $arguments[0] . ($name === 'DATE_ADD' ? ' + ' : ' - ') . $arguments[1] . ')';
        }
        if ($name === 'DATEDIFF') {
            self::requireArity($arguments, 2, 'MYSQL_DATEDIFF_ARITY_INVALID');
            return '(' . $arguments[0] . '::date - ' . $arguments[1] . '::date)';
        }
        if ($name === 'TIMESTAMPDIFF') {
            self::requireArity($arguments, 3, 'MYSQL_TIMESTAMPDIFF_ARITY_INVALID');
            return self::translateTimestampDiff($arguments[0], $arguments[1], $arguments[2]);
        }
        if ($name === 'UNIX_TIMESTAMP') {
            if ($arguments === []) {
                return 'EXTRACT(EPOCH FROM CURRENT_TIMESTAMP)::bigint';
            }
            self::requireArity($arguments, 1, 'MYSQL_UNIX_TIMESTAMP_ARITY_INVALID');
            return 'EXTRACT(EPOCH FROM ' . $arguments[0] . ')::bigint';
        }
        if ($name === 'FROM_UNIXTIME') {
            self::requireArity($arguments, 1, 'MYSQL_FROM_UNIXTIME_ARITY_INVALID');
            return '(to_timestamp(' . $arguments[0] . ') AT TIME ZONE ' . self::quoteSqlString('America/Sao_Paulo') . ')';
        }
        if ($name === 'LAST_INSERT_ID') {
            self::requireArity($arguments, 0, 'MYSQL_LAST_INSERT_ID_ARITY_INVALID');
            return 'lastval()';
        }
        if ($name === 'FIND_IN_SET') {
            self::requireArity($arguments, 2, 'MYSQL_FIND_IN_SET_ARITY_INVALID');
            return '(CASE WHEN ' . $arguments[0] . ' IS NULL OR ' . $arguments[1]
                . ' IS NULL THEN NULL ELSE COALESCE(array_position(string_to_array(' . $arguments[1]
                . '::text, ' . self::quoteSqlString(',') . '), ' . $arguments[0] . '::text), 0) END)';
        }
        if ($name === 'FIELD') {
            if (count($arguments) < 2) {
                throw new SqlTranslationException('MYSQL_FIELD_ARITY_INVALID');
            }
            $expression = array_shift($arguments);
            $allStrings = count(array_filter($arguments, static fn(string $argument): bool => $argument === 'NULL' || (strlen($argument) >= 2 && $argument[0] === "'" && $argument[strlen($argument) - 1] === "'"))) === count($arguments);
            $allNumbers = count(array_filter($arguments, static fn(string $argument): bool => $argument === 'NULL' || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $argument) === 1)) === count($arguments);
            if (!$allStrings && !$allNumbers) {
                throw new SqlTranslationException('MYSQL_FIELD_ARGUMENT_TYPES_UNSUPPORTED');
            }
            $expressionIsNumber = preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', trim($expression)) === 1;
            $expressionIsString = preg_match("/\A'(?:''|[^'])*'\z/D", trim($expression)) === 1;
            if (!$expressionIsNumber && !$expressionIsString && strtoupper(trim($expression)) !== 'NULL') {
                throw new SqlTranslationException('MYSQL_FIELD_DYNAMIC_TYPE_UNSUPPORTED');
            }
            $numericStringCandidates = false;
            if ($allStrings && $expressionIsNumber) {
                $numericStringCandidates = true;
                foreach ($arguments as $candidate) {
                    if ($candidate === 'NULL') {
                        continue;
                    }
                    $value = self::stringLiteralValue($candidate);
                    if (preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/D', $value) !== 1) {
                        throw new SqlTranslationException('MYSQL_FIELD_MIXED_TYPE_COMPARISON_UNSUPPORTED');
                    }
                }
            }
            if ($allNumbers && $expressionIsString) {
                $value = self::stringLiteralValue(trim($expression));
                if (preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/D', $value) !== 1) {
                    throw new SqlTranslationException('MYSQL_FIELD_MIXED_TYPE_COMPARISON_UNSUPPORTED');
                }
            }
            $expressionType = ($allNumbers || $numericStringCandidates) ? 'numeric' : 'text';
            $typedCandidates = array_map(
                static fn(string $candidate): string => $candidate === 'NULL' ? 'NULL::' . $expressionType : '(' . $candidate . ')::' . $expressionType,
                $arguments
            );
            return '(SELECT CASE WHEN field_expr.value IS NULL THEN 0 ELSE COALESCE(array_position(ARRAY['
                . implode(', ', $typedCandidates) . '], field_expr.value), 0) END FROM (VALUES (' . $expression
                . '::' . $expressionType . ')) AS field_expr(value))';
        }
        if ($name === 'CONCAT' || $name === 'CONCAT_WS') {
            if (($name === 'CONCAT' && count($arguments) < 1) || ($name === 'CONCAT_WS' && count($arguments) < 2)) {
                throw new SqlTranslationException('MYSQL_CONCAT_ARITY_INVALID');
            }
            if ($name === 'CONCAT') {
                $parts = array_map(static fn(string $argument): string => '(' . $argument . ')::text', $arguments);
                return '(' . implode(' || ', $parts) . ')';
            }
            return $name . '(' . implode(', ', $arguments) . ')';
        }
        if (in_array($name, ['DATE', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'WEEKDAY', 'DAYOFWEEK'], true)) {
            self::requireArity($arguments, 1, 'MYSQL_DATE_PART_ARITY_INVALID');
            if ($name === 'DATE') {
                return '(' . $arguments[0] . ')::date';
            }
            $part = match ($name) {
                'YEAR' => 'YEAR',
                'MONTH' => 'MONTH',
                'DAY' => 'DAY',
                'HOUR' => 'HOUR',
                'MINUTE' => 'MINUTE',
                'WEEKDAY' => 'ISODOW',
                'DAYOFWEEK' => 'DOW',
                default => throw new SqlTranslationException('MYSQL_DATE_PART_UNSUPPORTED'),
            };
            $value = 'EXTRACT(' . $part . ' FROM ' . $arguments[0] . ')::int';
            return $name === 'WEEKDAY' ? '(' . $value . ' - 1)' : ($name === 'DAYOFWEEK' ? '(' . $value . ' + 1)' : $value);
        }
        throw new SqlTranslationException('MYSQL_FUNCTION_UNSUPPORTED');
    }

    private static function translateDatabaseCall(): string
    {
        return 'current_schema()';
    }

    private static function translateTimestampDiff(string $unit, string $start, string $end): string
    {
        $unit = strtoupper(trim($unit));
        if (preg_match('/\A[A-Z]+\z/D', $unit) !== 1) {
            throw new SqlTranslationException('MYSQL_TIMESTAMPDIFF_UNIT_UNSUPPORTED');
        }
        $startTimestamp = 'CAST((' . $start . ') AS timestamp)';
        $endTimestamp = 'CAST((' . $end . ') AS timestamp)';
        $difference = '(' . $endTimestamp . ' - ' . $startTimestamp . ')';
        return match ($unit) {
            'MICROSECOND' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . ') * 1000000)::bigint',
            'SECOND' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . '))::bigint',
            'MINUTE' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . ') / 60)::bigint',
            'HOUR' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . ') / 3600)::bigint',
            'DAY' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . ') / 86400)::bigint',
            'WEEK' => 'TRUNC(EXTRACT(EPOCH FROM ' . $difference . ') / 604800)::bigint',
            'MONTH' => '(EXTRACT(YEAR FROM AGE(' . $endTimestamp . ', ' . $startTimestamp . ')) * 12 + EXTRACT(MONTH FROM AGE(' . $endTimestamp . ', ' . $startTimestamp . ')))::bigint',
            'QUARTER' => 'TRUNC((EXTRACT(YEAR FROM AGE(' . $endTimestamp . ', ' . $startTimestamp . ')) * 12 + EXTRACT(MONTH FROM AGE(' . $endTimestamp . ', ' . $startTimestamp . '))) / 3)::bigint',
            'YEAR' => 'EXTRACT(YEAR FROM AGE(' . $endTimestamp . ', ' . $startTimestamp . '))::bigint',
            default => throw new SqlTranslationException('MYSQL_TIMESTAMPDIFF_UNIT_UNSUPPORTED'),
        };
    }

    private static function translateGroupConcat(array $tokens): string
    {
        if ($tokens === []) {
            throw new SqlTranslationException('MYSQL_GROUP_CONCAT_ARGUMENT_UNSUPPORTED');
        }
        $distinct = self::nextWord($tokens, 0) === 'DISTINCT';
        $expressionStart = $distinct ? self::nextSignificant($tokens, self::nextSignificant($tokens, 0) + 1) : 0;
        if ($expressionStart === null) {
            throw new SqlTranslationException('MYSQL_GROUP_CONCAT_ARGUMENT_UNSUPPORTED');
        }

        $orderStart = null;
        $separatorStart = null;
        $depth = 0;
        for ($i = $expressionStart; $i < count($tokens); $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $depth--;
            } elseif ($depth === 0 && $kind === 'word' && strtoupper($text) === 'SEPARATOR') {
                $separatorStart = $i;
                break;
            } elseif ($depth === 0 && $kind === 'word' && strtoupper($text) === 'ORDER'
                && self::nextWord($tokens, $i + 1) === 'BY') {
                $orderStart = $i;
            } elseif ($depth === 0 && $kind === 'symbol' && $text === ',') {
                throw new SqlTranslationException('MYSQL_GROUP_CONCAT_MULTIPLE_EXPRESSIONS_UNSUPPORTED');
            }
        }

        $expressionEnd = min(array_filter([$orderStart, $separatorStart, count($tokens)], static fn(?int $value): bool => $value !== null));
        $expressionTokens = array_slice($tokens, $expressionStart, $expressionEnd - $expressionStart);
        $expression = trim(self::translateTokens($expressionTokens));
        if ($expression === '') {
            throw new SqlTranslationException('MYSQL_GROUP_CONCAT_ARGUMENT_UNSUPPORTED');
        }
        if ($distinct && $orderStart !== null) {
            throw new SqlTranslationException('MYSQL_GROUP_CONCAT_DISTINCT_ORDER_UNSUPPORTED');
        }

        $separator = ',';
        if ($separatorStart !== null) {
            $separatorIndex = self::nextSignificant($tokens, $separatorStart + 1);
            if ($separatorIndex === null || $tokens[$separatorIndex][0] !== 'string') {
                throw new SqlTranslationException('MYSQL_GROUP_CONCAT_SEPARATOR_UNSUPPORTED');
            }
            $separator = self::stringLiteralValue($tokens[$separatorIndex][1]);
            if (self::nextSignificant($tokens, $separatorIndex + 1) !== null) {
                throw new SqlTranslationException('MYSQL_GROUP_CONCAT_TRAILING_SYNTAX_UNSUPPORTED');
            }
        }

        $order = '';
        if ($orderStart !== null) {
            $byIndex = self::nextSignificant($tokens, $orderStart + 1);
            $orderBodyStart = $byIndex === null ? null : self::nextSignificant($tokens, $byIndex + 1);
            $orderEnd = $separatorStart ?? count($tokens);
            $orderTokens = $orderBodyStart === null ? [] : array_slice($tokens, $orderBodyStart, $orderEnd - $orderBodyStart);
            $orderSql = trim(self::translateTokens($orderTokens));
            if ($orderSql === '') {
                throw new SqlTranslationException('MYSQL_GROUP_CONCAT_ORDER_UNSUPPORTED');
            }
            $order = ' ORDER BY ' . $orderSql;
        }

        return 'string_agg(' . ($distinct ? 'DISTINCT ' : '') . $expression . '::text, '
            . self::quoteSqlString($separator) . $order . ')';
    }

    private static function translateInterval(array $tokens, int $index): array
    {
        $amountIndex = self::nextSignificant($tokens, $index + 1);
        if ($amountIndex === null) {
            throw new SqlTranslationException('MYSQL_INTERVAL_AMOUNT_UNSUPPORTED');
        }
        $amount = $tokens[$amountIndex];
        $end = $amountIndex;
        $amountSql = $amount[1];
        if ($amount[0] === 'symbol' && in_array($amount[1], ['+', '-'], true)) {
            $signedAmountIndex = self::nextSignificant($tokens, $amountIndex + 1);
            if ($signedAmountIndex === null || !in_array($tokens[$signedAmountIndex][0], ['number', 'symbol'], true)
                || ($tokens[$signedAmountIndex][0] === 'symbol' && $tokens[$signedAmountIndex][1] !== '?')) {
                throw new SqlTranslationException('MYSQL_INTERVAL_AMOUNT_UNSUPPORTED');
            }
            $amountSql .= $tokens[$signedAmountIndex][1];
            $end = $signedAmountIndex;
        } elseif (!in_array($amount[0], ['number'], true) && !($amount[0] === 'symbol' && $amount[1] === '?')) {
            throw new SqlTranslationException('MYSQL_INTERVAL_AMOUNT_UNSUPPORTED');
        }

        $unitIndex = self::nextSignificant($tokens, $end + 1);
        if ($unitIndex === null || $tokens[$unitIndex][0] !== 'word') {
            throw new SqlTranslationException('MYSQL_INTERVAL_UNIT_UNSUPPORTED');
        }
        $unit = strtoupper($tokens[$unitIndex][1]);
        $unitSql = match ($unit) {
            'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' => strtolower($unit),
            'QUARTER' => '3 months',
            default => throw new SqlTranslationException('MYSQL_INTERVAL_UNIT_UNSUPPORTED'),
        };
        return ['(' . $amountSql . ' * INTERVAL ' . self::quoteSqlString('1 ' . $unitSql) . ')', $unitIndex];
    }

    private static function stringLiteralValue(string $literal): string
    {
        if (strlen($literal) < 2 || $literal[0] !== "'" || $literal[strlen($literal) - 1] !== "'") {
            throw new SqlTranslationException('MYSQL_FORMAT_LITERAL_REQUIRED');
        }
        if (str_contains($literal, "\0")) {
            throw new SqlTranslationException('MYSQL_LITERAL_NUL_UNSUPPORTED');
        }
        if (str_contains($literal, '\\')) {
            throw new SqlTranslationException('MYSQL_FORMAT_BACKSLASH_UNSUPPORTED');
        }
        return str_replace("''", "'", substr($literal, 1, -1));
    }

    private static function postgresDateFormat(string $format): string
    {
        $tokens = [
            'd' => 'DD', 'm' => 'MM', 'Y' => 'YYYY', 'H' => 'HH24', 'i' => 'MI', 's' => 'SS',
            'y' => 'YY', 'M' => 'FMMonth', 'b' => 'FMMon', 'W' => 'FMDay', 'a' => 'FMDy',
            'e' => 'FMDD', 'c' => 'FMMM', 'j' => 'DDD', 'p' => 'AM',
        ];
        $output = '';
        $literal = '';
        $flushLiteral = static function () use (&$output, &$literal): void {
            if ($literal !== '') {
                $output .= '"' . str_replace('"', '""', $literal) . '"';
                $literal = '';
            }
        };
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            if ($format[$i] !== '%') {
                $literal .= $format[$i];
                continue;
            }
            if (++$i >= $length) {
                throw new SqlTranslationException('MYSQL_DATE_FORMAT_SPECIFIER_INVALID');
            }
            if ($format[$i] === '%') {
                $literal .= '%';
                continue;
            }
            if (!isset($tokens[$format[$i]])) {
                throw new SqlTranslationException('MYSQL_DATE_FORMAT_SPECIFIER_UNSUPPORTED');
            }
            $flushLiteral();
            $output .= $tokens[$format[$i]];
        }
        $flushLiteral();
        return $output;
    }

    private static function quoteSqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function translateShow(array $tokens, int $index): array
    {
        $kindIndex = self::nextSignificant($tokens, $index + 1);
        if ($kindIndex === null || $tokens[$kindIndex][0] !== 'word') {
            throw new SqlTranslationException('MYSQL_SHOW_FORM_UNSUPPORTED');
        }
        $kind = strtoupper($tokens[$kindIndex][1]);
        if ($kind === 'TABLES') {
            $next = self::nextSignificant($tokens, $kindIndex + 1);
            if ($next !== null && self::isWord($tokens[$next], 'LIKE')) {
                $patternIndex = self::nextSignificant($tokens, $next + 1);
                if ($patternIndex === null || !self::isStringOrPlaceholder($tokens[$patternIndex])) {
                    throw new SqlTranslationException('MYSQL_SHOW_TABLES_PATTERN_UNSUPPORTED');
                }
                $pattern = $tokens[$patternIndex][1];
                self::assertMetadataStatementEnd($tokens, $patternIndex);
                return [
                    'SELECT table_name AS "Tables_in_erp" FROM information_schema.tables '
                        . "WHERE table_schema = current_schema() AND table_type IN ('BASE TABLE', 'VIEW') AND table_name LIKE " . $pattern,
                    $patternIndex,
                ];
            }
            self::assertMetadataStatementEnd($tokens, $kindIndex);
            return [
                "SELECT table_name AS \"Tables_in_erp\" FROM information_schema.tables WHERE table_schema = current_schema() AND table_type IN ('BASE TABLE', 'VIEW')",
                $kindIndex,
            ];
        }

        if (in_array($kind, ['COLUMNS', 'FIELDS'], true)) {
            $fromIndex = self::nextSignificant($tokens, $kindIndex + 1);
            if ($fromIndex === null || !in_array(strtoupper($tokens[$fromIndex][1]), ['FROM', 'IN'], true)) {
                throw new SqlTranslationException('MYSQL_SHOW_COLUMNS_FORM_UNSUPPORTED');
            }
            [$table, $tableIndex] = self::readSimpleIdentifier($tokens, $fromIndex + 1);
            $filterSql = '';
            $end = $tableIndex;
            $next = self::nextSignificant($tokens, $tableIndex + 1);
            if ($next !== null && self::isWord($tokens[$next], 'LIKE')) {
                $patternIndex = self::nextSignificant($tokens, $next + 1);
                if ($patternIndex === null || !self::isStringOrPlaceholder($tokens[$patternIndex])) {
                    throw new SqlTranslationException('MYSQL_SHOW_COLUMNS_PATTERN_UNSUPPORTED');
                }
                $filterSql = ' AND a.attname LIKE ' . $tokens[$patternIndex][1];
                $end = $patternIndex;
            }
            self::assertMetadataStatementEnd($tokens, $end);
            return [self::columnsQuery($table, $filterSql), $end];
        }

        if (in_array($kind, ['INDEX', 'INDEXES', 'KEYS'], true)) {
            $fromIndex = self::nextSignificant($tokens, $kindIndex + 1);
            if ($fromIndex === null || !in_array(strtoupper($tokens[$fromIndex][1]), ['FROM', 'IN'], true)) {
                throw new SqlTranslationException('MYSQL_SHOW_INDEX_FORM_UNSUPPORTED');
            }
            [$table, $tableIndex] = self::readSimpleIdentifier($tokens, $fromIndex + 1);
            $filterSql = '';
            $end = $tableIndex;
            $next = self::nextSignificant($tokens, $tableIndex + 1);
            if ($next !== null && self::isWord($tokens[$next], 'WHERE')) {
                $keyName = self::nextSignificant($tokens, $next + 1);
                $equals = $keyName === null ? null : self::nextSignificant($tokens, $keyName + 1);
                $valueIndex = $equals === null ? null : self::nextSignificant($tokens, $equals + 1);
                if ($keyName === null || !self::isWord($tokens[$keyName], 'KEY_NAME') || $equals === null
                    || $tokens[$equals] !== ['symbol', '='] || $valueIndex === null || !self::isStringOrPlaceholder($tokens[$valueIndex])) {
                    throw new SqlTranslationException('MYSQL_SHOW_INDEX_FILTER_UNSUPPORTED');
                }
                $filterSql = ' AND idx.relname = ' . $tokens[$valueIndex][1];
                $end = $valueIndex;
            }
            self::assertMetadataStatementEnd($tokens, $end);
            return [self::indexQuery($table, $filterSql), $end];
        }

        throw new SqlTranslationException('MYSQL_SHOW_FORM_UNSUPPORTED');
    }

    private static function translateDescribe(array $tokens, int $index): array
    {
        [$table, $end] = self::readSimpleIdentifier($tokens, $index + 1);
        $filterSql = '';
        $next = self::nextSignificant($tokens, $end + 1);
        if ($next !== null && self::isWord($tokens[$next], 'LIKE')) {
            $patternIndex = self::nextSignificant($tokens, $next + 1);
            if ($patternIndex === null || !self::isStringOrPlaceholder($tokens[$patternIndex])) {
                throw new SqlTranslationException('MYSQL_DESCRIBE_PATTERN_UNSUPPORTED');
            }
            $filterSql = ' AND a.attname LIKE ' . $tokens[$patternIndex][1];
            $end = $patternIndex;
        }
        self::assertMetadataStatementEnd($tokens, $end);
        return [self::columnsQuery($table, $filterSql), $end];
    }

    private static function assertMetadataStatementEnd(array $tokens, int $end): void
    {
        $next = self::nextSignificant($tokens, $end + 1);
        if ($next === null) {
            return;
        }
        if ($tokens[$next] === ['symbol', ';'] && self::nextSignificant($tokens, $next + 1) === null) {
            return;
        }
        throw new SqlTranslationException('MYSQL_METADATA_TRAILING_SYNTAX_UNSUPPORTED');
    }

    private static function readSimpleIdentifier(array $tokens, int $index): array
    {
        $index = self::nextSignificant($tokens, $index);
        if ($index === null || !in_array($tokens[$index][0], ['word', 'identifier'], true)) {
            throw new SqlTranslationException('MYSQL_METADATA_IDENTIFIER_UNSUPPORTED');
        }
        return [strtolower($tokens[$index][1]), $index];
    }

    private static function isWord(array $token, string $expected): bool
    {
        return $token[0] === 'word' && strtoupper($token[1]) === $expected;
    }

    private static function isStringOrPlaceholder(array $token): bool
    {
        return $token[0] === 'string' || ($token[0] === 'symbol' && $token[1] === '?');
    }

    private static function columnsQuery(string $table, string $filterSql): string
    {
        $tableLiteral = self::quoteSqlString($table);
        return 'SELECT a.attname AS "Field", pg_catalog.format_type(a.atttypid, a.atttypmod) AS "Type", '
            . "CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS \"Null\", "
            . "CASE WHEN EXISTS (SELECT 1 FROM pg_catalog.pg_index pi WHERE pi.indrelid = c.oid AND pi.indisprimary AND a.attnum = ANY(pi.indkey)) THEN 'PRI' "
            . "WHEN EXISTS (SELECT 1 FROM pg_catalog.pg_index pi WHERE pi.indrelid = c.oid AND pi.indisunique AND a.attnum = ANY(pi.indkey)) THEN 'UNI' "
            . "WHEN EXISTS (SELECT 1 FROM pg_catalog.pg_index pi WHERE pi.indrelid = c.oid AND a.attnum = ANY(pi.indkey)) THEN 'MUL' ELSE '' END AS \"Key\", "
            . 'pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS "Default", '
            . "CASE WHEN a.attidentity <> '' THEN 'auto_increment' ELSE '' END AS \"Extra\" "
            . 'FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid = a.attrelid '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . 'LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum '
            . "WHERE n.nspname = current_schema() AND c.relname = {$tableLiteral} AND a.attnum > 0 AND NOT a.attisdropped"
            . $filterSql . ' ORDER BY a.attnum';
    }

    private static function indexQuery(string $table, string $filterSql): string
    {
        $tableLiteral = self::quoteSqlString($table);
        return 'SELECT tbl.relname AS "Table", CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS "Non_unique", '
            . 'idx.relname AS "Key_name", keypart.seq AS "Seq_in_index", att.attname AS "Column_name", '
            . "CASE WHEN (ix.indoption[keypart.seq - 1] & 1) = 1 THEN 'D' ELSE 'A' END AS \"Collation\", "
            . 'NULL::bigint AS "Cardinality", NULL::integer AS "Sub_part", NULL::text AS "Packed", '
            . "CASE WHEN att.attnotnull THEN '' ELSE 'YES' END AS \"Null\", upper(am.amname) AS \"Index_type\", "
            . "''::text AS \"Comment\", ''::text AS \"Index_comment\", 'YES'::text AS \"Visible\", "
            . 'CASE WHEN att.attnum IS NULL THEN pg_catalog.pg_get_indexdef(idx.oid, keypart.seq::integer, true) ELSE NULL END AS "Expression" '
            . 'FROM pg_catalog.pg_class tbl JOIN pg_catalog.pg_namespace ns ON ns.oid = tbl.relnamespace '
            . 'JOIN pg_catalog.pg_index ix ON ix.indrelid = tbl.oid JOIN pg_catalog.pg_class idx ON idx.oid = ix.indexrelid '
            . 'JOIN pg_catalog.pg_am am ON am.oid = idx.relam '
            . 'JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS keypart(attnum, seq) ON TRUE '
            . 'LEFT JOIN pg_catalog.pg_attribute att ON att.attrelid = tbl.oid AND att.attnum = keypart.attnum '
            . "WHERE ns.nspname = current_schema() AND tbl.relname = {$tableLiteral}" . $filterSql
            . ' ORDER BY idx.relname, keypart.seq';
    }

    private static function callArguments(array $tokens, int $open): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = $open + 1; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
                $current[] = $tokens[$i];
                continue;
            }
            if ($kind === 'symbol' && $text === ')') {
                if ($depth === 0) {
                    if ($current !== [] || $arguments !== []) {
                        $arguments[] = $current;
                    }
                    return [$arguments, $i];
                }
                $depth--;
                $current[] = $tokens[$i];
                continue;
            }
            if ($kind === 'symbol' && $text === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }
            $current[] = $tokens[$i];
        }
        throw new SqlTranslationException('SQL_FUNCTION_CALL_UNTERMINATED');
    }

    private static function translateOnClause(array $tokens, int $index): string
    {
        if (self::nextWord($tokens, $index + 1) === 'DUPLICATE'
            && self::wordSequenceAt($tokens, $index, ['ON', 'DUPLICATE', 'KEY', 'UPDATE'])) {
            throw new SqlTranslationException('MYSQL_ON_DUPLICATE_KEY_UNSUPPORTED');
        }
        return $tokens[$index][1];
    }

    private static function onDuplicateEnd(array $tokens, int $index): ?int
    {
        if (!self::wordSequenceAt($tokens, $index, ['ON', 'DUPLICATE', 'KEY', 'UPDATE'])) {
            return null;
        }
        return count($tokens) - 1;
    }

    private static function translateLimit(array $tokens, int $index): array
    {
        $next = self::nextSignificant($tokens, $index + 1);
        if ($next === null) {
            return [$tokens[$index][1], $index];
        }
        $comma = self::nextSignificant($tokens, $next + 1);
        if ($comma === null || $tokens[$comma] !== ['symbol', ',']) {
            return [$tokens[$index][1], $index];
        }

        $offset = $tokens[$next];
        $countIndex = self::nextSignificant($tokens, $comma + 1);
        if ($offset[0] !== 'number' || $countIndex === null || $tokens[$countIndex][0] !== 'number'
            || preg_match('/\A[0-9]+\z/D', $offset[1]) !== 1
            || preg_match('/\A[0-9]+\z/D', $tokens[$countIndex][1]) !== 1) {
            throw new SqlTranslationException('MYSQL_LIMIT_COMMA_UNSUPPORTED');
        }

        return ['LIMIT ' . $tokens[$countIndex][1] . ' OFFSET ' . $offset[1], $countIndex];
    }

    private static function wordSequenceAt(array $tokens, int $index, array $sequence): bool
    {
        foreach ($sequence as $word) {
            $index = self::nextSignificant($tokens, $index);
            if ($index === null || $tokens[$index][0] !== 'word' || strtoupper($tokens[$index][1]) !== $word) {
                return false;
            }
            $index++;
        }
        return true;
    }

    private static function nextWord(array $tokens, int $index): ?string
    {
        $index = self::nextSignificant($tokens, $index);
        return $index !== null && $tokens[$index][0] === 'word' ? strtoupper($tokens[$index][1]) : null;
    }

    private static function isRegexOperator(array $tokens, int $index): bool
    {
        $previous = self::previousSignificant($tokens, $index - 1);
        if ($previous !== null && $tokens[$previous][0] === 'word' && strtoupper($tokens[$previous][1]) === 'NOT') {
            $previous = self::previousSignificant($tokens, $previous - 1);
        }
        $next = self::nextSignificant($tokens, $index + 1);
        if ($previous === null || $next === null) {
            return false;
        }

        $isOperand = static function (array $token, bool $right): bool {
            [$kind, $text] = $token;
            if (in_array($kind, ['string', 'number', 'identifier'], true)) {
                return true;
            }
            if ($kind === 'symbol') {
                return in_array($text, $right ? ['(', '?'] : [')', ']'], true);
            }
            if ($kind !== 'word') {
                return false;
            }
            return !in_array(strtoupper($text), [
                'ALL', 'AND', 'AS', 'BETWEEN', 'BY', 'CASE', 'DELETE', 'DISTINCT', 'ELSE', 'END', 'FROM',
                'GROUP', 'HAVING', 'IN', 'INSERT', 'INTO', 'IS', 'JOIN', 'LEFT', 'LIKE', 'LIMIT', 'NOT', 'OFFSET',
                'ON', 'OR', 'ORDER', 'REG' . 'EXP', 'RETURNING', 'RIGHT', 'R' . 'LIKE', 'SELECT', 'SET', 'THEN',
                'UNION', 'UPDATE', 'VALUES', 'WHEN', 'WHERE', 'WITH',
            ], true);
        };
        return $isOperand($tokens[$previous], false) && $isOperand($tokens[$next], true);
    }

    private static function isStatementStart(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (in_array($tokens[$i][0], ['ws', 'comment'], true)) {
                continue;
            }
            return $tokens[$i][0] === 'symbol' && $tokens[$i][1] === ';';
        }
        return true;
    }

    private static function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        while ($index < $count && in_array($tokens[$index][0], ['ws', 'comment'], true)) {
            $index++;
        }
        return $index < $count ? $index : null;
    }

    private static function previousSignificant(array $tokens, int $index): ?int
    {
        for ($index = min($index, count($tokens) - 1); $index >= 0; $index--) {
            if (!in_array($tokens[$index][0], ['ws', 'comment'], true)) {
                return $index;
            }
        }
        return null;
    }

    private static function requireArity(array $arguments, int $arity, string $errorCode): void
    {
        if (count($arguments) !== $arity) {
            throw new SqlTranslationException($errorCode);
        }
    }

    private static function isExplicitBooleanExpression(array $tokens): bool
    {
        foreach ($tokens as [$kind, $text]) {
            if ($kind === 'word' && in_array(strtoupper($text), ['AND', 'BETWEEN', 'EXISTS', 'IN', 'IS', 'LIKE', 'NOT', 'OR'], true)) {
                return true;
            }
            if ($kind === 'symbol' && in_array($text, ['=', '<', '>', '!', '<=', '>=', '<>', '!=', '<=>'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function containsBooleanLiteral(array $tokens): bool
    {
        foreach ($tokens as [$kind, $text]) {
            if ($kind === 'word' && in_array(strtoupper($text), ['TRUE', 'FALSE'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function isNullValueExpression(array $tokens): bool
    {
        $significant = [];
        foreach ($tokens as $index => [$kind]) {
            if ($kind !== 'ws' && $kind !== 'comment') {
                $significant[] = $index;
            }
        }
        return count($significant) === 1
            && $tokens[$significant[0]][0] === 'word'
            && strtoupper($tokens[$significant[0]][1]) === 'NULL';
    }

    private static function isBooleanCaseExpression(array $tokens): bool
    {
        $caseDepth = 0;
        $parenDepth = 0;
        $mode = null;
        $part = [];
        $values = [];
        $closedOuterCase = false;
        $conditions = [];
        foreach ($tokens as [$kind, $text]) {
            if ($closedOuterCase) {
                if (!in_array($kind, ['ws', 'comment'], true)) {
                    return false;
                }
                continue;
            }
            $upper = $kind === 'word' ? strtoupper($text) : '';
            if ($caseDepth === 0 && $upper === 'CASE') {
                $caseDepth = 1;
                $mode = 'await_when';
                continue;
            }
            if ($caseDepth > 0 && $upper === 'CASE') {
                $caseDepth++;
                $part[] = [$kind, $text];
                continue;
            }
            if ($kind === 'symbol' && $text === '(') {
                $parenDepth++;
            } elseif ($kind === 'symbol' && $text === ')') {
                $parenDepth--;
                if ($parenDepth < 0) {
                    return false;
                }
            }
            if ($caseDepth === 1 && $parenDepth === 0 && $upper === 'WHEN') {
                if ($mode === 'value') {
                    $values[] = $part;
                    $part = [];
                } elseif ($mode !== 'await_when') {
                    return false;
                }
                $mode = 'condition';
                continue;
            }
            if ($caseDepth === 1 && $parenDepth === 0 && $upper === 'THEN') {
                if ($mode !== 'condition' || !self::isBooleanValueExpression($part)) {
                    return false;
                }
                $conditions[] = $part;
                $part = [];
                $mode = 'value';
                continue;
            }
            if ($caseDepth === 1 && $parenDepth === 0 && $upper === 'ELSE') {
                if ($mode !== 'value') {
                    return false;
                }
                $values[] = $part;
                $part = [];
                $mode = 'else_value';
                continue;
            }
            if ($upper === 'END') {
                if ($caseDepth > 1) {
                    $caseDepth--;
                    $part[] = [$kind, $text];
                    continue;
                }
                if ($caseDepth !== 1 || !in_array($mode, ['value', 'else_value'], true)) {
                    return false;
                }
                $values[] = $part;
                $part = [];
                $mode = null;
                $caseDepth = 0;
                $closedOuterCase = true;
                continue;
            }
            if ($caseDepth > 0) {
                $part[] = [$kind, $text];
            }
        }
        if (!$closedOuterCase || $caseDepth !== 0 || $values === [] || $conditions === []) {
            return false;
        }
        $hasBooleanValue = false;
        foreach ($values as $value) {
            if (self::isNullValueExpression($value)) {
                continue;
            }
            if (!self::isBooleanValueExpression($value)) {
                return false;
            }
            $hasBooleanValue = true;
        }
        return $hasBooleanValue;
    }

    private static function isBooleanValueExpression(array $tokens): bool
    {
        if (self::isTopLevelBooleanExpression($tokens)) {
            return true;
        }
        $significant = [];
        foreach ($tokens as $index => [$kind]) {
            if ($kind !== 'ws' && $kind !== 'comment') {
                $significant[] = $index;
            }
        }
        if ($significant === []) {
            return false;
        }
        $first = $tokens[$significant[0]];
        if ($first[0] !== 'word') {
            return false;
        }
        $function = strtoupper($first[1]);
        if (in_array($function, ['TRUE', 'FALSE'], true)) {
            return count($significant) === 1;
        }
        if ($function === 'CASE') {
            return self::isBooleanCaseExpression($tokens);
        }
        if (!in_array($function, ['COALESCE', 'IFNULL', 'IF'], true)) {
            return false;
        }
        $open = self::nextSignificant($tokens, $significant[0] + 1);
        if ($open === null || $tokens[$open] !== ['symbol', '(']) {
            return false;
        }
        [$arguments, $close] = self::callArguments($tokens, $open);
        if (self::nextSignificant($tokens, $close + 1) !== null) {
            return false;
        }
        $values = $function === 'IF' ? array_slice($arguments, 1) : $arguments;
        if ($function === 'IF' && count($arguments) !== 3) {
            return false;
        }
        $hasBooleanValue = false;
        foreach ($values as $value) {
            if (self::isNullValueExpression($value)) {
                continue;
            }
            if (!self::isBooleanValueExpression($value)) {
                return false;
            }
            $hasBooleanValue = true;
        }
        return $hasBooleanValue;
    }

    private static function isTopLevelBooleanExpression(array $tokens): bool
    {
        $significant = [];
        foreach ($tokens as $index => [$kind]) {
            if ($kind !== 'ws' && $kind !== 'comment') {
                $significant[] = $index;
            }
        }
        while (count($significant) >= 2) {
            $first = $significant[0];
            $last = $significant[count($significant) - 1];
            if ($tokens[$first] !== ['symbol', '('] || $tokens[$last] !== ['symbol', ')']) {
                break;
            }
            $depth = 0;
            $closesAtEnd = false;
            foreach (array_slice($tokens, $first, $last - $first + 1, true) as $index => [$kind, $text]) {
                if ($kind === 'symbol' && $text === '(') {
                    $depth++;
                } elseif ($kind === 'symbol' && $text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $closesAtEnd = $index === $last;
                        break;
                    }
                }
            }
            if (!$closesAtEnd) {
                break;
            }
            $tokens = array_slice($tokens, $first + 1, $last - $first - 1);
            $significant = array_values(array_filter(
                array_keys($tokens),
                static fn(int $index): bool => $tokens[$index][0] !== 'ws' && $tokens[$index][0] !== 'comment'
            ));
        }

        if ($significant === []) {
            return false;
        }
        $firstToken = $tokens[$significant[0]];
        if ($firstToken[0] === 'word' && in_array(strtoupper($firstToken[1]), ['CASE', 'TRUE', 'FALSE'], true)) {
            return false;
        }

        $depth = 0;
        foreach ($tokens as [$kind, $text]) {
            if ($kind === 'symbol' && $text === '(') {
                $depth++;
                continue;
            }
            if ($kind === 'symbol' && $text === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($kind === 'word' && in_array(strtoupper($text), ['AND', 'BETWEEN', 'EXISTS', 'IN', 'IS', 'LIKE', 'NOT', 'OR'], true)) {
                return true;
            }
            if ($kind === 'symbol' && in_array($text, ['=', '<', '>', '<=', '>=', '<>', '!=', '<=>'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function isWordStart(string $char): bool
    {
        return $char === '_' || $char === '$' || preg_match('/\A[A-Za-z]\z/', $char) === 1 || ord($char) >= 128;
    }

    private static function isWordPart(string $char): bool
    {
        return self::isWordStart($char) || preg_match('/\A[0-9]\z/', $char) === 1;
    }
    public static function translateStatements(
        string $sql,
        ?callable $conflictResolver = null,
        ?callable $runtimeDdlResolver = null
    ): array
    {
        $key = hash('sha256', "multi\0" . $sql);
        $hasResolver = $conflictResolver !== null || $runtimeDdlResolver !== null;
        if (!$hasResolver && array_key_exists($key, self::$cache)) {
            $cached = self::$cache[$key];
            if (isset($cached['error'])) {
                throw new SqlTranslationException($cached['error']);
            }
            return $cached['statements'];
        }

        try {
            $statements = [];
            $current = [];
            foreach (self::tokenize($sql) as $token) {
                if ($token[0] === 'symbol' && $token[1] === ';') {
                    if (!self::onlyWhitespaceAndComments($current)) {
                        $statements[] = !$hasResolver
                            ? trim(self::translateTokens($current))
                            : trim(self::translate(self::tokensToSql($current), $conflictResolver, $runtimeDdlResolver));
                    }
                    $current = [];
                    continue;
                }
                $current[] = $token;
            }
            if (!self::onlyWhitespaceAndComments($current)) {
                $statements[] = !$hasResolver
                    ? trim(self::translateTokens($current))
                    : trim(self::translate(self::tokensToSql($current), $conflictResolver, $runtimeDdlResolver));
            }
            if (!$hasResolver) {
                self::cache($key, ['statements' => $statements]);
            }
            return $statements;
        } catch (SqlTranslationException $exception) {
            if (!$hasResolver) {
                self::cache($key, ['error' => $exception->patternCode]);
            }
            throw $exception;
        }
    }

    private static function tokensToSql(array $tokens): string
    {
        $sql = '';
        foreach ($tokens as $token) {
            if (($token[0] ?? null) === 'identifier' && is_string($token[2] ?? null)) {
                $sql .= '`' . str_replace('`', '``', $token[2]) . '`';
                continue;
            }
            $sql .= (string)($token[1] ?? '');
        }
        return $sql;
    }

}
