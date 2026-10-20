<?php

namespace App\Support;

use DomainException;

/**
 * Pure monthly commercial-overtime suggestion/validation.
 *
 * A trusted Clients caller must obtain ALL commercial projects and the
 * employee-month completeness proof from Timesheets before passing complete=true.
 * This calculator does NOT grant AM access, persist allocations or certify
 * Timesheet readiness. It never reclassifies source TimeEntries.
 */
final class OvertimeAllocation
{
    private static function hundredths(int|float|string $value, string $field): int
    {
        $raw = (string) $value;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', $raw)) {
            throw new DomainException($field . ' must be nonnegative hours, max 2 decimal places');
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * @param array<int, array{project_id:int, hours:string|int|float, plan_hours:string|int|float|null}> $projects
     * @return array<string,mixed>
     */
    public static function suggest(
        int $employeeId,
        string $month,
        int|float|string $normHours,
        array $projects,
        bool $fullMonthComplete
    ): array {
        if ($employeeId <= 0 || !preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month)) {
            throw new DomainException('Invalid employee or service month');
        }
        $norm = self::hundredths($normHours, 'norm_hours');
        if ($norm === 0) throw new DomainException('Monthly norm must be positive');

        $seen = [];
        $rows = [];
        $total = 0;
        $excess = 0;
        foreach ($projects as $project) {
            $id = (int) ($project['project_id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                throw new DomainException('Projects must have distinct positive IDs');
            }
            $seen[$id] = true;
            $hours = self::hundredths($project['hours'] ?? '', 'hours');
            $plan = array_key_exists('plan_hours', $project) && $project['plan_hours'] !== null
                ? self::hundredths($project['plan_hours'], 'plan_hours') : null;
            $deviation = $plan === null ? 0 : max(0, $hours - $plan);
            $total += $hours;
            $excess += $deviation;
            $rows[] = [
                'project_id' => $id, 'hours_units' => $hours,
                'planned_units' => $plan, 'excess_units' => $deviation,
                'suggested_units' => 0,
            ];
        }
        usort($rows, static fn (array $a, array $b): int =>
            $a['project_id'] <=> $b['project_id']);
        $overtime = max(0, $total - $norm);

        // Allocate only as much of O as can be explained by positive deviations
        // from a known project plan. Never fabricate an allocation for a
        // project with no plan or silently assign remaining hours.
        $suggestible = min($overtime, $excess);
        if ($excess <= $overtime) {
            foreach ($rows as &$row) $row['suggested_units'] = $row['excess_units'];
            unset($row);
        } elseif ($suggestible > 0) {
            $assigned = 0;
            $remainders = [];
            foreach ($rows as $index => &$row) {
                // Monetary math is separate. Here integers prevent sub-0.01h
                // drift and preserve exact sum across clients.
                $weighted = $suggestible * $row['excess_units'];
                $row['suggested_units'] = intdiv($weighted, $excess);
                $assigned += $row['suggested_units'];
                $remainders[] = ['idx' => $index, 'fraction' => $weighted % $excess,
                    'project_id' => $row['project_id']];
            }
            unset($row);
            usort($remainders, static fn (array $a, array $b): int =>
                ($b['fraction'] <=> $a['fraction']) ?: ($a['project_id'] <=> $b['project_id']));
            for ($i = 0; $i < $suggestible - $assigned; $i++) {
                $rows[$remainders[$i]['idx']]['suggested_units']++;
            }
        }

        $unassigned = $overtime - $suggestible;
        return [
            'employee_id' => $employeeId, 'month' => $month,
            'norm_hours' => (float) ($norm / 100), 'total_hours' => (float) ($total / 100),
            'overtime_hours' => (float) ($overtime / 100),
            'suggested_hours' => (float) ($suggestible / 100),
            'unassigned_hours' => (float) ($unassigned / 100),
            'full_month_complete' => $fullMonthComplete,
            'status' => !$fullMonthComplete ? 'provisional'
                : ($unassigned > 0 ? 'manual_allocation_required' : 'ready_for_am_review'),
            'projects' => array_map(static fn (array $r): array => [
                'project_id' => $r['project_id'], 'hours' => (float) ($r['hours_units'] / 100),
                'plan_hours' => $r['planned_units'] === null ? null : (float) ($r['planned_units'] / 100),
                'excess_over_plan' => (float) ($r['excess_units'] / 100),
                'suggested_overtime_hours' => (float) ($r['suggested_units'] / 100),
            ], $rows),
        ];
    }

    /**
     * Validate an AM-edited allocation. The trusted caller still needs
     * serializable/locked persistence, source version and permission checks.
     */
    public static function validateFinal(
        array $proposal,
        array $selectedByProject,
        bool $currentAllProjectsVerified
    ): void {
        if (!$currentAllProjectsVerified || ($proposal['full_month_complete'] ?? false) !== true) {
            throw new DomainException('All employee-month Timesheets must be verified before finalization');
        }
        $available = [];
        foreach ($proposal['projects'] ?? [] as $row) {
            $available[(int) $row['project_id']] = self::hundredths($row['hours'], 'available hours');
        }
        $assigned = 0;
        foreach ($selectedByProject as $projectId => $selected) {
            if (!ctype_digit((string) $projectId) || !array_key_exists((int) $projectId, $available)) {
                throw new DomainException('Allocation references an unknown project');
            }
            $units = self::hundredths($selected, 'allocated hours');
            if ($units > $available[(int) $projectId]) {
                throw new DomainException('Project overtime cannot exceed approved project hours');
            }
            $assigned += $units;
        }
        if ($assigned !== self::hundredths($proposal['overtime_hours'] ?? '', 'total overtime')) {
            throw new DomainException('All employee-month overtime must be allocated exactly once');
        }
    }
}
