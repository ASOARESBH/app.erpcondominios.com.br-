<?php
declare(strict_types=1);

use ERP\Database\PgsqlMysqliConnection;
use ERP\Database\PgsqlMysqliResult;

foreach ([
    'MYSQLI_ASSOC' => 1,
    'MYSQLI_NUM' => 2,
    'MYSQLI_BOTH' => 3,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/../api/db/PgsqlMysqliConnection.php';
require_once __DIR__ . '/../api/db/PgsqlMysqliStatement.php';
require_once __DIR__ . '/../api/db/PgsqlMysqliResult.php';

final class FakePdoStatement extends PDOStatement
{
    private array $bindings = [];
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(private FakePdo $owner, private string $query)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = [$value, $type];
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->owner->failureState !== null) {
            $exception = new PDOException('synthetic database failure');
            $exception->errorInfo = [$this->owner->failureState, null, 'synthetic detail'];
            throw $exception;
        }
        if ($params !== null) {
            $this->bindings = $params;
        }
        $this->owner->afterExecute($this->query);
        $this->rows = $this->owner->rowsFor($this->query);
        $this->cursor = 0;
        $capturedBindings = $this->bindings;
        foreach ($capturedBindings as &$binding) {
            if (is_array($binding) && is_resource($binding[0] ?? null)) {
                rewind($binding[0]);
                $binding[0] = stream_get_contents($binding[0]);
            }
        }
        unset($binding);
        $this->owner->executedStatements[] = ['query' => $this->query, 'params' => array_values($capturedBindings)];
        $this->owner->lastBindings = $capturedBindings;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[$this->cursor++] ?? null;
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }

    public function rowCount(): int
    {
        return $this->owner->affectedRows;
    }

    public function columnCount(): int
    {
        return $this->owner->columnCountFor($this->query, $this->rows);
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;
        return true;
    }

    public function errorCode(): ?string
    {
        return '00000';
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }
}

