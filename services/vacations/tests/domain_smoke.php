<?php

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Absence\AbsenceDayCalculator;
use App\Domain\Absence\AbsenceStatus;
use App\Domain\Absence\AbsenceType;
use App\Domain\Absence\AbsenceWorkflow;

$workflow = new AbsenceWorkflow();
$days = new AbsenceDayCalculator();

assert($workflow->submitTarget(AbsenceType::PaidVacation, '2026-06-15') === AbsenceStatus::HrReview);
assert($workflow->submitTarget(AbsenceType::SickLeave, '2026-06-15') === AbsenceStatus::HrFinalReview);
assert($workflow->nextAfterApproval(AbsenceType::PaidVacation, AbsenceStatus::HrReview) === AbsenceStatus::AccountManagerReview);
assert($workflow->nextAfterApproval(AbsenceType::PaidVacation, AbsenceStatus::ManagerReview) === AbsenceStatus::HrFinalReview);
assert($workflow->nextAfterApproval(AbsenceType::DayOff, AbsenceStatus::HrFinalReview) === AbsenceStatus::Confirmed);
assert($workflow->canEmployeeEdit(AbsenceStatus::Planned));
assert(!$workflow->canEmployeeEdit(AbsenceStatus::HrReview));
assert($workflow->canReturnToPlanned(AbsenceStatus::ManagerReview));
assert(!$workflow->canReturnToPlanned(AbsenceStatus::Confirmed));

assert($days->calendarDays('2026-06-11', '2026-06-13') === 3);
assert($days->entitlementDays(AbsenceType::PaidVacation, '2026-06-11', '2026-06-13') === 2); // 12 June is excluded
assert($days->entitlementDays(AbsenceType::UnpaidVacation, '2026-06-11', '2026-06-13') === null);

echo "Vacations domain smoke tests passed\n";
