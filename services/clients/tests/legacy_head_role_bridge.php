<?php

require __DIR__.'/../app/Support/ClientContourAccess.php';
use App\Support\ClientContourAccess as Access;

$check=static function(bool $valid,string $reason):void {
    if (!$valid) throw new RuntimeException($reason);
};
$departments = [
    ['id'=>1,'alias'=>'irlix','parent_id'=>null,'manager_id'=>0],
    ['id'=>2,'alias'=>'client-service','parent_id'=>1,'manager_id'=>33],
    ['id'=>3,'alias'=>'sales','parent_id'=>2,'manager_id'=>41],
    ['id'=>4,'alias'=>'backend','parent_id'=>1,'manager_id'=>61],
];
$employees = [
    ['id'=>41,'department_id'=>3],
    ['id'=>61,'department_id'=>4],
];
$verified = Access::currentIdentityLabels(
    ['employee-id:41','org:irlix','org:client-service','org:sales',
     'head:sales','head:backend','department-manager'],
    41, $departments, $employees
);
$check(in_array('head:sales',$verified,true), 'Signed current head was not verified');
$check(!in_array('head:backend',$verified,true), 'Unrelated head role accepted');
$check(!in_array('department-manager',$verified,true), 'Legacy manager granted V2 privileges');
$check(!in_array('org:backend',$verified,true), 'Unrelated organization membership accepted');
$check(!in_array('head:sales', Access::currentIdentityLabels(
    ['head:sales'],41,
    [['id'=>3,'alias'=>'sales','parent_id'=>2,'manager_id'=>0]],$employees
),true), 'Revoked head role retained V2 privileges');
// A direct Client Service job title has functional identity without org:sales
// or org:account-management ancestry. Only signed AND live Employees-verified
// functional claims may select those existing V2 business columns.
$check(Access::verifiedFunctionalMatrixLabels(['sales-manager'], ['sales-manager']) === ['org:sales'],
    'Verified Sales role did not select canonical V2 matrix column');
$check(Access::verifiedFunctionalMatrixLabels(['account-manager'], ['account-manager']) === ['org:account-management'],
    'Verified Account Manager role did not select canonical V2 matrix column');
$check(Access::verifiedFunctionalMatrixLabels(['sales-manager'], []) === [],
    'Stale Sales claim retained access after Employees role removal');
$check(Access::verifiedFunctionalMatrixLabels([], ['sales-manager']) === [],
    'Unsigned Sales role from directory escalated matrix rights');
$check(Access::verifiedFunctionalMatrixLabels(['account-manager'], ['sales-manager']) === [],
    'Unmatched signed functional role escalated matrix rights');
$check(Access::verifiedFunctionalMatrixLabels(['finance'], ['sales-manager']) === [],
    'Finance role was accepted as Clients Sales permission');

echo "Clients canonical head identity: PASS\n";
