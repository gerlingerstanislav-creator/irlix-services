<?php

require __DIR__.'/../app/Services/TimesheetsRoleAuthorization.php';
use App\Services\TimesheetsRoleAuthorization as Authz;
require __DIR__.'/../app/Services/PartnerAccessService.php';
use App\Services\PartnerAccessService as PartnerAuth;

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
$partnerRules = [
    ['role'=>'org:account-management','permission'=>'timesheets.partner-hours.view','allowed'=>true,'scope'=>'own'],
    ['role'=>'head:account-management','permission'=>'timesheets.partner-hours.manage','allowed'=>true,'scope'=>'team'],
    ['role'=>'head:account-management','permission'=>'timesheets.partner-hours.approve','allowed'=>false,'scope'=>'none'],
];
$partner=Authz::evaluate(['org:account-management','head:account-management'], $partnerRules);
$check($partner['timesheets.partner-hours.view']===['allowed'=>true,'scope'=>'own'],
    'Partner hours read permission scope was not isolated');
$check($partner['timesheets.partner-hours.manage']===['allowed'=>true,'scope'=>'team'],
    'Partner hours write permission was not independent');
$check(!$partner['timesheets.partner-hours.approve']['allowed'],
    'Partner final approval must require a separate explicit grant');

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
$routes=[
    ['GET','api/partner-hours','timesheets.partner-hours.view'],
    ['PUT','api/partner-hours','timesheets.partner-hours.manage'],
    ['POST','api/partner-hours/confirm','timesheets.partner-hours.manage'],
    ['POST','api/partner-hours/final-approval','timesheets.partner-hours.approve'],
];
foreach($routes as [$method,$path,$required]){
    $check(PartnerAuth::permissionFor($method,$path,true)===$required,
        "V2 partner operation mapped to an incorrect permission: {$method} {$path}");
}
$check(PartnerAuth::permissionFor('POST','api/partner-hours',true)===null,
    'A future/unknown partner endpoint must never inherit write permission');
$check(PartnerAuth::permissionFor('GET','api/partner-hours',false)==='timesheets.management.view',
    'Legacy partner-hours compatibility unexpectedly changed');
echo "Timesheets V2 local authorization: PASS\n";
