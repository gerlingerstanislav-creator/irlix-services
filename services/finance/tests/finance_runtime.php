<?php
declare(strict_types=1);

// Finance run-time integration smoke against a disposable PostgreSQL schema.
// Client inter-service calls are mocked; this does NOT constitute a full
// Keycloak/Clients/Timesheets end-to-end acceptance test.
require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel's global CLI exception renderer can exit successfully after printing
// a fatal test exception. Guarantee GitHub Actions sees every assertion failure.
set_exception_handler(static function(\Throwable $e): void {
    fwrite(STDERR, 'Finance runtime test failed: '.$e->getMessage().PHP_EOL);
    exit(1);
});

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
    $r=Request::create('/api/receipts/1/allocations','POST',$body);
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
// Explicit legacy-style fixture IDs do not advance PostgreSQL identity sequences.
// Reset the cash identity before testing production insertGetId() paths.
DB::statement("SELECT setval(pg_get_serial_sequence('cash_transactions','id'),(SELECT MAX(id) FROM cash_transactions),true)");

$controller=new SettlementController();
$part=$controller->allocate(requestFor([
    'allocations'=>[['invoice_id'=>1,'amount'=>'70.00'],['invoice_id'=>2,'amount'=>'50.00']],
]),1);
check($part->getStatusCode()===201,'first partial settlement');
check(count($called)===0,'partially paid period must not close Clients');
check((int)DB::table('payment_allocations')->count()===2,'two allocation rows');
$receiptRead=Request::create('/api/receipts','GET');
$receiptRead->attributes->set('identity',['sub'=>'ci-finance-reader',
    'permissions'=>['finance.view'=>true]]);
app()->instance('request',$receiptRead);
$receiptRoute=\Illuminate\Support\Facades\Route::getRoutes()->match($receiptRead);
$receiptRoute->bind($receiptRead);
$receiptResponse=$receiptRoute->run();
check($receiptResponse->getStatusCode()===200,'receipt register responds');
$receiptRows=collect(json_decode($receiptResponse->getContent(),true)['data']);
$firstReceipt=$receiptRows->firstWhere('id',1);
check($firstReceipt!==null
    && (int)round((float)$firstReceipt['allocated_amount']*100)===12000,
    'receipt available amount accounts for both invoices, not a single allocation');

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
check(DB::table('finance_audit_log')->where('action','payment_synced')->count()===1,'audit of remote paid');

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
    'cost_center'=>'client:2','client_id'=>2,'project_id'=>10,
    'direct_cost_reason'=>'Explicit test client expense with contract evidence',
    'amount'=>'7.50','currency'=>'RUB',
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
$reversedDirect=DB::table('accruals')->where('reversal_of_accrual_id',$wrongId)->first();
check((int)$reversedDirect->client_id===2 && (int)$reversedDirect->project_id===10,
    'direct client expense reversal preserves external IDs');
check($reversedDirect->cost_center==='client:2',
    'storno must not silently move direct cost to corporate overhead');
$secondReverseBlocked=false;
try {$payments->reverseAccrual($reverseRequest,$wrongId);}
catch (HttpException $e) {$secondReverseBlocked=$e->getStatusCode()===409;}
check($secondReverseBlocked,'double reversal blocked');
$paidReverseBlocked=false;
try {$payments->reverseAccrual($reverseRequest,$accrualId);}
catch (HttpException $e) {$paidReverseBlocked=$e->getStatusCode()===409;}
check($paidReverseBlocked,'paid accrual requires separate refund/reconciliation before reversal');


// A supplier-accounting permission alone must never allow edits to a
// specific employee's salary, or reveal the salary amount via link response.
$salaryId=DB::table('accruals')->insertGetId([
    'recognition_month'=>'2026-10','kind'=>'salary','article'=>'Private payroll fixture',
    'cost_center'=>'direction:qa','employee_id'=>993,
    'amount'=>'3.00','currency'=>'RUB','status'=>'confirmed','created_by'=>'ci',
    'created_at'=>now(),'updated_at'=>now(),
]);
$salaryLinkForbidden=false;
try {$payments->attach($payReq,$salaryId);}
catch(HttpException $e){$salaryLinkForbidden=$e->getStatusCode()===403;}
check($salaryLinkForbidden,'expense manager cannot attach private payroll without salary permission');
$salaryReverseForbidden=false;
try {$payments->reverseAccrual($reverseRequest,$salaryId);}
catch(HttpException $e){$salaryReverseForbidden=$e->getStatusCode()===403;}
check($salaryReverseForbidden,'expense manager cannot reverse private payroll without salary permission');
check(DB::table('accrual_payment_links')->where('accrual_id',$salaryId)->count()===0,
    'unauthorized salary write leaves no links');

