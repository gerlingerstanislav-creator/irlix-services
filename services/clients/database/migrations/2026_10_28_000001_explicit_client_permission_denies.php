<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_contour_permissions', function (Blueprint $table): void {
            // Existing "allowed=false" rows are ordinary unassigned grants,
            // NOT explicit denials. This new flag is opt-in and defaults false.
            $table->boolean('denied')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('client_contour_permissions', function (Blueprint $table): void {
            $table->dropColumn('denied');
        });
    }
};
