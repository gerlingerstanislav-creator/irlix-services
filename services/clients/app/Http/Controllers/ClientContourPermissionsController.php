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
        abort_unless($access['platform_admin'], 403, 'Настройки разрешений доступны только администратору платформы.');
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
            if (!str_starts_with($permission, 'timesheets.')) {
                $matrix['platform-tester'][$permission] = ['allowed' => false, 'scope' => 'none', 'locked' => true];
            }
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
        abort_unless($access['platform_admin'], 403, 'Изменять разрешения может только администратор платформы.');
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_values(array_diff(array_keys(ClientContourAccess::ROLE_LABELS), ['platform-admin']))),],
            'permission' => ['required', 'string', Rule::in(array_keys(ClientContourAccess::PERMISSION_LABELS))],
            'allowed' => ['required', 'boolean'],
            'scope' => ['required', 'string', Rule::in(['none', 'own', 'team', 'all'])],
        ]);
        if ($data['role'] === 'platform-tester' && !str_starts_with($data['permission'], 'timesheets.')) {
            return response()->json(['message' => 'Доступ тестировщика платформы к сервису клиентов закрыт и не настраивается через матрицу.'], 422);
        }
        if (!$data['allowed']) $data['scope'] = 'none';
        if ($data['allowed'] && $data['scope'] === 'none') return response()->json(['message' => 'Для разрешённого действия укажите область данных.'], 422);

        $inheritedFrom = match ($data['role']) {
            'accounting-head' => ['account-manager', 'sales-manager'],
            'client-service-head' => ['account-manager'],
            'sales-head' => ['sales-manager'],
            default => [],
        };
        $inheritedRows = DB::table('client_contour_permissions')->whereIn('role', $inheritedFrom)->where('permission', $data['permission'])->where('allowed', true)->get();
        if ($inheritedRows->isNotEmpty()) {
            $rank = ['none' => 0, 'own' => 1, 'team' => 2, 'all' => 3];
            $minimum = $data['role'] === 'client-service-head' ? 'all' : 'team';
            if (!$data['allowed'] || ($rank[$data['scope']] ?? 0) < $rank[$minimum]) {
                return response()->json(['message' => 'Нельзя выдать руководителю права уже, чем у наследуемой роли.'], 422);
            }
        }

        $affected = [[$data['role'], $data['scope']]];
        if ($data['role'] === 'account-manager') $affected = array_merge($affected, [['accounting-head', $data['allowed'] ? ($data['scope'] === 'all' ? 'all' : 'team') : 'none'], ['client-service-head', $data['allowed'] ? 'all' : 'none']]);
        if ($data['role'] === 'sales-manager') $affected = array_merge($affected, [['sales-head', $data['allowed'] ? ($data['scope'] === 'all' ? 'all' : 'team') : 'none'], ['accounting-head', $data['allowed'] ? ($data['scope'] === 'all' ? 'all' : 'team') : 'none']]);

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
