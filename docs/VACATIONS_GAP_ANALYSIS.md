# Vacations — gap analysis

Актуально на 2026-09-25.

## Закрытые gaps

- доменная state machine Absence;
- отдельные type-specific validations;
- status history и audit log;
- Employees hierarchy/HR integration;
- manager scope на дочерние подразделения;
- manager fallback при отсутствии;
- submit/approve/return workflow;
- paid-vacation holiday calculation;
- attachments metadata + persistent file storage + ACL;
- frontend actions для сотрудника (edit, documents, submit);
- рабочий approvals inbox;
- department vacations calendar/list;
- HR/admin absence registry;
- отдельная страница action history;
- единый absence drawer и `⋮` context menu;
- server-driven available actions для scoped views.

## Оставшиеся gaps

1. **Clients / account managers.** Нужен production API-контракт для active project assignments и snapshot всех уникальных AM при submit. Пока этот этап остаётся незавершённой интеграционной границей.
2. **HR create-for-employee.** По продуктовому ТЗ кадровик должен уметь создать отсутствие за сотрудника и при допустимом сценарии сразу подтвердить его.
3. **Confirmed admin override.** Нужен отдельный audited flow; обычное редактирование confirmed по-прежнему запрещено.
4. **RabbitMQ/outbox.** Vacations ещё не публикует доменные события через общий outbox pattern.
5. **Timesheets contract.** Требуется стабильный read/event contract влияния отсутствий на рабочую норму.
6. **1С.** Кадровые остатки и юридически значимые данные пока не синхронизируются.
7. **Notifications.** Email/Telegram/in-app notification center отложены.
8. **Acceptance coverage.** Domain smoke tests есть, но нужны интеграционные тесты API permissions/scope, attachments и полный workflow после Clients.

## Принцип текущей реализации

Frontend не должен повторять state machine и permission logic. Для реестра, согласований и карточки Vacations backend возвращает `available_actions`; UI отображает эти действия через общий контекстный menu component.
