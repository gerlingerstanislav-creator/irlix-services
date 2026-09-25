# Vacations — implementation plan

## Состояние на 2026-09-25

Domain foundation, Employees hierarchy integration и основной approval state machine реализованы. Рабочий UI больше не ограничивается первым экраном: добавлены approvals, department view, HR registry, action history, документы и единая карточка отсутствия.

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
- HR approver и management chain;
- manager subtree scope;
- higher-level manager actions;
- manager absence fallback.

### Approval workflow

- submit;
- HR primary;
- manager stage;
- HR final;
- approve и return-to-planned;
- status/audit history.

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
- owner + HR/admin content ACL;
- document audit events.

### UI

- «Мои отпуска»;
- «Согласования»;
- «Отпуска подразделения» (calendar/list);
- «Управление отпусками»;
- «История действий»;
- absence drawer;
- common `⋮` context menu driven by available actions.

## Следующие обязательные этапы

### 1. Clients integration

Clients должен вернуть активные подключения сотрудника и уникальных account managers. При submit список AM фиксируется snapshot-ом в `absence_approvals`. До этого paid/unpaid workflow намеренно не подменяет этот этап фиктивным согласующим.

### 2. HR create/manage flows

- создание отсутствия за сотрудника;
- прямое HR confirmation там, где это разрешено бизнес-правилом;
- отдельный audited admin override для `confirmed`.

### 3. Events

- transactional outbox;
- `AbsenceCreated`, `AbsenceSubmitted`, `AbsenceApprovalStepCompleted`, `AbsenceReturnedToPlanned`, `AbsenceConfirmed`, `AbsenceCancelled`, `AbsenceStarted`, `AbsenceEnded`;
- idempotent RabbitMQ publishing/consuming.

### 4. Timesheets + 1C

- read contract для рабочего времени;
- кадровый entitlement/остатки из 1С;
- перенос остатков между годами после фиксации кадровых правил.

### 5. Acceptance

Расширить автоматизированные сценарии на permissions/scope, files ACL, UI contracts и полный approval cycle после появления Clients.
