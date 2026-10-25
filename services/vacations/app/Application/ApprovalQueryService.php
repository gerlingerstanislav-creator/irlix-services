<?php

namespace App\Application;

use App\Models\AbsenceApproval;

final class ApprovalQueryService
{
    public function pendingQueue(int $actorId, bool $admin, bool $personnel, bool $manager): array
    {
        $query = AbsenceApproval::query()->from('absence_approvals as a')
            ->join('absences as x', 'x.id', '=', 'a.absence_id')
            ->where('a.status', 'pending')
            ->select(['a.*', 'x.employee_id', 'x.type', 'x.starts_on',
                'x.ends_on', 'x.status as absence_status', 'x.comment']);
        if (!$admin && !$personnel) {
            $query->where(function ($query) use ($actorId, $manager): void {
                $query->where('a.approver_employee_id', $actorId);
                if ($manager) $query->orWhere('a.required_role', 'manager');
            });
        }
        return $query->orderBy('a.created_at')->get()->map(
            static fn (AbsenceApproval $item) => $item->getRawOriginal()
        )->all();
    }

    public function pending(int $approval): ?AbsenceApproval
    {
        return AbsenceApproval::query()->whereKey($approval)->where('status', 'pending')->first();
    }

    public function stagePendingIds(AbsenceApproval $task): array
    {
        return AbsenceApproval::query()
            ->where('absence_id', $task->absence_id)
            ->where('sequence', $task->sequence)
            ->where('status', 'pending')
            ->orderBy('id')->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }
}
