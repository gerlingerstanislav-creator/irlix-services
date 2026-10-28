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
$routes=[
    ['GET','api/workspace','vacations.mine.view'],
    ['POST','api/absences','vacations.mine.manage'],
    ['POST','api/absences/for-employee','vacations.department.manage'],
    ['GET','api/department-roster','vacations.department.view'],
    ['GET','api/registry','vacations.manage.view'],
    ['GET','api/approvals','vacations.approvals.view'],
    ['POST','api/approvals/18/approve','vacations.approvals.manage'],
    ['GET','api/absences/5/attachments/7/download','vacations.documents.view'],
    ['DELETE','api/absences/5/attachments/7','vacations.documents.manage'],
    ['GET','api/history','vacations.history.view'],
    ['GET','api/client-service-calendar','vacations.client-calendar.view'],
    ['DELETE','api/absences/5','vacations.manage.manage'],
];
foreach ($routes as [$method,$path,$permission]) {
    $test(Authz::requiredPermission($method,$path)===$permission,
        "Vacations route mapped to wrong permission: {$method} {$path}");
}
$test(Authz::requiredPermission('POST','api/approvals/5/download')===null,
    'Unknown approval route inherited a write permission');
$test(Authz::requiredPermission('POST','api/absences/5/attachments/7/download')===null,
    'Document download with wrong HTTP method inherited access');
$test(Authz::requiredPermission('GET','api/permissions')===null,
    'Matrix administration must remain a separate protected endpoint');
echo "Vacations local roles: PASS\n";
