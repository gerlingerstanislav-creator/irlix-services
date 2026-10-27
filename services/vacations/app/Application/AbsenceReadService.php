<?php

namespace App\Application;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceAttachment;
use App\Models\AbsenceAuditLog;
use App\Models\AbsenceStatusHistory;
use App\Domain\Absence\AbsenceStatusProjection;
use Illuminate\Support\Collection;

final class AbsenceReadService
{
    /** Exact raw values protect legacy API timestamp/field formats from model serialization. */
    private function rows($query, bool $projectStatus = false, string $idField = 'id'): array
    {
        $rows = $query->get()->map(static fn ($row): array => $row->getRawOriginal())->all();
        return $projectStatus ? AbsenceStatusProjection::many($rows, $idField) : $rows;
    }

    public function attachmentCounts(array $absenceIds): array
    {
        if (!$absenceIds) return [];
        return AbsenceAttachment::query()->whereIn('absence_id', $absenceIds)
            ->selectRaw('absence_id, COUNT(*) as aggregate')
            ->groupBy('absence_id')->pluck('aggregate', 'absence_id')
            ->mapWithKeys(static fn ($count, $id) => [(int) $id => (int) $count])->all();
    }

    public function attachmentCount(int $absenceId): int
    {
        return AbsenceAttachment::query()->where('absence_id', $absenceId)->count();
    }

    public function hasAttachment(int $absenceId): bool
    {
        return AbsenceAttachment::query()->where('absence_id', $absenceId)->exists();
    }

    public function approvals(array $absenceIds): Collection
    {
        if (!$absenceIds) return collect();
        return AbsenceApproval::query()->whereIn('absence_id', $absenceIds)
            ->orderBy('sequence')->orderBy('id')->get();
    }

    public function isAssignedApprover(int $absenceId, int $actorId): bool
    {
        return AbsenceApproval::query()->where('absence_id', $absenceId)
            ->where('status', 'pending')->where('approver_employee_id', $actorId)->exists();
    }

    public function statusHistory(int $absenceId): array
    {
        return $this->rows(AbsenceStatusHistory::query()
            ->where('absence_id', $absenceId)->orderBy('id'));
    }

    public function rejectionEvents(int $absenceId): Collection
    {
        return AbsenceAuditLog::query()->where('absence_id', $absenceId)
            ->where('event', 'rejected_to_planned')->orderBy('id')->get();
    }

    public function occupied(int $employeeId, array $excludedStatuses): array
    {
        return $this->rows(Absence::query()->where('employee_id', $employeeId)
            ->whereNotIn('status', $excludedStatuses)
            ->select(['id', 'type', 'status', 'starts_on', 'ends_on'])
            ->orderBy('starts_on'), true);
    }

    public function calendarAbsences(string $from, string $to, array $employeeIds = []): array
    {
        $query = Absence::query()->select([
            'id', 'employee_id', 'type', 'status', 'starts_on', 'ends_on',
        ])->whereDate('starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from));
        if ($employeeIds) $query->whereIn('employee_id', array_values(array_unique($employeeIds)));
        return $this->rows($query->orderBy('employee_id')->orderBy('starts_on'), true);
    }

    /**
     * One read over all currently employed employees (not one per attempt).
     * Only future/current, non-cancelled absences count. Red has priority.
     * Result is strictly employee_id => red|yellow; no diagnosis/type or dates.
     */
    public function absenceAlerts(array $activeIds, string $today, string $oneMonth, string $threeMonths): array
    {
        if (!$activeIds) return [];
        $rows = Absence::query()->whereIn('employee_id', $activeIds)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->whereDate('starts_on', '<=', $threeMonths)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->select(['employee_id', 'starts_on'])->get();
        $signals = [];
        foreach ($rows as $absence) {
            $employeeId = (int) $absence->employee_id;
            $level = substr((string) $absence->starts_on, 0, 10) <= $oneMonth ? 'red' : 'yellow';
            if ($level === 'red' || !isset($signals[$employeeId])) $signals[$employeeId] = $level;
        }
        return $signals;
    }

    public function upcoming(int $employeeId, string $start, string $end): Collection
    {
        return Absence::query()->where('employee_id', $employeeId)
            ->whereDate('starts_on', '>=', $start)
            ->whereDate('starts_on', '<=', $end)
            ->whereNotIn('status', ['confirmed', 'rejected', 'cancelled'])
            ->orderBy('starts_on')->limit(100)->get();
    }

    public function registry(array $employeeIds, array $filters): array
    {
        if (!$employeeIds) return [];
        $query = Absence::query()->whereIn('employee_id', $employeeIds);
        if ($from = trim((string) ($filters['from'] ?? ''))) {
            $query->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from));
        }
        if ($to = trim((string) ($filters['to'] ?? ''))) $query->whereDate('starts_on', '<=', $to);
        if ($type = trim((string) ($filters['type'] ?? ''))) $query->where('type', $type);
        if ($status = trim((string) ($filters['status'] ?? ''))) {
            if (in_array($status, AbsenceStatusProjection::REVIEW_STAGES, true)) {
                $query->where(function ($query) use ($status) {
                    $query->where('status', $status) // Pre-normalization legacy rows.
                        ->orWhere(function ($review) use ($status) {
                            $review->where('status', 'in_review')->whereExists(function ($step) use ($status) {
                                $step->selectRaw('1')->from('absence_approvals as step')
                                    ->whereColumn('step.absence_id', 'absences.id')
                                    ->where('step.status', 'pending')->where('step.stage', $status);
                            });
                        });
                });
            } else $query->where('status', $status);
        }
        return $this->rows($query->orderBy('starts_on')->orderBy('id'), true);
    }

    public function auditHistory(array $employeeIds, array $filters): array
    {
        if (!$employeeIds) return [];
        $query = AbsenceAuditLog::query()->from('absence_audit_log as log')
            ->join('absences as x', 'x.id', '=', 'log.absence_id')
            ->whereIn('x.employee_id', $employeeIds)
            ->select(['log.id', 'log.absence_id', 'log.event', 'log.actor_subject',
                'log.actor_employee_id', 'log.after', 'log.created_at',
                'x.employee_id', 'x.type', 'x.starts_on', 'x.ends_on', 'x.status']);
        if ($from = trim((string) ($filters['from'] ?? ''))) $query->whereDate('log.created_at', '>=', $from);
        if ($to = trim((string) ($filters['to'] ?? ''))) $query->whereDate('log.created_at', '<=', $to);
        if ($employee = (int) ($filters['employee_id'] ?? 0)) $query->where('x.employee_id', $employee);
        if ($event = trim((string) ($filters['event'] ?? ''))) $query->where('log.event', $event);
        return $this->rows($query->orderByDesc('log.created_at')->orderByDesc('log.id')->limit(500), true, 'absence_id');
    }
}
