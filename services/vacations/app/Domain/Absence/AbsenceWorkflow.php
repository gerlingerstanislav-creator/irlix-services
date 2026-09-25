<?php

namespace App\Domain\Absence;

use DomainException;

final class AbsenceWorkflow
{
    public function submitTarget(AbsenceType $type, ?string $endsOn): AbsenceStatus
    {
        return match ($type) {
            AbsenceType::PaidVacation, AbsenceType::UnpaidVacation => AbsenceStatus::HrReview,
            AbsenceType::SickLeave, AbsenceType::DayOff => AbsenceStatus::HrFinalReview,
            AbsenceType::MaternityLeave => $endsOn
                ? AbsenceStatus::HrFinalReview
                : throw new DomainException('Декрет можно отправить на итоговое подтверждение после указания фактической даты окончания'),
        };
    }

    public function nextAfterApproval(AbsenceType $type, AbsenceStatus $current): AbsenceStatus
    {
        if (in_array($type, [AbsenceType::SickLeave, AbsenceType::MaternityLeave, AbsenceType::DayOff], true)) {
            if ($current !== AbsenceStatus::HrFinalReview) {
                throw new DomainException('Недопустимый этап согласования для этого типа отсутствия');
            }
            return AbsenceStatus::Confirmed;
        }

        return match ($current) {
            AbsenceStatus::HrReview => AbsenceStatus::AccountManagerReview,
            AbsenceStatus::AccountManagerReview => AbsenceStatus::ManagerReview,
            AbsenceStatus::ManagerReview => AbsenceStatus::HrFinalReview,
            AbsenceStatus::HrFinalReview => AbsenceStatus::Confirmed,
            default => throw new DomainException('Отсутствие сейчас нельзя согласовать'),
        };
    }

    public function canEmployeeEdit(AbsenceStatus $status): bool
    {
        return $status === AbsenceStatus::Planned;
    }

    public function canReturnToPlanned(AbsenceStatus $status): bool
    {
        return in_array($status, [
            AbsenceStatus::HrReview,
            AbsenceStatus::AccountManagerReview,
            AbsenceStatus::ManagerReview,
            AbsenceStatus::HrFinalReview,
            AbsenceStatus::Rejected,
        ], true);
    }

    public function isImmutable(AbsenceStatus $status): bool
    {
        return $status === AbsenceStatus::Confirmed;
    }
}
