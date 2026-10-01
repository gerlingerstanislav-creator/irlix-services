<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('lead_id')->nullable()->after('client_id');
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE client_requests ALTER COLUMN client_id DROP NOT NULL');
        DB::statement("ALTER TABLE client_requests ADD CONSTRAINT client_requests_single_target CHECK ((client_id IS NOT NULL)::int + (lead_id IS NOT NULL)::int = 1)");

        Schema::create('lead_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by_employee_id')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('name');
            $table->string('inn', 32)->nullable();
            $table->string('full_name', 500)->nullable();
            $table->string('ogrn', 32)->nullable();
            $table->string('kpp', 32)->nullable();
            $table->date('registration_date')->nullable();
            $table->string('okpo', 32)->nullable();
            $table->string('oktmo', 32)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });

        DB::table('leads')->orderBy('id')->each(function ($lead): void {
            DB::table('lead_events')->insert([
                'lead_id' => $lead->id,
                'type' => 'status',
                'status' => $lead->status ?: 'Новый лид',
                'created_by_employee_id' => $lead->responsible_employee_id,
                'created_at' => $lead->created_at ?? now(),
                'updated_at' => $lead->created_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_legal_entities');
        Schema::dropIfExists('lead_events');
        DB::statement('ALTER TABLE client_requests DROP CONSTRAINT IF EXISTS client_requests_single_target');
        DB::table('client_requests')->whereNull('client_id')->delete();
        Schema::table('client_requests', function (Blueprint $table): void {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
        });
        DB::statement('ALTER TABLE client_requests ALTER COLUMN client_id SET NOT NULL');
    }
};
