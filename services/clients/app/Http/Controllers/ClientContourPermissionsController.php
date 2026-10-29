<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use App\Services\ClientContourPermissionService;
use Illuminate\Validation\Rule;

class ClientContourPermissionsController extends Controller
{
    public function me(Request $request, ClientContourAccess $resolver)
    {
        $access = $request->attributes->get('client_contour_access') ?? $resolver->resolve($request);
        if ($request->attributes->get('clients_timesheets_only') === true) {
            $permissions = array_filter((array) ($access['permissions'] ?? []),
                static fn($name) => str_starts_with((string) $name, 'timesheets.'),
                ARRAY_FILTER_USE_KEY);
            return response()->json(['data'=>[
                'permissions'=>$permissions,
                // Signed Timesheets consumer independently verifies Employees
                // service entry and can only preserve historical OWN access.
                'legacy_timesheets_implicit_mine_view' =>
                    ($access['legacy_timesheets_implicit_mine_view'] ?? false) === true,
            ]]);
        }
        return response()->json(['data'=>$access]);
    }

    public function index(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.view') && $resolver->scope($access, 'permissions.view') === 'all'), 403, 'Недостаточно прав на просмотр матрицы разрешений.');
        $matrix = app(ClientContourPermissionService::class)->matrix();
        $labels = ClientContourAccess::ROLE_LABELS;
        foreach (array_keys($matrix) as $role) {
            if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role)
                && $role !== 'special:platform-admin') $labels[$role] = $role;
        }
        return response()->json(['data' => [
            'can_manage' => $access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'),
            'roles' => collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'locked' => $key === 'platform-admin'])->values(),
            'permissions' => collect(ClientContourAccess::PERMISSION_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'matrix' => $matrix,
            'scopes' => [['key' => 'none', 'label' => 'Не назначено'], ['key' => 'own', 'label' => 'Только свои'], ['key' => 'team', 'label' => 'Своё направление'], ['key' => 'all', 'label' => 'Все']],
        ]]);
    }

    public function update(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'), 403, 'Недостаточно прав на изменение матрицы разрешений.');
        $data = $request->validate([
            'role' => ['required', 'string', 'max:120', static function ($attribute, $value, $fail): void {
                if (is_string($value) && $value !== 'platform-admin' && $value !== 'special:platform-admin'
                    && (array_key_exists($value, ClientContourAccess::ROLE_LABELS)
                        || preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value))) return;
                $fail('Недопустимый идентификатор роли.');
            }],
            'permission' => ['required', 'string', Rule::in(array_keys(ClientContourAccess::PERMISSION_LABELS))],
            'allowed' => ['required', 'boolean'],
            'denied' => ['sometimes', 'boolean'],
            'scope' => ['required', 'string', Rule::in(['none', 'own', 'team', 'all'])],
        ]);
        if (str_starts_with($data['permission'], 'permissions.') && $data['allowed'] && $data['scope'] !== 'all') return response()->json(['message' => 'Для управления глобальной матрицей разрешён только scope all.'], 422);
        if ($data['permission'] === 'specialists.view' && $data['allowed'] && $data['scope'] !== 'all') return response()->json(['message' => 'Общий календарь содержит даты отсутствий по всей компании. Для него разрешён только scope all.'], 422);
        if (($data['denied'] ?? false) && $data['allowed']) {
            return response()->json(['message' => 'Нельзя одновременно разрешить и запретить действие.'], 422);
        }
        if (!$data['allowed']) $data['scope'] = 'none';
        if ($data['allowed'] && $data['scope'] === 'none') return response()->json(['message' => 'Для разрешённого действия укажите область данных.'], 422);

        app(ClientContourPermissionService::class)->update(
            $data, isset($access['employee']['id']) ? (int) $access['employee']['id'] : null
        );
        return $this->index($request, $resolver);
    }
}
