<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns only the current actor's effective Recruitment permissions.
 * Keycloak bearer signature and current Employees roles were validated by
 * authentication middleware before this endpoint is reached.
 */
final class RecruitmentAccessController
{
    public function me(Request $request): JsonResponse
    {
        $local = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json(['data' => [
            'mode' => $local ? 'local' : 'legacy',
            'permissions' => $local
                ? (array) $request->attributes->get('recruitment_local_permissions', [])
                : [],
        ]]);
    }
}
