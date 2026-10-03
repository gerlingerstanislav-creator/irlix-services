<?php

namespace App\Migration\Services;

use App\Migration\Contracts\ServiceMigration;
use App\Migration\Core\LegacyReader;
use App\Migration\Core\MigrationStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EmployeesMigration implements ServiceMigration
{
    public function __construct(private readonly MigrationStore $store)
    {
    }

    public function key(): string
    {
        return 'employees';
    }

    public function inspect(int $runId): array
    {
        $legacy = $this->legacy();
        $safety = $legacy->assertSafe();

        $tables = ['departments', 'employees', 'employments', 'employee_roles', 'salaries', 'users', 'subcontracts', 'comments'];
        $counts = [];
        foreach ($tables as $table) {
            $row = $legacy->selectOne("SELECT count(*)::bigint AS count FROM public.{$table}");
            $counts[$table] = (int) ($row->count ?? 0);
        }

        return [
            'service' => $this->key(),
            'legacy_safety' => $safety,
            'counts' => $counts,
            'statuses' => array_values(array_filter(array_map(fn ($row) => $row->status, $legacy->select('SELECT DISTINCT status FROM public.employees ORDER BY status')))),
            'employment_types' => array_values(array_filter(array_map(fn ($row) => $row->employment_type, $legacy->select('SELECT DISTINCT employment_type FROM public.employees ORDER BY employment_type')))),
            'roles' => array_values(array_filter(array_map(fn ($row) => $row->role, $legacy->select('SELECT DISTINCT role FROM public.employee_roles ORDER BY role')))),
            'notes' => [
                'comments, employee.legal_entity, employee.yandex_id and salary author_id have no direct target field and are preserved only in migration metadata/conflict reports for now.',
                'Non-numeric salary values are treated as encrypted/unresolved and are never guessed.',
            ],
        ];
    }

    public function migrate(int $runId, bool $dryRun): array
    {
        $legacy = $this->legacy();
        $legacy->assertSafe();
        $target = DB::connection('target_employees');

        if ($dryRun) {
            return $this->dryRun($runId, $legacy, $target);
        }

        $summary = [
            'departments' => ['created' => 0, 'matched' => 0],
            'employees' => ['created' => 0, 'updated' => 0],
            'employment_periods' => 0,
            'roles' => 0,
            'salaries' => 0,
            'conflicts' => 0,
        ];

        $departments = $legacy->select(<<<'SQL'
SELECT id::text AS id, head_id::text AS head_id, hr_id::text AS hr_id, parent_id::text AS parent_id,
       title, alias, yandex_id, ldap_title, is_production
FROM public.departments
ORDER BY _lft, title
SQL);

        foreach ($departments as $department) {
            $existing = $this->findTargetDepartment($target, $department);
            if ($existing) {
                $targetId = (int) $existing->id;
                $summary['departments']['matched']++;
            } else {
                $targetId = (int) $target->table('departments')->insertGetId([
                    'name' => $department->title,
                    'alias' => $department->alias,
                    'parent_id' => null,
                    'manager_id' => null,
                    'hr_id' => null,
                    'yandex_id' => $department->yandex_id,
                    'ldap_group' => $department->ldap_title,
                    'is_production' => (bool) $department->is_production,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $summary['departments']['created']++;
            }

            $this->store->saveMapping($runId, $this->key(), 'department', $department->id, $targetId, [
                'yandex_id' => $department->yandex_id,
                'alias' => $department->alias,
                'title' => $department->title,
            ]);
        }

        $employees = $legacy->select(<<<'SQL'
SELECT id::text AS id, username, email, name, surname, patronymic, gender, birthdate, city,
       is_remote, specialization, employment_type, position, phone, skype, telegram, personal_email,
       status, hired_at, dismissed_at, department_id::text AS department_id, legal_entity, yandex_id,
       created_at, updated_at
FROM public.employees
ORDER BY created_at NULLS LAST, surname, name
SQL);

        foreach ($employees as $employee) {
            $departmentId = $employee->department_id ? $this->store->mapping($this->key(), 'department', $employee->department_id) : null;
            $existing = $this->findTargetEmployee($target, $employee, $runId);
            $payload = [
                'full_name' => trim(implode(' ', array_filter([$employee->surname, $employee->name, $employee->patronymic]))),
                'first_name' => $employee->name,
                'last_name' => $employee->surname,
                'middle_name' => $employee->patronymic,
                'gender' => $employee->gender,
                'login' => $employee->username,
                'work_email' => $employee->email,
                'personal_email' => $employee->personal_email,
                'birth_date' => $this->usableDate($employee->birthdate),
                'city' => $employee->city,
                'phone' => $employee->phone,
                'telegram' => $employee->telegram,
                'skype' => $employee->skype,
                'specialization' => $employee->specialization,
                'department_id' => $departmentId ? (int) $departmentId : null,
                'position' => $employee->position,
                'employment_status' => $employee->status,
                'work_format' => $employee->is_remote ? 'Удалённо' : 'Офис',
                'cooperation_type' => $employee->employment_type,
                'is_remote' => (bool) $employee->is_remote,
                'hired_at' => $this->usableDate($employee->hired_at),
                'fired_at' => $this->usableDate($employee->dismissed_at),
                'updated_at' => now(),
            ];

            if ($existing) {
                $targetId = (int) $existing->id;
                $target->table('employees')->where('id', $targetId)->update($payload);
                $summary['employees']['updated']++;
            } else {
                $targetId = (int) $target->table('employees')->insertGetId($payload + [
                    'identity_status' => 'not_provisioned',
                    'onboarding_email_status' => 'not_requested',
                    'created_at' => $employee->created_at ?: now(),
                ]);
                $summary['employees']['created']++;
            }

            $this->store->saveMapping($runId, $this->key(), 'employee', $employee->id, $targetId, [
                'legacy_yandex_id' => $employee->yandex_id,
                'legacy_legal_entity' => $employee->legal_entity,
                'legacy_created_at' => $employee->created_at,
                'legacy_updated_at' => $employee->updated_at,
            ]);
            foreach (['birthdate', 'hired_at', 'dismissed_at'] as $field) {
                if ($this->isPlaceholderDate($employee->{$field})) {
                    $this->store->conflict($runId, $this->key(), 'employee', $employee->id, 'LEGACY_DATE_PLACEHOLDER', "Legacy {$field} is a placeholder; target date was left empty for manual correction.", ['field' => $field], 'warning');
                    $summary['conflicts']++;
                }
            }
        }

        // Resolve the cyclic department -> manager/hr -> employee links only after every employee exists.
        foreach ($departments as $department) {
            $targetDepartmentId = $this->store->mapping($this->key(), 'department', $department->id);
            if (! $targetDepartmentId) {
                continue;
            }
            $desired = [
                'parent_id' => $department->parent_id ? $this->store->mapping($this->key(), 'department', $department->parent_id) : null,
                'manager_id' => $department->head_id ? $this->store->mapping($this->key(), 'employee', $department->head_id) : null,
                'hr_id' => $department->hr_id ? $this->store->mapping($this->key(), 'employee', $department->hr_id) : null,
            ];
            $current = $target->table('departments')->where('id', (int) $targetDepartmentId)->first();
            $updates = [];
            foreach ($desired as $field => $value) {
                if ($value === null) {
                    continue;
                }
                if ($current->{$field} === null) {
                    $updates[$field] = (int) $value;
                } elseif ((int) $current->{$field} !== (int) $value) {
                    $this->store->conflict($runId, $this->key(), 'department', $department->id, 'TARGET_RELATION_DIFFERS', "Target {$field} differs from legacy mapping; target value was kept.", [
                        'field' => $field,
                        'target' => $current->{$field},
                        'legacy_mapped' => $value,
                    ], 'warning');
                    $summary['conflicts']++;
                }
            }
            if ($updates) {
                $updates['updated_at'] = now();
                $target->table('departments')->where('id', (int) $targetDepartmentId)->update($updates);
            }
        }

        $employments = $legacy->select('SELECT id, employee_id::text AS employee_id, type, start_date, end_date FROM public.employments ORDER BY employee_id, start_date, id');
        foreach ($employments as $employment) {
            if ($this->store->mapping($this->key(), 'employment', $employment->id)) {
                continue;
            }
            if ($this->isPlaceholderDate($employment->start_date)) {
                $this->store->conflict($runId, $this->key(), 'employment', $employment->id, 'LEGACY_DATE_PLACEHOLDER', 'Legacy start_date is a placeholder; employment period was left for manual correction.', ['field' => 'start_date'], 'warning');
                $summary['conflicts']++;
                continue;
            }
            $endDate = $this->usableDate($employment->end_date);
            if ($endDate === null && $employment->end_date !== null) {
                $this->store->conflict($runId, $this->key(), 'employment', $employment->id, 'LEGACY_DATE_PLACEHOLDER', 'Legacy end_date is a placeholder; target end date was left empty.', ['field' => 'end_date'], 'warning');
                $summary['conflicts']++;
            }
            $employeeId = $this->store->mapping($this->key(), 'employee', $employment->employee_id);
            if (! $employeeId) {
                $this->store->conflict($runId, $this->key(), 'employment', $employment->id, 'EMPLOYEE_NOT_MAPPED', 'Employment period employee is not mapped.');
                $summary['conflicts']++;
                continue;
            }
            $existing = $target->table('employment_periods')
                ->where('employee_id', (int) $employeeId)
                ->where('cooperation_type', $employment->type)
                ->whereDate('started_at', $employment->start_date)
                ->where(function ($query) use ($endDate): void {
                    $endDate !== null ? $query->whereDate('ended_at', $endDate) : $query->whereNull('ended_at');
                })->first();
            if ($existing) {
                $periodId = (int) $existing->id;
            } else {
                if ($endDate === null && $target->table('employment_periods')->where('employee_id', (int) $employeeId)->whereNull('ended_at')->exists()) {
                    $this->store->conflict($runId, $this->key(), 'employment', $employment->id, 'OPEN_PERIOD_ALREADY_EXISTS', 'Target already has a different open employment period; legacy row was skipped.');
                    $summary['conflicts']++;
                    continue;
                }
                $periodId = (int) $target->table('employment_periods')->insertGetId([
                    'employee_id' => (int) $employeeId,
                    'cooperation_type' => $employment->type,
                    'started_at' => $employment->start_date,
                    'ended_at' => $endDate,
                    // Legacy employment periods do not contain historical department/position.
                    'department_id' => null,
                    'position' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->store->saveMapping($runId, $this->key(), 'employment', $employment->id, $periodId);
            $summary['employment_periods']++;
        }

        $roles = $legacy->select('SELECT id, employee_id::text AS employee_id, role FROM public.employee_roles ORDER BY id');
        foreach ($roles as $role) {
            $employeeId = $this->store->mapping($this->key(), 'employee', $role->employee_id);
            if (! $employeeId) {
                $this->store->conflict($runId, $this->key(), 'employee_role', $role->id, 'EMPLOYEE_NOT_MAPPED', 'Role employee is not mapped.');
                $summary['conflicts']++;
                continue;
            }
            $target->table('employee_access_roles')->insertOrIgnore([
                'employee_id' => (int) $employeeId,
                'role' => $role->role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $targetRoleId = $target->table('employee_access_roles')->where('employee_id', (int) $employeeId)->where('role', $role->role)->value('id');
            $this->store->saveMapping($runId, $this->key(), 'employee_role', $role->id, (int) $targetRoleId);
            $summary['roles']++;
        }

        $salaryRows = $legacy->select('SELECT id, employee_id::text AS employee_id, date, gross, bonus, status, comment, author_id FROM public.salaries ORDER BY employee_id, date, id');
        $grouped = [];
        foreach ($salaryRows as $row) {
            $grouped[$row->employee_id][] = $row;
        }
        foreach ($grouped as $legacyEmployeeId => $rows) {
            $employeeId = $this->store->mapping($this->key(), 'employee', $legacyEmployeeId);
            if (! $employeeId) {
                continue;
            }
            foreach ($rows as $index => $salary) {
                if ($this->store->mapping($this->key(), 'salary', $salary->id)) {
                    continue;
                }
                if ($this->isPlaceholderDate($salary->date)) {
                    $this->store->conflict($runId, $this->key(), 'salary', $salary->id, 'LEGACY_DATE_PLACEHOLDER', 'Legacy salary date is a placeholder; salary row was left for manual correction.', ['field' => 'date'], 'warning');
                    $summary['conflicts']++;
                    continue;
                }
                if (! is_numeric($salary->gross) || ($salary->bonus !== null && ! is_numeric($salary->bonus))) {
                    $this->store->conflict($runId, $this->key(), 'salary', $salary->id, 'SALARY_VALUE_ENCRYPTED_OR_INVALID', 'Salary/bonus is not numeric. Migration requires legacy decryption key or a decrypted export.', [
                        'gross_is_numeric' => is_numeric($salary->gross),
                        'bonus_is_numeric' => $salary->bonus === null || is_numeric($salary->bonus),
                    ]);
                    $summary['conflicts']++;
                    continue;
                }
                $nextIndex = $index + 1;
                while (isset($rows[$nextIndex]) && $this->isPlaceholderDate($rows[$nextIndex]->date)) {
                    $nextIndex++;
                }
                $next = $rows[$nextIndex] ?? null;
                $effectiveTo = $next ? CarbonImmutable::parse($next->date)->subDay()->toDateString() : null;
                $existing = $target->table('salary_history')->where('employee_id', (int) $employeeId)->whereDate('effective_from', $salary->date)->first();
                $payload = [
                    'employee_id' => (int) $employeeId,
                    'effective_from' => $salary->date,
                    'effective_to' => $effectiveTo,
                    'gross_salary' => (float) $salary->gross,
                    'bonus' => $salary->bonus === null ? null : (float) $salary->bonus,
                    'status' => $effectiveTo ? 'Завершена' : ($salary->status ?: 'Действует'),
                    'comment' => $salary->comment,
                    'updated_at' => now(),
                ];
                if ($existing) {
                    $salaryId = (int) $existing->id;
                    $target->table('salary_history')->where('id', $salaryId)->update($payload);
                } else {
                    if ($effectiveTo === null && $target->table('salary_history')->where('employee_id', (int) $employeeId)->whereNull('effective_to')->exists()) {
                        $this->store->conflict($runId, $this->key(), 'salary', $salary->id, 'ACTIVE_TARGET_SALARY_EXISTS', 'Target already has another active salary row; legacy row was skipped.');
                        $summary['conflicts']++;
                        continue;
                    }
                    $salaryId = (int) $target->table('salary_history')->insertGetId($payload + ['created_at' => now()]);
                }
                $this->store->saveMapping($runId, $this->key(), 'salary', $salary->id, $salaryId, ['legacy_author_id' => $salary->author_id]);
                $summary['salaries']++;
            }
        }

        $this->ensureLifecycleHistory($target, $employees, $runId, $summary);

        return $summary;
    }

    public function validate(int $runId): array
    {
        $legacy = $this->legacy();
        $legacy->assertSafe();
        $legacyEmployees = (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.employees')->count ?? 0);
        $legacyDepartments = (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.departments')->count ?? 0);
        $mappedEmployees = $this->store->mappedCount($this->key(), 'employee');
        $mappedDepartments = $this->store->mappedCount($this->key(), 'department');

        return [
            'ok' => $legacyEmployees === $mappedEmployees && $legacyDepartments === $mappedDepartments,
            'legacy' => ['employees' => $legacyEmployees, 'departments' => $legacyDepartments],
            'mapped' => ['employees' => $mappedEmployees, 'departments' => $mappedDepartments],
            'note' => 'Salary validation is intentionally separate because encrypted legacy values may require APP_KEY/decrypted export.',
        ];
    }

    private function dryRun(int $runId, LegacyReader $legacy, Connection $target): array
    {
        $summary = [
            'mode' => 'dry-run',
            'departments' => ['existing' => 0, 'would_create' => 0],
            'employees' => ['existing' => 0, 'would_create' => 0, 'identity_conflicts' => 0],
            'salary_rows' => ['ready' => 0, 'encrypted_or_invalid' => 0],
            'placeholder_dates' => ['employees' => 0, 'employments' => 0, 'salaries' => 0],
        ];

        foreach ($legacy->select('SELECT id::text AS id, title, alias, yandex_id FROM public.departments ORDER BY title') as $department) {
            $this->findTargetDepartment($target, $department) ? $summary['departments']['existing']++ : $summary['departments']['would_create']++;
        }
        foreach ($legacy->select('SELECT id::text AS id, username, email, birthdate, hired_at, dismissed_at FROM public.employees ORDER BY id') as $employee) {
            foreach (['birthdate', 'hired_at', 'dismissed_at'] as $field) {
                if ($this->isPlaceholderDate($employee->{$field})) {
                    $summary['placeholder_dates']['employees']++;
                }
            }
            try {
                $this->findTargetEmployee($target, $employee, $runId);
                $existing = $target->table('employees')->where('login', $employee->username)->orWhere('work_email', $employee->email)->exists();
                $existing ? $summary['employees']['existing']++ : $summary['employees']['would_create']++;
            } catch (RuntimeException) {
                $summary['employees']['identity_conflicts']++;
            }
        }
        foreach ($legacy->select('SELECT id, date, gross, bonus FROM public.salaries ORDER BY id') as $salary) {
            if ($this->isPlaceholderDate($salary->date)) {
                $summary['placeholder_dates']['salaries']++;
                continue;
            }
            if (is_numeric($salary->gross) && ($salary->bonus === null || is_numeric($salary->bonus))) {
                $summary['salary_rows']['ready']++;
            } else {
                $summary['salary_rows']['encrypted_or_invalid']++;
            }
        }
        foreach ($legacy->select('SELECT start_date, end_date FROM public.employments ORDER BY id') as $employment) {
            foreach (['start_date', 'end_date'] as $field) {
                if ($this->isPlaceholderDate($employment->{$field})) {
                    $summary['placeholder_dates']['employments']++;
                }
            }
        }

        return $summary;
    }

    private function legacy(): LegacyReader
    {
        return new LegacyReader($this->key());
    }

    private function findTargetDepartment(Connection $target, object $department): ?object
    {
        if ($department->yandex_id !== null) {
            $found = $target->table('departments')->where('yandex_id', $department->yandex_id)->first();
            if ($found) {
                return $found;
            }
        }
        if (! empty($department->alias)) {
            $found = $target->table('departments')->where('alias', $department->alias)->first();
            if ($found) {
                return $found;
            }
        }

        return $target->table('departments')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($department->title))])->first();
    }

    private function findTargetEmployee(Connection $target, object $employee, int $runId): ?object
    {
        $byLogin = $target->table('employees')->whereRaw('LOWER(login) = ?', [mb_strtolower(trim($employee->username))])->first();
        $byEmail = $target->table('employees')->whereRaw('LOWER(work_email) = ?', [mb_strtolower(trim($employee->email))])->first();
        if ($byLogin && $byEmail && (int) $byLogin->id !== (int) $byEmail->id) {
            $this->store->conflict($runId, $this->key(), 'employee', $employee->id, 'IDENTITY_COLLISION', 'Legacy login and email resolve to different target employees.', [
                'login_target_id' => $byLogin->id,
                'email_target_id' => $byEmail->id,
            ]);
            throw new RuntimeException('Employee identity collision for legacy employee '.$employee->id);
        }

        return $byLogin ?: $byEmail ?: null;
    }

    private function ensureLifecycleHistory(Connection $target, array $employees, int $runId, array &$summary): void
    {
        foreach ($employees as $employee) {
            $employeeId = $this->store->mapping($this->key(), 'employee', $employee->id);
            if (! $employeeId) {
                continue;
            }
            // A year-one sentinel is not an employment event. Leave the history absent
            // so an operator can enter the actual date instead of inventing an interval.
            if ($this->isPlaceholderDate($employee->hired_at) || $this->isPlaceholderDate($employee->dismissed_at)) {
                continue;
            }
            $from = $employee->hired_at ?: ($employee->created_at ? substr((string) $employee->created_at, 0, 10) : now()->toDateString());
            if (! $target->table('employee_status_history')->where('employee_id', (int) $employeeId)->exists()) {
                if ($employee->dismissed_at) {
                    $target->table('employee_status_history')->insert([
                        ['employee_id' => (int) $employeeId, 'status' => 'Трудоустроен', 'effective_from' => $from, 'effective_to' => CarbonImmutable::parse($employee->dismissed_at)->subDay()->toDateString(), 'reason' => 'Legacy migration (reconstructed)', 'created_at' => now(), 'updated_at' => now()],
                        ['employee_id' => (int) $employeeId, 'status' => $employee->status ?: 'Уволен', 'effective_from' => $employee->dismissed_at, 'effective_to' => null, 'reason' => 'Legacy migration', 'created_at' => now(), 'updated_at' => now()],
                    ]);
                } else {
                    $target->table('employee_status_history')->insert([
                        'employee_id' => (int) $employeeId,
                        'status' => $employee->status ?: 'Трудоустроен',
                        'effective_from' => $from,
                        'effective_to' => null,
                        'reason' => 'Legacy migration',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (! $target->table('employment_assignment_history')->where('employee_id', (int) $employeeId)->exists()) {
                $departmentId = $employee->department_id ? $this->store->mapping($this->key(), 'department', $employee->department_id) : null;
                $target->table('employment_assignment_history')->insert([
                    'employee_id' => (int) $employeeId,
                    'department_id' => $departmentId ? (int) $departmentId : null,
                    'position' => $employee->position,
                    'effective_from' => $from,
                    'effective_to' => $employee->dismissed_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function usableDate(?string $value): ?string
    {
        return $this->isPlaceholderDate($value) ? null : $value;
    }

    private function isPlaceholderDate(?string $value): bool
    {
        return $value !== null && (str_starts_with($value, '0001-01-01') || str_starts_with($value, '0000-'));
    }
}
