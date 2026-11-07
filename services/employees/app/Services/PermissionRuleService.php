<?php

namespace App\Services;

use App\Models\Department;
use App\Models\EmployeePermissionRule;
use App\Support\SpecialRoles;

final class PermissionRuleService
{
    public function roleCatalog(): array
    {
        // Employees' matrix presents only effective entry audiences.
        // Existing -head rules are preserved in storage and still evaluated,
        // but managers must now be configured through the aggregate role.
        $roles = [
            SpecialRoles::PlatformAdmin => 'Администратор платформы',
            SpecialRoles::PersonnelOfficer => 'Специалист по кадрам',
            SpecialRoles::Managers => 'Руководители',
        ];
        $orgRoles = [];
        foreach (Department::query()->whereNotNull('alias')->get(['alias', 'name']) as $department) {
            $alias = (string) $department->alias;
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) continue;
            $orgRoles[$alias] = $department->name;
            if (in_array($alias, ['hr', 'accounting'], true)) $roles[$alias] = $department->name;
        }

        // An otherwise unrelated role appears only when explicitly granted
        // BOTH reading Employees and entry into the Employees service.
        $explicit = [];
        foreach (EmployeePermissionRule::query()
            ->whereIn('permission', ['employees.read', 'services.employees'])
            ->get(['role', 'permission', 'enabled']) as $rule) {
            $explicit[$rule->role][$rule->permission] = (bool) $rule->enabled;
        }
        $extraRoles = [
            SpecialRoles::TopManagement => 'Топ-менеджмент',
            SpecialRoles::SystemAdministrator => 'Системный администратор',
            SpecialRoles::ProductionDirectionHead => 'Руководитель производственного направления',
            'manager' => 'Руководитель подразделения (совместимость)',
        ];
        foreach (array_merge($orgRoles, $extraRoles) as $role => $label) {
            if (str_ends_with($role, '-head')) continue;
            if (($explicit[$role]['employees.read'] ?? false)
                && ($explicit[$role]['services.employees'] ?? false)) {
                $roles[$role] = $label;
            }
        }
        return $roles;
    }

    public function permissionCatalog(): array
    {
        return [
            'employees.read' => 'Просмотр сотрудников',
            'employees.application.create' => 'Подача заявок на трудоустройство',
            'employees.hire' => 'Трудоустройство',
            'employees.manage' => 'Редактирование карточек',
            'employees.salary.read' => 'Просмотр окладов',
            'employees.salary.manage' => 'Изменение окладов',
            'employees.fot.view' => 'Просмотр ФОТ направления',
            'employees.fot.plan' => 'Планирование пересмотров зарплат',
            'employees.fot.approve' => 'Согласование пересмотров зарплат',
            'employees.transfer' => 'Кадровый перевод между направлениями',
            'organization.read' => 'Просмотр оргструктуры',
            'organization.manage' => 'Изменение оргструктуры',
            'staff_positions.read' => 'Просмотр должностей',
            'staff_positions.manage' => 'Управление должностями',
            'access.manage' => 'Управление специальными ролями',
            'audit.read' => 'Просмотр аудита',
            'services.clients' => 'Вход: Клиенты',
            'services.finance' => 'Вход: Финансы',
            'services.equipment' => 'Вход: Техника',
            'services.recruitment' => 'Вход: Подбор персонала',
            'services.cv-converter' => 'Вход: CV конвертер',
            'services.specialists' => 'Вход: Специалисты',
            'services.timesheets' => 'Вход: Табель',
            'services.vacations' => 'Вход: Отпуска',
            'services.employees' => 'Вход: Сотрудники',
        ];
    }

    public function rules()
    {
        return EmployeePermissionRule::query()->get(['role', 'permission', 'enabled']);
    }

    public function setRule(string $role, string $permission, bool $enabled): void
    {
        EmployeePermissionRule::query()->upsert(
            [['role' => $role, 'permission' => $permission, 'enabled' => $enabled,
              'created_at' => now(), 'updated_at' => now()]],
            ['role', 'permission'],
            ['enabled', 'updated_at']
        );
    }
}
