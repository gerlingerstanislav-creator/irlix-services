<?php

require_once __DIR__.'/../app/Support/BrandbookGate.php';

use App\Support\BrandbookGate;

$employee = (object) ['id'=>12,'login'=>'test','employment_status'=>'Трудоустроен','keycloak_user_id'=>'subject'];
if (!BrandbookGate::active($employee)) throw new RuntimeException('Corporate identity rejected');
if (BrandbookGate::active((object) ['employment_status'=>'Уволен','login'=>'test','keycloak_user_id'=>'subject'])) {
    throw new RuntimeException('Inactive employee authorized');
}
if (!BrandbookGate::validSession(['employee_id'=>12,'subject'=>'subject','expires_at'=>time()+300])) {
    throw new RuntimeException('Valid short session rejected');
}
echo "design system corporate session policy checks passed\n";
