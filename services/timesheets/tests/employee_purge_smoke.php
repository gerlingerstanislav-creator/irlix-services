<?php

// Synthetic regression for the common case: an accidental employee record with
// no own facts must purge without a functioning Clients membership endpoint.
require __DIR__.'/assignment_reconciliation_smoke.php';

use App\Services\EmployeePurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$unusedEmployee = 999;
DB::table('timesheet_entries')->where('employee_id', $unusedEmployee)->delete();
DB::table('final_approvals')->where('employee_id', $unusedEmployee)->delete();
DB::table('final_approvals')->where('approved_by', $unusedEmployee)->delete();
DB::table('final_approval_exceptions')->where('employee_id', $unusedEmployee)->delete();
DB::table('partner_daily_confirmations')->insert([
    'client_member_id'=>502, 'work_date'=>'2026-10-01',
    'confirmed_by_employee_id'=>$unusedEmployee, 'confirmed_at'=>now(),
    'created_at'=>now(), 'updated_at'=>now(),
]);
DB::table('timesheet_legacy_sources')->insert([
    'legacy_entry_id'=>9901, 'target_entry_id'=>9901,
    'employee_id'=>$unusedEmployee, 'project_id'=>12, 'work_date'=>'2026-10-01',
    'hours'=>1, 'created_at'=>now(), 'updated_at'=>now(),
]);

Http::fake(static function ($request) {
    throw new RuntimeException('Empty-employee purge attempted Clients integration: '.$request->url());
});
$service = app(EmployeePurgeService::class);
$service->purge($unusedEmployee);
$service->purge($unusedEmployee); // safe to retry
if (DB::table('timesheet_legacy_sources')->where('employee_id',$unusedEmployee)->exists()) {
    throw new RuntimeException('Legacy lineage was not erased');
}
if (DB::table('partner_daily_confirmations')
    ->where('confirmed_by_employee_id',$unusedEmployee)->exists()) {
    throw new RuntimeException('Partner confirmations retain deleted actor');
}
if (!DB::table('timesheet_entries')->where('employee_id',1)->exists()) {
    throw new RuntimeException('Unrelated employee facts were erased');
}
echo "Timesheets empty employee purge, idempotence and foreign actor cleanup PASS\n";
