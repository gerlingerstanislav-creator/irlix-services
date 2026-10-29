<?php
declare(strict_types=1);

// Ephemeral SQLite integration of the ACTUAL Timesheets importer. No data
// from production and no authentication secrets are involved.
$path = sys_get_temp_dir().'/irlix-authz-import-'.bin2hex(random_bytes(8)).'.sqlite';
$pdo = new PDO('sqlite:'.$path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE timesheets_role_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL,
    permission TEXT NOT NULL, allowed INTEGER NOT NULL, scope TEXT NOT NULL,
    created_at TEXT, updated_at TEXT, UNIQUE(role, permission))');

$run = static function (array $rows) use ($path): int {
    $command = 'env DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($path).
        ' APP_ENV=testing php /app/bin/import-clients-legacy-permissions.php';
    $pipes = [];
    $process = proc_open($command, [
        0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Importer process unavailable');
    fwrite($pipes[0], json_encode(['version'=>1,'rows'=>$rows], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($process);
};
$grant = static function (string $role, string $permission, bool $allowed,
    string $scope, bool $denied = false): array {
    return compact('role','permission','allowed','scope','denied');
};
$rows = [
    $grant('account-manager','timesheets.management.view',true,'own'),
    $grant('department-manager','timesheets.management.manage',true,'team'),
    $grant('employee','timesheets.analytics.view',false,'none'),
    $grant('sales-manager','timesheets.audit.view',false,'none',true),
];
$assert = static function (bool $valid, string $why): void {
    if (!$valid) throw new RuntimeException('Legacy Timesheets import: '.$why);
};
try {
    $assert($run($rows) === 0, 'first import failed');
    $assert($run($rows) === 0, 'repeat import was not idempotent');
    $all = $pdo->query('SELECT role,permission,allowed,scope
        FROM timesheets_role_permissions')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($all) === 6, 'expected view and management partner grants and explicit deny');
    $byKey = [];
    foreach ($all as $row) $byKey[$row['role'].'|'.$row['permission']] = $row;
    $assert(($byKey['org:account-management|timesheets.partner-hours.view']['scope'] ?? null)==='own',
        'partner view permission was not imported from account-manager');
    $assert(($byKey['head:*|timesheets.partner-hours.approve']['scope'] ?? null)==='team',
        'partner final approval lost historic management scope');
    $assert(($byKey['org:sales|timesheets.audit.view']['allowed'] ?? null)==='0',
        'explicit legacy deny was not imported');
    $assert(!isset($byKey['org:irlix|timesheets.analytics.view']),
        'old unassigned false row incorrectly became an explicit denial');
    $changed = $rows;
    $changed[0]['scope'] = 'all';
    $assert($run($changed) !== 0, 'pre-existing incompatible V2 grant was ignored');
    $assert($byKey['org:account-management|timesheets.management.view']['scope']==='own',
        'failed import unexpectedly altered the original granted scope');
    echo "Private Clients Timesheets V2 import and conflict integration: PASS\n";
} finally {
    $pdo = null;
    @unlink($path);
}
