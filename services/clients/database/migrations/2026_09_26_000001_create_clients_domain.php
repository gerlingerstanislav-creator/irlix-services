<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clients',function(Blueprint $t){$t->id();$t->string('name');$t->string('type')->nullable();$t->string('sector')->nullable();$t->unsignedBigInteger('sales_employee_id')->nullable();$t->unsignedBigInteger('account_employee_id');$t->timestamps();});
        Schema::create('projects',function(Blueprint $t){$t->id();$t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();$t->string('name')->nullable();$t->boolean('is_default')->default(false);$t->timestamps();$t->index(['client_id','is_default']);});
        Schema::create('project_members',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();$t->unsignedBigInteger('specialist_id');$t->string('specialist_name');$t->unsignedBigInteger('source_attempt_id')->nullable();$t->timestamps();$t->unique(['project_id','specialist_id']);});
        Schema::create('member_terms',function(Blueprint $t){$t->id();$t->foreignId('project_member_id')->constrained('project_members')->cascadeOnDelete();$t->string('technology');$t->string('level');$t->decimal('hourly_rate',12,2);$t->decimal('hours_per_day',5,2);$t->date('valid_from');$t->date('valid_to')->nullable();$t->timestamps();$t->index(['project_member_id','valid_from']);});
        Schema::create('leads',function(Blueprint $t){$t->id();$t->string('name');$t->string('source')->nullable();$t->unsignedBigInteger('responsible_employee_id');$t->string('status');$t->unsignedBigInteger('converted_client_id')->nullable();$t->timestamps();});
        Schema::create('contact_people',function(Blueprint $t){$t->id();$t->string('full_name');$t->string('position')->nullable();$t->string('phone')->nullable();$t->string('email')->nullable();$t->timestamps();});
        Schema::create('contact_relations',function(Blueprint $t){$t->id();$t->foreignId('contact_person_id')->constrained('contact_people')->cascadeOnDelete();$t->string('entity_type');$t->unsignedBigInteger('entity_id');$t->string('relation_role')->nullable();$t->text('comment')->nullable();$t->boolean('active')->default(true);$t->timestamps();$t->index(['entity_type','entity_id']);});
        Schema::create('client_requests',function(Blueprint $t){$t->id();$t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();$t->string('title');$t->text('description')->nullable();$t->unsignedBigInteger('responsible_employee_id');$t->date('deadline')->nullable();$t->string('status')->default('Новый');$t->timestamps();});
        Schema::create('positions',function(Blueprint $t){$t->id();$t->foreignId('client_request_id')->constrained('client_requests')->cascadeOnDelete();$t->string('direction')->nullable();$t->string('technology');$t->string('level');$t->unsignedInteger('quantity')->default(1);$t->text('description')->nullable();$t->string('status')->default('Ждёт кандидатов');$t->timestamps();});
        Schema::create('connection_attempts',function(Blueprint $t){$t->id();$t->foreignId('position_id')->constrained('positions')->cascadeOnDelete();$t->unsignedBigInteger('specialist_id');$t->string('specialist_name');$t->unsignedBigInteger('responsible_employee_id')->nullable();$t->date('control_date')->nullable();$t->decimal('proposed_rate',12,2)->nullable();$t->string('status')->default('Новая');$t->timestamps();});
        Schema::create('reporting_periods',function(Blueprint $t){$t->id();$t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();$t->date('period_start');$t->date('period_end');$t->string('status')->default('Новый');$t->decimal('confirmed_hours',12,2)->nullable();$t->decimal('confirmed_amount',14,2)->nullable();$t->timestamps();$t->unique(['client_id','period_start','period_end']);});
    }
    public function down(): void { foreach(['reporting_periods','connection_attempts','positions','client_requests','contact_relations','contact_people','leads','member_terms','project_members','projects','clients'] as $table) Schema::dropIfExists($table); }
};
