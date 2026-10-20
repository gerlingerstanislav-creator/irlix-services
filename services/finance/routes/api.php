<?php

use Illuminate\Http\Request;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\AccrualPaymentController;
use App\Http\Controllers\CashTransferController;
use App\Support\ClientsGateway;
use App\Http\Controllers\BudgetController;
use App\Support\RevenueReport;
use App\Support\ProfitabilityReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

function financeActor(Request $r): string {
    return (string)($r->attributes->get('identity')['sub'] ?? 'unknown');
}
function financeGuard(Request $r, string $permission) {
    if (($r->attributes->get('identity')['permissions'][$permission] ?? false) !== true) {
        return response()->json(['message' => 'Forbidden'], 403);
    }
    return null;
}
function financeAudit(string $type, int $id, string $action, Request $r, array $details=[]): void {
    DB::table('finance_audit_log')->insert(['entity_type'=>$type,'entity_id'=>$id,'action'=>$action,'actor'=>financeActor($r),'details'=>json_encode($details,JSON_UNESCAPED_UNICODE),'created_at'=>now()]);
}
function financeValidate(Request $r, array $rules) {
    $v=Validator::make($r->all(),$rules);
    return $v->fails() ? response()->json(['message'=>'Validation failed','errors'=>$v->errors()],422) : null;
}
// The deployment health probe must fail when the Finance schema or DB is
// unavailable. A static 200 here would mark an unmigrated finance service as
// healthy and allow real financial writes against a broken installation.
Route::get('/health',function(){
    try {
        DB::table('finance_audit_log')->limit(1)->exists();
        return response()->json(['status'=>'ok','service'=>'finance']);
    } catch(\Throwable) {
        return response()->json(['status'=>'unavailable','service'=>'finance'],503);
    }
});
Route::get('/me', fn(Request $r)=>response()->json(['data'=>[
    'permissions'=>$r->attributes->get('identity')['permissions']??[],
    'actor'=>financeActor($r),
]]));

