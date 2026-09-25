<?php

declare(strict_types=1);

$employees = json_decode(stream_get_contents(STDIN), true);
if (!is_array($employees) || $employees === []) {
    fwrite(STDERR, "Vacations demo seed: employee directory is empty\n");
    exit(1);
}

$month = getenv('VACATIONS_DEMO_SEED_MONTH') ?: '2026-09';
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    fwrite(STDERR, "Vacations demo seed: invalid month {$month}\n");
    exit(1);
}

$monthStart = new DateTimeImmutable($month . '-01');
$monthEnd = $monthStart->modify('last day of this month');
$daysInMonth = (int) $monthEnd->format('d');

$host = getenv('DB_HOST') ?: 'postgres';
$port = getenv('DB_PORT') ?: '5432';
$db = getenv('DB_DATABASE') ?: 'irlix_services';
$user = getenv('DB_USERNAME') ?: 'vacations_app';
$password = getenv('DB_PASSWORD') ?: '';
$pdo = new PDO(
    "pgsql:host={$host};port={$port};dbname={$db}",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS vacations.demo_seed_runs (
    seed_key varchar(100) PRIMARY KEY,
    seeded_count integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
)
SQL);

$seedKey = 'vacations-demo-' . $month;
$checkRun = $pdo->prepare('SELECT seeded_count FROM vacations.demo_seed_runs WHERE seed_key = :seed_key');
$checkRun->execute(['seed_key' => $seedKey]);
if (($count = $checkRun->fetchColumn()) !== false) {
    echo "Vacations demo seed {$seedKey} already applied ({$count} rows)\n";
    exit(0);
}

$grouped = [];
foreach ($employees as $employee) {
    $id = (int) ($employee['id'] ?? 0);
    $departmentId = (int) ($employee['department_id'] ?? 0);
    if ($id <= 0 || $departmentId <= 0) continue;
    $grouped[$departmentId][] = [
        'id' => $id,
        'department_id' => $departmentId,
        'full_name' => (string) ($employee['full_name'] ?? "#{$id}"),
    ];
}
ksort($grouped);
foreach ($grouped as &$group) {
    usort($group, fn (array $a, array $b) => $a['id'] <=> $b['id']);
}
unset($group);

// Round-robin by department so the demo calendar visibly covers different directions.
// Keep extra candidates because employees with real September absences are skipped.
$candidates = [];
for ($round = 0; count($candidates) < 36; $round++) {
    $added = false;
    foreach ($grouped as $group) {
        if (!isset($group[$round])) continue;
        $candidates[] = $group[$round];
        $added = true;
        if (count($candidates) >= 36) break;
    }
    if (!$added) break;
}

$patterns = [
    ['type' => 'paid_vacation',   'status' => 'confirmed',              'from' => 2,  'to' => 6,  'label' => 'Подтверждённый оплачиваемый отпуск'],
    ['type' => 'unpaid_vacation', 'status' => 'rejected',               'from' => 8,  'to' => 9,  'label' => 'Отклонённый неоплачиваемый отпуск'],
    ['type' => 'day_off',         'status' => 'cancelled',              'from' => 11, 'to' => 11, 'label' => 'Отменённый отгул'],
    ['type' => 'paid_vacation',   'status' => 'hr_review',              'from' => 13, 'to' => 17, 'label' => 'На первичной проверке HR'],
    ['type' => 'unpaid_vacation', 'status' => 'account_manager_review', 'from' => 18, 'to' => 20, 'label' => 'На согласовании аккаунт-менеджера'],
    ['type' => 'paid_vacation',   'status' => 'manager_review',         'from' => 21, 'to' => 24, 'label' => 'На согласовании руководителя'],
    ['type' => 'day_off',         'status' => 'hr_final_review',        'from' => 25, 'to' => 25, 'label' => 'На финальном подтверждении HR'],
    ['type' => 'paid_vacation',   'status' => 'planned',                'from' => 27, 'to' => 30, 'label' => 'Запланированный отпуск'],
    ['type' => 'sick_leave',      'status' => 'confirmed',              'from' => 4,  'to' => 5,  'label' => 'Подтверждённый больничный'],
    ['type' => 'unpaid_vacation', 'status' => 'planned',                'from' => 15, 'to' => 16, 'label' => 'Запланированный неоплачиваемый отпуск'],
    ['type' => 'day_off',         'status' => 'hr_review',              'from' => 7,  'to' => 7,  'label' => 'Отгул на проверке HR'],
    ['type' => 'paid_vacation',   'status' => 'manager_review',         'from' => 10, 'to' => 12, 'label' => 'Отпуск на согласовании руководителя'],
];

