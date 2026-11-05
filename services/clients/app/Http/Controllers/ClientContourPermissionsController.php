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
        return response()->json(['data'=>$access]);
    }

    public function index(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.view') && $resolver->scope($access, 'permissions.view') === 'all'), 403, 'Недостаточно прав на просмотр матрицы разрешений.');
        $matrix = app(ClientContourPermissionService::class)->matrix();
        $directory = (array) $request->attributes->get('clients_employees_directory', []);
        $catalog = array_filter((array) ($directory['role_catalog'] ?? []),
            static fn ($label, $role): bool => is_string($label)
                && ClientContourPermissionService::isCanonicalRole((string) $role),
            ARRAY_FILTER_USE_BOTH);
        // The table is a view of existing canonical V2 matrix roles.
        // Never show old aliases or silently create a new Keycloak role.
        // Display functional business names without introducing retired
        // duplicate role keys. Editing still targets the signed V2 identity.
        $businessLabels = [
            'org:sales' => 'Сейлз-менеджер',
            'org:account-management' => 'Аккаунт-менеджер',
            'org:client-service' => 'Клиентская служба',
            'head:sales' => 'Руководитель продаж',
            'head:account-management' => 'Руководитель аккаунтинга',
            'head:client-service' => 'Руководитель клиентской службы',
            'special:platform-admin' => 'Администратор платформы',
        ];
        $labels = [];
        foreach (array_keys($matrix) as $role) {
            $labels[$role] = $businessLabels[$role] ?? $catalog[$role] ?? $role;
        }
        return response()->json(['data' => [
            'can_manage' => $access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'),
            'roles' => collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'locked' => $key === 'special:platform-admin'])->values(),
            'available_roles' => collect($catalog)->map(fn ($label, $key) => [
                'key' => $key, 'label' => $businessLabels[$key] ?? $label,
                'locked' => $key === 'special:platform-admin',
            ])->values(),
            'permissions' => collect(ClientContourAccess::PERMISSION_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'matrix' => $matrix,
            'scopes' => [['key' => 'none', 'label' => 'Не назначено'], ['key' => 'own', 'label' => 'Только свои'], ['key' => 'team', 'label' => 'Своё направление'], ['key' => 'all', 'label' => 'Все']],
        ]]);
    }

    public function addRole(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'), 403);
        $directory = (array) $request->attributes->get('clients_employees_directory', []);
        $catalog = (array) ($directory['role_catalog'] ?? []);
        abort_if($catalog === [], 503, 'Каталог ролей Employees недоступен.');
        $data = $request->validate(['role' => ['required', 'string', Rule::in(array_keys($catalog))]]);
        abort_unless(ClientContourPermissionService::isCanonicalRole($data['role'])
            && $data['role'] !== 'special:platform-admin', 422, 'Эту роль нельзя добавлять.');

        $created = app(ClientContourPermissionService::class)->addRole(
            $data['role'], isset($access['employee']['id']) ? (int) $access['employee']['id'] : null
        );
        abort_unless($created, 409, 'Роль уже добавлена.');
        return $this->index($request, $resolver);
    }

    public function removeRole(Request $request, ClientContourAccess $resolver, string $role)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'), 403);
        abort_unless(ClientContourPermissionService::isCanonicalRole($role)
            && $role !== 'special:platform-admin', 422, 'Эту роль нельзя удалить.');
        $removed = app(ClientContourPermissionService::class)->removeRole(
            $role, isset($access['employee']['id']) ? (int) $access['employee']['id'] : null
        );
        abort_unless($removed, 404, 'Роль не найдена в матрице.');
        return response()->noContent();
    }

    public function update(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'), 403, 'Недостаточно прав на изменение матрицы разрешений.');
        $data = $request->validate([
            'role' => ['required', 'string', 'max:120', static function ($attribute, $value, $fail): void {
                if (is_string($value) && app(ClientContourPermissionService::class)->isEditableRole($value)) return;
                $fail('Роль отсутствует в действующей матрице Clients или заблокирована.');
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
