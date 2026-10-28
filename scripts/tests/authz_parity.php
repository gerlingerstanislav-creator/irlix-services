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
echo "Authorization effective-snapshot parity: PASS\n";
