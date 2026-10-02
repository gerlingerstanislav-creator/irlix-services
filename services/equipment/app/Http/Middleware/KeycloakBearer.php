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

        $normalizeRole = static fn ($role): string => str_replace('_', '-', mb_strtolower(trim((string) $role)));
        $roles = array_values(array_filter(array_map($normalizeRole, is_array($claims['realm_access']['roles'] ?? null) ? $claims['realm_access']['roles'] : [])));
        $employee = null;

        // Equipment access is a business permission, so the authoritative roles come from Employees.
        // Keep realm roles as a fallback for platform-admin and local development, but enrich them with
        // Employees special roles and the employee's org/position context.
        try {
            $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
            $http = Http::withToken($token)->acceptJson()->timeout(5);
            $access = $http->get("{$employeesBase}/access/me");
            $self = $http->get("{$employeesBase}/self");

            if ($access->successful()) {
                foreach ((array) $access->json('data.roles', []) as $role) $roles[] = $normalizeRole($role);
            }

            if ($self->successful()) {
                $employee = (array) $self->json('data', []);
                $position = mb_strtolower(trim((string) ($employee['position'] ?? '')));
                $department = mb_strtolower(trim((string) ($employee['department_name'] ?? '')));

                $isAccounting = str_contains($department, 'бухгалтер')
                    || str_contains($department, 'accounting')
                    || str_contains($position, 'бухгалтер')
                    || str_contains($position, 'accountant');
                if ($isAccounting) $roles[] = 'accounting';

                $isSystemAdmin = (str_contains($position, 'системн') && str_contains($position, 'админист'))
                    || str_contains($position, 'сисадмин')
                    || str_contains($position, 'sysadmin')
                    || (str_contains($position, 'system') && str_contains($position, 'admin'));
                if ($isSystemAdmin) $roles[] = 'system-admin';
            }
        } catch (\Throwable) {
            // The route-level guard will still honor verified Keycloak realm roles.
        }

        $roles = array_values(array_unique(array_filter($roles)));
        $request->attributes->set('identity', [
            'sub' => $claims['sub'] ?? null,
            'preferred_username' => $claims['preferred_username'] ?? null,
            'email' => $claims['email'] ?? null,
            'realm_roles' => $roles,
            'employee' => $employee,
        ]);
        return $next($request);
    }
}
