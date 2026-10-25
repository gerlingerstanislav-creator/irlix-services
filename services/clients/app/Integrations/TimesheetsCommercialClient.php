<?php

namespace App\Integrations;

use App\Models\MemberTerm;
use App\Models\ClientMember;
use App\Support\TimesheetMemberships;
use App\Support\CommercialSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class TimesheetsCommercialClient
{
    public function range(Request $request, string $from, string $to, ?int $clientId = null, ?string $freezeToken = null): array
    {
        $memberIds = $clientId === null ? null : ClientMember::query()
            ->where('client_id', $clientId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($clientId !== null && $memberIds === []) {
            abort_if($freezeToken !== null, 422, 'No client memberships for reporting freeze');
            return ['from' => $from, 'to' => $to, 'complete' => true, 'count' => 0,
                'entries' => [], 'final_approvals' => [], 'final_approval_exceptions' => []];
        }
        $query = ['from' => $from, 'to' => $to];
        if ($clientId !== null) {
            $query['client_id'] = $clientId;
            $query['client_member_ids'] = $memberIds;
            if ($freezeToken !== null) $query['freeze_token'] = $freezeToken;
        }
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $pending = Http::acceptJson()
            ->withHeaders(['X-Irlix-Timesheets-Token' => (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '')])
            ->connectTimeout(3)->timeout(10);
        if ($token) $pending = $pending->withToken((string) $token);
        $response = $pending->get(
            rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000/internal'), '/').'/cash-flow-facts',
            $query
        );
        abort_unless($response->successful(), 503, 'Timesheets service is unavailable');
        $data = $response->json('data');
        abort_unless(is_array($data) && ($data['complete'] ?? false) === true
            && ($data['schema_version'] ?? null) === 3
            && ($data['from'] ?? null) === $from && ($data['to'] ?? null) === $to
            && is_array($data['entries'] ?? null) && array_is_list($data['entries']), 503,
            'Timesheets returned an invalid cash-flow snapshot');

        // Commercial terms are owned by Clients: resolve them locally, by
        // actual work date, never by project or Timesheets' cached catalog.
        $memberIds = [];
        foreach ($data['entries'] as $entry) {
            abort_unless(is_array($entry) && isset($entry['client_member_id'])
                && ctype_digit((string) $entry['client_member_id'])
                && (int) $entry['client_member_id'] > 0
                && isset($entry['work_date']) && is_string($entry['work_date']), 503,
                'Timesheets returned an invalid cash-flow fact');
            $memberIds[] = (int) $entry['client_member_id'];
        }
        $termsByMember = [];
        foreach (array_chunk(array_values(array_unique($memberIds)), 1000) as $ids) {
            $terms = MemberTerm::query()->whereIn('client_member_id', $ids)
                ->whereDate('valid_from', '<=', $to)
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
                ->get(['id', 'client_member_id', 'valid_from', 'valid_to']);
            foreach ($terms as $term) $termsByMember[(int) $term->client_member_id][] = $term;
        }
        foreach ($data['entries'] as &$entry) {
            $day = substr($entry['work_date'], 0, 10);
            $matched = [];
            foreach ($termsByMember[(int) $entry['client_member_id']] ?? [] as $term) {
                if (substr((string) $term->valid_from, 0, 10) <= $day
                    && ($term->valid_to === null || substr((string) $term->valid_to, 0, 10) >= $day)) {
                    $matched[] = (int) $term->id;
                }
            }
            $entry['member_term_id'] = count($matched) === 1 ? $matched[0] : null;
            $entry['term_conflict'] = count($matched) !== 1;
        }
        unset($entry);
        $validated = CommercialSnapshot::validate($data, $from, $to, $clientId);
        if ($clientId !== null) $validated['membership_revision'] = TimesheetMemberships::snapshot()['revision'];
        return $validated;
    }
}
