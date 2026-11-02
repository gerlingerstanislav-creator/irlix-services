<?php

namespace App\Migration\Core;

/**
 * Read-only three-way evidence about a mapped record.
 *
 * Baseline = last committed migration payload, current = today's target,
 * candidate = today's normalized legacy payload. This class only describes
 * provenance and NEVER authorizes a write: a source change can still affect
 * signed reports, and a target change may be an approved human correction.
 */
final class ReconciliationProvenance
{
    /**
     * @param array<string,mixed>|null $baseline Previously committed target_payload
     * @param object|array<string,mixed> $current Current mapped target row
     * @param array<string,mixed> $candidate Today's normalized source payload
     * @return array{classification:string,changed_fields:list<string>,field_reasons:array<string,string>}
     */
    public static function compare(?array $baseline, object|array $current, array $candidate): array
    {
        $observed = is_object($current) ? get_object_vars($current) : $current;
        $reasons = [];
        $changed = [];
        foreach ($candidate as $field => $next) {
            // Absence is NOT null: migrations can deliberately omit a field,
            // e.g. a deferred financial amount. Unknown is fail-closed.
            if (!array_key_exists($field, $observed)) {
                $reasons[$field] = 'target_field_missing';
                $changed[] = $field;
                continue;
            }
            $actual = $observed[$field];
            if (TargetFieldComparison::matches($actual, $next, $field)) {
                $reasons[$field] = 'equal';
                continue;
            }
            $changed[] = $field;
            if ($baseline === null || !array_key_exists($field, $baseline)) {
                $reasons[$field] = 'baseline_missing';
                continue;
            }
            $before = $baseline[$field];
            $targetSame = TargetFieldComparison::matches($before, $actual, $field);
            $sourceSame = TargetFieldComparison::matches($before, $next, $field);
            $reasons[$field] = $targetSame ? 'source_changed'
                : ($sourceSame ? 'target_changed' : 'both_changed');
        }

        // Multiple fields can have different histories. One ambiguous field
        // requires manual proof, even if others are harmless source deltas.
        $blocked = ['target_field_missing', 'baseline_missing', 'both_changed'];
        $values = array_values(array_unique(array_values($reasons)));
        if (!$changed) $classification = 'unchanged';
        elseif (count(array_intersect($values, $blocked)) > 0) $classification = 'needs_evidence';
        elseif (in_array('target_changed', $values, true)) $classification = 'target_diverged';
        else $classification = 'source_delta';

        return [
            'classification' => $classification,
            'changed_fields' => $changed,
            'field_reasons' => $reasons,
        ];
    }
}
