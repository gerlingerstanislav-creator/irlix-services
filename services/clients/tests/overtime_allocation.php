<?php
declare(strict_types=1);

require __DIR__ . '/../app/Support/OvertimeAllocation.php';

use App\Support\OvertimeAllocation;

function checkOvertime(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function mustReject(callable $run, string $reason): void
{
    try { $run(); } catch (DomainException $e) { return; }
    throw new RuntimeException($reason);
}

// One day's 7h + another day's 9h do not create monthly overtime by themselves.
$noOvertime = OvertimeAllocation::suggest(1, '2026-10', '16.00', [
    ['project_id' => 10, 'hours' => '16.00', 'plan_hours' => '16.00'],
], true);
checkOvertime($noOvertime['overtime_hours'] === 0.0
    && $noOvertime['status'] === 'ready_for_am_review',
    'No overtime when employee total equals monthly norm');

// Product acceptance: A=88h/plan84, B=84h/plan84, N=168 -> O=4h on A.
$basic = OvertimeAllocation::suggest(2, '2026-10', '168', [
    ['project_id' => 20, 'hours' => '84', 'plan_hours' => '84'],
    ['project_id' => 10, 'hours' => '88', 'plan_hours' => '84'],
], true);
checkOvertime($basic['overtime_hours'] === 4.0
    && $basic['projects'][0]['project_id'] === 10
    && $basic['projects'][0]['suggested_overtime_hours'] === 4.0
    && $basic['projects'][1]['suggested_overtime_hours'] === 0.0,
    'Plan-based suggestion must prefer project above plan');
OvertimeAllocation::validateFinal($basic, [10 => '4.00', 20 => '0.00'], true);

// A=90/80, B=78/80, N=160 -> O=8h (not 10h above project plan).
$overPlan = OvertimeAllocation::suggest(2, '2026-10', 160, [
    ['project_id'=>1,'hours'=>90,'plan_hours'=>80],
    ['project_id'=>2,'hours'=>78,'plan_hours'=>80],
], true);
checkOvertime($overPlan['overtime_hours'] === 8.0
    && $overPlan['projects'][0]['suggested_overtime_hours'] === 8.0,
    'Monthly overtime must cap sum of positive plan deviations');

// When plan deviations exceed O, divide proportionally rather than giving
// priority to an arbitrary client or to row arrival order.
$split = OvertimeAllocation::suggest(2, '2026-10', '200.00', [
    ['project_id'=>3,'hours'=>'110.00','plan_hours'=>'80.00'],
    ['project_id'=>5,'hours'=>'110.00','plan_hours'=>'80.00'],
], true);
checkOvertime($split['overtime_hours']===20.0
    && $split['projects'][0]['suggested_overtime_hours']===10.0
    && $split['projects'][1]['suggested_overtime_hours']===10.0,
    'Multi-project overplan allocation should be proportional');

// 0.01h units use largest-remainder allocation, tied by stable project id.
$rounding=OvertimeAllocation::suggest(2,'2026-10','1.01',[
    ['project_id'=>12,'hours'=>'0.51','plan_hours'=>'0.00'],
    ['project_id'=>11,'hours'=>'0.51','plan_hours'=>'0.00'],
],true);
checkOvertime($rounding['overtime_hours']===0.01
    && $rounding['projects'][0]['project_id']===11
    && $rounding['projects'][0]['suggested_overtime_hours']===0.01
    && $rounding['projects'][1]['suggested_overtime_hours']===0.0,
    'Exact centihours and deterministic rounding must preserve total overtime');

// Unplanned hours may not be silently attributed to any client.
$missingPlan=OvertimeAllocation::suggest(2,'2026-10','160',[
    ['project_id'=>1,'hours'=>'90','plan_hours'=>null],
    ['project_id'=>2,'hours'=>'80','plan_hours'=>'80'],
],true);
checkOvertime($missingPlan['status']==='manual_allocation_required'
    && $missingPlan['unassigned_hours']===10.0,
    'Unknown project plans require manual allocation, not fabricated hints');
mustReject(fn()=>OvertimeAllocation::validateFinal($missingPlan, [1=>0,2=>0],true),
    'Final allocation with missing 10h must be rejected');
// An AM can explicitly choose a different valid project split.
OvertimeAllocation::validateFinal($missingPlan,[1=>'10.00',2=>'0.00'],true);

// No full-month proof -> preview only, never final, even if allocation matches.
$pending=OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>'172','plan_hours'=>'168'],
],false);
checkOvertime($pending['status']==='provisional','Late Timesheets must mark provisional');
mustReject(fn()=>OvertimeAllocation::validateFinal($pending,[1=>'4'],true),
    'Incomplete source must never be finalizable');
mustReject(fn()=>OvertimeAllocation::validateFinal($basic,[10=>'4'],false),
    'Fresh readiness verification is mandatory at final write');
mustReject(fn()=>OvertimeAllocation::validateFinal($basic,[10=>'5'],true),
    'Over-allocation of employee-month overtime must fail');
mustReject(fn()=>OvertimeAllocation::validateFinal($basic,[99=>'4'],true),
    'AM cannot write an allocation for an unrelated project');
mustReject(fn()=>OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>100,'plan_hours'=>90],
    ['project_id'=>1,'hours'=>68,'plan_hours'=>68],
],true), 'Duplicate project rows must be rejected, never counted twice');
mustReject(fn()=>OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>'1.001','plan_hours'=>1],
],true), 'More than two decimal places cannot be silently rounded');

echo "Clients employee-month overtime suggestion and finalization guards OK\n";
