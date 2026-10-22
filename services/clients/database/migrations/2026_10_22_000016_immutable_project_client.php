<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::unprepared(<<<'SQL'
CREATE FUNCTION clients_project_immutable_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.client_id IS DISTINCT FROM OLD.client_id THEN
        RAISE EXCEPTION 'Project ownership is immutable. Create a new project for another client.'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER clients_project_immutable_owner_guard
BEFORE UPDATE OF client_id ON projects
FOR EACH ROW EXECUTE FUNCTION clients_project_immutable_owner();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::unprepared('DROP TRIGGER IF EXISTS clients_project_immutable_owner_guard ON projects');
        DB::unprepared('DROP FUNCTION IF EXISTS clients_project_immutable_owner()');
    }
};
