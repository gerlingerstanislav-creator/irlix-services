<?php
declare(strict_types=1);

// Runtime route-level tests with a real PostgreSQL schema and fake actor.
// Routes are dispatched directly to test their own permissions and business
// invariants; Keycloak/JWT middleware requires a separate cross-service E2E.
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

function financeCheck(bool $pass,string $why): void {
    if(!$pass) throw new RuntimeException('Finance route test: '.$why);
}
function invokeFinance(string $method,string $uri,array $body=[],array $permissions=['finance.view'=>true,'finance.cash.confirm'=>true]) {
    $request=Request::create('/api/finance'.$uri,$method,$body);
    $request->headers->set('Accept','application/json');
    $request->attributes->set('identity',['sub'=>'finance-route-test','permissions'=>$permissions]);
    $route=Route::getRoutes()->match($request);
    $route->bind($request);
    return $route->run();
}
function rejection(callable $f,int $code,string $message): void {
    try {$response=$f();$status=$response->getStatusCode();}
    catch(HttpException $e){$status=$e->getStatusCode();}
    financeCheck($status===$code,$message.' (got '.$status.')');
}
DB::table('financial_accounts')->insert([
    'id'=>101,'name'=>'CI guard account','type'=>'bank','currency'=>'RUB',
    'opening_on'=>'2026-10-10','opening_balance'=>'20.00','active'=>true,
    'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$payload=[
    'request_id'=>'44444444-4444-4444-8444-444444444444',
    'financial_account_id'=>101,'occurred_on'=>'2026-10-09',
    'direction'=>'in','flow_type'=>'operating','amount'=>'6.50',
    'counterparty'=>'Fixture A','reference'=>'RTE-1'
];
rejection(fn()=>invokeFinance('POST','/receipts',$payload),422,'pre-opening receipt rejected');
financeCheck(DB::table('cash_transactions')->where('request_id',$payload['request_id'])->doesntExist(),
    'pre-opening receipt not created');
$payload['occurred_on']='2026-10-11';
// DB monetary columns have 2 decimal places; never round an accepted third.
rejection(fn()=>invokeFinance('POST','/receipts',array_replace($payload,[
    'amount'=>'0.001','request_id'=>'66666666-6666-4666-8666-666666666666'
])),422,'receipt with fractional kopecks rejected');
rejection(fn()=>invokeFinance('POST','/accounts',[
    'name'=>'Rounded opening amount','type'=>'bank','opening_on'=>'2026-10-10',
    'opening_balance'=>'10.001'
],['finance.accounts.manage'=>true]),422,'opening balance with third decimal rejected');
rejection(fn()=>invokeFinance('POST','/invoices',[
    'client_id'=>2,'reporting_period_id'=>10,'amount'=>'3.001','vat_amount'=>'0.00'
],['finance.invoices.manage'=>true]),422,'invoice amount with third decimal rejected');
rejection(fn()=>invokeFinance('POST','/expenses',[
    'recognition_month'=>'2026-10','kind'=>'other','article'=>'QA fixture',
    'cost_center'=>'corporate','amount'=>'0.001'
],['finance.expenses.manage'=>true]),422,'accrual with third decimal rejected');
$created=invokeFinance('POST','/receipts',$payload);
financeCheck($created->getStatusCode()===201,'valid receipt created');
$receipt=json_decode($created->getContent(),true)['data']['id'];
financeCheck($receipt>0,'receipt returned ID');
$replayed=invokeFinance('POST','/receipts',$payload);
financeCheck($replayed->getStatusCode()===200,'identical retry is idempotent');
rejection(fn()=>invokeFinance('POST','/receipts',array_replace($payload,[
    'flow_type'=>'investing'
])),409,'same idempotency key cannot change classification');
rejection(fn()=>invokeFinance('POST','/receipts',array_replace($payload,[
    'counterparty'=>'Fixture B'
])),409,'same idempotency key cannot change counterparty');
$confirmed=invokeFinance('POST','/receipts/'.$receipt.'/confirm');
financeCheck($confirmed->getStatusCode()===200,'valid receipt confirmed');
financeCheck(DB::table('cash_transactions')->where('id',$receipt)->value('status')==='confirmed',
    'confirmed receipt persists');
DB::table('cash_transactions')->insert([
    'request_id'=>'55555555-5555-4555-8555-555555555555',
    'financial_account_id'=>101,'occurred_on'=>'2026-10-09',
    'direction'=>'out','amount'=>'1.00','status'=>'draft','flow_type'=>'operating',
    'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$stale=DB::table('cash_transactions')->where('request_id','55555555-5555-4555-8555-555555555555')->value('id');
rejection(fn()=>invokeFinance('POST','/receipts/'.$stale.'/confirm'),422,
    'imported draft preceding bank opening cannot be confirmed');
rejection(fn()=>invokeFinance('GET','/invoice-stats',[],[
    'finance.view'=>false
]),403,'invoice aggregate protected by explicit read permission');
$stats=invokeFinance('GET','/invoice-stats');
financeCheck($stats->getStatusCode()===200,'permitted accountant can view invoice totals');

// Defense-in-depth: a direct SQL import is subject to the same monetary
// invariants as the validated HTTP endpoints.
function rejectsFinancialConstraint(callable $write,string $why): void {
    $rejected=false;
    try { $write(); }
    catch (\Illuminate\Database\QueryException $e) {
        $rejected=$e->getCode()==='23514';
    }
    financeCheck($rejected,$why);
}
rejectsFinancialConstraint(fn()=>DB::table('cash_transactions')->insert([
    'request_id'=>'77777777-7777-4777-8777-777777777777',
    'financial_account_id'=>101,'occurred_on'=>'2026-10-12',
    'direction'=>'in','amount'=>'0.00','status'=>'draft',
    'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]),'SQL import cannot create zero-value cash movement');
rejectsFinancialConstraint(fn()=>DB::table('invoices')->insert([
    'client_id'=>2,'reporting_period_id'=>10,'amount'=>'100.00',
    'vat_amount'=>'101.00','document_status'=>'draft',
    'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]),'SQL import cannot set VAT greater than invoice total');
rejectsFinancialConstraint(fn()=>DB::table('cash_transfers')->insert([
    'request_id'=>'88888888-8888-4888-8888-888888888888',
    'source_account_id'=>101,'destination_account_id'=>101,
    'occurred_on'=>'2026-10-12','amount'=>'1.00','created_by'=>'ci',
    'created_at'=>now(),'updated_at'=>now(),
]),'SQL import cannot record a transfer to the same account');

echo "Finance route guards OK: opening date, confirmation, immutable retries, aggregate permission, SQL constraints\n";
