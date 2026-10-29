<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing Vacations rules remain enforced by VacationsAccess and the
 * object-specific controllers. This is a scoped permission ceiling only.
 * Never assign managers access to confidential document content or deletion.
 */
return new class extends Migration {
    public function up(): void
    {
        $grants = [
            'head:*' => [
                'vacations.mine.view'=>'team',
                'vacations.mine.manage'=>'team',
                'vacations.department.view'=>'team',
                'vacations.department.manage'=>'team',
                'vacations.manage.view'=>'team',
                'vacations.approvals.view'=>'team',
                'vacations.approvals.manage'=>'team',
                'vacations.documents.manage'=>'team',
                'vacations.history.view'=>'team',
                'vacations.client-calendar.view'=>'all',
            ],
            'org:hr' => [
                'vacations.mine.view'=>'all',
                'vacations.department.view'=>'all',
                'vacations.manage.view'=>'all',
                'vacations.history.view'=>'all',
            ],
            'special:personnel-officer' => [
                'vacations.mine.view'=>'all',
                'vacations.mine.manage'=>'all',
                'vacations.department.view'=>'all',
                'vacations.department.manage'=>'all',
                'vacations.manage.view'=>'all',
                'vacations.approvals.view'=>'all',
                'vacations.approvals.manage'=>'all',
                'vacations.documents.view'=>'all',
                'vacations.documents.manage'=>'all',
                'vacations.history.view'=>'all',
            ],
            'org:client-service' => [
                'vacations.client-calendar.view'=>'all',
            ],
        ];
        DB::transaction(static function () use ($grants): void {
            foreach ($grants as $role => $permissions) {
                foreach ($permissions as $permission => $scope) {
                    DB::table('vacations_role_permissions')->insertOrIgnore([
                        'role'=>$role,'permission'=>$permission,'allowed'=>true,
                        'scope'=>$scope,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve user-maintained policy rows.
    }
};
