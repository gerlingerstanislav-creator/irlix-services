# Professional Skills for Irlix Services

Пять **переносимых** профессиональных Skills из оригинальных ZIP пользователя. Каждый каталог самодостаточен: `SKILL.md`, нужные `references/`, `assets/`, `scripts/` и собственные тесты. В корне `skills/` находятся только этот индекс и `MANIFEST.json`.

| Роль | Путь |
|---|---|
| Analyst | [Senior Business & System Analyst](senior-business-system-analyst/SKILL.md) |
| Designer | [Senior Product UI/UX Designer](senior-product-uiux-designer/SKILL.md) |
| Laravel Developer | [Senior PHP / Laravel Backend Engineer](senior-php-laravel-backend-engineer/SKILL.md) |
| Vue Developer | [Senior JavaScript / Vue Frontend Engineer](senior-javascript-vue-frontend-engineer/SKILL.md) |
| QA | [Senior QA Engineer](senior-qa-engineer/SKILL.md) |

## Порядок работы

1. Читать `/AGENTS.md`, `docs/CONTEXT_SCOPE.md` и [сопоставление Skills ролям](../docs/AGENT_SKILLS.md), затем только `SKILL.md` нужной роли.
2. Загружать вспомогательные файлы навыка **выборочно** по текущей задаче. Проектные ТЗ, архитектура, права доступа, дизайн-система и CI имеют приоритет над универсальными рекомендациями Skills.
3. При переносе/обновлении оригинальных архивов использовать `python scripts/skills/import.py` и `python scripts/skills/validate.py`; не загружать файлы пяти Skills общим списком непосредственно в корень `skills/`.
4. Для каждого изменения проверять manifest SHA-256, размеры, локальные ссылки и состав файлов. Данные `ideas` и секреты в публичную папку не добавлять.

Загрузка этих файлов в GitHub **не означает установку** Skills в личном ChatGPT и не запускает отдельных Work subagents. Это зафиксированные в репозитории инструкции, доступные новому разработчику из любого чата с доступом к GitHub.
