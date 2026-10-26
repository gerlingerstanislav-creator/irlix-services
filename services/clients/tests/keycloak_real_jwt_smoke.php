<?php

/**
 * Actual Keycloak-signed JWT -> Clients HTTP bearer/entitlement middleware.
 * Run only against an isolated Keycloak started by keycloak-authz CI.
 * No production endpoints and no database operations.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Middleware\KeycloakBearer;
use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Http\Request;

$token = (string) getenv('IRLIX_CI_BEARER');
$expected = (int) getenv('IRLIX_CI_STATUS');
if ($token === '' || !in_array($expected, [200, 401, 403], true)) {
    throw new RuntimeException('Missing isolated JWT fixture or expected HTTP status');
}
$request = Request::create('/api/clients', 'GET');
$request->headers->set('Authorization', 'Bearer '.$token);

$response = (new KeycloakBearer())->handle(
    $request,
    fn (Request $verified) => (new ServiceEntitlement())->handle(
        $verified,
        static fn () => response()->json(['authenticated' => true], 200)
    )
);
$status = $response->getStatusCode();
if ($status !== $expected) {
    throw new RuntimeException("Clients Keycloak JWT middleware returned {$status}, expected {$expected}");
}
if ($expected === 200 && $response->getData(true) !== ['authenticated' => true]) {
    throw new RuntimeException('Valid client entitlement did not reach the protected handler');
}
echo "Clients real Keycloak JWT + service entitlement: PASS ({$expected})\n";
