<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/**
 * Append-only audit of explicitly approved legacy reconciliation decisions.
 *
 * The ledger NEVER grants an implicit write permit: callers must separately
 * validate source fingerprints, target revisions, Timesheets and Finance
 * dependencies. Exposing an approval API requires authenticated platform
 * admin permissions and is deliberately outside this first implementation.
 */
final class ReconciliationDecisionStore
{
    private const ALLOWED = [
        'canonicalize_source',
        'preserve_corrected_target',
        'historical_unrated_fact',
        'approved_exclusion',
        'explicit_identity',
        'no_automatic_action',
    ];

    /**
     * @param array{service:string,entity_type:string,legacy_id:string,rule_code:string,
     *   rule_version:int,decision:string,source_sha256:string,evidence_sha256:string,
     *   approved_by:string,supersedes_id?:int|null} $decision
     */
    public function append(array $decision): int
    {
        foreach (['service','entity_type','legacy_id','rule_code','decision',
                  'source_sha256','evidence_sha256','approved_by'] as $field) {
            if (!isset($decision[$field]) || !is_string($decision[$field])
                || trim($decision[$field]) === '') {
                throw new \InvalidArgumentException('Missing reconciliation evidence: '.$field);
            }
        }
        if (!in_array($decision['decision'], self::ALLOWED, true)
            || !isset($decision['rule_version']) || !is_int($decision['rule_version'])
            || $decision['rule_version'] < 1
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $decision['service'])
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $decision['entity_type'])
            || !preg_match('/^[A-Z][A-Z0-9_]{0,95}$/D', $decision['rule_code'])
            || strlen($decision['legacy_id']) > 255 || strlen($decision['approved_by']) > 255) {
            throw new \InvalidArgumentException('Invalid reconciliation decision scope/rule');
        }
        foreach (['source_sha256','evidence_sha256'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $decision[$field])) {
                throw new \InvalidArgumentException('Invalid reconciliation fingerprint');
            }
        }

        return DB::transaction(function () use ($decision): int {
            $scope = [
                'service'=>$decision['service'],
                'entity_type'=>$decision['entity_type'],
                'legacy_id'=>$decision['legacy_id'],
                'rule_code'=>$decision['rule_code'],
            ];
            $last = DB::table('migration_reconciliation_decisions')
                ->where($scope)->orderByDesc('id')->first();
            $parent = $decision['supersedes_id'] ?? null;
            if ($last === null && $parent !== null) {
                throw new \DomainException('Superseded decision does not exist');
            }
            if ($last !== null && ((int) $last->id !== $parent
                || $decision['rule_version'] <= (int) $last->rule_version)) {
                throw new \DomainException('Stale or unversioned reconciliation decision');
            }
            return (int) DB::table('migration_reconciliation_decisions')->insertGetId(
                $scope + [
                    'rule_version'=>$decision['rule_version'],
                    'decision'=>$decision['decision'],
                    'source_sha256'=>$decision['source_sha256'],
                    'evidence_sha256'=>$decision['evidence_sha256'],
                    'approved_by'=>$decision['approved_by'],
                    'supersedes_id'=>$parent,
                    'created_at'=>now(),
                ]);
        });
    }

    public function latest(string $service, string $entityType, string $legacyId, string $ruleCode): ?array
    {
        $row = DB::table('migration_reconciliation_decisions')
            ->where(['service'=>$service,'entity_type'=>$entityType,
                'legacy_id'=>$legacyId,'rule_code'=>$ruleCode])
            ->orderByDesc('id')->first();
        return $row ? (array) $row : null;
    }
}
