# Vacations — gap analysis и план реализации

Дата анализа: 2026-09-25.

Источник требований: `ideas/company-internal-services-81-2.4/services/vacations/`.

## Текущее состояние

Сейчас реализован только первый вертикальный срез:

- Keycloak bearer-auth;
- получение текущего сотрудника через Employees `/api/self`;
- отдельная PostgreSQL schema `vacations`;
- единая таблица `absences`;
- типы: оплачиваемый отпуск, неоплачиваемый отпуск, больничный, декрет, отгул;
- просмотр собственных отсутствий по году;
- создание собственного отсутствия в статусе `planned`;
- запрет пересекающихся активных отсутствий;
- базовый frontend раздела «Мои отпуска»;
- заглушки разделов «Согласования» и «Управление отсутствиями».

## Основные gaps

### 1. Доменная модель и state machine

Текущей таблицы `absences` недостаточно. Не хватает:

- истории переходов статусов;
- этапов согласования;
- списка согласующих;
- поддержки нескольких account managers;
- финального подтверждения кадровиком;
- причины/автора отката в `planned`;
- открытой даты окончания декрета;
- отдельной фиксации фактической даты окончания декрета;
- признаков юридической/кадровой проверки;
- модели файлов/вложений;
- аудита изменений;
- подготовленной событийной модели RabbitMQ.

Целевой жизненный цикл оплачиваемого и неоплачиваемого отпуска:

`planned -> hr_review -> account_manager_review -> manager_review -> hr_final_review -> confirmed`

Правила:

- сотрудник создаёт/редактирует сущность только в `planned`;
- после первого согласования сотрудник сам изменения не вносит;
- кадровик или руководитель в своей зоне ответственности может вернуть сущность в `planned` до `confirmed`;
- возврат сбрасывает незавершённую цепочку и требует повторной отправки;
- после `confirmed` обычные роли изменения не выполняют;
- HR может создавать отсутствие за сотрудника сразу в подтверждённом состоянии;
- будущий административный override после `confirmed` должен быть отдельным сценарием с обязательным аудитом.

Для account-manager этапа требуется согласование всеми уникальными AM всех клиентских проектов сотрудника.

Если непосредственный руководитель отсутствует, согласующим становится ближайший доступный вышестоящий руководитель по Employees hierarchy.

### 2. Разные workflow по типам

Нужны отдельные правила по типу:

- `paid_vacation`: полная цепочка HR -> AM(s) -> manager -> HR final;
- `unpaid_vacation`: та же цепочка;
- `sick_leave`: создаётся задним числом, подтверждается кадровиком;
- `maternity_leave`: создаётся заранее с открытой датой окончания, закрывается по факту и подтверждается кадровиком;
- `day_off`: создаётся сотрудником и подтверждается кадровиком.

Сейчас все типы ведут себя одинаково — это нужно устранить.

### 3. Расчёт дней

Сейчас `calendar_days` — просто inclusive interval.

Для ежегодного оплачиваемого отпуска нужен отдельный расчёт по правилам ТК РФ:

- обычные выходные входят;
- нерабочие праздничные дни не уменьшают отпускной остаток;
- нехватка расчётного остатка не блокирует создание/согласование;
- перенос остатка пока не реализуется;
- временно может использоваться локальный расчёт/ориентир, но будущий источник истины по кадровым остаткам — 1С.

Для остальных типов правила длительности должны быть отдельными.

### 4. Остатки оплачиваемого отпуска

Нужен read-model годового остатка/использования:

- годовой ориентир по умолчанию;
- использовано/запланировано;
- отсутствие жёсткого блокирования при превышении;
- архитектура должна позволять заменить локальный расчёт данными 1С без изменения API frontend.

### 5. Employees integration

Сейчас используется только `/self`.

Нужны контракты для:

- подразделения сотрудника;
- непосредственного руководителя;
- дерева подразделений вниз;
- цепочки руководителей вверх;
- проверки отсутствия непосредственного руководителя;
- scope руководителя на дочерние подразделения;
- HR/кадровых ролей.

Vacations не должен копировать оргструктуру как источник истины.

### 6. Clients integration

Не реализована.

Для оплачиваемого/неоплачиваемого отпуска Vacations должен получить:

- активные подключения сотрудника к проектам;
- account managers этих проектов;
- дедуплицированный список согласующих.

До появления полноценного Clients API нужен явный integration contract/stub, а не скрытая зависимость от будущей БД Clients.

### 7. Permissions / scope

Нужна матрица `permission + scope`:

- сотрудник — свои отсутствия;
- кадровик — кадровые проверки, финальное подтверждение, откат до `planned`, HR-management;
- account manager — только назначенные ему approval tasks;
- руководитель — своё дерево подразделений целиком и те же действия, что руководитель любого дочернего подразделения;
- администратор — отдельный расширенный scope;
- обычный сотрудник не получает доступ к кадровым документам коллег.

