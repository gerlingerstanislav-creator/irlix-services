<?php

namespace App\Migration\Core;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyReader
{
    private bool $checked = false;
    private bool $configured = false;

    public function __construct(private readonly string $service)
    {
    }

    public static function assertReadQuery(string $sql): void
    {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('LegacyReader accepts SELECT statements only.');
        }

        // Legacy SQL is application-owned and fixed in code. We deliberately reject comments and
        // statement separators so mutating tokens cannot be hidden and multiple statements cannot
        // be smuggled through a read method.
        if (str_contains($sql, ';') || str_contains($sql, '--') || str_contains($sql, '/*') || str_contains($sql, '*/')) {
            throw new RuntimeException('Comments and multiple statements are forbidden on legacy databases.');
        }

        // Remove SQL string literals before scanning for verbs: the safety probes legitimately use
        // literals such as 'INSERT' in has_table_privilege(), which are data, not executable SQL.
        $executableSql = preg_replace("/'(?:''|[^'])*'/s", "''", $sql);
        if (! is_string($executableSql)) {
            throw new RuntimeException('Unable to validate legacy SELECT.');
        }

        if (preg_match('/\b(insert|update|delete|merge|truncate|alter|drop|create|grant|revoke|copy|call|do|into)\b/i', $executableSql)) {
            throw new RuntimeException('Potentially mutating SQL is forbidden on legacy databases.');
        }
        if (preg_match('/\bfor\s+(update|no\s+key\s+update|share|key\s+share)\b/i', $executableSql)) {
            throw new RuntimeException('Locking SELECT is forbidden on legacy databases.');
        }

        // A SELECT can still invoke PostgreSQL functions with operational side effects. The
        // migration queries need none of these, so explicitly reject the dangerous families.
        if (preg_match('/\b(pg_advisory_[a-z_]*|pg_sleep|pg_terminate_backend|pg_cancel_backend|pg_notify|dblink(?:_[a-z_]*)?|lo_[a-z_]+|nextval|setval|set_config)\s*\(/i', $executableSql)) {
            throw new RuntimeException('Side-effecting PostgreSQL function is forbidden on legacy databases.');
        }
    }

    public function assertSafe(): array
    {
        $this->ensureConfigured();
        if ($this->checked) {
            return ['safe' => true];
        }

        $source = config('migration.legacy.'.$this->service);
        if (! is_array($source)) {
            throw new RuntimeException("Unknown legacy source: {$this->service}");
        }
        if (! ($source['readonly_confirmed'] ?? false)) {
            throw new RuntimeException("Legacy {$this->service} connection is blocked until read-only use is explicitly confirmed.");
        }

        foreach (['host', 'database', 'username'] as $required) {
            if (empty($source['database'][$required])) {
                throw new RuntimeException("Legacy {$this->service} connection is not configured: {$required} is empty.");
            }
        }

        $connection = $this->connection();

        // Session-only protection. This does not change the database, role or server configuration;
        // it makes every subsequent transaction on this connection read-only by default.
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

        $createPrivilege = $this->selectOne(<<<'SQL'
SELECT has_database_privilege(current_user, current_database(), 'CREATE') AS database_create,
       EXISTS (
           SELECT 1
           FROM pg_namespace n
           WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
             AND has_schema_privilege(current_user, n.oid, 'CREATE')
       ) AS schema_create
SQL);
        if ($createPrivilege && ($createPrivilege->database_create || $createPrivilege->schema_create)) {
            throw new RuntimeException('Legacy DB role can create persistent database/schema objects; migration is blocked.');
        }

        $sequencePrivilege = $this->selectOne(<<<'SQL'
SELECT n.nspname AS schema_name, c.relname AS sequence_name
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind = 'S'
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND (
      has_sequence_privilege(current_user, c.oid, 'USAGE') OR
      has_sequence_privilege(current_user, c.oid, 'UPDATE')
  )
LIMIT 1
SQL);
        if ($sequencePrivilege) {
            throw new RuntimeException("Legacy DB role can advance sequence {$sequencePrivilege->schema_name}.{$sequencePrivilege->sequence_name}; migration is blocked.");
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
            // Every extraction query gets its own explicit READ ONLY transaction. Short lock and
            // idle timeouts ensure even an unexpected contention scenario cannot linger on legacy.
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement('SET LOCAL statement_timeout = '.$timeout);
            $connection->statement('SET LOCAL lock_timeout = 1000');
            $connection->statement('SET LOCAL idle_in_transaction_session_timeout = 5000');

            return $connection->select($sql, $bindings);
        }, 1);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    private function connection(): Connection
    {
        $this->ensureConfigured();
        $name = config('migration.legacy.'.$this->service.'.connection');
        if (! is_string($name) || $name === '') {
            throw new RuntimeException("Legacy connection name is missing for {$this->service}.");
        }

        return DB::connection($name);
    }

    private function ensureConfigured(): void
    {
        if ($this->configured) {
            return;
        }

        if (app()->bound(ConnectionProfileStore::class)) {
            app(ConnectionProfileStore::class)->apply($this->service);
        }
        $this->configured = true;
    }
}
