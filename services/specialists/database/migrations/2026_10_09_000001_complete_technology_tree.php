<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Public technology taxonomy only; target IDs are allocated by Specialists.
        $catalog = [
            ['name' => 'System analyst', 'alias' => 'SA', 'parent' => null],
            ['name' => 'C#', 'alias' => 'C#', 'parent' => null],
            ['name' => 'Java', 'alias' => 'Java', 'parent' => null],
            ['name' => 'Spring', 'alias' => 'Spring', 'parent' => 'Java'],
            ['name' => 'JS', 'alias' => 'Js', 'parent' => null],
            ['name' => 'React.js', 'alias' => 'React', 'parent' => 'JS'],
            ['name' => 'Vue.js', 'alias' => 'Vue', 'parent' => 'JS'],
            ['name' => 'Node.js', 'alias' => 'Node', 'parent' => 'JS'],
            ['name' => 'PHP', 'alias' => 'Php', 'parent' => null],
            ['name' => 'Laravel', 'alias' => 'Laravel', 'parent' => 'PHP'],
            ['name' => 'Symfony', 'alias' => 'Symfony', 'parent' => 'PHP'],
            ['name' => 'DevOps', 'alias' => 'DevOps', 'parent' => null],
            ['name' => 'Python', 'alias' => 'Python', 'parent' => null],
            ['name' => 'Django', 'alias' => 'Django', 'parent' => 'Python'],
            ['name' => 'FastAPI', 'alias' => 'FastAPI', 'parent' => 'Python'],
            ['name' => 'Android', 'alias' => 'Android', 'parent' => null],
            ['name' => 'iOS', 'alias' => 'iOS', 'parent' => null],
            ['name' => 'Flutter', 'alias' => 'Flutter', 'parent' => null],
            ['name' => 'Project manager', 'alias' => 'PM', 'parent' => null],
            ['name' => 'Business analyst', 'alias' => 'BA', 'parent' => null],
            ['name' => 'QA', 'alias' => 'QA', 'parent' => null],
            ['name' => 'QAA', 'alias' => 'QAA', 'parent' => 'QA'],
            ['name' => 'QAM', 'alias' => 'QAM', 'parent' => 'QA'],
            ['name' => 'QAMM', 'alias' => 'QAMM', 'parent' => 'QA'],
            ['name' => 'Design', 'alias' => 'Design', 'parent' => null],
            ['name' => 'UX/UI', 'alias' => 'UX/UI', 'parent' => 'Design'],
            ['name' => '1C', 'alias' => '1C', 'parent' => null],
            ['name' => 'GO', 'alias' => 'GO', 'parent' => null],
            ['name' => 'ML', 'alias' => 'ML', 'parent' => null],
            ['name' => 'Computer vision', 'alias' => 'CV', 'parent' => 'ML'],
            ['name' => 'NLP', 'alias' => 'NLP', 'parent' => 'ML'],
            ['name' => 'Product owner', 'alias' => 'PO', 'parent' => null],
        ];
        DB::transaction(function () use ($catalog): void {
            $ids = [];
            foreach ($catalog as $item) {
                $id = DB::table('technologies')->where('name', $item['name'])->value('id');
                if ($id === null) $id = DB::table('technologies')->insertGetId([
                    'name' => $item['name'], 'alias' => $item['alias'], 'active' => true,
                    'category' => $item['name'] === 'UX/UI' ? 'Frontend' : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $ids[$item['name']] = $id;
            }
            foreach ($catalog as $item) DB::table('technologies')->where('id', $ids[$item['name']])->update([
                'parent_id' => $item['parent'] === null ? null : $ids[$item['parent']],
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Catalog records can be used by profiles and Clients; never delete them on rollback.
    }
};
