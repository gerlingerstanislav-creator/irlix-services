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

    /**
     * Legacy employment periods can predate the assignment-history migration.
     * Use their recorded department only on dates with no explicit assignment;
     * never use the employee's current department for past payroll.
     */
    private function departmentAt(iterable $periods, iterable $assignments, string $day): ?int
    {
        $period = null;
        foreach ($periods as $item) {
            if (self::covers($item, $day, 'started_at', 'ended_at')) $period = $item;
        }
        if (!$period) return null;

        $assignment = null;
        foreach ($assignments as $item) {
            if (self::covers($item, $day, 'effective_from', 'effective_to')) $assignment = $item;
        }
        $department = $assignment ? $assignment->department_id : ($period->department_id ?? null);
        return $department === null ? null : (int) $department;
    }

    /** A calendar-day overlap counts even when no paid working day exists. */
    public function visible(iterable $periods, iterable $assignments, array $allowed, string $from, string $to): bool
    {
        for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $to; $date = $date->modify('+1 day')) {
            $department = $this->departmentAt($periods, $assignments, $date->format('Y-m-d'));
            if ($department !== null && isset($allowed[$department])) return true;
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

                $department = $this->departmentAt($periods, $assignments, $day);
                if ($department === null || !isset($allowed[$department])) continue;
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
