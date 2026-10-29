<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy Employees admission: accounting or explicit system-admin.
 * Legacy equipmentCanManage() mirrors view; equipmentCanOperate()
 * additionally requires system-admin (platform-admin handled separately).
 * Manual Employees service-entry denies still apply to every V2 request.
 * Existing user-edited V2 rows are NEVER overwritten.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach (['org:accounting','special:system-admin'] as $role) {
                foreach (['equipment.view','equipment.manage'] as $permission) {
                    DB::table('equipment_role_permissions')->insertOrIgnore([
                        'role'=>$role,'permission'=>$permission,
                        'allowed'=>true,'scope'=>'all',
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            DB::table('equipment_role_permissions')->insertOrIgnore([
                'role'=>'special:system-admin','permission'=>'equipment.operate',
                'allowed'=>true,'scope'=>'all',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
    }

    public function down(): void
    {
        // Non-destructive: do not remove subsequently modified policy.
    }
};
