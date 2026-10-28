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

        if (filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            // Global and cross-contour read models are not yet provably
            // filtered per row. Fail closed rather than reusing legacy grants.
            if (!filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                return response()->json(['message' => 'Verified JWT mode required for Vacations V2'], 503);
            }
            if ($request->header('X-Irlix-Role-Preview') !== null) {
                return response()->json(['message' => 'Role preview cannot authorize Vacations'], 403);
            }
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            $path = $request->path();
            $method = $request->method();
            $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
            try {
                $fresh = Http::withToken((string) $token)->acceptJson()->timeout(5)
                    ->get($base.'/access/me');
            } catch (\Throwable) {
                return response()->json(['message' => 'Current Vacations identity unavailable'], 503);
            }
            if (!$fresh->successful() || !is_array($fresh->json('data'))) {
                return response()->json(['message' => 'Current Vacations identity unavailable'], 503);
            }
            if (!\App\Services\VacationsRoleAuthorization::currentIdentityMatches(
                $roles, (array) $fresh->json('data')
            )) {
                return response()->json(['message' => 'Vacations roles changed; refresh authentication'], 403);
            }
            if ($path === 'api/permissions') {
                // The service administrator manages its OWN matrix.
                // Legacy service:vacations is not an extra prerequisite;
                // the current JWT still needs a live administrator role.
                if (!in_array('platform-admin', $roles, true)
                    && !in_array('special:platform-admin', $roles, true)) {
                    return response()->json(['message' => 'Vacations matrix administration denied'], 403);
                }
                return $next($request);
            }
            try {
                $permissions = app(\App\Services\VacationsRoleAuthorization::class)->forSignedRoles($roles);
            } catch (\Throwable) {
                return response()->json(['message' => 'Vacations policy unavailable'], 503);
            }
            if ($path === 'api/permissions/me') {
                $request->attributes->set('vacations_local_permissions', $permissions);
                return $next($request);
            }
            if ($path === 'api/workspace'
                && \App\Services\VacationsRoleAuthorization::canOpenWorkspace($permissions)) {
                $request->attributes->set('vacations_local_employee_id',
                    (int) $fresh->json('data.employee_id'));
                $request->attributes->set('vacations_local_permissions', $permissions);
                return $next($request);
            }
            $required = \App\Services\VacationsRoleAuthorization::requiredPermission($method, $path);
            $permission = (array) ($permissions[$required ?? ''] ?? []);
            if (($permission['allowed'] ?? false) !== true
                || !in_array(($permission['scope'] ?? 'none'), ['own','team','all'], true)) {
                return response()->json(['message' => 'Vacations permission denied'], 403);
            }
            // Object grants are checked against the actual Vacations-owned
            // absence/approval and current Employees department membership.
            // Even all does NOT override approver and attachment rules in
            // domain controllers.
            try {
                $permitted = app(\App\Services\VacationsScopedAccess::class)
                    ->permits($request, (string) $permission['scope'],
                        (int) $fresh->json('data.employee_id'));
            } catch (\DomainException) {
                return response()->json(['message' => 'Vacations object access denied'], 403);
            } catch (\Throwable) {
                return response()->json(['message' => 'Vacations scope source unavailable'], 503);
            }
            if (!$permitted) {
                return response()->json(['message' => 'Vacations object access denied'], 403);
            }
            $request->attributes->set('vacations_local_employee_id',
                (int) $fresh->json('data.employee_id'));
            $request->attributes->set('vacations_local_permissions', $permissions);
            return $next($request);
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
