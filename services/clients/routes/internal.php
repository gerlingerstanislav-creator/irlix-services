<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;


// Trusted Finance integration only. Do not reuse employee purge or user bearer tokens.
// Separate secret must be set on Finance and Clients before enabling the integration.
$financeAllowed = static function (Request $request): bool {
    $secret = (string) env('IRLIX_FINANCE_CLIENTS_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Finance-Token', '');
    return strlen($secret) >= 32 && $provided !== '' && hash_equals($secret, $provided);
};

// Validate an explicitly documented direct project expense without granting
// Finance authority to read or modify Clients-owned database tables.
Route::get('/finance/client-projects',function(Request $request)use($financeAllowed){
    abort_unless($financeAllowed($request),403,'Finance integration forbidden');
    $clients=DB::table('clients')->select('id','name')->orderBy('name')->limit(5000)->get();
    $projects=DB::table('projects')->select('id','client_id','name')->orderBy('name')->limit(10000)->get();
    return response()->json(['data'=>['clients'=>$clients,'projects'=>$projects]]);
});

Route::get('/finance/direct-cost-entity',function(Request $request)use($financeAllowed){
    abort_unless($financeAllowed($request),403,'Finance integration forbidden');
    $v=$request->validate([
        'client_id'=>['required','integer','min:1'],
        'project_id'=>['nullable','integer','min:1']
    ]);
    $client=DB::table('clients')->where('id',(int)$v['client_id'])->first(['id','name']);
    abort_unless($client,422,'Client does not exist');
    $project=null;
    if(!empty($v['project_id'])){
        $project=DB::table('projects')->where('id',(int)$v['project_id'])
            ->where('client_id',(int)$client->id)->first(['id','name']);
        abort_unless($project,422,'Project does not belong to client');
    }
    return response()->json(['data'=>[
        'client_id'=>(int)$client->id,'client_name'=>(string)$client->name,
        'project_id'=>$project ? (int)$project->id : null,
        'project_name'=>$project ? (string)$project->name : null,
    ]]);
});

Route::get('/finance/reporting-periods', function(Request $request) use ($financeAllowed){
    abort_unless($financeAllowed($request),403,'Finance integration forbidden');
    $periods=DB::table('reporting_periods as rp')
        ->join('clients as c','c.id','=','rp.client_id')
        ->select('rp.id','rp.client_id','rp.period_start','rp.period_end','rp.status',
            'c.name as client_name')
        ->whereIn('rp.status',['Акт согласован','Счет оплачен'])
        ->orderByDesc('rp.period_end')->limit(500)->get();
    return response()->json(['data'=>$periods]);
});

