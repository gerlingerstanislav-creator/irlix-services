<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->nullable(); // Employees namespace
            $t->foreignId('partner_specialist_id')->nullable()->constrained('partner_specialists')->restrictOnDelete();
            $t->string('specialist_name');
            $t->timestamps();
            $t->unique(['client_id', 'employee_id']);
            $t->unique(['client_id', 'partner_specialist_id']);
        });

        Schema::table('member_terms', function (Blueprint $t) {
            $t->foreignId('client_member_id')->nullable()->constrained('client_members')->restrictOnDelete();
            $t->index(['client_member_id', 'valid_from']);
        });

        // Old project_members/terms are retained for a subsequent reconciled
        // cross-service data migration; new ClientMembers use this same terms
        // table with an independent client_member_id and no project dependency.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE member_terms ALTER COLUMN project_member_id DROP NOT NULL');
            DB::statement("ALTER TABLE client_members ADD CONSTRAINT client_members_identity_xor CHECK ((employee_id IS NOT NULL) <> (partner_specialist_id IS NOT NULL))");
            DB::statement("ALTER TABLE member_terms ADD CONSTRAINT member_terms_parent_xor CHECK ((project_member_id IS NOT NULL) <> (client_member_id IS NOT NULL))");
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE member_terms ADD CONSTRAINT member_terms_client_no_overlap EXCLUDE USING gist (client_member_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (client_member_id IS NOT NULL)");
        }

        Schema::create('project_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_member_id')->constrained('client_members')->restrictOnDelete();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->timestamps();
            $t->index(['client_member_id', 'project_id']);
        });
        Schema::create('client_member_feedbacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_member_id')->constrained('client_members')->restrictOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $t->text('text');
            $t->string('created_by_username')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE project_assignments ADD CONSTRAINT project_assignments_valid_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)");
            DB::statement("ALTER TABLE project_assignments ADD CONSTRAINT project_assignments_no_overlap EXCLUDE USING gist (client_member_id WITH =, project_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION client_project_assignment_guard() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM client_members AS m
        JOIN projects AS p ON p.client_id = m.client_id
        WHERE m.id = NEW.client_member_id AND p.id = NEW.project_id
    ) THEN RAISE EXCEPTION 'Project and ClientMember belong to different clients';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER project_assignment_client_guard
BEFORE INSERT OR UPDATE OF client_member_id, project_id ON project_assignments
FOR EACH ROW EXECUTE FUNCTION client_project_assignment_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('client_members')->exists() && DB::table('client_members')->count() > 0)
            throw new RuntimeException('ClientMember identity/history requires explicit reconciliation before rollback.');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS project_assignment_client_guard ON project_assignments');
            DB::statement('DROP FUNCTION IF EXISTS client_project_assignment_guard()');
            DB::statement('ALTER TABLE member_terms DROP CONSTRAINT IF EXISTS member_terms_client_no_overlap');
            DB::statement('ALTER TABLE member_terms DROP CONSTRAINT IF EXISTS member_terms_parent_xor');
        }
        Schema::dropIfExists('client_member_feedbacks');
        Schema::dropIfExists('project_assignments');
        Schema::table('member_terms', function (Blueprint $t) { $t->dropConstrainedForeignId('client_member_id'); });
        if (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE member_terms ALTER COLUMN project_member_id SET NOT NULL');
        Schema::dropIfExists('client_members');
    }
};
