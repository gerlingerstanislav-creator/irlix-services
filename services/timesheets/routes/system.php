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
        \App\Support\ReportingFreeze::lock();
        $snapshot=app(\App\Support\ClientMemberDirectory::class)->snapshot();
        $byMember=[];
        foreach($snapshot['members'] as $member)$byMember[$member['client_member_id']]=$member['client_id'];
        $legacy=DB::table('timesheet_entries')->where('employee_id',$employee)->whereNotNull('project_id')->exists()
            ? app(\App\Support\AssignmentsDirectory::class)->snapshot()['project_clients']:[];
        $periods=array_merge($snapshot['locked_periods'],DB::table('reporting_freezes')->get()->map(fn($p)=>(array)$p)->all());
        foreach(DB::table('timesheet_entries')->where('employee_id',$employee)->get() as $fact){
            $clientId=$fact->client_member_id!==null?($byMember[$fact->client_member_id]??null):($legacy[$fact->project_id]??null);
            abort_unless($clientId!==null,503,'Cannot purge facts with unresolved ownership');
            foreach($periods as $period)abort_if((int)$period['client_id']===(int)$clientId
                && $period['period_start']<=$fact->work_date && $period['period_end']>=$fact->work_date,423,'Protected financial facts require a dedicated purge procedure');
        }
        // Purging an author cannot rewrite the attribution of a frozen approval.
        foreach(DB::table('final_approvals')->where(fn($q)=>$q->where('approved_by',$employee)->orWhere('employee_id',$employee))->get() as $approval){
            $clientId=$approval->client_member_id!==null?($byMember[$approval->client_member_id]??null):($legacy[$approval->project_id]??null);
            abort_unless($clientId!==null,503,'Cannot purge approvals with unresolved ownership');
            $end=\Carbon\Carbon::parse($approval->month)->endOfMonth()->toDateString();
            foreach($periods as $period)abort_if((int)$period['client_id']===(int)$clientId
                && $period['period_start']<=$end && $period['period_end']>=$approval->month,423,'Protected approval authors require a dedicated purge procedure');
        }

        DB::table('timesheet_entries')->where('employee_id', $employee)->delete();
        DB::table('employee_confirmations')->where('employee_id', $employee)->delete();
        DB::table('final_approvals')->where('employee_id', $employee)->delete();
        DB::table('timesheet_audit')->where('employee_id', $employee)->delete();

        DB::table('final_approvals')->where('approved_by', $employee)->update(['approved_by' => null]);
        DB::table('period_locks')->where('locked_by', $employee)->update(['locked_by' => null]);
        DB::table('timesheet_audit')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
    });

    return response()->json(['purged' => true]);
});


Route::get('/finance/commercial-data', [\App\Http\Controllers\CommercialDataController::class, 'finance']);
Route::post('/reporting-freezes', [\App\Http\Controllers\ReportingFreezeController::class, 'createLegacy']);
Route::delete('/reporting-freezes/{period}', [\App\Http\Controllers\ReportingFreezeController::class, 'release']);
Route::post('/reporting-freezes-v2', [\App\Http\Controllers\ReportingFreezeController::class, 'createCanonical']);
