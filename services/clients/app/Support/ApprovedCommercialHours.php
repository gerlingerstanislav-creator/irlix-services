<?php

namespace App\Support;

use DomainException;

final class ApprovedCommercialHours
{
    /**
     * Commercial hours are independent from actual timesheet work:
     * AM may approve fewer or more hours than were recorded. Never enforce
     * regular + overtime = worked. The two approved values are disjoint;
     * overtime is never added twice.
     *
     * @return array{regular_hours:float,overtime_hours:float,total_approved_hours:float,worked_hours:float,agreed_amount:float,worked_amount:float}
     */
    public static function calculate(
        float $regular, float $overtime, float $worked, float $hourlyRate, float $multiplier
    ): array {
        foreach ([$regular, $overtime, $worked, $hourlyRate] as $number) {
            if (!is_finite($number) || $number < 0) throw new DomainException('Invalid hours or rate');
        }
        if (!is_finite($multiplier) || $multiplier <= 0) {
            throw new DomainException('Invalid overtime multiplier');
        }
        $agreed = $hourlyRate * ($regular + $overtime * $multiplier);
        $performed = $hourlyRate * $worked;
        if (!is_finite($agreed) || !is_finite($performed)) {
            throw new DomainException('Commercial amount overflow');
        }
        return [
            'regular_hours' => round($regular, 2),
            'overtime_hours' => round($overtime, 2),
            'total_approved_hours' => round($regular + $overtime, 2),
            'worked_hours' => round($worked, 2),
            'agreed_amount' => round($agreed, 2),
            'worked_amount' => round($performed, 2),
        ];
    }
}
