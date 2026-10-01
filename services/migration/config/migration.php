<?php

use App\Migration\Services\EmployeesMigration;
use App\Migration\Services\VacationsMigration;

$pgsql = static function (string $prefix, string $defaultHost, string $defaultDatabase, string $defaultUser, string $defaultPassword, string $searchPath): array {
    return [
        'driver' => 'pgsql',
        'url' => null,
        'host' => env($prefix.'_HOST', $defaultHost),
        'port' => env($prefix.'_PORT', 5432),
        'database' => env($prefix.'_DATABASE', $defaultDatabase),
        'username' => env($prefix.'_USERNAME', $defaultUser),
        'password' => env($prefix.'_PASSWORD', $defaultPassword),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => $searchPath,
        'sslmode' => env($prefix.'_SSLMODE', 'prefer'),
    ];
};

return [
    'legacy_statement_timeout_ms' => (int) env('LEGACY_STATEMENT_TIMEOUT_MS', 15000),

    'legacy' => [
        'employees' => [
            'connection' => 'legacy_employees',
            'readonly_confirmed' => (bool) env('LEGACY_EMPLOYEES_DB_READ_ONLY_CONFIRMED', false),
            'database' => $pgsql('LEGACY_EMPLOYEES_DB', '', '', '', '', 'public'),
        ],
        'vacations' => [
            'connection' => 'legacy_vacations',
            'readonly_confirmed' => (bool) env('LEGACY_VACATIONS_DB_READ_ONLY_CONFIRMED', false),
            'database' => $pgsql('LEGACY_VACATIONS_DB', '', '', '', '', 'public'),
        ],
    ],

    'targets' => [
        'employees' => [
            'connection' => 'target_employees',
            'database' => $pgsql(
                'TARGET_EMPLOYEES_DB',
                env('TARGET_SHARED_DB_HOST', ''),
                env('TARGET_SHARED_DB_DATABASE', ''),
                env('TARGET_EMPLOYEES_DB_USERNAME', ''),
                env('TARGET_EMPLOYEES_DB_PASSWORD', ''),
                'employees',
            ),
        ],
        'vacations' => [
            'connection' => 'target_vacations',
            'database' => $pgsql(
                'TARGET_VACATIONS_DB',
                env('TARGET_SHARED_DB_HOST', ''),
                env('TARGET_SHARED_DB_DATABASE', ''),
                env('TARGET_VACATIONS_DB_USERNAME', ''),
                env('TARGET_VACATIONS_DB_PASSWORD', ''),
                'vacations',
            ),
        ],
    ],

    // A new legacy service is added as an independent module here. The core runner does not
    // contain service-specific if/else branches.
    'modules' => [
        'employees' => EmployeesMigration::class,
        'vacations' => VacationsMigration::class,
    ],

    // Conservative mappings only. Unknown values must become conflicts, never guesses.
    'vacation_types' => [
        'paid_vacation' => 'paid_vacation',
        'оплачиваемый отпуск' => 'paid_vacation',
        'ежегодный оплачиваемый отпуск' => 'paid_vacation',
        'unpaid_vacation' => 'unpaid_vacation',
        'отпуск без сохранения заработной платы' => 'unpaid_vacation',
        'неоплачиваемый отпуск' => 'unpaid_vacation',
        'sick_leave' => 'sick_leave',
        'больничный' => 'sick_leave',
        'maternity_leave' => 'maternity_leave',
        'декрет' => 'maternity_leave',
        'декретный отпуск' => 'maternity_leave',
        'day_off' => 'day_off',
        'отгул' => 'day_off',
    ],

    'vacation_statuses' => [
        'planned' => 'planned',
        'запланирован' => 'planned',
        'confirmed' => 'confirmed',
        'подтвержден' => 'confirmed',
        'подтверждён' => 'confirmed',
        'согласован' => 'confirmed',
        'approved' => 'confirmed',
        'rejected' => 'rejected',
        'отклонен' => 'rejected',
        'отклонён' => 'rejected',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'отменен' => 'cancelled',
        'отменён' => 'cancelled',
    ],
];
