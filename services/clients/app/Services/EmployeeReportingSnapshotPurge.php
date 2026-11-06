<?php

namespace App\Services;

use App\Models\ReportingPeriod;

/**
 * Erase an employee's rows from persisted client-report snapshots.
 *
 * Paid/sent periods belong to the client, not to the employee: their status,
 * dates and other employees' rows survive. The deleted employee's financial
 * detail and potentially stale aggregate figures cannot survive admin purge.
 */
final class EmployeeReportingSnapshotPurge
{
    public static function apply(
        int $employeeId, array $memberIds, array $termIds, array $ranges, bool $preflight
    ): void {
        $members = array_fill_keys(array_map('intval', $memberIds), true);
        $terms = array_fill_keys(array_map('intval', $termIds), true);
        $clientIds = array_values(array_unique(array_map(
            static fn ($r) => (int) $r['client_id'], $ranges
        )));

        $query = ReportingPeriod::query()->where(static function ($q) use ($clientIds): void {
            if ($clientIds) $q->whereIn('client_id', $clientIds)->orWhereNotNull('timesheet_snapshot');
            else $q->whereNotNull('timesheet_snapshot');
        })->orderBy('id');

        $query->chunkById(100, static function ($periods) use (
            $employeeId, $members, $terms, $ranges, $clientIds, $preflight
        ): void {
            foreach ($periods as $period) {
                $rangeHit = false;
                foreach ($ranges as $range) {
                    if ((int) $period->client_id === (int) $range['client_id']
                        && substr((string) $period->period_start, 0, 10) <= $range['to']
                        && substr((string) $period->period_end, 0, 10) >= $range['from']) {
                        $rangeHit = true;
                        break;
                    }
                }

                $raw = (string) ($period->timesheet_snapshot ?? '');
                $changed = false;
                $payload = null;
                if ($raw !== '') {
                    $payload = json_decode($raw, true);
                    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                        // A malformed snapshot outside the owned client's scope
                        // must not block an unrelated employee's deletion.
                        abort_if(in_array((int) $period->client_id, $clientIds, true)
                            || preg_match('/"employee_id"\\s*:\\s*'.preg_quote((string)$employeeId, '/').'\\b/', $raw),
                            409, 'Unparseable reporting snapshot needs reconciliation before erasure');
                        continue;
                    }
                    foreach (['entries', 'final_approvals', 'final_approval_exceptions'] as $key) {
                        if (!isset($payload[$key])) continue;
                        abort_unless(is_array($payload[$key]), 409,
                            'Malformed reporting snapshot detail needs reconciliation');
                        $filtered = [];
                        foreach ($payload[$key] as $row) {
                            abort_unless(is_array($row), 409,
                                'Malformed reporting snapshot row needs reconciliation');
                            $owned = (int)($row['employee_id'] ?? 0) === $employeeId
                                || isset($members[(int)($row['client_member_id'] ?? 0)])
                                || isset($terms[(int)($row['member_term_id'] ?? 0)]);
                            if ($owned) $changed = true;
                            else $filtered[] = $row;
                        }
                        $payload[$key] = $filtered;
                    }
                }
                if (!$changed && !$rangeHit) continue;
                if ($preflight) continue;

                $values = [
                    // Stored totals were calculated with now-erased employee
                    // facts. Null forces readers to derive fresh totals from
                    // surviving members rather than presenting a false sum.
                    'confirmed_hours' => null,
                    'confirmed_amount' => null,
                    'timesheet_revision' => null,
                    'updated_at' => now(),
                ];
                if ($changed) {
                    $payload['count'] = count($payload['entries'] ?? []);
                    $payload['revision'] = null;
                    $payload['membership_revision'] = null;
                    $values['timesheet_snapshot'] = json_encode(
                        $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    );
                }
                ReportingPeriod::query()->whereKey($period->id)->update($values);
            }
        });
    }
}
