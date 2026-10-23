<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $t) {
            $t->id();
            $t->string('name', 255);
            $t->string('contact_name', 255)->nullable();
            $t->string('phone', 100)->nullable();
            $t->string('email', 255)->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
        });
        // Existing external specialists and imports may not have an identified company.
        // Keep them, show them in an explicit "Без партнёра" group and reconcile later.
        Schema::table('partner_specialists', function (Blueprint $t) {
            $t->foreignId('partner_id')->nullable()->constrained('partners')->restrictOnDelete();
            $t->string('specialization', 255)->nullable();
            $t->text('comment')->nullable();
            $t->index(['partner_id', 'full_name']);
        });
        // A future partner identity will bind to this stable specialist ID, never
        // to an Employees ID. No Keycloak user or external login is created now.
        foreach (['account-manager', 'accounting-head', 'client-service-head', 'sales-manager', 'sales-head'] as $role) {
            foreach (['partners.view', 'partners.manage'] as $permission) {
                $allowed = $permission === 'partners.view' || !in_array($role, ['sales-manager', 'sales-head'], true);
                DB::table('client_contour_permissions')->updateOrInsert(
                    ['role' => $role, 'permission' => $permission],
                    ['allowed' => $allowed, 'scope' => $allowed ? 'all' : 'none', 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // Do not silently orphan business identities.
        if (DB::table('partner_specialists')->whereNotNull('partner_id')->exists())
            throw new RuntimeException('Partner assignments must be reconciled before schema rollback.');
        DB::table('client_contour_permissions')->whereIn('permission', ['partners.view','partners.manage'])->delete();
        Schema::table('partner_specialists', function (Blueprint $t) {
            $t->dropForeign(['partner_id']);
            $t->dropIndex(['partner_id','full_name']);
            $t->dropColumn(['partner_id','specialization','comment']);
        });
        Schema::dropIfExists('partners');
    }
};
