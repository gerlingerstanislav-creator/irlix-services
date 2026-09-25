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
        $issuer = "{$base}/realms/{$realm}";

        $parsed = $this->parseJwt($token);
        if (!$parsed) {
            return response()->json(['message' => 'Invalid access token'], 401);
        }

        [$header, $claims, $signedData, $signature] = $parsed;

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            return response()->json(['message' => 'Unsupported access token signature'], 401);
        }

        try {
            $jwks = Cache::remember("keycloak.jwks.{$realm}", 300, function () use ($issuer) {
                $response = Http::acceptJson()->timeout(5)->get("{$issuer}/protocol/openid-connect/certs");
                if (!$response->successful()) {
                    throw new \RuntimeException('JWKS request failed');
                }
                return $response->json();
            });
        } catch (\Throwable) {
            return response()->json(['message' => 'Identity provider unavailable'], 503);
        }

        $jwk = collect($jwks['keys'] ?? [])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']);
        if (!$jwk) {
            Cache::forget("keycloak.jwks.{$realm}");
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

        if (($claims['iss'] ?? null) !== $issuer) {
            return response()->json(['message' => 'Token was issued by another identity provider'], 401);
        }

        if (($claims['azp'] ?? null) !== $clientId) {
            return response()->json(['message' => 'Token was issued for another client'], 401);
        }

        $roles = $claims['realm_access']['roles'] ?? [];
        if (!is_array($roles) || !in_array('platform-admin', $roles, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

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
