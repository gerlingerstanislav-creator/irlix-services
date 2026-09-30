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
        return [...$data, 'direction_department_id' => $id, 'direction' => $department['name']];
    }

    public static function id(array $position, array $access): int
    {
        return (int) ($position['direction_department_id']
            ?? ($access['legacy_direction_ids'][$position['direction'] ?? ''] ?? 0));
    }
}
