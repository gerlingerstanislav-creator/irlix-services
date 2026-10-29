<?php

require __DIR__.'/../app/Services/SpecialistsRoleAuthorization.php';
use App\Services\SpecialistsRoleAuthorization as Authz;
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$rows=[
    ['role'=>'head:backend','permission'=>'specialists.profiles.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'head:backend','permission'=>'specialists.profiles.manage','allowed'=>true,'scope'=>'team'],
    ['role'=>'org:irlix','permission'=>'specialists.catalog.view','allowed'=>true,'scope'=>'all'],
    ['role'=>'org:irlix','permission'=>'specialists.catalog.manage','allowed'=>false,'scope'=>'none'],
];
$result=Authz::evaluate(['head:backend','org:irlix'],$rows);
$check($result['specialists.profiles.view']['scope']==='team','Manager scoped view missing');
$check($result['specialists.profiles.manage']['scope']==='team','Manager scoped write missing');
$check(!$result['specialists.catalog.manage']['allowed'],'Catalog editing must remain separately guarded');
$check($result['specialists.catalog.view']['scope']==='all','Catalog read must not inherit team scope');
$check(!Authz::evaluate(['service:specialists'],$rows)['specialists.profiles.view']['allowed'],
    'Legacy service entitlement gave specialist profile access');
$check(!Authz::evaluate(['head:backend'],[
    ['role'=>'head:backend','permission'=>'specialists.catalog.manage','allowed'=>true,'scope'=>'team']
])['specialists.catalog.manage']['allowed'],'Unscoped catalog operation trusted team');
$check(Authz::evaluate(['platform-admin'],[])['specialists.catalog.manage']['scope']==='all',
    'Platform admin override is required');
$base=[
    ['role'=>'head:*','permission'=>'specialists.profiles.view','allowed'=>true,'scope'=>'team'],
    ['role'=>'head:*','permission'=>'specialists.catalog.view','allowed'=>true,'scope'=>'all'],
];
$check(Authz::evaluate(['head:backend'],$base)['specialists.profiles.view']['scope']==='team',
    'Signed head did not inherit legacy production-manager profile scope');
$check(!Authz::evaluate(['org:backend','org:irlix'],$base)['specialists.profiles.view']['allowed'],
    'Non-manager organization member gained head template');
$check(!Authz::evaluate(['service:specialists'],$base)['specialists.catalog.view']['allowed'],
    'Service marker bypassed verified head requirement');
$base[]=['role'=>'head:backend','permission'=>'specialists.profiles.view','allowed'=>false,'scope'=>'none'];
$check(!Authz::evaluate(['head:backend'],$base)['specialists.profiles.view']['allowed'],
    'Explicit exact-head deny failed to override manager baseline');

echo "Specialists independent local scope authorization: PASS\n";
