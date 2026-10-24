<?php

require_once __DIR__.'/../app/Support/SpecialRoles.php';
require_once __DIR__.'/../app/Support/BrandbookGate.php';

use App\Support\BrandbookGate as Gate;

$positive = [['top-management'], ['platform-admin'], ['hr','top-management']];
$negative = [[], ['hr'], ['irlix-head'], ['accounting-head'], ['system-admin'], ['platform-tester'], ['top_management'], ['service:brandbook']];
foreach ($positive as $roles) if (!Gate::hasRole($roles)) throw new RuntimeException('valid role denied: '.json_encode($roles));
foreach ($negative as $roles) if (Gate::hasRole($roles)) throw new RuntimeException('unauthorized role accepted: '.json_encode($roles));
$employee = (object) ['id'=>12,'login'=>'test','employment_status'=>'Трудоустроен','keycloak_user_id'=>'opaque-identity'];
if (!Gate::active($employee)) throw new RuntimeException('employed identity denied');
foreach ([
    null, (object) ['login'=>'test','employment_status'=>'Уволен','keycloak_user_id'=>'opaque-identity'],
    (object) ['login'=>'test','employment_status'=>'Трудоустроен','keycloak_user_id'=>null],
] as $inactive) if (Gate::active($inactive)) throw new RuntimeException('inactive identity accepted');
$payload = ['employee_id'=>12,'subject'=>'opaque-identity','expires_at'=>time()+300];
if (!Gate::validSession($payload)) throw new RuntimeException('valid short session denied');
foreach ([
    [...$payload,'expires_at'=>time()-1],
    [...$payload,'expires_at'=>time()+301],
    [...$payload,'subject'=>''],
    [...$payload,'employee_id'=>0],
    [...$payload,'employee_id'=>'12'],
    ['employee_id'=>12,'expires_at'=>time()+10],
    null,
] as $bad) if (Gate::validSession($bad)) throw new RuntimeException('bad session accepted');
echo "brandbook role and session policy checks passed\n";
