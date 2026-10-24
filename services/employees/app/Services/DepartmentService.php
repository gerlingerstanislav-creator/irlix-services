<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\StaffPosition;

final class DepartmentService
{
    public function deleteEmpty(int $department): array
    {
        $current = Department::query()->whereKey($department)->first();
        if (!$current) return ['status' => 404, 'message' => 'Подразделение не найдено.'];

        $employeeCount = Employee::query()->where('department_id', $department)->count();
        $childCount = Department::query()->where('parent_id', $department)->count();
        $positionCount = StaffPosition::query()->where('direction_id', $department)->count();
        if ($employeeCount > 0 || $childCount > 0 || $positionCount > 0) {
            return [
                'status' => 409,
                'message' => 'Нельзя удалить непустое подразделение. Сначала перенесите сотрудников, дочерние подразделения и должности штатного расписания.',
                'meta' => [
                    'employee_count' => $employeeCount,
                    'child_department_count' => $childCount,
                    'staff_position_count' => $positionCount,
                ],
            ];
        }
        $current->delete();

        return ['status' => 200, 'data' => ['id' => $department, 'name' => $current->name, 'deleted' => true]];
    }
}
