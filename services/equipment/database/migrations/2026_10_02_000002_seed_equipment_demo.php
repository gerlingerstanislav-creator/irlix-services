<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const INVENTORY_NUMBER = 'DEMO-001';
    private const DEMO_EMPLOYEE_ID = 999999999;

    public function up(): void
    {
        if (DB::table('equipment_items')->where('inventory_number', self::INVENTORY_NUMBER)->exists()) return;

        DB::transaction(function (): void {
            $now = now();
            $itemId = DB::table('equipment_items')->insertGetId([
                'inventory_number' => self::INVENTORY_NUMBER,
                'type' => 'laptop',
                'manufacturer' => 'DemoTech',
                'model' => 'WorkBook 14',
                'serial_number' => 'DEMO-SN-0001',
                'purpose' => 'development',
                'condition' => 'ok',
                'comment' => 'Синтетическая демонстрационная запись. Не относится к реальной технике.',
                'purchased_on' => '2026-01-15',
                'purchase_cost' => 120000,
                'useful_life_months' => 36,
                'cpu' => 'Intel Core i5 (demo)',
                'ram' => '16 GB',
                'storage' => '512 GB SSD',
                'gpu' => 'Integrated',
                'os' => 'Windows 11 Pro',
                'os_version' => '24H2',
                'imei' => null,
                'written_off_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assignmentId = DB::table('equipment_assignments')->insertGetId([
                'equipment_item_id' => $itemId,
                'employee_id' => self::DEMO_EMPLOYEE_ID,
                'starts_on' => '2026-09-01',
                'planned_ends_on' => null,
                'returned_on' => null,
                'issued_by' => 'demo-seed',
                'returned_by' => null,
                'issue_comment' => 'Синтетическая демо-выдача. employee_id зарезервирован для демо и не ссылается на реального сотрудника.',
                'return_comment' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('equipment_audit_log')->insert([
                [
                    'equipment_item_id' => $itemId,
                    'event' => 'created',
                    'actor' => 'demo-seed',
                    'payload' => json_encode(['demo' => true, 'inventory_number' => self::INVENTORY_NUMBER], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'equipment_item_id' => $itemId,
                    'event' => 'assigned',
                    'actor' => 'demo-seed',
                    'payload' => json_encode(['demo' => true, 'assignment_id' => $assignmentId, 'employee_id' => self::DEMO_EMPLOYEE_ID, 'starts_on' => '2026-09-01'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }

    public function down(): void
    {
        $itemId = DB::table('equipment_items')->where('inventory_number', self::INVENTORY_NUMBER)->value('id');
        if (!$itemId) return;

        DB::transaction(function () use ($itemId): void {
            DB::table('equipment_audit_log')->where('equipment_item_id', $itemId)->where('actor', 'demo-seed')->delete();
            DB::table('equipment_assignments')->where('equipment_item_id', $itemId)->where('issued_by', 'demo-seed')->delete();
            DB::table('equipment_items')->where('id', $itemId)->where('inventory_number', self::INVENTORY_NUMBER)->delete();
        });
    }
};
