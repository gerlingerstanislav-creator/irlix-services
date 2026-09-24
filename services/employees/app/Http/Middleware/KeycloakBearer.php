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

        $request->attributes->set('identity', $response->json());

        return $next($request);
    }
}
