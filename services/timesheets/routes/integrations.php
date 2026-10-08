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

    $entriesQuery = DB::table('timesheet_entries')
        ->whereBetween('work_date', [$data['from'], $data['to']])
        ->orderBy('work_date')
        ->orderBy('employee_id')
        ->orderBy('project_id');

    if (!empty($data['client_id'])) {
        $entriesQuery->where('client_id', (int) $data['client_id']);
    }

    $entries = $entriesQuery->get();
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

    $snapshot = app(\App\Support\AssignmentsDirectory::class)->snapshot();
    $assignments = array_filter($snapshot['assignments'], fn ($a) => in_array($a['employee_id'], $employeeIds, true)
        && in_array($a['project_id'], $projectIds, true) && (empty($data['client_id']) || $a['client_id'] === (int) $data['client_id']));
    $approvals = \App\Support\ReportingPeriodApprovals::effective($approvals, $assignments,
        $snapshot['locked_reporting_periods'], $data['from'], $data['to']);

    return response()->json(['data' => [
        'from' => $data['from'],
        'to' => $data['to'],
        'entries' => $entries,
        'final_approvals' => $approvals,
    ]]);
});
