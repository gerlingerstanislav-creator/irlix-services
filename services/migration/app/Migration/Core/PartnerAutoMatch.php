<?php

namespace App\Migration\Core;

/**
 * Strict and deterministic cross-service partner matching. The operator
 * authorized automatic reconciliation; authorization cannot resolve ambiguity.
 * Original rate from/to does not constrain timesheets.date in the source FK:
 * matching a person/client must not depend on term-date coverage.
 */
final class PartnerAutoMatch
{
    public static function choose(array $candidates, bool $hasFactDates): ?int
    {
        if (!$hasFactDates) return null;
        $valid = array_values(array_filter($candidates, static fn (array $candidate): bool =>
            (($candidate['name_exact'] ?? false) === true
                || ($candidate['name_tokens_exact'] ?? false) === true)
            && ($candidate['client_label_exact'] ?? false) === true
            && ($candidate['partner_specialist_id'] ?? null) !== null
            && (int) ($candidate['target_id'] ?? 0) > 0));
        return count($valid) === 1 ? (int) $valid[0]['target_id'] : null;
    }
}
