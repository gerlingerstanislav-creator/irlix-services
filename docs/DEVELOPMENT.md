# Development

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

At minimum run:

```bash
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example build
```

Project-wide working rules are in `/AGENTS.md`.
