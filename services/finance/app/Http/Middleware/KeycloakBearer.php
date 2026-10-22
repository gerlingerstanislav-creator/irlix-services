<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class KeycloakBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) return response()->json(['message' => 'Authentication token missing'], 401);
        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $clientId = (string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web');
        $issuer = rtrim((string) env('KEYCLOAK_ISSUER', "{$base}/realms/{$realm}"), '/');
        $parts = explode('.', $token);
        if (count($parts) !== 3) return response()->json(['message' => 'Invalid access token'], 401);
        $decode = static function (string $value): ?string { $value = strtr($value, '-_', '+/'); $value .= str_repeat('=', (4 - strlen($value) % 4) % 4); $decoded = base64_decode($value, true); return $decoded === false ? null : $decoded; };
        $header = json_decode((string) $decode($parts[0]), true); $claims = json_decode((string) $decode($parts[1]), true); $signature = $decode($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === null || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) return response()->json(['message' => 'Invalid access token'], 401);
        $cacheKey = "keycloak.jwks.{$realm}"; try { $jwks = Cache::get($cacheKey); } catch (\Throwable) { $jwks = null; }
        if (!is_array($jwks) || !isset($jwks['keys'])) { try { $response = Http::acceptJson()->timeout(5)->get("{$base}/realms/{$realm}/protocol/openid-connect/certs"); $jwks = $response->successful() ? $response->json() : null; if (is_array($jwks) && isset($jwks['keys'])) try { Cache::put($cacheKey, $jwks, 300); } catch (\Throwable) {} } catch (\Throwable) { $jwks = null; } }
        if (!is_array($jwks) || !isset($jwks['keys'])) return response()->json(['message' => 'Identity provider unavailable'], 503);
        $jwk = collect($jwks['keys'])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']);
        if (!$jwk || empty($jwk['x5c'][0])) return response()->json(['message' => 'Signing certificate unavailable'], 401);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($jwk['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) return response()->json(['message' => 'Invalid access token signature'], 401);
        $now = time();
        if (($claims['exp'] ?? 0) <= $now || (($claims['nbf'] ?? 0) > $now + 30) || rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer || ($claims['azp'] ?? null) !== $clientId) return response()->json(['message' => 'Invalid or expired access token'], 401);


        // Role-only sandbox: never accept replacement roles from the browser.
        // Employees verifies the *original signed bearer* and the actor's
        // platform-admin / platform-tester assignment on every preview request.
        $rolePreview = trim((string) $request->header('X-Irlix-Role-Preview', ''));
        if ($rolePreview !== '') {
            if (!filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
                || !in_array($request->method(), ['GET', 'HEAD'], true)) {
                return response()->json(['message' => 'Role preview is read-only and requires JWT authorization'], 403);
            }
            try {
                $employeesUrl = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
                $previewResponse = Http::withToken((string) $token)->acceptJson()
                    ->withHeaders(['X-Irlix-Role-Preview' => $rolePreview])->timeout(5)
                    ->get($employeesUrl.'/access/preview');
            } catch (\Throwable) {
                return response()->json(['message' => 'Role preview validation unavailable'], 503);
            }
            if (!$previewResponse->successful()) {
                return response()->json(['message' => 'Role preview is not permitted'], 403);
            }
            $preview = $previewResponse->json('data');
            if (!is_array($preview) || ($preview['role_preview']['value'] ?? null) !== $rolePreview
                || ($preview['role_preview']['read_only'] ?? null) !== true
                || !is_array($preview['jwt_grants'] ?? null)) {
                return response()->json(['message' => 'Invalid role preview validation'], 403);
            }
            $claims['realm_access']['roles'] = $preview['jwt_grants'];
            $request->attributes->set('role_preview_access', $preview);
        }

        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            // The token was verified above. Global entry and local Finance
            // capabilities are mapped from synchronized Keycloak labels.
            $roles = (array) ($claims['realm_access']['roles'] ?? []);
            if (!in_array('service:finance', $roles, true)) {
                return response()->json(['message' => 'Finance access is not granted'], 403);
            }
            // Preserve existing fine-grained Finance denies. Never infer
            // finance.salary.view or cash confirmation merely from the
            // department label or the global service-entry permission.
            $permissionNames = [
                'finance.view', 'finance.salary.view', 'finance.invoices.manage',
                'finance.cash.confirm', 'finance.expenses.manage',
                'finance.accounts.manage', 'finance.planning.manage',
                'finance.planning.approve',
            ];
            $permissions = [];
            foreach ($permissionNames as $permission) {
                $permissions[$permission] = in_array(
                    'permission:'.str_replace('.', '-', $permission), $roles, true
                );
            }
            if (!$permissions['finance.view']) {
                return response()->json(['message' => 'Finance view access denied'], 403);
            }
            $request->attributes->set('identity', [
                'sub' => $claims['sub'] ?? null,
                'preferred_username' => $claims['preferred_username'] ?? null,
                'email' => $claims['email'] ?? null,
                'realm_roles' => $roles,
                'service_access_granted' => true,
                'permissions' => $permissions,
            ]);
            return $next($request);
        }

        // Employees is the source of truth for service entitlements. Never fall back to
        // Keycloak realm roles or free-text job titles when the decision is unavailable.
        try {
            $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
            $accessResponse = Http::withToken($token)->acceptJson()->timeout(5)
                ->get("{$employeesBase}/access/me");
            if (!$accessResponse->successful() || $accessResponse->json('data.services.finance') !== true) {
                return response()->json(['message' => 'Finance access is not granted'], 403);
            }
            $roles = (array) $accessResponse->json('data.roles', []);
            $permissions = (array) $accessResponse->json('data.permissions', []);
        } catch (\Throwable) {
            return response()->json(['message' => 'Finance authorization service unavailable'], 503);
        }
        $request->attributes->set('identity', [
            'sub' => $claims['sub'] ?? null,
            'preferred_username' => $claims['preferred_username'] ?? null,
            'email' => $claims['email'] ?? null,
            'realm_roles' => $roles,
            'service_access_granted' => true,
            'permissions' => $permissions,
        ]);
        return $next($request);
    }
}
