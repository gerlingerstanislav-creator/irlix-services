<?php

require __DIR__.'/../vendor/autoload.php';

use App\Support\EmployeesDirectory;

$assert = static function (bool $value, string $error): void {
    if (!$value) throw new RuntimeException($error);
};
$old = [
    'employee-id:44','service:vacations','org:irlix','head:accounting',
    'personnel-officer','special:personnel-officer','platform-admin','special:platform-admin',
];
$current = ['employee_id'=>44, 'roles'=>['personnel-officer','irlix']];
$actual = EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,$current);
$assert(in_array('personnel-officer',$actual,true),'Current personnel role incorrectly revoked');
$assert(in_array('special:personnel-officer',$actual,true),'Current namespaced personnel role lost');
$assert(!in_array('platform-admin',$actual,true),'Revoked admin remains effective');
$assert(!in_array('special:platform-admin',$actual,true),'Revoked special admin remains effective');
$assert(in_array('head:accounting',$actual,true),'Unrelated org/head roles unexpectedly mutated');

$revoked=EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,
    ['employee_id'=>44,'roles'=>['irlix']]);
$assert(!in_array('personnel-officer',$revoked,true),'Revoked personnel role remains effective');
$assert(!in_array('special:personnel-officer',$revoked,true),'Revoked special personnel role remains effective');
$assert(in_array('service:vacations',$revoked,true),'Revocation incorrectly removed ordinary admission');

foreach ([
    ['employee_id'=>33,'roles'=>['personnel-officer','platform-admin']],
    ['employee_id'=>44],
    [],
] as $invalid) {
    try {
        EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,$invalid);
        throw new RuntimeException('Missing or mismatched authoritative subject accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Missing or mismatched authoritative subject accepted') throw $e;
    }
}
$assert(EmployeesDirectory::limitToCurrentPrivilegedRoles(['employee-id:44','org:irlix'],44,
    ['employee_id'=>44,'roles'=>[]])===['employee-id:44','org:irlix'],
    'Regular signed organization labels must be left unchanged');
// Exercise the actual EmployeesDirectory JWT decision with a controlled
// real Laravel HTTP client. Never reach external hosts or production.
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, 'Vacations revoked roles FAIL: '.$error->getMessage()."\n");
    exit(1);
});
$oldJwtMode = getenv('IRLIX_AUTHZ_JWT_ENABLED');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
$source = ['employee_id'=>44,'roles'=>['personnel-officer']];
$status = 200;
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Http::fake(static function ($request) use (&$source, &$status) {
    if (!str_ends_with((string) $request->url(), '/access/me')) {
        return \Illuminate\Support\Facades\Http::response([], 404);
    }
    return \Illuminate\Support\Facades\Http::response(['data'=>$source], $status);
});
$req = \Illuminate\Http\Request::create('/api/approvals/1/approve','POST');
$req->headers->set('Authorization', 'Bearer synthetic-test-token');
$req->attributes->set('identity', ['realm_roles'=>[
    'employee-id:44','service:vacations','scope:all','personnel-officer',
]]);
$directory = new EmployeesDirectory();
$assert(in_array('personnel-officer',$directory->access($req)['roles'],true),
    'Current signed personnel role rejected on live Employees validation');
$source['roles'] = [];
try {
    $directory->access($req);
    throw new RuntimeException('Stale JWT and scope:all remained effective after revocation');
} catch (DomainException $expected) {
    // A revoked privileged role must deny the whole stale token, not
    // leave the lingering scope:all grant effective.
}
$source = ['employee_id'=>55,'roles'=>['personnel-officer']];
try {
    $directory->access($req);
    throw new RuntimeException('Mismatched employee subject accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage()==='Mismatched employee subject accepted') throw $expected;
}
$source=['employee_id'=>44,'roles'=>['personnel-officer']];
$status=503;
try {
    $directory->access($req);
    throw new RuntimeException('Employees outage granted personnel privileges');
} catch (RuntimeException $expected) {
    if ($expected->getMessage()==='Employees outage granted personnel privileges') throw $expected;
}
$status=200;
$req->headers->set('X-Irlix-Role-Preview', 'personnel-officer');
try {
    $directory->access($req);
    throw new RuntimeException('Role preview granted privileged access');
} catch (DomainException $expected) {
    // preview never authorizes sensitive data
}
if ($oldJwtMode === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwtMode);

echo "Vacations immediate privileged-role revocation contract: PASS\n";
