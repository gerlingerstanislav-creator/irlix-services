<?php

namespace App\Services;

use App\Models\ClientContourPermission;
use App\Models\ClientContourPermissionAudit;
use App\Support\ClientContourAccess;
use Illuminate\Support\Facades\DB;

final class ClientContourPermissionService
{
    public function matrix(): array
    {
        $rows = ClientContourPermission::query()->orderBy('role')->orderBy('permission')->get();
        $matrix = [];
        foreach ($rows as $row) {
            if ($row->role === 'platform-tester') continue;
            $matrix[$row->role][$row->permission] = [
                'allowed' => (bool) $row->allowed,
                'scope' => $row->scope,
                'denied' => (bool) ($row->denied ?? false),
            ];
        }
        $matrix['platform-admin'] = [];
        foreach (array_keys(ClientContourAccess::PERMISSION_LABELS) as $permission) {
            $matrix['platform-admin'][$permission] = [
                'allowed' => true, 'scope' => 'all', 'locked' => true,
            ];
        }
        return $matrix;
    }

    public function update(array $data, ?int $actorId): void
    {
        DB::transaction(static function () use ($data, $actorId): void {
            $row = ClientContourPermission::query()
                ->where(['role' => $data['role'], 'permission' => $data['permission']])
                ->lockForUpdate()->first();
            $before = [
                'allowed' => (bool) ($row?->allowed ?? false),
                'scope' => $row?->scope ?? 'none',
                'denied' => (bool) ($row?->denied ?? false),
            ];
            $after = [
                'allowed' => $data['allowed'],
                'scope' => $data['scope'],
                'denied' => (bool) ($data['denied'] ?? false),
            ];
            ClientContourPermission::query()->updateOrInsert(
                ['role' => $data['role'], 'permission' => $data['permission']],
                [...$after, 'updated_at' => now(), ...(!$row ? ['created_at' => now()] : [])]
            );
            ClientContourPermissionAudit::query()->insert([
                'actor_employee_id' => $actorId,
                'role' => $data['role'], 'permission' => $data['permission'],
                'before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }
}
