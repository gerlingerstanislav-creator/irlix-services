<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', function (Request $request, int $employee) {
    $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Invalid internal purge token'], 403);
    }

    $username = (string) $request->input('username', '');
    DB::transaction(function () use ($employee, $username): void {
        DB::table('project_members')->where('specialist_id', $employee)->delete();
        DB::table('connection_attempts')->where('specialist_id', $employee)->delete();

        DB::table('clients')->where('sales_employee_id', $employee)->update(['sales_employee_id' => null]);
        DB::table('clients')->where('account_employee_id', $employee)->update(['account_employee_id' => null]);
        DB::table('leads')->where('responsible_employee_id', $employee)->update(['responsible_employee_id' => null]);
        DB::table('client_requests')->where('responsible_employee_id', $employee)->update(['responsible_employee_id' => null]);
        DB::table('connection_attempts')->where('responsible_employee_id', $employee)->update(['responsible_employee_id' => null]);
        DB::table('client_contour_permission_audit')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);

        if ($username !== '') {
            DB::table('project_member_feedbacks')->where('created_by_username', $username)->update(['created_by_username' => null]);
        }
    });

    return response()->json(['purged' => true]);
});
