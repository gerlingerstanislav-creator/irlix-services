<?php

require __DIR__.'/../app/Support/RoleLabels.php';
use App\Support\RoleLabels;

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
$check(RoleLabels::resolve(null,$departments,['platform-admin']), []);
echo "Direct organizational and assigned Keycloak labels: PASS\n";
