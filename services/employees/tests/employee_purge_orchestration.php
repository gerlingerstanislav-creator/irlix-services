<?php

// Contract: every employee-bearing service must acknowledge erasure before the
// Employees row or Keycloak identity can be removed. No real identity is used.
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Services\EmployeeDeletionService;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
putenv('IRLIX_INTERNAL_PURGE_TOKEN=synthetic-cross-service-purge-secret');
$_ENV['IRLIX_INTERNAL_PURGE_TOKEN'] = 'synthetic-cross-service-purge-secret';

$expected = [
    'timesheets', 'clients', 'vacations', 'specialists', 'recruitment',
    'finance', 'equipment', 'platform-core', 'storage-api',
];
$person = new Employee();
$person->id = 777;
$person->login = 'synthetic.erasure';
$person->full_name = 'Synthetic Test Employee';
$person->keycloak_user_id = null; // no Keycloak/network or DB mutation

$check = static function ($yes, $message): void {
    if (!$yes) throw new RuntimeException($message);
};
$factory = new Factory();
Http::swap($factory);
$factory->preventStrayRequests();
$factory->fake(static function ($request) {
    $token = $request->header('X-Irlix-Internal-Purge-Token');
    if (is_array($token)) $token = $token[0] ?? '';
    if ($token !== 'synthetic-cross-service-purge-secret') {
        throw new RuntimeException('Internal purge request did not contain the service token');
    }
    return (new Factory())->response(['purged' => true, 'client_member_ids' => [501]], 200);
});
$ok = (new EmployeeDeletionService())->purge($person);
$check($ok['status'] === 204, 'Orchestration failed on synthetic service success');
$seen = [];
foreach ($factory->recorded() as [$request]) {
    $url = $request->url();
    $host = parse_url($url, PHP_URL_HOST);
    $seen[] = $host;
    if ($host === 'timesheets') {
        $check($request->data()['client_member_ids'] === [501],
            'Timesheets did not receive canonical membership IDs');
    }
    $path = parse_url($url, PHP_URL_PATH);
    $check(str_ends_with($path, '/internal/system/employees/777/purge')
            || str_ends_with($path, '/internal/system/employees/777/purge/preflight'),
        'A purge request used a wrong internal endpoint');
}
$check($seen === ['clients', ...$expected],
    'Preflight must always precede the first irreversible service mutation');
$check($seen === ['clients', ...$expected],
    'Employee hard delete did not require every service in canonical order: '.implode(', ', $seen));

$factory2 = new Factory();
Http::swap($factory2);
$factory2->preventStrayRequests();
$factory2->fake(static function ($request) {
    return (new Factory())->response(['message' => 'Protected employee data'],
        str_contains($request->url(), 'finance:8000') ? 423 : 200);
});
$blocked = (new EmployeeDeletionService())->purge($person);
$check($blocked['status'] === 423 && $blocked['service'] === 'finance',
    'Protected Finance data did not stop Employee deletion');
$seen2 = array_map(static fn ($r) => parse_url($r[0]->url(), PHP_URL_HOST),
    $factory2->recorded()->all());
$check(!in_array('storage-api', $seen2, true),
    'Orchestrator continued deleting after a downstream protected refusal');

$factory3 = new Factory();
Http::swap($factory3);
$factory3->preventStrayRequests();
$factory3->fake(static function ($request) {
    return (new Factory())->response(['message' => 'Protected reporting period'],
        str_ends_with($request->url(), '/purge/preflight') ? 423 : 200);
});
$locked = (new EmployeeDeletionService())->purge($person);
$check($locked['status'] === 423 && $locked['service'] === 'clients',
    'Read-only Clients preflight did not stop protected deletion');
$requests = $factory3->recorded()->all();
// The HTTP client may retry 423 with the configured retry policy; every
// request must still be read-only preflight, never a mutating purge.
$check(count($requests) >= 1
    && array_reduce($requests, static fn (bool $ok, $entry): bool =>
        $ok && str_ends_with($entry[0]->url(), '/purge/preflight'), true),
    'An irreversible downstream purge was called after a failed Clients preflight');

echo "Employee erasure orchestration, protected preflight and retry isolation: PASS\n";
