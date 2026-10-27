<?php

namespace App\Support;

final class StoragePilotGate
{
    public static function canEnter(array $roles): bool
    {
        // A platform tester, top-management or system-admin is NOT a superuser.
        return in_array(SpecialRoles::PlatformAdmin, $roles, true);
    }
}