final class FakePdo extends PDO
{
    public array $rowsByQuery = [];
    public array $execCalls = [];
    public array $queryCalls = [];
    public array $prepareCalls = [];
    public array $executedStatements = [];
    public array $lastBindings = [];
    public int $affectedRows = 1;
    public ?string $failureState = null;
    public ?string $sequenceName = 'public.sample_id_seq';
    public string $primaryKeyColumn = 'id';
    public ?string $insertedRowId = '89';
    public ?string $sequenceCurrentValue = '88';
    public ?string $sequenceNextValue = '89';
    public ?string $sequenceTriggeredValue = null;
    private bool $transaction = false;

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'pgsql';
        }
        if ($attribute === PDO::ATTR_CLIENT_VERSION) {
            return 'synthetic-pg-client';
        }
        return null;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCalls[] = $query;
        return new FakePdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCalls[] = $query;
        $statement = new FakePdoStatement($this, $query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function exec(string $statement): int|false
    {
        $this->execCalls[] = $statement;
        return 1;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    public function rowsFor(string $query): array
    {
        if (str_contains($query, 'pg_get_serial_sequence')) {
            return [['column_name' => $this->primaryKeyColumn, 'sequence_name' => $this->sequenceName]];
        }
        if (array_key_exists($query, $this->rowsByQuery)) {
            return $this->rowsByQuery[$query];
        }
        if (PgsqlMysqliConnection::hasReturningClause($query)) {
            return $this->insertedRowId === null ? [] : [['insert_id' => $this->insertedRowId]];
        }
        return $this->rowsByQuery[$query] ?? [];
    }

    public function afterExecute(string $query): void
    {
        if (preg_match('/\A\s*INSERT\b/i', $query) !== 1) {
            return;
        }
        if ($this->sequenceNextValue !== null) {
            $this->sequenceCurrentValue = $this->sequenceTriggeredValue ?? $this->sequenceNextValue;
        }
    }

    public function columnCountFor(string $query, array $rows): int
    {
        if (preg_match('/\A\s*SELECT\b/i', $query) === 1 || PgsqlMysqliConnection::hasReturningClause($query)) {
            if (!isset($rows[0])) {
                return 1;
            }
            $numericKeys = array_filter(array_keys($rows[0]), 'is_int');
            return $numericKeys !== [] ? count($numericKeys) : count($rows[0]);
        }
        return 0;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function assertThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$passed = 0;
$test = static function (string $name, callable $operation) use (&$passed): void {
    $operation();
    $passed++;
    echo "PASS {$name}\n";
};

$test('DSN usa valores validados', static function (): void {
    assertSameValue('pgsql:host=127.0.0.1;port=5432;dbname=erp_pg_dev', PgsqlMysqliConnection::dsnFromConfig([
        'host' => '127.0.0.1', 'port' => 5432, 'database' => 'erp_pg_dev',
    ]), 'DSN inesperado.');
    assertSameValue('pgsql:host=::1;port=5432;dbname=erp_pg_dev', PgsqlMysqliConnection::dsnFromConfig([
        'host' => '::1', 'port' => 5432, 'database' => 'erp_pg_dev',
    ]), 'IPv6 loopback deveria ser aceito.');
    assertThrows(static fn() => PgsqlMysqliConnection::dsnFromConfig([
        'host' => '127.0.0.1;dbname=other', 'port' => 5432, 'database' => 'erp_pg_dev',
    ]), 'Host com conteúdo de DSN deve ser rejeitado.');
    assertThrows(static fn() => PgsqlMysqliConnection::dsnFromConfig([
        'host' => 'localhost:5432', 'port' => 5432, 'database' => 'erp_pg_dev',
    ]), 'Porta embutida no host deve ser rejeitada.');
});

$test('client_info é obtido sem imprimir dados de conexão', static function (): void {
    $connection = new PgsqlMysqliConnection(new FakePdo());
    assertSameValue('synthetic-pg-client', $connection->clientInfo(), 'client_info deve usar PDO::ATTR_CLIENT_VERSION.');
});

$test('bind_param lê o valor por referência no execute', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('SELECT id FROM sample WHERE id = ?');
    $value = 7;
    assertTrueValue($statement !== false && $statement->bind_param('i', $value), 'bind_param deveria aceitar inteiro.');
    $value = 11;
    assertTrueValue($statement->execute(), 'execute deveria funcionar.');
    assertSameValue(11, $pdo->lastBindings[1][0], 'O valor corrente da referência deve ser usado.');
    assertSameValue(PDO::PARAM_INT, $pdo->lastBindings[1][1], 'Inteiro deve usar PDO::PARAM_INT.');
    $value = 14;
    assertTrueValue($statement->execute(), 'Statement vinculado deve ser reutilizável.');
    assertSameValue(14, $pdo->lastBindings[1][0], 'A reexecução deve usar o valor atualizado da referência.');
});

$test('bind_param aceita array unpack por referência e ignora placeholders em texto SQL', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $query = "SELECT ? AS id, '?' AS literal -- ? comentário\n/* ? */";
    $statement = $connection->prepare($query);
    assertTrueValue($statement !== false, 'Statement deveria ser preparado.');
    assertSameValue(1, $statement->param_count, 'A contagem deve ignorar literais/comentários.');
    $params = [7];
    assertTrueValue($statement->bind_param('i', ...$params), 'Array unpack por referência deveria funcionar.');
    $params[0] = 19;
    assertTrueValue($statement->execute(), 'Statement deveria executar após mudar o array.');
    assertSameValue(19, $pdo->lastBindings[1][0], 'O valor atualizado no array deve ser lido no execute.');
});

$test('send_long_data acumula bytes BLOB em fragments e exige tipo b', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('INSERT INTO sample(payload) VALUES (?)');
    $placeholder = null;
    assertTrueValue($statement !== false && $statement->bind_param('b', $placeholder), 'Parâmetro BLOB deve aceitar binding por referência.');
    assertTrueValue($statement->send_long_data(0, "\x00\x01A"), 'Primeiro fragmento binário deve ser aceito.');
    assertTrueValue($statement->send_long_data(0, "B\xFF"), 'Fragmento subsequente deve ser acumulado.');
    assertTrueValue($statement->execute(), 'INSERT BLOB deve executar por PDO::PARAM_LOB.');
    $blobBinding = null;
    foreach ($pdo->executedStatements as $execution) {
        if (str_contains($execution['query'], 'INSERT INTO sample(payload)')) {
            $blobBinding = $execution['params'][0] ?? null;
            break;
        }
    }
    assertSameValue("\x00\x01AB\xFF", is_array($blobBinding) ? ($blobBinding[0] ?? null) : null, 'Bytes binários devem permanecer idênticos e ordenados.');
    assertSameValue(PDO::PARAM_LOB, is_array($blobBinding) ? ($blobBinding[1] ?? null) : null, 'O parâmetro deve ser enviado como PDO::PARAM_LOB.');

    $invalid = $connection->prepare('INSERT INTO sample(value) VALUES (?)');
    $value = 'text';
    assertTrueValue($invalid !== false && $invalid->bind_param('s', $value), 'Binding de texto deve continuar disponível.');
    assertSameValue(false, $invalid->send_long_data(0, 'not-blob'), 'send_long_data deve rejeitar parâmetro que não foi declarado como b.');
});

