<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

final class EmployeeUserOverrides
{
    public static function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([mb_strtolower(trim($row['email'] ?? '')), (string) ($row['employee_id'] ?? $row['external_key'] ?? '')]));
    }

    public static function resolve(array $row, string $service = 'vacations'): ?int
    {
        $login = EmployeeLoginRegistry::matchingLogin($row, $service);
        if ($login === null) return null;
        try { $employee = self::match($login); }
        catch (\DomainException $e) {
            throw new \DomainException('Сохранено сопоставление '.EmployeeLoginRegistry::oldLogin($row).' → '.$login.'. '.$e->getMessage().' Сопоставление сохранено; восстановите сотрудника или исправьте текущий логин.');
        }
        self::assertUuid($row, (int) $employee->id);
        return (int) $employee->id;
    }

    /**
     * A previously imported user of Vacations or Clients may already carry
     * an explicitly reconciled corporate UUID. Reuse that UUID, not the old
     * service-local numeric user ID. This private mapping is not a new
     * identity decision and cannot override Employees or the login registry.
     */
    public static function previouslyImportedUuid(array $row): ?int
    {
        $uuid = trim((string) ($row['employee_id'] ?? $row['external_key'] ?? ''));
        if ($uuid === '') return null;
        $matches = [];
        foreach (DB::table('migration_mappings')
            ->whereIn('service', ['vacations', 'clients'])->where('entity_type', 'users')
            ->select(['target_id', 'metadata'])->cursor() as $mapping) {
            $source = json_decode($mapping->metadata ?? '{}', true)['source'] ?? [];
            if (!is_array($source)) continue;
            $previousUuid = trim((string) ($source['employee_id'] ?? $source['external_key'] ?? ''));
            if ($previousUuid !== $uuid) continue;
            if (!ctype_digit((string) $mapping->target_id) || (int) $mapping->target_id < 1) {
                throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                    'Старое сопоставление сотрудника содержит недопустимый Employees ID.');
            }
            $matches[(int) $mapping->target_id] = true;
        }
        if (count($matches) > 1) {
            throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                'Ранее импортированные Clients и Vacations сопоставили один UUID разным сотрудникам. Требуется ручная сверка.',
                ['source_field'=>'users.employee_id', 'target_id_count'=>count($matches)]);
        }
        if (!$matches) return null;
        $id = (int) array_key_first($matches);
        self::assertUuid($row, $id);
        if (!DB::connection('target_employees')->table('employees')->where('id', $id)->exists()) {
            throw new SourceRowConflict('MAPPING_TARGET_MISSING',
                'Сопоставление из предыдущего сервиса указывает на отсутствующего сотрудника Employees.');
        }
        return $id;
    }

    public static function assertUuid(array $row, int $id): void
    {
        $uuid = $row['employee_id'] ?? $row['external_key'] ?? null;
        if (!empty($uuid)) {
            $mapped = app(MigrationStore::class)->mapping('employees', 'employee', $uuid);
            if ($mapped !== null && (int) $mapped !== $id) throw new \DomainException('Выбранный сотрудник противоречит сохранённому сопоставлению UUID Employees.');
        }
    }

    public static function source(int $conflict): array
    {
        $error = DB::table('migration_conflicts')->find($conflict);
        $source = json_decode($error->context ?? '{}', true)['source'] ?? [];
        if (!$error || !in_array($error->service, ['vacations','clients'], true) || $error->entity_type !== 'users' || $error->severity !== 'error' || empty($source['email']) || (string) ($source['id'] ?? '') !== (string) $error->legacy_id) throw new \DomainException('Для этой ошибки нет данных пользователя. Повторите Dry run.');
        $latest = app(MigrationStore::class)->latestRun($error->service, 'dry-run');
        if (!$latest || (int) $latest['id'] !== (int) $error->migration_run_id || !in_array($latest['status'], ['conflicts','failed'], true)) throw new \DomainException('Ошибка относится к прежнему запуску. Повторите Dry run и сопоставьте пользователя из нового отчёта.');
        return [$error, $source];
    }

    public static function match(string $login): object
    {
        $matches = DB::connection('target_employees')->table('employees')->whereRaw('LOWER(TRIM(login)) = ?', [mb_strtolower(trim($login))])->get(['id', 'login', 'full_name']);
        if ($matches->count() !== 1) throw new \DomainException($matches->isEmpty() ? 'Сотрудник с этим текущим логином не найден.' : 'Найдено несколько сотрудников. Сопоставление неоднозначно.');
        return $matches->first();
    }

    public static function idle(MigrationOperationsClient $ops): void
    {
        foreach (array_keys(config('migration.modules', [])) as $service) if (app(MigrationStore::class)->hasActiveRun($service)) throw new \RuntimeException('Дождитесь завершения активного переноса.', 409);
        [$code, $state] = $ops->request('GET', '/console/state');
        if ($code !== 200) throw new \RuntimeException('Очередь переноса недоступна.', 503);
        if (in_array($state['operation']['status'] ?? '', ['queued','running'], true) || in_array($state['snapshot_operation']['state'] ?? '', ['queued','running'], true)) throw new \RuntimeException('Дождитесь завершения операции.', 409);
    }
}
