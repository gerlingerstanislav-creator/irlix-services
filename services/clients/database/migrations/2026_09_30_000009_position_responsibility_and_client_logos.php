<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('positions', fn (Blueprint $t) => $t->unsignedBigInteger('responsible_rn_employee_id')->nullable());
        Schema::table('clients', function (Blueprint $t): void { $t->string('logo_storage_path')->nullable(); $t->string('logo_mime_type')->nullable(); });
        Schema::table('connection_attempts', fn (Blueprint $t) => $t->dropColumn('proposed_rate'));
    }
    public function down(): void {
        Schema::table('positions', fn (Blueprint $t) => $t->dropColumn('responsible_rn_employee_id'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['logo_storage_path','logo_mime_type']));
        Schema::table('connection_attempts', fn (Blueprint $t) => $t->decimal('proposed_rate',12,2)->nullable());
    }
};
