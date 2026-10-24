<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('reporting_periods',function(Blueprint $t){
            $t->string('timesheet_freeze_token',128)->nullable();
            $t->string('timesheet_revision',64)->nullable();
            $t->text('timesheet_snapshot')->nullable();
        });
    }
    public function down(): void {
        throw new RuntimeException('Reporting snapshots and freeze tokens require explicit reconciliation before rollback.');
    }
};
