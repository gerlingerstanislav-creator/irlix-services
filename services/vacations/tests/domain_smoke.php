<?php

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Absence\AbsenceDayCalculator;
use App\Domain\Absence\AbsenceStatus;
use App\Domain\Absence\AbsenceType;
use App\Domain\Absence\AbsenceWorkflow;
use App\Support\VacationsAccess;
use DomainException;

$workflow = new AbsenceWorkflow();
$days = new AbsenceDayCalculator();
$access = (new ReflectionClass(VacationsAccess::class))->newInstanceWithoutConstructor();

assert($access->isPersonnelOfficer(['roles' => ['personnel-officer']]));
assert($access->isPersonnelOfficer(['roles' => ['PERSONNEL_OFFICER']]));
assert($access->isElevated(['roles' => ['personnel_officer']]));
assert(!$access->isPersonnelOfficer(['roles' => []]));

assert($workflow->submitTarget(AbsenceType::PaidVacation, '2026-06-15') === AbsenceStatus::HrReview);
assert($workflow->submitTarget(AbsenceType::SickLeave, '2026-06-15') === AbsenceStatus::HrFinalReview);
assert($workflow->submitTarget(AbsenceType::MaternityLeave, '2026-06-15') === AbsenceStatus::HrFinalReview);

foreach ([AbsenceType::SickLeave, AbsenceType::MaternityLeave] as $openType) {
    $blocked = false;
    try { $workflow->submitTarget($openType, null); }
    catch (DomainException) { $blocked = true; }
    assert($blocked);
}

assert($workflow->nextAfterApproval(AbsenceType::PaidVacation, AbsenceStatus::HrReview) === AbsenceStatus::AccountManagerReview);
assert($workflow->nextAfterApproval(AbsenceType::PaidVacation, AbsenceStatus::ManagerReview) === AbsenceStatus::HrFinalReview);
assert($workflow->nextAfterApproval(AbsenceType::DayOff, AbsenceStatus::HrFinalReview) === AbsenceStatus::Confirmed);
assert($workflow->canEmployeeEdit(AbsenceStatus::Planned));
assert(!$workflow->canEmployeeEdit(AbsenceStatus::HrReview));
assert($workflow->canReturnToPlanned(AbsenceStatus::ManagerReview));
assert(!$workflow->canReturnToPlanned(AbsenceStatus::Confirmed));

assert($days->calendarDays('2026-06-11', '2026-06-13') === 3);
assert($days->calendarDays('2026-06-11', null) === null);
assert($days->entitlementDays(AbsenceType::PaidVacation, '2026-06-11', '2026-06-13') === 2); // 12 June is excluded
assert($days->entitlementDays(AbsenceType::UnpaidVacation, '2026-06-11', '2026-06-13') === null);

$name = \App\Support\VacationDocumentNames::filename('Synthetic Person','paid_vacation','2026-01-10','2026-01-12','synthetic-key','random.pdf');
assert(str_starts_with($name,'Заявление — Synthetic Person — Оплачиваемый отпуск — 2026-01-10_2026-01-12'));
assert($name !== \App\Support\VacationDocumentNames::filename('Synthetic Person','paid_vacation','2026-01-10','2026-01-12','another-key','random.pdf'));
assert(strlen(\App\Support\VacationDocumentNames::filename(str_repeat('Я',200).'../../','paid_vacation','2026-01-10','2026-01-12','synthetic-key','random.pdf')) <= 255);
assert(!str_contains(\App\Support\VacationDocumentNames::filename('../Synthetic/Person','paid_vacation','2026-01-10','2026-01-12','synthetic-key','random.pdf'),'/'));
assert($workflow->canReturnToPlanned(AbsenceStatus::EmployeeReview));
echo "Vacations domain smoke tests passed\n";
