# Development

## Context before development

Сначала прочитать `AGENTS.md` и `docs/CONTEXT_SCOPE.md`. Выбрать документацию и код текущего сервиса по задаче; соседние сервисы подключать только через конкретный используемый контракт. Не читать весь проект и не обходить зависимости рекурсивно.

## Branch workflow

Рабочая интеграционная ветка проекта — `development`.

Обычный цикл разработки:

```text
main
  └─ development
       ├─ commit 1
       ├─ commit 2
       └─ commit N
             │
             └─ итоговая проверка diff
                    │
                    ▼
              merge в main
                    │
                    ▼
                CI / deploy
```

Правила:

- промежуточные изменения коммитятся и push-ятся в `development`;
- `development` может содержать несколько технически связанных коммитов одной задачи;
- перед merge проверяется итоговый diff относительно `main`;
- в `main` попадает только законченный логический блок;
- deploy стенда выполняется только из `main`;
- промежуточные push в `development` не должны запускать deploy;
- если для `development` добавляются проверки, они должны быть быстрыми и не выполнять выкладку на стенд.

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
