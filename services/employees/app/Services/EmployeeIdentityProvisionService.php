<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\EmployeeOnboardingService;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Add Keycloak access to an already employed, previously imported employee.
 * This operation never creates an Employees record or changes HR history.
 *
 * An unconfirmed Keycloak error deliberately leaves identity_status=provisioning:
 * a remote POST might have succeeded before the connection failed. Retrying it
 * blindly can create a second identity and corrupt the employee->subject link.
 */
final class EmployeeIdentityProvisionService
{
    public function __construct(
        private readonly EmployeeIdentityService $identity,
        private readonly EmployeeOnboardingService $onboarding
    ) {}

    public function provision(int $employeeId): array
    {
        $employee = Employee::query()->business()->whereKey($employeeId)->first();
        if (!$employee) throw new DomainException('Сотрудник не найден.');
        if ($employee->employment_status !== 'Трудоустроен') {
            throw new DomainException('Доступ можно создать только трудоустроенному сотруднику.');
        }
        if ($employee->keycloak_user_id) {
            throw new DomainException('Учётная запись Keycloak уже привязана к сотруднику.');
        }
        if ($employee->identity_status !== 'not_provisioned') {
            throw new DomainException('Состояние Keycloak требует проверки перед созданием нового доступа.');
        }
        if (!$employee->login || !$employee->work_email
            || mb_strtolower((string) $employee->work_email) !== mb_strtolower($employee->login.'@irlix.ru')) {
            throw new DomainException('Для создания Keycloak требуется корректный корпоративный логин.');
        }
        if (!$employee->personal_email) {
            throw new DomainException('Укажите личную почту сотрудника для письма установки пароля.');
        }

        // Claim the operation before the remote POST; never hold a database
        // transaction open across Keycloak or an external mail request.
        $claimed = Employee::query()->business()->whereKey($employeeId)
            ->where('employment_status', 'Трудоустроен')
            ->whereNull('keycloak_user_id')->where('identity_status', 'not_provisioned')
            ->update(['identity_status' => 'provisioning']);
        if ($claimed !== 1) {
            throw new DomainException('Создание доступа уже выполняется или состояние сотрудника изменилось.');
        }

        try {
            $newIdentity = $this->identity->provision($employee);
        } catch (DomainException $error) {
            // An explicit Keycloak 409 guarantees that POST was rejected,
            // unlike an ambiguous timeout or a later group-sync failure.
            Employee::query()->whereKey($employeeId)->whereNull('keycloak_user_id')
                ->where('identity_status', 'provisioning')
                ->update(['identity_status' => 'not_provisioned']);
            throw $error;
        }

        $assigned = Employee::query()->business()->whereKey($employeeId)
            ->whereNull('keycloak_user_id')->where('identity_status', 'provisioning')
            ->update([
                'keycloak_user_id' => $newIdentity['user_id'],
                'identity_status' => 'active',
                'onboarding_email_status' => 'pending_template',
            ]);
        if ($assigned !== 1) {
            throw new RuntimeException('Keycloak was created but Employees identity could not be linked');
        }

        // PostgreSQL's existing employees_auth_sync_employee trigger schedules
        // current realm roles when the new keycloak_user_id is persisted.
        try {
            $this->onboarding->sendPasswordSetup($employeeId);
        } catch (Throwable $error) {
            report($error);
            $this->onboarding->markFailure($employeeId, $error);
        }
        return [
            'status' => 'provisioned',
            'onboarding_email_status' => Employee::query()->whereKey($employeeId)
                ->value('onboarding_email_status'),
        ];
    }
}
