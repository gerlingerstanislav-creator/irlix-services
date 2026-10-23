<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TimeHours
{
    // Integer hundredths for calculations; PostgreSQL NUMERIC(5,2) remains canonical storage.
    public static function cents(string|int|float $hours): int
    {
        $raw = trim((string) $hours);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2})0*)?$/D', $raw, $matches)) {
            throw new InvalidArgumentException('Hours must have no more than two significant decimal places');
        }
        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function decimal(string|int|float $hours): string
    {
        try { $cents = self::cents($hours); }
        catch (InvalidArgumentException) { abort(422, 'Hours must have at most two decimal places'); }
        abort_if($cents < 0 || $cents > 2400, 422, 'Hours must be from 0 to 24');
        return self::format($cents);
    }

    public static function quarter(string|int|float $hours): string
    {
        $value = self::decimal($hours);
        self::assertWritable($value);
        return $value;
    }

    // Historical hundredths may be retained during a description edit. New
    // amounts still follow the normal quarter-hour input rule.
    public static function assertWritable(string|int|float $hours, string|int|float|null $previous = null): void
    {
        $cents = self::cents($hours);
        abort_if($cents % 25 !== 0 && ($previous === null || self::cents($previous) !== $cents),
            422, 'New hours must be a multiple of 0.25');
    }

    public static function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    // Lock even when the employee has no entries yet: row locks alone cannot lock absent rows.
    // Always acquire locks in ascending date order for bulk operations.
    public static function lockDay(int $employeeId, string $date): void
    {
        ReportingFreeze::lock();
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'timesheets:employee-day:'.$employeeId.':'.$date,
            ]);
        }
    }
}
