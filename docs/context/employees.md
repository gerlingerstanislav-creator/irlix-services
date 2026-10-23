# Employees — точечный вход

- Frontend: `apps/web/` (**не** `apps/employees/`).
- Backend: `services/employees/`.
- Продукт: `ideas/company-internal-services-81-2.4/services/employees/`.
- UI: маршрут, компонент, тест; не читать весь onboarding.
- Доменная модель: целевые идентификаторы, оргструктура, статусы трудоустройства и инварианты по конкретной операции.
- Identity/Keycloak: только если задача затрагивает provisioning, авторизацию или сопоставление аккаунтов.
- Межсервисный потребитель: начинать с контракта Employees, а не всего его backend.
## Адресные продуктовые маршруты (ideas)

| Сценарий | Начальная точка |
|---|---|
| Ролевая модель Keycloak | `services/employees/KEYCLOAK_ROLE_MODEL.md` |
| Принятие на работу | `services/employees/EMPLOYMENT_APPLICATIONS.md` |
| Полное удаление | `services/employees/HARD_DELETE.md` |
| Отображение имени | `services/employees/NAME_DISPLAY.md` |
| Остальные требования | Конкретные разделы `services/employees/ТЗ.md`, `UI.md` |

Все пути относительно `ideas/company-internal-services-81-2.4/`. Не подключать Keycloak при обычной UI-правке.
