<?php
declare(strict_types=1);

// Synthetic signed-JWT runtime tests. These exercise real Finance middleware
// against a signed token and fake Keycloak JWKS, with no Employees network call.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Middleware\KeycloakBearer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function checkJwt(bool $yes, string $why): void {
    if (!$yes) throw new RuntimeException('Finance signed JWT test: '.$why);
}
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
checkJwt($key !== false, 'cannot generate RSA test key');
$csr = openssl_csr_new(['commonName' => 'irlix-ci-jwt-only.test'], $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
checkJwt($cert !== false, 'cannot self-sign synthetic certificate');
openssl_x509_export($cert, $certificate);
$x5c = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificate);

$base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
$realm = (string) env('KEYCLOAK_REALM', 'irlix');
$issuer = rtrim((string) env('KEYCLOAK_ISSUER', "{$base}/realms/{$realm}"), '/');
$client = (string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web');
Http::preventStrayRequests();
Http::fake([
    "{$base}/realms/{$realm}/protocol/openid-connect/certs" =>
        Http::response(['keys'=>[['kid'=>'synthetic-ci','x5c'=>[$x5c]]]], 200),
    rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/').'/access/preview' =>
        Http::response(['message' => 'Role preview is not permitted'], 403),
]);
$encode = static fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
$sign = static function (array $claims) use ($encode, $key): string {
    $data = $encode(['alg'=>'RS256','typ'=>'JWT','kid'=>'synthetic-ci']).'.'.$encode($claims);
    checkJwt(openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256), 'JWT signature could not be generated');
    return $data.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
};
$claims = [
    'sub'=>'ci-accounting-director',
    'preferred_username'=>'ci-accounting-director',
    'exp'=>time()+600,
    'nbf'=>time()-20,
    'iss'=>$issuer,
    'azp'=>$client,
    'realm_access'=>['roles'=>[
        'accounting','accounting-head','service:finance',
        'permission:finance-view','permission:finance-planning-approve',
    ]],
];
$invoke = static function (string $token, ?string $preview = null, string $method = 'GET'): array {
    $request = Request::create('/api/finance/overview', $method);
    if ($preview !== null) $request->headers->set('X-Irlix-Role-Preview', $preview);
    $request->headers->set('Authorization', 'Bearer '.$token);
    $middleware = new KeycloakBearer();
    $response = $middleware->handle($request, function (Request $r) {
        return response()->json(['permissions' => $r->attributes->get('identity')['permissions'] ?? []]);
    });
    return ['status'=>$response->getStatusCode(),'payload'=>json_decode($response->getContent(), true)];
};
try {
    $allowed = $invoke($sign($claims));
    checkJwt($allowed['status'] === 200, 'valid signed JWT rejected');
    $permissions = $allowed['payload']['permissions'] ?? [];
    checkJwt(($permissions['finance.view'] ?? null) === true, 'Finance view grant ignored');
    checkJwt(($permissions['finance.planning.approve'] ?? null) === true, 'Accounting head approval grant ignored');
    foreach (['finance.salary.view','finance.cash.confirm','finance.invoices.manage'] as $private) {
        checkJwt(($permissions[$private] ?? null) === false, "JWT granted {$private} without permission label");
    }
    checkJwt($invoke($sign($claims), 'platform-admin')['status'] === 403,
        'Forged role preview obtained Finance access');
    checkJwt($invoke($sign($claims), 'accounting', 'POST')['status'] === 403,
        'Role preview allowed a write request');
    $noService = $claims;
    $noService['realm_access']['roles'] = ['accounting','permission:finance-view'];
    checkJwt($invoke($sign($noService))['status'] === 403, 'missing global service entitlement accepted');
    $noRead = $claims;
    $noRead['realm_access']['roles'] = ['accounting','service:finance'];
    checkJwt($invoke($sign($noRead))['status'] === 403, 'missing finance.view permission accepted');
    $expired = $claims;
    $expired['exp'] = time()-10;
    checkJwt($invoke($sign($expired))['status'] === 401, 'expired token accepted');
    $wrongIssuer = $claims;
    $wrongIssuer['iss'] = 'https://attacker.invalid/realms/irlix';
    checkJwt($invoke($sign($wrongIssuer))['status'] === 401, 'wrong token issuer accepted');
    $wrongClient = $claims;
    $wrongClient['azp'] = 'unauthorized-app';
    checkJwt($invoke($sign($wrongClient))['status'] === 401, 'wrong OIDC client accepted');
    $tampered = explode('.', $sign($claims));
    $tampered[1] = $encode($wrongIssuer);
    checkJwt($invoke(implode('.', $tampered))['status'] === 401, 'modified unsigned JWT accepted');
    echo "Finance synthetic signed JWT admission and permission-denial tests: PASS\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Finance JWT test FAILED: '.$e->getMessage()."\n");
    exit(1);
}
