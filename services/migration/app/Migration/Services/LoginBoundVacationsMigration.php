<?php

namespace App\Migration\Services;

use App\Migration\Contracts\ServiceMigration;
use App\Migration\Core\LegacyReader;
use App\Migration\Core\MigrationStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vacations migration guard: Employees is the source of truth and login is the
 * universal cross-service employee identity. The legacy Vacations DB contains
 * corporate email, so its local part is normalized into login.
 */
final class LoginBoundVacationsMigration implements ServiceMigration
{
    private VacationsMigration $delegate;

    public function __construct(private readonly MigrationStore $store)
    {
        $this->delegate = new VacationsMigration($store);
    }

    public function key(): string
    {
        return 'vacations';
    }

    public function inspect(int $runId): array
    {
        $result = $this->delegate->inspect($runId);
        $result['employee_identity'] = [
            'source' => 'legacy employee.email local-part',
            'target' => 'employees.login',
            'rule' => 'case-insensitive exact login match; Employees must already be populated',
        ];

        return $result;
    }

    public function migrate(int $runId, bool $dryRun): array
    {
        $preflight = $this->prepareEmployeeOverrides($runId);

        if (! $dryRun && $preflight['unresolved'] > 0) {
            throw new RuntimeException(
                "Vacations migration blocked: {$preflight['unresolved']} employee(s) cannot be resolved by unique login in Employees. Run Dry run and fix Employees data first."
            );
        }

        $result = $this->delegate->migrate($runId, $dryRun);
        $result['login_preflight'] = $preflight;

        return $result;
    }

    public function validate(int $runId): array
    {
        return $this->delegate->validate($runId);
    }

    private function prepareEmployeeOverrides(int $runId): array
    {
        $legacy = new LegacyReader($this->key());
        $legacy->assertSafe();
        $target = DB::connection('target_employees');
        $resolved = 0;
        $unresolved = 0;

        $employees = $legacy->select(
            'SELECT id, email, name, surname, middle_name FROM public.employee ORDER BY id'
        );

        foreach ($employees as $employee) {
            $login = $this->loginFromEmail($employee->email ?? null);
            $matches = collect();
            if ($login !== null) {
                $matches = $target->table('employees')
                    ->whereRaw('LOWER(TRIM(login)) = ?', [$login])
                    ->pluck('id');
            }

            $targetId = $matches->count() === 1 ? (int) $matches->first() : 0;
            DB::table('migration_overrides')->updateOrInsert(
                [
                    'service' => $this->key(),
                    'entity_type' => 'employee',
                    'legacy_id' => (string) $employee->id,
                ],
                [
                    'target_id' => (string) $targetId,
                    'note' => $targetId > 0
                        ? "auto: login {$login}"
                        : 'auto: unresolved login; target_id=0 intentionally blocks fallback matching',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            if ($targetId > 0) {
                $resolved++;
                continue;
            }

            $unresolved++;
            $this->store->conflict(
                $runId,
                $this->key(),
                'employee',
                $employee->id,
                $login === null ? 'EMPLOYEE_LOGIN_MISSING' : 'EMPLOYEE_LOGIN_NOT_UNIQUE',
                $login === null
                    ? 'Legacy Vacations employee has no corporate email from which login can be derived.'
                    : 'Legacy Vacations employee must resolve to exactly one Employees record by login.',
                [
                    'login' => $login,
                    'email' => $employee->email ?? null,
                    'target_matches' => $matches->count(),
                    'full_name' => trim(implode(' ', array_filter([
                        $employee->surname ?? null,
                        $employee->name ?? null,
                        $employee->middle_name ?? null,
                    ]))),
                ],
            );
        }

        return [
            'total' => count($employees),
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'identity' => 'login',
        ];
    }

    private function loginFromEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $login = trim(strstr($email, '@', true));

        return $login === '' ? null : $login;
    }
}
