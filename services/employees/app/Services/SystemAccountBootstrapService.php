<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent operational repair for the protected bootstrap identity.
 *
 * Versioned migrations run only once, but legacy data imports and incomplete
 * bootstrap environments can leave the technical identity unclassified later.
 * Run after Employees migrations on every Employees backend deployment.
 *
 * This is an Employees-local compatibility anchor. Credentials remain in
 * Keycloak; no identity, audit history, or human platform-admin record is
 * deleted or changed.
 */
final class SystemAccountBootstrapService
{
    public function reconcile(): void
    {
        DB::transaction(static function (): void {
            // Reclassify existing technical identities; never infer a person's
            // account type from the platform-admin role alone.
            Employee::query()
                ->where('cooperation_type', 'Системный аккаунт')
                ->where('account_type', '<>', Employee::TYPE_SYSTEM)
                ->update(['account_type' => Employee::TYPE_SYSTEM]);

            $admin = Employee::query()->where('login', 'admin')->lockForUpdate()->first();
            if (!$admin) {
                $admin = Employee::query()->firstOrCreate(
                    ['login' => 'admin'],
                    [
                        'full_name' => 'IRLIX Admin',
                        'first_name' => 'IRLIX',
                        'last_name' => 'Admin',
                        'work_email' => 'admin@irlix.ru',
                        'position' => null,
                        'employment_status' => 'Трудоустроен',
                        'work_format' => 'Удалённо',
                        'cooperation_type' => 'Системный аккаунт',
                        'account_type' => Employee::TYPE_SYSTEM,
                        'identity_status' => 'not_provisioned',
                        'onboarding_email_status' => 'not_requested',
                        'is_remote' => true,
                    ]
                );
            }

            if ($admin->account_type !== Employee::TYPE_SYSTEM) {
                $admin->account_type = Employee::TYPE_SYSTEM;
                $admin->save();
            }

            EmployeeAccessRole::query()->firstOrCreate([
                'employee_id' => $admin->id,
                'role' => 'platform-admin',
            ]);
        });
    }
}
