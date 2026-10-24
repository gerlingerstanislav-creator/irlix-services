# Clients — точечный вход

- Реализация: `apps/clients/`, `services/clients/`.
- Продукт: `ideas/company-internal-services-81-2.4/services/clients/`.
- Локальное UI-изменение: конкретный экран/компонент + тест; остальные приложения не читать.
- Подключение клиента/проекта/сотрудника: найти модели, API и правила периодов связей; Timesheets подключить **только** если меняются потребители этих связей или правила времени.
- Запросы, позиции, попытки: читать раздел соответствующего сценария, не полное ТЗ сервиса.
- Авторизация: конкретный permission/scope, общий auth лишь при затронутом контракте.

Не считать этот индекс описанием актуальной структуры БД: она проверяется по текущему коду и миграциям.
## Адресные продуктовые маршруты (ideas)

| Сценарий | Начальная точка |
|---|---|
| Связь клиент → проект → участник → таймшит | `services/clients/CLIENT_MEMBER_TIMESHEETS_ARCHITECTURE.md`, `PROJECT_MEMBER_FORM.md` |
| Карточка участника | `services/clients/PROJECT_MEMBER_CARD.md` |
| Лиды → запросы → попытки | `services/clients/ATTEMPT_FUNNEL.md`, `LEADS_AND_CLIENT_HANDOFF.md` |
| Доступ и навигация | `services/clients/ACCESS.md`, `ACCESS_AND_NAVIGATION.md` |
| Клиентская карточка и реестр | `services/clients/CLIENT_CARD.md`, `CLIENT_REGISTRY.md` |

Файлы выше находятся относительно `ideas/company-internal-services-81-2.4/`. Читай только относящийся к сценарию документ и раздел, не весь список.
