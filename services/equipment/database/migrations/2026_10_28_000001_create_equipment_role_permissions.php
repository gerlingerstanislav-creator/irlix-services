<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('equipment_role_permissions', function (Blueprint $t): void {
            $t->id(); $t->string('role',120); $t->string('permission',100);
            $t->boolean('allowed')->default(false); $t->string('scope',12)->default('none');
            $t->timestamps(); $t->unique(['role','permission']);
        });
        Schema::create('equipment_permission_audit', function (Blueprint $t): void {
            $t->id(); $t->string('actor',160); $t->string('role',120);
            $t->string('permission',100); $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('equipment_permission_audit');
        Schema::dropIfExists('equipment_role_permissions');
    }
};
