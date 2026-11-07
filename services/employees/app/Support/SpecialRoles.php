<?php

namespace App\Support;

final class SpecialRoles
{
    public const PlatformAdmin = 'platform-admin';
    public const PlatformTester = 'platform-tester';
    public const TopManagement = 'top-management';
    public const PersonnelOfficer = 'personnel-officer';
    public const SystemAdministrator = 'system-admin';
    public const ProductionDirectionHead = 'production-direction-head';
    public const Managers = 'managers';
    public const DirectionHr = 'direction-hr';

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
                'description' => 'Только доступ к безопасному режиму проверки функциональных ролей. Административных полномочий роль не предоставляет.',
            ],
            self::TopManagement => [
                'key' => self::TopManagement,
                'label' => 'Топ-менеджмент',
                'description' => 'Доступ к закрытому цифровому брендбуку. Роль не даёт других административных прав.',
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
            self::Managers => [
                'key' => self::Managers,
                'label' => 'Руководители',
                'description' => 'Автоматически: руководители любых подразделений, назначенные в оргструктуре. Область доступа ограничена управляемыми подразделениями.',
                'automatic' => true,
            ],
            self::DirectionHr => [
                'key' => self::DirectionHr,
                'label' => 'HR направлений',
                'description' => 'Автоматически: сотрудники, закреплённые HR за подразделениями через hr_id. Область доступа — закреплённые подразделения и их дочерние.',
                'automatic' => true,
            ],
            self::ProductionDirectionHead => [
                'key' => self::ProductionDirectionHead,
                'label' => 'Руководитель производственного направления',
                'description' => 'Определяется автоматически: руководитель хотя бы одного подразделения с признаком «Производство». Доступ ограничен своим деревом подразделений.',
                'automatic' => true,
            ],
        ];
    }

    public static function isAutomatic(string $role): bool
    {
        return in_array($role, [self::Managers, self::DirectionHr, self::ProductionDirectionHead], true);
    }

    public static function isPlatformPrivileged(array $roles): bool
    {
        return in_array(self::PlatformAdmin, $roles, true);
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
