<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service admission follows Employees' effective entitlement.
 * Domain permission/scope middleware executes after this gate.
 */
final class ServiceEntitlement
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) return response()->json(['message' => 'Authentication required'], 401);

        // Until Vacations enforces its local permissions AND object scopes
        // in every workflow/attachment route, flipping the V2 switch must
        // never silently fall through to legacy broad role authorization.
        // Matrix configuration remains usable; all business operations fail
        // closed on premature activation. This is deliberately not cutover.
        if (filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && !$request->is('api/permissions')) {
            return response()->json(['message' => 'Vacations V2 authorization cutover is not ready'], 503);
        }
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            if (!in_array('service:vacations', $roles, true)
                && !($request->is('api/client-service-calendar', 'api/client-service-absence-alerts') && in_array('service:clients', $roles, true))) {
                return response()->json(['message' => 'Service access denied'], 403);
            }
            return $next($request);
        }
        try {
            $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
            $result = Http::withToken((string) $token)->acceptJson()->timeout(5)
                ->get("{$base}/access/me");
        } catch (\Throwable) {
            return response()->json(['message' => 'Service authorization unavailable'], 503);
        }
        if ($result->status() === 401) return response()->json(['message' => 'Authentication required'], 401);
        if (!$result->successful()) return response()->json(['message' => 'Service authorization unavailable'], 503);
        if ($result->json('data.services.vacations') !== true
            && !($request->is('api/client-service-calendar', 'api/client-service-absence-alerts') && $result->json('data.services.clients') === true)) {
            return response()->json(['message' => 'Service access denied'], 403);
        }
        return $next($request);
    }
}
