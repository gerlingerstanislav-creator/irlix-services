<?php

namespace App\Http\Controllers;

use App\Services\SpecialistsRoleAuthorization;
use App\Services\SpecialistsPermissionManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

final class SpecialistsPermissionsController
{
    private function admin(Request $request): void
    {
        $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
        abort_unless(in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true), 403);
        abort_if($request->header('X-Irlix-Role-Preview') !== null, 403);
        $ids = array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        abort_unless(count($ids) === 1, 403);
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        abort_unless(is_string($token) && $token !== '', 401);
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $source = Http::withToken($token)->acceptJson()->timeout(5)->get($base.'/access/me');
        } catch (\Throwable) {
            abort(503, 'Current administrator assignment unavailable');
        }
        abort_unless($source->successful() && is_array($source->json('data')), 503);
        $current = (array) $source->json('data');
        abort_unless((int) ($current['employee_id'] ?? 0) === (int) substr($ids[0], strlen('employee-id:'))
            && in_array('platform-admin', (array) ($current['roles'] ?? []), true), 403);
    }

    /**
     * Read-only canonical Employees role catalog, shared with Clients.
     * Only the role labels/tree are returned; the directory's people data
     * must never be exposed through a Specialists permissions response.
     */
    private function roleCatalog(Request $request): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->get($base.'/clients-directory');
        } catch (\Throwable) {
            abort(503, 'Employees role catalog unavailable');
        }
        abort_unless($response->successful(), 503, 'Employees role catalog unavailable');
        $data = (array) $response->json('data', []);
        $catalog = array_filter((array) ($data['role_catalog'] ?? []), static fn ($label, $role) =>
            is_string($label) && is_string($role)
                && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1,
            ARRAY_FILTER_USE_BOTH);
        abort_if($catalog === [], 503, 'Employees role catalog unavailable');
        return ['labels' => $catalog, 'tree' => (array) ($data['role_tree'] ?? [])];
    }

    public function index(Request $request, SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);
        $source = $this->roleCatalog($request);
        $state = $service->matrix();
        $labels = $source['labels'];
        $labelFor = static function (string $role) use ($labels): string {
            if ($role === 'head:*') return 'Руководители: базовое правило (legacy)';
            if (isset($labels[$role])) return $labels[$role];
            [$type, $name] = array_pad(explode(':', $role, 2), 2, '');
            return match ($type) {
                'org' => 'Подразделение: '.$name,
                'head' => 'Руководитель: '.$name,
                default => 'Роль: '.$role,
            };
        };
        $permissionLabels = [
            'specialists.profiles.view' => 'Специалисты: просмотр',
            'specialists.profiles.manage' => 'Специалисты: изменение',
            'specialists.catalog.view' => 'Технологии: просмотр',
            'specialists.catalog.manage' => 'Технологии: изменение',
        ];

        return response()->json(['data' => [
            'can_manage' => true,
            'enabled' => filter_var(env('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'roles' => collect($state['roles'])->map(static fn ($role) => [
                'key' => $role, 'label' => $labelFor($role), 'locked' => $role === 'head:*',
            ])->values(),
            'available_roles' => collect($labels)->map(static fn ($label, $role) => [
                'key' => $role, 'label' => $labelFor($role),
                'locked' => $role === 'special:platform-admin',
            ])->values(),
            'role_tree' => $source['tree'],
            'permissions' => collect($state['permissions'])->map(static fn ($key) => [
                'key' => $key, 'label' => $permissionLabels[$key] ?? $key,
            ])->values(),
            'scopes' => [
                ['key' => 'inherit', 'label' => 'Не назначено'],
                ['key' => 'team', 'label' => 'Команда'],
                ['key' => 'all', 'label' => 'Все'],
                ['key' => 'deny', 'label' => 'Явный запрет'],
            ],
            'matrix' => $state['matrix'],
        ]]);
    }

    private function rules(bool $withEffect): array
    {
        $rules = [
            'role' => ['required', 'string', 'max:120', 'regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission' => ['required', Rule::in(SpecialistsRoleAuthorization::PERMISSIONS)],
        ];
        if ($withEffect) {
            $rules['allowed'] = ['required', 'boolean'];
            $rules['scope'] = ['required', Rule::in(['none', 'team', 'all'])];
        }
        return $rules;
    }

    public function addRole(Request $request, SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);
        $catalog = $this->roleCatalog($request);
        $input = $request->validate(['role' => ['required', 'string', Rule::in(array_keys($catalog['labels']))]]);
        abort_if($input['role'] === 'special:platform-admin', 422, 'Role is locked');
        abort_unless($service->addRole($input['role']), 409, 'Role already selected');
        return $this->index($request, $service);
    }

    public function removeRole(Request $request, SpecialistsPermissionManagementService $service, string $role)
    {
        $this->admin($request);
        abort_unless(preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1
            && $role !== 'special:platform-admin', 422, 'Role is locked');
        abort_unless($service->removeRole($role,
            (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown')), 404);
        return response()->noContent();
    }

    public function update(Request $request, SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);
        $input = $request->validate($this->rules(true));
        abort_unless($service->isEditableRole($input['role']), 422, 'Role is not configured');
        abort_if(($input['allowed'] && $input['scope'] === 'none')
            || (!$input['allowed'] && $input['scope'] !== 'none')
            || (str_starts_with($input['permission'], 'specialists.catalog.') && $input['allowed']
                && $input['scope'] !== 'all'), 422, 'Invalid scope');
        $service->update($input, (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'));
        return $this->index($request, $service);
    }

    public function remove(Request $request, SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);
        $input = $request->validate($this->rules(false));
        abort_unless($service->isEditableRole($input['role']), 422, 'Role is not configured');
        $service->remove($input['role'], $input['permission'],
            (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'));
        return $this->index($request, $service);
    }
}
