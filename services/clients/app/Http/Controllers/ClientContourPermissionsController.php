<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientContourPermissionsController extends Controller
{
    public function me(Request $request, ClientContourAccess $resolver)
    {
        return response()->json(['data' => $resolver->resolve($request)]);
    }

    public function index(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.view') && $resolver->scope($access, 'permissions.view') === 'all'), 403, 'Недостаточно прав на просмотр матрицы разрешений.');
        $rows = DB::table('client_contour_permissions')->orderBy('role')->orderBy('permission')->get();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row->role][$row->permission] = [
                'allowed' => (bool) $row->allowed,
                'scope' => $row->scope,
            ];
        }
        $matrix['platform-admin'] = [];
        foreach (array_keys(ClientContourAccess::PERMISSION_LABELS) as $permission) {
            $matrix['platform-admin'][$permission] = ['allowed' => true, 'scope' => 'all', 'locked' => true];
        }
        return response()->json(['data' => [
            'can_manage' => $access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'),
            'roles' => collect(ClientContourAccess::ROLE_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'locked' => $key === 'platform-admin'])->values(),
            'permissions' => collect(ClientContourAccess::PERMISSION_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'matrix' => $matrix,
            'scopes' => [['key' => 'none', 'label' => 'Нет доступа'], ['key' => 'own', 'label' => 'Только свои'], ['key' => 'team', 'label' => 'Своё направление'], ['key' => 'all', 'label' => 'Все']],
        ]]);
    }

    public function update(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, 'permissions.manage') && $resolver->scope($access, 'permissions.manage') === 'all'), 403, 'Недостаточно прав на изменение матрицы разрешений.');
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_values(array_diff(array_keys(ClientContourAccess::ROLE_LABELS), ['platform-admin']))),],
            'permission' => ['required', 'string', Rule::in(array_keys(ClientContourAccess::PERMISSION_LABELS))],
            'allowed' => ['required', 'boolean'],
            'scope' => ['required', 'string', Rule::in(['none', 'own', 'team', 'all'])],
        ]);
        if (str_starts_with($data['permission'], 'permissions.') && $data['allowed'] && $data['scope'] !== 'all') return response()->json(['message' => 'Для управления глобальной матрицей разрешён только scope all.'], 422);
        if (!$data['allowed']) $data['scope'] = 'none';
        if ($data['allowed'] && $data['scope'] === 'none') return response()->json(['message' => 'Для разрешённого действия укажите область данных.'], 422);

        DB::transaction(function () use ($data, $access): void {
            $row = DB::table('client_contour_permissions')
                ->where(['role' => $data['role'], 'permission' => $data['permission']])
                ->lockForUpdate()->first();
            $before = ['allowed' => (bool) ($row?->allowed ?? false), 'scope' => $row?->scope ?? 'none'];
            $after = ['allowed' => $data['allowed'], 'scope' => $data['scope']];
            DB::table('client_contour_permissions')->updateOrInsert(
                ['role' => $data['role'], 'permission' => $data['permission']],
                [...$after, 'updated_at' => now(), ...(!$row ? ['created_at' => now()] : [])]
            );
            DB::table('client_contour_permission_audit')->insert([
                'actor_employee_id' => $access['employee']['id'] ?? null,
                'role' => $data['role'], 'permission' => $data['permission'],
                'before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
        return $this->index($request, $resolver);
    }
}
