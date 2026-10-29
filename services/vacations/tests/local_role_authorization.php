<?php

// Load Laravel helpers before exercising the real app services. An isolated
// PHP script does not load env() or the request/container helpers by itself.
require __DIR__.'/../vendor/autoload.php';

set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, 'Vacations role authorization FAIL: '.$error->getMessage()."\n");
    exit(1);
});

require __DIR__.'/../app/Services/VacationsRoleAuthorization.php';
use App\Services\VacationsRoleAuthorization as Authz;
require __DIR__.'/../app/Services/VacationsObjectScope.php';
use App\Services\VacationsObjectScope as ObjectScope;
require __DIR__.'/../app/Services/VacationsActionPolicy.php';
use App\Services\VacationsActionPolicy as ActionPolicy;
require __DIR__.'/../app/Support/EmployeesDirectory.php';
require __DIR__.'/../app/Support/VacationsAccess.php';
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;

$test = static function (bool $condition, string $why): void {
    if (!$condition) throw new RuntimeException($why);
};
$entry = \App\Http\Middleware\ServiceEntitlement::class;
$clientsOnly = ['clients'=>true,'vacations'=>false];
$test($entry::hasCurrentServiceAdmission('api/client-service-calendar','GET',$clientsOnly),
    'Clients-only specialist calendar legacy access was lost in V2');
$test($entry::hasCurrentServiceAdmission('api/client-service-absence-alerts','GET',$clientsOnly),
    'Clients-only specialist alerts legacy access was lost in V2');
$test(!$entry::hasCurrentServiceAdmission('api/workspace','GET',$clientsOnly),
    'Clients-only actor unexpectedly opened full Vacations workspace');
$test(!$entry::hasCurrentServiceAdmission('api/absences/1/attachments/2/download','GET',$clientsOnly),
    'Clients-only actor unexpectedly obtained Vacations attachments');
$test(!$entry::hasCurrentServiceAdmission('api/client-service-calendar','POST',$clientsOnly),
    'Clients-only actor obtained write access from calendar entry');
$test(!$entry::hasCurrentServiceAdmission('api/client-service-calendar','GET',
    ['clients'=>false,'vacations'=>false]), 'Explicit Clients service deny was bypassed');
$test($entry::hasCurrentServiceAdmission('api/workspace','GET',
    ['clients'=>false,'vacations'=>true]), 'Existing Vacations entry was lost');

$rows = [
    ['role'=>'org:irlix','permission'=>'vacations.mine.view','allowed'=>true,'scope'=>'own'],
    ['role'=>'special:personnel-officer','permission'=>'vacations.manage.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'head:accounting','permission'=>'vacations.department.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'org:hr','permission'=>'vacations.documents.view','allowed'=>false,'scope'=>'none'],
    ['role'=>'special:personnel-officer','permission'=>'vacations.documents.view','allowed'=>true,'scope'=>'all'],
];
$managerTemplate = [
    ['role'=>'head:*','permission'=>'vacations.department.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'head:*','permission'=>'vacations.documents.manage','allowed'=>true,'scope'=>'team'],
];
$managerPolicy = Authz::evaluate(['head:backend'], $managerTemplate);
$test($managerPolicy['vacations.department.view']['scope']==='team',
    'Signed current manager lost existing team view');
$test(!$managerPolicy['vacations.documents.view']['allowed'],
    'Manager received confidential document download access');
$test(!Authz::evaluate(['org:backend'], $managerTemplate)['vacations.department.view']['allowed'],
    'Ordinary employee inherited management scope');
$managerTemplate[]=['role'=>'head:backend','permission'=>'vacations.department.view',
    'allowed'=>false,'scope'=>'none'];
$test(!Authz::evaluate(['head:backend'], $managerTemplate)['vacations.department.view']['allowed'],
    'Explicit manager deny failed to override inherited template');

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
    ['GET','api/calendar-absences','vacations.department.view'],
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
// Every business API route, including attachments, must declare a local
// permission. A new endpoint cannot accidentally become an unguarded route
// simply because someone forgot to update the V2 matrix.
$api = file_get_contents(__DIR__.'/../routes/api.php');
preg_match_all("~Route::(get|post|patch|delete|put)\\(\\s*'([^']+)'~",
    $api, $routeMatches, PREG_SET_ORDER);
$test(count($routeMatches) >= 20, 'Vacations route test did not parse the real API');
foreach ($routeMatches as $route) {
    $method = strtoupper($route[1]);
    $path = 'api'.preg_replace('/\\{[a-zA-Z_]+\\}/', '17', $route[2]);
    if (in_array($path, ['api/health','api/permissions','api/permissions/me'], true)) {
        // Separate authenticated health / admin / current-actor paths.
        continue;
    }
    $required = Authz::requiredPermission($method,$path);
    $test($required !== null && in_array($required, Authz::PERMISSIONS, true),
        "Unmapped business endpoint {$method} {$path}");
}
$ownPolicy = [
    'vacations.mine.view'=>['allowed'=>true,'scope'=>'own'],
    'vacations.mine.manage'=>['allowed'=>false,'scope'=>'none'],
    'vacations.documents.view'=>['allowed'=>false,'scope'=>'none'],
    'vacations.approvals.manage'=>['allowed'=>false,'scope'=>'none'],
];
$test(ActionPolicy::visible(['view','edit','submit','approve','view_attachments'],
    $ownPolicy) === ['view'], 'UI must hide actions denied by local policy');
$test(ActionPolicy::visible(['view','edit'],null) === ['view','edit'],
    'Legacy UI projections must be preserved');
$test(ActionPolicy::visible(['view','reject','approve'],
    ['vacations.approvals.manage'=>['allowed'=>true,'scope'=>'own']])
    === ['view','reject','approve'], 'Assigned approver actions lost');
$oldJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
$oldLocal = getenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED=true');
$actorPolicy = new VacationsAccess(new EmployeesDirectory());
$legacyPrivilege = ['roles'=>['personnel-officer','hr','manager','backend-head']];
$test(!$actorPolicy->isPersonnelOfficer($legacyPrivilege),
    'Legacy personnel role still authorizes local V2 staffing operations');
$test(!$actorPolicy->isHr($legacyPrivilege),
    'Legacy HR role still authorizes local V2 privileges');
$test(!$actorPolicy->isManager($legacyPrivilege),
    'Legacy manager alias still authorizes local V2 permissions');
$test($actorPolicy->isPersonnelOfficer(['roles'=>['special:personnel-officer']]),
    'V2 explicitly assigned personnel officer denied');
$test($actorPolicy->isManager(['roles'=>['head:backend']]),
    'V2 directly assigned head role denied');
if ($oldJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
if ($oldLocal === false) putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED='.$oldLocal);
echo "Vacations local roles: PASS\n";
