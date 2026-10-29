<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT service:clients grants admission. The Clients-owned V2 matrix
 * independently supplies business permissions and object scope.
 */
final class ServiceEntitlement
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) return response()->json(['message' => 'Authentication required'], 401);

        // JWT is the normal supported admission path. The non-JWT branch
        // remains an inactive rollout compatibility path only.
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            if (!in_array('service:clients', $roles, true)) {
                return response()->json(['message' => 'Service access denied'], 403);
            }
            if (filter_var(env('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                // Keycloak signed service admission; Clients alone decides
                // actual local permission/scope. No Employees /access/me
                // authorization request in the normal local V2 gate.
                try {
                    $access = app(\App\Support\ClientContourAccess::class)->resolve($request);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                    return response()->json(['message'=>'Clients authorization denied'], $error->getStatusCode());
                } catch (\Throwable) {
                    return response()->json(['message'=>'Clients authorization unavailable'], 503);
                }
                $granted = (bool) ($access['platform_admin'] ?? false);
                foreach ((array) ($access['permissions'] ?? []) as $permission => $effect) {
                    if (str_starts_with((string) $permission, 'timesheets.')) continue;
                    if (($effect['allowed'] ?? false) === true) { $granted = true; break; }
                }
                if (!$granted) {
                    return response()->json(['message'=>'Clients access denied by local policy'],403);
                }
                $request->attributes->set('client_contour_access',$access);
                $request->attributes->set('client_contour_verified_at_entitlement',true);
            }
            return $next($request);
                }
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
        if ($result->json('data.services.clients') !== true) {
            return response()->json(['message' => 'Service access denied'], 403);
        }
        return $next($request);
    }
}
