<?php
namespace App\Migration\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Validate the browser's Keycloak access token before reading privileged grants. */
final class VerifiedKeycloakJwt
{
    public static function authorize(Request $request, string $service): array|JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) return response()->json(['message' => 'Authentication required'], 401);
        $parts = explode('.', $token);
        if (count($parts) !== 3) return response()->json(['message' => 'Invalid access token'], 401);
        $decode = static function (string $text): ?string {
            $text = strtr($text, '-_', '+/');
            $out = base64_decode($text.str_repeat('=', (4-strlen($text)%4)%4), true);
            return $out === false ? null : $out;
        };
        $header = json_decode((string) $decode($parts[0]), true);
        $claims = json_decode((string) $decode($parts[1]), true);
        $signature = $decode($parts[2]);
        if (!is_array($header) || !is_array($claims) || !is_string($signature)
            || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null))
            return response()->json(['message' => 'Invalid access token'], 401);
        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $issuer = rtrim((string) env('KEYCLOAK_ISSUER', "{$base}/realms/{$realm}"), '/');
        $client = (string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web');
        $now = time();
        if (($claims['exp'] ?? 0) <= $now || ($claims['nbf'] ?? 0) > $now + 30
            || rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer
            || ($claims['azp'] ?? '') !== $client || empty($claims['sub']))
            return response()->json(['message' => 'Invalid token claims'], 401);

        $cacheKey = 'keycloak.admin-jwks.'.hash('sha256', $issuer);
        try { $keys = Cache::get($cacheKey); } catch (\Throwable) { $keys = null; }
        if (!is_array($keys) || !isset($keys['keys'])) {
            try {
                $result = Http::acceptJson()->timeout(5)->get("{$base}/realms/{$realm}/protocol/openid-connect/certs");
                $keys = $result->successful() ? $result->json() : null;
                if (is_array($keys) && isset($keys['keys'])) {
                    try { Cache::put($cacheKey, $keys, 300); } catch (\Throwable) {}
                }
            } catch (\Throwable) { $keys = null; }
        }
        if (!is_array($keys) || !is_array($keys['keys'] ?? null))
            return response()->json(['message' => 'Identity provider unavailable'], 503);
        $selected = null;
        foreach ($keys['keys'] as $key) {
            if (($key['kid'] ?? null) === $header['kid']) { $selected = $key; break; }
        }
        if (!is_array($selected) || !is_string($selected['x5c'][0] ?? null))
            return response()->json(['message' => 'Token signing key unavailable'], 401);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($selected['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1)
            return response()->json(['message' => 'Invalid access token signature'], 401);
        $roles = $claims['realm_access']['roles'] ?? [];
        if (!is_array($roles) || !in_array('platform-admin', $roles, true)
            || !in_array('service:'.$service, $roles, true))
            return response()->json(['message' => 'Platform administrator permission required'], 403);
        return ['roles' => $roles, 'services' => [$service => true], 'sub' => $claims['sub']];
    }
}
