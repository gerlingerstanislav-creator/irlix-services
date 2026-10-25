<?php

namespace App\Application;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceAttachment;
use App\Models\AbsenceAuditLog;
use App\Models\AbsenceStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EmployeePurgeService
{
    /**
     * Historical endpoint uses schema-qualified tables because it is invoked
     * without the normal bearer middleware. Preserve that isolation.
     *
     * File deletion precedes SQL transaction, as in the existing implementation;
     * callers must be able to retry an interrupted purge.
     */
    public function purge(int $employee, string $subject): bool
    {
        $absenceIds = Absence::query()->from('vacations.absences')
            ->where('employee_id', $employee)->pluck('id')->all();

        $attachments = AbsenceAttachment::query()->from('vacations.absence_attachments')
            ->where(function ($query) use ($absenceIds, $employee, $subject) {
                if ($absenceIds) $query->whereIn('absence_id', $absenceIds);
                $query->orWhere('uploaded_by_employee_id', $employee);
                if ($subject !== '') $query->orWhere('uploaded_by_subject', $subject);
            })->get(['id', 'storage_path']);

        foreach ($attachments as $attachment) {
            $path = (string) $attachment->storage_path;
            if ($path !== '' && Storage::disk('local')->exists($path)
                && !Storage::disk('local')->delete($path)) {
                return false;
            }
        }

        DB::transaction(static function () use ($employee, $subject, $absenceIds, $attachments): void {
            $attachmentIds = $attachments->pluck('id')->all();
            if ($attachmentIds) {
                AbsenceAttachment::query()->from('vacations.absence_attachments')
                    ->whereIn('id', $attachmentIds)->delete();
            }
            if ($absenceIds) {
                AbsenceAuditLog::query()->from('vacations.absence_audit_log')
                    ->whereIn('absence_id', $absenceIds)->delete();
                AbsenceStatusHistory::query()->from('vacations.absence_status_history')
                    ->whereIn('absence_id', $absenceIds)->delete();
                AbsenceApproval::query()->from('vacations.absence_approvals')
                    ->whereIn('absence_id', $absenceIds)->delete();
                Absence::query()->from('vacations.absences')->whereIn('id', $absenceIds)->delete();
            }

            AbsenceApproval::query()->from('vacations.absence_approvals')
                ->where('approver_employee_id', $employee)->update(['approver_employee_id' => null]);
            AbsenceStatusHistory::query()->from('vacations.absence_status_history')
                ->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
            AbsenceAuditLog::query()->from('vacations.absence_audit_log')
                ->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);

            if ($subject !== '') {
                Absence::query()->from('vacations.absences')
                    ->where('created_by_subject', $subject)->update(['created_by_subject' => 'deleted-employee']);
                AbsenceApproval::query()->from('vacations.absence_approvals')
                    ->where('approver_subject', $subject)->update(['approver_subject' => null]);
                AbsenceApproval::query()->from('vacations.absence_approvals')
                    ->where('acted_by_subject', $subject)->update(['acted_by_subject' => null]);
                AbsenceStatusHistory::query()->from('vacations.absence_status_history')
                    ->where('actor_subject', $subject)->update(['actor_subject' => 'deleted-employee']);
                AbsenceAuditLog::query()->from('vacations.absence_audit_log')
                    ->where('actor_subject', $subject)->update(['actor_subject' => 'deleted-employee']);
            }
        });
        return true;
    }
}
