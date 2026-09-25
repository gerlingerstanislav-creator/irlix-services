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
        if ($request->is('api/health')) {
            return $next($request);
        }

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) {
            return response()->json(['message' => 'Authentication token missing'], 401);
        }

        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $clientId = (string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web');
        $expectedIssuer = rtrim((string) env('KEYCLOAK_ISSUER', "{$base}/realms/{$realm}"), '/');
        $jwksUrl = "{$base}/realms/{$realm}/protocol/openid-connect/certs";

        $parsed = $this->parseJwt($token);
        if (!$parsed) {
            return response()->json(['message' => 'Invalid access token'], 401);
        }

        [$header, $claims, $signedData, $signature] = $parsed;

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            return response()->json(['message' => 'Unsupported access token signature'], 401);
        }

        $jwks = $this->loadJwks($realm, $jwksUrl);
        if (!$jwks) {
            return response()->json(['message' => 'Identity provider unavailable'], 503);
        }

        $jwk = collect($jwks['keys'] ?? [])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']);
        if (!$jwk) {
            try {
                Cache::forget("keycloak.jwks.{$realm}");
            } catch (\Throwable) {
                // Cache is an optimization only; authentication must not depend on Redis availability.
            }
            $jwks = $this->fetchJwks($jwksUrl);
            $jwk = collect($jwks['keys'] ?? [])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']);
        }

        if (!$jwk) {
            return response()->json(['message' => 'Unknown access token signing key'], 401);
        }

        $certificate = $jwk['x5c'][0] ?? null;
        if (!$certificate) {
            return response()->json(['message' => 'Signing certificate unavailable'], 401);
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificate, 64, "\n")."-----END CERTIFICATE-----\n";
        $verified = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return response()->json(['message' => 'Invalid access token signature'], 401);
        }

        $now = time();
        if (($claims['exp'] ?? 0) <= $now || (($claims['nbf'] ?? 0) > $now + 30)) {
            return response()->json(['message' => 'Expired or inactive access token'], 401);
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $expectedIssuer) {
            return response()->json(['message' => 'Token was issued by another identity provider'], 401);
        }

        if (($claims['azp'] ?? null) !== $clientId) {
            return response()->json(['message' => 'Token was issued for another client'], 401);
        }

        $roles = $claims['realm_access']['roles'] ?? [];
        if (!is_array($roles)) $roles = [];

        $request->attributes->set('identity', [
            'sub' => $claims['sub'] ?? null,
            'preferred_username' => $claims['preferred_username'] ?? null,
            'email' => $claims['email'] ?? null,
            'given_name' => $claims['given_name'] ?? null,
            'family_name' => $claims['family_name'] ?? null,
            'realm_roles' => $roles,
        ]);

        return $next($request);
    }

    private function loadJwks(string $realm, string $jwksUrl): ?array
    {
        $cacheKey = "keycloak.jwks.{$realm}";

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['keys'])) {
                return $cached;
            }
        } catch (\Throwable) {
            // Redis/cache outages must not turn valid API requests into 503 responses.
        }

        $jwks = $this->fetchJwks($jwksUrl);
        if (!$jwks) {
            return null;
        }

        try {
            Cache::put($cacheKey, $jwks, 300);
        } catch (\Throwable) {
            // Cache is best-effort only.
        }

        return $jwks;
    }

    private function fetchJwks(string $jwksUrl): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(5)->get($jwksUrl);
            if (!$response->successful()) {
                return null;
            }
            $payload = $response->json();
            return is_array($payload) && isset($payload['keys']) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $headerJson = $this->base64UrlDecode($parts[0]);
        $claimsJson = $this->base64UrlDecode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        if ($headerJson === null || $claimsJson === null || $signature === null) return null;

        $header = json_decode($headerJson, true);
        $claims = json_decode($claimsJson, true);
        if (!is_array($header) || !is_array($claims)) return null;

        return [$header, $claims, $parts[0].'.'.$parts[1], $signature];
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }
}
