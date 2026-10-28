<?php

require __DIR__.'/../vendor/autoload.php';

use App\Support\EmployeesDirectory;

$assert = static function (bool $value, string $error): void {
    if (!$value) throw new RuntimeException($error);
};
$old = [
    'employee-id:44','service:vacations','org:irlix','head:accounting',
    'personnel-officer','special:personnel-officer','platform-admin','special:platform-admin',
];
$current = ['employee_id'=>44, 'roles'=>['personnel-officer','irlix']];
$actual = EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,$current);
$assert(in_array('personnel-officer',$actual,true),'Current personnel role incorrectly revoked');
$assert(in_array('special:personnel-officer',$actual,true),'Current namespaced personnel role lost');
$assert(!in_array('platform-admin',$actual,true),'Revoked admin remains effective');
$assert(!in_array('special:platform-admin',$actual,true),'Revoked special admin remains effective');
$assert(in_array('head:accounting',$actual,true),'Unrelated org/head roles unexpectedly mutated');

$revoked=EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,
    ['employee_id'=>44,'roles'=>['irlix']]);
$assert(!in_array('personnel-officer',$revoked,true),'Revoked personnel role remains effective');
$assert(!in_array('special:personnel-officer',$revoked,true),'Revoked special personnel role remains effective');
$assert(in_array('service:vacations',$revoked,true),'Revocation incorrectly removed ordinary admission');

foreach ([
    ['employee_id'=>33,'roles'=>['personnel-officer','platform-admin']],
    ['employee_id'=>44],
    [],
] as $invalid) {
    try {
        EmployeesDirectory::limitToCurrentPrivilegedRoles($old,44,$invalid);
        throw new RuntimeException('Missing or mismatched authoritative subject accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Missing or mismatched authoritative subject accepted') throw $e;
    }
}
$assert(EmployeesDirectory::limitToCurrentPrivilegedRoles(['employee-id:44','org:irlix'],44,
    ['employee_id'=>44,'roles'=>[]])===['employee-id:44','org:irlix'],
    'Regular signed organization labels must be left unchanged');
echo "Vacations immediate privileged-role revocation contract: PASS\n";
