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


// Approval permissions: self-approval is forbidden even with personnel powers.
// Personnel may act for other approvers, but a manager must remain within scope.
$ownAbsence = ['employee_id' => 10];
$otherAbsence = ['employee_id' => 11];
$hrTask = ['required_role' => 'hr', 'approver_employee_id' => 12];
$amTask = ['required_role' => 'account-manager', 'approver_employee_id' => 12];
$managerTask = ['required_role' => 'manager', 'approver_employee_id' => 12];
$personnel = ['roles' => ['personnel-officer']];
assert(!$access->canApproveTask($personnel, 10, $hrTask, $ownAbsence));
assert(!$access->canApproveTask($personnel, 10, $amTask, $ownAbsence));
assert($access->canApproveTask($personnel, 13, $amTask, $otherAbsence));
assert($access->canApproveTask($personnel, 13, $hrTask, $otherAbsence));
assert(!$access->canApproveTask(['roles' => []], 11, $amTask, $otherAbsence));
assert($access->canApproveTask(['roles' => []], 12, $amTask, $otherAbsence));
assert(!$access->canApproveTask(['roles' => ['manager']], 13, $managerTask, $otherAbsence));
assert($access->canApproveTask(['roles' => ['manager']], 13, $managerTask, $otherAbsence, true));
assert($access->canApproveTask(['roles' => ['platform-admin']], 10, $hrTask, $ownAbsence));

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