// Confirmed turnover must NOT silently treat extra commercial hours as
// ordinary-rate profit without explicit client and employee multipliers.
$commercialDays=21;
Http::fake(function($req)use(&$commercialDays) {
    $url=$req->url();
    if(str_contains($url,'/finance/commercial-terms'))return Http::response(['data'=>[
        'terms'=>[['term_id'=>9,'project_id'=>10,'client_id'=>2,
            'employee_id'=>5,'hourly_rate'=>100,'valid_from'=>'2026-10-01',
            'valid_to'=>null,'client_name'=>'Fixture','project_name'=>'Fixture']],
        'periods'=>[['id'=>8,'client_id'=>2,'period_start'=>'2026-10-01',
            'period_end'=>'2026-10-31','status'=>'Акт согласован']],
        'approved_hours_overrides'=>[],
    ]],200);
    if(str_contains($url,'/finance/commercial-data')){
        $entries=[];
        for($i=1;$i<=$commercialDays;$i++)$entries[]=[
            'employee_id'=>5,'project_id'=>10,'client_id'=>2,
            'work_date'=>sprintf('2026-10-%02d',$i),'hours'=>8,
        ];
        return Http::response(['data'=>[
            'entries'=>$entries,'final_approvals'=>[[
                'employee_id'=>5,'project_id'=>10,'month'=>'2026-10-01'
            ]]
        ]],200);
    }
    if(str_contains($url,'/production-calendar')){
        $days=[];
        for($i=1;$i<=20;$i++)$days[sprintf('2026-10-%02d',$i)]=[
            'is_working'=>true,'is_short'=>false
        ];
        return Http::response(['data'=>['available'=>true,'days'=>$days]],200);
    }
    return Http::response(['message'=>'Unrecognized fixture URL'],500);
});
$overloaded=\App\Support\RevenueReport::month('2026-10');
check($overloaded['quality']==='incomplete','unpriced overtime makes monthly revenue incomplete');
check($overloaded['client_agreed_amount']===null,'no certified revenue when overtime multipliers missing');
check(collect($overloaded['unknown_rows'])->contains(fn($x)=>
    ($x['reason']??'')==='overtime_requires_client_and_employee_terms'),
    'unknown overtime flagged explicitly');
$commercialDays=20;
$ordinary=\App\Support\RevenueReport::month('2026-10');
check($ordinary['quality']==='complete','ordinary time remains calculable');
check((int)$ordinary['client_agreed_amount']===16000,'confirmed base rate for time within norm');

// Verify actual HTTP route responses: even with agreed revenue and existing
// manually entered accruals, profit remains unknown until the entire monthly
// cost ledger has been attested complete. The arithmetic difference is labelled.
foreach(['pnl','overview'] as $reportEndpoint) {
    $r=Request::create('/api/'.$reportEndpoint.'?month=2026-10','GET');
    $r->attributes->set('identity',['sub'=>'ci-finance-reader','permissions'=>['finance.view'=>true]]);
    app()->instance('request',$r);
    $route=\Illuminate\Support\Facades\Route::getRoutes()->match($r);
    $route->bind($r);
    $response=$route->run();
    check($response->getStatusCode()===200,'monthly '.$reportEndpoint.' route responds');
    $report=(array)json_decode($response->getContent(),true)['data'];
    $field=$reportEndpoint==='pnl'?'result':'pnl';
    check(array_key_exists($field,$report)&&$report[$field]===null,
        'unattested monthly '.$reportEndpoint.' is not certified profit');
    check(is_numeric($report['known_recorded_difference']??null),
        'known-data difference is returned separately for '.$reportEndpoint);
    check($report['completeness']==='partial_manual_accruals',
        'incomplete manual accounting is explicit for '.$reportEndpoint);
}



