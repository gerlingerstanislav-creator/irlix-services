<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('connection_attempts', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('cv_original_name')->nullable();
            $table->string('cv_mime_type')->nullable();
            $table->unsignedBigInteger('cv_size_bytes')->nullable();
            $table->string('cv_storage_path')->nullable();
            $table->timestamp('cv_uploaded_at')->nullable();
            $table->timestamp('cv_sent_at')->nullable();
            $table->date('connection_date')->nullable();
            $table->json('failure_reasons')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::create('attempt_interviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_attempt_id')->constrained('connection_attempts')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->dateTime('scheduled_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->unique(['connection_attempt_id', 'sequence']);
        });

        DB::table('connection_attempts')->where('status', 'Интервью')->update(['status' => 'Интервью назначено']);
        DB::table('connection_attempts')->where('status', 'Закрыта: успех')->update(['status' => 'Закрыт: успех', 'closed_at' => DB::raw('updated_at')]);
        DB::table('connection_attempts')->where('status', 'Закрыта: неудача')->update(['status' => 'Закрыт: неудача', 'closed_at' => DB::raw('updated_at')]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clients_refresh_position_progress() RETURNS trigger AS $$
DECLARE
    target_position bigint;
    required_count integer;
    successful_count integer;
    active_count integer;
BEGIN
    IF TG_OP = 'DELETE' THEN target_position := OLD.position_id; ELSE target_position := NEW.position_id; END IF;
    SELECT quantity INTO required_count FROM positions WHERE id = target_position;
    IF required_count IS NULL THEN IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF; END IF;
    SELECT
        COUNT(*) FILTER (WHERE status = 'Закрыт: успех'),
        COUNT(*) FILTER (WHERE status NOT IN ('Закрыт: успех', 'Закрыт: неудача'))
    INTO successful_count, active_count FROM connection_attempts WHERE position_id = target_position;
    UPDATE positions SET status = CASE
        WHEN successful_count >= required_count THEN 'Закрыта: успех'
        WHEN successful_count > 0 THEN 'Частично закрыта'
        WHEN active_count > 0 THEN 'На рассмотрении'
        ELSE 'Ждёт кандидатов'
    END, updated_at = now()
    WHERE id = target_position AND status <> 'Закрыта: неудача';
    IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('UPDATE connection_attempts SET status = status');
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_interviews');
        Schema::table('connection_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'description', 'cv_original_name', 'cv_mime_type', 'cv_size_bytes', 'cv_storage_path',
                'cv_uploaded_at', 'cv_sent_at', 'connection_date', 'failure_reasons', 'closed_at',
            ]);
        });
    }
};
