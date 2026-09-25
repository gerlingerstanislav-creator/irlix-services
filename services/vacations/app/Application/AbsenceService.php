<?php

namespace App\Application;

use App\Domain\Absence\AbsenceDayCalculator;
use App\Domain\Absence\AbsenceStatus;
use App\Domain\Absence\AbsenceType;
use App\Domain\Absence\AbsenceWorkflow;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AbsenceService
{
    public function __construct(
        private readonly AbsenceDayCalculator $days,
        private readonly AbsenceWorkflow $workflow,
    ) {}

    public function listOwn(int $employeeId, int $year): array
    {
        return DB::table('absences')
            ->where('employee_id', $employeeId)
            ->where(function ($query) use ($year) {
                $query->whereYear('starts_on', $year)->orWhereYear('ends_on', $year);
            })
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->all();
    }

    public function getOwn(int $absenceId, int $employeeId): array
    {
        $absence = DB::table('absences')->where('id', $absenceId)->where('employee_id', $employeeId)->first();
        if (!$absence) throw new DomainException('Отсутствие не найдено');
        return (array) $absence;
    }

    public function get(int $absenceId): array
    {
        $absence = DB::table('absences')->where('id', $absenceId)->first();
        if (!$absence) throw new DomainException('Отсутствие не найдено');
        return (array) $absence;
    }

    public function history(int $absenceId, int $employeeId): array
    {
        $this->getOwn($absenceId, $employeeId);
        return DB::table('absence_status_history')
            ->where('absence_id', $absenceId)
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->all();
    }

    public function createOwn(int $employeeId, array $data, string $actorSubject): array
    {
        $type = AbsenceType::from($data['type']);
        $this->validateTypeDates($type, $data['starts_on'], $data['ends_on'] ?? null);
        $this->assertNoOverlap($employeeId, $data['starts_on'], $data['ends_on'] ?? null);

        return DB::transaction(function () use ($employeeId, $data, $actorSubject, $type) {
            $calendarDays = $this->days->calendarDays($data['starts_on'], $data['ends_on'] ?? null);
            $entitlementDays = $this->days->entitlementDays($type, $data['starts_on'], $data['ends_on'] ?? null);

            $id = DB::table('absences')->insertGetId([
                'employee_id' => $employeeId,
                'type' => $type->value,
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'calendar_days' => $calendarDays,
                'entitlement_days' => $entitlementDays,
                'status' => AbsenceStatus::Planned->value,
                'comment' => $data['comment'] ?? null,
                'created_by_subject' => $actorSubject,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $after = (array) DB::table('absences')->where('id', $id)->first();
            $this->recordStatus($id, null, AbsenceStatus::Planned->value, $actorSubject, $employeeId, 'created');
            $this->audit($id, 'created', $actorSubject, $employeeId, null, $after);
            return $after;
        });
    }

    public function updateOwnPlanned(int $absenceId, int $employeeId, array $data, string $actorSubject): array
    {
        $current = $this->getOwn($absenceId, $employeeId);
        $status = AbsenceStatus::from($current['status']);
        if (!$this->workflow->canEmployeeEdit($status)) throw new DomainException('Изменять отсутствие можно только в статусе «Запланировано»');

        $type = AbsenceType::from($data['type'] ?? $current['type']);
        $startsOn = $data['starts_on'] ?? $current['starts_on'];
        $endsOn = array_key_exists('ends_on', $data) ? $data['ends_on'] : $current['ends_on'];
        $this->validateTypeDates($type, $startsOn, $endsOn);
        $this->assertNoOverlap($employeeId, $startsOn, $endsOn, $absenceId);

        return DB::transaction(function () use ($absenceId, $employeeId, $data, $actorSubject, $current, $type, $startsOn, $endsOn) {
            DB::table('absences')->where('id', $absenceId)->update([
                'type' => $type->value,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'calendar_days' => $this->days->calendarDays($startsOn, $endsOn),
                'entitlement_days' => $this->days->entitlementDays($type, $startsOn, $endsOn),
                'comment' => array_key_exists('comment', $data) ? $data['comment'] : $current['comment'],
                'updated_at' => now(),
            ]);
            $after = (array) DB::table('absences')->where('id', $absenceId)->first();
            $this->audit($absenceId, 'updated', $actorSubject, $employeeId, $current, $after);
            return $after;
        });
    }

    public function submitOwn(int $absenceId, int $employeeId, string $actorSubject, array $approvalContext): array
    {
        $current = $this->getOwn($absenceId, $employeeId);
        if ($current['status'] !== AbsenceStatus::Planned->value) throw new DomainException('На согласование можно отправить только запланированное отсутствие');

        $type = AbsenceType::from($current['type']);
        $target = $this->workflow->submitTarget($type, $current['ends_on']);
        $hrApproverId = (int) ($approvalContext['hr_approver']['employee_id'] ?? 0);
        if ($hrApproverId <= 0) throw new DomainException('Для подразделения не назначен специалист по кадрам');

        $managerApproverId = null;
        if (in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)) {
            $managerApproverId = $this->firstAvailableManager(
                $approvalContext['manager_chain'] ?? [],
                $current['starts_on'],
                $current['ends_on']
            );
        }

        return DB::transaction(function () use ($absenceId, $employeeId, $actorSubject, $current, $target, $type, $hrApproverId, $managerApproverId) {
            DB::table('absences')->where('id', $absenceId)->update([
                'status' => $target->value,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('absence_approvals')->where('absence_id', $absenceId)->delete();
            $now = now();

            if (in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)) {
                DB::table('absence_approvals')->insert([
                    [
                        'absence_id' => $absenceId, 'sequence' => 1, 'stage' => AbsenceStatus::HrReview->value,
                        'status' => 'pending', 'required_role' => 'hr', 'approver_employee_id' => $hrApproverId,
                        'created_at' => $now, 'updated_at' => $now,
                    ],
                    [
                        'absence_id' => $absenceId, 'sequence' => 2, 'stage' => AbsenceStatus::AccountManagerReview->value,
                        'status' => 'waiting', 'required_role' => 'account-manager', 'approver_employee_id' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ],
                    [
                        'absence_id' => $absenceId, 'sequence' => 3, 'stage' => AbsenceStatus::ManagerReview->value,
                        'status' => 'waiting', 'required_role' => 'manager', 'approver_employee_id' => $managerApproverId,
                        'created_at' => $now, 'updated_at' => $now,
                    ],
                    [
                        'absence_id' => $absenceId, 'sequence' => 4, 'stage' => AbsenceStatus::HrFinalReview->value,
                        'status' => 'waiting', 'required_role' => 'hr', 'approver_employee_id' => $hrApproverId,
                        'created_at' => $now, 'updated_at' => $now,
                    ],
                ]);
            } else {
                DB::table('absence_approvals')->insert([
                    'absence_id' => $absenceId,
                    'sequence' => 1,
                    'stage' => AbsenceStatus::HrFinalReview->value,
                    'status' => 'pending',
                    'required_role' => 'hr',
                    'approver_employee_id' => $hrApproverId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $after = (array) DB::table('absences')->where('id', $absenceId)->first();
            $this->recordStatus($absenceId, $current['status'], $target->value, $actorSubject, $employeeId, 'submitted');
            $this->audit($absenceId, 'submitted', $actorSubject, $employeeId, $current, $after);
            return $after;
        });
    }

    public function approvalQueueFor(int $employeeId, array $access): array
    {
        $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
        $isHr = in_array('hr', $roles, true) || in_array('company-admin', $roles, true) || in_array('platform-admin', $roles, true);
        $isManager = in_array('manager', $roles, true) || in_array('company-admin', $roles, true) || in_array('platform-admin', $roles, true);
        $departmentIds = array_map('intval', $access['department_ids'] ?? []);

        $query = DB::table('absence_approvals as a')
            ->join('absences as x', 'x.id', '=', 'a.absence_id')
            ->where('a.status', 'pending')
            ->select(['a.*', 'x.employee_id', 'x.type', 'x.starts_on', 'x.ends_on', 'x.status as absence_status', 'x.comment']);

        $query->where(function ($q) use ($employeeId, $isHr, $isManager, $departmentIds) {
            $q->where('a.approver_employee_id', $employeeId);
            if ($isHr) $q->orWhere('a.required_role', 'hr');
            if ($isManager && $departmentIds) {
                $q->orWhere(function ($managerQuery) use ($departmentIds) {
                    $managerQuery->where('a.required_role', 'manager')
                        ->whereExists(function ($sub) use ($departmentIds) {
                            $sub->selectRaw('1')->from('absences as ax')
                                ->whereColumn('ax.id', 'a.absence_id')
                                ->whereIn('ax.employee_id', function ($employeeSub) use ($departmentIds) {
                                    $employeeSub->select('id')->from('employees_scope_cache')->whereIn('department_id', $departmentIds);
                                });
                        });
                });
            }
        });

        return $query->orderBy('a.created_at')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function approvalsFor(int $absenceId): array
    {
        return DB::table('absence_approvals')->where('absence_id', $absenceId)->orderBy('sequence')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function approve(int $approvalId, int $actorEmployeeId, string $actorSubject, callable $authorize): array
    {
        $approval = DB::table('absence_approvals')->where('id', $approvalId)->first();
        if (!$approval || $approval->status !== 'pending') throw new DomainException('Задача согласования не найдена или уже обработана');
        $absence = $this->get((int) $approval->absence_id);
        if (!$authorize((array) $approval, $absence)) throw new DomainException('Недостаточно прав для согласования');

        return DB::transaction(function () use ($approvalId, $actorEmployeeId, $actorSubject, $approval, $absence) {
            DB::table('absence_approvals')->where('id', $approvalId)->update([
                'status' => 'approved',
                'acted_by_subject' => $actorSubject,
                'acted_at' => now(),
                'updated_at' => now(),
            ]);

            $remainingCurrentStage = DB::table('absence_approvals')
                ->where('absence_id', $approval->absence_id)
                ->where('stage', $approval->stage)
                ->where('status', '!=', 'approved')
                ->exists();

            if ($remainingCurrentStage) return $this->get((int) $approval->absence_id);

            $next = DB::table('absence_approvals')
                ->where('absence_id', $approval->absence_id)
                ->where('sequence', '>', $approval->sequence)
                ->where('status', 'waiting')
                ->orderBy('sequence')
                ->first();

            $before = $this->get((int) $approval->absence_id);
            if (!$next) {
                DB::table('absences')->where('id', $approval->absence_id)->update([
                    'status' => AbsenceStatus::Confirmed->value,
                    'confirmed_at' => now(),
                    'updated_at' => now(),
                ]);
                $to = AbsenceStatus::Confirmed->value;
            } else {
                DB::table('absence_approvals')
                    ->where('absence_id', $approval->absence_id)
                    ->where('sequence', $next->sequence)
                    ->where('status', 'waiting')
                    ->update(['status' => 'pending', 'updated_at' => now()]);
                DB::table('absences')->where('id', $approval->absence_id)->update([
                    'status' => $next->stage,
                    'updated_at' => now(),
                ]);
                $to = $next->stage;
            }

            $after = $this->get((int) $approval->absence_id);
            $this->recordStatus((int) $approval->absence_id, $absence['status'], $to, $actorSubject, $actorEmployeeId, 'approved');
            $this->audit((int) $approval->absence_id, 'approved', $actorSubject, $actorEmployeeId, $before, $after);
            return $after;
        });
    }

    public function returnToPlanned(int $absenceId, int $actorEmployeeId, string $actorSubject): array
    {
        $current = $this->get($absenceId);
        $status = AbsenceStatus::from($current['status']);
        if (!$this->workflow->canReturnToPlanned($status)) throw new DomainException('Это отсутствие нельзя вернуть в «Запланировано»');
        if ($this->workflow->isImmutable($status)) throw new DomainException('Подтверждённое отсутствие нельзя изменить');

        return DB::transaction(function () use ($absenceId, $actorEmployeeId, $actorSubject, $current) {
            DB::table('absence_approvals')->where('absence_id', $absenceId)->delete();
            DB::table('absences')->where('id', $absenceId)->update([
                'status' => AbsenceStatus::Planned->value,
                'submitted_at' => null,
                'updated_at' => now(),
            ]);
            $after = $this->get($absenceId);
            $this->recordStatus($absenceId, $current['status'], AbsenceStatus::Planned->value, $actorSubject, $actorEmployeeId, 'returned_to_planned');
            $this->audit($absenceId, 'returned_to_planned', $actorSubject, $actorEmployeeId, $current, $after);
            return $after;
        });
    }

    private function firstAvailableManager(array $managerChain, string $startsOn, ?string $endsOn): ?int
    {
        foreach ($managerChain as $manager) {
            $managerId = (int) ($manager['employee_id'] ?? 0);
            if ($managerId <= 0) continue;
            if (!$this->hasConfirmedAbsence($managerId, $startsOn, $endsOn)) return $managerId;
        }
        return null;
    }

    private function hasConfirmedAbsence(int $employeeId, string $startsOn, ?string $endsOn): bool
    {
        $effectiveEnd = $endsOn ?? '9999-12-31';
        return DB::table('absences')
            ->where('employee_id', $employeeId)
            ->where('status', AbsenceStatus::Confirmed->value)
            ->whereDate('starts_on', '<=', $effectiveEnd)
            ->where(function ($query) use ($startsOn) {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn);
            })
            ->exists();
    }

    private function validateTypeDates(AbsenceType $type, string $startsOn, ?string $endsOn): void
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = $endsOn ? CarbonImmutable::parse($endsOn)->startOfDay() : null;

        if ($type !== AbsenceType::MaternityLeave && $end === null) throw new DomainException('Дата окончания обязательна для этого типа отсутствия');
        if ($end && $end->lt($start)) throw new DomainException('Дата окончания не может быть раньше даты начала');

        if ($type === AbsenceType::SickLeave && ($start->isFuture() || ($end && $end->isFuture()))) {
            throw new DomainException('Больничный оформляется за уже наступивший период');
        }
    }

    private function assertNoOverlap(int $employeeId, string $startsOn, ?string $endsOn, ?int $ignoreId = null): void
    {
        $effectiveEnd = $endsOn ?? '9999-12-31';
        $query = DB::table('absences')
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', [AbsenceStatus::Cancelled->value, AbsenceStatus::Rejected->value])
            ->whereDate('starts_on', '<=', $effectiveEnd)
            ->where(function ($q) use ($startsOn) {
                $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn);
            });
        if ($ignoreId !== null) $query->where('id', '!=', $ignoreId);
        if ($query->exists()) throw new DomainException('На выбранные даты уже запланировано отсутствие');
    }

    private function recordStatus(int $absenceId, ?string $from, string $to, string $actorSubject, ?int $actorEmployeeId, ?string $reason): void
    {
        DB::table('absence_status_history')->insert([
            'absence_id' => $absenceId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_subject' => $actorSubject,
            'actor_employee_id' => $actorEmployeeId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function audit(int $absenceId, string $event, string $actorSubject, ?int $actorEmployeeId, ?array $before, ?array $after): void
    {
        DB::table('absence_audit_log')->insert([
            'absence_id' => $absenceId,
            'event' => $event,
            'actor_subject' => $actorSubject,
            'actor_employee_id' => $actorEmployeeId,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }
}
