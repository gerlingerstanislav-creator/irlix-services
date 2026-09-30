<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ProductionDirection
{
    public static function resolve(Request $request, array $data): array
    {
        $access = (array) $request->attributes->get('client_contour_access', []);
        $directions = collect($access['production_directions'] ?? []);
        $id = (int) ($data['direction_department_id'] ?? 0);
        if (!$id && !empty($data['direction'])) {
            $matches = $directions->where('name', $data['direction']);
            if ($matches->count() === 1) $id = (int) $matches->first()['id'];
        }
        $department = $directions->first(fn ($row) => (int) $row['id'] === $id);
        abort_unless($department, 422, 'Выберите производственное направление из списка.');
        return [...$data, 'direction_department_id' => $id, 'direction' => $department['name'], 'responsible_rn_employee_id' => self::responsibleId($request, [...$data, 'direction_department_id' => $id])];
    }

    public static function responsibleId(Request $request, array $data): ?int
    {
        $access = (array) $request->attributes->get('client_contour_access', []);
        $directions = collect($access['production_directions'] ?? []);
        $selected = (int) ($data['responsible_rn_employee_id'] ?? 0);
        if ($selected) {
            abort_unless($directions->contains(fn ($row) => (int) ($row['manager_id'] ?? 0) === $selected), 422, 'Выберите руководителя производственного направления.');
            return $selected;
        }
        $department = $directions->first(fn ($row) => (int) $row['id'] === self::id($data, $access));
        return !empty($department['manager_id']) ? (int) $department['manager_id'] : null;
    }

    public static function id(array $position, array $access): int
    {
        return (int) ($position['direction_department_id']
            ?? ($access['legacy_direction_ids'][$position['direction'] ?? ''] ?? 0));
    }
}
