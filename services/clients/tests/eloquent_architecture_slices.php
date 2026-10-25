<?php

// The staged refactor must not let already-converted HTTP boundaries regress.
// Remaining legacy controllers are intentional WIP, not implicit compliance.
$root = dirname(__DIR__);
foreach ([
    'AbsenceApproversController', 'TimesheetMembershipsController',
    'FinanceClientsController', 'EmployeePurgeController',
    'PartnersController', 'MemberTermsController',
    'ProjectMemberFeedbackController', 'ProjectMemberCardController',
    'ClientAccountHandoffsController',
] as $name) {
    $path = $root.'/app/Http/Controllers/'.$name.'.php';
    $code = file_get_contents($path);
    if ($code === false || str_contains($code, 'DB::table(')
        || str_contains($code, 'DB::transaction(')
        || preg_match('/\\b[A-Z][A-Za-z0-9_]*::query\\(/', $code)) {
        throw new RuntimeException("HTTP controller still owns persistence: {$name}");
    }
}
$routes = file_get_contents($root.'/routes/internal.php');
if (str_contains($routes, 'DB::table(') || str_contains($routes, 'DB::transaction(')
    || preg_match('/Route::(get|post|put|patch|delete)\\([^\\n]*?function\\s*\\(/', $routes)) {
    throw new RuntimeException('Internal business routes must dispatch to controllers');
}
echo "Clients staged Eloquent HTTP boundary gate: PASS\n";
