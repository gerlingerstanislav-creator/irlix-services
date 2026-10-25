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
     * Independent, read-only canonical facts. Scoped reads are authorised by the
     * Clients integration token and the complete set of ClientMember IDs sent by
     * Clients; do not call Clients while Clients is waiting for this response.
     */
    public function read(string $from, string $to, ?array $memberIds = null): array
    {
        $memberIds = $memberIds === null ? null : array_values(array_unique(array_map('intval', $memberIds)));
        return DB::transaction(function () use ($from, $to, $memberIds): array {
            ReportingFreeze::lock();
            // A legacy fact must either be represented by a canonical lineage,
            // or explicitly reconciled before it can enter financial reporting.
            $legacyIds = TimesheetEntry::query()->whereBetween('work_date', [$from, $to])
                ->whereNull('client_member_id')->pluck('id')->all();
            foreach (array_chunk($legacyIds, 1000) as $ids) {
                $lineages = DB::table('timesheet_legacy_sources')
                    ->whereIn('legacy_entry_id', $ids)->pluck('target_entry_id', 'legacy_entry_id');
                abort_unless(count($lineages) === count($ids)
                    && TimesheetEntry::query()->whereIn('id', array_values($lineages->all()))
                        ->whereNotNull('client_member_id')->count() === count(array_unique($lineages->all())),
                    503, 'Unreconciled legacy Timesheets facts require explicit migration');
            }

            $facts = TimesheetEntry::query()->whereBetween('work_date', [$from, $to])
                ->whereNotNull('client_member_id');
            $months = [substr($from, 0, 7).'-01', substr($to, 0, 7).'-01'];
            $approvals = FinalApproval::query()->whereBetween('month', $months);
            $exceptions = FinalApprovalException::query()->whereBetween('work_date', [$from, $to]);
            // Unlike canonical duplicates, old approvals do not have safe
            // lineage. Never turn an unverified legacy approval into a final one.
            abort_if((clone $approvals)->whereNull('client_member_id')->exists()
                || (clone $exceptions)->whereNull('client_member_id')->exists(), 503,
                'Unreconciled legacy approval requires explicit verification');
            if ($memberIds !== null) {
                $facts->whereIn('client_member_id', $memberIds ?: [0]);
                $approvals->whereIn('client_member_id', $memberIds ?: [0]);
                $exceptions->whereIn('client_member_id', $memberIds ?: [0]);
            }
            $allFacts = $facts->orderBy('id')->get();
            $allApprovals = $approvals->orderBy('id')->get();
            $allExceptions = $exceptions->orderBy('id')->get();
            // Preserve all mutable fields in the revision, not merely rendered
            // hours. This also protects immutable sent reporting snapshots.
            $revision = hash('sha256', json_encode([
                $memberIds, $allFacts->map(fn ($e) => $e->getAttributes())->all(),
                $allApprovals->map(fn ($e) => $e->getAttributes())->all(),
                $allExceptions->map(fn ($e) => $e->getAttributes())->all(),
            ], JSON_THROW_ON_ERROR));
            $entries = $allFacts->map(fn ($row) => [
                'client_member_id' => (int) $row->client_member_id,
                'employee_id' => $row->employee_id === null ? null : (int) $row->employee_id,
                'work_date' => substr((string) $row->work_date, 0, 10),
                'hours' => (string) $row->hours,
                'description' => $row->description,
            ])->all();
            $finalApprovals = $allApprovals->map(fn ($row) => [
                'client_member_id' => (int) $row->client_member_id,
                'month' => substr((string) $row->month, 0, 10),
            ])->all();
            $finalExceptions = $allExceptions->map(fn ($row) => [
                'client_member_id' => (int) $row->client_member_id,
                'work_date' => substr((string) $row->work_date, 0, 10),
            ])->all();
            return [
                'schema_version' => 3, 'from' => $from, 'to' => $to,
                'complete' => true, 'count' => count($entries), 'revision' => $revision,
                'entries' => $entries, 'final_approvals' => $finalApprovals,
                'final_approval_exceptions' => $finalExceptions,
            ];
        });
    }
}
