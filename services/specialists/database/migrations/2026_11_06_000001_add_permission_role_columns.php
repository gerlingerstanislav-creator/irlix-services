<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A displayed role is distinct from its grants: adding a column must not
 * create an authorization grant, and removing one must survive a restart.
 * Inactive rows are deny-by-default tombstones for retired head:* inheritance.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('specialists_permission_roles', static function (Blueprint $table): void {
            $table->string('role', 120)->primary();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::transaction(static function (): void {
            $template = DB::table('specialists_role_permissions')
                ->where('role', 'head:*')->get();
            $existing = DB::table('specialists_role_permissions')
                ->where('role', '<>', 'head:*')->distinct()->pluck('role');

            foreach ($existing as $role) {
                DB::table('specialists_permission_roles')->insert([
                    'role' => $role, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // Moving legacy exact-head rules into explicit columns must
                // preserve untouched template grants and explicit denies.
                if (!str_starts_with($role, 'head:')) continue;
                foreach ($template as $row) {
                    DB::table('specialists_role_permissions')->insertOrIgnore([
                        'role' => $role, 'permission' => $row->permission,
                        'allowed' => $row->allowed, 'scope' => $row->scope,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialists_permission_roles');
    }
};
