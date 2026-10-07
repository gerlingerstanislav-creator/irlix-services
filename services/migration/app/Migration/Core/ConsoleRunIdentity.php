<?php

namespace App\Migration\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConsoleRunIdentity
{
    public static function matches(Request $request, int $run): bool
    {
        $input = $request->validate(['operation_id' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[0-9]+$/D']]);
        if (! isset($input['operation_id'])) return true;
        foreach (DB::table('migration_run_events')->where('migration_run_id', $run)->where('event', 'console')->get(['context']) as $event) {
            $context = json_decode($event->context ?? '{}', true);
            if ((string) ($context['operation_id'] ?? '') === $input['operation_id']) return true;
        }
        return false;
    }
}
