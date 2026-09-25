<?php

namespace App\Domain\Absence;

enum AbsenceType: string
{
    case PaidVacation = 'paid_vacation';
    case UnpaidVacation = 'unpaid_vacation';
    case SickLeave = 'sick_leave';
    case MaternityLeave = 'maternity_leave';
    case DayOff = 'day_off';

    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
