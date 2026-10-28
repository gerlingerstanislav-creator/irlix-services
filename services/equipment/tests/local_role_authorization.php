<?php

require __DIR__.'/../app/Services/EquipmentRoleAuthorization.php';
use App\Services\EquipmentRoleAuthorization as Authz;
$check=static function(bool $ok,string $error):void{if(!$ok)throw new RuntimeException($error);};
$rows=[
    ['role'=>'org:accounting','permission'=>'equipment.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'org:accounting','permission'=>'equipment.manage','allowed'=>true,'scope'=>'all'],
    ['role'=>'special:system-admin','permission'=>'equipment.operate','allowed'=>true,'scope'=>'all'],
    ['role'=>'org:irlix','permission'=>'equipment.operate','allowed'=>false,'scope'=>'none'],
];
$accounting=Authz::evaluate(['org:accounting'],$rows);
$check($accounting['equipment.view'] && $accounting['equipment.manage'],'Accounting basic grants missing');
$check(!$accounting['equipment.operate'],'Accounting could issue equipment without permission');
$check(!Authz::evaluate(['org:irlix','special:system-admin'],$rows)['equipment.operate'],
    'Explicit deny failed to override system administrator issue grant');
$check(!Authz::evaluate(['service:equipment','permission:equipment-operate'],$rows)['equipment.view'],
    'Legacy identity marker unexpectedly granted Equipment access');
$check(Authz::evaluate(['platform-admin'],[])['equipment.operate'],'Platform administrator override failed');
$check(!Authz::evaluate(['org:accounting'],[
    ['role'=>'org:accounting','permission'=>'equipment.view','allowed'=>true,'scope'=>'team']
])['equipment.view'],'Unsupported team scope was not denied');
echo "Equipment independent local authorization: PASS\n";
