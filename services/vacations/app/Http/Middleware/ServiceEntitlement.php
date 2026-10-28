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

        if (filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && !$request->is('api/permissions')) {
            // Conservative incremental cutover: admit only existing own-only
            // operations whose controllers already enforce employee ownership.
            // All broad, approval, team and sensitive attachment operations
            // stay 503 until their exact object authorization is complete.
            if (!filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                return response()->json(['message' => 'Verified JWT mode required for Vacations V2'], 503);
            }
            if ($request->header('X-Irlix-Role-Preview') !== null) {
                return response()->json(['message' => 'Role preview cannot authorize Vacations'], 403);
            }
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            $path = $request->path();
            $method = $request->method();
            $personal = $method === 'GET' && in_array($path, [
                'api/me', 'api/absences', 'api/absences/occupied',
                'api/workspace', 'api/history', 'api/production-calendar',
            ], true);
            $personal = $personal || $method === 'POST' && $path === 'api/absences'
                || in_array($method, ['GET','PATCH'], true) &&
                    preg_match('#^api/absences/[1-9][0-9]*(?:/history)?$#D', $path) === 1
                    && ($method !== 'PATCH' || !str_ends_with($path, '/history'));
            $ownSubmission = $method === 'POST'
                && preg_match('#^api/absences/[1-9][0-9]*/submit$#D', $path) === 1;
            $ownDocument = ($method === 'GET'
                    && preg_match('#^api/absences/[1-9][0-9]*/attachments(?:/[1-9][0-9]*/download)?$#D', $path) === 1)
                || ($method === 'POST'
                    && preg_match('#^api/absences/[1-9][0-9]*/attachments$#D', $path) === 1)
                || ($method === 'DELETE'
                    && preg_match('#^api/absences/[1-9][0-9]*/attachments/[1-9][0-9]*$#D', $path) === 1);
            $personal = $personal || $ownSubmission || $ownDocument;
            if (!$personal) {
                return response()->json(['message' => 'Vacations V2 object authorization not yet verified'], 503);
            }
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
            try {
                $permissions = app(\App\Services\VacationsRoleAuthorization::class)->forSignedRoles($roles);
            } catch (\Throwable) {
                return response()->json(['message' => 'Vacations policy unavailable'], 503);
            }
            $required = \App\Services\VacationsRoleAuthorization::requiredPermission($method, $path);
            $permission = (array) ($permissions[$required ?? ''] ?? []);
            if (($permission['allowed'] ?? false) !== true
                || !in_array(($permission['scope'] ?? 'none'), ['own','team','all'], true)) {
                return response()->json(['message' => 'Vacations permission denied'], 403);
            }
            // For document and submit routes, ownership is independently
            // verified from the Vacations database BEFORE domain workflows.
            // A matrix grant never authorizes another employee's attachments.
            if ($ownDocument || $ownSubmission) {
                $id = (int) (explode('/', $path)[2] ?? 0);
                try {
                    $owner = \App\Models\Absence::query()->whereKey($id)->value('employee_id');
                } catch (\Throwable) {
                    return response()->json(['message' => 'Vacations object ownership unavailable'], 503);
                }
                if ($owner === null) {
                    return response()->json(['message' => 'Absence not found'], 404);
                }
                if ((int) $owner !== (int) $fresh->json('data.employee_id')) {
                    return response()->json(['message' => 'Other employees require object-scope authorization'], 403);
                }
            }
            // Even a team/all grant never changes ownership of these personal
            // endpoints. The domain controller remains the final authority.
            if ($path === 'api/absences/occupied') {
                $actorId = (int) ($fresh->json('data.employee_id') ?? 0);
                $targetId = (int) ($request->query('employee_id') ?: $actorId);
                if ($targetId !== $actorId || $actorId <= 0) {
                    return response()->json(['message' => 'Other employees require object-scope authorization'], 403);
                }
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