$test('param_count ignora dollar quotes e operador PDO escapado', static function (): void {
    $connection = new PgsqlMysqliConnection(new FakePdo());
    $query = "SELECT ? AS value, \$tag\$ ? \$tag\$ AS literal, payload ?? 'x' FROM sample WHERE id = ?";
    $statement = $connection->prepare($query);
    assertTrueValue($statement !== false, 'Statement deveria ser preparado.');
    assertSameValue(2, $statement->param_count, 'Só placeholders posicionais fora de texto/operação devem ser contados.');
    $identifierQuery = 'SELECT foo$tag$bar, ?';
    $identifierStatement = $connection->prepare($identifierQuery);
    assertTrueValue($identifierStatement !== false, 'Identificador com $ deveria ser aceito pelo preparador sintético.');
    assertSameValue(1, $identifierStatement->param_count, 'Dollar signs dentro de identificador não iniciam dollar-quote.');
    $unicodeIdentifier = $connection->prepare('SELECT é$tag$bar, ?');
    assertTrueValue($unicodeIdentifier !== false, 'Identificador Unicode com $ deveria ser aceito.');
    assertSameValue(1, $unicodeIdentifier->param_count, 'Identificador Unicode não inicia dollar-quote.');
    $nonLetterUnicodeIdentifier = $connection->prepare('SELECT é💩$tag$bar, ?');
    assertTrueValue($nonLetterUnicodeIdentifier !== false, 'Identificador com byte alto não alfabético deveria ser aceito pelo scanner PostgreSQL 18.');
    assertSameValue(1, $nonLetterUnicodeIdentifier->param_count, 'Byte não ASCII dentro de identificador impede início de dollar-quote.');
    $unicodeDollarQuote = $connection->prepare('SELECT $é$ ? $é$, ?');
    assertTrueValue($unicodeDollarQuote !== false, 'Tag dollar-quote Unicode deveria ser aceita.');
    assertSameValue(1, $unicodeDollarQuote->param_count, 'Placeholder dentro de dollar-quote Unicode deve ser ignorado.');
    $nonLetterByteTag = $connection->prepare('SELECT $💩$ ? $💩$, ?');
    assertTrueValue($nonLetterByteTag !== false, 'Tag dollar-quote com bytes não ASCII deveria ser aceita pelo scanner PostgreSQL 18.');
    assertSameValue(1, $nonLetterByteTag->param_count, 'Placeholder em tag não ASCII válida deve ser ignorado.');
    $caseSensitiveTag = $connection->prepare('SELECT $tag$ $TAG$ ? $tag$, ?');
    assertTrueValue($caseSensitiveTag !== false, 'Tag dollar-quote com variação de caixa deveria ser aceita no texto do literal.');
    assertSameValue(1, $caseSensitiveTag->param_count, 'Delimitador dollar-quote diferencia caixa e só o exato encerra o literal.');
    $digitTag = $connection->prepare('SELECT $tag1$ ? $tag1$, ?');
    assertTrueValue($digitTag !== false, 'Tag com dígitos após a primeira posição deveria ser aceita.');
    assertSameValue(1, $digitTag->param_count, 'Placeholder no dollar-quote com dígito deve ser ignorado.');
});

$test('bind_param inválido não reutiliza bindings anteriores', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('SELECT id FROM sample WHERE id = ?');
    $value = 41;
    assertTrueValue($statement !== false && $statement->bind_param('i', $value), 'Binding inicial deveria funcionar.');
    assertSameValue(false, $statement->bind_param('ii', $value), 'Binding com cardinalidade inválida deve falhar.');
    assertSameValue(false, $statement->execute(), 'Binding inválido não deve executar o valor anterior.');
    assertSameValue([], $pdo->lastBindings, 'Não deve haver execução implícita com parâmetros obsoletos.');
});

$test('bind_result e fetch atribuem colunas por referência', static function (): void {
    $pdo = new FakePdo();
    $pdo->rowsByQuery['SELECT id, nome FROM sample'] = [['id' => 12, 'nome' => 'Fixture']];
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('SELECT id, nome FROM sample');
    assertTrueValue($statement !== false && $statement->execute(), 'SELECT preparada deveria executar.');
    $id = null;
    $nome = null;
    assertTrueValue($statement->bind_result($id, $nome), 'bind_result deveria aceitar duas colunas.');
    assertTrueValue($statement->fetch(), 'fetch deveria retornar a linha sintética.');
    assertSameValue(12, $id, 'ID vinculado incorreto.');
    assertSameValue('Fixture', $nome, 'Nome vinculado incorreto.');
});

