<?php
declare(strict_types=1);

// CI only: an actual Keycloak-signed JWT is verified by the Timesheets bearer
// middleware; Employees/Clients business fixtures are synthetic and isolated.
// This never connects to production or serializes real employee identifiers.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Middleware\KeycloakBearer;
use App\Http\Middleware\ServiceEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'Timesheets isolated signed JWT + local V2: FAIL ('.$e->getMessage().")\n");
    exit(1);
});

$token = (string) getenv('IRLIX_CI_BEARER');
$jwks = json_decode((string) base64_decode((string) getenv('IRLIX_CI_JWKS_BASE64'), true), true);
if ($token === '' || !is_array($jwks) || !isset($jwks['keys'])) {
    throw new RuntimeException('Missing actual signed Keycloak CI JWT or certs');
}

config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', ':memory:');
DB::purge('sqlite');
Schema::connection('sqlite')->create('timesheets_role_permissions',
    static function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('role');
        $table->string('permission');
        $table->boolean('allowed');
        $table->string('scope');
    });
$grant = [
    'role'=>'org:irlix', 'permission'=>'timesheets.mine.view',
    'allowed'=>true, 'scope'=>'own',
];
DB::table('timesheets_role_permissions')->insert($grant);
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
putenv('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED=true');

// Mirror the actual isolated Keycloak persona's signed namespaced roles
// into the synthetic current Employees source. The real middleware still
// independently verifies the JWT signature before trusting any of them.
$jwtSegments = explode('.', $token);
$jwtPayload = count($jwtSegments) === 3
    ? json_decode((string) base64_decode(strtr($jwtSegments[1], '-_', '+/'), true), true)
    : null;
$signedLabels = is_array($jwtPayload) && is_array($jwtPayload['realm_access']['roles'] ?? null)
    ? $jwtPayload['realm_access']['roles'] : [];
$signedIdentityIds = array_values(array_filter($signedLabels, static fn ($label) =>
    is_string($label) && preg_match('/^employee-id:[1-9][0-9]*$/D', $label) === 1));
$expectedId = 'employee-id:42';
if ($signedIdentityIds !== [$expectedId]) {
    throw new RuntimeException('Keycloak synthetic actor identity claim not unique and expected');
}
$signedNamespaced = array_values(array_filter($signedLabels, static fn ($label) =>
    is_string($label)
    && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $label) === 1));
if (!in_array('org:irlix', $signedNamespaced, true)) {
    throw new RuntimeException('Keycloak synthetic actor lacks org:irlix');
}
$liveEmployeeId = 42;
$liveRoles = $signedNamespaced;
$employeesAllowed = true;
$employeesStatus = 200;
$legacyPermissions = [];
$clientsStatus = 200;
Http::preventStrayRequests();
Http::fake(static function ($request) use (
    $jwks, &$liveEmployeeId, &$liveRoles, &$employeesAllowed,
    &$employeesStatus, &$legacyPermissions, &$clientsStatus
) {
    $url = (string) $request->url();
    if (str_ends_with($url, '/protocol/openid-connect/certs')) {
        // This is Keycloak's real public signing certificate from the runner.
        return Http::response($jwks, 200);
    }
    if (str_ends_with($url, '/access/me')) {
        return Http::response(['data'=>[
            'employee_id'=>$liveEmployeeId,
            'role_claims'=>$liveRoles,
            'roles'=>[],
            'services'=>['timesheets'=>$employeesAllowed],
        ]], $employeesStatus);
    }
    if (str_ends_with($url, '/permissions/me')) {
        return Http::response(['data'=>[
            'permissions'=>$legacyPermissions,
        ]], $clientsStatus);
    }
    return Http::response(['message'=>'No synthetic route'], 404);
});

