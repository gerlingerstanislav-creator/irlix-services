<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

final class AuditQueryService
{
    public function search(array $filters): array
    {
        $query = AuditLog::query()->orderByDesc('occurred_at')->orderByDesc('id');

        if ($employeeId = ($filters['employee_id'] ?? null)) {
            $query->where('target_type', 'employee')->where('target_id', (string) ((int) $employeeId));
        }
        if ($action = trim((string) ($filters['action'] ?? ''))) {
            $query->where('action', 'ilike', "%{$action}%");
        }
        if ($actor = trim((string) ($filters['actor'] ?? ''))) {
            $query->where(function ($q) use ($actor) {
                $q->where('actor_name', 'ilike', "%{$actor}%")
                    ->orWhere('actor_sub', 'ilike', "%{$actor}%");
            });
        }
        if ($from = ($filters['from'] ?? null)) $query->where('occurred_at', '>=', $from.' 00:00:00+00');
        if ($to = ($filters['to'] ?? null)) $query->where('occurred_at', '<=', $to.' 23:59:59+00');

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $rows = $query->limit($limit)->get()->map(static function (AuditLog $entry): object {
            // Keep the existing exact SQL serialization contract: Eloquent's
            // implicit date serialization would change created_at/updated_at.
            $row = (object) $entry->getRawOriginal();
            foreach (['before', 'after', 'metadata'] as $field) {
                if (is_string($row->{$field} ?? null)) {
                    $row->{$field} = json_decode($row->{$field}, true);
                }
            }
            return $row;
        });

        return [
            'data' => $rows,
            'meta' => [
                'count' => $rows->count(),
                'limit' => $limit,
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->values(),
            ],
        ];
    }
}
