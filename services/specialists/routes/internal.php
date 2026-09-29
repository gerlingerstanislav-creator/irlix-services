<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', function (Request $request, int $employee) {
    $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Invalid internal purge token'], 403);
    }

    DB::transaction(function () use ($employee): void {
        DB::table('specialist_competencies')->where('employee_id', $employee)->delete();
        DB::table('specialist_technologies')->where('employee_id', $employee)->delete();
        DB::table('specialist_profiles')->where('employee_id', $employee)->delete();
    });

    return response()->json(['purged' => true]);
});