$test = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
};
$execute = static function (string $jwt, string $path, array $headers = []): array {
    $request = Request::create($path, 'GET');
    $request->headers->set('Authorization', 'Bearer '.$jwt);
    foreach ($headers as $name => $value) $request->headers->set($name, $value);
    $response = (new KeycloakBearer())->handle(
        $request, static fn (Request $signed) =>
            (new ServiceEntitlement())->handle(
                $signed, static fn (Request $authorized) => response()->json([
                    'scope'=>$authorized->attributes->get('timesheets_local_permissions')
                        ['timesheets.mine.view']['scope'] ?? 'none',
                ], 200)
            )
    );
    return [$response->getStatusCode(), json_decode((string)$response->getContent(), true)];
};

[$status, $payload] = $execute($token, '/api/workspace');
$test($status === 200 && ($payload['scope'] ?? null) === 'own',
    'Legitimately signed personal workspace was not constrained to own scope'
    .' (HTTP '.$status.', scope '.(string)($payload['scope'] ?? 'none')
    .', reason '.(string)($payload['message'] ?? 'none').')');
$test($execute($token, '/api/management')[0] === 403,
    'Personal workspace grant created unauthorized management access');
$test($execute($token, '/api/workspace', ['X-Irlix-Role-Preview'=>'platform-admin'])[0] === 403,
    'Role-preview header escalated access');

$parts = explode('.', $token);
$test(count($parts) === 3, 'Keycloak did not mint a three-part JWT');
$parts[2] = (str_starts_with($parts[2], 'A') ? 'B' : 'A').substr($parts[2], 1);
$test($execute(implode('.', $parts), '/api/workspace')[0] === 401,
    'Altered Keycloak JWT signature was accepted');

$liveEmployeeId = 43;
$test($execute($token, '/api/workspace')[0] === 403,
    'Original JWT was trusted for another Employees actor');
$liveEmployeeId = 42;
$liveRoles = [];
$test($execute($token, '/api/workspace')[0] === 403,
    'Revoked organizational membership retained workspace access');
$liveRoles = $signedNamespaced;
$employeesAllowed = false;
$test($execute($token, '/api/workspace')[0] === 403,
    'Existing Employees service-entry deny was bypassed');
$employeesAllowed = true;
$employeesStatus = 503;
$test($execute($token, '/api/workspace')[0] === 503,
    'Unavailable Employees identity source was not fail-closed');
$employeesStatus = 200;

// V2 must NOT depend on old Clients permissions: the persisted own grant
// is sufficient, while Employees still validates live service entitlement.
$legacyPermissions = ['timesheets.mine.view'=>['allowed'=>false,'scope'=>'none']];
$test($execute($token, '/api/workspace')[0] === 200,
    'Old Clients permission deny incorrectly overrides an explicit Timesheets V2 own grant');
$clientsStatus = 503;
$test($execute($token, '/api/workspace')[0] === 200,
    'Timesheets local V2 still depends on unavailable Clients legacy business policy');
$clientsStatus = 200;
$legacyPermissions = [];

DB::table('timesheets_role_permissions')->delete();
$test($execute($token, '/api/workspace')[0] === 403,
    'Missing V2 grant was replaced by the historical fallback');

// Isolated request-level rollback rehearsal, not a production container
// restart: with V2 failing closed, switching only this service's flag back
// to false must restore its original signed service:timesheets admission.
$setFlag = static function (bool $enabled): void {
    $value = $enabled ? 'true' : 'false';
    // Laravel's immutable Dotenv repository can read $_ENV/$_SERVER ahead of
    // getenv(); changing putenv() alone does not emulate a recreated process.
    putenv('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED='.$value);
    $_ENV['IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED'] = $value;
    $_SERVER['IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED'] = $value;
};
$setFlag(false);
[$rollbackStatus, $rollbackBody] = $execute($token, '/api/workspace');
$test($rollbackStatus === 200,
    'Disabling only Timesheets V2 did not restore legacy signed admission'
    .' (HTTP '.$rollbackStatus.', reason '.(string)($rollbackBody['message'] ?? 'none').')');
$setFlag(true);
$test($execute($token, '/api/workspace')[0] === 403,
    'Re-enabled Timesheets V2 did not reinstate default deny');

echo "Timesheets isolated real Keycloak-signed JWT -> independent V2 own/deny and flag rollback: PASS\n";