// Exercise the actual HTTP kernel + RS256 middleware, not only controllers.
// Generated keys and certificate exist only inside ephemeral CI memory.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
check($key!==false,'test RSA key generated');
$csr=openssl_csr_new(['commonName'=>'finance-ci.invalid'],$key);
$cert=openssl_csr_sign($csr,null,$key,1);
check($cert!==false,'test identity certificate generated');
openssl_x509_export($cert,$pem);
$x5c=preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/','',$pem);
$enc=fn($data)=>rtrim(strtr(base64_encode($data),'+/','-_'),'=');
$base=rtrim((string)env('KEYCLOAK_URL','http://keycloak:8080/keycloak/auth'),'/');
$iss=rtrim((string)env('KEYCLOAK_ISSUER',$base.'/realms/'.(string)env('KEYCLOAK_REALM','irlix')),'/');
$header=$enc(json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>'ci-finance-key']));
$payload=$enc(json_encode([
    'iss'=>$iss,'azp'=>(string)env('KEYCLOAK_CLIENT_ID','irlix-services-web'),
    'sub'=>'ci-accountant','exp'=>time()+1800,'nbf'=>time()-60,
    'realm_access'=>['roles'=>['platform-admin']],
]));
openssl_sign($header.'.'.$payload,$signature,$key,OPENSSL_ALGO_SHA256);
$jwt=$header.'.'.$payload.'.'.$enc($signature);
$admitted=false;$salaryGranted=false;
Http::fake(function($request) use (&$admitted,&$salaryGranted,$x5c){
    if(str_contains($request->url(),'/protocol/openid-connect/certs'))
        return Http::response(['keys'=>[['kid'=>'ci-finance-key','x5c'=>[$x5c]]]],200);
    if(str_contains($request->url(),'/access/me'))
        return Http::response(['data'=>[
            'services'=>['finance'=>$admitted],
            'roles'=>[],
            'permissions'=>['finance.view'=>true,'finance.salary.view'=>$salaryGranted,'finance.cash.confirm'=>true],
        ]],200);
    return Http::response(['message'=>'Unmocked integration call'],500);
});
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$requestWithoutToken=Request::create('/api/expenses','GET');
$unauth=$kernel->handle($requestWithoutToken);
check($unauth->getStatusCode()===401,'real API rejects missing JWT');
$kernel->terminate($requestWithoutToken,$unauth);
$apiRequest=fn()=>Request::create('/api/expenses','GET',[],[],[],[
    'HTTP_AUTHORIZATION'=>'Bearer '.$jwt,
]);
$deniedRequest=$apiRequest();
$denied=$kernel->handle($deniedRequest);
check($denied->getStatusCode()===403,'Employees denies access even with a platform-admin JWT role');
$kernel->terminate($deniedRequest,$denied);
$admitted=true;
$salaryDeniedRequest=$apiRequest();
$salaryDenied=$kernel->handle($salaryDeniedRequest);
check($salaryDenied->getStatusCode()===200,'Finance reader can view non-payroll expenses');
$limitedRows=(array)json_decode($salaryDenied->getContent(),true)['data'];
check(!collect($limitedRows)->contains(fn($row)=>(int)$row['id']===$salaryId),
    'salary detail is filtered from general Finance register');
$kernel->terminate($salaryDeniedRequest,$salaryDenied);
$salaryGranted=true;
$allowedRequest=$apiRequest();
$allowed=$kernel->handle($allowedRequest);
check($allowed->getStatusCode()===200,'Employees grants Finance access and salary permission');
$allRows=(array)json_decode($allowed->getContent(),true)['data'];
check(collect($allRows)->contains(fn($row)=>(int)$row['id']===$salaryId),
    'authorized payroll reader can inspect individual salary record');
$kernel->terminate($allowedRequest,$allowed);


// Both sides of an own-account transfer must be durable atomically and never
// count as external revenue. Retrying the same request must be idempotent.
DB::table('financial_accounts')->insert([
    'id'=>2,'name'=>'CI reserve account','type'=>'bank','currency'=>'RUB',
    'opening_on'=>'2026-10-01','opening_balance'=>'40.00',
    'active'=>true,'created_by'=>'ci','created_at'=>now(),'updated_at'=>now(),
]);
$transferBody=[
    'request_id'=>'e1ce6c39-32f3-4f6f-9811-dde0aac3af01',
    'source_account_id'=>1,'destination_account_id'=>2,
    'occurred_on'=>'2026-10-19','amount'=>'10.00',
];
$transferRequest=Request::create('/api/transfers','POST',$transferBody,[],[],[
    'HTTP_AUTHORIZATION'=>'Bearer '.$jwt,
]);
$transferResponse=$kernel->handle($transferRequest);
check($transferResponse->getStatusCode()===201,'atomic own-account transfer');
$kernel->terminate($transferRequest,$transferResponse);
$transfer=DB::table('cash_transfers')->where('request_id',$transferBody['request_id'])->first();
check($transfer!==null,'cash transfer row persisted');
$pairs=DB::table('cash_transactions')->where('internal_transfer_id',$transfer->id)->get();
check($pairs->count()===2,'transfer creates exactly two opposite cash movements');
check($pairs->pluck('direction')->sort()->values()->all()===['in','out'],
    'own account transfer balances inflow and outflow');
