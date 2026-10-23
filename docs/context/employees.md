# Employees — точечный вход

- Frontend: `apps/web/` (**не** `apps/employees/`).
- Backend: `services/employees/`.
- Продукт: `ideas/company-internal-services-81-2.4/services/employees/`.
- UI: маршрут, компонент, тест; не читать весь onboarding.
- Доменная модель: целевые идентификаторы, оргструктура, статусы трудоустройства и инварианты по конкретной операции.
- Identity/Keycloak: только если задача затрагивает provisioning, авторизацию или сопоставление аккаунтов.
- Межсервисный потребитель: начинать с контракта Employees, а не всего его backend.