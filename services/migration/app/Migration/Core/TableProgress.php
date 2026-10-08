<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/** Exact import counters, separate from read-only extraction and metadata preservation. */
final class TableProgress
{
    public static ?int $activeRun = null;

    public static function table(string $service, string $entity): string
    {
        return config('migration.table_entities.'.$service.'.'.$entity, $entity);
    }

    public static function ensure(int $run, string $table): void
    {
        DB::table('migration_table_progress')->insertOrIgnore([
            'migration_run_id' => $run, 'table_name' => $table, 'state' => 'waiting',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function count(int $run, string $service, string $entity, string $counter, string|int|null $legacyId): void
    {
        $table = self::table($service, $entity);
        self::ensure($run, $table);
        DB::transaction(function () use ($run, $table, $counter, $legacyId): void {
            $newRow = 0; $increment = 1;
            if ($legacyId !== null) {
                $key = ['migration_run_id' => $run, 'table_name' => $table, 'legacy_id' => (string) $legacyId];
                $newRow = DB::table('migration_table_rows')->insertOrIgnore($key);
                if (in_array($counter, ['success_count','ready_count'], true)) {
                    $flag = $counter === 'success_count' ? 'succeeded' : 'ready';
                    $increment = DB::table('migration_table_rows')->where($key)->where($flag, false)->update([$flag => true]);
                }
            }
            DB::table('migration_table_progress')->where('migration_run_id', $run)->where('table_name', $table)
                ->update([$counter => DB::raw($counter.' + '.$increment),
                    'processed_count' => DB::raw('processed_count + '.$newRow), 'state' => 'processing', 'updated_at' => now()]);
        });
    }

    public static function extracted(string $sql, array $rows): void
    {
        if (self::$activeRun === null || ! preg_match('/\bFROM\s+public\.([a-z_]+)/i', $sql, $match)) return;
        $run = self::$activeRun;
        $table = $match[1];
        self::ensure($run, $table);
        $query = DB::table('migration_table_progress')->where('migration_run_id', $run)->where('table_name', $table);
        if (preg_match('/SELECT\s+count\(\*\)/i', $sql)) {
            $query->update(['total' => (int) ($rows[0]->count ?? 0), 'updated_at' => now()]);
        } elseif (! preg_match('/\bDISTINCT\b/i', $sql)) {
            $query->update(['read_count' => count($rows), 'state' => 'read', 'updated_at' => now()]);
        }
    }

    public static function finish(int $run, string $mode, bool $failed): void
    {
        foreach (DB::table('migration_table_progress')->where('migration_run_id', $run)->get() as $row) {
            $state = $failed ? ($row->state === 'waiting' ? 'waiting' : 'interrupted')
                : ($mode !== 'migrate' ? ($row->error_count > 0 ? 'conflicts' : ($mode === 'dry-run' && $row->ready_count > 0 ? 'preflight_ready' : ($mode === 'dry-run' && $row->warning_count > 0 ? 'metadata_only' : 'checked')))
                    : ($row->error_count > 0 ? 'conflicts'
                        : ($row->success_count > 0 && $row->total !== null && $row->success_count >= $row->total
                            ? 'completed' : ($row->success_count > 0 ? 'partial' : 'read_only'))));
            DB::table('migration_table_progress')->where('id', $row->id)->update(['state' => $state, 'updated_at' => now()]);
        }
    }
}
