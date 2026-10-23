<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    // Independently validate every permanent client-member identity from
    // Clients before aggregating partner facts (never infer from a project).
    $secret=(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
    abort_unless($secret!=='',503,'ClientMember integration unavailable');
    try {
        $response=Http::withHeaders(['X-Irlix-Timesheets-Token'=>$secret])->acceptJson()
            ->timeout(8)->get(rtrim((string)env('CLIENTS_INTERNAL_URL','http://clients:8000/internal'),'/').'/client-member-assignments');
    } catch (\Throwable) { abort(503,'ClientMember directory unavailable'); }
    $directory=$response->json();
    abort_unless($response->successful() && ($directory['complete']??false)===true
        && is_array($directory['data']??null)
        && ($directory['count']??null)===count($directory['data']),503,'Incomplete ClientMember directory');
    $memberClients=[];
    foreach($directory['data'] as $member) {
        $id=(int)($member['client_member_id']??0);
        abort_unless($id>0 && !isset($memberClients[$id])
            && (int)($member['client_id']??0)>0,503,'Invalid ClientMember directory');
        $memberClients[$id]=(int)$member['client_id'];
    }
    $entriesQuery = DB::table('timesheet_entries')
        ->whereBetween('work_date', [$data['from'], $data['to']])
        ->orderBy('work_date')
        ->orderBy('employee_id')
        ->orderBy('project_id');

    if (!empty($data['client_id'])) {
        $projectIdsForClient = array_keys(array_filter($ownership,
            fn ($clientId) => (int) $clientId === (int) $data['client_id']));
        $memberIdsForClient=array_keys(array_filter($memberClients,
            fn($clientId)=>(int)$clientId===(int)$data['client_id']));
        $entriesQuery->where(function($query) use($projectIdsForClient,$memberIdsForClient) {
            $query->whereIn('project_id',$projectIdsForClient?:[0])
                ->orWhereIn('client_member_id',$memberIdsForClient?:[0]);
        });
    }

    // Preserve the existing integration DTO's client_id while removing physical duplication.
    $entries = $entriesQuery->get()->map(static function ($entry) use ($ownership,$memberClients) {
        $entry->client_id = $entry->client_member_id !== null
            ? ($memberClients[(int)$entry->client_member_id] ?? null)
            : ($ownership[(int)$entry->project_id] ?? null);
        abort_unless($entry->client_id!==null,503,'Timesheet ownership unresolved');
        return $entry;
    });
    $employeeIds = $entries->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    $projectIds = $entries->pluck('project_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    $memberIds=$entries->pluck('client_member_id')->filter()->map(fn($id)=>(int)$id)->unique()->values()->all();

    $monthCursor = Carbon::parse($data['from'])->startOfMonth();
    $lastMonth = Carbon::parse($data['to'])->startOfMonth();
    $months = [];
    while ($monthCursor->lte($lastMonth)) {
        $months[] = $monthCursor->toDateString();
        $monthCursor->addMonth();
    }

    $approvals = collect();
    if ($months && ($projectIds || $memberIds)) {
        $approvals = DB::table('final_approvals')
            ->where('month','>=',min($months))->where('month','<=',max($months))
            ->where(function($q)use($projectIds,$memberIds,$employeeIds) {
                if($projectIds && $employeeIds)$q->where(function($q)use($employeeIds,$projectIds) {
                    $q->whereIn('employee_id',$employeeIds)->whereIn('project_id',$projectIds);
                });
                if($memberIds) {
                    if($projectIds && $employeeIds)$q->orWhereIn('client_member_id',$memberIds);
                    else $q->whereIn('client_member_id',$memberIds);
                }
            })->get();
    }

    return response()->json(['data' => [
        'from' => $data['from'],
        'to' => $data['to'],
        'entries' => $entries,
        'final_approvals' => $approvals,
    ]]);
});