$overlap = $pdo->prepare(<<<'SQL'
SELECT 1
FROM vacations.absences
WHERE employee_id = :employee_id
  AND starts_on <= :month_end
  AND (ends_on IS NULL OR ends_on >= :month_start)
LIMIT 1
SQL);
$insertAbsence = $pdo->prepare(<<<'SQL'
INSERT INTO vacations.absences (
    employee_id, type, starts_on, ends_on, calendar_days, entitlement_days,
    status, comment, created_by_subject, submitted_at, confirmed_at, created_at, updated_at
) VALUES (
    :employee_id, :type, :starts_on, :ends_on, :calendar_days, :entitlement_days,
    :status, :comment, 'demo-seed', :submitted_at, :confirmed_at, now(), now()
)
RETURNING id
SQL);
$insertHistory = $pdo->prepare(<<<'SQL'
INSERT INTO vacations.absence_status_history (
    absence_id, from_status, to_status, actor_subject, actor_employee_id, reason, created_at
) VALUES (:absence_id, NULL, :status, 'demo-seed', NULL, 'demo_seed', now())
SQL);
$insertAudit = $pdo->prepare(<<<'SQL'
INSERT INTO vacations.absence_audit_log (
    absence_id, event, actor_subject, actor_employee_id, before, after, created_at
) VALUES (:absence_id, 'created', 'demo-seed', NULL, NULL, CAST(:after AS jsonb), now())
SQL);
$insertApproval = $pdo->prepare(<<<'SQL'
INSERT INTO vacations.absence_approvals (
    absence_id, sequence, stage, status, required_role, approver_employee_id,
    created_at, updated_at
) VALUES (:absence_id, 1, :stage, 'pending', :required_role, NULL, now(), now())
SQL);

$approvalRoles = [
    'hr_review' => 'hr',
    'account_manager_review' => 'account-manager',
    'manager_review' => 'manager',
    'hr_final_review' => 'hr',
];

$pdo->beginTransaction();
try {
    $seeded = 0;
    $patternIndex = 0;
    foreach ($candidates as $employee) {
        if ($patternIndex >= count($patterns)) break;

        $overlap->execute([
            'employee_id' => $employee['id'],
            'month_start' => $monthStart->format('Y-m-d'),
            'month_end' => $monthEnd->format('Y-m-d'),
        ]);
        if ($overlap->fetchColumn()) continue;

        $pattern = $patterns[$patternIndex];
        $fromDay = min($daysInMonth, $pattern['from']);
        $toDay = min($daysInMonth, max($fromDay, $pattern['to']));
        $startsOn = sprintf('%s-%02d', $month, $fromDay);
        $endsOn = sprintf('%s-%02d', $month, $toDay);
        $calendarDays = $toDay - $fromDay + 1;
        $entitlementDays = $pattern['type'] === 'paid_vacation' ? $calendarDays : null;
        $submittedAt = $pattern['status'] === 'planned' ? null : $startsOn . ' 09:00:00+00';
        $confirmedAt = $pattern['status'] === 'confirmed' ? $endsOn . ' 12:00:00+00' : null;
        $comment = sprintf('[demo-seed:%s] %s · %s', $month, $pattern['label'], $employee['full_name']);

        $insertAbsence->execute([
            'employee_id' => $employee['id'],
            'type' => $pattern['type'],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'calendar_days' => $calendarDays,
            'entitlement_days' => $entitlementDays,
            'status' => $pattern['status'],
            'comment' => $comment,
            'submitted_at' => $submittedAt,
            'confirmed_at' => $confirmedAt,
        ]);
        $absenceId = (int) $insertAbsence->fetchColumn();

        $insertHistory->execute(['absence_id' => $absenceId, 'status' => $pattern['status']]);
        $insertAudit->execute([
            'absence_id' => $absenceId,
            'after' => json_encode([
                'employee_id' => $employee['id'],
                'type' => $pattern['type'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => $pattern['status'],
                'comment' => $comment,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (isset($approvalRoles[$pattern['status']])) {
            $insertApproval->execute([
                'absence_id' => $absenceId,
                'stage' => $pattern['status'],
                'required_role' => $approvalRoles[$pattern['status']],
            ]);
        }

        $seeded++;
        $patternIndex++;
    }

    $marker = $pdo->prepare('INSERT INTO vacations.demo_seed_runs (seed_key, seeded_count) VALUES (:seed_key, :seeded_count)');
    $marker->execute(['seed_key' => $seedKey, 'seeded_count' => $seeded]);
    $pdo->commit();
    echo "Vacations demo seed {$seedKey} applied ({$seeded} rows)\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Vacations demo seed failed: {$e->getMessage()}\n");
    exit(1);
}
