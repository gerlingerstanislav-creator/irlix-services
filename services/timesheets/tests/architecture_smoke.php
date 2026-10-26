<?php

/**
 * Compile-time architecture contract. The runtime contract suite exercises real
 * routes against synthetic data; this gate catches accidental route/SQL regressions.
 */
$base = dirname(__DIR__);
$routeFiles = [
    'api.php', 'attention.php', 'partner-hours.php', 'system.php', 'integrations.php',
];
foreach ($routeFiles as $name) {
    $source = file_get_contents($base.'/routes/'.$name);
    if ($source === false) throw new RuntimeException("Missing route file: {$name}");
    if (preg_match('/Route::(?:get|post|put|patch|delete|match|any)\s*\([^,\n]+,\s*(?:function\s*\(|fn\s*\()/i', $source)) {
        throw new RuntimeException("Business route closure in {$name}");
    }
    if (preg_match('/\bDB::(?:table|transaction|select|statement|insert|update|delete)\s*\(/', $source)) {
        throw new RuntimeException("Direct persistence in route file {$name}");
    }
}
$controllers = glob($base.'/app/Http/Controllers/*.php') ?: [];
foreach ($controllers as $path) {
    $source = file_get_contents($path);
    if (preg_match('/\bDB::(?:table|transaction|select|statement|insert|update|delete)\s*\(/', $source)) {
        throw new RuntimeException("SQL/transactions in controller ".basename($path));
    }
}
$required = [
    'Models/TimesheetEntry.php',
    'Models/EmployeeConfirmation.php',
    'Models/FinalApproval.php',
    'Models/FinalApprovalException.php',
    'Models/PartnerDailyConfirmation.php',
    'Models/TimesheetAudit.php',
    'Models/ReportingFreezeRecord.php',
    'Services/PersonalTimesheetsService.php',
    'Services/ManagementTimesheetsService.php',
    'Services/PartnerHoursService.php',
    'Services/AnalyticsTimesheetsService.php',
    'Services/AuditTrailService.php',
    'Services/AttentionService.php',
    'Services/EmployeePurgeService.php',
    'Services/ReportingFreezeService.php',
];
foreach ($required as $name) {
    if (!is_file($base.'/app/'.$name)) throw new RuntimeException("Missing Timesheets layer: {$name}");
}
echo "Timesheets architecture boundary contract passed.\n";
