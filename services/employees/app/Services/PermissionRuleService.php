<?php

namespace App\Services;

use App\Models\Department;
use App\Models\EmployeePermissionRule;

final class PermissionRuleService
{
    public function roleCatalog(): array
    {
        $roles = [
            'platform-admin' => 'Администратор платформы',
            'personnel-officer' => 'Специалист по кадрам',
            'top-management' => 'Топ-менеджмент',
            'manager' => 'Руководитель подразделения (совместимость)',
            'system-admin' => 'Системный администратор',
        ];
        foreach (Department::query()->whereNotNull('alias')->get(['alias', 'name']) as $department) {
            $alias = (string) $department->alias;
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) continue;
            $roles[$alias] = $department->name;
            $roles[$alias.'-head'] = 'Руководитель: '.$department->name;
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