Route::get('/finance/commercial-terms', function(Request $request) use ($financeAllowed){
    abort_unless($financeAllowed($request),403,'Finance integration forbidden');
    $v=$request->validate([
        'from'=>['required','date'],'to'=>['required','date','after_or_equal:from']
    ]);
    abort_if(\Carbon\Carbon::parse($v['from'])->diffInDays(\Carbon\Carbon::parse($v['to']))>31,
        422,'Only one month at a time');
    $terms=DB::table('member_terms as mt')
        ->join('project_members as pm','pm.id','=','mt.project_member_id')
        ->join('projects as p','p.id','=','pm.project_id')
        ->join('clients as c','c.id','=','p.client_id')
        ->whereDate('mt.valid_from','<=',$v['to'])
        ->where(fn($q)=>$q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to','>=',$v['from']))
        ->select('mt.id as term_id','mt.hourly_rate','mt.valid_from','mt.valid_to',
          'pm.specialist_id as employee_id','pm.id as project_member_id',
          'p.id as project_id','p.client_id','p.name as project_name','c.name as client_name')
        ->orderBy('mt.valid_from')->get();
    // Newly created ClientMember terms may have no legacy ProjectMember.
    // Finance must treat partner revenue as client-level work, not drop it.
    $clientTerms=DB::table('member_terms as mt')
        ->join('client_members as cm','cm.id','=','mt.client_member_id')
        ->join('clients as c','c.id','=','cm.client_id')
        ->whereNull('mt.project_member_id')
        ->whereDate('mt.valid_from','<=',$v['to'])
        ->where(fn($q)=>$q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to','>=',$v['from']))
        ->select('mt.id as term_id','mt.hourly_rate','mt.valid_from','mt.valid_to',
            'cm.employee_id','cm.partner_specialist_id','cm.id as client_member_id',
            'cm.client_id','c.name as client_name')->get()
        ->map(fn($row)=>(array)$row+['project_id'=>null,'project_name'=>null]);
    $terms=$terms->concat($clientTerms)->values();
    $periods=DB::table('reporting_periods')
        ->whereDate('period_start','<=',$v['to'])
        ->whereDate('period_end','>=',$v['from'])
        ->select('id','client_id','period_start','period_end','status','overtime_multiplier')->get();
    $overrides=DB::table('reporting_period_approved_hours')
        ->whereIn('reporting_period_id',$periods->pluck('id'))
        ->select('reporting_period_id','member_term_id','hours','overtime_hours')->get();
    return response()->json(['data'=>['terms'=>$terms,'periods'=>$periods,'approved_hours_overrides'=>$overrides]]);
});

Route::get('/finance/reporting-periods/{period}', function (Request $request, int $period) use ($financeAllowed) {
    abort_unless($financeAllowed($request), 403, 'Finance integration forbidden');
    $row = DB::table('reporting_periods')->find($period);
    abort_unless($row, 404, 'Reporting period not found');
    return response()->json(['data' => [
        'id' => (int)$row->id,
        'client_id' => (int)$row->client_id,
        'status' => $row->status,
        'period_start' => substr((string)$row->period_start, 0, 10),
        'period_end' => substr((string)$row->period_end, 0, 10),
        'updated_at' => (string)$row->updated_at,
    ]]);
})->whereNumber('period');

Route::post('/finance/reporting-periods/{period}/paid', function (Request $request, int $period) use ($financeAllowed) {
    abort_unless($financeAllowed($request), 403, 'Finance integration forbidden');
    $data = $request->validate([
        'ledger_version' => ['required', 'integer', 'min:1'],
        'invoice_count' => ['required', 'integer', 'min:1'],
        'paid_total_kopecks' => ['required', 'integer', 'min:1'],
        'evidence_id' => ['required', 'string', 'max:180'],
    ]);
    // Finance checks individual invoices and paid allocations. Clients accepts
    // the signed assertion and freezes its own reporting-period status atomically.
    // No browser can access this endpoint with only a Keycloak token.
    return DB::transaction(function () use ($request, $period, $data) {
        $row = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
        abort_unless($row, 404, 'Reporting period not found');
        if ($row->status === 'Счет оплачен') {
            return response()->json(['data' => ['id' => $period, 'status' => 'Счет оплачен', 'already_paid' => true]]);
        }
        abort_unless($row->status === 'Акт согласован', 409, 'Перед оплатой нужно согласовать акт');
        DB::table('reporting_periods')->where('id', $period)->update([
            'status' => 'Счет оплачен', 'paid_at' => now()->toDateString(), 'updated_at' => now(),
        ]);
        DB::table('reporting_period_history')->insert([
            'reporting_period_id' => $period, 'type' => 'payment',
            'title' => 'Все счета оплачены через Finance',
            'text' => sprintf('Документы: %d, ledger v%d, операция %s',
                $data['invoice_count'], $data['ledger_version'], $data['evidence_id']),
            'author' => 'Finance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => ['id'=>$period,'status'=>'Счет оплачен','already_paid'=>false]]);
    });
})->whereNumber('period');

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
Route::get('/timesheet-memberships', function (Request $request) {
    $expected=(string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
    $given=(string) $request->header('X-Irlix-Timesheets-Token','');
    abort_unless(strlen($expected)>=32 && $given!=='' && hash_equals($expected,$given),403,'Timesheets integration forbidden');
    $rows=DB::table('member_terms as mt')
        ->join('client_members as cm','cm.id','=','mt.client_member_id')
        ->join('clients as c','c.id','=','cm.client_id')
        ->whereNotNull('mt.valid_from')
        ->select(['cm.id as client_member_id','cm.client_id','cm.employee_id',
            'cm.partner_specialist_id','cm.specialist_name','c.name as client_name',
            'c.account_employee_id','mt.id as member_term_id','mt.valid_from','mt.valid_to'])
        ->orderBy('cm.id')->orderBy('mt.valid_from')->get()->map(fn($r)=>[
            'client_member_id'=>(int)$r->client_member_id,'client_id'=>(int)$r->client_id,
            'employee_id'=>$r->employee_id===null?null:(int)$r->employee_id,
            'partner_specialist_id'=>$r->partner_specialist_id===null?null:(int)$r->partner_specialist_id,
            'specialist_name'=>$r->specialist_name,'client_name'=>$r->client_name,
            'account_employee_id'=>$r->account_employee_id===null?null:(int)$r->account_employee_id,
            'member_term_id'=>(int)$r->member_term_id,
            'valid_from'=>substr((string)$r->valid_from,0,10),
            'valid_to'=>$r->valid_to===null?null:substr((string)$r->valid_to,0,10),
        ])->values();
    $delegations=DB::table('client_account_delegations')
        ->whereNull('revoked_at')->whereDate('starts_at','<=',today())
        ->whereDate('ends_at','>=',today())->get()->groupBy('client_id');
    $items=$rows->map(fn($r)=>$r+[
        'delegated_account_employee_ids'=>($delegations[$r['client_id']]??collect())
            ->pluck('to_employee_id')->map(fn($id)=>(int)$id)->values()->all()
    ])->values();
    $periods=DB::table('reporting_periods')->where('status','<>','Новый')
        ->get(['id','client_id','period_start','period_end','status','timesheets_sent_at'])
        ->map(fn($r)=>['id'=>(int)$r->id,'client_id'=>(int)$r->client_id,
            'period_start'=>substr((string)$r->period_start,0,10),
            'period_end'=>substr((string)$r->period_end,0,10),'status'=>$r->status,
            'timesheets_sent_at'=>$r->timesheets_sent_at])->values();
    return response()->json([
        'complete'=>true,'count'=>$items->count(),'data'=>$items,
        'locked_reporting_periods_complete'=>true,
        'locked_reporting_periods_count'=>$periods->count(),
        'locked_reporting_periods'=>$periods,
    ]);
});
