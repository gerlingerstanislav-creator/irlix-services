<?php

use App\Migration\Services\EmployeesMigration;
use App\Migration\Services\VacationsV2Migration;
use App\Migration\Services\ClientsMigration;
use App\Migration\Services\TimesheetsMigration;

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
        'sslmode' => env($prefix.'_SSLMODE', 'disable'),
    ];
};

return [
    'legacy_statement_timeout_ms' => (int) env('LEGACY_STATEMENT_TIMEOUT_MS', 15000),
    'employees_url' => rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/'),

    'legacy' => [
        'employees' => [
            'connection' => 'legacy_employees',
            'required_tables' => ['departments', 'employees', 'employments', 'employee_roles', 'users', 'subcontracts', 'comments'],
            'readonly_confirmed' => filter_var(env('LEGACY_EMPLOYEES_DB_READ_ONLY_CONFIRMED', false), FILTER_VALIDATE_BOOL),
            'database' => $pgsql('LEGACY_EMPLOYEES_DB', '', '', '', '', 'public'),
        ],
        'vacations' => [
            'connection' => 'legacy_vacations',
            'required_tables' => ['users', 'vacations', 'approvers', 'changes', 'comments', 'attachments', 'business_dates', 'departments', 'activity_log'],
            'readonly_confirmed' => filter_var(env('LEGACY_VACATIONS_DB_READ_ONLY_CONFIRMED', false), FILTER_VALIDATE_BOOL),
            'database' => $pgsql('LEGACY_VACATIONS_DB', '', '', '', '', 'public'),
        ],
        'clients' => [
            'connection' => 'legacy_clients',
            'required_tables' => ['users', 'clients', 'projects', 'members', 'rates', 'leads', 'contacts', 'client_contact', 'lead_contact', 'legal_entities', 'client_requests', 'positions', 'attempts', 'results', 'attempt_result', 'reporting_periods', 'reporting_period_rate', 'sectors', 'technologies', 'grades', 'grade_rates', 'departments', 'feedback', 'interviews', 'notes', 'reviews', 'subcontracts', 'attachments', 'legal_documents', 'activity_log', 'settings', 'client_technology', 'user_technology'],
            'readonly_confirmed' => filter_var(env('LEGACY_CLIENTS_DB_READ_ONLY_CONFIRMED', false), FILTER_VALIDATE_BOOL),
            'database' => $pgsql('LEGACY_CLIENTS_DB', '', '', '', '', 'public'),
        ],
        'timesheets' => [
            'connection' => 'legacy_timesheets',
            'required_tables' => ['users', 'members', 'rates', 'timesheets', 'month_confirmations', 'time_records', 'vacations', 'business_dates', 'departments'],
            'readonly_confirmed' => filter_var(env('LEGACY_TIMESHEETS_DB_READ_ONLY_CONFIRMED', false), FILTER_VALIDATE_BOOL),
            'database' => $pgsql('LEGACY_TIMESHEETS_DB', '', '', '', '', 'public'),
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
        'clients' => [
            'connection' => 'target_clients',
            'database' => $pgsql('TARGET_CLIENTS_DB', env('TARGET_SHARED_DB_HOST', ''), env('TARGET_SHARED_DB_DATABASE', ''), env('TARGET_CLIENTS_DB_USERNAME', ''), env('TARGET_CLIENTS_DB_PASSWORD', ''), 'clients'),
        ],
        'timesheets' => [
            'connection' => 'target_timesheets',
            'database' => $pgsql('TARGET_TIMESHEETS_DB', env('TARGET_SHARED_DB_HOST', ''), env('TARGET_SHARED_DB_DATABASE', ''), env('TARGET_TIMESHEETS_DB_USERNAME', ''), env('TARGET_TIMESHEETS_DB_PASSWORD', ''), 'timesheets'),
        ],
    ],

    // A new legacy service is added as an independent module here. The core runner does not
    // contain service-specific if/else branches.
    'modules' => [
        'employees' => EmployeesMigration::class,
        'vacations' => VacationsV2Migration::class,
        'clients' => ClientsMigration::class,
        'timesheets' => TimesheetsMigration::class,
    ],

    // Dashboard catalog deliberately includes future modules so the migration plan remains visible
    // before their old DB schemas are connected.
    'dependencies' => ['employees' => [], 'vacations' => ['employees'], 'clients' => ['employees'], 'timesheets' => ['employees', 'clients'], 'specialists' => ['employees'], 'recruitment' => []],
    'table_entities' => [
        'employees' => ['department' => 'departments', 'employee' => 'employees', 'employment' => 'employments', 'employee_role' => 'employee_roles'],
        'vacations' => ['users' => 'users', 'vacations' => 'vacations'],
    ],
    'source_keys' => [
        'client_contact' => ['client_id','contact_id'], 'lead_contact' => ['lead_id','contact_id'],
        'attempt_result' => ['attempt_id','result_id'], 'reporting_period_rate' => ['reporting_period_id','rate_id'],
        'client_technology' => ['client_id','technology_id'], 'user_technology' => ['employee_id','technology_id'],
    ],
    'imported_tables' => [
        'vacations' => ['vacations' => 'absences'],
        'clients' => ['clients' => 'clients', 'projects' => 'projects', 'members' => 'project_members', 'rates' => 'member_terms', 'leads' => 'leads', 'contacts' => 'contact_people', 'legal_entities' => 'client_legal_entities', 'client_requests' => 'client_requests', 'positions' => 'positions', 'attempts' => 'connection_attempts', 'reporting_periods' => 'reporting_periods'],
        'timesheets' => ['timesheets' => 'timesheet_entries'],
    ],
    'catalog' => [
        'employees' => [
            'title' => 'Employees',
            'description' => 'Отделы, сотрудники, трудовые периоды, роли и история статусов/назначений.',
            'status' => 'implemented',
        ],
        'vacations' => [
            'title' => 'Vacations',
            'description' => 'Сотрудники legacy Vacations, отсутствия, статусы и доступная история согласований.',
            'status' => 'implemented',
        ],
        'clients' => [
            'title' => 'Clients',
            'description' => 'Клиенты, проекты, подключения, ставки, отчётные периоды и связанные сущности.',
            'status' => 'implemented',
        ],
        'timesheets' => [
            'title' => 'Timesheets',
            'description' => 'Исторические таймшиты с использованием mappings Employees и Clients.',
            'status' => 'implemented',
        ],
        'specialists' => [
            'title' => 'Specialists',
            'description' => 'Исторические данные направления специалистов — после получения legacy-схемы.',
            'status' => 'planned',
        ],
        'recruitment' => [
            'title' => 'Recruitment',
            'description' => 'Кандидаты и воронка найма — отдельный модуль после анализа источника.',
            'status' => 'planned',
        ],
    ],

    // Conservative mappings only. Unknown values must become conflicts, never guesses.
    'vacation_types' => [
        'paid' => 'paid_vacation',
        'unpaid' => 'unpaid_vacation',
        'maternity' => 'maternity_leave',
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
