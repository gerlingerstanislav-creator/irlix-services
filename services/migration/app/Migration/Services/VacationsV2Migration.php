<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use App\Migration\Core\MigrationOperationsClient;
use Illuminate\Support\Facades\DB;

class VacationsV2Migration extends SchemaMigration
{
    public function key(): string { return 'vacations'; }

    protected function assertCheckpoint(): void
    {
        try { parent::assertCheckpoint(); return; }
        catch (\RuntimeException $e) {
            if ($e->getCode() === 409) throw $e;
            [$code,$state] = app(MigrationOperationsClient::class)->request('GET','/vacations/state');
            if ($code === 200 && !in_array($state['operation']['state'] ?? '', ['queued','running'], true)
                && collect($state['snapshots'] ?? [])->contains(fn ($s) => ($s['compatible'] ?? true))) return;
            throw $e;
        }
    }

    protected function import(): void
    {
        $this->rows('users', function ($row, $id) { $this->map('users', $id, $this->employee($row), $row); });
        $this->rows('vacations', function ($row, $id) {
            $employee = $this->ref('users', $row['user_id']);
            [$from, $to] = $this->dates($row['from'], $row['to']);
            $this->need($to, 'Absence end is required');
            $type = $this->enum($row['type'], config('migration.vacation_types'), 'type');
            $status = $this->enum($row['status'], config('migration.vacation_statuses'), 'status');
            $this->number($row['working_hours'], 32767);
            $absence = $this->write('vacations', $id, 'absences', [
                'employee_id' => $employee, 'type' => $type, 'starts_on' => $from, 'ends_on' => $to,
                'calendar_days' => (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1,
                'status' => $status, 'created_by_subject' => 'legacy-migration',
            ], $row);
            if (!$this->dryRun && !DB::connection('target_vacations')->table('absence_audit_log')->where('absence_id', $absence)->where('event', 'legacy_migrated')->exists()) {
                DB::connection('target_vacations')->table('absence_audit_log')->insert([
                    'absence_id' => $absence, 'event' => 'legacy_migrated', 'actor_subject' => 'legacy-migration',
                    'after' => json_encode(['legacy_vacation_id' => $id, 'status' => $row['status'], 'working_hours' => $row['working_hours']]),
                    'created_at' => $row['created_at'] ?? now(),
                ]);
                DB::connection('target_vacations')->table('absence_status_history')->insert([
                    'absence_id' => $absence, 'to_status' => $status, 'actor_subject' => 'legacy-migration',
                    'reason' => 'Imported stored legacy state; transition timestamps are unavailable',
                    'context' => json_encode(['legacy_vacation_id' => $id, 'reconstructed' => false]), 'created_at' => now(),
                ]);
            }
        });
        // The old order/boolean does not identify new HR/AM/RN workflow stages or action time.
        foreach (['approvers', 'changes', 'comments', 'attachments', 'business_dates', 'activity_log', 'departments'] as $table) {
            $this->rows($table, fn ($row, $id) => $this->preserve($table, $row, $id,
                'Legacy history/calendar/file reference preserved. New workflow stages and file bytes cannot be reconstructed from this schema.'));
        }
    }
}
