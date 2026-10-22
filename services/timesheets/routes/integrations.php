<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/commercial-data', function (Request $request) {
    $data = $request->validate([
        'from' => ['required', 'date'],
        'to' => ['required', 'date', 'after_or_equal:from'],
        'client_id' => ['nullable', 'integer', 'min:1'],
    ]);

    // Never filter by a mutable copy stored in Timesheets. Clients owns project -> client.
    $snapshot = app(\App\Support\AssignmentsDirectory::class)->snapshot();
    $ownership = $snapshot['project_clients'];
    $entriesQuery = DB::table('timesheet_entries')
        ->whereBetween('work_date', [$data['from'], $data['to']])
        ->orderBy('work_date')
        ->orderBy('employee_id')
        ->orderBy('project_id');

    if (!empty($data['client_id'])) {
        $projectIdsForClient = array_keys(array_filter($ownership,
            fn ($clientId) => (int) $clientId === (int) $data['client_id']));
        $entriesQuery->whereIn('project_id', $projectIdsForClient ?: [0]);
    }

    // Preserve the existing integration DTO's client_id while removing physical duplication.
    $entries = $entriesQuery->get()->map(static function ($entry) use ($ownership) {
        $entry->client_id = $ownership[(int) $entry->project_id] ?? null;
        return $entry;
    });
    $employeeIds = $entries->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    $projectIds = $entries->pluck('project_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

    $monthCursor = Carbon::parse($data['from'])->startOfMonth();
    $lastMonth = Carbon::parse($data['to'])->startOfMonth();
    $months = [];
    while ($monthCursor->lte($lastMonth)) {
        $months[] = $monthCursor->toDateString();
        $monthCursor->addMonth();
    }

    $approvals = collect();
    if ($employeeIds && $projectIds && $months) {
        $approvals = DB::table('final_approvals')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('project_id', $projectIds)
            ->whereIn('month', $months)
            ->get();
    }

    return response()->json(['data' => [
        'from' => $data['from'],
        'to' => $data['to'],
        'entries' => $entries,
        'final_approvals' => $approvals,
    ]]);
});
