<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\RecruitmentRoleAuthorization;

/** Existing Recruitment had a whole-workspace admission for HR or personnel
 * officer. This explicitly carries the old salary/offer access instead of
 * accidentally granting it to unrelated org labels. Current Employees service
 * admission remains a required ceiling at request time.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach (['org:hr','special:personnel-officer'] as $role) {
                foreach (RecruitmentRoleAuthorization::PERMISSIONS as $permission) {
                    DB::table('recruitment_role_permissions')->insertOrIgnore([
                        'role'=>$role,'permission'=>$permission,
                        'allowed'=>true,'scope'=>'all',
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // No destructive rollback of manually edited matrix rows.
    }
};
