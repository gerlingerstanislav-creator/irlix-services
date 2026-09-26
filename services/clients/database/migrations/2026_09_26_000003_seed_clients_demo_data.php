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
                ['name' => 'Альфа Банк', 'type' => 'Прямой', 'sector' => 'Финтех', 'sales_employee_id' => 11, 'account_employee_id' => 21],
                ['name' => 'Северсталь Digital', 'type' => 'Прямой', 'sector' => 'Промышленность', 'sales_employee_id' => 12, 'account_employee_id' => 22],
                ['name' => 'Медиахолдинг Вектор', 'type' => 'Прямой', 'sector' => 'Медиа', 'sales_employee_id' => 11, 'account_employee_id' => 23],
                ['name' => 'Demo Retail Lab', 'type' => 'Партнёрский', 'sector' => 'Retail', 'sales_employee_id' => 13, 'account_employee_id' => 21],
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
            $projects['Альфа Банк']['Мобильный банк'] = $upsertId('projects', ['client_id' => $clients['Альфа Банк'], 'name' => 'Мобильный банк'], ['is_default' => false]);
            $projects['Альфа Банк']['Data Platform'] = $upsertId('projects', ['client_id' => $clients['Альфа Банк'], 'name' => 'Data Platform'], ['is_default' => false]);
            $projects['Северсталь Digital']['MES 2.0'] = $upsertId('projects', ['client_id' => $clients['Северсталь Digital'], 'name' => 'MES 2.0'], ['is_default' => false]);
            $projects['Медиахолдинг Вектор']['Streaming'] = $upsertId('projects', ['client_id' => $clients['Медиахолдинг Вектор'], 'name' => 'Streaming'], ['is_default' => false]);
            $projects['Demo Retail Lab']['Marketplace'] = $upsertId('projects', ['client_id' => $clients['Demo Retail Lab'], 'name' => 'Marketplace'], ['is_default' => false]);

            $leadStatuses = [
                ['Demo Lead — Новый', 'Конференция HighLoad', 11, 'Новый лид', null],
                ['Demo Lead — Контакт', 'Рекомендация', 12, 'Первичный контакт', null],
                ['Demo Lead — Потребность', 'Входящая заявка', 13, 'Уточнение потребностей', null],
                ['Demo Lead — КП', 'Cold outreach', 11, 'КП отправлено', null],
                ['Demo Lead — Переговоры', 'Партнёр', 12, 'Активные переговоры', null],
                ['Demo Lead — Игнор', 'Telegram', 13, 'Клиент в игноре', null],
                ['Demo Lead — Успех', 'Повторное обращение', 11, 'Сделка закрыта — Успех', $clients['Demo Retail Lab']],
                ['Demo Lead — Отказ', 'Сайт', 12, 'Сделка закрыта — Отказ', null],
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
                ['Анна Петрова', 'Head of Procurement', '+7 999 100-10-01', 'anna.petrova@example.test'],
                ['Илья Соколов', 'CTO', '+7 999 100-10-02', 'ilya.sokolov@example.test'],
                ['Марина Волкова', 'Project Manager', '+7 999 100-10-03', 'marina.volkova@example.test'],
                ['Дмитрий Орлов', 'HR BP', '+7 999 100-10-04', 'dmitry.orlov@example.test'],
                ['Ольга Лебедева', 'Product Owner', '+7 999 100-10-05', 'olga.lebedeva@example.test'],
                ['Сергей Миронов', 'Delivery Manager', '+7 999 100-10-06', 'sergey.mironov@example.test'],
            ] as [$fullName, $position, $phone, $email]) {
                $contacts[$fullName] = $upsertId('contact_people', ['email' => $email], [
                    'full_name' => $fullName,
                    'position' => $position,
                    'phone' => $phone,
                ]);
            }

            $relations = [
                ['Анна Петрова', 'client', $clients['Альфа Банк'], 'Закупки', 'Основной контакт по договору'],
                ['Илья Соколов', 'client', $clients['Альфа Банк'], 'Технический контакт', 'Принимает технические решения'],
                ['Марина Волкова', 'client', $clients['Северсталь Digital'], 'Проектный контакт', 'Еженедельные статусы'],
                ['Дмитрий Орлов', 'client', $clients['Северсталь Digital'], 'HR', 'Согласование специалистов'],
                ['Ольга Лебедева', 'client', $clients['Медиахолдинг Вектор'], 'Product Owner', null],
                ['Сергей Миронов', 'client', $clients['Demo Retail Lab'], 'Delivery', 'Контакт после конвертации лида'],
                ['Сергей Миронов', 'lead', $leads['Demo Lead — Успех'], 'Инициатор', 'Контакт был создан ещё на этапе лида'],
                ['Ольга Лебедева', 'lead', $leads['Demo Lead — Переговоры'], 'ЛПР', null],
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
                ['Альфа Банк', 'Java-команда в мобильный банк', 'Нужно усилить backend-команду', 31, '2026-10-15', 'В работе'],
                ['Альфа Банк', 'Data Engineers Q4', 'Расширение data platform', 32, '2026-11-01', 'Новый'],
                ['Северсталь Digital', 'MES: усиление команды', 'Backend + QA для нового релиза', 33, '2026-10-05', 'В работе'],
                ['Медиахолдинг Вектор', 'Streaming launch', 'Команда для запуска новой платформы', 31, '2026-09-30', 'Закрыт: успех'],
                ['Demo Retail Lab', 'Marketplace discovery', 'Пилотный запрос для демонстрации', 32, '2026-10-20', 'Закрыт: неудача'],
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
                ['Java-команда в мобильный банк', 'Java Senior x2', 'Backend', 'Java', 'Senior', 2, 'Два senior backend разработчика', 'Ждёт кандидатов'],
                ['Java-команда в мобильный банк', 'QA Automation', 'QA', 'Java', 'Middle+', 1, 'Автоматизация API/UI', 'Ждёт кандидатов'],
                ['Data Engineers Q4', 'Data Engineer', 'Data', 'Python', 'Middle', 2, 'Airflow + Spark', 'Ждёт кандидатов'],
                ['MES: усиление команды', '.NET Backend', 'Backend', '.NET', 'Senior', 1, 'Интеграции MES', 'Ждёт кандидатов'],
                ['Streaming launch', 'Frontend Vue', 'Frontend', 'Vue', 'Middle+', 1, 'Закрытая успешная позиция', 'Ждёт кандидатов'],
                ['Marketplace discovery', 'Product Analyst', 'Analytics', 'SQL', 'Middle', 1, 'Закрытая неуспешная позиция', 'Ждёт кандидатов'],
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
                ['Java Senior x2', 1001, 'Алексей Смирнов', 31, '2026-09-29', 4200, 'CV отправлено'],
                ['Java Senior x2', 1002, 'Никита Фёдоров', 31, '2026-09-28', 4500, 'Интервью'],
                ['Java Senior x2', 1003, 'Павел Козлов', 31, null, 4300, 'Закрыта: неудача'],
                ['QA Automation', 1004, 'Елена Новикова', 32, '2026-09-30', 3500, 'Ожидает подключения'],
                ['Data Engineer', 1005, 'Роман Морозов', 32, '2026-10-02', 3900, 'Новая'],
                ['.NET Backend', 1006, 'Артём Васильев', 33, null, 4100, 'Закрыта: неудача'],
                ['Frontend Vue', 1007, 'Дарья Попова', 31, null, 3700, 'Закрыта: успех'],
                ['Product Analyst', 1008, 'Михаил Павлов', 32, null, 3300, 'Закрыта: неудача'],
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
            DB::table('positions')->where('id', $positions['Product Analyst'])->update(['status' => 'Закрыта: неудача', 'updated_at' => $now]);

            $members = [];
            foreach ([
                ['Альфа Банк', 'Мобильный банк', 2001, 'Иван Захаров', null],
                ['Альфа Банк', 'Мобильный банк', 2002, 'Ксения Белова', null],
                ['Альфа Банк', 'Data Platform', 2003, 'Максим Егоров', null],
                ['Северсталь Digital', 'MES 2.0', 2004, 'Татьяна Крылова', null],
                ['Медиахолдинг Вектор', 'Streaming', 1007, 'Дарья Попова', $attempts[1007]],
                ['Demo Retail Lab', 'Marketplace', 2005, 'Владимир Комаров', null],
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
                [2001, 'Java', 'Senior', 4300, 8, '2026-08-01', '2026-09-15'],
                [2001, 'Java', 'Senior', 4600, 8, '2026-09-16', null],
                [2002, 'QA Automation', 'Middle+', 3300, 6, '2026-09-01', null],
                [2003, 'Python', 'Senior', 4800, 8, '2026-07-01', '2026-08-31'],
                [2003, 'Python', 'Senior', 5000, 4, '2026-09-10', null],
                [2004, '.NET', 'Senior', 4500, 8, '2026-06-01', '2026-09-20'],
                [1007, 'Vue', 'Middle+', 3700, 8, '2026-09-21', null],
                [2005, 'React', 'Middle', 3200, 4, '2026-10-15', null],
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
                ['Альфа Банк', '2026-09-01', '2026-09-30', 'ТШ на согласовании', 286, 1249800],
                ['Альфа Банк', '2026-08-01', '2026-08-31', 'Счет оплачен', 312, 1310400],
                ['Северсталь Digital', '2026-09-01', '2026-09-30', 'ТШ согласованы', 112, 504000],
                ['Медиахолдинг Вектор', '2026-09-01', '2026-09-30', 'Акт на согласовании', 48, 177600],
                ['Demo Retail Lab', '2026-09-01', '2026-09-30', 'Новый', null, null],
                ['Северсталь Digital', '2026-08-01', '2026-08-31', 'Акт согласован', 168, 756000],
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
            $demoClientNames = ['Альфа Банк', 'Северсталь Digital', 'Медиахолдинг Вектор', 'Demo Retail Lab'];
            $demoLeadNames = [
                'Demo Lead — Новый', 'Demo Lead — Контакт', 'Demo Lead — Потребность', 'Demo Lead — КП',
                'Demo Lead — Переговоры', 'Demo Lead — Игнор', 'Demo Lead — Успех', 'Demo Lead — Отказ',
            ];
            $demoEmails = [
                'anna.petrova@example.test', 'ilya.sokolov@example.test', 'marina.volkova@example.test',
                'dmitry.orlov@example.test', 'olga.lebedeva@example.test', 'sergey.mironov@example.test',
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
