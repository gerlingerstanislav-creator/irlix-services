<?php

namespace App\Application;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceAttachment;
use App\Models\AbsenceAuditLog;
use App\Models\AbsenceStatusHistory;
use Illuminate\Support\Collection;

final class AbsenceReadService
{
    /** Exact raw values protect legacy API timestamp/field formats from model serialization. */
    private function rows($query): array
    {
        return $query->get()->map(static fn ($row): array => $row->getRawOriginal())->all();
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
            ->orderBy('starts_on'));
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
        if ($status = trim((string) ($filters['status'] ?? ''))) $query->where('status', $status);
        return $this->rows($query->orderBy('starts_on')->orderBy('id'));
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
        return $this->rows($query->orderByDesc('log.created_at')->orderByDesc('log.id')->limit(500));
    }
}
