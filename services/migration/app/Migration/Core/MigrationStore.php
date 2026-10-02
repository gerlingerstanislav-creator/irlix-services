<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

final class MigrationStore
{
    public function beginRun(string $service, string $mode, ?string $requestedBy = null): int
    {
        return $this->createRun($service, $mode, 'running', $requestedBy);
    }

    public function queueRun(string $service, string $mode, ?string $requestedBy = null): int
    {
        return DB::transaction(function () use ($service, $mode, $requestedBy): int {
            // SQLite serializes writers. Acquire its write lock before checking active runs,
            // so simultaneous POSTs cannot both pass the check and create duplicate tasks.
            DB::table('migration_connections')->where('service', $service)
                ->update(['updated_at' => DB::raw('updated_at')]);
            if ($this->hasActiveRun($service)) {
                throw new \RuntimeException('Для этого сервиса уже выполняется операция.', 409);
            }
            $runId = $this->createRun($service, $mode, 'queued', $requestedBy);
            $this->event($runId, 'queued', 'Задача поставлена в очередь.');
            return $runId;
        });
    }

    public function markRunning(int $runId): void
    {
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => 'running',
            'progress_phase' => 'starting',
            'progress_message' => 'Проверяем подключение и запускаем операцию.',
            'heartbeat_at' => now(),
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event($runId, 'started', 'Операция запущена.');
    }

