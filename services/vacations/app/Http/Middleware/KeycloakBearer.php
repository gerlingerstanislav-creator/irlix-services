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
        $expectedIssuer = rtrim((string) env('KEYCLOAK_ISSUER', "{$base}/realms/{$realm}"), '/');
        $jwksUrl = "{$base}/realms/{$realm}/protocol/openid-connect/certs";

        $parts = explode('.', $token);
        if (count($parts) !== 3) return response()->json(['message' => 'Invalid access token'], 401);

        $decode = static function (string $value): ?string {
            $value = strtr($value, '-_', '+/');
            $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
            $decoded = base64_decode($value, true);
            return $decoded === false ? null : $decoded;
        };
        $header = json_decode((string) $decode($parts[0]), true);
        $claims = json_decode((string) $decode($parts[1]), true);
        $signature = $decode($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === null) return response()->json(['message' => 'Invalid access token'], 401);
        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) return response()->json(['message' => 'Unsupported access token signature'], 401);

        $cacheKey = "keycloak.jwks.{$realm}";
        try { $jwks = Cache::get($cacheKey); } catch (\Throwable) { $jwks = null; }
        if (!is_array($jwks) || !isset($jwks['keys'])) {
            try {
                $response = Http::acceptJson()->timeout(5)->get($jwksUrl);
                $jwks = $response->successful() ? $response->json() : null;
                if (is_array($jwks) && isset($jwks['keys'])) {
                    try { Cache::put($cacheKey, $jwks, 300); } catch (\Throwable) {}
                }
            } catch (\Throwable) { $jwks = null; }
        }
        if (!is_array($jwks) || !isset($jwks['keys'])) return response()->json(['message' => 'Identity provider unavailable'], 503);

        $jwk = collect($jwks['keys'])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']);
        if (!$jwk) return response()->json(['message' => 'Unknown access token signing key'], 401);
        $certificate = $jwk['x5c'][0] ?? null;
        if (!$certificate) return response()->json(['message' => 'Signing certificate unavailable'], 401);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificate, 64, "\n")."-----END CERTIFICATE-----\n";
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) return response()->json(['message' => 'Invalid access token signature'], 401);

        $now = time();
        if (($claims['exp'] ?? 0) <= $now || (($claims['nbf'] ?? 0) > $now + 30)) return response()->json(['message' => 'Expired or inactive access token'], 401);
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $expectedIssuer) return response()->json(['message' => 'Token was issued by another identity provider'], 401);
        if (($claims['azp'] ?? null) !== $clientId) return response()->json(['message' => 'Token was issued for another client'], 401);

        $request->attributes->set('identity', [
            'sub' => $claims['sub'] ?? null,
            'preferred_username' => $claims['preferred_username'] ?? null,
            'email' => $claims['email'] ?? null,
            'realm_roles' => is_array($claims['realm_access']['roles'] ?? null) ? $claims['realm_access']['roles'] : [],
        ]);
        return $next($request);
    }
}
