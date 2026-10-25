<?php

namespace App\Services;

use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use App\Models\TimesheetEntry;
use App\Support\ReportingFreeze;
use Illuminate\Support\Facades\DB;

final class CashFlowFactsReader
{
    /**
     * A read-only, self-contained Timesheets snapshot for Clients.
     * Do not fetch the Clients directory: the caller IS the Clients service.
     */
    public function read(string $from, string $to): array
    {
        return DB::transaction(function () use ($from, $to): array {
            ReportingFreeze::lock();
            $facts = TimesheetEntry::query()->whereBetween('work_date', [$from, $to]);
            abort_if((clone $facts)->whereNull('client_member_id')->exists(), 503,
                'Commercial data contains unreconciled legacy facts');

            $approvalMonths = [substr($from, 0, 7).'-01', substr($to, 0, 7).'-01'];
            $approvals = FinalApproval::query()->whereBetween('month', $approvalMonths);
            $exceptions = FinalApprovalException::query()->whereBetween('work_date', [$from, $to]);
            abort_if((clone $approvals)->whereNull('client_member_id')->exists()
                || (clone $exceptions)->whereNull('client_member_id')->exists(), 503,
                'Commercial approvals contain unreconciled legacy identities');

            $entries = $facts->orderBy('work_date')->orderBy('id')
                ->get(['client_member_id', 'employee_id', 'work_date', 'hours', 'description'])
                ->map(fn ($entry) => [
                    'client_member_id' => $entry->client_member_id === null ? null : (int) $entry->client_member_id,
                    'employee_id' => $entry->employee_id === null ? null : (int) $entry->employee_id,
                    'work_date' => substr((string) $entry->work_date, 0, 10),
                    'hours' => (string) $entry->hours,
                    'description' => $entry->description,
                ])->all();
            $finalApprovals = $approvals->orderBy('id')->get(['client_member_id', 'month'])
                ->map(fn ($row) => [
                    'client_member_id' => (int) $row->client_member_id,
                    'month' => substr((string) $row->month, 0, 10),
                ])->all();
            $finalExceptions = $exceptions->orderBy('id')->get(['client_member_id', 'work_date'])
                ->map(fn ($row) => [
                    'client_member_id' => (int) $row->client_member_id,
                    'work_date' => substr((string) $row->work_date, 0, 10),
                ])->all();
            return [
                'schema_version' => 3, 'from' => $from, 'to' => $to,
                'complete' => true, 'count' => count($entries),
                'entries' => $entries,
                'final_approvals' => $finalApprovals,
                'final_approval_exceptions' => $finalExceptions,
            ];
        });
    }
}
