<?php

namespace App\Migration\Jobs;

use App\Migration\Core\MigrationRegistry;
use App\Migration\Core\MigrationStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RunMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0;

    public function __construct(
        public readonly int $runId,
        public readonly string $service,
        public readonly string $mode,
        public readonly ?string $credentialKeyFingerprint = null,
    ) {
        $this->onQueue('migration');
    }

    public function handle(MigrationRegistry $registry, MigrationStore $store): void
    {
        \App\Migration\Core\TableProgress::$activeRun = $this->runId;
        $store->markRunning($this->runId);
        try {
            $module = $registry->get($this->service);
            $store->progress($this->runId, 'safety', 'Повторно проверяем read-only защиту legacy DB перед операцией.');

            if ($this->mode === 'migrate') {
                $plan = $module->inspect($this->runId);
                $store->event($this->runId, 'table_plan', 'Получены объёмы таблиц.', 'info', ['counts' => $plan['counts'] ?? []]);
            }
            $result = match ($this->mode) {
                'inspect' => $module->inspect($this->runId),
                'dry-run' => $module->migrate($this->runId, true),
                'migrate' => $module->migrate($this->runId, false),
                'validate' => $module->validate($this->runId),
                default => throw new InvalidArgumentException("Unsupported migration mode: {$this->mode}"),
            };

            $store->progress($this->runId, 'reconciliation', 'Формируем итоговые счётчики и отчёт.');
            $errorConflicts = DB::table('migration_conflicts')
                ->where('migration_run_id', $this->runId)
                ->where('severity', 'error')
                ->count();

            $status = 'completed';
            if ($this->mode === 'validate' && ! ($result['ok'] ?? false)) {
                $status = 'conflicts';
            } elseif ($errorConflicts > 0) {
                $status = 'conflicts';
            }

            $store->finishRun($this->runId, $status, $result);
        } catch (Throwable $e) {
            $store->finishRun($this->runId, 'failed', [], $e->getMessage());
            throw $e;
        } finally {
            \App\Migration\Core\TableProgress::$activeRun = null;
        }
    }
}
