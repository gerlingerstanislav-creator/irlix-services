<?php

namespace App\Http\Controllers;

use App\Services\VacationsRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated effective authorization for the current actor only.
 * Keycloak and live Employees role freshness are checked in middleware;
 * business grants never come from Employees' global services flags.
 */
final class VacationsAccessController
{
    public function me(Request $request): JsonResponse
    {
        $local = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

        $permissions = $local
            ? $request->attributes->get('vacations_local_permissions')
            : [];
        abort_if($local && !is_array($permissions), 503,
            'Vacations authorization has not been evaluated');

        return response()->json(['data' => [
            'mode' => $local ? 'local' : 'legacy',
            'permissions' => $permissions,
            'viewable' => $local
                ? VacationsRoleAuthorization::canOpenWorkspace($permissions)
                : true,
        ]])->header('Cache-Control', 'no-store');
    }
}