    public function progress(int $runId, string $phase, string $message): void
    {
        DB::table('migration_runs')->where('id', $runId)->update([
            'progress_phase' => $phase,
            'progress_message' => $message,
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finishRun(int $runId, string $status, array $summary = [], ?string $error = null): void
    {
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => $status,
            'progress_phase' => $status === 'failed' ? 'failed' : 'finished',
            'progress_message' => $status === 'failed' ? 'Операция завершилась ошибкой.' : 'Операция завершена.',
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => $error,
            'heartbeat_at' => now(),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event(
            $runId,
            $status === 'failed' ? 'failed' : 'finished',
            $status === 'failed' ? ($error ?: 'Операция завершилась ошибкой.') : 'Операция завершена.',
            $status === 'failed' ? 'error' : ($status === 'conflicts' ? 'warning' : 'info'),
            $summary,
        );
    }

    public function event(int $runId, string $event, string $message, string $level = 'info', array $context = []): void
    {
        DB::table('migration_run_events')->insert([
            'migration_run_id' => $runId,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    }

    public function hasActiveRun(string $service): bool
    {
        return DB::table('migration_runs')
            ->where('service', $service)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    public function latestRun(string $service, ?string $mode = null): ?array
    {
        $query = DB::table('migration_runs')->where('service', $service);
        if ($mode !== null) {
            $query->where('mode', $mode);
        }
        $row = $query->orderByDesc('id')->first();
        return $row ? $this->normalizeRun($row) : null;
    }

    public function recentRuns(?string $service = null, int $limit = 30): array
    {
        $query = DB::table('migration_runs')->orderByDesc('id')->limit(max(1, min($limit, 100)));
        if ($service !== null) {
            $query->where('service', $service);
        }

        return $query->get()->map(fn ($row) => $this->normalizeRun($row))->all();
    }

    public function run(int $runId): ?array
    {
        $row = DB::table('migration_runs')->where('id', $runId)->first();
        if (! $row) {
            return null;
        }
        $run = $this->normalizeRun($row);
        $run['events'] = DB::table('migration_run_events')
            ->where('migration_run_id', $runId)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($event) => [
                'id' => (int) $event->id,
                'level' => $event->level,
                'event' => $event->event,
                'message' => $event->message,
                'context' => $this->decodeJson($event->context),
                'created_at' => $event->created_at,
            ])->all();
        $run['conflicts'] = DB::table('migration_conflicts')
            ->where('migration_run_id', $runId)
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($conflict) => [
                'id' => (int) $conflict->id,
                'entity_type' => $conflict->entity_type,
                'legacy_id' => $conflict->legacy_id,
                'severity' => $conflict->severity,
                'code' => $conflict->code,
                'message' => $conflict->message,
                'context' => $this->decodeJson($conflict->context),
                'resolved' => (bool) $conflict->resolved,
                'created_at' => $conflict->created_at,
            ])->all();

        return $run;
    }

    public function mapping(string $service, string $entityType, string|int $legacyId): ?string
    {
        $value = DB::table('migration_mappings')
            ->where('service', $service)
            ->where('entity_type', $entityType)
            ->where('legacy_id', (string) $legacyId)
            ->value('target_id');

        return $value === null ? null : (string) $value;
    }

    public function saveMapping(int $runId, string $service, string $entityType, string|int $legacyId, string|int $targetId, array $metadata = []): void
    {
        DB::table('migration_mappings')->updateOrInsert(
            [
                'service' => $service,
                'entity_type' => $entityType,
                'legacy_id' => (string) $legacyId,
            ],
            [
                'target_id' => (string) $targetId,
                'migration_run_id' => $runId,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        DB::table('migration_runs')->where('id', $runId)->update([
            'processed_count' => DB::raw('processed_count + 1'),
            'success_count' => DB::raw('success_count + 1'),
            'progress_phase' => 'processing',
            'progress_message' => 'Перенос выполняется. Счётчики обновляются по мере обработки данных.',
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function override(string $service, string $entityType, string|int $legacyId): ?string
    {
        $value = DB::table('migration_overrides')
            ->where('service', $service)
            ->where('entity_type', $entityType)
            ->where('legacy_id', (string) $legacyId)
            ->value('target_id');

        return $value === null ? null : (string) $value;
    }

    public function conflict(int $runId, string $service, string $entityType, string|int|null $legacyId, string $code, string $message, array $context = [], string $severity = 'error'): void
    {
        DB::table('migration_conflicts')->insert([
            'migration_run_id' => $runId,
            'service' => $service,
            'entity_type' => $entityType,
            'legacy_id' => $legacyId === null ? null : (string) $legacyId,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'resolved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $updates = [
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ];
        if ($severity === 'warning') {
            $updates['warning_count'] = DB::raw('warning_count + 1');
        } else {
            // An error conflict represents a source entity that was processed but could not be
            // migrated. Warnings belong to an already processed entity and must not double-count it.
            $updates['processed_count'] = DB::raw('processed_count + 1');
            $updates['conflict_count'] = DB::raw('conflict_count + 1');
        }
        DB::table('migration_runs')->where('id', $runId)->update($updates);
    }

    public function mappedCount(string $service, string $entityType): int
    {
        return DB::table('migration_mappings')->where('service', $service)->where('entity_type', $entityType)->count();
    }

    private function createRun(string $service, string $mode, string $status, ?string $requestedBy): int
    {
        return (int) DB::table('migration_runs')->insertGetId([
            'service' => $service,
            'mode' => $mode,
            'status' => $status,
            'requested_by' => $requestedBy,
            'progress_phase' => $status === 'queued' ? 'queued' : 'starting',
            'progress_message' => $status === 'queued' ? 'Ожидает запуска worker.' : 'Запуск операции.',
            'processed_count' => 0,
            'success_count' => 0,
            'warning_count' => 0,
            'conflict_count' => 0,
            'heartbeat_at' => now(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizeRun(object $row): array
    {
        $mapped = DB::table('migration_mappings')->where('migration_run_id', $row->id)->count();
        return [
            'id' => (int) $row->id,
            'service' => $row->service,
            'mode' => $row->mode,
            'status' => $row->status,
            'requested_by' => $row->requested_by,
            'progress_phase' => $row->progress_phase,
            'progress_message' => $row->progress_message,
            'processed_count' => (int) $row->processed_count,
            'success_count' => (int) $row->success_count,
            'mapped_count' => (int) $mapped,
            'warning_count' => (int) $row->warning_count,
            'conflict_count' => (int) $row->conflict_count,
            'summary' => $this->decodeJson($row->summary),
            'error' => $row->error,
            'heartbeat_at' => $row->heartbeat_at,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at' => $row->created_at,
        ];
    }

    private function decodeJson(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
