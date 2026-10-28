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
            if (filter_var(env('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                if ($request->header('X-Irlix-Role-Preview') !== null) {
                    return response()->json(['message'=>'Role preview cannot authorize Recruitment'],403);
                }
                $base=rtrim((string)env('EMPLOYEES_URL','http://employees:8000/api'),'/');
                try {
                    $live=Http::withToken((string)$token)->acceptJson()->timeout(5)
                        ->get($base.'/access/me');
                } catch (\Throwable) {
                    return response()->json(['message'=>'Recruitment identity freshness unavailable'],503);
                }
                if (!$live->successful() || !is_array($live->json('data'))) {
                    return response()->json(['message'=>'Recruitment identity freshness unavailable'],503);
                }
                if (!\App\Services\RecruitmentRoleAuthorization::currentIdentityMatches(
                    $roles,(array)$live->json('data'))) {
                    return response()->json(['message'=>'Recruitment roles changed'],403);
                }
                try {
                    $permissions=app(\App\Services\RecruitmentRoleAuthorization::class)->forVerifiedRoles($roles);
                } catch (\Throwable) {
                    return response()->json(['message'=>'Recruitment permission policy unavailable'],503);
                }
                $path=$request->path();
                $administrator=in_array('platform-admin',$roles,true)
                    ||in_array('special:platform-admin',$roles,true);
                if ($path==='api/role-permissions') {
                    if (!$administrator) return response()->json(['message'=>'Forbidden'],403);
                } else {
                    $required=\App\Services\RecruitmentRoleAuthorization::requiredPermission(
                        $request->method(),$path);
                    if ($required===null || !($permissions[$required]??false)) {
                        return response()->json(['message'=>'Recruitment permission denied'],403);
                    }
                }
                $request->attributes->set('recruitment_local_permissions',$permissions);
                return $next($request);
            }
            if (!in_array('service:recruitment', $roles, true)) {
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
        if ($result->json('data.services.recruitment') !== true) {
            return response()->json(['message' => 'Service access denied'], 403);
        }
        return $next($request);
    }
}
