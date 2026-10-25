<?php

namespace App\Services;

use App\Integrations\TimesheetsCommercialClient;
use App\Models\MemberTerm;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class CashFlowReportService
{
    public function __construct(private readonly TimesheetsCommercialClient $timesheets) {}

    public function report(Request $request, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth()->toDateString();
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        // Index facts, approvals and exceptions once. The old implementation
        // rescanned every imported fact for each commercial term (O(terms * facts)).
        $snapshot = $this->timesheets->range($request, $start, $end);
        $byMember = [];
        foreach ($snapshot['entries'] as $entry) {
            $byMember[(int) $entry['client_member_id']][] = $entry;
        }
        $approved = [];
        foreach ($snapshot['final_approvals'] as $approval) {
            if (substr((string) $approval['month'], 0, 10) === $start) {
                $approved[(int) $approval['client_member_id']] = true;
            }
        }
        $exceptions = [];
        foreach ($snapshot['final_approval_exceptions'] as $exception) {
            $exceptions[(int) $exception['client_member_id']][substr((string) $exception['work_date'], 0, 10)] = true;
        }

        $terms = MemberTerm::query()
            ->with('clientMember')
            ->whereNotNull('client_member_id')
            ->whereDate('valid_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start))
            ->get();

        $rows = [];
        foreach ($terms as $term) {
            $member = $term->clientMember;
            if ($member === null) continue;
            $from = max(substr((string) $term->valid_from, 0, 10), $start);
            $to = min($term->valid_to ? substr((string) $term->valid_to, 0, 10) : $end, $end);
            $memberId = (int) $member->id;
            $hours = 0.0;
            $hasException = false;
            foreach ($byMember[$memberId] ?? [] as $entry) {
                $date = substr((string) $entry['work_date'], 0, 10);
                if (!empty($entry['term_conflict']) || $date < $from || $date > $to) continue;
                $hours += (float) $entry['hours'];
                if (isset($exceptions[$memberId][$date])) $hasException = true;
            }
            $hours = round($hours, 2);
            $rate = $term->hourly_rate === null ? null : (float) $term->hourly_rate;
            $amount = $rate === null ? null : round($hours * $rate, 2);
            $finalApproved = isset($approved[$memberId]) && !$hasException;
            $rows[] = [
                'term_id' => (int) $term->id,
                'project_member_id' => $term->project_member_id,
                'client_member_id' => $memberId,
                'client_id' => (int) $member->client_id,
                'timesheet_hours' => $hours,
                'timesheet_amount' => $amount,
                'confirmed_hours' => $finalApproved ? $hours : 0,
                'confirmed_amount' => $rate === null ? null : ($finalApproved ? $amount : 0),
                'final_approved' => $finalApproved,
            ];
        }
        return ['month' => $month, 'rows' => $rows];
    }
}
