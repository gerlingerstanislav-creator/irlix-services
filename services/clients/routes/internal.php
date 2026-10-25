<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;


// Trusted Finance integration only. Token verified in FinanceClientsController.
Route::get('/finance/client-projects', [\App\Http\Controllers\FinanceClientsController::class, 'projects']);
Route::get('/finance/direct-cost-entity', [\App\Http\Controllers\FinanceClientsController::class, 'directCostEntity']);
Route::get('/finance/reporting-periods', [\App\Http\Controllers\FinanceClientsController::class, 'periods']);
Route::get('/finance/commercial-terms', [\App\Http\Controllers\FinanceClientsController::class, 'terms']);
Route::get('/finance/reporting-periods/{period}', [\App\Http\Controllers\FinanceClientsController::class, 'period'])->whereNumber('period');
Route::post('/finance/reporting-periods/{period}/paid', [\App\Http\Controllers\FinanceClientsController::class, 'paid'])->whereNumber('period');

Route::post('/employees/{employee}/purge', function (Request $request, int $employee) {
    $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Invalid internal purge token'], 403);
    }

    $username = (string) $request->input('username', '');
    DB::transaction(function () use ($employee, $username): void {
        abort_if(DB::table('client_members')->where('employee_id',$employee)->exists(),409,'Employee has retained client history and cannot be purged.');
        DB::table('project_members')->where('specialist_id', $employee)->delete();
        DB::table('connection_attempts')->where('specialist_id', $employee)->delete();

        DB::table('clients')->where('sales_employee_id', $employee)->update(['sales_employee_id' => null]);
        DB::table('clients')->where('account_employee_id', $employee)->update(['account_employee_id' => null]);
        DB::table('positions')->where('responsible_rn_employee_id', $employee)->update(['responsible_rn_employee_id' => null]);
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

/**
 * Authoritative complete client-level membership directory for Timesheets v2.
 * Keep the old project-based endpoint during a staged expand/contract rollout.
 */
Route::get('/timesheet-memberships', \App\Http\Controllers\TimesheetMembershipsController::class);
