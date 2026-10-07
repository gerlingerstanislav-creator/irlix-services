<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

final class EmployeeUserOverrides
{
    public static function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([mb_strtolower(trim($row['email'] ?? '')), (string) ($row['employee_id'] ?? '')]));
    }

    public static function resolve(array $row): ?int
    {
        $override = DB::table('migration_overrides')->where('service', 'vacations')->where('entity_type', 'users')->where('legacy_id', (string) $row['id'])->first();
        if (!$override) return null;
        $note = json_decode($override->note ?? '{}', true);
        if (($note['source_fingerprint'] ?? '') !== self::fingerprint($row)) throw new \DomainException('Исходные данные пользователя изменились. Проверьте ручное сопоставление заново.');
        $id = (int) $override->target_id;
        if (!DB::connection('target_employees')->table('employees')->where('id', $id)->exists()) throw new \DomainException('Сотрудник из ручного сопоставления больше не существует.');
        self::assertUuid($row, $id);
        return $id;
    }

    public static function assertUuid(array $row, int $id): void
    {
        if (!empty($row['employee_id'])) {
            $mapped = app(MigrationStore::class)->mapping('employees', 'employee', $row['employee_id']);
            if ($mapped !== null && (int) $mapped !== $id) throw new \DomainException('Выбранный сотрудник противоречит сохранённому сопоставлению UUID Employees.');
        }
    }

    public static function source(int $conflict): array
    {
        $error = DB::table('migration_conflicts')->find($conflict);
        $source = json_decode($error->context ?? '{}', true)['source'] ?? [];
        if (!$error || $error->service !== 'vacations' || $error->entity_type !== 'users' || $error->severity !== 'error' || empty($source['email']) || (string) ($source['id'] ?? '') !== (string) $error->legacy_id) throw new \DomainException('Для этой ошибки нет данных пользователя. Повторите Dry run.');
        $latest = app(MigrationStore::class)->latestRun('vacations', 'dry-run');
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
