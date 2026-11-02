<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Decision evidence is private Migration metadata, never a target
        // Clients/Timesheets table. Historical policy changes append events;
        // revising a previous decision would destroy the basis of reruns.
        Schema::create('migration_reconciliation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64);
            $table->string('entity_type', 64);
            $table->string('legacy_id', 255);
            $table->string('rule_code', 96);
            $table->unsignedInteger('rule_version');
            $table->string('decision', 48);
            $table->char('source_sha256', 64);
            $table->char('evidence_sha256', 64);
            $table->string('approved_by', 255);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['service','entity_type','legacy_id','rule_code','rule_version'],
                'recon_decision_rule_version_unique');
            $table->index(['service','entity_type','legacy_id'], 'recon_decision_lookup');
        });

        // Migration metadata is currently SQLite. Force append-only semantics
        // at the database boundary, not just in optional application code.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER recon_decision_no_update
                BEFORE UPDATE ON migration_reconciliation_decisions
                BEGIN SELECT RAISE(ABORT, 'Reconciliation decisions are append-only'); END");
            DB::unprepared("CREATE TRIGGER recon_decision_no_delete
                BEFORE DELETE ON migration_reconciliation_decisions
                BEGIN SELECT RAISE(ABORT, 'Reconciliation decisions are append-only'); END");
        }
    }

    public function down(): void
    {
        // An operator-controlled schema rollback can drop its own table.
        // Backup/export requirements apply before rolling metadata backwards.
        Schema::dropIfExists('migration_reconciliation_decisions');
    }
};
