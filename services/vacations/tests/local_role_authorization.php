<?php

require __DIR__.'/../app/Services/VacationsRoleAuthorization.php';
use App\Services\VacationsRoleAuthorization as Authz;

$test = static function (bool $condition, string $why): void {
    if (!$condition) throw new RuntimeException($why);
};
$rows = [
    ['role'=>'org:irlix','permission'=>'vacations.mine.view','allowed'=>true,'scope'=>'own'],
    ['role'=>'special:personnel-officer','permission'=>'vacations.manage.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'head:accounting','permission'=>'vacations.department.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'org:hr','permission'=>'vacations.documents.view','allowed'=>false,'scope'=>'none'],
    ['role'=>'special:personnel-officer','permission'=>'vacations.documents.view','allowed'=>true,'scope'=>'all'],
];
$roles=['org:irlix','org:hr','head:accounting','special:personnel-officer'];
$effective=Authz::evaluate($roles,$rows);
$test($effective['vacations.mine.view']['scope']==='own','Base scope must be own');
$test($effective['vacations.department.view']['scope']==='team','Head scope must be team');
$test($effective['vacations.manage.view']['scope']==='all','Personnel may have management permission');
$test(!$effective['vacations.documents.view']['allowed'],'HR explicit document deny must win');
$test(!$effective['vacations.approvals.manage']['allowed'],'Read must not imply approval');
$test(!Authz::evaluate(['service:vacations','personnel-officer'],$rows)['vacations.manage.view']['allowed'],
    'Legacy service admission/role must not grant V2 permissions');
$test(Authz::evaluate(['platform-admin'],[])['vacations.documents.view']['scope']==='all',
    'Platform admin policy must retain emergency access');
echo "Vacations local roles: PASS\n";
