<?php
declare(strict_types=1);

require __DIR__.'/../app/Support/OvertimeCompensation.php';

use App\Support\OvertimeCompensation;

function mustMatch(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function mustFail(callable $run,string $message): void {
    try{$run();}catch(DomainException $e){return;}
    throw new RuntimeException($message);
}

// Approved default: 160k gross, 160h norm, 8h extra, worker x1.5.
$base=OvertimeCompensation::estimate('160','8','160000',null,'1.5');
mustMatch($base['hourly_base']===1000.0
    && $base['estimated_gross_extra']===12000.0
    && $base['hourly_base_source']==='gross_monthly_norm'
    && $base['quality']==='estimate_not_accrual',
    'Gross / monthly norm produces extra gross compensation above salary');

// x1 remains a positive additional payment, not an absence of compensation.
$one=OvertimeCompensation::estimate(160,8,160000,null,1);
mustMatch($one['estimated_gross_extra']===8000.0,
    'Worker x1 is a full extra base-rate payment');

// A contracted hourly base takes precedence and must not use the normal salary.
$contract=OvertimeCompensation::estimate('160','8','160000','1250','1.5');
mustMatch($contract['hourly_base']===1250.0
    && $contract['estimated_gross_extra']===15000.0
    && $contract['hourly_base_source']==='contract_hourly_rate',
    'Contracted overtime base supersedes gross/month norm');
$contractNoSalary=OvertimeCompensation::estimate(160,8,null,'1250',1);
mustMatch($contractNoSalary['estimated_gross_extra']===10000.0,
    'Unique contracted rate does not require duplicating salary data');

$withoutWorkerSetting=OvertimeCompensation::estimate(160,8,160000,null);
mustMatch($withoutWorkerSetting['multiplier']===1.0
    && $withoutWorkerSetting['estimated_gross_extra']===8000.0,
    'First release uses x1 with no employee coefficient setting');
mustFail(fn()=>OvertimeCompensation::estimate(160,8,null,null,1),
    'Missing gross and contracted base must not be replaced with zero');
mustFail(fn()=>OvertimeCompensation::estimate(0,8,160000,null,1),
    'Monthly norm cannot be zero');
mustFail(fn()=>OvertimeCompensation::estimate(160,-1,160000,null,1),
    'Negative overtime hours must be rejected');
mustFail(fn()=>OvertimeCompensation::estimate(160,8,160000,null,0),
    'Zero multiplier cannot silently erase employee compensation');
mustFail(fn()=>OvertimeCompensation::estimate(160,8,160000,'NaN',1),
    'Malformed contracted rate must fail even with valid salary');

echo "Finance overtime compensation estimates OK: independent worker multiplier, contract override and strict quality\n";
