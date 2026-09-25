<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/audit', function (Request $request) {
    $query = DB::table('audit_log')->orderByDesc('occurred_at')->orderByDesc('id');

    if ($employeeId = $request->query('employee_id')) {
        $query->where('target_type', 'employee')->where('target_id', (string) ((int) $employeeId));
    }
    if ($action = trim((string) $request->query('action', ''))) {
        $query->where('action', 'ilike', "%{$action}%");
    }
    if ($actor = trim((string) $request->query('actor', ''))) {
        $query->where(function ($q) use ($actor) {
            $q->where('actor_name', 'ilike', "%{$actor}%")->orWhere('actor_sub', 'ilike', "%{$actor}%");
        });
    }
    if ($from = $request->query('from')) $query->where('occurred_at', '>=', $from.' 00:00:00+00');
    if ($to = $request->query('to')) $query->where('occurred_at', '<=', $to.' 23:59:59+00');

    $limit = max(1, min(200, (int) $request->query('limit', 100)));
    $rows = $query->limit($limit)->get()->map(function ($row) {
        foreach (['before', 'after', 'metadata'] as $field) {
            if (is_string($row->{$field} ?? null)) $row->{$field} = json_decode($row->{$field}, true);
        }
        return $row;
    });

    return response()->json([
        'data' => $rows,
        'meta' => [
            'count' => $rows->count(),
            'limit' => $limit,
            'actions' => DB::table('audit_log')->distinct()->orderBy('action')->pluck('action')->values(),
        ],
    ]);
});
