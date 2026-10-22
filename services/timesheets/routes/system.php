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

        DB::table('final_approvals')->where('approved_by', $employee)->update(['approved_by' => null]);
        DB::table('period_locks')->where('locked_by', $employee)->update(['locked_by' => null]);
        DB::table('timesheet_audit')->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
    });

    return response()->json(['purged' => true]);
});


Route::get('/finance/commercial-data',function(Request $request){
    $secret=(string)env('IRLIX_FINANCE_TIMESHEETS_TOKEN','');
    $provided=(string)$request->header('X-Irlix-Finance-Timesheets-Token','');
    abort_unless(strlen($secret)>=32 && $provided!=='' && hash_equals($secret,$provided),403,
        'Finance/TImesheets access forbidden');
    $v=$request->validate([
        'from'=>['required','date'],'to'=>['required','date','after_or_equal:from'],
    ]);
    $from=\Carbon\Carbon::parse($v['from']);
    $to=\Carbon\Carbon::parse($v['to']);
    abort_if($from->diffInDays($to)>31,422,'Only one month at a time');
    $ownership=app(\App\Support\AssignmentsDirectory::class)->snapshot()['project_clients'];
    $entries=DB::table('timesheet_entries')->whereBetween('work_date',[
        $from->toDateString(),$to->toDateString()
    ])->select('employee_id','project_id','work_date','hours')->get()
        ->map(static function ($entry) use ($ownership) {
            abort_unless(isset($ownership[(int) $entry->project_id]), 503,
                'Historical project ownership is unavailable for Finance');
            $entry->client_id=$ownership[(int) $entry->project_id];
            return $entry;
        });
    $months=[];
    for($cursor=$from->copy()->startOfMonth();$cursor->lte($to);$cursor->addMonth()){
        $months[]=$cursor->toDateString();
    }
    $approvals=DB::table('final_approvals')->whereIn('month',$months)
        ->select('employee_id','project_id','month')->get();
    return response()->json(['data'=>[
        'entries'=>$entries,'final_approvals'=>$approvals,
        'from'=>$from->toDateString(),'to'=>$to->toDateString(),
    ]]);
});
