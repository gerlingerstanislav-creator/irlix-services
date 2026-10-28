<?php

require __DIR__.'/../app/Services/RecruitmentRoleAuthorization.php';
use App\Services\RecruitmentRoleAuthorization as Authz;
$check=static function(bool $ok,string $error):void{if(!$ok)throw new RuntimeException($error);};
$rows=[
    ['role'=>'special:personnel-officer','permission'=>'recruitment.workspace.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'special:personnel-officer','permission'=>'recruitment.candidates.manage','allowed'=>true,'scope'=>'all'],
    ['role'=>'org:irlix','permission'=>'recruitment.candidates.manage','allowed'=>false,'scope'=>'none'],
];
$result=Authz::evaluate(['org:irlix','special:personnel-officer'],$rows);
$check($result['recruitment.workspace.view'],'Recruiter view missing');
$check(!$result['recruitment.candidates.manage'],'Explicit denial did not override recruiter grant');
$check(!$result['recruitment.offers.manage'],'Unassigned offer permission became granted');
$check(!$result['recruitment.compensation.view'],'General workspace access exposed compensation');
$check(!Authz::evaluate(['service:recruitment'],$rows)['recruitment.workspace.view'],
    'Legacy service marker bypassed Recruitment policy');
$check(!Authz::evaluate(['special:personnel-officer'],[
    ['role'=>'special:personnel-officer','permission'=>'recruitment.workspace.view','allowed'=>true,'scope'=>'team']
])['recruitment.workspace.view'],'Team scope without row filtering was trusted');
$check(Authz::evaluate(['platform-admin'],[])['recruitment.offers.manage'],'Admin override missing');
$check(Authz::requiredPermission('POST','api/offers')==='recruitment.offers.manage','Offer guard missing');
$check(Authz::requiredPermission('GET','api/not-a-page')===null,'Unknown API path was granted');
$check(!Authz::currentIdentityMatches(['employee-id:42','head:backend'],[
    'employee_id'=>42,'roles'=>[],'role_claims'=>[]]),'Stale manager role accepted');
echo "Recruitment staged independent role authorization: PASS\n";
