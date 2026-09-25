<?php

namespace App\Support;

final class SpecialRoles
{
    public const CompanyAdmin = 'company-admin';
    public const PersonnelOfficer = 'personnel-officer';

    public static function catalog(): array
    {
        return [
            self::CompanyAdmin => [
                'key' => self::CompanyAdmin,
                'label' => 'Администратор компании',
                'description' => 'Полный административный доступ к Employees и управление специальными ролями.',
            ],
            self::PersonnelOfficer => [
                'key' => self::PersonnelOfficer,
                'label' => 'Кадровик',
                'description' => 'Функциональная кадровая роль для кадровых этапов согласования отпусков. Не зависит от подразделения сотрудника.',
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function exists(string $role): bool
    {
        return array_key_exists($role, self::catalog());
    }
}
