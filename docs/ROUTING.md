# Frontend routing

Каждый самостоятельный экран внутреннего сервиса обязан иметь стабильный URL. Навигация внутри сервиса использует History API: переходы меняют адрес без полной перезагрузки, браузерные Back/Forward восстанавливают экран, прямой заход по URL открывает тот же экран после авторизации.

Корневой URL сервиса сохраняется как совместимый вход и канонизируется на его стартовый экран.

## Брендбук — закрытый доступ для топ-менеджмента

- `/brandbook/` → `/brandbook/identity/` — компания;
- `/brandbook/logo/` — логотип;
- `/brandbook/colors/` — цвета;
- `/brandbook/typography/` — типографика;
- `/brandbook/graphics/` — графика;
- `/brandbook/voice/` — коммуникации;
- `/brandbook/applications/` — применение;
- `/brandbook/assets/` — библиотека;
- `/brandbook/changelog/` — версии.

Страницы и файлы закрыты серверной авторизацией (top-management / platform-admin); анонимным посетителям доступ запрещён. Неутверждённые правила отмечаются явно, а закрытые `ideas`-документы и реальные HR/клиентские данные не включаются в сборку.

## Dashboard

- `/` — каталог сервисов;
- `/tasks/` — персональный список задач и согласований;
- `/resources/` и `/service-map/` — совместимые перенаправления на соответствующие страницы System.

## Employees

- `/employees/employees/` — сотрудники;
- `/employees/departments/` — подразделения;
- `/employees/fot` — ФОТ направлений и плановые пересмотры зарплат (permission-gated);
- `/employees/roles/` — специальные роли;
- `/employees/audit/` — история действий.

## Vacations

- `/vacations/mine/` — мои отпуска;
- `/vacations/department/` — отпуска подразделения;
- `/vacations/management/` — управление отпусками;
- `/vacations/audit/` — история действий.

## Clients

- `/clients/clients/` — клиенты;
- `/clients/leads/` — лиды;
- `/clients/contacts/` — контактные лица;
- `/clients/requests/` — запросы;
- `/clients/positions/` — позиции;
- `/clients/attempts/` — попытки подключения;
- `/clients/members/` — участники проектов;
- `/clients/cashflow/` — ДДС;
- `/clients/reporting-periods/` — отчётные периоды.

## Finance

- `/finance/` → `/finance/overview/` — сервис финансов для пользователей с `finance.view`;
- `/finance/revenue/` — выручка;
- `/finance/pnl/` — P&L (флаги неполноты обязательны);
- `/finance/profitability/` — рентабельность;
- `/finance/invoices/` — счета и оплаты;
- `/finance/expenses/` — расходы и выплаты;
- `/finance/cash-flow/` — ДДС;
- `/finance/planning/` — планирование;
- `/finance/settings/` — настройки.

## Timesheets

- `/timesheets/mine/` — мои таймшиты;
- `/timesheets/management/` — управление;
- `/timesheets/commercial-load/` — коммерческая загрузка;
- `/timesheets/audit/` — история действий.

## CV конвертер

- `/cv-converter/convert/` — рабочее окно конвертера;
- `/cv-converter/` — вход в сервис, канонизируется на `/cv-converter/convert/`;
- старые `/cv` и `/cv/*` перенаправляются на `/cv-converter/`.

## Design System

- `/design-system/components/` — компоненты;
- `/design-system/navigation/` — навигация.

## System — только `platform-admin`

- `/system/` → `/system/resources/` — административный frontend;
- `/system/resources/` — ресурсный монитор;
- `/system/service-map/` — карта сервисов;
- `/system/migration/` — консоль переноса legacy-данных;
- `/migration/` — совместимое перенаправление в System;
- миграционные `inspect`, `dry-run`, `migrate`, `restore`, `validate` остаются в защищённом сервисном API/изолированном runtime, не выполняются логикой Dashboard.

Dashboard остаётся на `/`.

## Правило для новых экранов

При добавлении нового пункта пользовательской навигации одновременно добавляется его route в `packages/ui/src/sectionRouting.js`. Route должен быть человекочитаемым, стабильным и не зависеть от внутреннего имени Vue-компонента. Host/container nginx обязан возвращать SPA entry point при прямом запросе к route.

**Защита маршрутов:** все `/brandbook/*` на host nginx через `auth_request` и текущую Employees DB роль (`top-management` или `platform-admin`). Без cookie → Portal/Keycloak `/?brandbook=1`, без роли → 403, при отказе Employees/DB → 503. Скрытие пункта меню или `noindex` не являются защитой; assets и deep links также закрыты.
