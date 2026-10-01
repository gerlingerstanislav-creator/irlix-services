<?php

namespace App\Migration\Contracts;

interface ServiceMigration
{
    public function key(): string;

    public function inspect(int $runId): array;

    public function migrate(int $runId, bool $dryRun): array;

    public function validate(int $runId): array;
}
