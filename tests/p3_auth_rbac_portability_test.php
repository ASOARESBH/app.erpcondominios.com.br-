<?php
declare(strict_types=1);

use ERP\Database\DatabaseDialect;
use ERP\Database\DatabaseDriverSelector;
use ERP\Database\PgsqlMysqliConnection;

require_once __DIR__ . '/../api/db/DatabaseDriverSelector.php';
require_once __DIR__ . '/../api/db/DatabaseDialect.php';
require_once __DIR__ . '/../api/tenant_helper.php';
require_once __DIR__ . '/../api/rbac_helper.php';
require_once __DIR__ . '/../api/auth_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    session_id('p3synthetic' . getmypid());
    session_start();
    $syntheticSessionFile = session_save_path() . '/sess_' . session_id();
    register_shutdown_function(static function () use ($syntheticSessionFile): void {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (is_file($syntheticSessionFile)) unlink($syntheticSessionFile);
    });
}

final class AuthRbacFakePdoStatement extends PDOStatement
{
    private array $bindings = [];
    private array $rows = [];

    public function __construct(private AuthRbacFakePdo $owner, private string $query)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->owner->failureState !== null
            && ($this->owner->failureNeedle === null || str_contains($this->query, $this->owner->failureNeedle))) {
            $exception = new PDOException('synthetic database failure');
            $exception->errorInfo = [$this->owner->failureState, null, 'synthetic detail'];
            throw $exception;
        }
        if ($params !== null) {
            $this->bindings = $params;
        }
        $this->owner->executed[] = ['query' => $this->query, 'bindings' => $this->bindings];
        $this->rows = $this->owner->rowsFor($this->query, $this->bindings);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function columnCount(): int
    {
        return preg_match('/\A\s*SELECT\b/i', $this->query) === 1
            || preg_match('/\bRETURNING\b/i', $this->query) === 1 ? 1 : 0;
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        return true;
    }
}

final class AuthRbacFakePdo extends PDO
{
    public array $executed = [];
    public ?string $failureState = null;
    public ?string $failureNeedle = null;
    public bool $rbacSchemaPresent = true;
    public bool $tenantLinkActive = true;
    public bool $globalSuperAdmin = false;
    public array $groupEffects = [];
    public array $individualEffects = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AuthRbacFakePdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new AuthRbacFakePdoStatement($this, $query);
        $statement->execute();
        return $statement;
    }

    public function rowsFor(string $query, array $bindings): array
    {
        if (str_contains($query, 'FROM usuarios u') && str_contains($query, 'usuario_tenant ut')) {
            $superAdminPredicate = str_contains($query, "LOWER(COALESCE(u.permissao, '')) = 'super_admin'");
            return ($this->tenantLinkActive || ($this->globalSuperAdmin && $superAdminPredicate))
                ? [['autorizado' => 1, 0 => 1]] : [];
        }
        if (str_contains($query, 'INSERT INTO rbac_sessoes') && str_contains($query, 'RETURNING id AS insert_id')) {
            return [['insert_id' => 777, 0 => 777]];
        }
        if (str_contains($query, 'information_schema.tables')) {
            if (!$this->rbacSchemaPresent) {
                return [];
            }
            return [['presente' => 1, 0 => 1]];
        }
        if (str_contains($query, 'SELECT revisao_permissoes')) {
            return [['revisao_permissoes' => 1, 0 => 1]];
        }
        if (str_contains($query, 'SELECT g.id,g.slug,g.nome,g.protegido')) {
            return [[
                'id' => 10,
                'slug' => 'grupo-fixture',
                'nome' => 'Grupo sintético',
                'protegido' => 0,
                0 => 10,
                1 => 'grupo-fixture',
                2 => 'Grupo sintético',
                3 => 0,
            ]];
        }
        if (str_contains($query, 'SELECT m.chave,p.acao,gp.efeito,gp.escopo_dados')) {
            return $this->groupEffects;
        }
        if (str_contains($query, 'SELECT m.chave,p.acao,up.efeito,up.escopo_dados')) {
            return $this->individualEffects;
        }
        if (str_contains($query, 'LOWER(COALESCE(permissao')) {
            $permissao = $this->globalSuperAdmin ? 'super_admin' : 'operador';
            return [['permissao' => $permissao, 0 => $permissao]];
        }
        if (str_contains($query, 'EXTRACT(EPOCH FROM')) {
            return [[
                'segundos_restantes' => 120,
                'data_login' => '2026-01-01 10:00:00',
                'data_expiracao' => '2026-01-01 10:02:00',
                'ultima_atividade' => '2026-01-01 10:00:00',
                0 => 120,
                1 => '2026-01-01 10:00:00',
                2 => '2026-01-01 10:02:00',
                3 => '2026-01-01 10:00:00',
            ]];
        }
        return [];
    }
}

