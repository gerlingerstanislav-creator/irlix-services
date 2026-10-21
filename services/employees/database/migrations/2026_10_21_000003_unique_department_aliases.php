<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Null remains permitted for pre-existing unclassified departments;
        // distinct non-null aliases must be unique even under simultaneous
        // admin writes. Fail loudly if historical duplicates need resolution:
        // never silently reassign rights or rename live departments.
        $duplicate = DB::table('departments')->select('alias')
            ->whereNotNull('alias')->groupBy('alias')->havingRaw('count(*) > 1')->first();
        if ($duplicate) {
            throw new RuntimeException('Duplicate department aliases detected: resolve before applying the authorization-key unique index.');
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS departments_alias_unique ON departments (alias)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS departments_alias_unique');
    }
};
