<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_purge_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('filename');
            $table->timestamps();
            $table->unique(['employee_id', 'filename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_purge_files');
    }
};
