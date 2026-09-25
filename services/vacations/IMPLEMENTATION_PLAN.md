# Vacations — implementation plan

## Состояние на 2026-09-25

Domain foundation, Employees hierarchy integration, special role integration и основной approval state machine реализованы. Рабочий UI состоит из «Моих отпусков», «Отпусков подразделения», единого «Управления отпусками» и системной «Истории действий».

Отдельная страница «Согласования» удалена: approval tasks, текущие actions и progress отображаются непосредственно в реестре «Управление отпусками».

## Реализованные блоки

### Domain foundation

- общая сущность Absence и type-specific policies;
- расширенная schema absences;
- approvals/status history/audit;
- расчёт оплачиваемых отпускных дней с исключением праздников РФ;
- overlap validation;
- immutable confirmed.

### Employees + permissions

- current employee;
- manager chain и manager subtree scope;
- higher-level manager actions;
- manager absence fallback;
- отдельный Vacations directory contract;
- `personnel-officer` как Employees-owned специальная роль кадровика;
- directional HR (`departments.hr_id`) и HR department остаются отдельной организационной моделью и не используются как замена кадровику.

### Approval workflow

- submit;
- кадровая primary review;
- manager stage;
- кадровая final review;
- approve и return-to-planned;
- status/audit history;
- любой текущий `personnel-officer` может выполнить активный кадровый этап;
- снятая роль сразу перестаёт давать право согласования.

В исторической DB-схеме названия `hr_review`, `hr_final_review` и `required_role=hr` пока сохранены для совместимости. В authorization/business semantics это кадровые этапы `personnel-officer`.

Account-manager stage присутствует в state machine, но ждёт реального Clients contract.

### Type-specific flows

- retroactive sick leave;
- open-ended maternity;
- day off;
- paid/unpaid vacation.

### Documents

- `absence_attachments` metadata;
- persistent file storage;
- upload/list/download/delete;
- owner + personnel-officer/admin content ACL;
- manager/HR видят факт наличия документа без права читать содержимое;
- document audit events;
- в «Моих отпусках» количество файлов/ссылка отображаются в таблице.

### UI

- «Мои отпуска» с временным расчётным остатком 28 дней;
- «Отпуска подразделения» (calendar/list);
- «Управление отпусками» как registry + approvals workspace;
- месячные quick filters и расчёт absence load по норме 8ч × Пн–Пт;
- фильтр «Требуют моего действия»;
- визуальный approval progress в строках;
- «История действий» в нижнем системном блоке sidebar;
- sidebar по паттерну Employees с service launcher и hover labels;
- absence drawer;
- common `⋮` context menu driven by available actions;
- panels with internal scroll and context-menu teleport.

## Следующие обязательные этапы

### 1. Clients integration

Clients должен вернуть активные подключения сотрудника и уникальных account managers. При submit список AM фиксируется snapshot-ом в `absence_approvals`. До этого paid/unpaid workflow намеренно не подменяет этот этап фиктивным согласующим.

### 2. Кадровый/admin manage flow

Уже реализовано создание `planned` отсутствия за сотрудника кадровиком и руководителем в scope.

Остаётся:

- direct confirmation при создании кадровиком/admin там, где это разрешено бизнес-правилом;
- отдельный audited admin override для `confirmed`.

### 3. Events

- transactional outbox;
- `AbsenceCreated`, `AbsenceSubmitted`, `AbsenceApprovalStepCompleted`, `AbsenceReturnedToPlanned`, `AbsenceConfirmed`, `AbsenceCancelled`, `AbsenceStarted`, `AbsenceEnded`;
- idempotent RabbitMQ publishing/consuming.

### 4. Timesheets + 1C

- read contract для рабочего времени;
- юридически значимый кадровый entitlement/остатки из 1С;
- перенос остатков между годами после фиксации кадровых правил.

### 5. Acceptance

Расширить автоматизированные сценарии на:

- personnel-officer vs HR separation;
- несколько кадровиков и снятие роли во время активного workflow;
- manager scope;
- files ACL;
- unified management actions;
- monthly statistics;
- полный approval cycle после появления Clients.
