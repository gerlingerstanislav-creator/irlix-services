<?php

namespace App\Application;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceStatusHistory;
use App\Models\AbsenceAuditLog;
use App\Domain\Absence\AbsenceDayCalculator;
use App\Domain\Absence\AbsenceStatus;
use App\Domain\Absence\AbsenceStatusProjection;
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
        return Absence::query()
            ->where('employee_id', $employeeId)
            ->where(function ($query) use ($year) {
                $query->whereYear('starts_on', $year)->orWhereYear('ends_on', $year);
            })
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => $item->getRawOriginal())
            ->pipe(fn ($items) => AbsenceStatusProjection::many($items->all()));
    }

    public function getOwn(int $absenceId, int $employeeId): array
    {
        $absence = Absence::query()->where('id', $absenceId)->where('employee_id', $employeeId)->first();
        if (!$absence) throw new DomainException('Отсутствие не найдено');
        return AbsenceStatusProjection::one($absence->getRawOriginal());
    }

    public function get(int $absenceId): array
    {
        $absence = Absence::query()->where('id', $absenceId)->first();
        if (!$absence) throw new DomainException('Отсутствие не найдено');
        return AbsenceStatusProjection::one($absence->getRawOriginal());
    }

    public function history(int $absenceId, int $employeeId): array
    {
        $this->getOwn($absenceId, $employeeId);
        return AbsenceStatusHistory::query()
            ->where('absence_id', $absenceId)
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => $item->getRawOriginal())
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

            $id = Absence::query()->insertGetId([
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

            $after = $this->get($id);
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
            if ($this->hasAssignedChain($absenceId)) AbsenceApproval::query()->where('absence_id', $absenceId)->update(['status'=>'waiting','acted_at'=>null,'acted_by_subject'=>null,'acted_by_employee_id'=>null,'updated_at'=>now()]);
            Absence::query()->where('id', $absenceId)->update([
                'type' => $type->value,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'calendar_days' => $this->days->calendarDays($startsOn, $endsOn),
                'entitlement_days' => $this->days->entitlementDays($type, $startsOn, $endsOn),
                'comment' => array_key_exists('comment', $data) ? $data['comment'] : $current['comment'],
                'updated_at' => now(),
            ]);
            $after = $this->get($absenceId);
            $this->audit($absenceId, 'updated', $actorSubject, $employeeId, $current, $after);
            return $after;
        });
    }

    /**
     * Correct the recorded actor for a manager/personnel-created employee absence.
     * Authenticated subject remains unchanged, only the real employee id is fixed.
     */
    public function recordCreationActor(int $absenceId, int $actorId): void
    {
        AbsenceStatusHistory::query()->where('absence_id', $absenceId)
            ->where('reason', 'created')->update(['actor_employee_id' => $actorId]);
        AbsenceAuditLog::query()->where('absence_id', $absenceId)
            ->where('event', 'created')->update(['actor_employee_id' => $actorId]);
    }

    /**
     * Keep submission and the Clients-derived account-manager stage atomic.
     * A technical failure in Clients is handled before entering this transaction.
     */
    public function submitWithClientApprovers(
        int $absenceId,
        int $employeeId,
        string $subject,
        array $approvalContext,
        AbsenceType $type,
        array $accountManagerIds,
    ): array {
        return DB::transaction(function () use (
            $absenceId, $employeeId, $subject, $approvalContext, $type, $accountManagerIds
        ): array {
            $result = $this->submitOwn($absenceId, $employeeId, $subject, $approvalContext);

            if (in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)) {
                AbsenceApproval::query()->where('absence_id', $absenceId)
                    ->where('required_role', 'account-manager')->delete();
                if ($accountManagerIds) {
                    $now = now();
                    AbsenceApproval::query()->insert(array_map(static fn (int $accountManagerId) => [
                        'absence_id' => $absenceId,
                        'sequence' => 2,
                        'stage' => AbsenceStatus::AccountManagerReview->value,
                        'status' => 'waiting',
                        'required_role' => 'account-manager',
                        'approver_employee_id' => $accountManagerId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $accountManagerIds));
                }
            }
            return $result;
        });
    }

    public function hasAssignedChain(int $absenceId): bool
    {
        return AbsenceApproval::query()->where('absence_id', $absenceId)->where('stage', AbsenceStatus::EmployeeReview->value)->exists();
    }

    public function submitOwn(int $absenceId, int $employeeId, string $actorSubject, array $approvalContext): array
    {
        $current = $this->getOwn($absenceId, $employeeId);
        if ($current['status'] !== AbsenceStatus::Planned->value) throw new DomainException('На согласование можно отправить только запланированное отсутствие');

        $type = AbsenceType::from($current['type']);
        $target = $this->workflow->submitTarget($type, $current['ends_on']);
        if ($this->hasAssignedChain($absenceId)) {
            return DB::transaction(function () use ($absenceId, $employeeId, $actorSubject) {
                $next = AbsenceApproval::query()->where('absence_id', $absenceId)->where('status', 'waiting')->orderBy('sequence')->first();
                $status = $next ? AbsenceStatus::EmployeeReview->value : AbsenceStatus::Confirmed->value;
                $lifecycle = $next ? 'in_review' : AbsenceStatus::Confirmed->value;
                if ($next) AbsenceApproval::query()->where('absence_id', $absenceId)->where('sequence', $next->sequence)->where('status', 'waiting')->update(['status'=>'pending','updated_at'=>now()]);
                Absence::query()->where('id', $absenceId)->update(['status'=>$lifecycle,'submitted_at'=>now(),'confirmed_at'=>$next ? null : now(),'updated_at'=>now()]);
                $this->recordStatus($absenceId, AbsenceStatus::Planned->value, $status, $actorSubject, $employeeId, 'submitted');
                $this->audit($absenceId, 'submitted', $actorSubject, $employeeId, null, $this->get($absenceId));
                return $this->get($absenceId);
            });
        }
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
            Absence::query()->where('id', $absenceId)->update([
                'status' => 'in_review',
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

            AbsenceApproval::query()->where('absence_id', $absenceId)->delete();
            $now = now();

            if (in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)) {
                AbsenceApproval::query()->insert([
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
                AbsenceApproval::query()->insert([
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

            $after = $this->get($absenceId);
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

        $query = AbsenceApproval::query()->from('absence_approvals as a')
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

        return $query->orderBy('a.created_at')->get()->map(function ($row) {
            $data = $row->getRawOriginal();
            if ($data['absence_status'] === 'in_review') $data['absence_status'] = $data['stage'];
            return $data;
        })->all();
    }

    public function approvalsFor(int $absenceId): array
    {
        return AbsenceApproval::query()->where('absence_id', $absenceId)->orderBy('sequence')->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    }

    public function approve(int $approvalId, int $actorEmployeeId, string $actorSubject, callable $authorize): array
    {
        $approval = AbsenceApproval::query()->where('id', $approvalId)->first();
        if (!$approval || $approval->status !== 'pending') throw new DomainException('Задача согласования не найдена или уже обработана');
        $absence = $this->get((int) $approval->absence_id);
        if (!$authorize($approval->getRawOriginal(), $absence)) throw new DomainException('Недостаточно прав для согласования');

        return DB::transaction(function () use ($approvalId, $actorEmployeeId, $actorSubject, $approval, $absence) {
            AbsenceApproval::query()->where('id', $approvalId)->update([
                'status' => 'approved',
                'acted_by_subject' => $actorSubject,
                'acted_by_employee_id' => $actorEmployeeId,
                'acted_at' => now(),
                'updated_at' => now(),
            ]);

            $remainingCurrentStage = AbsenceApproval::query()
                ->where('absence_id', $approval->absence_id)
                ->where('sequence', $approval->sequence)
                ->where('status', '!=', 'approved')
                ->exists();

            if ($remainingCurrentStage) return $this->get((int) $approval->absence_id);

            $next = AbsenceApproval::query()
                ->where('absence_id', $approval->absence_id)
                ->where('sequence', '>', $approval->sequence)
                ->where('status', 'waiting')
                ->orderBy('sequence')
                ->first();

            $before = $this->get((int) $approval->absence_id);
            if (!$next) {
                Absence::query()->where('id', $approval->absence_id)->update([
                    'status' => AbsenceStatus::Confirmed->value,
                    'confirmed_at' => now(),
                    'updated_at' => now(),
                ]);
                $to = AbsenceStatus::Confirmed->value;
            } else {
                AbsenceApproval::query()
                    ->where('absence_id', $approval->absence_id)
                    ->where('sequence', $next->sequence)
                    ->where('status', 'waiting')
                    ->update(['status' => 'pending', 'updated_at' => now()]);
                Absence::query()->where('id', $approval->absence_id)->update([
                    'status' => 'in_review',
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

    public function returnToPlanned(int $absenceId, int $actorEmployeeId, string $actorSubject, string $reason = 'returned_to_planned', ?string $comment = null): array
    {
        $current = $this->get($absenceId);
        $status = AbsenceStatus::from($current['status']);
        if (!$this->workflow->canReturnToPlanned($status)) throw new DomainException('Это отсутствие нельзя вернуть в «Запланировано»');
        if ($this->workflow->isImmutable($status)) throw new DomainException('Подтверждённое отсутствие нельзя изменить');

        return DB::transaction(function () use ($absenceId, $actorEmployeeId, $actorSubject, $current, $reason, $comment) {
            if ($this->hasAssignedChain($absenceId)) {
                AbsenceApproval::query()->where('absence_id', $absenceId)->update(['status'=>'waiting','acted_at'=>null,'acted_by_subject'=>null,'acted_by_employee_id'=>null,'updated_at'=>now()]);
            } else AbsenceApproval::query()->where('absence_id', $absenceId)->delete();
            Absence::query()->where('id', $absenceId)->update([
                'status' => AbsenceStatus::Planned->value,
                'submitted_at' => null,
                'updated_at' => now(),
            ]);
            $after = $this->get($absenceId);
            $this->recordStatus($absenceId, $current['status'], AbsenceStatus::Planned->value, $actorSubject, $actorEmployeeId, $reason);
            $auditAfter = $comment === null ? $after : array_merge($after, ['rejection_comment' => $comment]);
            $this->audit($absenceId, $reason, $actorSubject, $actorEmployeeId, $current, $auditAfter);
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
        return Absence::query()
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

        if (!in_array($type, [AbsenceType::SickLeave, AbsenceType::MaternityLeave], true) && $end === null) {
            throw new DomainException('Дата окончания обязательна для этого типа отсутствия');
        }
        if ($end && $end->lt($start)) throw new DomainException('Дата окончания не может быть раньше даты начала');

    }

    private function assertNoOverlap(int $employeeId, string $startsOn, ?string $endsOn, ?int $ignoreId = null): void
    {
        $effectiveEnd = $endsOn ?? '9999-12-31';
        $query = Absence::query()
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', [AbsenceStatus::Cancelled->value, AbsenceStatus::Rejected->value])
            ->whereDate('starts_on', '<=', $effectiveEnd)
            ->where(function ($q) use ($startsOn) {
                $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn);
            });
        if ($ignoreId !== null) $query->where('id', '!=', $ignoreId);
        $conflict = $query->orderBy('starts_on')->first(['id', 'starts_on', 'ends_on']);
        if ($conflict) {
            $until = $conflict->ends_on ? substr((string) $conflict->ends_on, 0, 10) : 'дата окончания не указана';
            throw new DomainException(sprintf(
                'Период пересекается с отсутствием №%d (%s — %s). Проверьте существующую запись.',
                $conflict->id,
                substr((string) $conflict->starts_on, 0, 10),
                $until,
            ));
        }
    }

    private function recordStatus(int $absenceId, ?string $from, string $to, string $actorSubject, ?int $actorEmployeeId, ?string $reason): void
    {
        AbsenceStatusHistory::query()->insert([
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
        AbsenceAuditLog::query()->insert([
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
