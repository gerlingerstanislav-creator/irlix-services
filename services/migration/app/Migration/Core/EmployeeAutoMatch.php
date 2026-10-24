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
        if (!$hasFactDates || !$allClientMemberships) return null;
        $actual = [];
        foreach ($allClientMemberships as $membership) {
            $id=(int)($membership['target_id']??0);
            if ($id < 1 || (int)($membership['employee_id']??0)<1) return null;
            $actual[$id]=true;
        }
        // An employee can work for several clients in separate periods.
        // All dates from every legacy rate must fit exactly one membership,
        // not just one of its periods. Any competing match prevents attribution.
        $compatible = [];
        foreach ($factDatedCandidates as $candidate) {
            $id=(int)($candidate['target_id']??0);
            if (isset($actual[$id]) && ($candidate['period_matches']??false) === true)
                $compatible[$id]=true;
        }
        return count($compatible)===1 ? (int)array_key_first($compatible) : null;
    }
}
