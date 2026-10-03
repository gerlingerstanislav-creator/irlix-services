FROM python:3.13-alpine
RUN apk add --no-cache docker-cli docker-cli-compose curl
WORKDIR /opt/irlix-services
COPY scripts/migration_ops_server.py scripts/migration_test_snapshot.py scripts/migration_test_rollback.py scripts/
RUN python3 -m py_compile scripts/migration_ops_server.py scripts/migration_test_snapshot.py scripts/migration_test_rollback.py
CMD ["python3", "scripts/migration_ops_server.py"]
