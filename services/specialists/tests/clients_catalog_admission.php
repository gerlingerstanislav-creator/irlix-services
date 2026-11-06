<?php

// A Clients user may read only the shared technology vocabulary, without
// any Specialists service entitlement. Verify denial on all other paths.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
$_ENV['IRLIX_AUTHZ_JWT_ENABLED']='true';
$_SERVER['IRLIX_AUTHZ_JWT_ENABLED']='true';
putenv('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED=false');
$_ENV['IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED']='false';
$_SERVER['IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED']='false';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$guard = new ServiceEntitlement();
$make = static function (string $path, array $roles): Request {
    $request = Request::create($path, 'GET', [], [], [], ['HTTP_X_IRLIX_ACCESS_TOKEN' => 'synthetic-read-only']);
    $request->attributes->set('identity', ['realm_roles' => $roles]);
    return $request;
};
$ok = static fn() => response()->json(['data'=>['technologies'=>[['name'=>'PHP']]]]);
Http::fake(['*' => Http::response(['data'=>['services'=>['clients'=>true,'specialists'=>false]]], 200)]);
$allowed = $guard->handle($make('/api/clients-technology-catalog', ['service:clients']), $ok);
$check($allowed->getStatusCode() === 200, 'Clients-only user cannot read shared technology vocabulary');
$preview = $make('/api/clients-technology-catalog', ['service:clients']);
$preview->headers->set('X-Irlix-Role-Preview', 'org:sales');
$check($guard->handle($preview, $ok)->getStatusCode() === 403,
    'Role preview bypassed Clients reference admission');
$check($guard->handle($make('/api/clients-technology-catalog', ['service:specialists']), $ok)->getStatusCode() === 403,
    'Specialists-only identity bypassed Clients reference admission');
$check($guard->handle($make('/api/catalog', ['service:clients']), $ok)->getStatusCode() === 403,
    'Clients reference grant opened the full Specialists catalogue');
$check($guard->handle($make('/api/workspace', ['service:clients']), $ok)->getStatusCode() === 403,
    'Clients reference grant opened Specialists profiles');

Http::fake(['*' => Http::response(['data'=>['services'=>['clients'=>false]]], 200)]);
$check($guard->handle($make('/api/clients-technology-catalog', ['service:clients']), $ok)->getStatusCode() === 403,
    'Revoked Clients entitlement still opened shared vocabulary');

Http::fake(['*' => Http::response(['error'=>'unavailable'], 503)]);
$check($guard->handle($make('/api/clients-technology-catalog', ['service:clients']), $ok)->getStatusCode() === 503,
    'Unavailable Employees admission must fail closed');

echo "Shared Clients reference admission and Specialists isolation: PASS\n";
