<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;

/**
 * Technical identity projection. The legacy numeric ID is retained solely
 * for compatibility with Keycloak synchronization and cross-service audit.
 */
final class SystemAccountService
{
    public function list(): array
    {
        $accounts = Employee::query()->system()
            ->orderBy('login')
            ->get(['id', 'login', 'full_name', 'identity_status', 'keycloak_user_id']);
        $roles = EmployeeAccessRole::query()
            ->whereIn('employee_id', $accounts->pluck('id'))
            ->orderBy('role')->get(['employee_id', 'role'])
            ->groupBy('employee_id');

        return $accounts->map(static fn (Employee $account): array => [
            'id' => (int) $account->id,
            'login' => (string) $account->login,
            'name' => (string) $account->full_name,
            'account_type' => Employee::TYPE_SYSTEM,
            'identity_status' => (string) ($account->identity_status ?? 'unknown'),
            'identity_linked' => !empty($account->keycloak_user_id),
            'protected' => $account->login === 'admin',
            'roles' => $roles->get($account->id, collect())->pluck('role')->values()->all(),
        ])->all();
    }
}
