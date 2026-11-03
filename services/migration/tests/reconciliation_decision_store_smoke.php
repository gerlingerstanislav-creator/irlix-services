<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Migration/Core/ReconciliationDecisionStore.php';

use App\Migration\Core\ReconciliationDecisionStore;

function checkReconciliation(bool $value, string $description): void
{
    if (!$value) throw new RuntimeException($description);
}

$dir = sys_get_temp_dir().'/irlix-reconciliation-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);
$ledgerPath = $dir.'/decisions.sqlite';
$metadataPath = $dir.'/migration.sqlite';
putenv('MIGRATION_RECONCILIATION_DATABASE='.$ledgerPath);

try {
    // Simulate metadata checkpoints being replaced by a restore, without
    // ever touching the separate, private decisions volume.
    file_put_contents($metadataPath, 'metadata-before-checkpoint');
    $store = new ReconciliationDecisionStore();
    $evidence = [
        'service' => 'clients',
        'entity_type' => 'rates',
        'legacy_id' => 'synthetic-rate-7',
        'rule_code' => 'PRESERVE_HISTORY',
        'rule_version' => 1,
        'decision' => 'preserve_corrected_target',
        'source_sha256' => hash('sha256', 'synthetic-source'),
        'evidence_sha256' => hash('sha256', 'synthetic-proof'),
        'approved_by' => 'synthetic-operator',
    ];
    // Initial evidence must start at version one, even without a parent.
    try {
        $store->append(array_replace($evidence, [
            'rule_version'=>3,
        ]));
        throw new RuntimeException('Initial decision accepted an invalid revision');
    } catch (DomainException $expected) {}
    $id = $store->append($evidence);
    checkReconciliation($id > 0, 'First decision must be stored');
    file_put_contents($metadataPath, 'metadata-restored-to-earlier-checkpoint');
    checkReconciliation(
        ($store->latest('clients','rates','synthetic-rate-7','PRESERVE_HISTORY')['id'] ?? null) == $id,
        'Metadata rollback must not remove independent reconciliation decision'
    );
    checkReconciliation(
        fileperms($ledgerPath) % 01000 === 0600,
        'Private decision file must not be group/world accessible'
    );

    try {
        $store->append($evidence);
        throw new RuntimeException('Duplicate approval accepted');
    } catch (DomainException $expected) {}

    try {
        $store->append(array_replace($evidence, [
            'rule_version' => 2,
            'decision' => 'canonicalize_source',
            'supersedes_id' => $id + 1,
        ]));
        throw new RuntimeException('Invalid approval parent accepted');
    } catch (DomainException $expected) {}

    // A revision gap must never masquerade as an unbroken audit trail.
    try {
        $store->append(array_replace($evidence, [
            'rule_version' => 4,
            'decision' => 'no_automatic_action',
            'supersedes_id' => $id,
        ]));
        throw new RuntimeException('Missing ledger revisions accepted');
    } catch (DomainException $expected) {}

    $revision = $store->append(array_replace($evidence, [
        'rule_version' => 2,
        'decision' => 'no_automatic_action',
        'supersedes_id' => $id,
        'evidence_sha256' => hash('sha256', 'synthetic-revised-proof'),
    ]));
    checkReconciliation($revision > $id, 'Revision must append');

    $db = new PDO('sqlite:'.$ledgerPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        "UPDATE reconciliation_decisions SET decision = 'canonicalize_source' WHERE id = ".$id,
        'DELETE FROM reconciliation_decisions WHERE id = '.$id,
    ] as $sql) {
        try {
            $db->exec($sql);
            throw new RuntimeException('Decision could be rewritten or deleted');
        } catch (PDOException $expected) {}
    }
    $db = null;

    $latest = (new ReconciliationDecisionStore())->latest('clients','rates','synthetic-rate-7','PRESERVE_HISTORY');
    checkReconciliation($latest !== null && $latest['decision'] === 'no_automatic_action',
        'Restart must retain newest decision and reject old revision');
    checkReconciliation((new ReconciliationDecisionStore())->latest(
        'clients','rates','different-source','PRESERVE_HISTORY') === null,
        'Unknown entity must not inherit an operator decision');
    // A private ledger filename must not be redirected by a symlink.
    $linkPath = $dir.'/linked.sqlite';
    if (!symlink($ledgerPath, $linkPath)) throw new RuntimeException('Fixture symlink failed');
    putenv('MIGRATION_RECONCILIATION_DATABASE='.$linkPath);
    try {
        (new ReconciliationDecisionStore())->latest('clients','rates','synthetic-rate-7','PRESERVE_HISTORY');
        throw new RuntimeException('Symlinked reconciliation ledger accepted');
    } catch (DomainException $expected) {}
    putenv('MIGRATION_RECONCILIATION_DATABASE='.$ledgerPath);
    unlink($linkPath);
    echo "Durable reconciliation decisions: PASS\n";
} finally {
    foreach ([$ledgerPath, $ledgerPath.'-journal', $metadataPath] as $path) @unlink($path);
    @rmdir($dir);
}