### 8. Attachments

Не реализованы.

Требуется:

- загрузка файлов до итогового `confirmed` во всех workflow;
- хранение metadata отдельно от бинарного содержимого;
- доступ к содержимому: сотрудник-владелец и кадровики;
- руководители и account managers видят факт наличия файла, но не содержимое;
- файлы должны быть недоступны по прямому публичному URL;
- удаление/замена до `confirmed` с аудитом.

### 9. Approval queue

Сейчас UI-заглушка.

Нужно:

- очередь задач текущего пользователя;
- сведения о сотруднике, типе, периоде, подразделении;
- текущий этап согласования;
- история предыдущих согласований;
- approve/reject/rollback;
- корректное поведение при нескольких AM;
- защита от повторного/устаревшего согласования.

### 10. HR / management screen

Сейчас UI-заглушка.

Нужно:

- поиск по сотруднику;
- фильтр статуса;
- фильтр подразделения;
- диапазон дат;
- создание отсутствия за сотрудника;
- возможность HR сразу создать `confirmed`;
- просмотр approval history;
- помесячная статистика;
- отсутствие silent-edit для `confirmed` без отдельного admin flow.

### 11. Team calendar

Не реализован.

Правила видимости:

- обычный сотрудник: видит факт отсутствия коллег без причины и без файлов;
- руководитель: видит тип отсутствия в своей иерархической области;
- HR: видит полный контекст;
- вложения никогда не появляются в календаре.

Нужны фильтры по периоду, подразделению и сотрудникам.

### 12. Frontend

Текущий `App.vue` — прототип первого vertical slice.

Нужно разнести приложение по компонентам/страницам как минимум на:

- MyAbsences;
- AbsenceForm / AbsenceDetails;
- ApprovalQueue;
- Management;
- TeamCalendar;
- AttachmentList;
- ApprovalHistory.

Статусы и действия должны приходить из backend capability/read model, а не быть зашиты в UI.

### 13. Backend structure

Сейчас почти вся бизнес-логика находится в `routes/api.php`.

Перед расширением необходимо вынести её минимум в:

- controllers;
- application/domain services;
- repositories/models;
- policies/authorization;
- integrations/Employees;
- integrations/Clients;
- event publisher;
- file storage service.

Routes должны остаться транспортным слоем.

### 14. RabbitMQ

Пока отсутствует.

После фиксации state machine нужны события минимум:

- `AbsenceCreated`;
- `AbsenceSubmitted`;
- `AbsenceApprovalAdvanced`;
- `AbsenceReturnedToPlanned`;
- `AbsenceConfirmed`;
- `AbsenceRejected`;
- `AbsenceCancelled`;
- `AbsenceStarted`;
- `AbsenceEnded`.

Публикация должна быть надёжной; предпочтительно outbox pattern, а не publish напрямую внутри HTTP transaction.

### 15. Tests / acceptance

Сейчас полноценного доменного набора тестов нет.

Нужны:

- unit tests state machine;
- integration tests API;
- authorization/scope tests;
- multi-AM approval tests;
- manager fallback tests;
- HR rollback tests;
- confirmed immutability tests;
- file-access tests;
- holiday calculation tests;
- smoke checks на stand.

## Порядок реализации

### Block 1 — Domain foundation

- нормализовать backend structure;
- расширить schema;
- реализовать type-specific state machine;
- approval history/tasks;
- audit trail;
- расчёт дней/праздников;
- базовые unit/integration tests.

Результат: backend умеет корректно вести жизненный цикл absence без UI-заглушек.

### Block 2 — Employees scopes and approvals

- contracts Employees hierarchy;
- HR role/scope;
- manager subtree;
- manager fallback;
- approval queue backend.

Результат: HR и руководители получают корректные задачи и область ответственности.

### Block 3 — Clients / account managers

- integration contract Clients;
- получение активных проектов и AM;
- multi-AM approval barrier;
- fallback mode до полноценного Clients service при необходимости.

Результат: vacation workflow соответствует коммерческим подключениям сотрудника.

### Block 4 — Attachments

- private storage;
- metadata;
- upload/download/delete API;
- permission model;
- audit.

### Block 5 — User UI

- My Absences;
- create/edit/submit;
- details/history;
- status timeline;
- file upload;
- paid-days read model.

### Block 6 — Approval + HR UI

- Approval Queue;
- HR management;
- filters;
- create-for-employee;
- rollback;
- final confirmation.

### Block 7 — Team calendar

- calendar API;
- scope-sensitive DTO;
- frontend calendar/filtering.

### Block 8 — RabbitMQ + hardening

- outbox;
- lifecycle events;
- resilience;
- observability;
- expanded smoke/acceptance tests.

## Следующий непосредственный шаг

Начать Block 1 с миграций и backend refactor. До реализации Clients service границу integration оставить контрактной, чтобы Vacations не зависел от таблиц другого сервиса.
