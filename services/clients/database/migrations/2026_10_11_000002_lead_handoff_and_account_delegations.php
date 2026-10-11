<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One request can have a historical lead and an active client.
        DB::statement('ALTER TABLE client_requests DROP CONSTRAINT IF EXISTS client_requests_single_target');
        DB::statement('ALTER TABLE client_requests ADD CONSTRAINT client_requests_target_present CHECK (lead_id IS NOT NULL OR client_id IS NOT NULL)');

        Schema::create('client_account_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('from_employee_id');
            $table->unsignedBigInteger('to_employee_id');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedBigInteger('created_by_employee_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'starts_at', 'ends_at']);
            $table->index(['to_employee_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE client_account_delegations ADD CONSTRAINT valid_delegation_dates CHECK (ends_at >= starts_at)');
        DB::statement('ALTER TABLE client_account_delegations ADD CONSTRAINT distinct_account_managers CHECK (from_employee_id <> to_employee_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_delegations');
        DB::statement('ALTER TABLE client_requests DROP CONSTRAINT IF EXISTS client_requests_target_present');
        // Historical lead/client references cannot be discarded on rollback.
        // Operators must remove conflicting dual bindings before restoring the previous XOR.
        DB::statement("ALTER TABLE client_requests ADD CONSTRAINT client_requests_single_target CHECK ((client_id IS NOT NULL)::int + (lead_id IS NOT NULL)::int = 1)");
    }
};
