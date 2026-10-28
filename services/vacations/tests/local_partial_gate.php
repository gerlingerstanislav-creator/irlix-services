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

$profileId = 17;
$live = ['employee_id'=>17,
    'roles'=>['platform-admin'],
    'role_claims'=>['org:irlix','special:platform-admin'],
];
Http::preventStrayRequests();
Http::fake(static function ($req) use (&$live, &$profileId) {
    if (str_ends_with((string) $req->url(), '/access/me')) {
        return Http::response(['data'=>$live],200);
    }
    if (str_ends_with((string) $req->url(), '/self')) {
        return Http::response(['data'=>['id'=>$profileId,'full_name'=>'Synthetic']],200);
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
$assert(200, 'GET', '/api/registry');
$assert(503, 'GET', '/api/department-roster');
$assert(200, 'GET', '/api/approvals');
$assert(503, 'GET', '/api/client-service-calendar');
$assert(503, 'GET', '/api/calendar-absences');
$scoped = new \App\Services\VacationsScopedAccess(new \App\Support\EmployeesDirectory());
if (!$scoped->permits(Request::create('/api/registry','GET'), 'team', 17)) {
    throw new RuntimeException('Scoped registry blocked at middleware despite controller row filter');
}
if (!$scoped->permits(Request::create('/api/approvals','GET'), 'own', 17)) {
    throw new RuntimeException('Scoped approvals list blocked before controller resource filter');
}
if ($scoped->permits(Request::create('/api/random','GET'), 'all', 17)) {
    throw new RuntimeException('Unknown Vacations API path was authorized');
}


$live['role_claims']=['org:irlix'];
$assert(403, 'GET', '/api/me');
$live['role_claims']=['org:irlix','special:platform-admin'];
$live['employee_id']=18;
$assert(403, 'GET', '/api/me');

// A secondary Employees /self response may never silently change the
// actor already bound to the signed Keycloak/Employees identity projection.
$profile = Request::create('/api/me', 'GET', [], [], [], [
    'HTTP_AUTHORIZATION'=>'Bearer synthetic-signed-jwt',
]);
$profile->attributes->set('vacations_local_employee_id',17);
$profileService = new \App\Support\CurrentEmployee();
if ((int) $profileService->resolve($profile)['id'] !== 17) {
    throw new RuntimeException('Verified actor profile mismatch in normal flow');
}
$profileId = 18;
try {
    $profileService->resolve($profile);
    throw new RuntimeException('Another employee profile was accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage()==='Another employee profile was accepted') throw $expected;
}

if ($oldLocal === false) putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED='.$oldLocal);
if ($oldJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
echo "Vacations personal-only local gate: PASS\n";
