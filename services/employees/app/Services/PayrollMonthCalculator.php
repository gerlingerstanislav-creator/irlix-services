<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Pure FOT calculation from Employees' dated employment, assignment and salary histories.
 * Employment dates are inclusive; only working days contribute to FOT, while any
 * calendar-day overlap is enough for the employee to appear in the yearly register.
 */
final class PayrollMonthCalculator
{
    private static function covers(object $row, string $day, string $from, string $to): bool
    {
        return $row->{$from} <= $day
            && (!$row->{$to} || $row->{$to} >= $day);
    }

    /** @param iterable<object> $periods @param iterable<object> $assignments */
    public function visible(iterable $periods, iterable $assignments, array $allowed, string $from, string $to): bool
    {
        foreach ($periods as $period) {
            $start = max($from, (string) $period->started_at);
            $end = min($to, (string) ($period->ended_at ?: $to));
            if ($start > $end) continue;

            foreach ($assignments as $assignment) {
                if (!isset($allowed[(int) ($assignment->department_id ?? 0)])) continue;
                $assignmentStart = max($start, (string) $assignment->effective_from);
                $assignmentEnd = min($end, (string) ($assignment->effective_to ?: $end));
                if ($assignmentStart <= $assignmentEnd) return true;
            }
        }
        return false;
    }

    /**
     * @param iterable<object> $periods Inclusive employment periods, including rehires.
     * @param iterable<object> $assignments Dated department assignments in chronological order.
     * @param iterable<object> $salaries Effective salary periods in chronological order.
     * @param iterable<object> $plans Non-cancelled salary reviews in chronological order.
     * @param array<string,bool> $working Platform Core calendar working days.
     * @param array<int,int> $monthWorkDays Whole-month working-day divisors.
     * @param array<int,bool> $allowed Department IDs in the caller's permitted filter scope.
     */
    public function calculate(int $year, array $working, array $monthWorkDays, iterable $periods,
        iterable $assignments, iterable $salaries, iterable $plans, array $allowed): array
    {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $current = 0.0;
            $planned = 0.0;
            $days = 0;
            $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            while ((int) $date->format('n') === $month) {
                $day = $date->format('Y-m-d');
                $date = $date->modify('+1 day');
                if (!isset($working[$day])) continue;

                $employed = false;
                foreach ($periods as $period) {
                    if (self::covers($period, $day, 'started_at', 'ended_at')) {
                        $employed = true;
                        break;
                    }
                }
                if (!$employed) continue;

                $assignment = null;
                foreach ($assignments as $item) {
                    if (self::covers($item, $day, 'effective_from', 'effective_to')) $assignment = $item;
                }
                if (!$assignment || !isset($allowed[(int) ($assignment->department_id ?? 0)])) continue;
                $days++;

                $salary = null;
                foreach ($salaries as $item) {
                    if (self::covers($item, $day, 'effective_from', 'effective_to')) $salary = $item;
                }
                // Missing salary history is not a reason to drop a legitimately
                // employed person from the register. Their unpriced days contribute zero.
                if (!$salary) continue;

                $actual = (float) $salary->gross_salary;
                $forecast = $actual;
                $effective = (string) $salary->effective_from;
                foreach ($plans as $plan) {
                    if ($plan->effective_month <= $day && $plan->effective_month >= $effective) {
                        $forecast = (float) $plan->gross_salary;
                        $effective = (string) $plan->effective_month;
                    }
                }
                $divisor = $monthWorkDays[$month] ?: 1;
                $current += $actual / $divisor;
                $planned += $forecast / $divisor;
            }
            $months[$month] = [
                'current' => round($current, 2),
                'planned' => round($planned, 2),
                'working_days' => $days,
            ];
        }
        return $months;
    }
}
