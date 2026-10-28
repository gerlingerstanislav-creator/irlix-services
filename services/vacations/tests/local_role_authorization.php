<?php

require __DIR__.'/../app/Services/VacationsRoleAuthorization.php';
use App\Services\VacationsRoleAuthorization as Authz;
require __DIR__.'/../app/Services/VacationsObjectScope.php';
use App\Services\VacationsObjectScope as ObjectScope;

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
$org = [
    ['id'=>1,'parent_id'=>null,'manager_id'=>null],
    ['id'=>2,'parent_id'=>1,'manager_id'=>72],
    ['id'=>3,'parent_id'=>2,'manager_id'=>73],
    ['id'=>4,'parent_id'=>1,'manager_id'=>99],
    ['id'=>5,'parent_id'=>4,'manager_id'=>null],
    ['id'=>6,'parent_id'=>2,'manager_id'=>null],
];
$members = [
    ['id'=>72,'department_id'=>2],
    ['id'=>73,'department_id'=>3],
    ['id'=>74,'department_id'=>3],
    ['id'=>75,'department_id'=>5],
    ['id'=>76,'department_id'=>6],
];
$test(ObjectScope::managedDepartments(72,$org)===[2,3,6],
    'Direct management and inherited descendants must be stable by IDs');
$test(ObjectScope::allows('team',72,74,$org,$members),
    'A manager cannot see a member of its managed subtree');
$test(!ObjectScope::allows('team',72,75,$org,$members),
    'A manager received unrelated department access');
$test(!ObjectScope::allows('team',75,74,$org,$members),
    'A regular employee inherited team rights without manager assignment');
$test(!ObjectScope::allows('own',72,74,$org,$members),
    'Own scope leaked another employee');
$test(ObjectScope::allows('own',72,72,$org,$members),
    'Own scope must include current actor');
$test(!ObjectScope::allows('team',72,444,$org,$members),
    'Missing trusted employee target cannot be treated as a team member');
$test(!ObjectScope::allows('all',0,74,$org,$members),
    'Anonymous actor must never get a global scope grant');
$test(ObjectScope::requiresCollectiveScope('api/registry'),
    'Global registry must not treat own scope as organization-wide');
$signed = ['employee-id:74','org:irlix','org:backend','head:backend','special:personnel-officer'];
$live = ['employee_id'=>74,'roles'=>['personnel-officer'],
    'role_claims'=>['org:irlix','org:backend','head:backend','special:personnel-officer']];
$test(Authz::currentIdentityMatches($signed,$live),'Live signed org/head and special roles rejected');
$live['role_claims']=['org:irlix','org:backend','special:personnel-officer'];
$test(!Authz::currentIdentityMatches($signed,$live),'Old head claim survived removal of management role');
$live['role_claims']=['org:irlix','org:backend','head:backend','special:personnel-officer'];
$live['employee_id']=75;
$test(!Authz::currentIdentityMatches($signed,$live),'Other employee authorized a foreign token');
$live['employee_id']=74;
$test(!Authz::currentIdentityMatches([...$signed,'platform-admin'],$live),
    'Old superuser JWT survived administrator role removal');
$test(!Authz::canOpenWorkspace([]), 'Empty Vacations matrix accidentally admits a service');
$test(!Authz::canOpenWorkspace(['vacations.mine.manage'=>['allowed'=>true,'scope'=>'own']]),
    'Mutation grant alone must not expose the service');
$test(Authz::canOpenWorkspace(['vacations.manage.view'=>['allowed'=>true,'scope'=>'team']]),
    'Manager with independent read grant must be admitted without mine permission');
echo "Vacations local roles: PASS\n";
