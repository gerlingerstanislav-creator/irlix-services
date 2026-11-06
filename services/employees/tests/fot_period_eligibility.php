<?php

require_once __DIR__.'/../app/Services/PayrollMonthCalculator.php';

use App\Services\PayrollMonthCalculator;

function row(array $values): object { return (object) $values; }
function same(mixed $actual, mixed $expected, string $why): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($why.': expected '.var_export($expected, true)
            .', got '.var_export($actual, true));
    }
}

$calc = new PayrollMonthCalculator();
$working = array_fill_keys(['2026-01-05','2026-01-06','2026-01-07','2026-01-08','2026-01-09'], true);
$monthDays = array_fill(1, 12, 0);
$monthDays[1] = 5;
$allYear = [row(['started_at' => '2026-01-01', 'ended_at' => '2026-12-31'])];
$halfMonth = [row(['started_at' => '2026-01-07', 'ended_at' => '2026-01-08'])];
$assignment = [row(['effective_from' => '2026-01-01', 'effective_to' => null, 'department_id' => 10])];
$salary = [row(['effective_from' => '2025-01-01', 'effective_to' => null, 'gross_salary' => 100000])];
$plans = [row(['effective_month' => '2026-01-01', 'gross_salary' => 120000])];

same($calc->visible($halfMonth, $assignment, [10 => true], '2026-01-01', '2026-12-31'), true,
    'Hired and fired within the selected year remains visible');
$months = $calc->calculate(2026, $working, $monthDays, $halfMonth, $assignment, $salary, $plans, [10 => true]);
same($months[1]['current'], 40000.0, 'Hiring and firing prorates actual FOT');
same($months[1]['planned'], 48000.0, 'Hiring and firing prorates planned FOT');
same($months[1]['working_days'], 2, 'Only employed working days are counted');

$transfers = [
    row(['effective_from' => '2026-01-01', 'effective_to' => '2026-01-07', 'department_id' => 10]),
    row(['effective_from' => '2026-01-08', 'effective_to' => null, 'department_id' => 20]),
];
$source = $calc->calculate(2026, $working, $monthDays, $halfMonth, $transfers, $salary, [], [10 => true]);
$target = $calc->calculate(2026, $working, $monthDays, $halfMonth, $transfers, $salary, [], [20 => true]);
same($source[1]['current'], 20000.0, 'Transfer keeps source department FOT');
same($target[1]['current'], 20000.0, 'Transfer assigns subsequent FOT to target department');

$legacy = [row(['started_at' => '2026-01-07', 'ended_at' => '2026-01-08', 'department_id' => 10])];
same($calc->visible($legacy, [], [10 => true], '2026-01-01', '2026-12-31'), true,
    'Pre-assignment-migration employment department is still visible');
same($calc->calculate(2026, $working, $monthDays, $legacy, [], $salary, [], [10 => true])[1]['current'],
    40000.0, 'Legacy departmental employment accrues in its historical department');

$weekendHire = [row(['started_at' => '2026-01-03', 'ended_at' => '2026-01-03'])];
same($calc->visible($weekendHire, $assignment, [10 => true], '2026-01-01', '2026-12-31'), true,
    'One-day non-working employment must remain in register');
same($calc->calculate(2026, $working, $monthDays, $weekendHire, $assignment, $salary, [], [10 => true])[1]['current'], 0.0,
    'Non-working-only period accrues no FOT');
same($calc->calculate(2026, $working, $monthDays, $allYear, $assignment, [], [], [10 => true])[1]['current'], 0.0,
    'Missing salary record does not accrue invented FOT');
same($calc->visible($allYear, $assignment, [20 => true], '2026-01-01', '2026-12-31'), false,
    'Out-of-scope direction is never visible');
same($calc->visible([row(['started_at' => '2025-01-01', 'ended_at' => '2025-12-31'])],
    $assignment, [10 => true], '2026-01-01', '2026-12-31'), false,
    'Employee with no overlap in selected year is excluded');

$rehires = [
    row(['started_at' => '2026-01-06', 'ended_at' => '2026-01-06']),
    row(['started_at' => '2026-01-09', 'ended_at' => null]),
];
same($calc->calculate(2026, $working, $monthDays, $rehires, $assignment, $salary, [], [10 => true])[1]['current'], 40000.0,
    'Gap between termination and rehire is not charged');
echo "Payroll period and FOT regression checks passed\n";
