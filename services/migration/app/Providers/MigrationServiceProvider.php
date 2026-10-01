<?php

namespace App\Providers;

use App\Migration\Core\MigrationRegistry;
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
}
