<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** PostgreSQL advisory lock shared by all partner writes to one person/day. */
final class PartnerDayLock
{
    public static function acquire(int $partnerSpecialistId, string $date): void
    {
        ReportingFreeze::lock();
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                'timesheets:partner-day:'.$partnerSpecialistId.':'.$date,
            ]);
        }
    }
}
