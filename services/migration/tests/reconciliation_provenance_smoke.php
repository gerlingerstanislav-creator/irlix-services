<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Migration/Core/TargetFieldComparison.php';
require_once __DIR__.'/../app/Migration/Core/ReconciliationProvenance.php';

use App\Migration\Core\ReconciliationProvenance;

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$prior = ['valid_to'=>'2026-01-31', 'hourly_rate'=>'1500.00', 'technology'=>'PHP'];
$target = (object) ['valid_to'=>'2026-01-31 00:00:00', 'hourly_rate'=>'1500.0', 'technology'=>'PHP'];
$same = ReconciliationProvenance::compare($prior, $target,
    ['valid_to'=>'2026-01-31','hourly_rate'=>1500.00,'technology'=>'PHP']);
verify($same['classification']==='unchanged' && !$same['changed_fields'],
    'Equivalent SQL dates and decimals must not create spurious deltas');

$incoming = ReconciliationProvenance::compare($prior, $target,
    ['valid_to'=>'2026-02-28','hourly_rate'=>'1700.00','technology'=>'PHP']);
verify($incoming['classification']==='source_delta'
    && $incoming['field_reasons']['valid_to']==='source_changed'
    && $incoming['field_reasons']['hourly_rate']==='source_changed',
    'Changed normalized source relative to the last applied baseline is evidence, not a write permit');

$targetEdited = (object) ['valid_to'=>'2026-02-28', 'hourly_rate'=>'1500.00', 'technology'=>'PHP'];
$preserve = ReconciliationProvenance::compare($prior, $targetEdited, $prior);
verify($preserve['classification']==='target_diverged'
    && $preserve['field_reasons']['valid_to']==='target_changed',
    'A target-side agreed correction must not be mistaken for a source delta');

$both = ReconciliationProvenance::compare($prior,
    (object) ['valid_to'=>'2026-02-15', 'hourly_rate'=>'1500', 'technology'=>'PHP'],
    ['valid_to'=>'2026-02-28','hourly_rate'=>'1500.00','technology'=>'PHP']);
verify($both['classification']==='needs_evidence'
    && $both['field_reasons']['valid_to']==='both_changed',
    'Concurrent source and target history requires evidence');

$missing = ReconciliationProvenance::compare(null, $target,
    ['valid_to'=>'2026-02-28']);
verify($missing['classification']==='needs_evidence'
    && $missing['field_reasons']['valid_to']==='baseline_missing',
    'Absent mapping baseline is not evidence of a safe write');

$deferred = ReconciliationProvenance::compare($prior, $target,
    ['valid_to'=>'2026-02-28']);
verify($deferred['classification']==='source_delta'
    && !array_key_exists('hourly_rate', $deferred['field_reasons']),
    'Deferred money field must never be interpreted as NULL or overwritten');

$missingTarget = ReconciliationProvenance::compare($prior,
    (object) ['valid_to'=>'2026-01-31'], ['hourly_rate'=>'1600']);
verify($missingTarget['classification']==='needs_evidence'
    && $missingTarget['field_reasons']['hourly_rate']==='target_field_missing',
    'Missing target field must be treated as unknown, not false equality');

$zero = ReconciliationProvenance::compare(['hours_per_day'=>null],
    (object) ['hours_per_day'=>null], ['hours_per_day'=>'0']);
verify($zero['classification']==='source_delta',
    'Unknown historical amount NULL is never equal to numeric zero');

echo "Reconciliation provenance smoke: PASS\n";
