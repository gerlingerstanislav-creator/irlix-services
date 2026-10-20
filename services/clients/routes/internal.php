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
    $periods=DB::table('reporting_periods')
        ->whereDate('period_start','<=',$v['to'])
        ->whereDate('period_end','>=',$v['from'])
        ->select('id','client_id','period_start','period_end','status')->get();
    return response()->json(['data'=>['terms'=>$terms,'periods'=>$periods]]);
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
