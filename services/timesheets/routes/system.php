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

    DB::transaction(function () use ($employee): void {
        DB::table('timesheet_entries')->where('employee_id', $employee)->delete();
        DB::table('employee_confirmations')->where('employee_id', $employee)->delete();
        DB::table('final_approvals')->where('employee_id', $employee)->delete();
        DB::table('timesheet_audit')->where('employee_id', $employee)->delete();

        DB::table('timesheet_entries')->where('account_employee_id', $employee)->update(['account_employee_id' => null]);
        DB::table('final_approvals')->where('approved_by', $employee)->update(['approved_by' => null]);
        DB::table('period_locks')->where('locked_by', $employee)->update(['locked_by' => null]);
        DB::table('timesheet_audit')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
    });

    return response()->json(['purged' => true]);
});
