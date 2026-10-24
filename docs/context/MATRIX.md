# Матрица проектных знаний

Поведение выбирается по [WORKFLOW](../agent/WORKFLOW.md) и [BEHAVIORS](../agent/BEHAVIORS.md). Эта матрица отвечает, где искать факты и технические ограничения. Она не назначает роли, обязательные пакеты чтения или полномочия.

Порядок: определить сценарий → найти затронутый сервис/символ → выбрать нужный раздел горизонтали → проверить прямой контракт. Не обходить зависимости рекурсивно. Контекст = общие инварианты + нужные технические правила + затронутый сценарий + доказанные внешние контракты. При конфликте/новом факте пересмотреть выбор. Расход контекста и продолжение — [STATE](../agent/STATE.md).

## Логические горизонтали (не дублировать правила)

| Горизонталь | Источник истины / точка входа | Подключать когда |
|---|---|---|
| frontend | `docs/horizontal/FRONTEND.md`; `packages/ui/README.md` | UI, общие компоненты, router или визуальный контракт |
| backend | `docs/horizontal/BACKEND.md`; `docs/ARCHITECTURE.md` | доменная логика, Laravel/FastAPI, API |
| database | `docs/horizontal/DATABASE.md`, конкретные миграции и модели сервиса | изменение схемы, ORM, сохранение, SQL |
| security | `docs/horizontal/SECURITY.md`, `docs/AUTH.md` | auth, access, sensitive boundary; короткий safety-pass при backend |
| integration | `docs/horizontal/INTEGRATIONS.md` + конкретный endpoint/DTO/event | межсервисное взаимодействие |
| qa | `docs/QA.md`, точные тесты изменяемой функции | подбор тестов/регрессия/независимая проверка |
| governance | `docs/horizontal/GOVERNANCE.md` | неоднозначности, продуктовые требования, документация, критическая оценка |
| delivery | `docs/BRANCHING.md`, `docs/policies/GIT_RELEASE.md`, `docs/CI.md`, `docs/DEPLOYMENT.md` | Git, PR, CI, release, infra |

Канонические горизонтальные регламенты: `docs/horizontal/{FRONTEND,BACKEND,DATABASE,SECURITY,INTEGRATIONS,GOVERNANCE}.md`. Открывать только соответствующие разделы; прежний `docs/policies/ENGINEERING.md` — индекс совместимости со старыми ссылками.

## Вертикали

Текущие точечные указатели: `clients`, `timesheets`, `employees`, `migration` в `docs/context/`. Остальные домены используют прямые пути `apps/<service>/`, `services/<service>/` и сервисные документы в `ideas` с исключениями `Employees=apps/web`, `CV=services/cv-converter`; искать файлы перед чтением.

## Третья координата — сценарий

Сетка горизонталь × вертикаль **не создаёт файл для каждого пересечения**. Сценарий выбирает конкретную секцию внутри ячейки. В `MANIFEST.json` перечислены маршруты и условия загрузки, но не автоматические обязательные чтения. Любой manifest — навигация, не источник продуктовых требований и не точная спецификация БД.
