<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::post('/employees/{employee}/purge', function (Request $request, int $employee) {
    $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Invalid internal purge token'], 403);
    }

    $subject = (string) $request->input('keycloak_subject', '');
    $absenceIds = DB::table('vacations.absences')->where('employee_id', $employee)->pluck('id')->all();

    $attachments = DB::table('vacations.absence_attachments')
        ->where(function ($query) use ($absenceIds, $employee, $subject) {
            if ($absenceIds) $query->whereIn('absence_id', $absenceIds);
            $query->orWhere('uploaded_by_employee_id', $employee);
            if ($subject !== '') $query->orWhere('uploaded_by_subject', $subject);
        })->get(['id', 'storage_path']);

    foreach ($attachments as $attachment) {
        $path = (string) $attachment->storage_path;
        if ($path !== '' && Storage::disk('local')->exists($path) && !Storage::disk('local')->delete($path)) {
            return response()->json(['message' => 'Failed to remove employee attachment'], 500);
        }
    }

    DB::transaction(function () use ($employee, $subject, $absenceIds, $attachments): void {
        $attachmentIds = $attachments->pluck('id')->all();
        if ($attachmentIds) DB::table('vacations.absence_attachments')->whereIn('id', $attachmentIds)->delete();

        if ($absenceIds) {
            DB::table('vacations.absence_audit_log')->whereIn('absence_id', $absenceIds)->delete();
            DB::table('vacations.absence_status_history')->whereIn('absence_id', $absenceIds)->delete();
            DB::table('vacations.absence_approvals')->whereIn('absence_id', $absenceIds)->delete();
            DB::table('vacations.absences')->whereIn('id', $absenceIds)->delete();
        }

        DB::table('vacations.absence_approvals')->where('approver_employee_id', $employee)->update(['approver_employee_id' => null]);
        DB::table('vacations.absence_status_history')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
        DB::table('vacations.absence_audit_log')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);

        if ($subject !== '') {
            DB::table('vacations.absences')->where('created_by_subject', $subject)->update(['created_by_subject' => 'deleted-employee']);
            DB::table('vacations.absence_approvals')->where('approver_subject', $subject)->update(['approver_subject' => null]);
            DB::table('vacations.absence_approvals')->where('acted_by_subject', $subject)->update(['acted_by_subject' => null]);
            DB::table('vacations.absence_status_history')->where('actor_subject', $subject)->update(['actor_subject' => 'deleted-employee']);
            DB::table('vacations.absence_audit_log')->where('actor_subject', $subject)->update(['actor_subject' => 'deleted-employee']);
        }
    });

    return response()->json(['purged' => true]);
});