function p3AssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function p3AssertThrows(callable $operation, string $message): void
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

try {
    $test('driver default mysql e seleção explícita validada', static function (): void {
        p3AssertSame('mysql', DatabaseDriverSelector::resolve(null), 'Driver ausente deve manter MySQL.');
        p3AssertSame('mysql', DatabaseDriverSelector::resolve(''), 'Driver vazio deve manter MySQL.');
        p3AssertSame('mysql', DatabaseDriverSelector::resolve(' MYSQL '), 'MySQL explícito deve ser normalizado.');
        p3AssertSame('pgsql', DatabaseDriverSelector::resolve('PgSQL'), 'PostgreSQL explícito deve ser aceito.');
        p3AssertThrows(static fn() => DatabaseDriverSelector::resolve('sqlite'), 'Driver não suportado deve falhar.');
        p3AssertThrows(static fn() => DatabaseDriverSelector::resolve(false), 'Tipo de driver inesperado deve falhar.');
    });

    $test('ID zero legado é válido apenas como inteiro de sessão', static function (): void {
        p3AssertSame(true, erp_auth_usuario_id_sessao_valido(0), 'ID inteiro zero deve preservar o caso legado.');
        p3AssertSame(true, erp_auth_usuario_id_sessao_valido('0'), 'ID string zero deve continuar aceito.');
        p3AssertSame(false, erp_auth_usuario_id_sessao_valido(-1), 'ID negativo deve ser rejeitado.');
        p3AssertSame(false, erp_auth_usuario_id_sessao_valido('not-an-id'), 'ID não numérico deve ser rejeitado.');
        p3AssertSame(false, erp_auth_usuario_id_sessao_valido(null), 'ID ausente deve ser rejeitado.');
    });

    $test('permissão do tenant escolhido aplica hierarquia global/local sem promover super_admin', static function (): void {
        p3AssertSame('admin', erpPermissaoLoginEfetiva('operador', 'admin'), 'Vínculo admin deve ser efetivo no tenant selecionado.');
        p3AssertSame('admin', erpPermissaoLoginEfetiva('admin', 'visualizador'), 'Permissão local não deve rebaixar o papel global na regra vigente.');
        p3AssertSame('super_admin', erpPermissaoLoginEfetiva('super_admin', 'operador'), 'Vínculo local não pode rebaixar super_admin global.');
        p3AssertSame('operador', erpPermissaoLoginEfetiva('operador', 'super_admin'), 'Vínculo local não pode promover a super_admin global.');
    });

    $test('dialecto reconhece somente conexões suportadas', static function (): void {
        $mysql = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
        p3AssertSame('mysql', DatabaseDialect::driver($mysql), 'Conexão mysqli deve selecionar MySQL.');
        p3AssertSame('<=>', DatabaseDialect::nullSafeEqualityOperator($mysql), 'MySQL deve manter igualdade null-safe.');
        p3AssertSame(true, DatabaseDialect::supports($mysql), 'Conexão mysqli deve ser suportada.');
        p3AssertSame(true, str_contains(DatabaseDialect::tableExistsSql($mysql), 'DATABASE()'), 'Metadata MySQL deve limitar o schema corrente.');
        p3AssertThrows(static fn() => DatabaseDialect::driver(new stdClass()), 'Conexão desconhecida deve falhar.');
    });

    $test('metadata PostgreSQL preparado e tabela presente', static function (): void {
        $pdo = new AuthRbacFakePdo();
        $connection = new PgsqlMysqliConnection($pdo);
        p3AssertSame(true, DatabaseDialect::tableExists($connection, 'rbac_modulos'), 'Tabela sintética deveria existir.');
        p3AssertSame('pgsql', DatabaseDialect::driver($connection), 'Conexão PDO deve ser reconhecida como PostgreSQL.');
        p3AssertSame('IS NOT DISTINCT FROM', DatabaseDialect::nullSafeEqualityOperator($connection), 'PostgreSQL deve usar igualdade null-safe explícita.');
        p3AssertSame(true, str_contains(DatabaseDialect::tableExistsSql($connection), 'current_schema()'), 'Metadata PostgreSQL deve usar o schema corrente.');
        p3AssertSame('rbac_modulos', $pdo->executed[0]['bindings'][1] ?? null, 'Nome de tabela deve ser parâmetro preparado.');
        p3AssertSame(true, str_contains($pdo->executed[0]['query'], 'information_schema.tables'), 'Consulta de metadata deve usar information_schema.');
    });

    $test('metadata ausente e falha de consulta não se confundem', static function (): void {
        $missing = new AuthRbacFakePdo();
        $missing->rbacSchemaPresent = false;
        $missingConnection = new PgsqlMysqliConnection($missing);
        p3AssertSame(false, DatabaseDialect::tableExists($missingConnection, 'rbac_modulos'), 'Tabela ausente deve retornar false.');
        p3AssertThrows(static fn() => DatabaseDialect::tableExists($missingConnection, 'rbac-modulos'), 'Identificador inválido deve falhar antes da consulta.');

        $failed = new AuthRbacFakePdo();
        $failed->failureState = '42P01';
        $failedConnection = new PgsqlMysqliConnection($failed);
        p3AssertThrows(static fn() => DatabaseDialect::tableExists($failedConnection, 'rbac_modulos'), 'Falha de metadata deve lançar erro e não virar ausência.');
    });

    $test('tenant ativo, revogação e super_admin são avaliados no servidor', static function (): void {
        $active = new AuthRbacFakePdo();
        $activeConnection = new PgsqlMysqliConnection($active);
        p3AssertSame(true, usuarioTemAcessoTenantAtivo($activeConnection, 71, 23), 'Vínculo ativo deve autorizar o tenant.');

        $revoked = new AuthRbacFakePdo();
        $revoked->tenantLinkActive = false;
        p3AssertSame(false, usuarioTemAcessoTenantAtivo(new PgsqlMysqliConnection($revoked), 71, 23), 'Vínculo revogado deve bloquear o tenant.');

        $superAdmin = new AuthRbacFakePdo();
        $superAdmin->tenantLinkActive = false;
        $superAdmin->globalSuperAdmin = true;
        p3AssertSame(true, usuarioTemAcessoTenantAtivo(new PgsqlMysqliConnection($superAdmin), 71, 23), 'super_admin deve manter autoridade global.');
        p3AssertSame(true, usuarioTemAcessoTenantAtivo(new PgsqlMysqliConnection($superAdmin), 0, 23), 'super_admin com ID legado zero deve manter a autoridade existente.');

        $before = count($revoked->executed);
        p3AssertSame(false, usuarioTemAcessoTenantAtivo(new PgsqlMysqliConnection($revoked), -1, 23), 'IDs negativos devem falhar fechado.');
        p3AssertSame($before, count($revoked->executed), 'IDs negativos não podem disparar consulta.');

        $failed = new AuthRbacFakePdo();
        $failed->failureState = '42P01';
        p3AssertThrows(static fn() => usuarioTemAcessoTenantAtivo(new PgsqlMysqliConnection($failed), 71, 23), 'Falha ao consultar vínculo/tenant não pode autorizar a sessão.');
    });

    $test('super_admin com ID zero mantém sessão e acesso RBAC', static function (): void {
        $pdo = new AuthRbacFakePdo();
        $pdo->globalSuperAdmin = true;
        $connection = new PgsqlMysqliConnection($pdo);
        $_SESSION = ['usuario_id' => 0, 'tenant_id' => 23];

        p3AssertSame('super_admin', rbacUsuarioPerfilBanco($connection, 0, 23), 'Perfil global deve ser consultado para ID zero.');
        p3AssertSame(true, rbacUsuarioTemAcessoTotal($connection, 0, 23), 'ID zero super_admin mantém acesso total.');
        p3AssertSame(777, rbacRegistrarOuAtualizarSessao($connection, 0, 23), 'Registro da sessão RBAC deve aceitar ID zero legado.');
        p3AssertSame(true, rbacPode($connection, 'moradores', 'visualizar', 0, 23), 'Guard rbacPode deve permitir super_admin com ID zero.');
    });

    $test('traduções explícitas de idempotência e insert id', static function (): void {
        $connection = new PgsqlMysqliConnection(new AuthRbacFakePdo());
        $mysql = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
        $sql = 'INSERT INTO rbac_grupos (tenant_id,slug) VALUES (?, ?)';
        $postgresSql = DatabaseDialect::idempotentInsertSql($connection, $sql);
        p3AssertSame(true, str_contains($postgresSql, 'ON CONFLICT DO NOTHING'), 'PostgreSQL deve usar conflito explícito.');
        p3AssertSame(false, str_contains($postgresSql, 'INSERT IGNORE'), 'SQL PostgreSQL não deve manter INSERT IGNORE.');
        p3AssertSame(true, str_starts_with(DatabaseDialect::idempotentInsertSql($mysql, $sql), 'INSERT IGNORE INTO'), 'MySQL deve preservar semântica idempotente.');
        p3AssertThrows(static fn() => DatabaseDialect::idempotentInsertSql($connection, 'UPDATE rbac_grupos SET ativo=1'), 'SQL não reconhecido deve falhar.');
        p3AssertSame(42, DatabaseDialect::lastInsertId($connection, (object)['insert_id' => 42]), 'PostgreSQL deve ler insert_id do RETURNING.');
        p3AssertSame(true, str_contains(DatabaseDialect::rbacRevisionUpsertSql($connection), 'ON CONFLICT (tenant_id)'), 'Revisão deve atualizar por chave tenant.');
        p3AssertSame(true, str_contains(DatabaseDialect::rbacGroupMembershipUpsertSql($connection), 'ON CONFLICT (usuario_id,tenant_id,grupo_id)'), 'Vínculo deve atualizar pela chave natural composta.');
        p3AssertSame(true, str_contains(DatabaseDialect::rbacUserPermissionUpsertSql($connection), 'ON CONFLICT (usuario_id,tenant_id,permissao_id)'), 'Exceção deve atualizar pela chave natural composta.');
        p3AssertSame(true, str_contains(DatabaseDialect::rbacSessionInsertSql($connection), 'RETURNING id AS insert_id'), 'Sessão PostgreSQL deve retornar seu ID.');
    });

    $test('registro RBAC persiste somente SHA-256 na coluna de sessão', static function (): void {
        $_SESSION = ['tenant_id' => 23, 'usuario_id' => 71];
        $rawSessionId = session_id();
        $pdo = new AuthRbacFakePdo();
        $connection = new PgsqlMysqliConnection($pdo);

        p3AssertSame(777, rbacRegistrarOuAtualizarSessao($connection, 71, 23), 'Registro sintético deve retornar o ID vindo de RETURNING.');
        $insert = null;
        foreach ($pdo->executed as $call) {
            if (str_contains($call['query'], 'INSERT INTO rbac_sessoes')) $insert = $call;
        }
        p3AssertSame(true, is_array($insert), 'A consulta de sessão RBAC deve ser executada.');
        p3AssertSame(true, str_contains($insert['query'], 'sessao_hash'), 'O digest deve ser armazenado na coluna sessao_hash.');
        p3AssertSame(hash('sha256', $rawSessionId), $insert['bindings'][3] ?? null, 'Somente SHA-256 do identificador deve ser persistido.');
        $rawBindingKeys = array_keys(array_filter($insert['bindings'], static fn($value): bool => is_string($value) && hash_equals($rawSessionId, $value)));
        p3AssertSame([], $rawBindingKeys, 'O identificador/cookie em claro nunca deve ser persistido; posições vinculadas: ' . implode(',', $rawBindingKeys));
        p3AssertSame(false, array_key_exists('_rbac_sessao_hash', $_SESSION), 'Digest não deve ser copiado para outro campo de sessão.');
        p3AssertSame(true, isset($_SESSION['_rbac_sessao_id']), 'O estado PHP deve guardar somente o ID interno numérico da sessão RBAC.');
    });

    $test('RBAC PostgreSQL incompleto falha fechado', static function (): void {
        $pdo = new AuthRbacFakePdo();
        $pdo->rbacSchemaPresent = false;
        $connection = new PgsqlMysqliConnection($pdo);
        p3AssertThrows(static fn() => rbacTabelasDisponiveis($connection), 'Schema RBAC PostgreSQL incompleto não pode entrar em modo compatibilidade.');
    });

    $test('erro de validação da sessão RBAC PostgreSQL falha fechado', static function (): void {
        $pdo = new AuthRbacFakePdo();
        $pdo->failureState = '42P01';
        $pdo->failureNeedle = 'SELECT id FROM rbac_sessoes';
        $connection = new PgsqlMysqliConnection($pdo);
        $_SESSION = ['_rbac_sessao_id' => 55];
        p3AssertThrows(
            static fn() => rbacValidarSessaoAtual($connection, 71, 23),
            'Falha de leitura de sessão PostgreSQL não pode aceitar a sessão.'
        );
    });

    $test('precedência individual sobre grupo permanece invariável', static function (): void {
        $pdo = new AuthRbacFakePdo();
        $pdo->groupEffects = [[
            'chave' => 'moradores', 'acao' => 'editar', 'efeito' => 'PERMITIR', 'escopo_dados' => 'GLOBAL',
            0 => 'moradores', 1 => 'editar', 2 => 'PERMITIR', 3 => 'GLOBAL',
        ]];
        $pdo->individualEffects = [[
            'chave' => 'moradores', 'acao' => 'editar', 'efeito' => 'NEGAR', 'escopo_dados' => 'GLOBAL',
            0 => 'moradores', 1 => 'editar', 2 => 'NEGAR', 3 => 'GLOBAL',
        ]];
        $connection = new PgsqlMysqliConnection($pdo);
        $_SESSION = [];
        $effective = rbacObterPermissoesEfetivas($connection, 71, 23, true);
        p3AssertSame(false, rbacEfetivaPermissaoPermitida($effective, 'moradores', 'editar'), 'NEGAR individual deve prevalecer sobre PERMITIR de grupo.');
        p3AssertSame('NEGAR', $effective['permitidas']['moradores.editar']['efeito_efetivo'] ?? null, 'Efeito vencedor deve permanecer NEGAR.');
        $groupLookup = array_values(array_filter($pdo->executed, static fn(array $call): bool => str_contains($call['query'], 'SELECT g.id,g.slug,g.nome,g.protegido')))[0] ?? null;
        p3AssertSame(true, is_array($groupLookup) && str_contains($groupLookup['query'], 'g.tenant_id=ug.tenant_id'), 'Lookup de grupo deve rejeitar vínculo entre tenants divergentes.');

        $pdo2 = new AuthRbacFakePdo();
        $pdo2->groupEffects = [[
            'chave' => 'moradores', 'acao' => 'editar', 'efeito' => 'NEGAR', 'escopo_dados' => 'GLOBAL',
            0 => 'moradores', 1 => 'editar', 2 => 'NEGAR', 3 => 'GLOBAL',
        ]];
        $pdo2->individualEffects = [[
            'chave' => 'moradores', 'acao' => 'editar', 'efeito' => 'PERMITIR', 'escopo_dados' => 'GLOBAL',
            0 => 'moradores', 1 => 'editar', 2 => 'PERMITIR', 3 => 'GLOBAL',
        ]];
        $effective2 = rbacObterPermissoesEfetivas(new PgsqlMysqliConnection($pdo2), 71, 23, true);
        p3AssertSame(true, rbacEfetivaPermissaoPermitida($effective2, 'moradores', 'editar'), 'PERMITIR individual deve prevalecer sobre NEGAR de grupo.');
    });

    $test('Portal PostgreSQL bloqueia antes de escrita e o verificador não expõe session_id', static function (): void {
        $login = file_get_contents(__DIR__ . '/../api/api_verificar_tipo_login.php');
        $session = file_get_contents(__DIR__ . '/../api/verificar_sessao_completa.php');
        $frontend = file_get_contents(__DIR__ . '/../frontend/login.html');
        $sessionInitializer = strpos($login, 'function iniciar_sessao_autenticada_limpa');
        $erpStart = strpos($login, '// 5c. Criar sessão ERP com tenant_id');
        $erpReset = strpos($login, 'iniciar_sessao_autenticada_limpa();', $erpStart);
        $erpIdentity = strpos($login, "\$_SESSION['usuario_id']", $erpStart);
        $portalStart = strpos($login, "if (\$tipo_final === 'morador')");
        $portalReset = strpos($login, 'iniciar_sessao_autenticada_limpa();', $portalStart);
        $portalToken = strpos($login, '$token     = bin2hex(random_bytes(32))', $portalStart);
        $portalBegin = strpos($login, '$conexao->begin_transaction()', $portalStart);
        $portalDelete = strpos($login, 'DELETE FROM sessoes_portal WHERE morador_id = ?', $portalStart);
        $portalInsert = strpos($login, 'INSERT INTO sessoes_portal', $portalStart);
        $portalUpdate = strpos($login, 'UPDATE moradores SET ultimo_acesso = NOW()', $portalStart);
        $portalResponse = strpos($login, '$respostaPortal = json_encode([', $portalStart);
        $portalCommit = strpos($login, '$conexao->commit()', $portalStart);
        $portalAudit = strpos($login, 'registrar_log($log_tipo', $portalStart);
        $portalClose = strpos($login, 'fechar_conexao($conexao);', $portalStart);
        $catchStart = strrpos($login, '} catch (Throwable $e) {');
        $catchBlock = is_int($catchStart) ? substr($login, $catchStart) : '';
        $portalDdl = file_get_contents(__DIR__ . '/../sql/database_portal.sql');
        $moradoresDdl = file_get_contents(__DIR__ . '/../sql/database.sql');
        p3AssertSame(true, is_int($sessionInitializer) && str_contains(substr($login, $sessionInitializer, 450), 'session_regenerate_id(true)') && str_contains(substr($login, $sessionInitializer, 450), '$_SESSION = []'), 'Novo login deve rotacionar o ID e limpar todo estado RBAC/identidade anterior.');
        p3AssertSame(true, is_int($erpStart) && is_int($erpReset) && is_int($erpIdentity) && $erpReset < $erpIdentity, 'Novo login ERP deve limpar sessão antiga antes de gravar a identidade/tenant.');
        p3AssertSame(true, is_int($portalStart) && is_int($portalReset) && is_int($portalToken) && $portalReset < $portalToken, 'Novo login Portal deve limpar sessão ERP residual antes de criar token Bearer.');
        p3AssertSame(true, is_int($portalBegin) && is_int($portalDelete) && is_int($portalInsert) && is_int($portalUpdate) && is_int($portalResponse) && is_int($portalCommit) && is_int($portalAudit) && is_int($portalClose) && $portalBegin < $portalDelete && $portalDelete < $portalInsert && $portalInsert < $portalUpdate && $portalUpdate < $portalResponse && $portalResponse < $portalCommit && $portalCommit < $portalAudit && $portalAudit < $portalClose, 'Transação deve cobrir tokens/último acesso e ser confirmada antes da auditoria best-effort e do fechamento.');
        p3AssertSame(true, is_string($portalDdl) && str_contains($portalDdl, 'CREATE TABLE IF NOT EXISTS sessoes_portal') && str_contains($portalDdl, 'ENGINE=InnoDB'), 'Rollback depende de tabela Portal transacional conforme DDL canônico.');
        $moradoresDdlStart = strpos($moradoresDdl, 'CREATE TABLE IF NOT EXISTS moradores');
        $moradoresDdlEnd = is_int($moradoresDdlStart) ? strpos($moradoresDdl, ';', $moradoresDdlStart) : false;
        p3AssertSame(true, is_int($moradoresDdlStart) && is_int($moradoresDdlEnd) && str_contains(substr($moradoresDdl, $moradoresDdlStart, $moradoresDdlEnd - $moradoresDdlStart), 'ENGINE=InnoDB'), 'Rollback da transação que atualiza último acesso depende de moradores InnoDB no DDL canônico.');
        p3AssertSame(true, str_contains($catchBlock, 'if ($portalTransactionStarted') && str_contains($catchBlock, '$conexao->rollback()') && str_contains($catchBlock, 'if ($novaSessaoAutenticada') && str_contains($catchBlock, 'session_destroy()') && str_contains($catchBlock, 'conectar_banco()') && str_contains($catchBlock, 'DELETE FROM sessoes_portal WHERE morador_id = ? AND token = ?') && str_contains($catchBlock, 'if (!$stmt_revogar->execute())'), 'Falha deve reverter sessões anteriores, destruir sessão PHP parcial e tentar revogar token isolado.');
        p3AssertSame(true, str_contains($catchBlock, 'try { fechar_conexao($conexaoRevogacao); } catch (Throwable) {}') && str_contains($catchBlock, "try {\n            fechar_conexao(\$conexao);\n        } catch (Throwable)"), 'Falha ao fechar qualquer conexão no catch não pode impedir destruição da sessão nem resposta genérica.');
        $committedGuard = strpos($catchBlock, 'if ($portalTransactionCommitted && isset($respostaPortal)');
        $tokenRevocation = strpos($catchBlock, 'if ($tokenPortalCriado !== null');
        p3AssertSame(true, is_int($committedGuard) && is_int($tokenRevocation) && $committedGuard < $tokenRevocation && str_contains(substr($catchBlock, $committedGuard, $tokenRevocation - $committedGuard), 'echo $respostaPortal;') && str_contains(substr($catchBlock, $committedGuard, $tokenRevocation - $committedGuard), 'exit;'), 'Após commit confirmado, falha tardia deve devolver a resposta serializada e não revogar o token durável.');
        p3AssertSame(true, is_string($login) && str_contains($login, "if (defined('ERP_DB_DRIVER') && ERP_DB_DRIVER === 'pgsql') http_response_code(503);"), 'Falhas do login PostgreSQL devem ter status 503.');
        p3AssertSame(true, str_contains($login, '$senha_valida_morador && !\\ERP\\Database\\DatabaseDialect::isPostgres($conexao)'), 'Upgrade de senha Portal não pode escrever em PostgreSQL antes do bloqueio.');
        $getStart = strpos($session, "if (\$_SERVER['REQUEST_METHOD'] === 'GET')");
        $postStart = strpos($session, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        p3AssertSame(true, is_int($getStart) && is_int($postStart) && $postStart > $getStart, 'Blocos GET/POST de sessão precisam existir.');
        $getBlock = substr($session, $getStart, $postStart - $getStart);
        $getBarrier = strpos($getBlock, 'O login do Portal');
        $getTimeoutConfig = strpos($getBlock, '$config = obter_config_timeout');
        $getPortalLookup = strpos($getBlock, '$sessao = verificar_sessao_portal');
        p3AssertSame(true, is_int($getBarrier) && is_int($getTimeoutConfig) && is_int($getPortalLookup) && $getBarrier < $getTimeoutConfig && $getTimeoutConfig < $getPortalLookup, 'GET deve bloquear Portal antes de ler config_sessao ou a sessão Bearer.');
        $postBlock = substr($session, $postStart);
        $postBarrier = strpos($postBlock, 'O login do Portal');
        $postTimeoutConfig = strpos($postBlock, '$config = obter_config_timeout');
        $postPortalUpdate = strpos($postBlock, 'UPDATE sessoes_portal');
        p3AssertSame(true, is_int($postBarrier) && is_int($postTimeoutConfig) && is_int($postPortalUpdate) && $postBarrier < $postTimeoutConfig && $postTimeoutConfig < $postPortalUpdate, 'POST deve bloquear Portal antes de ler config_sessao ou atualizar sessão Bearer.');
        p3AssertSame(false, str_contains($session, 'session_id()'), 'A resposta do verificador não pode incluir o PHP session_id.');
        p3AssertSame(true, str_contains($session, 'if ($uid >= 0)'), 'ID de usuário zero deve atualizar o estado sessao_inativa no verificador.');
        p3AssertSame(true, str_contains($login, "\$permissao_global === 'super_admin'") && str_contains($login, "FROM tenants WHERE status = 'ativo'"), 'super_admin global deve receber somente catálogo de tenants ativos no fluxo de domínio único PostgreSQL.');
        p3AssertSame(true, str_contains($login, 'tenant_slug_escolhido') && str_contains($login, "\A[a-z0-9-]{1,50}\z"), 'Slug de tenant escolhido precisa de validação server-side.');
        p3AssertSame(true, str_contains($login, 'erpPermissaoLoginEfetiva(') && str_contains($login, "\$_SESSION['usuario_permissao']    = \$dados_erp['permissao']"), 'A permissão efetiva precisa ser aplicada antes de criar a sessão do tenant escolhido.');
        p3AssertSame(true, str_contains($frontend, "tipo === 'selecionar_tenant'") && str_contains($frontend, 'name.textContent = String(tenant.nome || tenant.slug)'), 'Frontend deve suportar seleção global sem inserir nomes como HTML.');
    });

    $test('auditoria do login não registra identificadores pessoais nem IP', static function (): void {
        $login = file_get_contents(__DIR__ . '/../api/api_verificar_tipo_login.php');
        $auditCalls = array_values(array_filter(explode("\n", $login), static fn(string $line): bool => str_contains($line, 'registrar_log(')));
        p3AssertSame(true, count($auditCalls) >= 8, 'Eventos de login devem continuar auditáveis por código.');
        foreach ($auditCalls as $call) {
            p3AssertSame(false, preg_match('/\$(?:email|dados_erp|dados_morador|usuario|tenant_slug|tenant)|HTTP_USER_AGENT|REMOTE_ADDR/', $call) === 1, 'Evento não pode armazenar e-mail, nome, slug, user-agent ou IP.');
            p3AssertSame(true, str_contains($call, 'null, false'), 'Toda auditoria do login deve omitir usuário e IP.');
        }
    });

    $test('senha pendente do seletor é limpa após cancelamento, erro ou login terminal', static function (): void {
        $frontend = file_get_contents(__DIR__ . '/../frontend/login.html');
        $cancelStart = strpos($frontend, 'function _fecharPopup()');
        $cancelClear = is_int($cancelStart) ? strpos($frontend, '_limparCredenciaisPendentes();', $cancelStart) : false;
        p3AssertSame(true, str_contains($frontend, "function _limparCredenciaisPendentes()") && str_contains($frontend, "_pendEmail = '';\n            _pendSenha = ''"), 'Helper deve apagar email/senha temporários.');
        p3AssertSame(true, is_int($cancelStart) && is_int($cancelClear), 'Cancelar seleção deve apagar credenciais pendentes.');
        p3AssertSame(true, substr_count($frontend, '_limparCredenciaisPendentes();') >= 7, 'Falhas e respostas terminais devem limpar as credenciais em memória.');
    });

    session_write_close();
    echo "OK: {$passed} testes sintéticos de portabilidade auth/RBAC; nenhuma conexão de banco aberta.\n";
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