$test('colunas com nomes repetidos preservam valores posicionais', static function (): void {
    $query = 'SELECT a.id, b.id FROM a JOIN b ON b.id = a.id';
    $pdo = new FakePdo();
    $pdo->rowsByQuery[$query] = [[0 => 21, 1 => 22, 'id' => 22]];
    $connection = new PgsqlMysqliConnection($pdo);

    $rowResult = $connection->query($query);
    assertTrueValue($rowResult instanceof PgsqlMysqliResult, 'Consulta deveria retornar um resultset.');
    assertSameValue([21, 22], $rowResult->fetch_row(), 'fetch_row perdeu uma das colunas duplicadas.');

    $bothResult = $connection->query($query);
    assertTrueValue($bothResult instanceof PgsqlMysqliResult, 'Segunda consulta deveria retornar resultset.');
    assertSameValue(['id' => 22, 0 => 21, 1 => 22], $bothResult->fetch_array(MYSQLI_BOTH), 'fetch_array(MYSQLI_BOTH) não preservou as posições.');

    $statement = $connection->prepare($query);
    assertTrueValue($statement !== false && $statement->execute(), 'Statement deveria executar.');
    $first = null;
    $second = null;
    assertTrueValue($statement->bind_result($first, $second) && $statement->fetch(), 'bind_result deveria atribuir ambas as colunas.');
    assertSameValue(21, $first, 'Primeira coluna duplicada foi sobrescrita.');
    assertSameValue(22, $second, 'Segunda coluna duplicada incorreta.');
});

$test('resultset oferece fetch_assoc, fetch_row, fetch_all e data_seek', static function (): void {
    $result = new PgsqlMysqliResult([
        ['id' => 4, 'nome' => 'Teste'],
        ['id' => 5, 'nome' => 'Exemplo'],
    ]);
    assertSameValue(2, $result->num_rows, 'Contagem de linhas incorreta.');
    assertSameValue(['id' => 4, 'nome' => 'Teste'], $result->fetch_assoc(), 'fetch_assoc incorreto.');
    assertTrueValue($result->data_seek(1), 'data_seek deveria aceitar posição válida.');
    assertSameValue([5, 'Exemplo'], $result->fetch_row(), 'fetch_row incorreto.');
    assertTrueValue($result->data_seek(0), 'data_seek deveria reiniciar a leitura.');
    assertSameValue([['id' => 4, 'nome' => 'Teste'], ['id' => 5, 'nome' => 'Exemplo']], $result->fetch_all(MYSQLI_ASSOC), 'fetch_all associativo incorreto.');
});

$test('query retorna resultado buffered', static function (): void {
    $pdo = new FakePdo();
    $pdo->rowsByQuery['SELECT id FROM sample'] = [['id' => 9]];
    $connection = new PgsqlMysqliConnection($pdo);
    $result = $connection->query('SELECT id FROM sample');
    assertTrueValue($result instanceof PgsqlMysqliResult, 'SELECT deveria retornar PgsqlMysqliResult.');
    assertSameValue(['id' => 9], $result->fetch_assoc(), 'Linha da consulta não corresponde.');
});

$test('query respeita RETURNING insert_id explícito e rejeita modo streaming', static function (): void {
    $pdo = new FakePdo();
    $query = 'INSERT INTO sample (nome) VALUES (\'synthetic\') RETURNING id AS insert_id';
    $pdo->rowsByQuery[$query] = [['insert_id' => 74]];
    $connection = new PgsqlMysqliConnection($pdo);
    assertTrueValue($connection->query($query) instanceof PgsqlMysqliResult, 'INSERT RETURNING deveria retornar o resultset.');
    assertSameValue(74, $connection->insert_id, 'Somente o alias explícito insert_id deve alimentar a propriedade.');
    assertSameValue(75, PgsqlMysqliConnection::returnedInsertId([['id' => '75']]), 'RETURNING id convencional deve alimentar insert_id sem exigir alias especial.');
    $idReturningPdo = new FakePdo();
    $idReturningQuery = 'INSERT INTO sample (nome) VALUES (\'synthetic\') RETURNING id';
    $idReturningPdo->rowsByQuery[$idReturningQuery] = [['id' => '76']];
    $idReturningConnection = new PgsqlMysqliConnection($idReturningPdo);
    assertTrueValue($idReturningConnection->query($idReturningQuery) instanceof PgsqlMysqliResult, 'INSERT RETURNING id deveria entregar resultset.');
    assertSameValue(76, $idReturningConnection->insert_id, 'INSERT RETURNING id deve alimentar insert_id na conexão.');
    assertTrueValue($connection->query('SELECT id FROM sample') instanceof PgsqlMysqliResult, 'SELECT deveria retornar o resultset.');
    assertSameValue(0, $connection->insert_id, 'Uma consulta não-INSERT não deve deixar ID obsoleto.');
    assertSameValue(false, $connection->query('SELECT id FROM sample', 1), 'Modo de resultado streaming deve falhar fechado.');
    assertSameValue(1, $connection->errno, 'Modo não suportado deve registrar erro resumido.');
});

