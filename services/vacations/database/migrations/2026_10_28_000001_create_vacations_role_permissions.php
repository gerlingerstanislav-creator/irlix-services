<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vacations_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 120);
            $table->string('permission', 120);
            $table->boolean('allowed')->default(false);
            $table->string('scope', 12)->default('none');
            $table->timestamps();
            $table->unique(['role', 'permission']);
        });
        Schema::create('vacations_permission_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('actor', 160);
            $table->string('role', 120);
            $table->string('permission', 120);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacations_permission_audit');
        Schema::dropIfExists('vacations_role_permissions');
    }
};
