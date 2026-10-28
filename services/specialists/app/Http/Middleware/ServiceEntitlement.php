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
            if (filter_var(env('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                if ($request->header('X-Irlix-Role-Preview') !== null) {
                    return response()->json(['message'=>'Role preview cannot authorize Specialists'],403);
                }
                $base=rtrim((string)env('EMPLOYEES_URL','http://employees:8000/api'),'/');
                try {
                    $live=Http::withToken((string)$token)->acceptJson()->timeout(5)
                        ->get($base.'/access/me');
                } catch (\Throwable) {
                    return response()->json(['message'=>'Specialists role freshness unavailable'],503);
                }
                if (!$live->successful() || !is_array($live->json('data'))) {
                    return response()->json(['message'=>'Specialists role freshness unavailable'],503);
                }
                if (!\App\Services\SpecialistsRoleAuthorization::currentIdentityMatches(
                    $roles,(array)$live->json('data'))) {
                    return response()->json(['message'=>'Specialists role assignments changed'],403);
                }
                try {
                    $permissions=app(\App\Services\SpecialistsRoleAuthorization::class)->forVerifiedRoles($roles);
                } catch (\Throwable) {
                    return response()->json(['message'=>'Specialists local policy unavailable'],503);
                }
                $path=$request->path();
                $method=$request->method();
                if ($path==='api/permissions') {
                    $hasGrant=false;
                    foreach ($permissions as $permission) {
                        if (($permission['allowed']??false)===true) { $hasGrant=true; break; }
                    }
                    if (!$hasGrant) return response()->json(['message'=>'Specialists access denied'],403);
                    $request->attributes->set('specialists_local_permissions',$permissions);
                    return $next($request);
                }
                $required=match(true){
                    $path==='api/workspace'||($method==='GET'&&preg_match('#^api/people/[0-9]+$#',$path))
                        =>'specialists.profiles.view',
                    $method==='PUT'&&preg_match('#^api/people/[0-9]+$#',$path)
                        =>'specialists.profiles.manage',
                    $method==='GET'&&$path==='api/catalog'=>'specialists.catalog.view',
                    in_array($method,['POST','PUT','DELETE'],true)
                        &&preg_match('#^api/catalog/technologies(?:/[0-9]+)?$#',$path)
                        =>'specialists.catalog.manage',
                    default=>null,
                };
                $admin=in_array('platform-admin',$roles,true)
                    ||in_array('special:platform-admin',$roles,true);
                if ($path==='api/role-permissions') {
                    if (!$admin) return response()->json(['message'=>'Forbidden'],403);
                } elseif ($required===null || !($permissions[$required]['allowed']??false)) {
                    return response()->json(['message'=>'Specialists permission denied'],403);
                }
                $request->attributes->set('specialists_local_permissions',$permissions);
                return $next($request);
            }
            if (!in_array('service:specialists', $roles, true)) {
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
        if ($result->json('data.services.specialists') !== true) {
            return response()->json(['message' => 'Service access denied'], 403);
        }
        return $next($request);
    }
}