$test('query limpa insert_id após UPDATE e após falha', static function (): void {
    $pdo = new FakePdo();
    $insert = 'INSERT INTO sample (nome) VALUES (\'synthetic\') RETURNING id AS insert_id';
    $pdo->rowsByQuery[$insert] = [['insert_id' => 81]];
    $connection = new PgsqlMysqliConnection($pdo);
    assertTrueValue($connection->query($insert) instanceof PgsqlMysqliResult, 'INSERT RETURNING deveria executar.');
    assertSameValue(81, $connection->insert_id, 'ID do INSERT deveria estar disponível.');
    assertSameValue(true, $connection->query('UPDATE sample SET ativo = 1'), 'UPDATE deveria executar.');
    assertSameValue(0, $connection->insert_id, 'UPDATE não pode deixar insert_id obsoleto.');
    $pdo->failureState = '23505';
    assertSameValue(false, $connection->query('UPDATE sample SET ativo = 2'), 'Falha deveria retornar false.');
    assertSameValue(0, $connection->affected_rows, 'Falha não pode preservar affected_rows anterior.');
    assertSameValue(0, $connection->insert_id, 'Falha não pode preservar insert_id anterior.');
});

$test('INSERT simples obtém insert_id da linha retornada sem usar currval', static function (): void {
    $pdo = new FakePdo();
    $pdo->sequenceTriggeredValue = '90';
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('INSERT INTO sample (nome) VALUES (?)');
    $value = 'synthetic';
    assertTrueValue($statement !== false && $statement->bind_param('s', $value) && $statement->execute(), 'INSERT deveria executar.');
    assertSameValue(89, $connection->insert_id, 'O ID da sequence da PK deve ser preservado após INSERT simples.');
    assertSameValue(89, $statement->insert_id, 'O statement também deve expor insert_id.');
    assertSameValue(0, $statement->field_count, 'A coluna auxiliar RETURNING não deve aparecer como resultado público do statement.');
    assertSameValue(false, $statement->get_result(), 'INSERT original sem retorno explícito não deve expor resultset auxiliar.');
    assertSameValue('90', $pdo->sequenceCurrentValue, 'O cenário sintético deve representar trigger consumindo a mesma sequence após a linha inserida.');
    assertSameValue([], $pdo->queryCalls, 'Metadata e INSERT preparado não devem executar consultas de sequência paralelas.');
    $preparedSql = implode("\n", $pdo->prepareCalls);
    assertTrueValue(str_contains($preparedSql, 'pg_get_serial_sequence'), 'Metadata deve resolver a sequence da chave primária da tabela alvo.');
    assertTrueValue(str_contains($preparedSql, 'RETURNING "id" AS insert_id'), 'O INSERT preparado deve obter o ID da própria linha com RETURNING.');
    assertSameValue(false, str_contains($preparedSql, 'currval('), 'O adapter não deve inferir insert_id pelo currval global da conexão.');
    $directPdo = new FakePdo();
    $directPdo->sequenceTriggeredValue = '90';
    $directConnection = new PgsqlMysqliConnection($directPdo);
    assertSameValue(true, $directConnection->query('INSERT INTO sample (nome) VALUES (\'synthetic\')'), 'INSERT direto deveria executar sem expor a coluna interna.');
    assertSameValue(89, $directConnection->insert_id, 'INSERT direto deve obter o ID real da linha, mesmo se um trigger avançar a sequence.');
    assertSameValue(1, $directConnection->affected_rows, 'A coluna RETURNING interna não deve alterar affected_rows.');
});

$test('detecção de RETURNING ignora literais, identificadores quoted e comentários', static function (): void {
    assertSameValue(false, PgsqlMysqliConnection::hasReturningClause("INSERT INTO sample(value) VALUES ('RETURNING') -- RETURNING"), 'Texto literal ou comentário não pode simular cláusula RETURNING.');
    assertSameValue(false, PgsqlMysqliConnection::hasReturningClause('INSERT INTO sample(value) VALUES ($tag$RETURNING$tag$ /* RETURNING */'), 'Dollar quote e comentário em bloco devem ser ignorados.');
    assertSameValue(false, PgsqlMysqliConnection::hasReturningClause('INSERT INTO sample("returning") VALUES (1)'), 'Identificador quoted não é cláusula RETURNING.');
    assertSameValue(false, PgsqlMysqliConnection::hasReturningClause('INSERT INTO sample$returning(value) VALUES (1)'), 'Sufixo de identificador com $ não deve simular uma cláusula RETURNING.');
    assertSameValue(true, PgsqlMysqliConnection::hasReturningClause('INSERT INTO sample(value) VALUES (1) RETURNING id'), 'Cláusula RETURNING real deve ser detectada.');
});

