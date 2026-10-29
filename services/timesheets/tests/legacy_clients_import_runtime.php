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

$run = static function (array $rows, ?string $database = null) use ($path): int {
    $command = 'env DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($database ?? $path).
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
    $assert(count($all) === 7, 'expected legacy personal view, partner grants and explicit deny');
    $byKey = [];
    foreach ($all as $row) $byKey[$row['role'].'|'.$row['permission']] = $row;
    $assert(($byKey['org:irlix|timesheets.mine.view']['scope'] ?? null)==='own',
        'Legacy employee workspace GET was lost when old Clients matrix had no row');
    $assert((int)($byKey['org:irlix|timesheets.mine.view']['allowed'] ?? 0) === 1,
        'Legacy personal workspace view was not restored');
    $assert(($byKey['org:account-management|timesheets.partner-hours.view']['scope'] ?? null)==='own',
        'partner view permission was not imported from account-manager');
    $assert(($byKey['head:*|timesheets.partner-hours.approve']['scope'] ?? null)==='team',
        'partner final approval lost historic management scope');
    $assert((int)($byKey['org:sales|timesheets.audit.view']['allowed'] ?? 1) === 0,
        'explicit legacy deny was not imported');
    $assert(!isset($byKey['org:irlix|timesheets.analytics.view']),
        'old unassigned false row incorrectly became an explicit denial');
    $changed = $rows;
    $changed[0]['scope'] = 'all';
    $assert($run($changed) !== 0, 'pre-existing incompatible V2 grant was ignored');
    $scopeAfterFailure = $pdo->query("SELECT scope FROM timesheets_role_permissions
        WHERE role='org:account-management' AND permission='timesheets.management.view'")
        ->fetchColumn();
    $assert($scopeAfterFailure === 'own',
        'failed import unexpectedly altered the original granted scope');
    // A previously configured explicit legacy deny MUST win over the
    // inferred backend workspace default; test with a separate clean DB.
    $deniedDb = sys_get_temp_dir().'/irlix-authz-denied-'.bin2hex(random_bytes(8)).'.sqlite';
    $deniedPdo = new PDO('sqlite:'.$deniedDb);
    $deniedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ddl = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='timesheets_role_permissions'")
        ->fetchColumn();
    $deniedPdo->exec($ddl);
    try {
        $assert($run([$grant('employee','timesheets.mine.view',false,'none',true)], $deniedDb) === 0,
            'explicit legacy personal view deny import failed');
        $saved = $deniedPdo->query("SELECT allowed,scope FROM timesheets_role_permissions
            WHERE role='org:irlix' AND permission='timesheets.mine.view'")
            ->fetch(PDO::FETCH_ASSOC);
        $assert($saved && (int)$saved['allowed'] === 0 && $saved['scope']==='none',
            'explicit old employee denial was overridden by inferred default');
    } finally {
        $deniedPdo = null;
        @unlink($deniedDb);
    }
    echo "Private Clients Timesheets V2 import and conflict integration: PASS\n";
} finally {
    $pdo = null;
    @unlink($path);
}
