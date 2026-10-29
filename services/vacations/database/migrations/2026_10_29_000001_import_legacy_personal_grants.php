<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Reproduce the existing personal absence/document baseline for employees.
 * Team and historical registers require their own object-proof migration.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach ([
                'vacations.mine.view','vacations.mine.manage',
                'vacations.documents.view','vacations.documents.manage',
                'vacations.history.view',
                'vacations.approvals.view','vacations.approvals.manage',
            ] as $permission) {
                DB::table('vacations_role_permissions')->insertOrIgnore([
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