$test('INSERT sem sequence de PK continua sem insert_id inventado', static function (): void {
    $pdo = new FakePdo();
    $pdo->sequenceName = null;
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('INSERT INTO sample (nome) VALUES (?)');
    $value = 'synthetic';
    assertTrueValue($statement !== false && $statement->bind_param('s', $value) && $statement->execute(), 'INSERT sintético deveria executar.');
    assertSameValue(0, $connection->insert_id, 'Sem sequence serial/identity associada à PK, o adapter não deve inferir ID.');
    assertSameValue(false, str_contains(implode("\n", $pdo->prepareCalls), 'RETURNING'), 'Sem metadata de sequence, o SQL original deve ser preservado.');

    $explicitPrimaryKeyPdo = new FakePdo();
    $explicitPrimaryKeyConnection = new PgsqlMysqliConnection($explicitPrimaryKeyPdo);
    assertSameValue(true, $explicitPrimaryKeyConnection->query('INSERT INTO sample (id, nome) VALUES (5, \'synthetic\')'), 'INSERT com PK explícita deve executar normalmente.');
    assertSameValue(0, $explicitPrimaryKeyConnection->insert_id, 'PK fornecida pelo chamador não deve ser confundida com ID gerado pela sequence.');
    assertSameValue(false, str_contains(implode("\n", $explicitPrimaryKeyPdo->queryCalls), 'RETURNING'), 'O adapter deve preservar SQL que fornece a PK explicitamente.');
});

$test('INSERT multi-row sem RETURNING não inventa insert_id incompatível', static function (): void {
    assertSameValue(true, PgsqlMysqliConnection::isSingleValuesInsert('INSERT INTO sample (nome) VALUES (?)'), 'INSERT VALUES simples deve ser reconhecido.');
    assertSameValue(false, PgsqlMysqliConnection::isSingleValuesInsert('INSERT INTO sample (nome) VALUES (?), (?)'), 'INSERT multi-row deve ser recusado pelo detector de ID simples.');
    assertSameValue(false, PgsqlMysqliConnection::isSingleValuesInsert('INSERT INTO sample (nome) VALUES (?), (?) ON CONFLICT DO NOTHING'), 'ON CONFLICT multi-row não pode usar o estado final da sequence como ID único.');
    assertSameValue(false, PgsqlMysqliConnection::isSingleValuesInsert('INSERT INTO sample (nome) SELECT nome FROM source'), 'INSERT SELECT não fornece tupla VALUES simples.');

    $preparedPdo = new FakePdo();
    $preparedPdo->affectedRows = 2;
    $preparedConnection = new PgsqlMysqliConnection($preparedPdo);
    $prepared = $preparedConnection->prepare('INSERT INTO sample (nome) VALUES (?), (?)');
    assertTrueValue($prepared !== false && $prepared->execute(['synthetic-a', 'synthetic-b']), 'INSERT multi-row preparado deveria executar.');
    assertSameValue(0, $prepared->insert_id, 'Sem RETURNING, statement multi-row não deve expor o último ID como se fosse o primeiro.');

    $directPdo = new FakePdo();
    $directPdo->affectedRows = 2;
    $directConnection = new PgsqlMysqliConnection($directPdo);
    assertSameValue(true, $directConnection->query("INSERT INTO sample (nome) VALUES ('synthetic-a'), ('synthetic-b')"), 'INSERT multi-row direto deveria executar.');
    assertSameValue(0, $directConnection->insert_id, 'Sem RETURNING, conexão não deve expor ID ambíguo de INSERT multi-row.');

    $partialPdo = new FakePdo();
    $partialPdo->affectedRows = 1;
    $partialConnection = new PgsqlMysqliConnection($partialPdo);
    $partial = $partialConnection->prepare('INSERT INTO sample (nome) VALUES (?), (?) ON CONFLICT DO NOTHING');
    assertTrueValue($partial !== false && $partial->execute(['synthetic-a', 'synthetic-b']), 'INSERT parcial via ON CONFLICT deveria executar.');
    assertSameValue(0, $partial->insert_id, 'Um único registro afetado não torna inequívoco o ID de um INSERT multi-row.');
    $sequenceMetadataCalls = array_filter(
        array_merge($partialPdo->prepareCalls, $partialPdo->queryCalls),
        static fn(string $query): bool => str_contains($query, 'pg_get_serial_sequence')
    );
    assertSameValue([], array_values($sequenceMetadataCalls), 'Detector multi-row deve evitar até mesmo capturar sequence de ID ambíguo.');
});

