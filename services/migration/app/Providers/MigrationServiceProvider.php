<?php

namespace App\Providers;

use App\Migration\Core\MigrationRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (config('migration.legacy', []) as $source) {
            config(['database.connections.'.$source['connection'] => $source['database']]);
        }

        foreach (config('migration.targets', []) as $target) {
            config(['database.connections.'.$target['connection'] => $target['database']]);
        }

        $this->app->singleton(MigrationRegistry::class);
    }

    public function boot(): void
    {
        if (config('database.default') === 'sqlite') {
            // API polling and the background worker share one private metadata database. WAL keeps
            // long-running migration writes from unnecessarily blocking read-only progress polling.
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=5000');
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }
}
