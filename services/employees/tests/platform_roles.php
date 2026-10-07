<?php

require_once __DIR__.'/../app/Support/SpecialRoles.php';

use App\Support\SpecialRoles;

$catalog = SpecialRoles::catalog();
if (!isset($catalog[SpecialRoles::PlatformTester])) {
    fwrite(STDERR, "platform-tester role is missing from catalog\n");
    exit(1);
}
if (!SpecialRoles::isPlatformPrivileged([SpecialRoles::PlatformAdmin])) {
    fwrite(STDERR, "platform-admin must remain platform privileged\n");
    exit(1);
}
if (!SpecialRoles::isPlatformPrivileged([SpecialRoles::PlatformTester])) {
    fwrite(STDERR, "platform-tester must be platform privileged\n");
    exit(1);
}
if (SpecialRoles::isPlatformPrivileged([SpecialRoles::PersonnelOfficer])) {
    fwrite(STDERR, "personnel-officer must not become platform privileged\n");
    exit(1);
}

$accessRoutes = file_get_contents(__DIR__.'/../routes/access.php');
foreach ([
    'SpecialRoles::PlatformAdmin, SpecialRoles::PlatformTester',
    '$testerCanMutateRole',
    'не может назначать роли администратора платформы или тестировщика платформы',
    'не может снимать роли администратора платформы или тестировщика платформы',
] as $needle) {
    if (!str_contains($accessRoutes, $needle)) {
        fwrite(STDERR, "protected platform-role mutation guard is incomplete: {$needle}\n");
        exit(1);
    }
}

echo "platform role checks passed\n";
