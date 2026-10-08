# Development

## Context before development

Сначала прочитать `AGENTS.md` и `docs/CONTEXT_SCOPE.md`. Выбрать документацию и код текущего сервиса по задаче; соседние сервисы подключать только через конкретный используемый контракт. Не читать весь проект и не обходить зависимости рекурсивно.

## Branch workflow

`main` — production baseline; `development` — накопительный интеграционный релиз-кандидат. Изменения могут накапливаться несколько задач подряд.

```text
feature/* (если нужна изоляция) → development → CI verified
                                         │
                                         └─ явная команда «в прод» → fast-forward main
                                                                  → production CI/deploy
```

- Новые задачи добавляются в `development`, но **не запускают release автоматически**.
- `development` должна быть стабильной как единый релиз; незаконченная функциональность остаётся во временной feature-ветке.
- Каждый production-релиз содержит **все** коммиты `main..development`; не выполняются выборочные cherry-pick/squash.
- В `main` не коммитить и не создавать hotfix напрямую. Сдвиг `main` разрешён только fast-forward до CI-проверенного SHA `development`, строго с проверкой ожидаемого SHA.
- Перед выпуском показать все изменения относительно `main`; убедиться в успешном `CI verified` целевого SHA и в отсутствии новых коммитов во время продвижения.
- `main` в CI проверяется на происхождение из истории `development`; без успешной проверки prod deploy запрещён. Дополнительно нужен GitHub ruleset для реального запрета direct push.
- После появления TEST окружения каждый push в `development` будет выкладывать его туда с отдельными credentials/данными. Сейчас `development` только проверяется и собирается.
- Ручной release workflow: `.github/workflows/release.yml`. Подробности: `docs/CI.md`.

## Start

```bash
cp .env.example .env
docker compose up -d --build
```

Local endpoints:

- frontend: `http://127.0.0.1:8080`;
- Platform Core: `http://127.0.0.1:8081/api/health`;
- Employees: `http://127.0.0.1:8082/api/health`.

PostgreSQL, Redis and RabbitMQ are internal Compose services and are not published to the host by default.

## Reset local infrastructure

To recreate PostgreSQL schemas/users from scratch:

```bash
docker compose down -v
docker compose up -d --build
```

`down -v` removes local volumes and therefore all local data.

## Before commit

Run the relevant local tests for changed components. Validate Compose when infrastructure changes; build only affected image services when image verification is needed:

```bash
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example build <affected-image-service>
```

Project-wide working rules are in `/AGENTS.md`.

Use the applicable Compose overlays for the component. Full-stack build is needed only for a platform-wide change or explicit bootstrap, not for every service task. Executing a shared check does not require loading every checked service into the working context.

## CI integration

Before adding a service or changing build/deploy behavior, read `docs/CI.md`. Register each image in `infra/ci/services.json`; generate the image overlay and validate the complete Compose stack. Local/temporary branches use local checks; automatic image verification runs on development, deployment on main. Do not recreate per-service pull-request build workflows.
