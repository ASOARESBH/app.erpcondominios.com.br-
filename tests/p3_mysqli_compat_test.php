<?php
declare(strict_types=1);

use ERP\Database\PgsqlMysqliConnection;
use ERP\Database\PgsqlMysqliResult;

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
        $this->rows = $this->owner->rowsFor($this->query);
        $this->cursor = 0;
        $this->owner->lastBindings = $this->bindings;
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
    public array $lastBindings = [];
    public int $affectedRows = 1;
    public ?string $failureState = null;
    private bool $transaction = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakePdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
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
        return $this->rowsByQuery[$query] ?? [];
    }

    public function columnCountFor(string $query, array $rows): int
    {
        if (preg_match('/\A\s*SELECT\b/i', $query) === 1 || preg_match('/\bRETURNING\b/i', $query) === 1) {
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

$test('INSERT id sem RETURNING não é inferido pela sequence de trigger', static function (): void {
    $pdo = new FakePdo();
    $connection = new PgsqlMysqliConnection($pdo);
    $statement = $connection->prepare('INSERT INTO sample (nome) VALUES (?)');
    $value = 'synthetic';
    assertTrueValue($statement !== false && $statement->bind_param('s', $value) && $statement->execute(), 'INSERT deveria executar.');
    assertSameValue(0, $connection->insert_id, 'Sem RETURNING a camada não deve inferir qual sequence gerou o ID.');
    assertSameValue([], $pdo->execCalls, 'Não deve consultar LASTVAL nem alterar transações.');
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
    assertSameValue(32, $pdo->lastBindings[0], 'O último valor explícito não foi usado.');
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
