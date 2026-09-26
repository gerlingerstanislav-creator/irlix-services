<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS projects_one_default_per_client ON projects (client_id) WHERE is_default = true');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS contact_relations_active_unique ON contact_relations (contact_person_id, entity_type, entity_id) WHERE active = true");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clients_refresh_position_progress() RETURNS trigger AS $$
DECLARE
    target_position bigint;
    required_count integer;
    successful_count integer;
    active_count integer;
BEGIN
    target_position := COALESCE(NEW.position_id, OLD.position_id);
    SELECT quantity INTO required_count FROM positions WHERE id = target_position;
    IF required_count IS NULL THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    SELECT
        COUNT(*) FILTER (WHERE status = 'Закрыта: успех'),
        COUNT(*) FILTER (WHERE status NOT IN ('Закрыта: успех', 'Закрыта: неудача'))
    INTO successful_count, active_count
    FROM connection_attempts
    WHERE position_id = target_position;

    UPDATE positions
    SET status = CASE
        WHEN successful_count >= required_count THEN 'Закрыта: успех'
        WHEN successful_count > 0 THEN 'Частично закрыта'
        WHEN active_count > 0 THEN 'На рассмотрении'
        ELSE 'Ждёт кандидатов'
    END,
    updated_at = now()
    WHERE id = target_position
      AND status <> 'Закрыта: неудача';

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_clients_refresh_position_progress ON connection_attempts;
CREATE TRIGGER trg_clients_refresh_position_progress
AFTER INSERT OR UPDATE OF status OR DELETE ON connection_attempts
FOR EACH ROW EXECUTE FUNCTION clients_refresh_position_progress();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_clients_refresh_position_progress ON connection_attempts');
        DB::unprepared('DROP FUNCTION IF EXISTS clients_refresh_position_progress()');
        DB::statement('DROP INDEX IF EXISTS contact_relations_active_unique');
        DB::statement('DROP INDEX IF EXISTS projects_one_default_per_client');
    }
};
