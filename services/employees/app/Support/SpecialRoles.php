<?php

namespace App\Support;

final class SpecialRoles
{
    public const PlatformAdmin = 'platform-admin';
    public const PlatformTester = 'platform-tester';
    public const PersonnelOfficer = 'personnel-officer';
    public const SystemAdministrator = 'system-admin';

    public static function catalog(): array
    {
        return [
            self::PlatformAdmin => [
                'key' => self::PlatformAdmin,
                'label' => 'Администратор платформы',
                'description' => 'Полный административный доступ к сервисам платформы и управление специальными ролями.',
            ],
            self::PlatformTester => [
                'key' => self::PlatformTester,
                'label' => 'Тестировщик платформы',
                'description' => 'Полный административный доступ к сервисам платформы без права назначать или снимать роли администратора платформы и тестировщика платформы.',
            ],
            self::PersonnelOfficer => [
                'key' => self::PersonnelOfficer,
                'label' => 'Специалист по кадрам',
                'description' => 'Функциональная кадровая роль для кадровых этапов согласования отпусков. Не зависит от подразделения сотрудника.',
            ],
            self::SystemAdministrator => [
                'key' => self::SystemAdministrator,
                'label' => 'Системный администратор',
                'description' => 'Функциональная роль для системного администрирования и инфраструктурных процессов. Не зависит от подразделения сотрудника.',
            ],
        ];
    }

    public static function isPlatformPrivileged(array $roles): bool
    {
        return in_array(self::PlatformAdmin, $roles, true) || in_array(self::PlatformTester, $roles, true);
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
