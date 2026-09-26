<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('technologies', 'alias')) {
            Schema::table('technologies', function (Blueprint $table) {
                $table->string('alias')->nullable()->after('name');
            });
        }

        if (!Schema::hasColumn('technologies', 'parent_id')) {
            Schema::table('technologies', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_id')->nullable()->index()->after('category');
            });
        }

        $technologies = [
            ['name' => '1C', 'alias' => '1C', 'category' => '1S', 'parent' => null],
            ['name' => 'Android', 'alias' => 'Android', 'category' => 'Mobile', 'parent' => null],
            ['name' => 'Business analyst', 'alias' => 'BA', 'category' => 'Analytics', 'parent' => null],
            ['name' => 'C#', 'alias' => 'C#', 'category' => 'Backend', 'parent' => null],
            ['name' => 'Design', 'alias' => 'Design', 'category' => 'Frontend', 'parent' => null],
            ['name' => 'DevOps', 'alias' => 'DevOps', 'category' => 'Backend', 'parent' => null],
            ['name' => 'Flutter', 'alias' => 'Flutter', 'category' => 'Mobile', 'parent' => null],
            ['name' => 'GO', 'alias' => 'GO', 'category' => 'Backend', 'parent' => null],
            ['name' => 'JS', 'alias' => 'Js', 'category' => 'Frontend', 'parent' => null],
            ['name' => 'Node.js', 'alias' => 'Node', 'category' => 'Frontend', 'parent' => 'JS'],
            ['name' => 'React.js', 'alias' => 'React', 'category' => 'Frontend', 'parent' => 'JS'],
            ['name' => 'Vue.js', 'alias' => 'Vue', 'category' => 'Frontend', 'parent' => 'JS'],
            ['name' => 'Java', 'alias' => 'Java', 'category' => 'Backend', 'parent' => null],
            ['name' => 'Spring', 'alias' => 'Spring', 'category' => 'Backend', 'parent' => 'Java'],
            ['name' => 'ML', 'alias' => 'ML', 'category' => null, 'parent' => null],
            ['name' => 'Computer vision', 'alias' => 'CV', 'category' => null, 'parent' => 'ML'],
            ['name' => 'NLP', 'alias' => 'NLP', 'category' => null, 'parent' => 'ML'],
            ['name' => 'PHP', 'alias' => 'Php', 'category' => 'Backend', 'parent' => null],
            ['name' => 'Laravel', 'alias' => 'Laravel', 'category' => 'Backend', 'parent' => 'PHP'],
            ['name' => 'Symfony', 'alias' => 'Symfony', 'category' => 'Backend', 'parent' => 'PHP'],
            ['name' => 'Product owner', 'alias' => 'PO', 'category' => null, 'parent' => null],
            ['name' => 'Project manager', 'alias' => 'PM', 'category' => null, 'parent' => null],
            ['name' => 'Python', 'alias' => 'Python', 'category' => 'Backend', 'parent' => null],
            ['name' => 'Django', 'alias' => 'Django', 'category' => 'Backend', 'parent' => 'Python'],
            ['name' => 'FastAPI', 'alias' => 'FastAPI', 'category' => 'Backend', 'parent' => 'Python'],
            ['name' => 'QA', 'alias' => 'QA', 'category' => 'QA', 'parent' => null],
            ['name' => 'QAA', 'alias' => 'QAA', 'category' => 'QA', 'parent' => 'QA'],
            ['name' => 'QAM', 'alias' => 'QAM', 'category' => 'QA', 'parent' => 'QA'],
            ['name' => 'QAMM', 'alias' => 'QAMM', 'category' => 'QA', 'parent' => 'QA'],
            ['name' => 'System analyst', 'alias' => 'SA', 'category' => 'Analytics', 'parent' => null],
            ['name' => 'iOS', 'alias' => 'iOS', 'category' => 'Mobile', 'parent' => null],
        ];

        foreach ($technologies as $technology) {
            DB::table('technologies')->updateOrInsert(
                ['name' => $technology['name']],
                [
                    'alias' => $technology['alias'],
                    'category' => $technology['category'],
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
                ],
            );
        }

        $ids = DB::table('technologies')->pluck('id', 'name');
        foreach ($technologies as $technology) {
            DB::table('technologies')
                ->where('name', $technology['name'])
                ->update([
                    'parent_id' => $technology['parent'] === null ? null : ($ids[$technology['parent']] ?? null),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('technologies', 'parent_id')) {
            Schema::table('technologies', function (Blueprint $table) {
                $table->dropColumn('parent_id');
            });
        }

        if (Schema::hasColumn('technologies', 'alias')) {
            Schema::table('technologies', function (Blueprint $table) {
                $table->dropColumn('alias');
            });
        }
    }
};
