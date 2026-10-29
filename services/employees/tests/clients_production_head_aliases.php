<?php
declare(strict_types=1);

require __DIR__.'/../app/Support/ClientsProductionHeadAliases.php';
use App\Support\ClientsProductionHeadAliases as Exporter;

$check = static function(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$departments = [
    ['id'=>1,'alias'=>'irlix','parent_id'=>0,'is_production'=>false,'manager_id'=>9],
    ['id'=>2,'alias'=>'client-service','parent_id'=>1,'is_production'=>false,'manager_id'=>20],
    ['id'=>3,'alias'=>'accounting','parent_id'=>2,'is_production'=>true,'manager_id'=>31],
    ['id'=>4,'alias'=>'backend','parent_id'=>1,'is_production'=>true,'manager_id'=>40],
    ['id'=>5,'alias'=>null,'parent_id'=>4,'is_production'=>true,'manager_id'=>null],
    ['id'=>6,'alias'=>'','parent_id'=>1,'is_production'=>true,'manager_id'=>0],
    ['id'=>7,'alias'=>'accounting','parent_id'=>1,'is_production'=>false,'manager_id'=>44],
];
$actual = Exporter::fromDepartments($departments);
$check($actual === ['account-management','backend'],
    'Only existing managed production roles must be exported; client accounting canonicalization failed');
$invalid = $departments;
$invalid[4]['manager_id'] = 50;
$failed = false;
try { Exporter::fromDepartments($invalid); }
catch(RuntimeException $e) { $failed = $e->getMessage() === 'Managed production department lacks canonical alias'; }
$check($failed, 'Managed production department missing alias was silently granted another role');
$duplicate = $departments;
$duplicate[] = ['id'=>8,'alias'=>'backend','parent_id'=>1,'is_production'=>true,'manager_id'=>55];
$failed = false;
try { Exporter::fromDepartments($duplicate); }
catch(RuntimeException $e) { $failed = $e->getMessage() === 'Duplicate managed production role alias'; }
$check($failed, 'Two different active managers are mapped to the same head role');
$check(Exporter::fromDepartments([['id'=>1,'alias'=>null,'parent_id'=>0,
    'is_production'=>true,'manager_id'=>0]]) === [],
    'No managed roles should be fabricated when only unmanaged branches exist');
echo "Employees managed production role alias export: PASS\n";
