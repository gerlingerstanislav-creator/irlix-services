<?php

namespace App\Domain\Absence;

use Carbon\CarbonImmutable;

final class AbsenceDayCalculator
{
    public function calendarDays(string $startsOn, ?string $endsOn): ?int
    {
        if ($endsOn === null) return null;
        return CarbonImmutable::parse($startsOn)->startOfDay()->diffInDays(CarbonImmutable::parse($endsOn)->startOfDay()) + 1;
    }

    public function entitlementDays(AbsenceType $type, string $startsOn, ?string $endsOn): ?int
    {
        $calendarDays = $this->calendarDays($startsOn, $endsOn);
        if ($calendarDays === null || $type !== AbsenceType::PaidVacation) return null;

        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();
        $days = 0;

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (!$this->isRussianNonWorkingHoliday($date)) $days++;
        }

        return $days;
    }

    private function isRussianNonWorkingHoliday(CarbonImmutable $date): bool
    {
        $md = $date->format('m-d');
        return in_array($md, [
            '01-01', '01-02', '01-03', '01-04', '01-05', '01-06', '01-07', '01-08',
            '02-23', '03-08', '05-01', '05-09', '06-12', '11-04',
        ], true);
    }
}
