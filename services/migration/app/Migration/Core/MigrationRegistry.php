<?php

namespace App\Migration\Core;

use App\Migration\Contracts\ServiceMigration;
use InvalidArgumentException;

final class MigrationRegistry
{
    public function keys(): array
    {
        return array_keys(config('migration.modules', []));
    }

    public function get(string $key): ServiceMigration
    {
        $class = config('migration.modules.'.$key);
        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException("Unknown migration service: {$key}");
        }

        $migration = app($class);
        if (! $migration instanceof ServiceMigration) {
            throw new InvalidArgumentException("Migration module {$class} does not implement ServiceMigration.");
        }

        return $migration;
    }
}
