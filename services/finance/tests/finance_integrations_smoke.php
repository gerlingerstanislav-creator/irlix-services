<?php
declare(strict_types=1);

// Executed only inside the deployed Finance container, after its own migration
// and HTTP readiness checks. This is a read-only cross-service contract probe:
// never log private payroll, client records, or integration secrets.
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$month=(new DateTimeImmutable('first day of this month'))->format('Y-m');
$start=$month.'-01';
$end=(new DateTimeImmutable($start))->format('Y-m-t');
$contracts=[
    [
        'name'=>'Clients reporting periods',
        'token_env'=>'IRLIX_FINANCE_CLIENTS_TOKEN',
        'header'=>'X-Irlix-Finance-Token',
        'url'=>rtrim((string)env('CLIENTS_INTERNAL_URL','http://clients:8000/internal/system'),'/').'/finance/reporting-periods',
        'params'=>[],
    ],
    [
        'name'=>'Timesheets commercial data',
        'token_env'=>'IRLIX_FINANCE_TIMESHEETS_TOKEN',
        'header'=>'X-Irlix-Finance-Timesheets-Token',
        'url'=>(string)env('TIMESHEETS_FINANCE_URL','http://timesheets:8000/internal/system/finance/commercial-data'),
        'params'=>['from'=>$start,'to'=>$end],
    ],
    [
        'name'=>'Employees salary conditions',
        'token_env'=>'IRLIX_FINANCE_EMPLOYEES_TOKEN',
        'header'=>'X-Irlix-Finance-Employees-Token',
        'url'=>(string)env('EMPLOYEES_FINANCE_URL','http://employees:8000/internal/system/finance/payroll-conditions'),
        'params'=>['month'=>$month],
    ],
];
require __DIR__.'/support/finance_integration_probe.php';
$errors=financeIntegrationProbe($contracts,
    static fn(string $key): string => (string)env($key,''),
    static fn(array $contract,array $headers) => Http::withHeaders($headers)->acceptJson()
        ->withoutRedirecting()->timeout(8)->get($contract['url'],$contract['params'])
);
foreach($errors as $error)fwrite(STDERR,$error."\n");
if($errors)exit(1);
echo "Finance connected source contracts and token rejection ready (read-only)\n";
