<?php

namespace App\Migration\Core;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyReader
{
    private bool $checked = false;

    public function __construct(private readonly string $service)
    {
    }

    public static function assertReadQuery(string $sql): void
    {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('LegacyReader accepts SELECT statements only.');
        }

        // SELECT is intentionally the only accepted statement form. WITH is rejected because
        // PostgreSQL allows data-changing CTEs inside WITH.
        if (preg_match('/\b(insert|update|delete|merge|truncate|alter|drop|create|grant|revoke|copy|call|do)\b/i', $sql)) {
            throw new RuntimeException('Potentially mutating SQL is forbidden on legacy databases.');
        }
    }

    public function assertSafe(): array
    {
        if ($this->checked) {
            return ['safe' => true];
        }

        $source = config('migration.legacy.'.$this->service);
        if (! is_array($source)) {
            throw new RuntimeException("Unknown legacy source: {$this->service}");
        }
        if (! ($source['readonly_confirmed'] ?? false)) {
            throw new RuntimeException("Legacy {$this->service} connection is blocked until *_READ_ONLY_CONFIRMED=true is explicitly set.");
        }

        foreach (['host', 'database', 'username'] as $required) {
            if (empty($source['database'][$required])) {
                throw new RuntimeException("Legacy {$this->service} connection is not configured: {$required} is empty.");
            }
        }

        $connection = $this->connection();

        // Session-level belt-and-suspenders protection. This changes only this client session.
        $connection->statement('SET default_transaction_read_only = on');

        $state = $this->selectOne(<<<'SQL'
SELECT current_user AS db_user,
       current_database() AS db_name,
       current_setting('default_transaction_read_only') AS default_read_only
SQL);
        if (($state->default_read_only ?? 'off') !== 'on') {
            throw new RuntimeException('Legacy connection did not enter default_transaction_read_only=on.');
        }

        $role = $this->selectOne(<<<'SQL'
SELECT rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
FROM pg_roles
WHERE rolname = current_user
SQL);
        if ($role && ($role->rolsuper || $role->rolcreatedb || $role->rolcreaterole || $role->rolreplication || $role->rolbypassrls)) {
            throw new RuntimeException('Legacy connection uses an elevated PostgreSQL role. A dedicated read-only role is required.');
        }

        $writePrivilege = $this->selectOne(<<<'SQL'
SELECT n.nspname AS schema_name, c.relname AS table_name
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p')
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND (
      has_table_privilege(current_user, c.oid, 'INSERT') OR
      has_table_privilege(current_user, c.oid, 'UPDATE') OR
      has_table_privilege(current_user, c.oid, 'DELETE') OR
      has_table_privilege(current_user, c.oid, 'TRUNCATE') OR
      has_table_privilege(current_user, c.oid, 'TRIGGER')
  )
LIMIT 1
SQL);
        if ($writePrivilege) {
            throw new RuntimeException("Legacy DB role has write privileges on {$writePrivilege->schema_name}.{$writePrivilege->table_name}; migration is blocked.");
        }

        $this->checked = true;

        return [
            'safe' => true,
            'database' => $state->db_name,
            'user' => $state->db_user,
            'default_transaction_read_only' => $state->default_read_only,
        ];
    }

    public function select(string $sql, array $bindings = []): array
    {
        self::assertReadQuery($sql);
        $connection = $this->connection();
        $timeout = max(1000, (int) config('migration.legacy_statement_timeout_ms', 15000));

        return $connection->transaction(function () use ($connection, $sql, $bindings, $timeout): array {
            // Every individual extraction query is protected by an explicit READ ONLY transaction.
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement('SET LOCAL statement_timeout = '.$timeout);

            return $connection->select($sql, $bindings);
        }, 1);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    private function connection(): Connection
    {
        $name = config('migration.legacy.'.$this->service.'.connection');
        if (! is_string($name) || $name === '') {
            throw new RuntimeException("Legacy connection name is missing for {$this->service}.");
        }

        return DB::connection($name);
    }
}
