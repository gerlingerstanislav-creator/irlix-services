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


Route::get('/finance/commercial-data', function (Request $request, \App\Support\CommercialData $commercial) {
    $secret=(string)env('IRLIX_FINANCE_TIMESHEETS_TOKEN','');
    $provided=(string)$request->header('X-Irlix-Finance-Timesheets-Token','');
    abort_unless(strlen($secret)>=32 && $provided!=='' && hash_equals($secret,$provided),403,'Finance/Timesheets access forbidden');
    $data=$request->validate(['from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d','after_or_equal:from']]);
    return response()->json(['data'=>$commercial->read($data['from'],$data['to'])]);
});

$assertReportingToken = static function (Request $request): void {
    $expected=(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
    $provided=(string)$request->header('X-Irlix-Timesheets-Token','');
    abort_unless(strlen($expected)>=32 && $provided!=='' && hash_equals($expected,$provided),403,'Invalid reporting integration token');
};
Route::post('/reporting-freezes', function (Request $request, \App\Support\CommercialData $commercial) use ($assertReportingToken) {
    $assertReportingToken($request);
    $data=$request->validate(['reporting_period_id'=>['required','integer','min:1'],'client_id'=>['required','integer','min:1'],
        'from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d','after_or_equal:from']]);
    return DB::transaction(function () use ($data,$commercial) {
        \App\Support\ReportingFreeze::lock();
        $existing=DB::table('reporting_freezes')->where('reporting_period_id',$data['reporting_period_id'])->lockForUpdate()->first();
        if ($existing) {
            abort_unless((int)$existing->client_id===(int)$data['client_id'] && $existing->period_start===$data['from'] && $existing->period_end===$data['to'],409,'Reporting freeze identity mismatch');
            return response()->json(['data'=>['freeze_token'=>$existing->freeze_token,'revision'=>$existing->revision]]);
        }
        $snapshot=$commercial->read($data['from'],$data['to'],(int)$data['client_id']);
        $token=bin2hex(random_bytes(32));
        DB::table('reporting_freezes')->insert(['reporting_period_id'=>$data['reporting_period_id'],'client_id'=>$data['client_id'],
            'period_start'=>$data['from'],'period_end'=>$data['to'],'freeze_token'=>$token,'revision'=>$snapshot['revision'],
            'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>['freeze_token'=>$token,'revision'=>$snapshot['revision']]]);
    });
});
Route::delete('/reporting-freezes/{period}', function (Request $request, int $period) use ($assertReportingToken) {
    $assertReportingToken($request);
    $data=$request->validate(['freeze_token'=>['required','string','size:64']]);
    DB::transaction(function () use ($period,$data): void {
        \App\Support\ReportingFreeze::lock();
        $existing=DB::table('reporting_freezes')->where('reporting_period_id',$period)->lockForUpdate()->first();
        if (!$existing) return; // Idempotent retry after a successful release.
        abort_unless(hash_equals($existing->freeze_token,$data['freeze_token']),409,'Reporting freeze token mismatch');
        DB::table('reporting_freezes')->where('reporting_period_id',$period)->delete();
    });
    return response()->json(['data'=>['ok'=>true]]);
});


/*
 * Cycle-free reporting freeze for the Clients-owned canonical connection model.
 * The trusted Clients service supplies its complete membership set. The old
 * reporting-freezes API remains for existing pre-cutover consumers.
 */
Route::post('/reporting-freezes-v2', function (Request $request, \App\Services\CashFlowFactsReader $reader) {
    $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Timesheets-Token', '');
    abort_unless(strlen($expected) >= 32 && $provided !== ''
        && hash_equals($expected, $provided), 403, 'Invalid reporting integration token');
    $data = $request->validate([
        'reporting_period_id' => ['required', 'integer', 'min:1'],
        'client_id' => ['required', 'integer', 'min:1'],
        'from' => ['required', 'date_format:Y-m-d'],
        'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        'client_member_ids' => ['required', 'array', 'min:1', 'max:10000'],
        'client_member_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        'membership_revision' => ['required', 'regex:/^[0-9a-f]{64}$/'],
    ]);
    $ids = array_values(array_unique($data['client_member_ids']));
    return DB::transaction(function () use ($data, $ids, $reader) {
        \App\Support\ReportingFreeze::lock();
        $snapshot = $reader->read($data['from'], $data['to'], $ids);
        $existing = DB::table('reporting_freezes')
            ->where('reporting_period_id', $data['reporting_period_id'])->lockForUpdate()->first();
        if ($existing) {
            abort_unless((int) $existing->client_id === $data['client_id']
                && $existing->period_start === $data['from'] && $existing->period_end === $data['to']
                && hash_equals((string) $existing->revision, $snapshot['revision']), 409,
                'Reporting freeze identity or revision mismatch');
            return response()->json(['data' => [
                'freeze_token' => $existing->freeze_token, 'revision' => $existing->revision,
                'membership_revision' => $data['membership_revision'],
            ]]);
        }
        $token = bin2hex(random_bytes(32));
        DB::table('reporting_freezes')->insert([
            'reporting_period_id' => $data['reporting_period_id'],
            'client_id' => $data['client_id'],
            'period_start' => $data['from'], 'period_end' => $data['to'],
            'freeze_token' => $token, 'revision' => $snapshot['revision'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => [
            'freeze_token' => $token, 'revision' => $snapshot['revision'],
            'membership_revision' => $data['membership_revision'],
        ]]);
    });
});
