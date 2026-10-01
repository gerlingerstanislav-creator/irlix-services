<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('connection_attempt_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_attempt_id')->constrained('connection_attempts')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['connection_attempt_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        // Preserve only historical facts that are known exactly. Do not invent intermediate timestamps.
        DB::table('connection_attempts')->orderBy('id')->get()->each(function ($attempt): void {
            DB::table('connection_attempt_events')->insert([
                'connection_attempt_id' => $attempt->id,
                'event_type' => 'created',
                'from_status' => null,
                'to_status' => 'Новая',
                'actor_employee_id' => null,
                'metadata' => null,
                'occurred_at' => $attempt->created_at,
            ]);
            if ($attempt->closed_at && in_array($attempt->status, ['Закрыт: успех', 'Закрыт: неудача'], true)) {
                DB::table('connection_attempt_events')->insert([
                    'connection_attempt_id' => $attempt->id,
                    'event_type' => 'status_changed',
                    'from_status' => $attempt->status === 'Закрыт: неудача' ? $attempt->closed_from_status : null,
                    'to_status' => $attempt->status,
                    'actor_employee_id' => null,
                    'metadata' => null,
                    'occurred_at' => $attempt->closed_at,
                ]);
            }
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clients_record_attempt_status_event() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO connection_attempt_events
            (connection_attempt_id, event_type, from_status, to_status, actor_employee_id, metadata, occurred_at)
        VALUES
            (NEW.id, 'created', NULL, NEW.status, NULL, NULL, COALESCE(NEW.created_at, now()));
        RETURN NEW;
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO connection_attempt_events
            (connection_attempt_id, event_type, from_status, to_status, actor_employee_id, metadata, occurred_at)
        VALUES
            (NEW.id, 'status_changed', OLD.status, NEW.status, NULL, NULL, now());
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS connection_attempt_status_event_trigger ON connection_attempts;
CREATE TRIGGER connection_attempt_status_event_trigger
AFTER INSERT OR UPDATE OF status ON connection_attempts
FOR EACH ROW EXECUTE FUNCTION clients_record_attempt_status_event();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS connection_attempt_status_event_trigger ON connection_attempts;
DROP FUNCTION IF EXISTS clients_record_attempt_status_event();
SQL);
        Schema::dropIfExists('connection_attempt_events');
    }
};
