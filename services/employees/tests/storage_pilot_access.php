<?php
require_once __DIR__.'/../app/Support/SpecialRoles.php';
require_once __DIR__.'/../app/Support/StoragePilotGate.php';
use App\Support\StoragePilotGate;
if (!StoragePilotGate::canEnter(['platform-admin'])) throw new RuntimeException('admin rejected');
foreach ([[],['employee'],['platform-tester'],['system-admin'],['top-management'],['storage']])
    as $roles) if (StoragePilotGate::canEnter($roles)) throw new RuntimeException('privilege escalation');
echo "Storage pilot role grant checks passed\n";
