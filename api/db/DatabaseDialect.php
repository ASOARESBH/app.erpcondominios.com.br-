<?php
declare(strict_types=1);

namespace ERP\Database;

use RuntimeException;

require_once __DIR__ . '/PgsqlMysqliConnection.php';
require_once __DIR__ . '/PgsqlMysqliStatement.php';
require_once __DIR__ . '/PgsqlMysqliResult.php';

final class DatabaseDialect
{
    public static function supports(mixed $connection): bool
    {
        if (self::isPdoMysqliFacade($connection)) {
            return in_array($connection->driver(), ['mysql', 'pgsql'], true);
        }
        return $connection instanceof \mysqli || $connection instanceof PgsqlMysqliConnection;
    }

    public static function driver(mixed $connection): string
    {
        if (self::isPdoMysqliFacade($connection)) {
            $driver = $connection->driver();
            if (in_array($driver, ['mysql', 'pgsql'], true)) {
                return $driver;
            }
            throw new RuntimeException('Conexão de banco não suportada.');
        }
        if ($connection instanceof \mysqli) {
            return 'mysql';
        }
        if ($connection instanceof PgsqlMysqliConnection) {
            return 'pgsql';
        }

        throw new RuntimeException('Conexão de banco não suportada.');
    }

    private static function isPdoMysqliFacade(mixed $connection): bool
    {
        return class_exists(PdoMysqliConnection::class, false) && $connection instanceof PdoMysqliConnection;
    }

    public static function isPostgres(mixed $connection): bool
    {
        return self::driver($connection) === 'pgsql';
    }

    public static function tableExists(mixed $connection, string $table): bool
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $table) !== 1) {
            throw new RuntimeException('Identificador de tabela inválido.');
        }

        $sql = self::tableExistsSql($connection);

        $statement = $connection->prepare($sql);
        if (!$statement || !$statement->bind_param('s', $table) || !$statement->execute()) {
            if ($statement) {
                $statement->close();
            }
            throw new RuntimeException('Não foi possível verificar o schema do banco.');
        }

        $result = $statement->get_result();
        if (!$result) {
            $statement->close();
            throw new RuntimeException('Não foi possível verificar o schema do banco.');
        }

        $exists = $result->fetch_assoc() !== null;
        $result->free();
        $statement->close();
        return $exists;
    }

    public static function tableExistsSql(mixed $connection): string
    {
        return self::driver($connection) === 'pgsql'
            ? 'SELECT 1 AS presente FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1'
            : 'SELECT 1 AS presente FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1';
    }

    public static function idempotentInsertSql(mixed $connection, string $sql): string
    {
        if (preg_match('/\A(\s*)INSERT\s+INTO\b/i', $sql) !== 1) {
            throw new RuntimeException('SQL de inserção idempotente não reconhecido.');
        }

        if (self::driver($connection) === 'mysql') {
            $converted = preg_replace('/\A(\s*)INSERT\s+INTO\b/i', '$1INSERT IGNORE INTO', $sql, 1, $replacements);
            if (!is_string($converted) || $replacements !== 1) {
                throw new RuntimeException('SQL de inserção idempotente não reconhecido.');
            }
            return $converted;
        }

        return rtrim(rtrim($sql), ';') . ' ON CONFLICT DO NOTHING';
    }

    public static function nullSafeEqualityOperator(mixed $connection): string
    {
        return self::driver($connection) === 'pgsql' ? 'IS NOT DISTINCT FROM' : '<=>';
    }

    public static function lastInsertId(mixed $connection, mixed $statement): int
    {
        if (self::driver($connection) === 'pgsql') {
            return (int)($statement->insert_id ?? 0);
        }

        return (int)($connection->insert_id ?? 0);
    }

    public static function rbacRevisionUpsertSql(mixed $connection): string
    {
        return self::isPostgres($connection)
            ? 'INSERT INTO rbac_configuracoes (tenant_id, revisao_permissoes) VALUES (?, 1) ON CONFLICT (tenant_id) DO UPDATE SET revisao_permissoes = rbac_configuracoes.revisao_permissoes + 1, atualizado_em = NOW()'
            : 'INSERT INTO rbac_configuracoes (tenant_id, revisao_permissoes) VALUES (?, 1) ON DUPLICATE KEY UPDATE revisao_permissoes = revisao_permissoes + 1, atualizado_em = NOW()';
    }

    public static function rbacGroupMembershipUpsertSql(mixed $connection): string
    {
        return self::isPostgres($connection)
            ? 'INSERT INTO rbac_usuario_grupos (usuario_id,tenant_id,grupo_id,ativo,atribuido_por_usuario_id,atribuido_em,removido_em) VALUES (?,?,?,1,?,NOW(),NULL) ON CONFLICT (usuario_id,tenant_id,grupo_id) DO UPDATE SET ativo=1,atribuido_por_usuario_id=EXCLUDED.atribuido_por_usuario_id,atribuido_em=NOW(),removido_em=NULL'
            : 'INSERT INTO rbac_usuario_grupos (usuario_id,tenant_id,grupo_id,ativo,atribuido_por_usuario_id,atribuido_em,removido_em) VALUES (?,?,?,1,?,NOW(),NULL) ON DUPLICATE KEY UPDATE ativo=1,atribuido_por_usuario_id=VALUES(atribuido_por_usuario_id),atribuido_em=NOW(),removido_em=NULL';
    }

    public static function rbacUserPermissionUpsertSql(mixed $connection): string
    {
        return self::isPostgres($connection)
            ? "INSERT INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo,atribuido_por_usuario_id,revogado_em) VALUES (?,?,?,?,'GLOBAL',?,?,NULL) ON CONFLICT (usuario_id,tenant_id,permissao_id) DO UPDATE SET efeito=EXCLUDED.efeito,escopo_dados='GLOBAL',motivo=EXCLUDED.motivo,atribuido_por_usuario_id=EXCLUDED.atribuido_por_usuario_id,revogado_em=NULL,atualizado_em=NOW()"
            : "INSERT INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo,atribuido_por_usuario_id,revogado_em) VALUES (?,?,?,?,'GLOBAL',?,?,NULL) ON DUPLICATE KEY UPDATE efeito=VALUES(efeito),escopo_dados='GLOBAL',motivo=VALUES(motivo),atribuido_por_usuario_id=VALUES(atribuido_por_usuario_id),revogado_em=NULL,atualizado_em=NOW()";
    }

    public static function rbacSessionInsertSql(mixed $connection): string
    {
        $sql = "INSERT INTO rbac_sessoes (tenant_id,usuario_id,sessao_hash,status,sessao_inativa,timeout_minutos,ip,user_agent,dispositivo,navegador,sistema_operacional) VALUES (?,?,?,'ATIVA',?,?,?,?,?,?,?)";
        return self::isPostgres($connection) ? $sql . ' RETURNING id AS insert_id' : $sql;
    }
}
