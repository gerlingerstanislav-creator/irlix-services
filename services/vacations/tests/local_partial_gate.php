<?php

require __DIR__.'/../vendor/autoload.php';

use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Unhandled exceptions must fail the image build. Laravel's global
// handler can otherwise report a failed assertion but exit PHP with 0.
set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, 'Vacations local gate FAIL: '.$error->getMessage()."\n");
    exit(1);
});

if (getenv('IRLIX_SECURITY_TEST_PROBE') === '1') {
    throw new RuntimeException('Security test must signal failure to Docker');
}

config()->set('database.default','sqlite');
config()->set('database.connections.sqlite.database',':memory:');
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\Schema::connection('sqlite')->create('absences',
    static function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('employee_id');
    });
\Illuminate\Support\Facades\DB::table('absences')->insert([
    ['id'=>15, 'employee_id'=>17],
    ['id'=>16, 'employee_id'=>18],
]);
\Illuminate\Support\Facades\Schema::connection('sqlite')->create('vacations_role_permissions',
    static function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('role');
        $table->string('permission');
        $table->boolean('allowed');
        $table->string('scope');
    });
\Illuminate\Support\Facades\DB::table('vacations_role_permissions')->insert([
    ['role'=>'org:irlix','permission'=>'vacations.mine.view','allowed'=>true,'scope'=>'own'],
    ['role'=>'org:irlix','permission'=>'vacations.documents.view','allowed'=>true,'scope'=>'own'],
]);

$oldLocal = getenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
$oldJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED=true');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');

$profileId = 17;
$live = ['employee_id'=>17,
    'services'=>['vacations'=>true],
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
    if (str_ends_with((string) $req->url(), '/clients-directory')) {
        return Http::response(['data'=>[
            'actor'=>['id'=>17,'department_id'=>2],
            'employees'=>[
                ['id'=>17,'department_id'=>2],
                ['id'=>18,'department_id'=>3],
                ['id'=>19,'department_id'=>4],
                ['id'=>20,'department_id'=>2],
            ],
            'departments'=>[
                ['id'=>1,'parent_id'=>null,'manager_id'=>null],
                ['id'=>2,'parent_id'=>1,'manager_id'=>17],
                ['id'=>3,'parent_id'=>2,'manager_id'=>18],
                ['id'=>4,'parent_id'=>1,'manager_id'=>19],
            ],
        ]],200);
    }
    return Http::response([],404);
});

$gate = new ServiceEntitlement();
$assert = static function (
    int $expected, string $method, string $path, array $data = [], array $signed = []
) use ($gate): void {
    $request = Request::create($path, $method, $data, [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer synthetic-signed-jwt',
    ]);
    $request->attributes->set('identity', ['realm_roles'=>($signed ?: [
        'employee-id:17','org:irlix','special:platform-admin',
    ])]);
    $response = $gate->handle($request,
        static fn () => response()->json(['data'=>['authorized'=>true]],200));
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("Expected {$expected} for {$method} {$path}, got {$response->getStatusCode()}");
    }
};

$assert(200, 'GET', '/api/me');
$assert(200, 'GET', '/api/permissions');
$assert(200, 'PUT', '/api/permissions');
$assert(200, 'DELETE', '/api/permissions');
$assert(200, 'GET', '/api/absences');
$assert(200, 'POST', '/api/absences');
$assert(200, 'GET', '/api/absences/15/history');
$assert(200, 'PATCH', '/api/absences/15');
$assert(200, 'GET', '/api/absences/occupied', ['employee_id'=>17]);
$assert(200, 'GET', '/api/absences/occupied', ['employee_id'=>18]);
$assert(200, 'GET', '/api/workspace');
$assert(200, 'GET', '/api/history');
$assert(200, 'GET', '/api/production-calendar');
$assert(200, 'GET', '/api/registry');
$assert(200, 'GET', '/api/department-roster');
$assert(200, 'GET', '/api/approvals');
$assert(200, 'GET', '/api/client-service-calendar');
$assert(200, 'GET', '/api/client-service-absence-alerts');
$assert(403, 'GET', '/api/unknown-global-export');
$assert(200, 'GET', '/api/calendar-absences');
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
$scopeRequest = Request::create('/api/calendar-absences', 'GET', [], [], [], [
    'HTTP_AUTHORIZATION'=>'Bearer synthetic-signed-jwt',
]);
$scopeRequest->attributes->set('vacations_local_permissions', [
    'vacations.department.view'=>['allowed'=>true,'scope'=>'team'],
]);
$teamIds = $scoped->visibleEmployeeIds($scopeRequest, 'team', 17);
sort($teamIds);
if ($teamIds !== [17,18,20]) {
    throw new RuntimeException('Manager scope did not follow current department IDs');
}
if ($scoped->visibleEmployeeIds($scopeRequest, 'team', 17, [19]) !== []) {
    throw new RuntimeException('Manager gained access to a foreign department');
}
if ($scoped->visibleEmployeeIds($scopeRequest, 'own', 17) !== [17]) {
    throw new RuntimeException('Own scope returned someone else');
}
if ($scoped->visibleEmployeeIds($scopeRequest, 'all', 17) !== null) {
    throw new RuntimeException('All scope did not preserve intentional global access');
}


// Real signed non-admin actor with own scope: other absences and
// documents stay forbidden, even when a URL is guessed directly.
$live['roles']=[];
$live['role_claims']=['org:irlix'];
$own=['employee-id:17','org:irlix'];
$assert(200,'GET','/api/workspace',[],$own);
$assert(403,'GET','/api/permissions',[],$own);
$assert(403,'PUT','/api/permissions',[],$own);
$assert(200,'GET','/api/absences/15',[],$own);
$assert(403,'GET','/api/absences/16',[],$own);
$assert(200,'GET','/api/absences/15/attachments',[],$own);
$assert(403,'GET','/api/absences/16/attachments',[],$own);
$assert(403,'GET','/api/approvals',[],$own);
$assert(403,'GET','/api/calendar-absences',[],$own);
$assert(403,'GET','/api/history',[],$own);
$assert(403,'POST','/api/absences',[],$own);

// Restore the administrative actor for stale-token revocation tests.
$live['roles']=['platform-admin'];
$live['role_claims']=['org:irlix','special:platform-admin'];

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
