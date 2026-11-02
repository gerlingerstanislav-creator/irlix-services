<?php

namespace App\Migration\Core;

use PDO;

/**
 * Immutable operator decisions stored outside Migration metadata snapshots.
 *
 * The /identity volume already survives business/metadata rollback and is
 * shared only by the Migration API/worker. This registry is intentionally NOT
 * part of /data/migration.sqlite, which can be restored to an older checkpoint.
 * No code path may treat a row here as automatic permission to change Clients,
 * Timesheets, approved reporting periods, or Finance.
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

    private static function database(): PDO
    {
        $path = getenv('MIGRATION_RECONCILIATION_DATABASE') ?: '/identity/reconciliation-decisions.sqlite';
        if (!str_starts_with($path, '/') || $path === '/') {
            throw new \DomainException('Reconciliation decision database must be an absolute private file');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Reconciliation decision directory cannot be created');
        }
        // An unexpected symlink could redirect confidential decision evidence
        // outside the dedicated identity volume. Reject it before opening SQLite.
        if (is_link($path)) {
            throw new \DomainException('Reconciliation decision store must not be a symlink');
        }
        $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (!chmod($path, 0600)) {
            throw new \RuntimeException('Reconciliation decision store permissions cannot be secured');
        }
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS reconciliation_decisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service TEXT NOT NULL, entity_type TEXT NOT NULL,
            legacy_id TEXT NOT NULL, rule_code TEXT NOT NULL,
            rule_version INTEGER NOT NULL CHECK(rule_version > 0),
            decision TEXT NOT NULL, source_sha256 TEXT NOT NULL,
            evidence_sha256 TEXT NOT NULL, approved_by TEXT NOT NULL,
            supersedes_id INTEGER, created_at TEXT NOT NULL,
            UNIQUE(service, entity_type, legacy_id, rule_code, rule_version)
        )');
        $db->exec("CREATE TRIGGER IF NOT EXISTS reconciliation_decisions_no_update
            BEFORE UPDATE ON reconciliation_decisions
            BEGIN SELECT RAISE(ABORT, 'Reconciliation decisions are append-only'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS reconciliation_decisions_no_delete
            BEFORE DELETE ON reconciliation_decisions
            BEGIN SELECT RAISE(ABORT, 'Reconciliation decisions are append-only'); END");
        return $db;
    }

    /**
     * An approval is only recorded after external authorization/evidence checks.
     * This API does NOT expose an HTTP approval path and cannot permit a write.
     *
     * @param array{service:string,entity_type:string,legacy_id:string,rule_code:string,
     *   rule_version:int,decision:string,source_sha256:string,evidence_sha256:string,
     *   approved_by:string,supersedes_id?:int|null} $decision
     */
    public function append(array $decision): int
    {
        foreach (['service', 'entity_type', 'legacy_id', 'rule_code', 'decision',
                  'source_sha256', 'evidence_sha256', 'approved_by'] as $field) {
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
        foreach (['source_sha256', 'evidence_sha256'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $decision[$field])) {
                throw new \InvalidArgumentException('Invalid reconciliation fingerprint');
            }
        }
        $parent = $decision['supersedes_id'] ?? null;
        if ($parent !== null && (!is_int($parent) || $parent < 1)) {
            throw new \InvalidArgumentException('Invalid reconciliation parent revision');
        }

        $db = self::database();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $lookup = $db->prepare('SELECT id, rule_version FROM reconciliation_decisions
                WHERE service = ? AND entity_type = ? AND legacy_id = ? AND rule_code = ?
                ORDER BY id DESC LIMIT 1');
            $scope = [$decision['service'], $decision['entity_type'],
                $decision['legacy_id'], $decision['rule_code']];
            $lookup->execute($scope);
            $last = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($last === false && $parent !== null) {
                throw new \DomainException('Superseded decision does not exist');
            }
            // A later approval must name the exact previous decision and
            // advance by exactly one. Gaps hide missing evidence revisions.
            if ($last !== false && ((int) $last['id'] !== $parent
                || $decision['rule_version'] !== (int) $last['rule_version'] + 1)) {
                throw new \DomainException('Stale or unversioned reconciliation decision');
            }
            $stmt = $db->prepare('INSERT INTO reconciliation_decisions
                (service, entity_type, legacy_id, rule_code, rule_version, decision,
                 source_sha256, evidence_sha256, approved_by, supersedes_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([...$scope, $decision['rule_version'], $decision['decision'],
                $decision['source_sha256'], $decision['evidence_sha256'],
                $decision['approved_by'], $parent, gmdate('c')]);
            $id = (int) $db->lastInsertId();
            $db->exec('COMMIT');
            return $id;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function latest(string $service, string $entityType, string $legacyId, string $ruleCode): ?array
    {
        $db = self::database();
        $stmt = $db->prepare('SELECT * FROM reconciliation_decisions
            WHERE service = ? AND entity_type = ? AND legacy_id = ? AND rule_code = ?
            ORDER BY id DESC LIMIT 1');
        $stmt->execute([$service, $entityType, $legacyId, $ruleCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
