<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\StaffPosition;

final class DepartmentService
{

    public function list(): \Illuminate\Support\Collection
    {
        return Department::query()->from('departments as d')
            ->leftJoin('departments as parent', 'parent.id', '=', 'd.parent_id')
            ->leftJoin('employees as manager', 'manager.id', '=', 'd.manager_id')
            ->leftJoin('employees as hr', 'hr.id', '=', 'd.hr_id')
            ->select([
                'd.id', 'd.name', 'd.alias', 'd.parent_id', 'parent.name as parent_name',
                'd.manager_id', 'manager.full_name as manager_name',
                'd.hr_id', 'hr.full_name as hr_name', 'd.yandex_id', 'd.ldap_group',
                'd.is_production', 'd.created_at', 'd.updated_at',
            ])
            ->selectSub(fn ($query) => $query->from('employees as department_employee')
                ->selectRaw('COUNT(*)::int')
                ->whereColumn('department_employee.department_id', 'd.id'), 'employee_count')
            ->selectSub(fn ($query) => $query->from('employees as active_employee')
                ->selectRaw('COUNT(*)::int')
                ->whereColumn('active_employee.department_id', 'd.id')
                ->where('active_employee.employment_status', 'Трудоустроен'), 'active_employee_count')
            ->orderBy('d.name')->get()
            ->map(static fn (Department $department): object => (object) $department->getRawOriginal());
    }

    public function exists(int $department): bool
    {
        return Department::query()->whereKey($department)->exists();
    }

    public function getRaw(int $department): ?object
    {
        $row = Department::query()->whereKey($department)->first();
        return $row ? (object) $row->getRawOriginal() : null;
    }

    public function create(array $data): object
    {
        $row = Department::query()->create($this->payload($data));
        return $this->getRaw((int) $row->id);
    }

    public function update(int $department, array $data, ?int $parentId): object
    {
        Department::query()->whereKey($department)->update([
            ...$this->payload($data), 'parent_id' => $parentId,
        ]);
        return $this->getRaw($department);
    }

    public function wouldCycle(int $department, ?int $parentId): bool
    {
        if ($parentId === null) return false;
        if ($department === $parentId) return true;
        $visited = [];
        $current = $parentId;
        while ($current !== null) {
            if (isset($visited[$current]) || $current === $department) return true;
            $visited[$current] = true;
            $next = Department::query()->whereKey($current)->value('parent_id');
            $current = $next === null ? null : (int) $next;
        }
        return false;
    }

    private function payload(array $data): array
    {
        return [
            'name' => $data['name'],
            'alias' => $data['alias'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'hr_id' => $data['hr_id'] ?? null,
            'yandex_id' => $data['yandex_id'] ?? null,
            'ldap_group' => $data['ldap_group'] ?? null,
            'is_production' => (bool) ($data['is_production'] ?? false),
        ];
    }

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
