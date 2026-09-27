<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach ([
                'Альфа Банк' => 'Демо Финтех Контур',
                'Северсталь Digital' => 'Демо Пром Тех',
                'Медиахолдинг Вектор' => 'Демо Медиа Лаб',
                'Demo Retail Lab' => 'Демо Ритейл Хаб',
            ] as $old => $new) {
                DB::table('clients')->where('name', $old)->update(['name' => $new, 'updated_at' => $now]);
            }

            foreach ([
                'Мобильный банк' => 'Мобильная платформа',
                'Data Platform' => 'Data Platform Demo',
                'MES 2.0' => 'Production Core',
                'Streaming' => 'Media Stream',
                'Marketplace' => 'Demo Marketplace',
            ] as $old => $new) {
                DB::table('projects')->where('name', $old)->update(['name' => $new, 'updated_at' => $now]);
            }

            foreach ([
                'Demo Lead — Новый' => 'Demo Lead 01 — Новый',
                'Demo Lead — Контакт' => 'Demo Lead 02 — Контакт',
                'Demo Lead — Потребность' => 'Demo Lead 03 — Потребность',
                'Demo Lead — КП' => 'Demo Lead 04 — КП',
                'Demo Lead — Переговоры' => 'Demo Lead 05 — Переговоры',
                'Demo Lead — Игнор' => 'Demo Lead 06 — Игнор',
                'Demo Lead — Успех' => 'Demo Lead 07 — Успех',
                'Demo Lead — Отказ' => 'Demo Lead 08 — Отказ',
            ] as $old => $new) {
                DB::table('leads')->where('name', $old)->update(['name' => $new, 'updated_at' => $now]);
            }
            $leadSources = [
                'Demo Lead 01 — Новый' => 'Demo Source A',
                'Demo Lead 02 — Контакт' => 'Demo Source B',
                'Demo Lead 03 — Потребность' => 'Demo Source C',
                'Demo Lead 04 — КП' => 'Demo Source D',
                'Demo Lead 05 — Переговоры' => 'Demo Source E',
                'Demo Lead 06 — Игнор' => 'Demo Source F',
                'Demo Lead 07 — Успех' => 'Demo Source G',
                'Demo Lead 08 — Отказ' => 'Demo Source H',
            ];
            foreach ($leadSources as $name => $source) {
                DB::table('leads')->where('name', $name)->update(['source' => $source, 'updated_at' => $now]);
            }

            $contactRows = [
                'anna.petrova@example.test' => ['Демо Контакт 01', '+7 900 000-00-01', 'demo.contact01@example.test'],
                'ilya.sokolov@example.test' => ['Демо Контакт 02', '+7 900 000-00-02', 'demo.contact02@example.test'],
                'marina.volkova@example.test' => ['Демо Контакт 03', '+7 900 000-00-03', 'demo.contact03@example.test'],
                'dmitry.orlov@example.test' => ['Демо Контакт 04', '+7 900 000-00-04', 'demo.contact04@example.test'],
                'olga.lebedeva@example.test' => ['Демо Контакт 05', '+7 900 000-00-05', 'demo.contact05@example.test'],
                'sergey.mironov@example.test' => ['Демо Контакт 06', '+7 900 000-00-06', 'demo.contact06@example.test'],
            ];
            foreach ($contactRows as $oldEmail => [$name, $phone, $email]) {
                DB::table('contact_people')->where('email', $oldEmail)->update([
                    'full_name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'updated_at' => $now,
                ]);
            }

            foreach ([
                'Java-команда в мобильный банк' => ['Demo Request — Backend Team', 'Демо-запрос на усиление backend-команды'],
                'Data Engineers Q4' => ['Demo Request — Data Q4', 'Демо-запрос на расширение data platform'],
                'MES: усиление команды' => ['Demo Request — Production Core', 'Демо-запрос Backend + QA'],
                'Streaming launch' => ['Demo Request — Media Stream', 'Демо-запрос для закрытого успешного сценария'],
                'Marketplace discovery' => ['Demo Request — Marketplace', 'Демо-запрос для закрытого неуспешного сценария'],
            ] as $oldTitle => [$newTitle, $description]) {
                DB::table('client_requests')->where('title', $oldTitle)->update([
                    'title' => $newTitle,
                    'description' => $description,
                    'updated_at' => $now,
                ]);
            }

            foreach ([
                'Два senior backend разработчика' => 'Демо-позиция: два senior backend разработчика',
                'Автоматизация API/UI' => 'Демо-позиция автоматизации API/UI',
                'Airflow + Spark' => 'Демо-позиция Airflow + Spark',
                'Интеграции MES' => 'Демо-позиция интеграций',
                'Закрытая успешная позиция' => 'Демо закрытая успешная позиция',
                'Закрытая неуспешная позиция' => 'Демо закрытая неуспешная позиция',
            ] as $old => $new) {
                DB::table('positions')->where('description', $old)->update(['description' => $new, 'updated_at' => $now]);
            }

            foreach ([
                'Алексей Смирнов' => 'Демо Специалист 01',
                'Никита Фёдоров' => 'Демо Специалист 02',
                'Павел Козлов' => 'Демо Специалист 03',
                'Елена Новикова' => 'Демо Специалист 04',
                'Роман Морозов' => 'Демо Специалист 05',
                'Артём Васильев' => 'Демо Специалист 06',
                'Дарья Попова' => 'Демо Специалист 07',
                'Михаил Павлов' => 'Демо Специалист 08',
            ] as $old => $new) {
                DB::table('connection_attempts')->where('specialist_name', $old)->update(['specialist_name' => $new, 'updated_at' => $now]);
            }

            foreach ([
                'Иван Захаров' => 'Демо Участник 01',
                'Ксения Белова' => 'Демо Участник 02',
                'Максим Егоров' => 'Демо Участник 03',
                'Татьяна Крылова' => 'Демо Участник 04',
                'Дарья Попова' => 'Демо Специалист 07',
                'Владимир Комаров' => 'Демо Участник 05',
            ] as $old => $new) {
                DB::table('project_members')->where('specialist_name', $old)->update(['specialist_name' => $new, 'updated_at' => $now]);
            }

            DB::table('contact_relations')
                ->where('comment', 'Основной контакт по договору')
                ->update(['comment' => 'Демо-контакт по договору', 'updated_at' => $now]);
            DB::table('contact_relations')
                ->where('comment', 'Принимает технические решения')
                ->update(['comment' => 'Демо техническое согласование', 'updated_at' => $now]);
            DB::table('contact_relations')
                ->where('comment', 'Еженедельные статусы')
                ->update(['comment' => 'Демо еженедельные статусы', 'updated_at' => $now]);
            DB::table('contact_relations')
                ->where('comment', 'Согласование специалистов')
                ->update(['comment' => 'Демо согласование специалистов', 'updated_at' => $now]);
            DB::table('contact_relations')
                ->where('comment', 'Контакт после конвертации лида')
                ->update(['comment' => 'Демо-контакт после конвертации лида', 'updated_at' => $now]);
            DB::table('contact_relations')
                ->where('comment', 'Контакт был создан ещё на этапе лида')
                ->update(['comment' => 'Демо-контакт создан на этапе лида', 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        // Intentional no-op: this migration removes potentially real-looking demo labels.
    }
};
