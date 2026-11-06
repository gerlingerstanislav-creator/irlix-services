<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared catalog mutation authorization. The catalog spans ALL specialists:
 * managing a personal/team profile does not permit global deletes or replacements.
 */
final class SpecialistsCatalogAuthorizer
{
    public function __construct(private readonly EmployeesDirectory $directory) {}

    public function assertCanManage(Request $request): array
    {
        abort_if($request->header('X-Irlix-Role-Preview') !== null, 403);
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $policy = (array) $request->attributes->get('specialists_local_permissions', []);
            abort_unless(($policy['specialists.catalog.manage']['allowed'] ?? false) === true
                && ($policy['specialists.catalog.manage']['scope'] ?? '') === 'all',
                403, 'Только явно разрешённая роль может изменять каталог технологий.');
            return (array) $request->attributes->get('identity', []);
        }
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $identity = (array) $request->attributes->get('identity', []);
            $roles = (array) ($identity['realm_roles'] ?? []);
            abort_unless(in_array('platform-admin', $roles, true)
                && in_array('service:specialists', $roles, true), 403,
                'Редактировать каталог технологий может только администратор платформы.');
            return $identity;
        }

        try {
            $directory = $this->directory->resolve($request);
        } catch (\DomainException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(8)->get("{$base}/access/me");
        } catch (\Throwable) {
            abort(503, 'Не удалось проверить права администратора платформы.');
        }
        if (!$response->successful() || !is_array($response->json('data.roles'))) {
            abort(503, 'Не удалось проверить права администратора платформы.');
        }
        abort_unless(in_array('platform-admin', $response->json('data.roles'), true), 403,
            'Редактировать каталог технологий может только администратор платформы.');
        return (array) ($directory['actor'] ?? []);
    }
}
