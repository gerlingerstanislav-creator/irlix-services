<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('financial_accounts', function (Blueprint $t) {
            $t->id(); $t->string('name', 160); $t->string('type', 12)->default('bank');
            $t->char('currency', 3)->default('RUB'); $t->date('opening_on');
            $t->decimal('opening_balance', 17, 2); $t->boolean('active')->default(true);
            $t->string('created_by', 150); $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('client_id')->index(); $t->unsignedBigInteger('reporting_period_id')->index();
            $t->string('number', 80)->nullable(); $t->string('payer', 220)->nullable();
            $t->date('issued_on')->nullable(); $t->date('due_on')->nullable();
            $t->char('currency', 3)->default('RUB'); $t->decimal('amount',17,2);
            $t->decimal('vat_amount',17,2)->default(0); $t->string('document_status',16)->default('draft');
            $t->text('comment')->nullable(); $t->integer('version')->default(1);
            $t->string('created_by',150); $t->timestamps();
        });
        Schema::create('cash_transactions', function (Blueprint $t) {
            $t->id(); $t->foreignId('financial_account_id')->constrained('financial_accounts');
            $t->date('occurred_on'); $t->string('direction',8);
            $t->decimal('amount',17,2); $t->string('counterparty',220)->nullable();
            $t->string('reference',150)->nullable(); $t->text('comment')->nullable();
            $t->string('status',16)->default('draft'); $t->string('created_by',150);
            $t->string('confirmed_by',150)->nullable(); $t->timestamp('confirmed_at')->nullable(); $t->timestamps();
            $t->index(['occurred_on','direction','status']);
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id(); $t->foreignId('cash_transaction_id')->constrained('cash_transactions');
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->decimal('amount',17,2); $t->string('created_by',150); $t->timestamps();
            $t->unique(['cash_transaction_id','invoice_id']);
        });
        Schema::create('accruals', function (Blueprint $t) {
            $t->id(); $t->string('recognition_month',7)->index();
            $t->string('kind',36); $t->string('article',120);
            $t->string('cost_center',120)->default('corporate');
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('counterparty',220)->nullable();
            $t->decimal('amount',17,2); $t->char('currency',3)->default('RUB');
            $t->string('status',16)->default('confirmed'); $t->string('created_by',150); $t->timestamps();
        });
        Schema::create('budget_versions', function (Blueprint $t) {
            $t->id(); $t->string('name',160); $t->string('scenario',32);
            $t->string('status',16)->default('draft'); $t->unsignedInteger('revision')->default(1);
            $t->string('created_by',150); $t->string('approved_by',150)->nullable();
            $t->timestamp('approved_at')->nullable(); $t->timestamps();
        });
        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('budget_version_id')->constrained('budget_versions');
            $t->string('month',7); $t->string('cost_center',120);
            $t->string('article',120); $t->decimal('amount',17,2);
            $t->timestamps();
        });
        Schema::create('finance_audit_log', function (Blueprint $t) {
            $t->id(); $t->string('entity_type',48); $t->unsignedBigInteger('entity_id');
            $t->string('action',60); $t->string('actor',150);
            $t->jsonb('details')->nullable(); $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type','entity_id']);
        });
    }
    public function down(): void {
        foreach (['finance_audit_log','budget_lines','budget_versions','accruals','payment_allocations','cash_transactions','invoices','financial_accounts'] as $t) Schema::dropIfExists($t);
    }
};
