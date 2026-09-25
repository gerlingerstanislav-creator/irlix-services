<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vacations.absence_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('absence_id');
            $table->string('kind', 32)->default('application');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path');
            $table->string('uploaded_by_subject', 128);
            $table->unsignedBigInteger('uploaded_by_employee_id')->nullable();
            $table->timestamps();
            $table->index(['absence_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacations.absence_attachments');
    }
};
