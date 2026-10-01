<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

final class MigrationStore
{
    public function beginRun(string $service, string $mode): int
    {
        return (int) DB::table('migration_runs')->insertGetId([
            'service' => $service,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finishRun(int $runId, string $status, array $summary = [], ?string $error = null): void
    {
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => $status,
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => $error,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
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
    }

    public function mappedCount(string $service, string $entityType): int
    {
        return DB::table('migration_mappings')->where('service', $service)->where('entity_type', $entityType)->count();
    }
}
