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
    && $noOvertime['status'] === 'suggestion_only',
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
// Suggestion does not fix or limit the AM's commercial approval.

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
checkOvertime($missingPlan['status']==='suggestion_only'
    && $missingPlan['unassigned_hours']===10.0,
    'Unknown project plans require manual allocation, not fabricated hints');
// Missing global Timesheets proofs do not block an AM approving their
// own client's period. The preview's status is never a server gate.
$pending=OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>'172','plan_hours'=>'168'],
],false);
checkOvertime($pending['status']==='suggestion_only' && !$pending['full_month_complete'],
    'Incomplete data still provides a nonblocking AM suggestion');
mustReject(fn()=>OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>100,'plan_hours'=>90],
    ['project_id'=>1,'hours'=>68,'plan_hours'=>68],
],true), 'Duplicate project rows must be rejected, never counted twice');
mustReject(fn()=>OvertimeAllocation::suggest(2,'2026-10','168',[
    ['project_id'=>1,'hours'=>'1.001','plan_hours'=>1],
],true), 'More than two decimal places cannot be silently rounded');

echo "Clients optional employee-month overtime suggestions OK (no global finalization gate)\n";
