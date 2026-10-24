# Professional Skills for Irlix Services

Семь **переносимых** профессиональных Skills: пять из оригинальных ZIP пользователя и два новых, созданных в репозитории. Каждый каталог самодостаточен: `SKILL.md`, нужные `references/`, `assets/`, `scripts/` и собственные тесты. В корне `skills/` находятся только этот индекс и `MANIFEST.json`.

| Роль | Путь |
|---|---|
| Analyst | [Senior Business & System Analyst](senior-business-system-analyst/SKILL.md) |
| Designer | [Senior Product UI/UX Designer](senior-product-uiux-designer/SKILL.md) |
| Laravel Developer | [Senior PHP / Laravel Backend Engineer](senior-php-laravel-backend-engineer/SKILL.md) |
| Vue Developer | [Senior JavaScript / Vue Frontend Engineer](senior-javascript-vue-frontend-engineer/SKILL.md) |
| QA | [Senior QA Engineer](senior-qa-engineer/SKILL.md) |
| DatabaseEngineer | [Senior Database Engineer](senior-database-engineer/SKILL.md) |
| SecurityEngineer | [Senior Application Security Engineer](senior-application-security-engineer/SKILL.md) |

## Статус и обслуживание

Кастомные Skills отключены: [единый статус](../docs/AGENT_SKILLS.md). При обычной задаче не загружать SKILL.md и не применять пакет. Его наличие в Git или личной установке не активирует методику и не запускает subagents.

Каталоги сохранены для отдельно разрешённого обслуживания/возобновления. При такой задаче проверять комплектность через `python scripts/skills/validate.py`; импорт архивов — `python scripts/skills/import.py`. Чтение пакета как объекта аудита не означает его применение. Решение об активации принимается отдельно; текущий workflow самодостаточен.
