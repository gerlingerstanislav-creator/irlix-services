<?php

namespace App\Support;

use App\Services\TimesheetMembershipDirectory;

/** Compatibility facade for existing trusted API callers. */
final class TimesheetMemberships
{
    public static function snapshot(): array
    {
        return app(TimesheetMembershipDirectory::class)->snapshot();
    }
}
