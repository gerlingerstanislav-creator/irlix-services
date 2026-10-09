<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;

final class VacationsAccess
{
    public function __construct(private readonly EmployeesDirectory $employees) {}

    public function roles(array $access): array
    {
        return array_values(array_unique(array_map(
            fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))),
            $access['roles'] ?? []
        )));
    }

    public function isAdmin(array $access): bool
    {
        return count(array_intersect(['platform-admin', 'platform-tester'], $this->roles($access))) > 0;
    }

    public function isHr(array $access): bool
    {
        return $this->isAdmin($access) || in_array('hr', $this->roles($access), true);
    }

    public function isPersonnelOfficer(array $access): bool
    {
        return $this->isAdmin($access) || in_array('personnel-officer', $this->roles($access), true);
    }

    public function isManager(array $access): bool
    {
        return $this->isAdmin($access) || in_array('manager', $this->roles($access), true);
    }

    /**
     * Authorization for one active approval. The staff member owning the absence
     * cannot approve it, including via the personnel-officer delegation path.
     * Platform admins retain their separately authorized override.
     */
    public function canApproveTask(array $access, int $actorId, array $task, array $absence, bool $managerInScope = false): bool
    {
        if ($this->isAdmin($access)) return true;
        if ((int) ($absence['employee_id'] ?? 0) === $actorId) return false;
        if ($this->isPersonnelOfficer($access)) return true;
        if (($task['required_role'] ?? null) === 'hr') return false;
        if ((int) ($task['approver_employee_id'] ?? 0) === $actorId) return true;
        return ($task['required_role'] ?? null) === 'manager'
            && $this->isManager($access)
            && $managerInScope;
    }

    public function isElevated(array $access): bool
    {
        return $this->isPersonnelOfficer($access) || $this->isHr($access) || $this->isManager($access);
    }

    public function canAccessEmployee(Request $request, array $access, int $actorEmployeeId, int $targetEmployeeId): bool
    {
        if ($actorEmployeeId === $targetEmployeeId) return true;
        if ($this->isAdmin($access) || $this->isPersonnelOfficer($access) || $this->isHr($access)) return true;
        if (!$this->isManager($access)) return false;

        try {
            $this->employees->employeeApprovalContext($request, $targetEmployeeId);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function assertCanAccessEmployee(Request $request, array $access, int $actorEmployeeId, int $targetEmployeeId): void
    {
        if (!$this->canAccessEmployee($request, $access, $actorEmployeeId, $targetEmployeeId)) {
            throw new DomainException('Недостаточно прав для просмотра этого отсутствия');
        }
    }
}
