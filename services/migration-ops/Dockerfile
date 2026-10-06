FROM python:3.13-alpine
RUN apk add --no-cache docker-cli docker-cli-compose curl
WORKDIR /opt/irlix-services
COPY scripts/migration_ops_server.py scripts/migration_console.py scripts/migration_console_snapshot.py scripts/migration_test_snapshot.py scripts/migration_test_rollback.py scripts/migration_vacations_snapshot.py scripts/migration_vacations_rollback.py scripts/
COPY scripts/tests/test_migration_console.py scripts/tests/test_migration_console.py
RUN python3 -m compileall -q scripts && python3 -m unittest discover -s scripts/tests -v
CMD ["python3", "scripts/migration_ops_server.py"]
