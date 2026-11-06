<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The old bootstrap seeding migration may already have been marked applied
        // before the legacy admin record was introduced. Repair existing databases
        // without resetting any employee, Keycloak link, or audit history.
        DB::transaction(static function (): void {
            DB::table('employees')
                ->where('cooperation_type', 'Системный аккаунт')
                ->where('account_type', '<>', 'system')
                ->update(['account_type' => 'system', 'updated_at' => now()]);

            $admin = DB::table('employees')->where('login', 'admin')->lockForUpdate()->first();
            if ($admin) {
                // Preserve the original numeric employee ID and Keycloak subject.
                DB::table('employees')->where('id', $admin->id)
                    ->update(['account_type' => 'system', 'updated_at' => now()]);
                $adminId = (int) $admin->id;
            } else {
                // Compatibility identity anchor only. Credentials remain in Keycloak;
                // no new Keycloak user, password or ordinary employee is created.
                $adminId = (int) DB::table('employees')->insertGetId([
                    'full_name' => 'IRLIX Admin',
                    'first_name' => 'IRLIX',
                    'last_name' => 'Admin',
                    'login' => 'admin',
                    'work_email' => 'admin@irlix.ru',
                    'position' => null,
                    'employment_status' => 'Трудоустроен',
                    'work_format' => 'Удалённо',
                    'cooperation_type' => 'Системный аккаунт',
                    'account_type' => 'system',
                    'identity_status' => 'not_provisioned',
                    'onboarding_email_status' => 'not_requested',
                    'is_remote' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Restore the explicitly protected bootstrap grant, without touching
            // the roles of real employees (including human platform admins).
            DB::table('employee_access_roles')->updateOrInsert(
                ['employee_id' => $adminId, 'role' => 'platform-admin'],
                ['updated_at' => now()]
            );
        });
    }

    public function down(): void
    {
        // Non-destructive rollback: never strip a bootstrap grant or delete an
        // identity anchor and its audit references.
    }
};
