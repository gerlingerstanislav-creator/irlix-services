<?php

// Synthetic Finance↔Clients boundary: token, tenant, paid status and idempotency.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\FinanceClientsService;
use App\Http\Controllers\FinanceClientsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

clientSchemaLegacy();
clientExpand();
DB::table('clients')->insert([
    ['id'=>1,'name'=>'A','account_employee_id'=>5],
    ['id'=>2,'name'=>'B','account_employee_id'=>6],
]);
DB::table('projects')->insert([
    ['id'=>10,'client_id'=>1,'name'=>'Project A'],
    ['id'=>20,'client_id'=>2,'name'=>'Project B'],
]);
DB::table('reporting_periods')->insert([
    ['id'=>30,'client_id'=>1,'period_start'=>'2026-03-01','period_end'=>'2026-03-31',
        'status'=>'Акт согласован'],
    ['id'=>40,'client_id'=>2,'period_start'=>'2026-03-01','period_end'=>'2026-03-31',
        'status'=>'Новый'],
    ['id'=>50,'client_id'=>2,'period_start'=>'2026-04-01','period_end'=>'2026-04-30',
        'status'=>'Счет оплачен'],
]);

$service = new FinanceClientsService();
clientCheck(count($service->projectsDirectory()['clients']) === 2, 'Directory incomplete');
clientCheck($service->directCostEntity(1, 10)['project_id'] === 10, 'Own project should resolve');
clientCheck($service->directCostEntity(1, null)['project_id'] === null, 'Project is optional');
clientReject(static fn () => $service->directCostEntity(1, 20), 422, 'Foreign-client project accepted');
clientReject(static fn () => $service->directCostEntity(9, null), 422, 'Unknown client accepted');
clientCheck(count($service->approvedPeriods()) === 2, 'New period leaked to Finance');
clientCheck($service->period(30)['status'] === 'Акт согласован', 'Detailed period changed');
clientReject(static fn () => $service->period(900), 404, 'Missing reporting period accepted');
clientReject(static fn () => $service->paid(40, [
    'invoice_count'=>1,'ledger_version'=>1,'evidence_id'=>'synthetic',
]), 409, 'Unapproved act was paid');
$payment = ['invoice_count'=>2,'ledger_version'=>3,'evidence_id'=>'synthetic-payment'];
clientCheck($service->paid(30, $payment) === [
    'id'=>30,'status'=>'Счет оплачен','already_paid'=>false
], 'First payment response changed');
clientCheck($service->paid(30, $payment)['already_paid'] === true, 'Retry is not idempotent');
clientCheck(DB::table('reporting_period_history')->where('reporting_period_id',30)
    ->where('type','payment')->count() === 1, 'Duplicate payment audit');

$controller = new FinanceClientsController($service);
putenv('IRLIX_FINANCE_CLIENTS_TOKEN='.str_repeat('f', 32));
$unauthorized = Request::create('/internal/system/finance/client-projects');
clientReject(static fn () => $controller->projects($unauthorized), 403, 'Finance token missing');
$unauthorized->headers->set('X-Irlix-Finance-Token','wrong');
clientReject(static fn () => $controller->projects($unauthorized), 403, 'Finance token mismatch');
$authorized = Request::create('/internal/system/finance/direct-cost-entity?client_id=1&project_id=10');
$authorized->headers->set('X-Irlix-Finance-Token',str_repeat('f',32));
$response = $controller->directCostEntity($authorized);
clientCheck($response->getStatusCode() === 200 &&
    $response->getData(true)['data']['project_id'] === 10, 'Trusted expense API contract changed');
putenv('IRLIX_FINANCE_CLIENTS_TOKEN=');

echo "Clients Finance boundary token, tenant and paid workflow: PASS\n";
