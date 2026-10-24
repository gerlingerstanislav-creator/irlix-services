<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('partners', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('partner_specialists', function (Blueprint $t) {
            // Existing partner specialists remain unassigned until explicitly reconciled.
            $t->foreignId('partner_id')->nullable()->constrained('partners')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->text('comment')->nullable();
            $t->index(['partner_id', 'active']);
        });
    }
    public function down(): void {
        Schema::table('partner_specialists', function (Blueprint $t) {
            $t->dropConstrainedForeignId('partner_id');
            $t->dropIndex(['partner_id', 'active']);
            $t->dropColumn(['active', 'comment']);
        });
        Schema::dropIfExists('partners');
    }
};
