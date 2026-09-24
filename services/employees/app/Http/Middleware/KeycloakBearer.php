<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class KeycloakBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) {
            return $next($request);
        }

        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $clientId = (string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->get("{$base}/realms/{$realm}/protocol/openid-connect/userinfo");
        } catch (\Throwable) {
            return response()->json(['message' => 'Identity provider unavailable'], 503);
        }

        if (!$response->successful()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $claims = $this->decodeClaims($token);
        if (!$claims) {
            return response()->json(['message' => 'Invalid access token'], 401);
        }

        $now = time();
        if (($claims['exp'] ?? 0) <= $now || (($claims['nbf'] ?? 0) > $now + 30)) {
            return response()->json(['message' => 'Expired or inactive access token'], 401);
        }

        if (($claims['azp'] ?? null) !== $clientId) {
            return response()->json(['message' => 'Token was issued for another client'], 401);
        }

        $roles = $claims['realm_access']['roles'] ?? [];
        if (!is_array($roles) || !in_array('platform-admin', $roles, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $identity = array_merge($response->json() ?? [], [
            'preferred_username' => $claims['preferred_username'] ?? ($response->json('preferred_username')),
            'email' => $claims['email'] ?? ($response->json('email')),
            'realm_roles' => $roles,
        ]);
        $request->attributes->set('identity', $identity);

        return $next($request);
    }

    private function decodeClaims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) return null;
        $claims = json_decode($decoded, true);
        return is_array($claims) ? $claims : null;
    }
}
