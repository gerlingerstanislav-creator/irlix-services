<?php

namespace App\Migration\Core;

/**
 * Deterministic fallback for an employee whose identity is established
 * through a corporate UUID but whose legacy project label has drifted.
 * An empty or ambiguous client catalog must never be guessed into.
 */
final class EmployeeAutoMatch
{
    public static function choose(array $allClientMemberships, array $factDatedCandidates, bool $hasFactDates): ?int
    {
        if (!$hasFactDates || count($allClientMemberships) !== 1) return null;
        $only = $allClientMemberships[0];
        $id = (int)($only['target_id'] ?? 0);
        if ($id < 1 || (int)($only['employee_id'] ?? 0) < 1) return null;
        $compatible = array_values(array_filter($factDatedCandidates,
            static fn(array $candidate) => (int)($candidate['target_id'] ?? 0)===$id
                && ($candidate['period_matches'] ?? false) === true));
        return count($compatible)===1 ? $id : null;
    }
}
