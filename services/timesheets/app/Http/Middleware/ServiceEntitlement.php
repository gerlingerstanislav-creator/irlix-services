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
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            if (filter_var(env('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                if ($request->header('X-Irlix-Role-Preview') !== null) {
                    return response()->json(['message' => 'Role preview cannot authorize Timesheets'], 403);
                }
                $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
                try {
                    $live = Http::withToken((string) $token)->acceptJson()
                        ->timeout(5)->get($employeesBase.'/access/me');
                } catch (\Throwable) {
                    return response()->json(['message' => 'Current Timesheets identities unavailable'], 503);
                }
                if (!$live->successful() || !is_array($live->json('data'))) {
                    return response()->json(['message' => 'Current Timesheets identities unavailable'], 503);
                }
                if (!\App\Services\TimesheetsRoleAuthorization::currentIdentityMatches(
                    $roles, (array) $live->json('data')
                )) return response()->json(['message' => 'Timesheets roles changed'], 403);
                try {
                    $permissions = app(\App\Services\TimesheetsRoleAuthorization::class)->forVerifiedRoles($roles);
                } catch (\Throwable) {
                    return response()->json(['message' => 'Timesheets authorization unavailable'], 503);
                }
                $path = $request->path();
                if ($path === 'api/permissions/me') {
                    $request->attributes->set('timesheets_local_permissions', $permissions);
                    return $next($request);
                }
                $required = match (true) {
                    $path === 'api/workspace' => 'timesheets.mine.view',
                    in_array($path, ['api/entries','api/confirm','api/unconfirm'], true) => 'timesheets.mine.manage',
                    $path === 'api/management' => 'timesheets.management.view',
                    str_starts_with($path, 'api/management/') => 'timesheets.management.manage',
                    $path === 'api/analytics' => 'timesheets.analytics.view',
                    $path === 'api/audit' => 'timesheets.audit.view',
                    $path === 'api/attention' => 'timesheets.mine.view',
                    $path === 'api/partner-hours' && $request->isMethod('GET') => 'timesheets.partner-hours.view',
                    $path === 'api/partner-hours' && $request->isMethod('PUT') => 'timesheets.partner-hours.manage',
                    $path === 'api/partner-hours/confirm' && $request->isMethod('POST') => 'timesheets.partner-hours.manage',
                    $path === 'api/partner-hours/final-approval' && $request->isMethod('POST') => 'timesheets.partner-hours.approve',
                    in_array($path, ['api/permissions'], true) => null,
                    default => null,
                };
                $admin = in_array('platform-admin', $roles, true)
                    || in_array('special:platform-admin', $roles, true);
                if ($required === null && !$admin) {
                    return response()->json(['message' => 'Timesheets operation denied'], 403);
                }
                if ($required !== null && !($permissions[$required]['allowed'] ?? false)) {
                    return response()->json(['message' => 'Timesheets access denied'], 403);
                }
                $request->attributes->set('timesheets_local_permissions', $permissions);
                return $next($request);
            }
            if (!in_array('service:timesheets', $roles, true)) {
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
        if ($result->json('data.services.timesheets') !== true) {
            return response()->json(['message' => 'Service access denied'], 403);
        }
        return $next($request);
    }
}
