<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Resolve effective final approval for month analytics in linear work per
 * timesheet fact (plus the relevant membership terms/reporting periods).
 *
 * Inputs are already permission-scoped by the caller; this service never
 * widens that scope or invents employee IDs for partner specialists.
 */
final class CommercialAnalyticsClassifier
{
    /**
     * @param Collection<int, object> $facts
     * @param Collection<int, object> $approvals
     * @param Collection<int, object> $exceptions
     * @param array<int, array<string, mixed>> $lockedPeriods
     * @param array<int, array<string, mixed>> $assignments
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    public function classify(
        Collection $facts,
        Collection $approvals,
        Collection $exceptions,
        array $lockedPeriods,
        array $assignments,
    ): array {
        $approvedMembers = [];
        foreach ($approvals as $approval) {
            if ($approval->client_member_id !== null) {
                $approvedMembers[(int) $approval->client_member_id] = true;
            }
        }

        $excluded = [];
        foreach ($exceptions as $exception) {
            if ($exception->client_member_id !== null) {
                $excluded[(int) $exception->client_member_id][substr((string) $exception->work_date, 0, 10)] = true;
            }
        }

        $memberTerms = [];
        foreach ($assignments as $term) {
            if (($term['valid_from'] ?? null) === null || ($term['client_member_id'] ?? null) === null) {
                continue;
            }
            $memberTerms[(int) $term['client_member_id']][] = $term;
        }

        $locksByClient = [];
        foreach ($lockedPeriods as $period) {
            $locksByClient[(int) $period['client_id']][] = $period;
        }

        $confirmed = [];
        $unconfirmed = [];
        $conflicted = [];
        foreach ($facts as $fact) {
            if ((bool) $fact->term_conflict) {
                $conflicted[] = $fact;
                continue;
            }

            $memberId = (int) $fact->client_member_id;
            $date = substr((string) $fact->work_date, 0, 10);
            $isApproved = isset($approvedMembers[$memberId]) && !isset($excluded[$memberId][$date]);

            if (!$isApproved) {
                // Sent reporting periods act as effective final approval only
                // for a membership that actually covers this fact's date.
                foreach ($memberTerms[$memberId] ?? [] as $term) {
                    if ($term['valid_from'] > $date || (!empty($term['valid_to']) && $term['valid_to'] < $date)) {
                        continue;
                    }
                    foreach ($locksByClient[(int) $term['client_id']] ?? [] as $period) {
                        if ($period['period_start'] <= $date && $period['period_end'] >= $date) {
                            $isApproved = true;
                            break 2;
                        }
                    }
                }
            }
            if ($isApproved) {
                $confirmed[] = $fact;
            } else {
                $unconfirmed[] = $fact;
            }
        }

        return [collect($confirmed), collect($unconfirmed), collect($conflicted)];
    }
}
