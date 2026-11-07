<?php

require_once __DIR__.'/../app/Support/SpecialRoles.php';
require_once __DIR__.'/../app/Support/PermissionScopePolicy.php';

use App\Support\PermissionScopePolicy as Policy;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$check(Policy::defaultForRole('managers') === 'own', 'Managers must default to assigned subtrees');
$check(Policy::defaultForRole('direction-hr') === 'own', 'Direction HR must default to assigned subtrees');
$check(Policy::defaultForRole('personnel-officer') === 'all', 'Personnel officer needs company-wide staffing scope');
$check(Policy::defaultForRole('backend') === 'deny',
    'Unrelated department role must never gain global salary access by legacy boolean');
$check(Policy::merge(['backend' => ['enabled' => true, 'scope' => null, 'department_ids' => []]])['scope'] === 'none',
    'Legacy unrelated role without ownership must remain non-effective');
$check(Policy::options('personnel-officer', 'employees.read') === ['deny', 'all'],
    'Personnel officer must not get an undefined own-department option');
$check(Policy::options('managers', 'employees.salary.read') === ['deny', 'own', 'all'],
    'Managers need explicitly configurable per-permission scopes');
$check(Policy::options('managers', 'services.employees') === ['deny', 'allow'],
    'Service admission is not a department scope');
$check(Policy::savedValue('managers', 'employees.salary.read', true) === 'own',
    'Legacy manager grants stay scoped');
$check(Policy::savedValue('personnel-officer', 'employees.read', true) === 'all',
    'Legacy personnel grants stay global');

$managerSalary = Policy::merge(['managers' => [
    'enabled' => true, 'scope' => 'own', 'department_ids' => [2, 3],
]]);
$personnelRead = Policy::merge([
    'managers' => ['enabled' => true, 'scope' => 'own', 'department_ids' => [2, 3]],
    'personnel-officer' => ['enabled' => true, 'scope' => 'all', 'department_ids' => []],
]);
$check($managerSalary['scope'] === 'subtree' && $managerSalary['department_ids'] === [2, 3],
    'Manager salary grant must be department-scoped');
$check($personnelRead['scope'] === 'all', 'Read grants may combine to company-wide visibility');
// A broad read grant cannot change salary or FOT policy:
$check($managerSalary['scope'] === 'subtree', 'Salary broadened by unrelated read permission');
$check(Policy::intersect($personnelRead, $managerSalary)['department_ids'] === [2, 3],
    'FOT scope must be bounded by the salary permission');

$assignedHr = Policy::merge(['direction-hr' => [
    'enabled' => true, 'scope' => 'own', 'department_ids' => [4, 5],
]]);
$check($assignedHr['scope'] === 'subtree' && $assignedHr['department_ids'] === [4, 5],
    'Assigned HR must only see assigned departments');
$check(Policy::merge(['direction-hr' => [
    'enabled' => true, 'scope' => 'own', 'department_ids' => [],
]])['scope'] === 'none', 'Missing HR assignments must never become global');
$check(Policy::merge(['managers' => [
    'enabled' => true, 'scope' => 'all', 'department_ids' => [2, 3],
]])['scope'] === 'all', 'Explicit global grants must be distinct from default own grants');

echo "Employees permission-scope policy checks passed\n";
