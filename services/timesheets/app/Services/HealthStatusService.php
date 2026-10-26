<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Infrastructure readiness query, intentionally isolated from HTTP routing. */
final class HealthStatusService
{
    public function status(): array
    {
        return [
            'service' => 'timesheets',
            'status' => 'ok',
            'database' => DB::select('select 1') ? 'ok' : 'error',
        ];
    }
}
