<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** The console stays alive; a shared lock drains metadata requests before inode replacement. */
final class RestoreMaintenance
{
    private function active(string $path): bool
    {
        $state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        return ($state['state'] ?? '') === 'running'
            || (($state['state'] ?? '') === 'failed' && (($state['database_outcome'] ?? '') === 'unknown'
                || (($state['database_committed'] ?? false) && !(($state['metadata_complete'] ?? false) && ($state['schemas_upgraded'] ?? false)))));
    }

    private function paused(Request $request): mixed
    {
        if ($request->isMethod('GET') && $request->is('api/migration/health')) {
            return response()->json(['status' => 'ok', 'service' => 'migration', 'maintenance' => true]);
        }
        return response()->json(['message' => 'Журнал миграции временно недоступен во время восстановления. Состояние отката доступно в пульте.', 'maintenance' => true], 503);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethod('GET') && $request->is('api/migration/console/state', 'api/migration/ops/health')) return $next($request);
        $path = config('migration.restore_status_path', '/ops/restore-status.json');
        $lock = null;
        try {
            try {
                if ($this->active($path)) return $this->paused($request);
                $lockPath = config('migration.restore_lock_path', '/ops/migration-metadata.lock');
                if (is_dir(dirname($lockPath))) {
                    $lock = fopen($lockPath, 'c');
                    // Never block the single-process API behind an exclusive restore lock.
                    if (!$lock || !flock($lock, LOCK_SH | LOCK_NB)) return $this->paused($request);
                    if ($this->active($path)) return $this->paused($request);
                }
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Не удалось проверить состояние отката.'], 503);
            }
            return $next($request);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
