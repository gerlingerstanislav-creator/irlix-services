<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class AssignmentReconciler
{
    // A reconciliation pass is idempotent: never remove facts or approvals,
    // only change term_conflict with an audit record in the same transaction.
    public function __construct(private readonly ClientMemberDirectory $directory) {}

    public function run(): void
    {
        // The upstream catalog must be complete. Read it outside the
        // reporting-snapshot lock, as in the original reconciliation contract.
        $snapshot = $this->directory->snapshot();
        $byMember = [];
        foreach ($snapshot['members'] as $row) {
            $id = (int) $row['client_member_id'];
            if (!isset($byMember[$id])) {
                $byMember[$id] = ['client_id' => (int) $row['client_id'], 'terms' => []];
            }
            if ($row['valid_from'] !== null) $byMember[$id]['terms'][] = $row;
        }
        $lockedByClient = [];
        foreach ($snapshot['locked_periods'] as $period) {
            $lockedByClient[(int) $period['client_id']][] = $period;
        }

        // A single transaction previously held a GLOBAL reporting-snapshot
        // advisory lock while scanning every fact, term and freeze one by one.
        // With imported history this starved read-only Finance snapshots.
        // Each bounded chunk is independently atomic and the next scheduled
        // pass safely retries any incomplete reconciliation.
        DB::table('timesheet_entries')->whereNotNull('client_member_id')
            ->orderBy('id')->chunkById(250, function ($batch) use ($byMember, $lockedByClient): void {
                $ids = $batch->pluck('id')->all();
                DB::transaction(function () use ($ids, $byMember, $lockedByClient): void {
                    ReportingFreeze::lock();

                    // Fetch protected local periods once for the chunk, while
                    // holding the same gate used by reporting freeze writers.
                    $frozenByClient = DB::table('reporting_freezes')
                        ->get(['client_id', 'period_start', 'period_end'])
                        ->groupBy('client_id');

                    $entries = DB::table('timesheet_entries')->whereIn('id', $ids)
                        ->orderBy('id')->lockForUpdate()->get();

                    foreach ($entries as $entry) {
                        $member = $byMember[(int) $entry->client_member_id] ?? null;
                        $clientId = $member['client_id'] ?? null;
                        $date = (string) $entry->work_date;

                        $isProtected = false;
                        if ($clientId !== null) {
                            foreach ($lockedByClient[$clientId] ?? [] as $lock) {
                                if ($lock['period_start'] <= $date && $lock['period_end'] >= $date) {
                                    $isProtected = true;
                                    break;
                                }
                            }
                            if (!$isProtected) {
                                foreach ($frozenByClient->get($clientId, collect()) as $lock) {
                                    if ($lock->period_start <= $date && $lock->period_end >= $date) {
                                        $isProtected = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($isProtected) continue;

                        $matches = 0;
                        foreach ($member['terms'] ?? [] as $term) {
                            if ($term['valid_from'] > $date
                                || (!empty($term['valid_to']) && $term['valid_to'] < $date)) continue;
                            if ($entry->employee_id !== null
                                ? (int) ($term['employee_id'] ?? 0) !== (int) $entry->employee_id
                                : $term['partner_specialist_id'] === null) continue;
                            $matches++;
                        }
                        $conflict = $matches !== 1;
                        if ((bool) $entry->term_conflict === $conflict) continue;

                        DB::table('timesheet_entries')->where('id', $entry->id)
                            ->update(['term_conflict' => $conflict, 'updated_at' => now()]);
                        DB::table('timesheet_audit')->insert([
                            'action' => $conflict ? 'client_member_term_conflict' : 'client_member_term_conflict_resolved',
                            'actor_employee_id' => null,
                            'employee_id' => $entry->employee_id,
                            'project_id' => null,
                            'client_member_id' => $entry->client_member_id,
                            'work_date' => $entry->work_date,
                            'before' => json_encode(['term_conflict' => (bool) $entry->term_conflict]),
                            'after' => json_encode(['term_conflict' => $conflict]),
                            'created_at' => now(),
                        ]);
                    }
                });
            }, 'id');
    }
}
