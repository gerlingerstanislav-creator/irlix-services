<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class ReportingFreeze
{
    // All timesheet mutations and commercial snapshots share this short gate.
    // Persistent freezes survive network failures and process restarts; no TTL
    // may silently reopen a period already sent by Clients.
    public static function lock(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['timesheets:reporting-snapshot']);
    }

    public static function assertEditable(int $clientId, string $from, ?string $to = null): void
    {
        self::lock();
        abort_if(DB::table('reporting_freezes')->where('client_id', $clientId)
            ->where('period_start', '<=', $to ?? $from)->where('period_end', '>=', $from)->exists(),
            423, 'Отчётный период заморожен для отправки клиенту.');
    }

    public static function revision(int $clientId, string $from, string $to, array $members, array $projectClients): string
    {
        $memberIds = array_values(array_unique(array_column(array_filter($members, fn ($m) => (int) $m['client_id'] === $clientId), 'client_member_id')));
        $projectIds = array_keys(array_filter($projectClients, fn ($id) => (int) $id === $clientId));
        $owned = static function ($q) use ($memberIds, $projectIds): void {
            $q->whereIn('client_member_id', $memberIds ?: [0])->orWhereIn('project_id', $projectIds ?: [0]);
        };
        $entries = DB::table('timesheet_entries')->where($owned)->whereBetween('work_date', [$from, $to])->orderBy('id')->get()->all();
        $approvals = DB::table('final_approvals')->where($owned)
            ->whereBetween('month', [substr($from, 0, 7).'-01', substr($to, 0, 7).'-01'])->orderBy('id')->get()->all();
        $exceptions = DB::table('final_approval_exceptions')->where($owned)->whereBetween('work_date', [$from, $to])->orderBy('id')->get()->all();
        return hash('sha256', json_encode([$entries, $approvals, $exceptions], JSON_THROW_ON_ERROR));
    }
}
