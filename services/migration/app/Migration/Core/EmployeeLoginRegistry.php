<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;
use PDO;

/** Private identity decisions, deliberately outside all business rollback volumes. */
final class EmployeeLoginRegistry
{
    private static function database(): PDO
    {
        $path = getenv('MIGRATION_IDENTITY_DATABASE') ?: '/identity/employee-logins.sqlite';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        chmod($path, 0600);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS employee_logins (source_key TEXT NOT NULL, old_login TEXT NOT NULL, new_login TEXT NOT NULL, actor TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (source_key, old_login))');
        $db->exec('CREATE TABLE IF NOT EXISTS identity_audit (id INTEGER PRIMARY KEY, source_key TEXT NOT NULL, old_login TEXT NOT NULL, previous_login TEXT, new_login TEXT NOT NULL, actor TEXT NOT NULL, created_at TEXT NOT NULL)');
        return $db;
    }

    public static function sourceKey(string $service = 'vacations'): string
    {
        $profile = DB::table('migration_connections')->where('service', $service)->first();
        $source = config('migration.legacy.'.$service.'.database');
        return hash('sha256', json_encode([$service, mb_strtolower(trim((string) ($profile->host ?? $source['host']))), (int) ($profile->port ?? $source['port']), (string) ($profile->database ?? $source['database']), 'public']));
    }

    public static function oldLogin(array $row): string
    {
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        $login = str_contains($email, '@') ? strstr($email, '@', true) : '';
        if ($login === '') throw new \DomainException('В исходной записи отсутствует логин для постоянного сопоставления.');
        return $login;
    }

    public static function lookup(array $row, string $service = 'vacations'): ?string
    {
        $query = self::database()->prepare('SELECT new_login FROM employee_logins WHERE source_key = ? AND old_login = ?');
        $query->execute([self::sourceKey($service), self::oldLogin($row)]);
        $login = $query->fetchColumn();
        return $login === false ? null : $login;
    }

    /** Clients can reuse the operator-confirmed Vacations list, never numeric legacy IDs. */
    public static function matchingLogin(array $row, string $service = 'vacations'): ?string
    {
        $own = self::lookup($row, $service);
        if ($service !== 'clients') return $own;
        $shared = self::lookup($row, 'vacations');
        if ($own !== null && $shared !== null && $own !== $shared) {
            throw new SourceRowConflict('IDENTITY_ALIAS_COLLISION', 'Подтверждённые сопоставления Clients и Vacations указывают на разные текущие логины. Требуется решение оператора.',
                ['client_login' => $own, 'vacation_login' => $shared]);
        }
        return $own ?? $shared;
    }

    public static function save(array $row, string $login, string $actor, bool $onlyMissing = false, string $service = 'vacations'): void
    {
        $db = self::database();
        $key = self::sourceKey($service); $old = self::oldLogin($row); $login = mb_strtolower(trim($login));
        $db->exec('BEGIN IMMEDIATE');
        try {
            $query = $db->prepare('SELECT new_login FROM employee_logins WHERE source_key = ? AND old_login = ?');
            $query->execute([$key, $old]); $previous = $query->fetchColumn();
            if ($onlyMissing && $previous !== false) { $db->exec('COMMIT'); return; }
            $time = gmdate('c');
            $query = $db->prepare('INSERT INTO employee_logins VALUES (?, ?, ?, ?, ?) ON CONFLICT(source_key, old_login) DO UPDATE SET new_login=excluded.new_login, actor=excluded.actor, updated_at=excluded.updated_at');
            $query->execute([$key, $old, $login, $actor, $time]);
            $query = $db->prepare('INSERT INTO identity_audit (source_key, old_login, previous_login, new_login, actor, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $query->execute([$key, $old, $previous === false ? null : $previous, $login, $actor, $time]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }

    public static function upgradeExisting(): void
    {
        foreach (DB::table('migration_overrides')->where('service', 'vacations')->where('entity_type', 'users')->get() as $override) {
            $note = json_decode($override->note ?? '{}', true);
            $fingerprint = $note['source_fingerprint'] ?? null;
            // A legacy numeric ID alone cannot prove identity after Employees rollback/recreation.
            if (!$fingerprint || empty($note['target_login']) || ($note['source_key'] ?? '') !== self::sourceKey()) continue;
            foreach (DB::table('migration_conflicts')->where('service', 'vacations')->where('entity_type', 'users')->where('legacy_id', $override->legacy_id)->orderByDesc('id')->get() as $conflict) {
                $source = json_decode($conflict->context ?? '{}', true)['source'] ?? [];
                if (EmployeeUserOverrides::fingerprint($source) !== $fingerprint) continue;
                if (self::lookup($source) !== null) break;
                try { $employee = EmployeeUserOverrides::match($note['target_login']); }
                catch (\DomainException $e) { break; }
                if ($employee && !empty($employee->login)) {
                    if ((int) EmployeeUserOverrides::match($employee->login)->id !== (int) $employee->id) continue;
                    EmployeeUserOverrides::assertUuid($source, (int) $employee->id);
                    self::save($source, $employee->login, 'upgrade-existing', true);
                }
                break;
            }
        }
        self::database();
    }
}
