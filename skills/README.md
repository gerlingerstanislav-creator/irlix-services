# Portable professional Skills for Irlix Services

Этот каталог предназначен для **полного содержимого пяти персональных ChatGPT Skill exports**, чтобы проект оставался воспроизводимым без привязки к одной учётной записи.

## Статус

**До импорта ZIP-архивов** тут находится только README. Профили агентов уже сопоставлены со Skills в [docs/AGENT_SKILLS.md](../docs/AGENT_SKILLS.md), однако сопоставление по имени **не означает, что байты навыка находятся в Git**.

После установки здесь появятся каталоги:

- `senior-business-system-analyst/SKILL.md`
- `senior-product-uiux-designer/SKILL.md`
- `senior-javascript-vue-frontend-engineer/SKILL.md`
- `senior-php-laravel-backend-engineer/SKILL.md`
- `senior-qa-engineer/SKILL.md`

**Вместе со всеми вложенными** `references/`, `assets/`, `scripts/`, `tests/`, `examples/`, файлами/картинками и `MANIFEST.json`.

## Импорт пяти исходных ZIP

Из корня checkout `irlix-services`:

```bash
python scripts/skills/import.py --dry-run /path/to/first.zip /path/to/second.zip /path/to/third.zip /path/to/fourth.zip /path/to/fifth.zip
python scripts/skills/import.py /path/to/first.zip /path/to/second.zip /path/to/third.zip /path/to/fourth.zip /path/to/fifth.zip
python scripts/skills/validate.py
git add skills/
git commit -m "feat(skills): import full professional Skill bundles"
```

Названия архивов значения не имеют: импортёр определяет оригинальный slug внутри каждого ZIP, запрещает traversal и symlinks, не исполняет содержимое и проверяет наличие всех пяти. Чтобы не затирать уже проверенный набор, повторный импорт требует предварительного отдельного обновления файлов.

## Использование агентами

1. Прочитать свои правила `.github/agents/<Role>.agent.md` и релевантные ТЗ.
2. При наличии соответствующего `skills/<slug>/SKILL.md` — читать его из Git и нужные относительные `references/` / `assets/`, вместо требования пользовательской установки.
3. Если навыки ещё не импортированы в Git, разрешено воспользоваться доступным персональным ChatGPT Skill либо полными инструкциями роли в репозитории. **Не утверждать, что Skills из Git установлены, когда каталогов нет.**
4. В Work каждый отдельный subagent читает только свой профиль и необходимый Skill; ни текст Skill, ни GitHub Actions не создают отдельных агентов автоматически.

Приоритет: утверждённые правила проекта, приватный `ideas`, контракт сервиса и CI > общие советы Skill. Не выполнять скрипты из ZIP автоматически: сначала провести review и запускать их только если они подходят контексту задачи.

**Безопасность:** `irlix-services` публичный. Перед публикацией новых версий выгруженных Skills убедиться, что в файлах нет секретов/персональных данных. Приватные ТЗ остаются в `ideas`, их не копируем сюда.
