<?php
declare(strict_types=1);

require __DIR__.'/../app/Support/ApprovedCommercialHours.php';

use App\Support\ApprovedCommercialHours;

function required(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function invalid(callable $action,string $message): void {
    try { $action(); } catch (DomainException) { return; }
    throw new RuntimeException($message);
}

$less = ApprovedCommercialHours::calculate(150,0,160,1000,2);
required($less['total_approved_hours'] === 150.0
    && $less['worked_hours']===160.0
    && $less['agreed_amount']===150000.0
    && $less['worked_amount']===160000.0,'AM can approve less than recorded');

$more = ApprovedCommercialHours::calculate(160,0,150,1000,1);
required($more['total_approved_hours']===160.0
    && $more['worked_hours']===150.0
    && $more['agreed_amount']===160000.0,'AM can approve more than recorded');

$ot = ApprovedCommercialHours::calculate(150,10,160,1000,1.5);
required($ot['total_approved_hours']===160.0
    && $ot['agreed_amount']===165000.0
    && $ot['worked_amount']===160000.0,
    'Overtime multiplier only applies to overtime, with no duplication');

$zero = ApprovedCommercialHours::calculate(0,0,0,100,1);
required($zero['agreed_amount']===0.0,'Zero approved hours permitted');

invalid(fn()=>ApprovedCommercialHours::calculate(150,-1,160,1000,2),'Negative overtime forbidden');
invalid(fn()=>ApprovedCommercialHours::calculate(150,0,160,1000,0),'Zero coefficient forbidden');
invalid(fn()=>ApprovedCommercialHours::calculate(150,0,160,NAN,1),'NaN must not be saved');
echo "Clients approved regular/overtime commercial amount rules OK\n";
