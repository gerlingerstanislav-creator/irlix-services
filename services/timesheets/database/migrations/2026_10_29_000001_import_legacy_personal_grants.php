<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Employees allows every active employee personal timesheets; Clients owns
 * the separate manager/account matrix and must be imported independently.
 * These baseline OWN grants do not promote service:* or org ancestors to
 * management, analytics, partner hours or audit.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach (['timesheets.mine.view','timesheets.mine.manage'] as $permission) {
                DB::table('timesheets_role_permissions')->insertOrIgnore([
                    'role'=>'org:irlix','permission'=>$permission,
                    'allowed'=>true,'scope'=>'own',
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // No destructive rollback of manually edited matrix rows.
    }
};
