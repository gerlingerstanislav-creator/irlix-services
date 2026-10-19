# Finance — реализация в разработке

Общая продуктовая спецификация: `ideas/company-internal-services-81-2.4/services/finance/`, в частности `TECHNICAL_SPEC.md` и `UI_SPEC_9_SCREENS.md`.

**Внимание: feature-ветка, не production-ready.** Сервис содержит схему Finance, проверку Keycloak JWT и служебных разрешений через Employees, ручные черновики счетов, регистр денежных счетов/операций, подтверждение денег, начисления и таблицы. 9 frontend-маршрутов зарегистрированы, но интеграции с Clients/Timesheets, расчёт настоящей выручки, рентабельности, выставление счетов и автоматическое закрытие Clients.ReportingPeriod, полноценные бюджеты и безопасная сверка импортов ещё **не реализованы**.

Счёт `draft` намеренно нельзя перевести в `issued` до проверки ReportingPeriod в Clients. `POST /api/invoices/{invoice}/issue` сейчас возвращает 503. **Не развертывать сервис в production и не назначать доступ к реальным финансовым данным до прохождения интеграционных и security QA.**

## Границы

- Employees — источник истины по сотрудникам и зарплатным условиям, access/me — единственный источник Finance entitlement.
- Только бухгалтерия, явно назначенный `finance-director` и `platform-admin` имеют доступ. РН других направлений не получают.
- Finance не выполняет закрытие бухгалтерского месяца. Оно происходит в 1С (позже импорт).
- Equipment по-прежнему единственный каталог оборудования; внутренние проекты и корпоративная аллокация отложены.
- Ни один показатель без коммерческих часов из Clients/Timesheets не маркируется достоверной выручкой.

## Запуск (локально, после подготовки базы и ключа)

```bash
# Сгенерировать сильный случайный APP_KEY локально и задать FINANCE_APP_KEY.
# Не сохранять ключи в Git.
export FINANCE_APP_KEY="base64:$(openssl rand -base64 32)"
docker compose -f docker-compose.yml -f docker-compose.finance.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.finance.yml exec finance php artisan migrate --force
```

**QA:** до merge нужны PHP lint Laravel bootstrap, Vue/Vite build, Docker compose config, API authorization tests, полноценные финансовые контрактные тесты с Clients, проверка на duplicate платежи, races, 1300px визуальные тесты и проверка permissions. Нельзя выдавать прохождение базового CI за полную финансовую приёмку.
