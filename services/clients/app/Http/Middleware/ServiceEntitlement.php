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

        // During rollout the old Employees check remains available until every
        // user has been backfilled into Keycloak. In JWT mode this guard has
        // zero dependency on Employees at request time.
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            if (filter_var(env('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                // Retain Employees' effective service-entry decisions,
                // including manual overrides, during the legacy policy import.
                $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
                try {
                    $liveAdmission = Http::withToken((string) $token)->acceptJson()
                        ->timeout(5)->get($employeesBase.'/access/me');
                } catch (\Throwable) {
                    return response()->json(['message'=>'Clients admission unavailable'],503);
                }
                if (!$liveAdmission->successful() || !is_array($liveAdmission->json('data'))) {
                    return response()->json(['message'=>'Clients admission unavailable'],503);
                }
                $timesheetsPolicyConsumer = $request->is('api/permissions/me')
                    && $liveAdmission->json('data.services.timesheets') === true;
                $timesheetsOnly = $timesheetsPolicyConsumer
                    && $liveAdmission->json('data.services.clients') !== true;
                if ($timesheetsOnly) {
                    $request->attributes->set('clients_timesheets_only', true);
                }
                if ($liveAdmission->json('data.services.clients') !== true && !$timesheetsOnly) {
                    return response()->json(['message'=>'Clients admission denied'],403);
                }
                try {
                    $access = app(\App\Support\ClientContourAccess::class)->resolve($request);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                    return response()->json(['message' => 'Clients authorization denied'], $error->getStatusCode());
                } catch (\Throwable) {
                    return response()->json(['message' => 'Clients authorization unavailable'], 503);
                }
                if ($timesheetsOnly) {
                    $request->attributes->set('client_contour_access', $access);
                    $request->attributes->set('client_contour_verified_at_entitlement', true);
                    return $next($request);
                }
                $granted = (bool) ($access['platform_admin'] ?? false);
                foreach ((array) ($access['permissions'] ?? []) as $permission => $effect) {
                    if (str_starts_with((string) $permission, 'timesheets.')) continue;
                    if (($effect['allowed'] ?? false) === true) { $granted = true; break; }
                }
                // A Timesheets-authorized actor may have an Employees Clients
                // service-entry flag but ZERO Clients business grants. The
                // Timesheets legacy upper-bound check must still be readable,
                // without exposing any Clients employee directory or capability.
                // Other Clients routes remain denied for that actor.
                if (!$granted) {
                    if (!$timesheetsPolicyConsumer) {
                        return response()->json(['message' => 'Clients access denied by local policy'], 403);
                    }
                    $request->attributes->set('clients_timesheets_only', true);
                }
                $request->attributes->set('client_contour_access', $access);
                $request->attributes->set('client_contour_verified_at_entitlement', true);
                return $next($request);
            }
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            if (!in_array('service:clients', $roles, true)) {
                if ($request->is('api/permissions/me')
                    && in_array('service:timesheets', $roles, true)) {
                    $request->attributes->set('clients_timesheets_only', true);
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