$test('resolução da sequence preserva schema e identificador entre aspas como parâmetros', static function (): void {
    $pdo = new FakePdo();
    $pdo->primaryKeyColumn = 'chave "principal"';
    $pdo->insertedRowId = '91';
    $connection = new PgsqlMysqliConnection($pdo);
    $query = 'INSERT INTO "My Schema"."odd""table" (nome) VALUES (\'synthetic\')';
    assertSameValue(true, $connection->query($query), 'INSERT em identificadores sintéticos deveria executar.');
    assertSameValue(91, $connection->insert_id, 'A PK com aspas/espaços deve ser retornada sem interpolação insegura.');
    $metadataBindings = null;
    foreach ($pdo->executedStatements as $execution) {
        if (str_contains($execution['query'], 'pg_get_serial_sequence')) {
            $metadataBindings = $execution['params'];
            break;
        }
    }
    assertSameValue(['My Schema', 'odd"table', 'My Schema', 'odd"table'], $metadataBindings, 'Schema/tabela devem ser enviados a to_regclass como parâmetros, sem interpolação.');
    assertTrueValue(str_contains(implode("\n", $pdo->queryCalls), 'RETURNING "chave ""principal""" AS insert_id'), 'O identificador da PK deve ser escapado corretamente na cláusula interna.');
});

$test('INSERT com RETURNING expõe somente o ID retornado pela consulta', static function (): void {
    assertSameValue(0, PgsqlMysqliConnection::returnedInsertId([['insert_id' => '73.5']]), 'Valor decimal não pode ser truncado para insert_id.');
    assertSameValue(0, PgsqlMysqliConnection::returnedInsertId([['insert_id' => '73x']]), 'Valor parcialmente numérico deve ser rejeitado.');
    $pdo = new FakePdo();
    $query = 'INSERT INTO sample (nome) VALUES (?) RETURNING id AS insert_id';
    $pdo->rowsByQuery[$query] = [['insert_id' => 73]];
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare($query);
    $value = 'synthetic';
    assertTrueValue($statement !== false && $statement->bind_param('s', $value) && $statement->execute(), 'INSERT RETURNING deveria executar.');
    assertSameValue(73, $statement->insert_id, 'ID retornado incorreto no statement.');
    assertSameValue(73, $connection->insert_id, 'ID retornado incorreto na conexão.');
});

$test('execute params valida cardinalidade e não reutiliza bindings ambíguos', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('SELECT id FROM sample WHERE id = ?');
    assertTrueValue($statement !== false, 'Statement deveria ser preparado.');
    assertSameValue(false, $statement->execute([]), 'Quantidade insuficiente deve falhar.');
    assertSameValue(true, $statement->execute([31]), 'Parâmetro explícito deveria executar.');
    assertSameValue(false, $statement->execute(), 'Binding anterior não deve ser reutilizado implicitamente.');
    $value = 33;
    assertSameValue(false, $statement->bind_param('i', $value), 'Não deve misturar execute(array) e bind_param no mesmo statement.');
    assertSameValue(true, $statement->execute([32]), 'Reexecução deve aceitar a nova lista explícita.');
    assertSameValue([32, PDO::PARAM_STR], $pdo->lastBindings[1], 'O último valor explícito não foi usado com o tipo legado de execute(array).');
});

$test('valores null são vinculados como SQL NULL no PostgreSQL', static function (): void {
    $paramsPdo = new FakePdo();
    $paramsConnection = new PgsqlMysqliConnection($paramsPdo);
    $paramsQuery = 'INSERT INTO sample (morador_id) VALUES (?)';
    $paramsStatement = $paramsConnection->prepare($paramsQuery);
    assertTrueValue($paramsStatement !== false && $paramsStatement->execute([null]), 'execute(array) com valor opcional nulo deveria executar.');
    $params = null;
    foreach ($paramsPdo->executedStatements as $execution) {
        if (str_starts_with($execution['query'], $paramsQuery)) {
            $params = $execution['params'][0] ?? null;
        }
    }
    assertSameValue([null, PDO::PARAM_NULL], $params, 'execute(array) deve preservar o binding como SQL NULL.');

    $boundPdo = new FakePdo();
    $boundConnection = new PgsqlMysqliConnection($boundPdo);
    $boundQuery = 'INSERT INTO sample (morador_id) VALUES (?)';
    $boundStatement = $boundConnection->prepare($boundQuery);
    $nullableId = null;
    assertTrueValue($boundStatement !== false && $boundStatement->bind_param('i', $nullableId) && $boundStatement->execute(), 'bind_param inteiro nulo deveria executar.');
    $params = null;
    foreach ($boundPdo->executedStatements as $execution) {
        if (str_starts_with($execution['query'], $boundQuery)) {
            $params = $execution['params'][0] ?? null;
        }
    }
    assertSameValue([null, PDO::PARAM_NULL], $params, 'bind_param deve preservar o binding como SQL NULL.');
});

