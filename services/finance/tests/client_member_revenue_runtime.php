<?php
declare(strict_types=1);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\RevenueReport;
use Illuminate\Support\Facades\Http;

function revenueCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$excepted = false;
$conflicted = false;
$incomplete = false;
$protected = false;
$liveHours = '7.33';
$fault = null;
$entry = static fn (string $date, string $hours): array => [
    'client_member_id' => 51, 'client_id' => 2, 'employee_id' => null,
    'partner_specialist_id' => 15, 'work_date' => $date, 'hours' => $hours,
];
Http::fake(function ($request) use (&$excepted, &$conflicted, &$incomplete, &$protected, &$liveHours, &$fault, $entry) {
    if (str_contains($request->url(), '/commercial-terms')) {
        return Http::response(['data' => [
            'complete' => true, 'count' => 2,
            'allocation_from' => '2026-09-01', 'allocation_to' => '2026-10-31',
            'terms' => [[
                'term_id' => 9, 'client_member_id' => 51, 'client_id' => 2,
                'employee_id' => null, 'partner_specialist_id' => 15,
                'specialist_name' => 'Synthetic partner', 'hourly_rate' => '0.01',
                'valid_from' => '2026-09-01', 'valid_to' => '2026-10-15',
            ], [
                'term_id' => 10, 'client_member_id' => 51, 'client_id' => 2,
                'employee_id' => null, 'partner_specialist_id' => 15,
                'specialist_name' => 'Synthetic partner', 'hourly_rate' => '100.00',
                'valid_from' => '2026-10-16', 'valid_to' => null,
            ]],
            'periods' => [[
                'id' => 21, 'client_id' => 2, 'period_start' => '2026-09-01',
                'period_end' => '2026-10-31', 'overtime_multiplier' => '1.5',
                'status' => 'Акт согласован',
                'timesheet_revision' => $protected ? 'fixed-revision' : null,
                'timesheet_snapshot' => $protected ? [
                    'complete' => true, 'count' => 4, 'revision' => 'fixed-revision',
                    'entries' => [$entry('2026-09-01','1.00'), $entry('2026-09-02','1.00'),
                        $entry('2026-10-01','1.00'), $entry('2026-10-16','7.33')],
                    'final_approvals' => [['client_member_id'=>51,'month'=>'2026-09-01'],
                        ['client_member_id'=>51,'month'=>'2026-10-01']],
                    'final_approval_exceptions' => [],
                ] : null,
            ]],
            'approved_hours_overrides' => [[
                'reporting_period_id' => 21, 'member_term_id' => 9,
                'hours' => '1.00', 'overtime_hours' => '0.00',
            ], [
                'reporting_period_id' => 21, 'member_term_id' => 10,
                'hours' => '7.00', 'overtime_hours' => '0.33',
            ]],
        ]]);
    }
    revenueCheck($request['from'] === '2026-09-01' && $request['to'] === '2026-10-31',
        'Revenue must fetch the full approved period before splitting months');
    $data = [
        'complete' => !$incomplete, 'count' => 4,
        'entries' => [
            $entry('2026-09-01', '1.00'), $entry('2026-09-02', '1.00'),
            $entry('2026-10-01', '1.00'),
            $entry('2026-10-16', $liveHours) + ['term_conflict' => $conflicted],
        ],
        'final_approvals' => [
            ['client_member_id' => 51, 'month' => '2026-09-01'],
            ['client_member_id' => 51, 'month' => '2026-10-01'],
        ],
        'final_approval_exceptions' => $excepted
            ? [['client_member_id' => 51, 'work_date' => '2026-10-16']] : [],
    ];
    if ($fault !== null) unset($data[$fault]);
    return Http::response(['data' => $data]);
});

$september = RevenueReport::month('2026-09');
$october = RevenueReport::month('2026-10');
revenueCheck($september['quality'] === 'complete' && $october['quality'] === 'complete',
    'Partner revenue across months and two rates must be complete');
revenueCheck($september['client_agreed_amount'] === 0.01
    && $october['client_agreed_amount'] === 749.5
    && round($september['client_agreed_amount'] + $october['client_agreed_amount'], 2) === 749.51,
    'Largest remainder cents must reconcile across all months, rates and overtime');
revenueCheck($october['hours'] === 8.33 && $october['rows'][1]['employee_id'] === null
    && $october['rows'][1]['partner_specialist_id'] === 15,
    'Exact historical hours and partner identity must survive allocation');
$excepted = true;
$changed = RevenueReport::month('2026-10');
revenueCheck($changed['quality'] === 'awaiting_approvals' && $changed['client_agreed_amount'] === null,
    'A changed day under a monthly approval must not certify revenue');
$excepted = false;
$conflicted = true;
revenueCheck(RevenueReport::month('2026-10')['quality'] === 'incomplete',
    'Conflicted membership terms must not certify revenue');
$conflicted = false;
$incomplete = true;
try {
    RevenueReport::month('2026-10');
    throw new RuntimeException('Incomplete Timesheets response accepted');
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    revenueCheck($e->getStatusCode() === 503, 'Incomplete upstream must fail closed');
}
$incomplete = false;
foreach (['complete','count','final_approval_exceptions','final_approvals','entries'] as $fault) {
    try {
        RevenueReport::month('2026-10');
        throw new RuntimeException('Missing mandatory upstream field accepted: '.$fault);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        revenueCheck($e->getStatusCode() === 503, 'Missing collection must fail closed');
    }
}
$fault = null;
$protected = true;
$excepted = true;
$liveHours = '2.00';
$frozen = RevenueReport::month('2026-10');
revenueCheck($frozen['quality'] === 'complete' && $frozen['hours'] === 8.33
    && $frozen['client_agreed_amount'] === 749.5,
    'Protected accepted snapshot must survive mutable upstream hours and approvals');
echo "ClientMember Finance revenue: partner identity, exceptions, full-period allocation and exact cents PASS\n";
