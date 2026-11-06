<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Historical ungraded associations become secondary technologies.
        // Existing legacy grade and competencies are retained without guessing
        // which technology should inherit the person's general grade.
        Schema::table('specialist_technologies', static function (Blueprint $table): void {
            $table->string('kind', 12)->default('secondary');
            $table->string('grade', 32)->nullable();
        });
        Schema::create('specialist_timeline_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('type', 40);
            $table->string('title', 255);
            $table->text('text')->nullable();
            $table->unsignedBigInteger('author_employee_id')->nullable();
            $table->string('author', 255)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            // The legacy exact-name UNIQUE constraint does not prevent
            // "React" / " react " collisions under concurrent requests.
            DB::statement('CREATE UNIQUE INDEX technologies_normalized_name_unique ON technologies (LOWER(BTRIM(name)))');
            DB::statement("ALTER TABLE specialist_technologies ADD CONSTRAINT specialist_technologies_kind_check CHECK (kind IN ('primary','secondary'))");
        }
    }
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE specialist_technologies DROP CONSTRAINT IF EXISTS specialist_technologies_kind_check');
            DB::statement('DROP INDEX IF EXISTS technologies_normalized_name_unique');
        }
        Schema::dropIfExists('specialist_timeline_events');
        Schema::table('specialist_technologies', static function (Blueprint $table): void {
            $table->dropColumn(['kind', 'grade']);
        });
    }
};
