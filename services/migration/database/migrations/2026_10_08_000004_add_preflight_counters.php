<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('migration_table_progress', function (Blueprint $t): void {
            $t->unsignedBigInteger('ready_count')->default(0);
            $t->unsignedBigInteger('blocked_count')->default(0);
        });
        Schema::table('migration_table_rows', fn (Blueprint $t) => $t->boolean('ready')->default(false));
    }
    public function down(): void {
        Schema::table('migration_table_progress', fn (Blueprint $t) => $t->dropColumn(['ready_count','blocked_count']));
        Schema::table('migration_table_rows', fn (Blueprint $t) => $t->dropColumn('ready'));
    }
};