// Employee picker exposes only names and department hierarchy, never salary
// conditions. Payroll/benefit accruals must reference real Employees IDs.
Route::get('/employee-options',function(Request $r) {
    if($g=financeGuard($r,'finance.expenses.manage'))return $g;
    $month=(string)$r->query('month',now()->format('Y-m'));
    if(!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/',$month))
        return response()->json(['message'=>'Invalid month'],422);
    $token=(string)env('IRLIX_FINANCE_EMPLOYEES_TOKEN','');
    abort_unless(strlen($token)>=32,503,'Employees integration token is not configured');
    try{
        $response=\Illuminate\Support\Facades\Http::withHeaders([
            'X-Irlix-Finance-Employees-Token'=>$token
        ])->acceptJson()->timeout(8)->get(
            (string)env('EMPLOYEES_FINANCE_URL','http://employees:8000/internal/system/finance/payroll-conditions'),
            ['month'=>$month]
        );
    }catch(\Throwable){
        abort(503,'Employees unavailable');
    }
    abort_unless($response->successful(),503,'Employees directory unavailable');
    $employees=collect((array)$response->json('data.employees',[]))
        ->map(fn($employee)=>[
            'id'=>(int)($employee['id']??0),
            'full_name'=>(string)($employee['full_name']??''),
            'department_id'=>$employee['department_id']??null,
            'department_name'=>(string)($employee['department_name']??'Без подразделения'),
            'employment_status'=>$employee['employment_status']??null,
        ])->filter(fn($e)=>$e['id']>0 && $e['full_name']!=='')->values();
    return response()->json(['data'=>$employees]);
});

Route::get('/accounts',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    return response()->json(['data'=>DB::table('financial_accounts')->orderBy('id')->get()]);
});
Route::post('/accounts',function(Request $r) {
    if($g=financeGuard($r,'finance.accounts.manage')) return $g;
    if($e=financeValidate($r,['name'=>'required|string|max:160','type'=>'required|in:bank,cash','opening_on'=>'required|date','opening_balance'=>'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99']))return $e;
    return DB::transaction(function()use($r){$id=DB::table('financial_accounts')->insertGetId([
        'name'=>$r->input('name'),'type'=>$r->input('type'),'opening_on'=>$r->input('opening_on'),
        'opening_balance'=>$r->input('opening_balance'),'currency'=>'RUB','created_by'=>financeActor($r),'created_at'=>now(),'updated_at'=>now()]);
        financeAudit('account',$id,'created',$r);return response()->json(['data'=>DB::table('financial_accounts')->find($id)],201);
    });
});
Route::get('/client-projects',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    return response()->json(['data'=>ClientsGateway::clientProjects()]);
});
Route::get('/client-periods',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    return response()->json(['data'=>ClientsGateway::reportingPeriods()]);
});
// Aggregates are computed over the complete ledger, not the first 500 rows
// returned to the register UI. Never present a truncated subtotal as company debt.
Route::get('/invoice-stats',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $issued=DB::table('invoices')->where('document_status','issued');
    $total=(float)(clone $issued)->sum('amount');
    $count=(int)(clone $issued)->count();
    $paid=(float)DB::table('payment_allocations as a')
        ->join('invoices as i','i.id','=','a.invoice_id')
        ->join('cash_transactions as ct','ct.id','=','a.cash_transaction_id')
        ->where('i.document_status','issued')
        ->where('ct.status','confirmed')
        ->whereNull('a.reversed_at')->sum('a.amount');
    return response()->json(['data'=>[
        'issued'=>$total,'paid'=>$paid,
        'debt'=>round(max(0,$total-$paid),2),'invoice_count'=>$count,
        'quality'=>'full_ledger_confirmed_allocations',
    ]]);
});
Route::get('/invoices',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $q=DB::table('invoices')->leftJoin('finance_period_settlements as fs',
         'fs.reporting_period_id','=','invoices.reporting_period_id')
      ->select('invoices.*','fs.status as settlement_status')
      ->selectRaw("COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN cash_transactions ct ON ct.id=pa.cash_transaction_id WHERE pa.invoice_id=invoices.id AND ct.status='confirmed' AND pa.reversed_at IS NULL),0) AS paid");
    if($r->filled('client_id'))$q->where('client_id',(int)$r->query('client_id'));
    if($r->filled('reporting_period_id'))$q->where('reporting_period_id',(int)$r->query('reporting_period_id'));
    // Stable descending ID cursor, so old invoices remain reachable after 500.
    $limit=min(500,max(1,(int)$r->query('limit',100)));
    if($r->filled('before_id')){
        $cursor=filter_var($r->query('before_id'),FILTER_VALIDATE_INT);
        abort_unless($cursor!==false && $cursor>0,422,'Invalid invoice cursor');
        $q->where('invoices.id','<',$cursor);
    }
    $items=$q->orderByDesc('invoices.id')->limit($limit+1)->get();
    $hasMore=$items->count()>$limit;
    $page=$items->take($limit)->values();
    return response()->json(['data'=>$page,'pagination'=>[
        'has_more'=>$hasMore,'next_before_id'=>$hasMore?$page->last()->id:null,
    ]]);
});
Route::post('/invoices',function(Request $r){
    if($g=financeGuard($r,'finance.invoices.manage'))return $g;
    if($e=financeValidate($r,['client_id'=>'required|integer|min:1','reporting_period_id'=>'required|integer|min:1','number'=>'nullable|string|max:80','payer'=>'nullable|string|max:220','issued_on'=>'nullable|date','due_on'=>'nullable|date','amount'=>'required|numeric|gt:0|decimal:0,2','vat_amount'=>'required|numeric|min:0|decimal:0,2','comment'=>'nullable|string|max:2000']))return $e;
    if((float)$r->input('vat_amount')>(float)$r->input('amount'))return response()->json(['message'=>'VAT exceeds invoice amount'],422);
    // Clients is the source of truth: fail closed on missing or immutable period.
    ClientsGateway::period((int)$r->input('reporting_period_id'),(int)$r->input('client_id'));
    if((float)$r->input('amount')<=0 || (float)$r->input('amount')>999999999999)abort(422,'Invalid amount');
    return DB::transaction(function()use($r){
        // Creation is also barred while the local ledger is being settled.
        $period=(int)$r->input('reporting_period_id');
        DB::table('finance_period_settlements')->insertOrIgnore([
            'reporting_period_id'=>$period,'status'=>'open','ledger_version'=>1,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        $lock=DB::table('finance_period_settlements')->where('reporting_period_id',$period)->lockForUpdate()->first();
        abort_if($lock->status!=='open',423,'Отчётный период уже закрыт или синхронизируется');
        $id=DB::table('invoices')->insertGetId([
        'client_id'=>$r->input('client_id'),'reporting_period_id'=>$r->input('reporting_period_id'),
        'number'=>$r->input('number'),'payer'=>$r->input('payer'),'issued_on'=>$r->input('issued_on'),
        'due_on'=>$r->input('due_on'),'amount'=>$r->input('amount'),'vat_amount'=>$r->input('vat_amount'),
        'comment'=>$r->input('comment'),'created_by'=>financeActor($r),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('finance_period_settlements')->where('id',$lock->id)->increment('ledger_version');
        financeAudit('invoice',$id,'created',$r);return response()->json(['data'=>DB::table('invoices')->find($id)],201);
    });
});
Route::post('/invoices/{invoice}/issue', [SettlementController::class,'issue'])->whereNumber('invoice');
Route::post('/invoices/{invoice}/cancel', [SettlementController::class,'cancel'])->whereNumber('invoice');
Route::post('/receipts/{receipt}/allocations', [SettlementController::class,'allocate'])->whereNumber('receipt');
Route::get('/allocations',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $query=DB::table('payment_allocations as a')->join('invoices as i','i.id','=','a.invoice_id')
        ->join('cash_transactions as t','t.id','=','a.cash_transaction_id')
        ->select('a.id','a.invoice_id','a.cash_transaction_id','a.amount','a.reversed_at',
          'a.reversal_reason','i.number','i.reporting_period_id','i.document_status','t.status as cash_status')
        ->orderByDesc('a.id')->limit(500);
    if($r->filled('invoice_id'))$query->where('a.invoice_id',(int)$r->query('invoice_id'));
    return response()->json(['data'=>$query->get()]);
});
Route::post('/allocations/{allocation}/reverse',
    [SettlementController::class,'reverseAllocation'])->whereNumber('allocation');
Route::post('/periods/{period}/retry-sync', [SettlementController::class,'retry'])->whereNumber('period');
Route::get('/periods/{period}/settlement', function(Request $r,int $period) {
    if($g=financeGuard($r,'finance.view'))return $g;
    return response()->json(['data'=>DB::table('finance_period_settlements')
        ->where('reporting_period_id',$period)->first()]);
})->whereNumber('period');
Route::get('/transfers',[CashTransferController::class,'index']);
Route::post('/transfers',[CashTransferController::class,'create']);
Route::get('/receipts',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    return response()->json(['data'=>DB::table('cash_transactions')->orderByDesc('id')->limit(500)->get()]);
});
Route::post('/receipts',function(Request $r){
    if($g=financeGuard($r,'finance.cash.confirm'))return $g;
    if($e=financeValidate($r,['request_id'=>'required|uuid','financial_account_id'=>'required|integer|exists:financial_accounts,id','occurred_on'=>'required|date','direction'=>'required|in:in,out','flow_type'=>'nullable|in:operating,investing,financing','amount'=>'required|numeric|gt:0|decimal:0,2','counterparty'=>'nullable|string|max:220','reference'=>'nullable|string|max:150']))return $e;
    // Both caller request_id and actual bank reference are duplicate guards. Always
    // check that a retried request matches the original immutable financial fact.
    $existing=DB::table('cash_transactions')->where('request_id',$r->input('request_id'))->first();
    if($existing){
        $same=(int)$existing->financial_account_id===(int)$r->input('financial_account_id')
            && substr((string)$existing->occurred_on,0,10)===$r->input('occurred_on')
            && $existing->direction===$r->input('direction')
            && (int)round((float)$existing->amount*100)===(int)round((float)$r->input('amount')*100)
            && (string)($existing->reference??'')===(string)($r->input('reference')??'')
            && (string)($existing->counterparty??'')===(string)($r->input('counterparty')??'')
            && (string)($existing->flow_type??'operating')===(string)$r->input('flow_type','operating');
        return $same?response()->json(['data'=>$existing]):response()->json(['message'=>'Idempotency key was reused with different data'],409);
    }
    if($r->filled('reference')&&DB::table('cash_transactions')->where('financial_account_id',$r->input('financial_account_id'))->where('reference',$r->input('reference'))->where('direction',$r->input('direction'))->exists())return response()->json(['message'=>'Duplicate reference'],409);
    try{
        return DB::transaction(function()use($r){
            // Lock the account to serialize validation with potential future
            // deactivation or corrections to its opening balance.
            $account=DB::table('financial_accounts')
                ->where('id',(int)$r->input('financial_account_id'))->lockForUpdate()->first();
            abort_unless($account && $account->active,422,'Денежный счёт недоступен');
            abort_unless((string)$account->currency==='RUB',422,'Операции в другой валюте пока не поддерживаются');
            abort_unless(substr((string)$account->opening_on,0,10)<=substr((string)$r->input('occurred_on'),0,10),
                422,'Дата операции раньше даты начального остатка');
            $id=DB::table('cash_transactions')->insertGetId([
            'request_id'=>$r->input('request_id'),
            'financial_account_id'=>$r->input('financial_account_id'),'occurred_on'=>$r->input('occurred_on'),
            'direction'=>$r->input('direction'),'amount'=>$r->input('amount'),
            'flow_type'=>$r->input('flow_type','operating'),
            'counterparty'=>$r->input('counterparty'),'reference'=>$r->input('reference'),
            'created_by'=>financeActor($r),'created_at'=>now(),'updated_at'=>now()]);
            financeAudit('cash',$id,'created',$r);return response()->json(['data'=>DB::table('cash_transactions')->find($id)],201);
        });
    }catch(\Illuminate\Database\QueryException $e){
        if($e->getCode()==='23505')return response()->json(['message'=>'Duplicate payment reference or idempotency key'],409);
        throw $e;
    }
});
Route::post('/receipts/{receipt}/confirm',function(Request $r,int $receipt){
    if($g=financeGuard($r,'finance.cash.confirm'))return $g;
    return DB::transaction(function()use($r,$receipt){
        $row=DB::table('cash_transactions')->where('id',$receipt)->lockForUpdate()->first();
        if(!$row)return response()->json(['message'=>'Not found'],404);
        if($row->status==='confirmed')return response()->json(['data'=>$row]);
        if($row->status!=='draft')return response()->json(['message'=>'Invalid status'],409);
        // A draft may have been imported or created before the account became
        // invalid. Never promote such a row to a confirmed bank movement.
        $account=DB::table('financial_accounts')->where('id',$row->financial_account_id)->first();
        abort_unless($account && $account->active,422,'Денежный счёт недоступен');
        abort_unless((string)$account->currency==='RUB',422,'Валюта счёта не поддерживается');
        abort_unless(substr((string)$row->occurred_on,0,10)>=substr((string)$account->opening_on,0,10),
            422,'Нельзя подтвердить операцию до даты начального остатка');
        DB::table('cash_transactions')->where('id',$receipt)->update(['status'=>'confirmed','confirmed_by'=>financeActor($r),'confirmed_at'=>now(),'updated_at'=>now()]);
        financeAudit('cash',$receipt,'confirmed',$r);return response()->json(['data'=>DB::table('cash_transactions')->find($receipt)]);
    });
});
Route::get('/expenses',function(Request $r){
    if($g=financeGuard($r,'finance.salary.view'))return $g;
    $q=DB::table('accruals')->select('accruals.*')
        ->selectRaw('(SELECT COALESCE(SUM(l.amount),0) FROM accrual_payment_links l WHERE l.accrual_id=accruals.id) AS linked_payments')
        ->selectRaw('EXISTS(SELECT 1 FROM accruals x WHERE x.reversal_of_accrual_id=accruals.id) AS is_reversed');
    if($r->filled('month'))$q->where('recognition_month',$r->query('month'));
    return response()->json(['data'=>$q->orderByDesc('id')->limit(500)->get()]);
});
// Linking a confirmed outgoing movement to an accrued obligation is NOT a
// second payment. The source cash entry stays the only DДС movement.
Route::get('/expenses/payment-links',[AccrualPaymentController::class,'index']);
Route::post('/expenses/{accrual}/payment-links',[AccrualPaymentController::class,'attach'])
    ->whereNumber('accrual');
Route::post('/expenses/{accrual}/reverse',[AccrualPaymentController::class,'reverseAccrual'])
    ->whereNumber('accrual');
Route::post('/expenses',function(Request $r){
    if($g=financeGuard($r,'finance.expenses.manage'))return $g;
    // Expense editing and individual payroll processing are separate rights.
    // A user able to create contractor expenses must not create/read private
    // employee salary/bonus/benefit records without salary detail permission.
    if($r->filled('employee_id') || in_array((string)$r->input('kind'),['salary','bonus','vacation','sick'],true)){
        if($g=financeGuard($r,'finance.salary.view'))return $g;
    }
    if($e=financeValidate($r,['recognition_month'=>'required|date_format:Y-m','kind'=>'required|in:salary,bonus,contribution,vacation,sick,contractor,other','article'=>'required|string|max:120','cost_center'=>'required|string|max:120','amount'=>'required|numeric|gt:0|decimal:0,2','employee_id'=>'required_if:kind,salary,bonus,vacation,sick|nullable|integer|min:1',
        'counterparty'=>'nullable|string|max:220',
        'client_id'=>'nullable|integer|min:1',
        'project_id'=>'nullable|integer|min:1|prohibited_without:client_id',
        'direct_cost_reason'=>'required_with:client_id|nullable|string|min:10|max:500'
    ]))return $e;
    // Employees is authoritative for employee references; reject phantom payroll
    // IDs before persisting an immutable financial fact.
    if($r->filled('employee_id'))
        \App\Support\EmployeesDirectory::assertExists((int)$r->input('employee_id'));
    // Only an explicitly attributed, documented expense is billed against a client;
    // missing Clients or an invalid project must fail closed.
    if($r->filled('client_id')){
        abort_unless(in_array((string)$r->input('kind'),['contractor','other'],true),422,
            'Прямой расход клиента допускается только для подрядчика и прочих подтверждённых затрат: зарплата уже распределяется по ТШ');
        ClientsGateway::validateDirectExpense(
            (int)$r->input('client_id'),
            $r->filled('project_id')?(int)$r->input('project_id'):null
        );
    }
    return DB::transaction(function()use($r){$id=DB::table('accruals')->insertGetId([
        'recognition_month'=>$r->input('recognition_month'),'kind'=>$r->input('kind'),
        'article'=>$r->input('article'),
        'cost_center'=>$r->filled('client_id')?'client:'.$r->input('client_id'):$r->input('cost_center'),
        'amount'=>$r->input('amount'),'employee_id'=>$r->input('employee_id'),
        'counterparty'=>$r->input('counterparty'),
        'client_id'=>$r->input('client_id'),
        'project_id'=>$r->input('project_id'),
        'direct_cost_reason'=>$r->input('direct_cost_reason'),
        'created_by'=>financeActor($r),
        'created_at'=>now(),'updated_at'=>now()]);
        financeAudit('accrual',$id,'created',$r);return response()->json(['data'=>DB::table('accruals')->find($id)],201);
    });
});
Route::get('/cash-flow',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $q=DB::table('cash_transactions')->where('status','confirmed');
    if($r->filled('month')) {
        if(!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/',(string)$r->query('month')))return response()->json(['message'=>'Invalid month'],422);
        $q->whereDate('occurred_on','>=',$r->query('month').'-01')->whereDate('occurred_on','<',date('Y-m-d',strtotime($r->query('month').'-01 +1 month')));
    }
    $rows=$q->orderByDesc('occurred_on')->limit(500)->get();
    $accounts=DB::table('financial_accounts')->get();
    $asOf=$r->filled('month')
        ? \Carbon\Carbon::parse($r->query('month').'-01')->endOfMonth()->toDateString()
        : now()->toDateString();
    $balances=[];
    foreach($accounts as $account){
        $opening=substr((string)$account->opening_on,0,10);
        if($opening>$asOf){
            $balances[]=['account_id'=>$account->id,'as_of'=>$asOf,'balance'=>null,'quality'=>'not_opened_yet'];
            continue;
        }
        $payments=DB::table('cash_transactions')->where('financial_account_id',$account->id)
            ->where('status','confirmed')
            ->whereDate('occurred_on','>=',$opening)->whereDate('occurred_on','<=',$asOf)
            ->selectRaw("SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as outcome")->first();
        $previous=DB::table('cash_transactions')->where('financial_account_id',$account->id)
            ->where('status','confirmed')->whereDate('occurred_on','<',$opening)->exists();
        $balances[]=[
            'account_id'=>$account->id,'as_of'=>$asOf,
            'balance'=>$previous ? null:round((float)$account->opening_balance
                +(float)($payments->income??0)-(float)($payments->outcome??0),2),
            'income'=>round((float)($payments->income??0),2),
            'outcome'=>round((float)($payments->outcome??0),2),
            'quality'=>$previous?'transactions_before_opening_balance':'manual_account_opening_plus_confirmed_movements',
        ];
    }
    return response()->json(['data'=>['transactions'=>$rows,'accounts'=>$accounts,'balances'=>$balances]]);
});
Route::get('/pnl',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $m=$r->query('month',now()->format('Y-m'));
    $expenses=DB::table('accruals')->where('recognition_month',$m)->selectRaw('cost_center,SUM(amount) AS total')->groupBy('cost_center')->get();
    try {
        $revenue=RevenueReport::month((string)$m);
    } catch(\Throwable) {
        return response()->json(['data'=>['month'=>$m,'expenses'=>$expenses,'revenue'=>null,
            'result'=>null,'completeness'=>'revenue_source_unavailable']]);
    }
    $accrualCount=DB::table('accruals')->where('recognition_month',$m)->count();
    $knownRevenue=$revenue['quality']==='complete' ? $revenue['client_agreed_amount'] : null;
    $result=$knownRevenue!==null && $accrualCount>0
        ? round((float)$knownRevenue-(float)$expenses->sum('total'),2):null;
    return response()->json(['data'=>['month'=>$m,'expenses'=>$expenses,
        'revenue'=>$knownRevenue,'preliminary_revenue'=>$revenue['preliminary_amount'],
        'result'=>$result,'completeness'=>$result===null?'partial':'calculated_from_manual_expenses',
        'revenue_quality'=>$revenue['quality'],'revenue_note'=>$revenue['note']]]);

});
Route::get('/revenue',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $month=(string)$r->query('month',now()->format('Y-m'));
    return response()->json(['data'=>RevenueReport::month($month)]);
});
Route::get('/profitability',function(Request $r){
    if($g=financeGuard($r,'finance.salary.view'))return $g;
    return response()->json(['data'=>ProfitabilityReport::month(
        (string)$r->query('month',now()->format('Y-m'))
    )]);
});
Route::get('/planning/{budget}/variance',[BudgetController::class,'variance'])->whereNumber('budget');
Route::get('/planning',[BudgetController::class,'index']);
Route::post('/planning',[BudgetController::class,'create']);
Route::post('/planning/{budget}/approve',[BudgetController::class,'approve'])->whereNumber('budget');
Route::post('/planning/{budget}/revise',[BudgetController::class,'revise'])->whereNumber('budget');
Route::get('/overview',function(Request $r){
    if($g=financeGuard($r,'finance.view'))return $g;
    $month=(string)$r->query('month',now()->format('Y-m'));
    if(!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/',$month))
        return response()->json(['message'=>'Invalid month'],422);
    // The dashboard must compare the SAME service/recognition month; invoice
    // issuance and money movement are distinct axes, never proxy revenue.
    $issued=DB::table('invoices')->where('document_status','issued')->sum('amount');
    $confirmed=DB::table('cash_transactions')
        ->where('direction','in')->where('status','confirmed')
        ->whereNull('internal_transfer_id')
        ->whereDate('occurred_on','>=',$month.'-01')
        ->whereDate('occurred_on','<',\Carbon\Carbon::parse($month.'-01')->addMonth()->toDateString())
        ->sum('amount');
    $expenses=DB::table('accruals')->where('recognition_month',$month)->sum('amount');
    $accrualCount=DB::table('accruals')->where('recognition_month',$month)->count();
    $revenue=null;$pnl=null;$quality='revenue_source_unavailable';
    try {
        $report=RevenueReport::month($month);
        if($report['quality']==='complete') {
            $revenue=$report['client_agreed_amount'];
            $quality='agreed_revenue';
            // When there are no actual accruals, a positive number is not a
            // proven net profit. Never silently substitute an empty cost pool.
            if($accrualCount>0 && $revenue!==null) {
                $pnl=round((float)$revenue-(float)$expenses,2);
                $quality='partial_manual_accruals';
            }
        } else $quality='commercial_data_incomplete';
    } catch(\Throwable) {
        // Preserve available accounting facts without fabricating revenue.
    }
    return response()->json(['data'=>[
        'month'=>$month,'issued_amount'=>$issued,'cash_in'=>$confirmed,
        'revenue'=>$revenue,'pnl'=>$pnl,'completeness'=>$quality
    ]]);
});
