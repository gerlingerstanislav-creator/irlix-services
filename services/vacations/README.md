# Vacations / Absences service

Vacations является source of truth для отсутствий сотрудников. Сервис не владеет сотрудниками и оргструктурой: employee identity, HR assignment, management hierarchy и permission/scope разрешаются через Employees.

## Реализовано

Backend:

- Keycloak bearer authentication;
- изолированная PostgreSQL schema `vacations`;
- `Absence` для `paid_vacation`, `unpaid_vacation`, `sick_leave`, `maternity_leave`, `day_off`;
- type-specific validation и расчёт дней ежегодного оплачиваемого отпуска с исключением нерабочих праздничных дней РФ;
- запрет пересекающихся активных отсутствий;
- workflow `planned -> hr_review -> account_manager_review -> manager_review -> hr_final_review -> confirmed` для оплачиваемого/неоплачиваемого отпуска;
- сокращённые HR workflows для больничного, декрета и отгула;
- approval tasks, status history и audit log;
- HR/manager permissions через Employees и manager scope на всё поддерево подразделения;
- руководитель может создать отсутствие сотруднику только внутри своего Employees scope;
- manager fallback при подтверждённом пересекающемся отсутствии руководителя;
- возврат незавершённой заявки в `planned` с аннулированием текущего approval cycle;
- неизменяемость `confirmed` для обычных ролей;
- безопасный attachment layer: metadata в PostgreSQL, бинарные файлы в persistent storage volume;
- содержимое документов доступно владельцу отсутствия и HR/admin; руководитель видит только факт наличия документов;
- scoped registry по организационной зоне;
- scoped action history на базе `absence_audit_log`;
- server-side `available_actions` для реестра, очереди согласований и карточки отсутствия.

Frontend:

1. **Мои отпуска** — создание, просмотр и управление собственными отсутствиями;
2. **Согласования** — inbox активных approval tasks;
3. **Отпуска подразделения** — календарь/список в manager/HR scope; для руководителя здесь доступно создание отсутствия за сотрудника;
4. **Управление отпусками** — HR/admin registry с фильтрами;
5. **История действий** вынесена в нижний системный блок sidebar по паттерну Employees и использует ту же иконку;
6. единая карточка отсутствия с документами, approval timeline и history;
7. единое контекстное меню `⋮` у каждой записи: frontend показывает только действия, разрешённые backend contract;
8. рабочие панели растягиваются до низа viewport и прокручивают таблицу/календарь внутри; контекстное меню телепортируется поверх scroll-контейнеров и не обрезается родителем;
9. отправка `planned` на согласование выполняется без дополнительного browser-confirm.

## Документы

Вложения хранятся в `storage/app/vacations/attachments`, который на стенде подключён к named volume `vacations_files`. Допустимые форматы текущего этапа: PDF, PNG/JPEG, DOC/DOCX, до 10 MB на файл.

Документы можно изменять до терминального состояния. В `confirmed`, `rejected`, `cancelled` комплект документов стандартным flow не изменяется.

Загрузка и удаление документа фиксируются в action history.

## Employees integration

Vacations использует Employees API для:

- текущего employee profile;
- ролей, permissions и scope;
- HR approver;
- manager chain;
- проверки доступа руководителя к сотруднику дочернего подразделения;
- directory данных для scoped registry/calendar/history.

Прямого чтения таблиц Employees из runtime Vacations нет.

Для одноразового стендового demo seed координационный script получает актуальный список сотрудников внутри контейнера Employees и передаёт в Vacations только JSON с `id`, `department_id`, `full_name`. Это не является runtime contract сервиса.

## Текущий workflow

Оплачиваемый и неоплачиваемый отпуск:

`planned -> HR primary -> account managers -> manager -> HR final -> confirmed`.

Первичный и финальный HR, а также manager snapshot разрешаются через Employees. Этап account managers пока является интеграционной границей: production flow не считается полностью завершённым, пока Clients не предоставляет активные подключения сотрудника и уникальных account managers. Никакой локальный фиктивный approver не подставляется.

Больничный и отгул идут из `planned` сразу на final HR. Декрет может быть создан с открытой датой окончания и отправляется на final HR после фиксации фактического окончания.

## API, добавленное для рабочего UI

- `GET /api/vacations/workspace` — текущий employee/access + scoped directory metadata;
- `GET /api/vacations/registry` — scoped absence registry;
- `GET /api/vacations/history` — scoped action history;
- `GET /api/vacations/absences/{id}/workspace` — единая карточка + approvals/history/actions;
- `POST /api/vacations/absences/for-employee` — создание `planned` отсутствия руководителем за сотрудника в своём scope;
- `GET|POST /api/vacations/absences/{id}/attachments`;
- `GET /api/vacations/absences/{id}/attachments/{attachment}/download`;
- `DELETE /api/vacations/absences/{id}/attachments/{attachment}`.

Existing create/edit/submit/approve/return endpoints продолжают использоваться UI.

## Demo seed стенда

`/opt/irlix-services/scripts/seed-vacations-demo.sh` создаёт идемпотентный набор тестовых отсутствий за сентябрь 2026 из актуальных сотрудников Employees. Seeder распределяет записи по доступным подразделениям, пропускает сотрудников с уже существующим пересечением и создаёт разные типы/статусы, history/audit и pending approval для workflow-статусов.

Маркер `vacations.demo_seed_runs` не позволяет применить этот seed повторно.

## Не завершено

- реальный Clients contract и snapshot всех уникальных account managers;
- HR create-for-employee/direct-confirm flow;
- admin override для изменения `confirmed`;
- перенос отпускных остатков между годами и интеграция кадровых остатков с 1С;
- RabbitMQ/outbox events Vacations;
- notifications;
- окончательный Timesheets read contract;
- полноценный acceptance suite для всех ролевых комбинаций.
