<?php

require __DIR__.'/../app/Support/RoleLabels.php';
require __DIR__.'/../app/Support/SpecialRoles.php';
require __DIR__.'/../app/Support/KeycloakAuthorizationSync.php';
use App\Support\RoleLabels;
use App\Support\KeycloakAuthorizationSync;

$departments = [
    (object) ['id'=>1,'alias'=>'irlix','parent_id'=>null,'manager_id'=>100],
    (object) ['id'=>2,'alias'=>'accounting','parent_id'=>1,'manager_id'=>200],
    (object) ['id'=>3,'alias'=>'account-management','parent_id'=>1,'manager_id'=>300],
    (object) ['id'=>4,'alias'=>'sales','parent_id'=>1,'manager_id'=>200],
];
$roles = fn (int $id,int $department,array $special=[]) =>
    RoleLabels::resolve((object) ['id'=>$id,'department_id'=>$department], $departments, $special);
$check = fn ($actual,$expected) => $actual === $expected || throw new RuntimeException(
    'Expected '.json_encode($expected).' got '.json_encode($actual));
$check($roles(101,2), ['accounting']);
$check($roles(200,3), ['account-management','accounting','accounting-head','sales','sales-head']);
$check($roles(200,3,['personnel-officer']), ['account-management','accounting','accounting-head','personnel-officer','sales','sales-head']);
$check($roles(100,1), ['irlix','irlix-head']);
$check($roles(101,2,['system-admin','system-admin']), ['accounting','system-admin']);
$check($roles(0,4), ['sales']);
$unsafe = [(object) ['id'=>5,'alias'=>'platform-admin','parent_id'=>null,'manager_id'=>501],
    (object) ['id'=>6,'alias'=>'accounting-head','parent_id'=>null,'manager_id'=>501],
    (object) ['id'=>7,'alias'=>'system-admin','parent_id'=>null,'manager_id'=>501]];
$check(RoleLabels::resolve((object) ['id'=>501,'department_id'=>5], $unsafe), []);

$check(RoleLabels::resolve(null,$departments,['platform-admin']), []);
$check(KeycloakAuthorizationSync::grants([
    'roles'=>['accounting','accounting-head','personnel-officer'],
    'services'=>['finance'=>true,'clients'=>false,'vacations'=>true],
    'scope'=>'subtree','department_ids'=>[2,3],
]),['accounting','accounting-head','personnel-officer','scope:department-2','scope:department-3','service:finance','service:vacations']);
$check(KeycloakAuthorizationSync::grants([
    'employee_id'=>42,'roles'=>['accounting','accounting-head'],
    'services'=>['finance'=>true,'clients'=>false],'scope'=>'none'
]), ['accounting','accounting-head','employee-id:42','service:finance']);
$check(KeycloakAuthorizationSync::grants([
    'employee_id'=>12,'roles'=>['accounting'],
    'permissions'=>['finance.view'=>true,'finance.salary.view'=>false,'finance.cash.confirm'=>false],
    'services'=>['finance'=>true,'clients'=>false],'scope'=>'none'
]), ['accounting','employee-id:12','permission:finance-view','service:finance']);
$check(KeycloakAuthorizationSync::managedNames(
    ['platform-admin','accounting','accounting-head','os_accounting','offline_access','service:finance'],
    [],
    ['accounting','irlix']
),['platform-admin','accounting','accounting-head','service:finance']);
$check(KeycloakAuthorizationSync::grants(['roles'=>['irlix','irlix-head'],
    'services'=>['finance'=>false,'dashboard'=>true],'scope'=>'all']),
    ['irlix','irlix-head','scope:all','service:dashboard']);
echo "Direct organizational and assigned Keycloak labels: PASS\n";
