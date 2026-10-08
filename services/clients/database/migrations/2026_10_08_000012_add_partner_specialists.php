<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('partner_specialists', function (Blueprint $t) { $t->id(); $t->string('full_name'); $t->timestamps(); });
        Schema::table('project_members', function (Blueprint $t) {
            $t->foreignId('partner_specialist_id')->nullable()->constrained('partner_specialists')->restrictOnDelete();
            $t->unique(['project_id', 'partner_specialist_id']);
        });
        DB::statement('ALTER TABLE project_members ALTER COLUMN specialist_id DROP NOT NULL');
        DB::statement('ALTER TABLE project_members ADD CONSTRAINT project_members_identity_check CHECK ((specialist_id IS NOT NULL AND partner_specialist_id IS NULL) OR (specialist_id IS NULL AND partner_specialist_id IS NOT NULL))');
    }
    public function down(): void {
        // Refuse destructive rollback when partner connections exist.
        if (DB::table('project_members')->whereNotNull('partner_specialist_id')->exists()) throw new RuntimeException('Partner connections require explicit reconciliation before rollback.');
        DB::statement('ALTER TABLE project_members DROP CONSTRAINT project_members_identity_check');
        DB::statement('ALTER TABLE project_members ALTER COLUMN specialist_id SET NOT NULL');
        Schema::table('project_members', function (Blueprint $t) { $t->dropUnique(['project_id', 'partner_specialist_id']); $t->dropConstrainedForeignId('partner_specialist_id'); });
        Schema::dropIfExists('partner_specialists');
    }
};
