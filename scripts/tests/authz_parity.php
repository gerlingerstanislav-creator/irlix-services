<?php
require __DIR__.'/../authz/compare_effective_grants.php';

function syntheticGrant(string $actor, string $permission, string $scope): array
{
    return [
        'subject'=>$actor,'service'=>'finance','permission'=>$permission,
        'allowed'=>$scope!=='none','scope'=>$scope,
    ];
}

$before = [syntheticGrant('example-a', 'finance.view', 'all'),
    syntheticGrant('example-a', 'finance.salary.view', 'none')];
if (!compareEffectiveGrants($before, array_reverse($before))['parity']) {
    throw new RuntimeException('Order should not affect parity');
}
$changed = [syntheticGrant('example-a', 'finance.view', 'all'),
    syntheticGrant('example-a', 'finance.salary.view', 'all')];
if (compareEffectiveGrants($before, $changed)['parity']) {
    throw new RuntimeException('Salary privilege escalation was not blocked');
}
if (compareEffectiveGrants($before, [$before[0]])['parity']) {
    throw new RuntimeException('Incomplete permission coverage was not blocked');
}
if (compareEffectiveGrants($before, [...$before,
    syntheticGrant('example-b', 'finance.view', 'all')])['parity']) {
    throw new RuntimeException('Unexpected employee was not blocked');
}
try {
    compareEffectiveGrants($before, [$before[0],$before[0]]);
    throw new RuntimeException('Duplicate decision was accepted');
} catch (InvalidArgumentException) {
    // expected
}
$resourceBefore = [
    ['subject'=>'example-a','service'=>'vacations','permission'=>'vacations.documents.view',
        'resource'=>'opaque-hmac-doc-1','allowed'=>true,'scope'=>'own'],
    ['subject'=>'example-a','service'=>'vacations','permission'=>'vacations.documents.view',
        'resource'=>'opaque-hmac-doc-2','allowed'=>false,'scope'=>'none'],
];
if (!compareEffectiveGrants($resourceBefore, array_reverse($resourceBefore))['parity']) {
    throw new RuntimeException('Per-object ordering affected parity');
}
$resourceChanged = $resourceBefore;
$resourceChanged[1]['allowed'] = true;
$resourceChanged[1]['scope'] = 'own';
if (compareEffectiveGrants($resourceBefore,$resourceChanged)['parity']) {
    throw new RuntimeException('Foreign confidential document became accessible');
}
$resourceOmitted = [$resourceBefore[0]];
if (compareEffectiveGrants($resourceBefore,$resourceOmitted)['parity']) {
    throw new RuntimeException('Object-level permission coverage was incomplete');
}
$aggregate = [$resourceBefore[0]];
unset($aggregate[0]['resource']);
if (compareEffectiveGrants([$resourceBefore[0]],$aggregate)['parity']) {
    throw new RuntimeException('Aggregate scope was confused with object-level access');
}
try {
    $invalid = $resourceBefore;
    $invalid[0]['resource']='__aggregate__';
    compareEffectiveGrants($resourceBefore, $invalid);
    throw new RuntimeException('Reserved aggregate resource key was accepted');
} catch (InvalidArgumentException) {
    // expected
}
echo "Authorization effective-snapshot parity: PASS\n";
