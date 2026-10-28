<?php

require __DIR__.'/../vendor/autoload.php';

use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$oldLocal = getenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
$oldJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED=true');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');

$live = ['employee_id'=>17,
    'roles'=>['platform-admin'],
    'role_claims'=>['org:irlix','special:platform-admin'],
];
Http::preventStrayRequests();
Http::fake(static function ($req) use (&$live) {
    if (str_ends_with((string) $req->url(), '/access/me')) {
        return Http::response(['data'=>$live],200);
    }
    return Http::response([],404);
});

$gate = new ServiceEntitlement();
$assert = static function (int $expected, string $method, string $path, array $data = []) use ($gate): void {
    $request = Request::create($path, $method, $data, [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer synthetic-signed-jwt',
    ]);
    $request->attributes->set('identity', ['realm_roles'=>[
        'employee-id:17','org:irlix','special:platform-admin',
    ]]);
    $response = $gate->handle($request,
        static fn () => response()->json(['data'=>['authorized'=>true]],200));
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("Expected {$expected} for {$method} {$path}, got {$response->getStatusCode()}");
    }
};

$assert(200, 'GET', '/api/me');
$assert(200, 'GET', '/api/absences');
$assert(200, 'POST', '/api/absences');
$assert(200, 'GET', '/api/absences/15/history');
$assert(200, 'PATCH', '/api/absences/15');
$assert(200, 'GET', '/api/absences/occupied', ['employee_id'=>17]);
$assert(403, 'GET', '/api/absences/occupied', ['employee_id'=>18]);
$assert(200, 'GET', '/api/workspace');
$assert(200, 'GET', '/api/history');
$assert(200, 'GET', '/api/production-calendar');
$assert(503, 'GET', '/api/registry');
$assert(503, 'GET', '/api/department-roster');
$assert(503, 'GET', '/api/approvals');
$assert(503, 'POST', '/api/approvals/55/approve');
$assert(503, 'GET', '/api/absences/15/attachments/44/download');
$assert(503, 'GET', '/api/client-service-calendar');

$live['role_claims']=['org:irlix'];
$assert(403, 'GET', '/api/me');
$live['role_claims']=['org:irlix','special:platform-admin'];
$live['employee_id']=18;
$assert(403, 'GET', '/api/me');

if ($oldLocal === false) putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED='.$oldLocal);
if ($oldJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
echo "Vacations personal-only local gate: PASS\n";
