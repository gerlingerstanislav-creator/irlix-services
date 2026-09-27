<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_member_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_member_id')->constrained('project_members')->cascadeOnDelete();
            $table->text('text');
            $table->string('created_by_username', 255)->nullable();
            $table->timestamps();

            $table->index(['project_member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_member_feedbacks');
    }
};
