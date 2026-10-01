<?php

namespace App\Migration\Services;

use App\Migration\Contracts\ServiceMigration;
use App\Migration\Core\LegacyReader;
use App\Migration\Core\MigrationStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class VacationsMigration implements ServiceMigration
{
    public function __construct(private readonly MigrationStore $store)
    {
    }

    public function key(): string
    {
        return 'vacations';
    }

    public function inspect(int $runId): array
    {
        $legacy = $this->legacy();
        $safety = $legacy->assertSafe();

        return [
            'service' => $this->key(),
            'legacy_safety' => $safety,
            'counts' => [
                'employees' => (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.employee')->count ?? 0),
                'vacations' => (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.vacation')->count ?? 0),
                'approvals' => (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.vacation_approval')->count ?? 0),
            ],
            'vacation_types' => array_map(fn ($row) => ['id' => (int) $row->id, 'value' => $row->value, 'description' => $row->description], $legacy->select('SELECT id, value, description FROM public.vacation_type_dict ORDER BY id')),
            'vacation_statuses' => array_values(array_filter(array_map(fn ($row) => $row->vacation_status, $legacy->select('SELECT DISTINCT vacation_status FROM public.vacation ORDER BY vacation_status')))),
            'approval_statuses' => array_values(array_filter(array_map(fn ($row) => $row->vacation_approval_status, $legacy->select('SELECT DISTINCT vacation_approval_status FROM public.vacation_approval ORDER BY vacation_approval_status')))),
            'notes' => [
                'Vacation employees are a legacy copy and are matched to target Employees; automatic matching is conservative.',
                'Legacy approvals have no stage/action timestamp, so they are preserved as migration metadata instead of fabricated into the new workflow.',
                'Legacy vacation pay gross/net have no target business field and are preserved in metadata plus a warning conflict.',
            ],
        ];
    }

    public function migrate(int $runId, bool $dryRun): array
    {
        $legacy = $this->legacy();
        $legacy->assertSafe();
        $targetVacations = DB::connection('target_vacations');
        $targetEmployees = DB::connection('target_employees');

        if ($dryRun) {
            return $this->dryRun($runId, $legacy, $targetEmployees);
        }

        $summary = [
            'employees' => ['mapped' => 0, 'conflicts' => 0],
            'vacations' => ['migrated' => 0, 'skipped' => 0],
            'warnings' => 0,
            'conflicts' => 0,
        ];

        $legacyEmployees = $legacy->select(<<<'SQL'
SELECT id, name, surname, middle_name, email, employment_date, dismissal_date, department_name,
       department_dict_id, employee_status_id
FROM public.employee
ORDER BY id
SQL);
        foreach ($legacyEmployees as $employee) {
            $targetId = $this->resolveEmployee($employee, $targetEmployees);
            if ($targetId === null) {
                $this->store->conflict($runId, $this->key(), 'employee', $employee->id, 'EMPLOYEE_NOT_RESOLVED', 'Legacy Vacations employee cannot be safely matched to Employees.', [
                    'email' => $employee->email,
                    'full_name' => trim("{$employee->surname} {$employee->name} {$employee->middle_name}"),
                    'employment_date' => $employee->employment_date,
                    'dismissal_date' => $employee->dismissal_date,
                ]);
                $summary['employees']['conflicts']++;
                $summary['conflicts']++;
                continue;
            }
            $this->store->saveMapping($runId, $this->key(), 'employee', $employee->id, $targetId, [
                'email' => $employee->email,
                'legacy_department_name' => $employee->department_name,
                'legacy_department_dict_id' => $employee->department_dict_id,
                'legacy_employee_status_id' => $employee->employee_status_id,
            ]);
            $summary['employees']['mapped']++;
        }

        $types = [];
        foreach ($legacy->select('SELECT id, value, description FROM public.vacation_type_dict ORDER BY id') as $type) {
            $types[(int) $type->id] = $this->mapType($type->value, $type->description);
        }
        $approvals = [];
        foreach ($legacy->select('SELECT id, vacation_id, vacation_approval_status, approving_employee_id, employee_project_id FROM public.vacation_approval ORDER BY vacation_id, id') as $approval) {
            $approvals[(int) $approval->vacation_id][] = [
                'id' => (int) $approval->id,
                'status' => $approval->vacation_approval_status,
                'approving_employee_id' => (int) $approval->approving_employee_id,
                'employee_project_id' => $approval->employee_project_id === null ? null : (int) $approval->employee_project_id,
            ];
        }

        $vacations = $legacy->select(<<<'SQL'
SELECT id, date_from, date_to, vacation_pay_gross, vacation_pay_net, vacation_status,
       vacation_type_dict_id, employee_id
FROM public.vacation
ORDER BY id
SQL);
        foreach ($vacations as $vacation) {
            $employeeId = $vacation->employee_id === null ? null : $this->store->mapping($this->key(), 'employee', $vacation->employee_id);
            $type = $types[(int) $vacation->vacation_type_dict_id] ?? null;
            $status = $this->mapStatus($vacation->vacation_status);
            if (! $employeeId || ! $type || ! $status) {
                $this->store->conflict($runId, $this->key(), 'vacation', $vacation->id, 'VACATION_NOT_SAFE_TO_MAP', 'Vacation was skipped because employee, type or status is unresolved.', [
                    'employee_resolved' => (bool) $employeeId,
                    'type_resolved' => (bool) $type,
                    'status_resolved' => (bool) $status,
                    'legacy_type_id' => $vacation->vacation_type_dict_id,
                    'legacy_status' => $vacation->vacation_status,
                ]);
                $summary['vacations']['skipped']++;
                $summary['conflicts']++;
                continue;
            }

            $calendarDays = CarbonImmutable::parse($vacation->date_from)->diffInDays(CarbonImmutable::parse($vacation->date_to)) + 1;
            $existingId = $this->store->mapping($this->key(), 'vacation', $vacation->id);
            $payload = [
                'employee_id' => (int) $employeeId,
                'type' => $type,
                'starts_on' => $vacation->date_from,
                'ends_on' => $vacation->date_to,
                'calendar_days' => $calendarDays,
                'status' => $status,
                'comment' => null,
                'created_by_subject' => 'legacy-migration',
                'submitted_at' => null,
                'confirmed_at' => null,
                'updated_at' => now(),
            ];
            if ($existingId) {
                $absenceId = (int) $existingId;
                $targetVacations->table('absences')->where('id', $absenceId)->update($payload);
            } else {
                $absenceId = (int) $targetVacations->table('absences')->insertGetId($payload + ['created_at' => now()]);
            }

            $legacyApprovals = $approvals[(int) $vacation->id] ?? [];
            $this->store->saveMapping($runId, $this->key(), 'vacation', $vacation->id, $absenceId, [
                'legacy_vacation_pay_gross' => $vacation->vacation_pay_gross,
                'legacy_vacation_pay_net' => $vacation->vacation_pay_net,
                'legacy_approvals' => $legacyApprovals,
                'approval_history_reconstructed' => false,
            ]);

            if ($vacation->vacation_pay_gross !== null || $vacation->vacation_pay_net !== null) {
                $this->store->conflict($runId, $this->key(), 'vacation', $vacation->id, 'VACATION_PAY_UNMAPPED', 'Vacation pay has no target domain field; values are preserved in migration metadata.', [
                    'gross' => $vacation->vacation_pay_gross,
                    'net' => $vacation->vacation_pay_net,
                ], 'warning');
                $summary['warnings']++;
            }
            if ($legacyApprovals) {
                $this->store->conflict($runId, $this->key(), 'vacation', $vacation->id, 'APPROVAL_HISTORY_PARTIAL', 'Legacy approval rows are preserved as metadata; stage and action timestamp cannot be reconstructed from the old schema.', [
                    'legacy_approval_count' => count($legacyApprovals),
                ], 'warning');
                $summary['warnings']++;
            }

            if (! $targetVacations->table('absence_status_history')->where('absence_id', $absenceId)->exists()) {
                $targetVacations->table('absence_status_history')->insert([
                    'absence_id' => $absenceId,
                    'from_status' => null,
                    'to_status' => $status,
                    'actor_subject' => 'legacy-migration',
                    'actor_employee_id' => null,
                    'reason' => 'Imported final legacy state; historical transitions unavailable',
                    'context' => json_encode(['legacy_vacation_id' => (int) $vacation->id, 'reconstructed' => false]),
                    'created_at' => now(),
                ]);
            }
            if (! $targetVacations->table('absence_audit_log')->where('absence_id', $absenceId)->where('event', 'legacy_migrated')->exists()) {
                $targetVacations->table('absence_audit_log')->insert([
                    'absence_id' => $absenceId,
                    'event' => 'legacy_migrated',
                    'actor_subject' => 'legacy-migration',
                    'actor_employee_id' => null,
                    'before' => null,
                    'after' => json_encode(['legacy_vacation_id' => (int) $vacation->id, 'legacy_status' => $vacation->vacation_status]),
                    'created_at' => now(),
                ]);
            }
            $summary['vacations']['migrated']++;
        }

        return $summary;
    }

    public function validate(int $runId): array
    {
        $legacy = $this->legacy();
        $legacy->assertSafe();
        $legacyCount = (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.vacation')->count ?? 0);
        $mappedCount = $this->store->mappedCount($this->key(), 'vacation');

        return [
            'ok' => $legacyCount === $mappedCount,
            'legacy_vacations' => $legacyCount,
            'mapped_vacations' => $mappedCount,
            'difference' => $legacyCount - $mappedCount,
        ];
    }

    private function dryRun(int $runId, LegacyReader $legacy, Connection $targetEmployees): array
    {
        $summary = [
            'mode' => 'dry-run',
            'employees' => ['resolvable' => 0, 'unresolved' => 0],
            'vacation_types' => ['resolved' => 0, 'unresolved' => []],
            'vacation_statuses' => ['resolved' => 0, 'unresolved' => []],
            'vacations_total' => (int) ($legacy->selectOne('SELECT count(*)::bigint AS count FROM public.vacation')->count ?? 0),
        ];
        foreach ($legacy->select('SELECT id, name, surname, middle_name, email, employment_date, dismissal_date FROM public.employee ORDER BY id') as $employee) {
            $this->resolveEmployee($employee, $targetEmployees) === null ? $summary['employees']['unresolved']++ : $summary['employees']['resolvable']++;
        }
        foreach ($legacy->select('SELECT id, value, description FROM public.vacation_type_dict ORDER BY id') as $type) {
            if ($this->mapType($type->value, $type->description)) {
                $summary['vacation_types']['resolved']++;
            } else {
                $summary['vacation_types']['unresolved'][] = ['id' => (int) $type->id, 'value' => $type->value, 'description' => $type->description];
            }
        }
        foreach ($legacy->select('SELECT DISTINCT vacation_status FROM public.vacation ORDER BY vacation_status') as $status) {
            if ($this->mapStatus($status->vacation_status)) {
                $summary['vacation_statuses']['resolved']++;
            } else {
                $summary['vacation_statuses']['unresolved'][] = $status->vacation_status;
            }
        }

        return $summary;
    }

    private function legacy(): LegacyReader
    {
        return new LegacyReader($this->key());
    }

    private function resolveEmployee(object $legacyEmployee, Connection $targetEmployees): ?int
    {
        $override = $this->store->override($this->key(), 'employee', $legacyEmployee->id);
        if ($override !== null) {
            return $targetEmployees->table('employees')->where('id', (int) $override)->exists() ? (int) $override : null;
        }

        if (! empty($legacyEmployee->email)) {
            $matches = $targetEmployees->table('employees')->whereRaw('LOWER(work_email) = ?', [mb_strtolower(trim($legacyEmployee->email))])->pluck('id');
            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
            if ($matches->count() > 1) {
                return null;
            }
        }

        $fullName = trim(implode(' ', array_filter([$legacyEmployee->surname, $legacyEmployee->name, $legacyEmployee->middle_name])));
        if ($fullName === '' || empty($legacyEmployee->employment_date)) {
            return null;
        }
        $query = $targetEmployees->table('employees')
            ->whereRaw('LOWER(full_name) = ?', [mb_strtolower($fullName)])
            ->whereDate('hired_at', $legacyEmployee->employment_date);
        if (! empty($legacyEmployee->dismissal_date)) {
            $query->whereDate('fired_at', $legacyEmployee->dismissal_date);
        }
        $matches = $query->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    private function mapType(?string $value, ?string $description): ?string
    {
        $map = config('migration.vacation_types', []);
        foreach ([$value, $description] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $key = mb_strtolower(trim($candidate));
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        return null;
    }

    private function mapStatus(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = mb_strtolower(trim($value));

        return config('migration.vacation_statuses.'.$key);
    }
}
