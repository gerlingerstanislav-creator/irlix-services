<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class CommercialData
{
    public function read(string $from, string $to, ?int $clientId = null, ?string $freezeToken = null): array
    {
        return DB::transaction(function () use ($from, $to, $clientId, $freezeToken): array {
            ReportingFreeze::lock();
            $snapshot = app(ClientMemberDirectory::class)->snapshot();
            $ownership = DB::table('timesheet_entries')->whereNotNull('project_id')->whereBetween('work_date', [$from, $to])->exists()
                ? app(AssignmentsDirectory::class)->snapshot()['project_clients'] : [];
            $members = [];
            foreach ($snapshot['members'] as $row) $members[$row['client_member_id']] = $row;
            $entries = DB::table('timesheet_entries')->whereBetween('work_date', [$from, $to])->orderBy('work_date')->orderBy('id')->get();
            $rows = [];
            foreach ($entries as $entry) {
                $member = $entry->client_member_id === null ? null : ($members[(int) $entry->client_member_id] ?? null);
                if ($entry->client_member_id !== null) {
                    abort_unless($member && $member['employee_id'] === ($entry->employee_id === null ? null : (int) $entry->employee_id), 503, 'Timesheet membership identity is unresolved');
                    $entry->client_id = $member['client_id'];
                    $entry->partner_specialist_id = $member['partner_specialist_id'];
                    $entry->specialist_name = $member['specialist_name'];
                    $terms = array_values(array_filter($snapshot['members'], fn ($t) => $t['client_member_id'] === (int) $entry->client_member_id
                        && $t['valid_from'] !== null && $t['valid_from'] <= $entry->work_date
                        && ($t['valid_to'] === null || $t['valid_to'] >= $entry->work_date)));
                    $entry->term_conflict = count($terms) !== 1;
                    $entry->member_term_id = count($terms) === 1 ? $terms[0]['member_term_id'] : null;
                } else {
                    abort_unless(isset($ownership[(int) $entry->project_id]), 503, 'Historical project ownership unavailable');
                    abort_if(DB::table('timesheet_legacy_sources')->where('legacy_entry_id', $entry->id)->exists(), 503, 'Legacy fact is already represented by canonical lineage');
                    $entry->client_id = $ownership[(int) $entry->project_id];
                    $entry->partner_specialist_id = null;
                    $entry->specialist_name = null;
                    $entry->term_conflict = false;
                    $entry->member_term_id = null;
                }
                if ($clientId === null || $entry->client_id === $clientId) $rows[] = $entry;
            }
            $memberIds = array_keys(array_filter($members, fn ($m) => $clientId === null || $m['client_id'] === $clientId));
            $projectIds = array_keys(array_filter($ownership, fn ($id) => $clientId === null || $id === $clientId));
            $owned = static fn ($q) => $q->whereIn('client_member_id', $memberIds ?: [0])->orWhereIn('project_id', $projectIds ?: [0]);
            $approvals = DB::table('final_approvals')->where($owned)->whereBetween('month', [substr($from, 0, 7).'-01', substr($to, 0, 7).'-01'])->orderBy('id')->get();
            $exceptions = DB::table('final_approval_exceptions')->where($owned)->whereBetween('work_date', [$from, $to])->orderBy('id')->get();
            $revision = $clientId !== null ? ReportingFreeze::revision($clientId, $from, $to, $snapshot['members'], $ownership)
                : hash('sha256', json_encode([$rows, $approvals, $exceptions], JSON_THROW_ON_ERROR));
            if ($freezeToken !== null) {
                $freeze = DB::table('reporting_freezes')->where('freeze_token', $freezeToken)->first();
                abort_unless($freeze && (int) $freeze->client_id === $clientId && $freeze->period_start === $from && $freeze->period_end === $to, 409, 'Reporting freeze does not cover this snapshot');
                abort_unless(hash_equals($freeze->revision, $revision), 409, 'Frozen timesheet revision changed');
            }
            return ['from' => $from, 'to' => $to, 'entries' => $rows,
                'final_approvals' => $approvals, 'final_approval_exceptions' => $exceptions,
                'complete' => true, 'count' => count($rows), 'schema_version' => 2,
                'client_member_catalog_complete' => true, 'membership_revision' => $snapshot['revision'], 'revision' => $revision];
        });
    }
}
