<?php

namespace App\Domain\Absence;

use App\Models\AbsenceApproval;

/**
 * Only the lifecycle is stored in absences.status. While in review, the
 * externally visible status is a projection of the active approval task.
 *
 * Older stage values remain readable until their approval chain can be
 * reconciled; we never invent an approver or silently advance a stage.
 */
final class AbsenceStatusProjection
{
    public const REVIEW_STAGES = [
        'employee_review', 'hr_review', 'account_manager_review',
        'manager_review', 'hr_final_review',
    ];

    public static function one(array $absence): array
    {
        return self::many([$absence])[0];
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function many(array $rows, string $idField = 'id'): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row[$idField] ?? 0), $rows
        ))));
        $active = [];
        if ($ids) {
            foreach (AbsenceApproval::query()->whereIn('absence_id', $ids)
                ->where('status', 'pending')->orderBy('sequence')->orderBy('id')
                ->get(['absence_id', 'stage']) as $step) {
                $id = (int) $step->absence_id;
                $active[$id] ??= (string) $step->stage;
            }
        }

        foreach ($rows as &$row) {
            $stored = (string) ($row['status'] ?? '');
            $legacyStage = in_array($stored, self::REVIEW_STAGES, true);
            $row['lifecycle_status'] = $legacyStage ? 'in_review' : $stored;
            if ($stored === 'in_review') {
                // A missing active step is inconsistent historical data. Expose
                // an honest generic state, not an invented approval stage.
                $row['status'] = $active[(int) ($row[$idField] ?? 0)] ?? 'in_review';
            }
        }
        unset($row);

        return $rows;
    }
}
