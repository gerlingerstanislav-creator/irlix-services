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
if (SpecialRoles::isPlatformPrivileged([SpecialRoles::PlatformTester])) {
    fwrite(STDERR, "platform-tester must never be platform privileged\n");
    exit(1);
}
if (!isset($catalog[SpecialRoles::TopManagement]) || !SpecialRoles::exists(SpecialRoles::TopManagement)) {
    throw new RuntimeException('top-management missing');
}
if (SpecialRoles::isPlatformPrivileged([SpecialRoles::TopManagement])) {
    throw new RuntimeException('top-management is not platform privileged');
}
if (SpecialRoles::isPlatformPrivileged([SpecialRoles::PersonnelOfficer])) {
    fwrite(STDERR, "personnel-officer must not become platform privileged\n");
    exit(1);
}

$controller = file_get_contents(__DIR__.'/../app/Http/Controllers/AccessRoleController.php');
foreach ([
    'private function isPlatformAdmin(',
    'in_array(SpecialRoles::PlatformAdmin, $roles, true)',
    'Управление ролями доступно только администратору платформы.',
    'isSystemAdministrator($employee)',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        fwrite(STDERR, "protected platform-role mutation guard is incomplete: {$needle}\n");
        exit(1);
    }
}
echo "platform role checks passed\n";
