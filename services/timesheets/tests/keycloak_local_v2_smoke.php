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

$liveEmployeeId = 42;
$liveRoles = ['org:irlix'];
$employeesAllowed = true;
$employeesStatus = 200;
$legacyPermissions = [];
$implicitOwn = true;
$clientsStatus = 200;
Http::preventStrayRequests();
Http::fake(static function ($request) use (
    $jwks, &$liveEmployeeId, &$liveRoles, &$employeesAllowed,
    &$employeesStatus, &$legacyPermissions, &$implicitOwn, &$clientsStatus
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
            'legacy_timesheets_implicit_mine_view'=>$implicitOwn,
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
    'Legitimately signed personal workspace was not constrained to own scope');
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
$liveRoles = ['org:irlix'];
$employeesAllowed = false;
$test($execute($token, '/api/workspace')[0] === 403,
    'Existing Employees service-entry deny was bypassed');
$employeesAllowed = true;
$employeesStatus = 503;
$test($execute($token, '/api/workspace')[0] === 503,
    'Unavailable Employees identity source was not fail-closed');
$employeesStatus = 200;

$implicitOwn = false;
$test($execute($token, '/api/workspace')[0] === 403,
    'Missing historical-absence attestation manufactured a grant');
$implicitOwn = true;
$legacyPermissions = ['timesheets.mine.view'=>['allowed'=>false,'scope'=>'none']];
$test($execute($token, '/api/workspace')[0] === 403,
    'Explicit old Clients denial was bypassed');
$legacyPermissions = [];
$clientsStatus = 503;
$test($execute($token, '/api/workspace')[0] === 503,
    'Unavailable old Clients ceiling was not fail-closed');
$clientsStatus = 200;

DB::table('timesheets_role_permissions')->delete();
$test($execute($token, '/api/workspace')[0] === 403,
    'Missing V2 grant was replaced by the historical fallback');

echo "Timesheets isolated real Keycloak-signed JWT -> local V2 own/deny: PASS\n";
