<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportingPeriodApprovals
{
    public static function locked(array $periods, int $clientId, string $from, string $to): bool
    {
        return collect($periods)->contains(fn (array $period) => $period['client_id'] === $clientId
            && $period['period_start'] <= $to && $period['period_end'] >= $from);
    }

    // A sent period requires final approval even when legacy data has no known approver.
    // This is a read model: do not invent an actor or persist approvals during page reads.
    public static function effective(Collection $stored, iterable $assignments, array $periods, string $from, string $to): Collection
    {
        $result = $stored->keyBy(fn ($approval) => $approval->employee_id.':'.$approval->project_id.':'.$approval->month);
        foreach ($assignments as $assignment) {
            $start = max($from, $assignment['valid_from']);
            $end = min($to, $assignment['valid_to'] ?: $to);
            if ($start > $end) continue;
            foreach ($periods as $period) {
                if ($period['client_id'] !== $assignment['client_id']) continue;
                $lockedFrom = max($start, $period['period_start']);
                $lockedTo = min($end, $period['period_end']);
                if ($lockedFrom > $lockedTo) continue;
                $cursor = Carbon::parse($lockedFrom)->startOfMonth();
                $last = Carbon::parse($lockedTo)->startOfMonth();
                while ($cursor->lte($last)) {
                    $month = $cursor->toDateString();
                    $key = $assignment['employee_id'].':'.$assignment['project_id'].':'.$month;
                    $existing = $result->get($key);
                    $result->put($key, (object) [
                        ...($existing ? (array) $existing : [
                            'employee_id' => $assignment['employee_id'], 'project_id' => $assignment['project_id'],
                            'month' => $month, 'approved_by' => null, 'approved_at' => $period['timesheets_sent_at'] ?? null,
                        ]),
                        'required_by_reporting_period' => true,
                    ]);
                    $cursor->addMonth();
                }
            }
        }
        return $result->values();
    }
}
