<?php

namespace App\Support;

/**
 * Reclassifies trusted Timesheets daily revenue using Clients' explicit
 * approved regular/overtime hours and the immutable multiplier snapshot of
 * its reporting period. Facts stay untouched; no global employee-month lock.
 */
final class CommercialRevenueAllocation
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $overrides
     * @param array<int,array<string,mixed>> $periods
     * @return array{rows:array,errors:array}
     */
    public static function apply(array $rows, array $overrides, array $periods, string $month): array
    {
        $periodById = [];
        foreach ($periods as $period) {
            $id = (int) ($period['id'] ?? 0);
            if ($id > 0) $periodById[$id] = $period;
        }
        $errors = [];
        $seen = [];
        foreach ($overrides as $override) {
            $periodId = (int) ($override['reporting_period_id'] ?? 0);
            $termId = (int) ($override['member_term_id'] ?? 0);
            $key = $periodId . ':' . $termId;
            if ($periodId <= 0 || $termId <= 0 || isset($seen[$key]) || !isset($periodById[$periodId])) {
                $errors[] = ['reason' => 'invalid_or_duplicate_client_approval', 'reporting_period_id' => $periodId, 'term_id' => $termId];
                continue;
            }
            $seen[$key] = true;
            $period = $periodById[$periodId];
            $matching = [];
            foreach ($rows as $idx => $row) {
                if ((int) ($row['reporting_period_id'] ?? 0) === $periodId
                    && (int) ($row['term_id'] ?? 0) === $termId) $matching[] = $idx;
            }
            // A client approval is a total for the entire ReportingPeriod.
            // Do not assign it to one month if the period crosses a boundary:
            // the API lacks independent monthly amounts.
            $start = substr((string) ($period['period_start'] ?? ''), 0, 10);
            $end = substr((string) ($period['period_end'] ?? ''), 0, 10);
            if (substr($start, 0, 7) !== $month || substr($end, 0, 7) !== $month) {
                $errors[] = ['reason' => 'client_approved_hours_requires_month_allocation',
                    'reporting_period_id' => $periodId, 'term_id' => $termId];
                continue;
            }
            $regular = self::nonnegative($override['hours'] ?? null);
            $extra = self::nonnegative($override['overtime_hours'] ?? null);
            $factor = self::positive($period['overtime_multiplier'] ?? null);
            if ($regular === null || $extra === null || $factor === null) {
                $errors[] = ['reason' => 'invalid_client_approved_hours_or_multiplier',
                    'reporting_period_id' => $periodId, 'term_id' => $termId];
                continue;
            }
            if (!$matching) {
                // An AM may approve more hours than worked, but source identity
                // and original rate must still be independently verifiable.
                $errors[] = ['reason' => 'client_approved_hours_without_timesheet_source',
                    'reporting_period_id' => $periodId, 'term_id' => $termId];
                continue;
            }
            $rate = (float) $rows[$matching[0]]['rate'];
            $worked = 0.0;
            foreach ($matching as $i) {
                if (abs((float) $rows[$i]['rate'] - $rate) > 0.000001) {
                    $errors[] = ['reason' => 'client_approval_rate_ambiguous',
                        'reporting_period_id' => $periodId, 'term_id' => $termId];
                    continue 2;
                }
                $worked += (float) $rows[$i]['hours'];
            }
            $total = ($regular + $extra * $factor) * $rate;
            if (!is_finite($total) || $total > 999999999999.99 || $worked <= 0) {
                $errors[] = ['reason' => 'client_approval_amount_invalid',
                    'reporting_period_id' => $periodId, 'term_id' => $termId];
                continue;
            }
            // Round once per term and distribute fractional cents using a
            // stable largest-remainder method. Preserve exactly the agreed sum.
            $cents = (int) round($total * 100);
            $parts = [];
            $fractions = [];
            $allocated = 0;
            foreach ($matching as $i) {
                $exact = $cents * (float) $rows[$i]['hours'] / $worked;
                $parts[$i] = (int) floor($exact);
                $allocated += $parts[$i];
                $fractions[] = ['index' => $i, 'remainder' => $exact - $parts[$i]];
            }
            usort($fractions, static fn ($a, $b) =>
                ($b['remainder'] <=> $a['remainder']) ?: ($a['index'] <=> $b['index']));
            for ($i = 0; $i < $cents - $allocated; $i++) $parts[$fractions[$i]['index']]++;
            foreach ($matching as $i) {
                $rows[$i]['amount'] = (float) ($parts[$i] / 100);
                $rows[$i]['commercial_approval_source'] = 'client_reporting_period';
                $rows[$i]['approved_regular_hours_total'] = $regular;
                $rows[$i]['approved_overtime_hours_total'] = $extra;
                $rows[$i]['overtime_multiplier'] = $factor;
            }
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function nonnegative(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', (string) $value)) return null;
        $f = (float) $value;
        return is_finite($f) && $f >= 0 ? $f : null;
    }

    private static function positive(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,3})?$/D', (string) $value)) return null;
        $f = (float) $value;
        return is_finite($f) && $f > 0 ? $f : null;
    }
}
