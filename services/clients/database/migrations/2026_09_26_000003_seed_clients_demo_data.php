<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            $upsertId = function (string $table, array $key, array $values = []) use ($now): int {
                DB::table($table)->updateOrInsert($key, array_merge($values, [
                    'updated_at' => $now,
                    'created_at' => $values['created_at'] ?? $now,
                ]));

                return (int) DB::table($table)->where($key)->value('id');
            };

            $clients = [];
            foreach ([
                ['name' => 'Демо Финтех Контур', 'type' => 'Прямой', 'sector' => 'Финтех', 'sales_employee_id' => 11, 'account_employee_id' => 21],
                ['name' => 'Демо Пром Тех', 'type' => 'Прямой', 'sector' => 'Промышленность', 'sales_employee_id' => 12, 'account_employee_id' => 22],
                ['name' => 'Демо Медиа Лаб', 'type' => 'Прямой', 'sector' => 'Медиа', 'sales_employee_id' => 11, 'account_employee_id' => 23],
                ['name' => 'Демо Ритейл Хаб', 'type' => 'Партнёрский', 'sector' => 'Retail', 'sales_employee_id' => 13, 'account_employee_id' => 21],
            ] as $row) {
                $name = $row['name'];
                unset($row['name']);
                $clients[$name] = $upsertId('clients', ['name' => $name], $row);
            }

            $projects = [];
            foreach ($clients as $name => $clientId) {
                $projects[$name]['Основной проект'] = $upsertId('projects', [
                    'client_id' => $clientId,
                    'is_default' => true,
                ], ['name' => null]);
            }
            $projects['Демо Финтех Контур']['Мобильная платформа'] = $upsertId('projects', ['client_id' => $clients['Демо Финтех Контур'], 'name' => 'Мобильная платформа'], ['is_default' => false]);
            $projects['Демо Финтех Контур']['Data Platform Demo'] = $upsertId('projects', ['client_id' => $clients['Демо Финтех Контур'], 'name' => 'Data Platform Demo'], ['is_default' => false]);
            $projects['Демо Пром Тех']['Production Core'] = $upsertId('projects', ['client_id' => $clients['Демо Пром Тех'], 'name' => 'Production Core'], ['is_default' => false]);
            $projects['Демо Медиа Лаб']['Media Stream'] = $upsertId('projects', ['client_id' => $clients['Демо Медиа Лаб'], 'name' => 'Media Stream'], ['is_default' => false]);
            $projects['Демо Ритейл Хаб']['Demo Marketplace'] = $upsertId('projects', ['client_id' => $clients['Демо Ритейл Хаб'], 'name' => 'Demo Marketplace'], ['is_default' => false]);

            $leadStatuses = [
                ['Demo Lead 01 — Новый', 'Demo Source A', 11, 'Новый лид', null],
                ['Demo Lead 02 — Контакт', 'Demo Source B', 12, 'Первичный контакт', null],
                ['Demo Lead 03 — Потребность', 'Demo Source C', 13, 'Уточнение потребностей', null],
                ['Demo Lead 04 — КП', 'Demo Source D', 11, 'КП отправлено', null],
                ['Demo Lead 05 — Переговоры', 'Demo Source E', 12, 'Активные переговоры', null],
                ['Demo Lead 06 — Игнор', 'Demo Source F', 13, 'Клиент в игноре', null],
                ['Demo Lead 07 — Успех', 'Demo Source G', 11, 'Сделка закрыта — Успех', $clients['Демо Ритейл Хаб']],
                ['Demo Lead 08 — Отказ', 'Demo Source H', 12, 'Сделка закрыта — Отказ', null],
            ];
            $leads = [];
            foreach ($leadStatuses as [$name, $source, $responsible, $status, $convertedClientId]) {
                $leads[$name] = $upsertId('leads', ['name' => $name], [
                    'source' => $source,
                    'responsible_employee_id' => $responsible,
                    'status' => $status,
                    'converted_client_id' => $convertedClientId,
                ]);
            }

            $contacts = [];
            foreach ([
                ['Демо Контакт 01', 'Head of Procurement', '+7 900 000-00-01', 'demo.contact01@example.test'],
                ['Демо Контакт 02', 'CTO', '+7 900 000-00-02', 'demo.contact02@example.test'],
                ['Демо Контакт 03', 'Project Manager', '+7 900 000-00-03', 'demo.contact03@example.test'],
                ['Демо Контакт 04', 'HR BP', '+7 900 000-00-04', 'demo.contact04@example.test'],
                ['Демо Контакт 05', 'Product Owner', '+7 900 000-00-05', 'demo.contact05@example.test'],
                ['Демо Контакт 06', 'Delivery Manager', '+7 900 000-00-06', 'demo.contact06@example.test'],
            ] as [$fullName, $position, $phone, $email]) {
                $contacts[$fullName] = $upsertId('contact_people', ['email' => $email], [
                    'full_name' => $fullName,
                    'position' => $position,
                    'phone' => $phone,
                ]);
            }

            $relations = [
                ['Демо Контакт 01', 'client', $clients['Демо Финтех Контур'], 'Закупки', 'Демо-контакт по договору'],
                ['Демо Контакт 02', 'client', $clients['Демо Финтех Контур'], 'Технический контакт', 'Демо техническое согласование'],
                ['Демо Контакт 03', 'client', $clients['Демо Пром Тех'], 'Проектный контакт', 'Демо еженедельные статусы'],
                ['Демо Контакт 04', 'client', $clients['Демо Пром Тех'], 'HR', 'Демо согласование специалистов'],
                ['Демо Контакт 05', 'client', $clients['Демо Медиа Лаб'], 'Product Owner', null],
                ['Демо Контакт 06', 'client', $clients['Демо Ритейл Хаб'], 'Delivery', 'Демо-контакт после конвертации лида'],
                ['Демо Контакт 06', 'lead', $leads['Demo Lead 07 — Успех'], 'Инициатор', 'Демо-контакт создан на этапе лида'],
                ['Демо Контакт 05', 'lead', $leads['Demo Lead 05 — Переговоры'], 'ЛПР', null],
            ];
            foreach ($relations as [$contactName, $entityType, $entityId, $role, $comment]) {
                $upsertId('contact_relations', [
                    'contact_person_id' => $contacts[$contactName],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ], [
                    'relation_role' => $role,
                    'comment' => $comment,
                    'active' => true,
                ]);
            }

            $requests = [];
            foreach ([
                ['Демо Финтех Контур', 'Demo Request — Backend Team', 'Демо-запрос на усиление backend-команды', 31, '2026-10-15', 'В работе'],
                ['Демо Финтех Контур', 'Demo Request — Data Q4', 'Демо-запрос на расширение data platform', 32, '2026-11-01', 'Новый'],
                ['Демо Пром Тех', 'Demo Request — Production Core', 'Демо-запрос Backend + QA', 33, '2026-10-05', 'В работе'],
                ['Демо Медиа Лаб', 'Demo Request — Media Stream', 'Демо-запрос для закрытого успешного сценария', 31, '2026-09-30', 'Закрыт: успех'],
                ['Демо Ритейл Хаб', 'Demo Request — Marketplace', 'Демо-запрос для закрытого неуспешного сценария', 32, '2026-10-20', 'Закрыт: неудача'],
            ] as [$clientName, $title, $description, $responsible, $deadline, $status]) {
                $requests[$title] = $upsertId('client_requests', [
                    'client_id' => $clients[$clientName],
                    'title' => $title,
                ], [
                    'description' => $description,
                    'responsible_employee_id' => $responsible,
                    'deadline' => $deadline,
                    'status' => $status,
                ]);
            }

            $positions = [];
            $positionRows = [
                ['Demo Request — Backend Team', 'Demo Java Senior x2', 'Backend', 'Java', 'Senior', 2, 'Демо-позиция: два senior backend разработчика', 'Ждёт кандидатов'],
                ['Demo Request — Backend Team', 'Demo QA Automation', 'QA', 'Java', 'Middle+', 1, 'Демо-позиция автоматизации API/UI', 'Ждёт кандидатов'],
                ['Demo Request — Data Q4', 'Demo Data Engineer', 'Data', 'Python', 'Middle', 2, 'Демо-позиция Airflow + Spark', 'Ждёт кандидатов'],
                ['Demo Request — Production Core', 'Demo .NET Backend', 'Backend', '.NET', 'Senior', 1, 'Демо-позиция интеграций', 'Ждёт кандидатов'],
                ['Demo Request — Media Stream', 'Demo Frontend Vue', 'Frontend', 'Vue', 'Middle+', 1, 'Демо закрытая успешная позиция', 'Ждёт кандидатов'],
                ['Demo Request — Marketplace', 'Demo Product Analyst', 'Analytics', 'SQL', 'Middle', 1, 'Демо закрытая неуспешная позиция', 'Ждёт кандидатов'],
            ];
            foreach ($positionRows as [$requestTitle, $key, $direction, $technology, $level, $quantity, $description, $status]) {
                $positions[$key] = $upsertId('positions', [
                    'client_request_id' => $requests[$requestTitle],
                    'technology' => $technology,
                    'level' => $level,
                ], [
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'description' => $description,
                    'status' => $status,
                ]);
            }

            $attemptRows = [
                ['Demo Java Senior x2', 91001, 'Демо Специалист 01', 31, '2026-09-29', 4200, 'CV отправлено'],
                ['Demo Java Senior x2', 91002, 'Демо Специалист 02', 31, '2026-09-28', 4500, 'Интервью'],
                ['Demo Java Senior x2', 91003, 'Демо Специалист 03', 31, null, 4300, 'Закрыта: неудача'],
                ['Demo QA Automation', 91004, 'Демо Специалист 04', 32, '2026-09-30', 3500, 'Ожидает подключения'],
                ['Demo Data Engineer', 91005, 'Демо Специалист 05', 32, '2026-10-02', 3900, 'Новая'],
                ['Demo .NET Backend', 91006, 'Демо Специалист 06', 33, null, 4100, 'Закрыта: неудача'],
                ['Demo Frontend Vue', 91007, 'Демо Специалист 07', 31, null, 3700, 'Закрыта: успех'],
                ['Demo Product Analyst', 91008, 'Демо Специалист 08', 32, null, 3300, 'Закрыта: неудача'],
            ];
            $attempts = [];
            foreach ($attemptRows as [$positionKey, $specialistId, $specialistName, $responsible, $controlDate, $rate, $status]) {
                $attempts[$specialistId] = $upsertId('connection_attempts', [
                    'position_id' => $positions[$positionKey],
                    'specialist_id' => $specialistId,
                ], [
                    'specialist_name' => $specialistName,
                    'responsible_employee_id' => $responsible,
                    'control_date' => $controlDate,
                    'proposed_rate' => $rate,
                    'status' => $status,
                ]);
            }
            DB::table('positions')->where('id', $positions['Demo Product Analyst'])->update(['status' => 'Закрыта: неудача', 'updated_at' => $now]);

            $members = [];
            foreach ([
                ['Демо Финтех Контур', 'Мобильная платформа', 92001, 'Демо Участник 01', null],
                ['Демо Финтех Контур', 'Мобильная платформа', 92002, 'Демо Участник 02', null],
                ['Демо Финтех Контур', 'Data Platform Demo', 92003, 'Демо Участник 03', null],
                ['Демо Пром Тех', 'Production Core', 92004, 'Демо Участник 04', null],
                ['Демо Медиа Лаб', 'Media Stream', 91007, 'Демо Специалист 07', $attempts[91007]],
                ['Демо Ритейл Хаб', 'Demo Marketplace', 92005, 'Демо Участник 05', null],
            ] as [$clientName, $projectName, $specialistId, $specialistName, $sourceAttemptId]) {
                $projectId = $projects[$clientName][$projectName];
                $members[$specialistId] = $upsertId('project_members', [
                    'project_id' => $projectId,
                    'specialist_id' => $specialistId,
                ], [
                    'specialist_name' => $specialistName,
                    'source_attempt_id' => $sourceAttemptId,
                ]);
            }

            $termsRows = [
                [92001, 'Java', 'Senior', 4300, 8, '2026-08-01', '2026-09-15'],
                [92001, 'Java', 'Senior', 4600, 8, '2026-09-16', null],
                [92002, 'QA Automation', 'Middle+', 3300, 6, '2026-09-01', null],
                [92003, 'Python', 'Senior', 4800, 8, '2026-07-01', '2026-08-31'],
                [92003, 'Python', 'Senior', 5000, 4, '2026-09-10', null],
                [92004, '.NET', 'Senior', 4500, 8, '2026-06-01', '2026-09-20'],
                [91007, 'Vue', 'Middle+', 3700, 8, '2026-09-21', null],
                [92005, 'React', 'Middle', 3200, 4, '2026-10-15', null],
            ];
            foreach ($termsRows as [$specialistId, $technology, $level, $hourlyRate, $hoursPerDay, $validFrom, $validTo]) {
                $upsertId('member_terms', [
                    'project_member_id' => $members[$specialistId],
                    'valid_from' => $validFrom,
                ], [
                    'technology' => $technology,
                    'level' => $level,
                    'hourly_rate' => $hourlyRate,
                    'hours_per_day' => $hoursPerDay,
                    'valid_to' => $validTo,
                ]);
            }

            $periods = [
                ['Демо Финтех Контур', '2026-09-01', '2026-09-30', 'ТШ на согласовании', 286, 1249800],
                ['Демо Финтех Контур', '2026-08-01', '2026-08-31', 'Счет оплачен', 312, 1310400],
                ['Демо Пром Тех', '2026-09-01', '2026-09-30', 'ТШ согласованы', 112, 504000],
                ['Демо Медиа Лаб', '2026-09-01', '2026-09-30', 'Акт на согласовании', 48, 177600],
                ['Демо Ритейл Хаб', '2026-09-01', '2026-09-30', 'Новый', null, null],
                ['Демо Пром Тех', '2026-08-01', '2026-08-31', 'Акт согласован', 168, 756000],
            ];
            foreach ($periods as [$clientName, $start, $end, $status, $hours, $amount]) {
                $upsertId('reporting_periods', [
                    'client_id' => $clients[$clientName],
                    'period_start' => $start,
                    'period_end' => $end,
                ], [
                    'status' => $status,
                    'confirmed_hours' => $hours,
                    'confirmed_amount' => $amount,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $demoClientNames = ['Демо Финтех Контур', 'Демо Пром Тех', 'Демо Медиа Лаб', 'Демо Ритейл Хаб'];
            $demoLeadNames = [
                'Demo Lead 01 — Новый', 'Demo Lead 02 — Контакт', 'Demo Lead 03 — Потребность', 'Demo Lead 04 — КП',
                'Demo Lead 05 — Переговоры', 'Demo Lead 06 — Игнор', 'Demo Lead 07 — Успех', 'Demo Lead 08 — Отказ',
            ];
            $demoEmails = [
                'demo.contact01@example.test', 'demo.contact02@example.test', 'demo.contact03@example.test',
                'demo.contact04@example.test', 'demo.contact05@example.test', 'demo.contact06@example.test',
            ];

            $clientIds = DB::table('clients')->whereIn('name', $demoClientNames)->pluck('id');
            DB::table('reporting_periods')->whereIn('client_id', $clientIds)->delete();
            DB::table('client_requests')->whereIn('client_id', $clientIds)->delete();
            DB::table('projects')->whereIn('client_id', $clientIds)->delete();
            DB::table('leads')->whereIn('name', $demoLeadNames)->delete();
            DB::table('contact_people')->whereIn('email', $demoEmails)->delete();
            DB::table('clients')->whereIn('id', $clientIds)->delete();
        });
    }
};
