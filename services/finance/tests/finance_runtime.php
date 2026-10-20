<?php
declare(strict_types=1);

// Finance run-time integration smoke against a disposable PostgreSQL schema.
// Client inter-service calls are mocked; this does NOT constitute a full
// Keycloak/Clients/Timesheets end-to-end acceptance test.
require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SettlementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function check(bool $truth, string $why): void
{
    if (!$truth) throw new RuntimeException('Finance integration failed: '.$why);
}
function inbound(int $id,int $account,string $amount): void
{
    DB::table('cash_transactions')->insert([
        'id'=>$id,'financial_account_id'=>$account,'occurred_on'=>'2026-10-15',
        'direction'=>'in','amount'=>$amount,'status'=>'confirmed',
        'created_by'=>'ci','confirmed_by'=>'ci','confirmed_at'=>now(),
        'created_at'=>now(),'updated_at'=>now(),
    ]);
}
function invoice(int $id,int $period,string $amount,string $status='issued'): void
{
    DB::table('invoices')->insert([
        'id'=>$id,'client_id'=>2,'reporting_period_id'=>$period,
        'number'=>'TEST-'.$id,'issued_on'=>'2026-10-01','amount'=>$amount,
        'document_status'=>$status,'vat_amount'=>0,'created_by'=>'ci',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
}
function requestFor(array $body): Request
{
    $r=Request::create('/api/finance/receipts/1/allocations','POST',$body);
    $r->attributes->set('identity',[
        'sub'=>'ci-accountant','permissions'=>[
            'finance.invoices.manage'=>true,
            'finance.cash.confirm'=>true,
        ],
    ]);
    return $r;
}
$called=[];
Http::fake(function($req) use (&$called) {
    $url=$req->url();
    if (str_contains($url,'/finance/reporting-periods/')) {
        $id=(int)preg_replace('/.*reporting-periods\/(\d+).*/','$1',$url);
        if ($req->method()==='POST') {
            $called[]=['period'=>$id,'payload'=>$req->data()];
            return Http::response(['data'=>['status'=>'Счет оплачен']],200);
        }
        return Http::response(['data'=>[
            'id'=>$id,'client_id'=>2,'status'=>'Акт согласован',
        ]],200);
    }
    return Http::response(['message'=>'Unknown endpoint'],500);
});

DB::table('financial_accounts')->insert([
    'id'=>1,'name'=>'CI bank','type'=>'bank','currency'=>'RUB',
    'opening_on'=>'2026-10-01','opening_balance'=>'100.00',
    'active'=>true,'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
invoice(1,10,'100.00');
invoice(2,10,'50.00');
invoice(3,11,'30.00');
inbound(1,1,'120.00');
inbound(2,1,'30.00');
inbound(3,1,'20.00');

$controller=new SettlementController();
$part=$controller->allocate(requestFor([
    'allocations'=>[['invoice_id'=>1,'amount'=>'70.00'],['invoice_id'=>2,'amount'=>'50.00']],
]),1);
check($part->getStatusCode()===201,'first partial settlement');
check(count($called)===0,'partially paid period must not close Clients');
check((int)DB::table('payment_allocations')->count()===2,'two allocation rows');

$complete=$controller->allocate(requestFor([
    'allocations'=>[['invoice_id'=>1,'amount'=>'30.00']],
]),2);
check($complete->getStatusCode()===201,'full payment');
check(count($called)===1,'Clients paid called once after full payment');
check($called[0]['period']===10,'correct reporting period');
check($called[0]['payload']['invoice_count']===2,'all issued invoices counted');
check($called[0]['payload']['paid_total_kopecks']===15000,'exact amount in kopecks');
check(DB::table('finance_period_settlements')->where('reporting_period_id',10)->value('status')==='synced','settled locally');
check(DB::table('payment_allocations')->count()===3,'no duplicate allocations');
$overpaid=false;
try {
    $controller->allocate(requestFor([
        'allocations'=>[['invoice_id'=>3,'amount'=>'21.00']],
    ]),3);
} catch(HttpException $e) {
    $overpaid=$e->getStatusCode()===422;
}
check($overpaid,'overallocate receipt must be rejected');
check(DB::table('payment_allocations')->count()===3,'rejected operation must not mutate ledger');

$partial=$controller->allocate(requestFor([
    'allocations'=>[['invoice_id'=>3,'amount'=>'20.00']],
]),3);
check($partial->getStatusCode()===201,'other period partial payment');
check(count($called)===1,'partial invoice does not cause extra paid event');

$paidBlocked=false;
try {
    $controller->allocate(requestFor([
        'allocations'=>[['invoice_id'=>1,'amount'=>'0.01']],
    ]),1);
} catch(HttpException $e) {
    $paidBlocked=in_array($e->getStatusCode(),[409,422,423],true);
}
check($paidBlocked,'paid period cannot accept any later allocation');
check(DB::table('payment_allocations')->count()===4,'post-paid mutation not allowed');

$blockedDraft=false;
invoice(4,12,'12.00','draft');
try {
    $controller->issue(requestFor([]),4);
} catch(\Throwable $e) {
    $blockedDraft=true; // A newly added draft is allowed only after Clients act check.
}
check(!$blockedDraft,'invoice draft can be issued with trusted agreed Clients period');
check(DB::table('invoices')->where('id',4)->value('document_status')==='issued','draft became issued');
check(DB::table('finance_audit_log')->where('action','paid_synced_clients')->count()===1,'audit of remote paid');

// The database must reject both duplicate cash operation IDs and reused bank
// references, including concurrent retries that pass a pre-insert lookup.
DB::table('cash_transactions')->insert([
    'financial_account_id'=>1,'occurred_on'=>'2026-10-16','direction'=>'out',
    'amount'=>'2.00','reference'=>'TEST-OUT-1',
    'request_id'=>'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
    'status'=>'draft','created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$duplicateRequest=false;
try {
    DB::table('cash_transactions')->insert([
        'financial_account_id'=>1,'occurred_on'=>'2026-10-16','direction'=>'out',
        'amount'=>'3.00','reference'=>'TEST-OUT-2',
        'request_id'=>'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        'status'=>'draft','created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
    ]);
} catch(\Illuminate\Database\QueryException $e) {
    $duplicateRequest=$e->getCode()==='23505';
}
check($duplicateRequest,'database rejects duplicate cash operation request_id');
$duplicateBankReference=false;
try {
    DB::table('cash_transactions')->insert([
        'financial_account_id'=>1,'occurred_on'=>'2026-10-16','direction'=>'out',
        'amount'=>'3.00','reference'=>'TEST-OUT-1',
        'request_id'=>'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        'status'=>'draft','created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
    ]);
} catch(\Illuminate\Database\QueryException $e) {
    $duplicateBankReference=$e->getCode()==='23505';
}
check($duplicateBankReference,'database rejects duplicate bank reference per account/direction');

// Issued invoice number may not be reused for another immutable document.
invoice(5,13,'4.00','issued');
$duplicateInvoice=false;
try {
    DB::table('invoices')->insert([
        'client_id'=>2,'reporting_period_id'=>13,'number'=>'TEST-5',
        'issued_on'=>'2026-10-02','amount'=>'9.00','vat_amount'=>0,
        'document_status'=>'issued','created_by'=>'ci',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
} catch(\Illuminate\Database\QueryException $e) {
    $duplicateInvoice=$e->getCode()==='23505';
}
check($duplicateInvoice,'issued invoice number must be unique');

// Expense settlement links are separate from the cash movement. They must
// never count the same outgoing payment twice or re-charge the P&L.
$accrualId=DB::table('accruals')->insertGetId([
    'recognition_month'=>'2026-10', 'kind'=>'contractor','article'=>'QA fixture',
    'cost_center'=>'corporate','amount'=>'15.00','currency'=>'RUB',
    'status'=>'confirmed','created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$outId=(int)DB::table('cash_transactions')->where('reference','TEST-OUT-1')->value('id');
DB::table('cash_transactions')->where('id',$outId)->update([
    'status'=>'confirmed','confirmed_by'=>'ci','confirmed_at'=>now(),
]);
$payReq=requestFor(['cash_transaction_id'=>$outId,'amount'=>'1.50']);
$payReq->attributes->set('identity',['sub'=>'ci-accountant','permissions'=>[
    'finance.expenses.manage'=>true,'finance.view'=>true,
]]);
$payments=new \App\Http\Controllers\AccrualPaymentController();
$linked=$payments->attach($payReq,$accrualId);
check($linked->getStatusCode()===201,'accrual payment linked to confirmed cash');
check((int)DB::table('accrual_payment_links')->count()===1,'one expense payment link');
check((int)DB::table('cash_transactions')->where('id',$outId)->count()===1,'payment link does not create duplicate cash transaction');
$duplicateLink=false;
try { $payments->attach($payReq,$accrualId); }
catch (HttpException $e) {$duplicateLink=$e->getStatusCode()===409;}
check($duplicateLink,'duplicate expense-to-payment link rejected');


// Unpaid accruals may be corrected by a new signed expense entry; the
// source remains immutable and both entries reconcile to zero in P&L.
$wrongId=DB::table('accruals')->insertGetId([
    'recognition_month'=>'2026-10','kind'=>'other','article'=>'Correction fixture',
    'cost_center'=>'corporate','amount'=>'7.50','currency'=>'RUB',
    'status'=>'confirmed','created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$reverseRequest=requestFor(['reason'=>'Duplicate recorded expense']);
$reverseRequest->attributes->set('identity',['sub'=>'ci-accountant','permissions'=>[
    'finance.expenses.manage'=>true,'finance.view'=>true,
]]);
$reversed=$payments->reverseAccrual($reverseRequest,$wrongId);
check($reversed->getStatusCode()===201,'expense storno creates a separate entry');
check((int)round(100*DB::table('accruals')->where('article','Correction fixture')->sum('amount'))===0,
    'original and storno balance to zero');
check((int)DB::table('accruals')->where('reversal_of_accrual_id',$wrongId)->count()===1,
    'storno has a unique reference to its original');
$secondReverseBlocked=false;
try {$payments->reverseAccrual($reverseRequest,$wrongId);}
catch (HttpException $e) {$secondReverseBlocked=$e->getStatusCode()===409;}
check($secondReverseBlocked,'double reversal blocked');
$paidReverseBlocked=false;
try {$payments->reverseAccrual($reverseRequest,$accrualId);}
catch (HttpException $e) {$paidReverseBlocked=$e->getStatusCode()===409;}
check($paidReverseBlocked,'paid accrual requires separate refund/reconciliation before reversal');

echo "Finance PostgreSQL runtime contract OK: partial/full, idempotency, bank refs, invoice issue, expense settlement and audit\n";
