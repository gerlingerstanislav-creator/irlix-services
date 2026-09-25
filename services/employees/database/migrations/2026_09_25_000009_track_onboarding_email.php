<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('onboarding_email_sent_at')->nullable()->after('onboarding_email_status');
            $table->text('onboarding_email_error')->nullable()->after('onboarding_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['onboarding_email_sent_at', 'onboarding_email_error']);
        });
    }
};