$test('falha de statement limpa estado do resultado anterior', static function (): void {
    $query = 'SELECT id FROM sample';
    $pdo = new FakePdo();
    $pdo->rowsByQuery[$query] = [['id' => 40]];
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare($query);
    assertTrueValue($statement !== false && $statement->execute(), 'Execução inicial deveria funcionar.');
    assertSameValue(1, $statement->field_count, 'field_count inicial incorreto.');
    $pdo->failureState = '23505';
    assertSameValue(false, $statement->execute(), 'Execução seguinte deveria falhar.');
    assertSameValue(0, $statement->field_count, 'field_count obsoleto após falha.');
    assertSameValue(0, $statement->num_rows, 'num_rows obsoleto após falha.');
    assertSameValue(0, $statement->affected_rows, 'affected_rows obsoleto após falha.');
    assertSameValue(false, $statement->get_result(), 'Resultado anterior não deve continuar disponível.');
});

$test('transações usam o PDO injetado', static function (): void {
    $connection = new PgsqlMysqliConnection(new FakePdo());
    assertTrueValue($connection->begin_transaction(), 'A transação deveria iniciar.');
    assertTrueValue($connection->commit(), 'Commit deveria funcionar.');
    assertTrueValue($connection->begin_transaction(), 'A segunda transação deveria iniciar.');
    assertTrueValue($connection->rollback(), 'Rollback deveria funcionar.');
});

$test('SQLSTATE conhecidos mapeiam códigos mysqli sem incluir mensagem do driver', static function (): void {
    assertSameValue(1062, PgsqlMysqliConnection::mysqlErrnoForSqlState('23505'), 'Mapeamento de unicidade incorreto.');
    assertSameValue(1146, PgsqlMysqliConnection::mysqlErrnoForSqlState('42P01'), 'Mapeamento de tabela ausente incorreto.');
    $pdo = new FakePdo();
    $pdo->failureState = '23505';
    $connection = new PgsqlMysqliConnection($pdo);
    assertSameValue(false, $connection->query('SELECT value FROM sample'), 'Falha sintética deveria retornar false.');
    assertTrueValue(str_contains($connection->error, '23505'), 'Estado SQL deve aparecer no erro interno.');
    assertSameValue(false, str_contains($connection->error, 'synthetic detail'), 'Mensagem bruta do driver não pode vazar.');
    assertSameValue(1062, $connection->errno, 'errno deve corresponder ao SQLSTATE.');
});

$test('charset aceita somente UTF-8 e o escape delega ao PDO quote', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    assertTrueValue($connection->set_charset('utf8mb4'), 'utf8mb4 deveria mapear para UTF8.');
    assertSameValue(false, $connection->set_charset('latin1'), 'Charset desconhecido deve falhar.');
    assertSameValue("O''Brien", $connection->real_escape_string("O'Brien"), 'Escape deve usar a citação do driver.');
});

$test('close invalida operações futuras', static function (): void {
    $connection = new PgsqlMysqliConnection(new FakePdo());
    $statement = $connection->prepare('SELECT 1');
    assertTrueValue($statement !== false, 'Statement deveria ser criado antes do close.');
    $connection->close();
    assertSameValue(false, $connection->prepare('SELECT 1'), 'Conexão encerrada não pode preparar consultas.');
    assertSameValue(2006, $connection->errno, 'Conexão encerrada deve definir errno compatível.');
    assertSameValue(false, $statement->execute(), 'Statement previamente preparado não pode executar após close da conexão.');
    assertSameValue(false, $statement->get_result(), 'Resultset não deve ser entregue após close da conexão.');
    assertSameValue(true, $statement->close(), 'Statement deve fechar idempotentemente.');
    assertSameValue(false, $statement->execute(), 'Statement fechado não pode executar.');

    $secondConnection = new PgsqlMysqliConnection(new FakePdo());
    $secondStatement = $secondConnection->prepare('SELECT 1');
    assertTrueValue($secondStatement !== false, 'Segundo statement deveria ser preparado.');
    assertSameValue(true, $secondStatement->close(), 'Statement deveria fechar.');
    assertSameValue(false, $secondStatement->execute(), 'Statement fechado manualmente não pode executar.');
    assertTrueValue($secondConnection->query('SELECT 1') instanceof PgsqlMysqliResult, 'Fechar statement não pode fechar a conexão inteira.');
});

echo "OK: {$passed} testes unitários sintéticos; nenhuma conexão MySQL/PostgreSQL aberta.\n";
