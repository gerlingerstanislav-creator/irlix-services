<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('parent_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('hr_id')->nullable()->after('manager_id')->constrained('employees')->nullOnDelete();
            $table->integer('yandex_id')->nullable()->after('hr_id');
            $table->string('ldap_group')->nullable()->after('yandex_id');
            $table->boolean('is_production')->default(false)->after('ldap_group');
        });

        $now = now();
        $rows = [
            ['name' => 'Irlix', 'parent' => null, 'yandex_id' => 27, 'ldap_group' => 'os_irlix', 'is_production' => false],
            ['name' => 'Back office', 'parent' => 'Irlix', 'yandex_id' => 10, 'ldap_group' => 'os_back-office', 'is_production' => false],
            ['name' => 'HR', 'parent' => 'Irlix', 'yandex_id' => 12, 'ldap_group' => 'os_hr', 'is_production' => false],
            ['name' => 'Development', 'parent' => 'Irlix', 'yandex_id' => 14, 'ldap_group' => 'os_development', 'is_production' => false],
            ['name' => 'Backend', 'parent' => 'Development', 'yandex_id' => 3, 'ldap_group' => 'os_backend', 'is_production' => true],
            ['name' => 'Frontend', 'parent' => 'Development', 'yandex_id' => 5, 'ldap_group' => 'os_frontend', 'is_production' => true],
            ['name' => 'QA', 'parent' => 'Development', 'yandex_id' => 6, 'ldap_group' => 'os_qa', 'is_production' => true],
            ['name' => 'Analytics', 'parent' => 'Development', 'yandex_id' => 7, 'ldap_group' => 'os_analytics', 'is_production' => true],
            ['name' => 'Mobile', 'parent' => 'Development', 'yandex_id' => 13, 'ldap_group' => 'os_mobile', 'is_production' => true],
            ['name' => '1S', 'parent' => 'Development', 'yandex_id' => 31, 'ldap_group' => 'os_1s', 'is_production' => true],
            ['name' => 'Other development specialists', 'parent' => 'Development', 'yandex_id' => 33, 'ldap_group' => null, 'is_production' => true],
            ['name' => 'Client service', 'parent' => 'Irlix', 'yandex_id' => 15, 'ldap_group' => 'os_client-service', 'is_production' => false],
            ['name' => 'Accounting', 'parent' => 'Client service', 'yandex_id' => 9, 'ldap_group' => 'os_accounting', 'is_production' => false],
            ['name' => 'Sales', 'parent' => 'Client service', 'yandex_id' => 11, 'ldap_group' => 'os_sales', 'is_production' => false],
            ['name' => 'PMs', 'parent' => 'Client service', 'yandex_id' => 32, 'ldap_group' => 'os_pm', 'is_production' => false],
            ['name' => 'Marketing', 'parent' => 'Irlix', 'yandex_id' => 17, 'ldap_group' => 'os_marketing', 'is_production' => false],
            ['name' => 'Finance', 'parent' => 'Irlix', 'yandex_id' => 29, 'ldap_group' => 'os_finance', 'is_production' => false],
            ['name' => 'Legal', 'parent' => 'Irlix', 'yandex_id' => 30, 'ldap_group' => 'os_legal', 'is_production' => false],
        ];

        $ids = [];
        foreach ($rows as $row) {
            $parentId = $row['parent'] ? ($ids[$row['parent']] ?? DB::table('departments')->where('name', $row['parent'])->value('id')) : null;
            $existingId = DB::table('departments')->where('name', $row['name'])->value('id');

            $payload = [
                'parent_id' => $parentId,
                'yandex_id' => $row['yandex_id'],
                'ldap_group' => $row['ldap_group'],
                'is_production' => $row['is_production'],
                'updated_at' => $now,
            ];

            if ($existingId) {
                DB::table('departments')->where('id', $existingId)->update($payload);
                $ids[$row['name']] = (int) $existingId;
            } else {
                $ids[$row['name']] = (int) DB::table('departments')->insertGetId([
                    'name' => $row['name'],
                    'alias' => null,
                    ...$payload,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('hr_id');
            $table->dropColumn(['yandex_id', 'ldap_group', 'is_production']);
        });
    }
};
