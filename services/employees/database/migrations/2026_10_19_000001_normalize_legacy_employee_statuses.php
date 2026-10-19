<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $allowed = ['Ожидает трудоустройства', 'Трудоустроен', 'Уволен'];
        $normalize = static function (?string $value, bool $isDismissed): string {
            $status = mb_strtolower(trim((string) $value));
            if (in_array($status, ['dismissed', 'fired', 'terminated', 'inactive', 'уволен', 'увольнение'], true) || $isDismissed) {
                return 'Уволен';
            }
            if (in_array($status, ['pending', 'waiting', 'new', 'not_hired', 'candidate', 'ожидает трудоустройства'], true)) {
                return 'Ожидает трудоустройства';
            }
            return 'Трудоустроен';
        };

        if (Schema::hasTable('employees')) {
            DB::table('employees')->whereNotNull('employment_status')
                ->whereNotIn('employment_status', $allowed)
                ->orderBy('id')->chunkById(200, function ($rows) use ($normalize) {
                    foreach ($rows as $row) {
                        DB::table('employees')->where('id', $row->id)->update([
                            'employment_status' => $normalize($row->employment_status, $row->fired_at !== null),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }

        if (Schema::hasTable('employee_status_history')) {
            DB::table('employee_status_history')->whereNotNull('status')
                ->whereNotIn('status', $allowed)
                ->orderBy('id')->chunkById(200, function ($rows) use ($normalize) {
                    foreach ($rows as $row) {
                        DB::table('employee_status_history')->where('id', $row->id)->update([
                            'status' => $normalize($row->status, false),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // A normalized status cannot safely be translated back to an arbitrary legacy value.
    }
};
