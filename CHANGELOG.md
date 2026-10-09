# Changelog

История подтверждённых релизов в production. Наличие записи в `development` не означает выпуск. Для каждого нового выпуска указывать дату и проверенный SHA `main`.

## Unreleased

### Documentation

- Разделены состояние продукта (`docs/STATUS.md`), бэклог (`docs/BACKLOG.md` / GitHub Issues) и история релизов (`CHANGELOG.md`).

## Legacy notes (release date not verified)

Ниже сохранены записи первоначального changelog. Их дата и состав фактического production-релиза не подтверждены, поэтому они не рассматриваются как запись о выпущенной версии.

### Iteration 1 foundation

- monorepo runtime structure;
- Docker Compose with PostgreSQL, Redis and RabbitMQ;
- isolated PostgreSQL schemas/users for Platform Core and Employees;
- Laravel service scaffolds;
- Vue platform shell;
- automated build/deploy pipeline for the stand;
- repository-level development rules in `AGENTS.md`.
