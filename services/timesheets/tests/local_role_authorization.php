<?php

require __DIR__.'/../app/Services/TimesheetsRoleAuthorization.php';
use App\Services\TimesheetsRoleAuthorization as Authz;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$rows = [
    ['role'=>'org:irlix','permission'=>'timesheets.mine.view','allowed'=>true,'scope'=>'own'],
    ['role'=>'org:accounting','permission'=>'timesheets.mine.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'head:accounting','permission'=>'timesheets.management.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'special:personnel-officer','permission'=>'timesheets.management.view','allowed'=>false,'scope'=>'none'],
];
$result = Authz::evaluate(['org:irlix','org:accounting','head:accounting','special:personnel-officer'], $rows);
$check($result['timesheets.mine.view'] === ['allowed'=>true,'scope'=>'all'], 'Same-permission union failed');
$check($result['timesheets.management.view'] === ['allowed'=>false,'scope'=>'none'], 'Explicit denial must win');
$check(!$result['timesheets.analytics.view']['allowed'], 'Unknown permission must be denied');
$check(!$result['timesheets.mine.manage']['allowed'], 'Viewing cannot imply editing');
$check(!Authz::evaluate(['org:irlix'], [])['timesheets.mine.view']['allowed'], 'Org role has no default rights');
$check(!Authz::evaluate(['service:timesheets','scope:all'], $rows)['timesheets.mine.view']['allowed'],
    'Legacy service grants must not be imported');
$check(Authz::evaluate(['platform-admin'], [])['timesheets.audit.view']['scope'] === 'all',
    'Platform admin override is required');
$jwt = ['employee-id:54','org:irlix','org:account-management','head:account-management','special:personnel-officer'];
$live = ['employee_id'=>54,'roles'=>['personnel-officer'],
    'role_claims'=>['org:irlix','org:account-management','head:account-management','special:personnel-officer']];
$check(Authz::currentIdentityMatches($jwt,$live),'Valid current identity was denied');
$live['role_claims']=['org:irlix','org:account-management','special:personnel-officer'];
$check(!Authz::currentIdentityMatches($jwt,$live),'Stale manager JWT role remained effective');
$live['role_claims']=['org:irlix','org:account-management','head:account-management','special:personnel-officer'];
$live['employee_id']=55;
$check(!Authz::currentIdentityMatches($jwt,$live),'Mismatched actor was trusted');
$live['employee_id']=54;
$check(!Authz::currentIdentityMatches([...$jwt,'platform-admin'],$live),
    'Revoked platform admin role was trusted');
echo "Timesheets V2 local authorization: PASS\n";
