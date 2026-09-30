<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_attempts', function (Blueprint $table): void {
            $table->boolean('is_external')->default(false);
            $table->string('closed_from_status')->nullable();
        });
        DB::statement('ALTER TABLE connection_attempts ALTER COLUMN specialist_id DROP NOT NULL');
        // Reconstruct historical failures only when their stored facts identify the stage.
        DB::statement(<<<'SQL'
UPDATE connection_attempts a SET closed_from_status = CASE
    WHEN connection_date IS NOT NULL THEN 'Ожидает подключения'
    WHEN EXISTS (SELECT 1 FROM attempt_interviews i WHERE i.connection_attempt_id = a.id AND i.completed_at IS NULL) THEN 'Интервью назначено'
    WHEN EXISTS (SELECT 1 FROM attempt_interviews i WHERE i.connection_attempt_id = a.id) THEN 'Интервью пройдено'
    WHEN cv_sent_at IS NOT NULL THEN 'CV отправлено'
    ELSE NULL
END WHERE status = 'Закрыт: неудача'
SQL);
    }

    public function down(): void
    {
        // Keep nullable specialist_id: external attempts must not be removed on rollback.
        Schema::table('connection_attempts', fn (Blueprint $table) => $table->dropColumn(['is_external', 'closed_from_status']));
    }
};
