<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy Specialists belongs to production managers only. A signed/current
 * head:* identity is necessary but not sufficient: Employees admission and
 * Specialists' own live production-tree object scope still apply.
 * This template means verified management, NOT arbitrary org membership.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach ([
                'specialists.profiles.view'=>'team',
                'specialists.profiles.manage'=>'team',
                'specialists.catalog.view'=>'all',
                'specialists.catalog.manage'=>'all',
            ] as $permission=>$scope) {
                DB::table('specialists_role_permissions')->insertOrIgnore([
                    'role'=>'head:*','permission'=>$permission,
                    'allowed'=>true,'scope'=>$scope,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Administrator-edited V2 rows must survive a code rollback.
    }
};