check((int)round(100*(float)$pairs->where('direction','in')->sum('amount'))
    ===(int)round(100*(float)$pairs->where('direction','out')->sum('amount')),
    'transfer does not create company income');
$retryRequest=Request::create('/api/transfers','POST',$transferBody,[],[],[
    'HTTP_AUTHORIZATION'=>'Bearer '.$jwt,
]);
$retry=$kernel->handle($retryRequest);
check($retry->getStatusCode()===200,'idempotent transfer retry');
$kernel->terminate($retryRequest,$retry);
check(DB::table('cash_transactions')->where('internal_transfer_id',$transfer->id)->count()===2,
    'retry did not duplicate either cash movement');
$conflict=Request::create('/api/transfers','POST',[
    ...$transferBody,'amount'=>'11.00'
],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$jwt]);
$conflictResult=$kernel->handle($conflict);
check($conflictResult->getStatusCode()===409,'idempotency key cannot be reused with another amount');
$kernel->terminate($conflict,$conflictResult);


// Internal account movements are not customer receipts or vendor payouts.
// Never permit a transfer to close Clients.ReportingPeriod or settle an accrual.
invoice(6,14,'10.00');
Http::fake(function($request){
    if(str_contains($request->url(),'/finance/reporting-periods/')){
        return Http::response(['data'=>[
            'client_id'=>2,'status'=>'Акт согласован'
        ]],200);
    }
    return Http::response(['message'=>'Unrecognized fixture'],500);
});
$inboundTransfer=$pairs->firstWhere('direction','in')->id;
$outboundTransfer=$pairs->firstWhere('direction','out')->id;
$clientGuard=false;
try {
    $controller->allocate(requestFor([
        'allocations'=>[['invoice_id'=>6,'amount'=>'10.00']]
    ]),(int)$inboundTransfer);
} catch(HttpException $e) {$clientGuard=$e->getStatusCode()===409;}
check($clientGuard,'own transfer cannot settle a client invoice');
check(DB::table('payment_allocations')->where('invoice_id',6)->count()===0,
    'no fake client payment stored from internal transfer');
$vendorGuard=false;
$tryVendor=requestFor(['cash_transaction_id'=>$outboundTransfer,'amount'=>'1.00']);
$tryVendor->attributes->set('identity',['sub'=>'ci-accountant','permissions'=>[
    'finance.expenses.manage'=>true
]]);
try { $payments->attach($tryVendor,$accrualId); }
catch(HttpException $e) {$vendorGuard=$e->getStatusCode()===409;}
check($vendorGuard,'own transfer cannot settle expense obligations');


$linksController=new \App\Http\Controllers\AccrualPaymentController();
$viewer=\Illuminate\Http\Request::create('/api/expenses/payment-links','GET');
$viewer->attributes->set('identity',['sub'=>'ci-viewer',
    'permissions'=>['finance.view'=>true,'finance.salary.view'=>false]]);
$salaryDenied=false;
try {$linksController->index($viewer);} catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){
    $salaryDenied=$e->getStatusCode()===403;
}
check($salaryDenied,'payment links forbidden without salary detail permission');
$accountant=\Illuminate\Http\Request::create('/api/expenses/payment-links','GET');
$accountant->attributes->set('identity',['sub'=>'ci-accountant',
    'permissions'=>['finance.view'=>true,'finance.salary.view'=>true]]);
check($linksController->index($accountant)->getStatusCode()===200,
    'authorized accountant can read payment links');

echo "Finance PostgreSQL runtime contract OK: partial/full, idempotency, bank refs, invoice issue, expense settlement and audit\n";
