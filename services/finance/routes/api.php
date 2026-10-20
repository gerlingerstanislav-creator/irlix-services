<?php

use Illuminate\Http\Request;
use App\Http\Controllers\SettlementController;
use App\Support\ClientsGateway;
use App\Http\Controllers\BudgetController;
use App\Support\RevenueReport;
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
Route::get('/health', fn()=>response()->json(['status'=>'ok','service'=>'finance']));
Route::get('/me', fn(Request $r)=>response()->json(['data'=>[
    'permissions'=>$r->attributes->get('identity')['permissions']??[],
    'actor'=>financeActor($r),
]]));
Route::get('/accounts', fn()=>response()->json(['data'=>DB::table('financial_accounts')->orderBy('id')->get()]));
Route::post('/accounts',function(Request $r) {
    if($g=financeGuard($r,'finance.accounts.manage')) return $g;
    if($e=financeValidate($r,['name'=>'required|string|max:160','type'=>'required|in:bank,cash','opening_on'=>'required|date','opening_balance'=>'required|numeric|between:-999999999999.99,999999999999.99']))return $e;
    return DB::transaction(function()use($r){$id=DB::table('financial_accounts')->insertGetId([
        'name'=>$r->input('name'),'type'=>$r->input('type'),'opening_on'=>$r->input('opening_on'),
        'opening_balance'=>$r->input('opening_balance'),'currency'=>'RUB','created_by'=>financeActor($r),'created_at'=>now(),'updated_at'=>now()]);
        financeAudit('account',$id,'created',$r);return response()->json(['data'=>DB::table('financial_accounts')->find($id)],201);
    });
});
Route::get('/invoices',function(Request $r){
    $q=DB::table('invoices')->select('invoices.*')
      ->selectRaw("COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN cash_transactions ct ON ct.id=pa.cash_transaction_id WHERE pa.invoice_id=invoices.id AND ct.status='confirmed'),0) AS paid");
    if($r->filled('client_id'))$q->where('client_id',(int)$r->query('client_id'));
    if($r->filled('reporting_period_id'))$q->where('reporting_period_id',(int)$r->query('reporting_period_id'));
    return response()->json(['data'=>$q->orderByDesc('invoices.id')->limit(500)->get()]);
});
Route::post('/invoices',function(Request $r){
    if($g=financeGuard($r,'finance.invoices.manage'))return $g;
    if($e=financeValidate($r,['client_id'=>'required|integer|min:1','reporting_period_id'=>'required|integer|min:1','number'=>'nullable|string|max:80','payer'=>'nullable|string|max:220','issued_on'=>'nullable|date','due_on'=>'nullable|date','amount'=>'required|numeric|gt:0','vat_amount'=>'required|numeric|min:0','comment'=>'nullable|string|max:2000']))return $e;
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
Route::post('/periods/{period}/retry-sync', [SettlementController::class,'retry'])->whereNumber('period');
Route::get('/periods/{period}/settlement', function(Request $r,int $period) {
    return response()->json(['data'=>DB::table('finance_period_settlements')
        ->where('reporting_period_id',$period)->first()]);
})->whereNumber('period');
Route::get('/receipts',fn()=>response()->json(['data'=>DB::table('cash_transactions')->orderByDesc('id')->limit(500)->get()]));
Route::post('/receipts',function(Request $r){
    if($g=financeGuard($r,'finance.cash.confirm'))return $g;
    if($e=financeValidate($r,['financial_account_id'=>'required|integer|exists:financial_accounts,id','occurred_on'=>'required|date','direction'=>'required|in:in,out','amount'=>'required|numeric|gt:0','counterparty'=>'nullable|string|max:220','reference'=>'nullable|string|max:150']))return $e;
    if($r->filled('reference')&&DB::table('cash_transactions')->where('financial_account_id',$r->input('financial_account_id'))->where('reference',$r->input('reference'))->where('direction',$r->input('direction'))->exists())return response()->json(['message'=>'Duplicate reference'],409);
    return DB::transaction(function()use($r){$id=DB::table('cash_transactions')->insertGetId([
        'financial_account_id'=>$r->input('financial_account_id'),'occurred_on'=>$r->input('occurred_on'),
        'direction'=>$r->input('direction'),'amount'=>$r->input('amount'),
        'counterparty'=>$r->input('counterparty'),'reference'=>$r->input('reference'),
        'created_by'=>financeActor($r),'created_at'=>now(),'updated_at'=>now()]);
        financeAudit('cash',$id,'created',$r);return response()->json(['data'=>DB::table('cash_transactions')->find($id)],201);
    });
});
Route::post('/receipts/{receipt}/confirm',function(Request $r,int $receipt){
    if($g=financeGuard($r,'finance.cash.confirm'))return $g;
    return DB::transaction(function()use($r,$receipt){
        $row=DB::table('cash_transactions')->where('id',$receipt)->lockForUpdate()->first();
        if(!$row)return response()->json(['message'=>'Not found'],404);
        if($row->status==='confirmed')return response()->json(['data'=>$row]);
        if($row->status!=='draft')return response()->json(['message'=>'Invalid status'],409);
        DB::table('cash_transactions')->where('id',$receipt)->update(['status'=>'confirmed','confirmed_by'=>financeActor($r),'confirmed_at'=>now(),'updated_at'=>now()]);
        financeAudit('cash',$receipt,'confirmed',$r);return response()->json(['data'=>DB::table('cash_transactions')->find($receipt)]);
    });
});
Route::get('/expenses',function(Request $r){
    $q=DB::table('accruals');
    if($r->filled('month'))$q->where('recognition_month',$r->query('month'));
    return response()->json(['data'=>$q->orderByDesc('id')->limit(500)->get()]);
});
Route::post('/expenses',function(Request $r){
    if($g=financeGuard($r,'finance.expenses.manage'))return $g;
    if($e=financeValidate($r,['recognition_month'=>'required|date_format:Y-m','kind'=>'required|in:salary,bonus,contribution,vacation,sick,contractor,other','article'=>'required|string|max:120','cost_center'=>'required|string|max:120','amount'=>'required|numeric|gt:0','employee_id'=>'nullable|integer|min:1','counterparty'=>'nullable|string|max:220']))return $e;
    return DB::transaction(function()use($r){$id=DB::table('accruals')->insertGetId([
        'recognition_month'=>$r->input('recognition_month'),'kind'=>$r->input('kind'),
        'article'=>$r->input('article'),'cost_center'=>$r->input('cost_center'),
        'amount'=>$r->input('amount'),'employee_id'=>$r->input('employee_id'),
        'counterparty'=>$r->input('counterparty'),'created_by'=>financeActor($r),
        'created_at'=>now(),'updated_at'=>now()]);
        financeAudit('accrual',$id,'created',$r);return response()->json(['data'=>DB::table('accruals')->find($id)],201);
    });
});
Route::get('/cash-flow',function(Request $r){
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
    $month=(string)$r->query('month',now()->format('Y-m'));
    return response()->json(['data'=>RevenueReport::month($month)]);
});
Route::get('/profitability',fn()=>response()->json(['data'=>[],'completeness'=>'clients_timesheets_not_integrated']));
Route::get('/planning',[BudgetController::class,'index']);
Route::post('/planning',[BudgetController::class,'create']);
Route::post('/planning/{budget}/approve',[BudgetController::class,'approve'])->whereNumber('budget');
Route::post('/planning/{budget}/revise',[BudgetController::class,'revise'])->whereNumber('budget');
Route::get('/overview',function(){
    $issued=DB::table('invoices')->where('document_status','issued')->sum('amount');
    $confirmed=DB::table('cash_transactions')->where('direction','in')->where('status','confirmed')->sum('amount');
    return response()->json(['data'=>['issued_amount'=>$issued,'cash_in'=>$confirmed,'revenue'=>null,'pnl'=>null,'completeness'=>'commercial_data_not_integrated']]);
});
