<?php

/**
 * Lightweight source-level architecture regression: intentionally runnable without
 * Laravel or a database. HTTP/authorization integration requires a separate test.
 */
$root = dirname(__DIR__);
foreach (['access.php', 'permissions.php', 'organization.php', 'finance.php', 'audit.php', 'onboarding.php'] as $name) {
    $routes = file_get_contents($root.'/routes/'.$name);
    if (str_contains($routes, 'DB::') || preg_match('/Route::(?:get|post|put|patch|delete)\([^\n]*\b(?:function|fn)\s*\(/', $routes)) {
        throw new RuntimeException($name.' must contain only controller route bindings');
    }
}
foreach ([
    'Employee', 'Department', 'EmployeeAccessRole', 'EmployeePermissionRule',
    'AuditLog', 'SalaryHistory', 'StaffPosition', 'EmploymentPeriod', 'EmploymentAssignmentHistory',
] as $model) {
    $php = file_get_contents($root.'/app/Models/'.$model.'.php');
    if (!str_contains($php, 'extends Model')) throw new RuntimeException($model.' is not an Eloquent model');
}
foreach ([
    'SpecialRoleService', 'PermissionRuleService', 'BrandbookSessionService',
    'FinanceDirectoryService', 'AuditQueryService', 'StaffPositionService', 'DepartmentService',
] as $service) {
    $php = file_get_contents($root.'/app/Services/'.$service.'.php');
    if (str_contains($php, 'DB::table(')) throw new RuntimeException($service.' bypasses Eloquent');
}
$authorization = file_get_contents($root.'/app/Support/EmployeesAccess.php');
if (str_contains($authorization, 'DB::table(')) throw new RuntimeException('EmployeesAccess must use Eloquent');
echo "employees authorization Eloquent architecture: PASS\n";
