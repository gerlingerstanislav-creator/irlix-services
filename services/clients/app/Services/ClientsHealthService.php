<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Infrastructure exception: an explicit constant SELECT 1 checks database availability. */
final class ClientsHealthService
{
    public function status(): array
    {
        return [
            'service' => 'clients',
            'status' => 'ok',
            'database' => DB::select('select 1') ? 'ok' : 'error',
        ];
    }
}
