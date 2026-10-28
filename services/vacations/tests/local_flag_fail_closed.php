<?php

require __DIR__.'/../vendor/autoload.php';

use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Http\Request;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$old = getenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
$oldJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED=true');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');

$gate = new ServiceEntitlement();
$pass = static fn ($request) => response()->json(['data'=>['unsafe'=>true]]);
foreach ([
    ['GET','/api/absences'],
    ['POST','/api/absences'],
    ['GET','/api/registry'],
    ['GET','/api/absences/123/attachments/4/download'],
    ['POST','/api/approvals/1/approve'],
    ['GET','/api/client-service-calendar'],
] as [$verb,$route]) {
    $request = Request::create($route,$verb,[],[],[],[
        'HTTP_AUTHORIZATION'=>'Bearer isolated-test-token',
    ]);
    $request->attributes->set('identity',['realm_roles'=>[
        'platform-admin','service:vacations','service:clients','scope:all',
    ]]);
    $result = $gate->handle($request,$pass);
    if ($result->getStatusCode() !== 503) {
        throw new RuntimeException('Incomplete Vacations V2 gate admitted '. $verb .' '. $route);
    }
}
if ($old === false) putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED='.$old);
if ($oldJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
echo "Vacations incomplete V2 switch fails closed: PASS\n";
