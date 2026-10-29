<?php

require __DIR__.'/../app/Services/FinanceRoleAuthorization.php';

use App\Services\FinanceRoleAuthorization as Authz;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$rows = [
    ['role'=>'org:irlix','permission'=>'finance.view','allowed'=>false,'scope'=>'none'],
    ['role'=>'org:accounting','permission'=>'finance.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'head:accounting','permission'=>'finance.planning.approve','allowed'=>true,'scope'=>'all'],
    ['role'=>'special:personnel-officer','permission'=>'finance.salary.view','allowed'=>true,'scope'=>'team'],
];
$actual = Authz::evaluate(['org:irlix','org:accounting','head:accounting','special:personnel-officer'], $rows);
$check(!$actual['finance.view'], 'Explicit deny must win for finance.view');
$check($actual['finance.planning.approve'], 'Direct head permission must be applied');
$check(!$actual['finance.salary.view'], 'An unsupported team grant must fail closed');
$check(!Authz::evaluate(['org:irlix'], [])[ 'finance.view'], 'A company role gives no implicit finance access');
$check(!Authz::evaluate(['service:finance','permission:finance-view'], $rows)['finance.view'],
    'Legacy service/permission markers must not be imported');
$check(Authz::evaluate(['platform-admin'], [])['finance.salary.view'], 'Platform admin is a superuser');
$check(Authz::evaluate(['special:platform-admin'], [])['finance.salary.view'], 'Verified special admin is a superuser');
$check(!Authz::evaluate(['org:accounting'], [
    ['role'=>'org:accounting','permission'=>'finance.salary.view','allowed'=>true,'scope'=>'own']
])['finance.salary.view'], 'Unsupported own scope must fail closed');
$v2Signed=['employee-id:44','org:irlix','org:accounting','head:accounting','special:personnel-officer'];
$live=['employee_id'=>44,'roles'=>['personnel-officer'],
    'role_claims'=>['org:irlix','org:accounting','head:accounting','special:personnel-officer']];
$check(Authz::currentIdentityMatches($v2Signed,$live),'Current signed identity roles denied');
$live['role_claims']=['org:irlix','special:personnel-officer'];
$check(!Authz::currentIdentityMatches($v2Signed,$live),
    'Revoked organizational head did not revoke Finance access');
$live['role_claims']=['org:irlix','org:accounting','head:accounting','special:personnel-officer'];
$live['employee_id']=45;
$check(!Authz::currentIdentityMatches($v2Signed,$live),'Other employee role data accepted');
$live['employee_id']=44;
$check(!Authz::currentIdentityMatches([...$v2Signed,'platform-admin'],$live),
    'Signed but revoked platform administrator retained Finance access');
$live['roles']=['personnel-officer','platform-admin'];
$check(Authz::currentIdentityMatches([...$v2Signed,'platform-admin'],$live),
    'Current administrator was incorrectly rejected');

// Legacy denials are transferred into Finance-owned V2 role rows once.
// The V2 permission engine makes the full decision from its own matrix.
$importedDenials = [
    ['role'=>'org:accounting','permission'=>'finance.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'org:accounting','permission'=>'finance.salary.view','allowed'=>false,'scope'=>'none'],
    ['role'=>'head:*','permission'=>'finance.cash.confirm','allowed'=>false,'scope'=>'none'],
    ['role'=>'org:accounting','permission'=>'finance.cash.confirm','allowed'=>true,'scope'=>'all'],
];
$granted = Authz::evaluate(['org:accounting'], $importedDenials);
$check($granted['finance.view'] && !$granted['finance.salary.view'],
    'Imported explicit Finance denial not applied independently');
$manager = Authz::evaluate(['org:accounting','head:backend'], $importedDenials);
$check(!$manager['finance.cash.confirm'],
    'Imported generic head:* deny failed to override Finance allow');
$nonHead = Authz::evaluate(['org:accounting'], $importedDenials);
$check($nonHead['finance.cash.confirm'], 'Generic head deny leaked to non-head');
$check(!method_exists(Authz::class, 'intersectLegacy'),
    'Finance V2 still depends on per-request Employees legacy permissions');

echo "Finance V2 local authorization: PASS\n";
