<?php
declare(strict_types=1);
require __DIR__.'/../app/Support/CommercialRevenueAllocation.php';

use App\Support\CommercialRevenueAllocation as Alloc;

function expect(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
$period=['id'=>21,'client_id'=>2,'period_start'=>'2026-10-01',
    'period_end'=>'2026-10-31','overtime_multiplier'=>'1.5','status'=>'Акт согласован'];
$row=['term_id'=>9,'reporting_period_id'=>21,'rate'=>100,
    'hours'=>160.0,'amount'=>16000.0];

$less=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>0]],[$period],'2026-10');
expect(!$less['errors'] && $less['rows'][0]['amount']===15000.0,
    'AM 150 + 0 / 160 must not be forced to match actual hours');

$more=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>170,'overtime_hours'=>0]],[$period],'2026-10');
expect(!$more['errors'] && $more['rows'][0]['amount']===17000.0,
    'AM 170 + 0 / 160 must not be clamped to Timesheets');

$extra=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>10]],[$period],'2026-10');
expect(!$extra['errors'] && $extra['rows'][0]['amount']===16500.0,
    'Only 10h overtime are multiplied ×1.5');

$source2=$row;
$source2['hours']=100.0;
$source2['amount']=10000.0;
$source3=$row;
$source3['hours']=60.0;
$source3['amount']=6000.0;
$split=Alloc::apply([$source2,$source3],[['reporting_period_id'=>21,
    'member_term_id'=>9,'hours'=>150,'overtime_hours'=>10]],[$period],'2026-10');
expect(!$split['errors']
    && round(array_sum(array_column($split['rows'],'amount')),2)===16500.0,
    'Each approved period amount is allocated exactly once across Timesheet days');

$small=['term_id'=>9,'reporting_period_id'=>21,'rate'=>0.01,'hours'=>1,'amount'=>0.01];
$cents=Alloc::apply([$small,$small,$small],[['reporting_period_id'=>21,
    'member_term_id'=>9,'hours'=>1,'overtime_hours'=>0]],[$period],'2026-10');
expect(!$cents['errors'] && array_sum(array_column($cents['rows'],'amount'))===0.01
    && collectValuesNonnegative($cents['rows']),
    'Remainder-based cent allocation must not create negative daily amounts');

$crossPeriod=$period;
$crossPeriod['period_start']='2026-09-15';
$cross=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>10]],[$crossPeriod],'2026-10');
expect(count($cross['errors'])===1
    && $cross['errors'][0]['reason']==='client_approved_hours_requires_month_allocation'
    && $cross['rows'][0]['amount']===16000.0,
    'Cross-month approval cannot be silently allocated wholly to October');

$invalidFactor=$period;
$invalidFactor['overtime_multiplier']=null;
$invalid=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>10]],[$invalidFactor],'2026-10');
expect(count($invalid['errors'])===1
    && $invalid['errors'][0]['reason']==='invalid_client_approved_hours_or_multiplier',
    'Missing period multiplier must fail closed');

$duplicated=Alloc::apply([$row],[['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>10],['reporting_period_id'=>21,'member_term_id'=>9,
    'hours'=>150,'overtime_hours'=>10]],[$period],'2026-10');
expect(count($duplicated['errors'])===1,'Duplicate approval must fail closed');

function collectValuesNonnegative(array $rows): bool {
    foreach ($rows as $r) if ($r['amount']<0) return false;
    return true;
}
echo "Finance commercial period revenue allocation contracts OK\n";
