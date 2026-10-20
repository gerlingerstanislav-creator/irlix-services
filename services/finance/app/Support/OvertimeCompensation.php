<?php

namespace App\Support;

use DomainException;

/**
 * Estimated employee overtime compensation, not a posted payroll accrual.
 * Employees owns gross salary, optional contracted overtime hourly rate and
 * employee multiplier. The caller must select a unique effective-dated term
 * and verify overtime allocation with Clients/Timesheets independently.
 */
final class OvertimeCompensation
{
    private static function number(int|float|string|null $raw, string $field, bool $allowZero = true): float
    {
        if ($raw === null
            || !preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,6})?$/D', (string) $raw)) {
            throw new DomainException('Missing or invalid ' . $field);
        }
        $value = (float) $raw;
        if (!is_finite($value) || (!$allowZero && $value <= 0)) {
            throw new DomainException('Invalid nonpositive or nonfinite ' . $field);
        }
        return $value;
    }

    /**
     * @return array{hourly_base:float,hourly_base_source:string,hours:float,multiplier:float,estimated_gross_extra:float,quality:string}
     */
    public static function estimate(
        int|float|string $normHours,
        int|float|string $overtimeHours,
        int|float|string|null $grossSalary,
        int|float|string|null $contractHourlyRate,
        int|float|string|null $employeeMultiplier = null
    ): array {
        $norm = self::number($normHours, 'monthly working norm', false);
        $hours = self::number($overtimeHours, 'overtime hours');
        // Employee multiplier defaults to ×1 for the first release. No
        // Employee-specific multiplier source or mandatory setting yet.
        $multiplier = self::number($employeeMultiplier ?? 1, 'employee multiplier', false);
        if ($contractHourlyRate !== null) {
            $base = self::number($contractHourlyRate, 'contract overtime hourly rate');
            $source = 'contract_hourly_rate';
        } else {
            $gross = self::number($grossSalary, 'gross monthly salary');
            $base = $gross / $norm;
            $source = 'gross_monthly_norm';
        }
        $amount = $base * $hours * $multiplier;
        if (!is_finite($amount) || $amount > 999999999999999.99) {
            throw new DomainException('Estimated overtime amount exceeds financial precision');
        }
        return [
            'hourly_base' => round($base, 6),
            'hourly_base_source' => $source,
            'hours' => $hours,
            'multiplier' => $multiplier,
            'estimated_gross_extra' => round($amount, 2),
            'quality' => 'estimate_not_accrual',
        ];
    }
}
