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
        abort_unless($resolver->allows($access, 'permissions.view'), 403, 'Недостаточно прав на просмотр матрицы разрешений.');
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
            'roles' => collect(ClientContourAccess::ROLE_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'locked' => $key === 'platform-admin'])->values(),
            'permissions' => collect(ClientContourAccess::PERMISSION_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'matrix' => $matrix,
            'scopes' => [['key' => 'none', 'label' => 'Нет доступа'], ['key' => 'own', 'label' => 'Только свои'], ['key' => 'team', 'label' => 'Своё направление'], ['key' => 'all', 'label' => 'Все']],
        ]]);
    }

    public function update(Request $request, ClientContourAccess $resolver)
    {
        $access = $resolver->resolve($request);
        abort_unless($resolver->allows($access, 'permissions.manage'), 403, 'Недостаточно прав на изменение матрицы разрешений.');
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_values(array_diff(array_keys(ClientContourAccess::ROLE_LABELS), ['platform-admin']))),],
            'permission' => ['required', 'string', Rule::in(array_keys(ClientContourAccess::PERMISSION_LABELS))],
            'allowed' => ['required', 'boolean'],
            'scope' => ['required', 'string', Rule::in(['none', 'own', 'team', 'all'])],
        ]);
        if (!$data['allowed']) $data['scope'] = 'none';
        if ($data['allowed'] && $data['scope'] === 'none') return response()->json(['message' => 'Для разрешённого действия укажите область данных.'], 422);

        // Every non-admin role is independently editable; no implicit grant propagation.
        $affected = [[$data['role'], $data['scope']]];

        DB::transaction(function () use ($affected, $data, $access): void {
            $rank = ['none' => 0, 'own' => 1, 'team' => 2, 'all' => 3];
            foreach ($affected as [$role, $scope]) {
                $row = DB::table('client_contour_permissions')->where(['role' => $role, 'permission' => $data['permission']])->lockForUpdate()->first();
                if (!$row) continue;
                $allowed = $role === $data['role'] ? $data['allowed'] : $scope !== 'none';
                $nextScope = $role === $data['role'] ? $data['scope'] : (($rank[$scope] ?? 0) > ($rank[$row->scope] ?? 0) ? $scope : $row->scope);
                $before = ['allowed' => (bool) $row->allowed, 'scope' => $row->scope];
                $after = ['allowed' => $allowed, 'scope' => $allowed ? $nextScope : 'none'];
                DB::table('client_contour_permissions')->where('id', $row->id)->update([...$after, 'updated_at' => now()]);
                DB::table('client_contour_permission_audit')->insert([
                    'actor_employee_id' => $access['employee']['id'] ?? null, 'role' => $role, 'permission' => $data['permission'],
                    'before' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after' => json_encode($after, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
                ]);
            }
        });
        return $this->index($request, $resolver);
    }
}
