<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\SpecialRoles;

final class SpecialRoleService
{
    public function catalogWithMembers(): array
    {
        $data = [];
        foreach (SpecialRoles::catalog() as $role) {
            $members = Employee::query()
                ->from('employees as e')
                ->join('employee_access_roles as r', 'r.employee_id', '=', 'e.id')
                ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
                ->where('r.role', $role['key'])
                ->select([
                    'e.id', 'e.full_name', 'e.login', 'e.work_email', 'e.position',
                    'e.department_id', 'd.name as department_name', 'r.created_at',
                ])
                ->orderBy('e.full_name')
                ->get();
            $data[] = [...$role, 'members' => $members, 'member_count' => $members->count()];
        }
        return $data;
    }

    public function employeeExists(int $employeeId): bool
    {
        return Employee::query()->whereKey($employeeId)->exists();
    }

    public function isSystemAdministrator(int $employeeId): bool
    {
        return Employee::query()->whereKey($employeeId)->where('login', 'admin')->exists();
    }

    public function assign(int $employeeId, string $role): void
    {
        // Atomic upsert retains the unique employee_id + role invariant under concurrent requests.
        EmployeeAccessRole::query()->upsert(
            [['employee_id' => $employeeId, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]],
            ['employee_id', 'role'],
            ['updated_at']
        );
    }

    public function revoke(int $employeeId, string $role): void
    {
        EmployeeAccessRole::query()->where('employee_id', $employeeId)->where('role', $role)->delete();
    }

    public function hasBrandbookRole(int $employeeId): bool
    {
        return EmployeeAccessRole::query()->where('employee_id', $employeeId)
            ->whereIn('role', [SpecialRoles::TopManagement, SpecialRoles::PlatformAdmin])->exists();
    }
}
