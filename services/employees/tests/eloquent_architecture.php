<?php

/**
 * Deterministic architecture regression. The application contracts and
 * authorization behaviors have separate synthetic SQLite / Docker smokes.
 */
$root = dirname(__DIR__);
$routes = [
    'api.php', 'fot.php', 'organization.php', 'self.php',
    'access.php', 'permissions.php', 'finance.php', 'audit.php',
    'onboarding.php', 'delete.php',
];
foreach ($routes as $name) {
    $source = file_get_contents($root.'/routes/'.$name);
    if (str_contains($source, 'DB::') || str_contains($source, '->table(')
        || preg_match('/Route::(?:get|post|put|patch|delete)\([^\n]*\b(?:function|fn)\s*\(/', $source)) {
        throw new RuntimeException($name.' must contain only controller route bindings');
    }
}

foreach ([
    'Employee', 'Department', 'EmployeeAccessRole', 'EmployeePermissionRule',
    'AuditLog', 'SalaryHistory', 'StaffPosition', 'EmploymentPeriod',
    'EmploymentAssignmentHistory', 'EmployeeStatusHistory', 'KeycloakAuthSync',
    'SalaryReview', 'SalaryReviewApproval', 'SalaryReviewVersion', 'OutboxEvent',
] as $model) {
    $source = file_get_contents($root.'/app/Models/'.$model.'.php');
    if (!str_contains($source, 'extends Model')) {
        throw new RuntimeException($model.' is not an Eloquent model');
    }
}

foreach ([
    'app/Services', 'app/Http/Controllers', 'app/Http/Middleware',
    'app/Support', 'bin',
] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root.'/'.$directory, FilesystemIterator::SKIP_DOTS
    ));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = file_get_contents($file->getPathname());
        if (str_contains($source, 'DB::table(')) {
            throw new RuntimeException($directory.'/'.$file->getFilename().' bypasses Eloquent');
        }
    }
}
echo "Employees controller/service/Eloquent boundary: PASS\n";
