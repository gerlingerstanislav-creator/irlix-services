<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_contour_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('permission');
            $table->boolean('allowed')->default(false);
            $table->string('scope')->default('none');
            $table->timestamps();
            $table->unique(['role', 'permission']);
        });
        Schema::create('client_contour_permission_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->string('role');
            $table->string('permission');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $permissions = [
            'clients.view', 'clients.manage', 'contacts.view', 'contacts.manage',
            'members.view', 'members.manage', 'requests.view', 'requests.manage',
            'positions.view', 'positions.manage', 'attempts.view', 'attempts.manage',
            'leads.view', 'leads.manage', 'reports.view', 'reports.manage',
            'cashflow.view', 'timesheets.mine.view', 'timesheets.management.view',
            'timesheets.management.manage', 'timesheets.analytics.view',
            'timesheets.audit.view', 'permissions.view', 'permissions.manage',
        ];
        $grants = [
            'employee' => ['timesheets.mine.view' => 'own'],
            'account-manager' => [
                'clients.view' => 'own', 'clients.manage' => 'own', 'contacts.view' => 'own', 'contacts.manage' => 'own',
                'members.view' => 'own', 'members.manage' => 'own', 'requests.view' => 'own', 'requests.manage' => 'own',
                'positions.view' => 'own', 'positions.manage' => 'own', 'attempts.view' => 'own', 'attempts.manage' => 'own',
                'reports.view' => 'own', 'reports.manage' => 'own', 'cashflow.view' => 'own',
                'timesheets.management.view' => 'own', 'timesheets.management.manage' => 'own',
            ],
            'accounting-head' => [],
            'client-service-head' => [],
            'sales-manager' => [
                'leads.view' => 'own', 'leads.manage' => 'own', 'requests.view' => 'own', 'requests.manage' => 'own',
                'positions.view' => 'own', 'positions.manage' => 'own', 'attempts.view' => 'own', 'attempts.manage' => 'own',
            ],
            'sales-head' => [],
            'department-manager' => [
                'timesheets.management.view' => 'team', 'timesheets.management.manage' => 'team',
                'timesheets.analytics.view' => 'team', 'timesheets.audit.view' => 'team',
            ],
            'personnel-officer' => [],
            'system-admin' => [],
        ];
        $inherit = [
            'accounting-head' => ['account-manager' => 'team', 'sales-manager' => 'team'],
            'client-service-head' => ['account-manager' => 'all'],
            'sales-head' => ['sales-manager' => 'team'],
        ];
        foreach ($inherit as $role => $parents) {
            foreach ($parents as $parent => $scope) {
                foreach (array_keys($grants[$parent]) as $permission) $grants[$role][$permission] = $scope;
            }
        }
        $now = now();
        foreach ($grants as $role => $roleGrants) {
            foreach ($permissions as $permission) {
                DB::table('client_contour_permissions')->insert([
                    'role' => $role,
                    'permission' => $permission,
                    'allowed' => isset($roleGrants[$permission]),
                    'scope' => $roleGrants[$permission] ?? 'none',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contour_permission_audit');
        Schema::dropIfExists('client_contour_permissions');
    }
};
