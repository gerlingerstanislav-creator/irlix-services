"""Single-node SQLite metadata for the restricted Storage V1 debugging stage.

Uses the existing SeaweedFS S3 object store for bytes; only metadata/ACL/audit
live in the SQLite file. Never touches the administrator pilot's S3 namespace.
A future PostgreSQL rollout can migrate this entire self-contained database.
"""
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
import os
import re
import sqlite3
import threading

from fastapi import HTTPException

_SCHEMA_LOCK = threading.Lock()
_SCHEMA_READY = set()

sqlite3.register_converter("TIMESTAMP", lambda raw: datetime.fromisoformat(raw.decode("utf-8")))

_SCHEMA = """
BEGIN IMMEDIATE;
CREATE TABLE IF NOT EXISTS objects (
    id TEXT PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('file','folder')),
    owner_employee_id INTEGER NOT NULL CHECK (owner_employee_id > 0),
    parent_id TEXT REFERENCES objects(id) ON DELETE RESTRICT,
    display_name TEXT NOT NULL CHECK (length(display_name) BETWEEN 1 AND 160),
    s3_key TEXT UNIQUE,
    state TEXT NOT NULL DEFAULT 'active' CHECK (state IN ('active','trashed')),
    deleted_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    CHECK ((kind='file' AND s3_key IS NOT NULL) OR (kind='folder' AND s3_key IS NULL)),
    CHECK ((state='active' AND deleted_at IS NULL) OR (state='trashed' AND deleted_at IS NOT NULL)),
    CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE UNIQUE INDEX IF NOT EXISTS objects_root_name ON
    objects(owner_employee_id, display_name COLLATE NOCASE) WHERE parent_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS objects_child_name ON
    objects(owner_employee_id, parent_id, display_name COLLATE NOCASE) WHERE parent_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS objects_owner ON objects(owner_employee_id, state);
CREATE INDEX IF NOT EXISTS objects_parent ON objects(parent_id);
CREATE TABLE IF NOT EXISTS grants (
    object_id TEXT NOT NULL REFERENCES objects(id) ON DELETE RESTRICT,
    employee_id INTEGER NOT NULL CHECK (employee_id > 0),
    role TEXT NOT NULL CHECK (role IN ('reader','editor')),
    granted_by_employee_id INTEGER NOT NULL CHECK (granted_by_employee_id > 0),
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP,
    PRIMARY KEY (object_id,employee_id)
);
CREATE INDEX IF NOT EXISTS grants_recipient ON grants(employee_id,object_id);
CREATE TABLE IF NOT EXISTS audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    actor_employee_id INTEGER NOT NULL CHECK (actor_employee_id > 0),
    object_id TEXT,
    action TEXT NOT NULL,
    outcome TEXT NOT NULL CHECK (outcome IN ('allowed','denied','error')),
    correlation_id TEXT NOT NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(details) AND json_type(details)='object')
);
CREATE INDEX IF NOT EXISTS audit_object_time ON audit_events(object_id,occurred_at DESC);
CREATE INDEX IF NOT EXISTS audit_actor_time ON audit_events(actor_employee_id,occurred_at DESC);
CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit_events
BEGIN SELECT RAISE(ABORT, 'audit is append-only'); END;
CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit_events
BEGIN SELECT RAISE(ABORT, 'audit is append-only'); END;
CREATE TABLE IF NOT EXISTS file_versions (
    id TEXT PRIMARY KEY,
    object_id TEXT NOT NULL REFERENCES objects(id) ON DELETE RESTRICT,
    s3_key TEXT NOT NULL UNIQUE,
    created_by_employee_id INTEGER NOT NULL CHECK (created_by_employee_id > 0),
    size_bytes INTEGER NOT NULL CHECK (size_bytes >= 0),
    sha256 TEXT NOT NULL CHECK (length(sha256)=64),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS versions_object_time ON file_versions(object_id,created_at DESC);
CREATE TRIGGER IF NOT EXISTS versions_no_update BEFORE UPDATE ON file_versions
BEGIN SELECT RAISE(ABORT, 'versions are append-only'); END;
CREATE TRIGGER IF NOT EXISTS versions_no_delete BEFORE DELETE ON file_versions
BEGIN SELECT RAISE(ABORT, 'versions are append-only'); END;
CREATE TABLE IF NOT EXISTS purge_requests (
    object_id TEXT PRIMARY KEY REFERENCES objects(id) ON DELETE RESTRICT,
    owner_employee_id INTEGER NOT NULL CHECK(owner_employee_id > 0),
    requested_by_employee_id INTEGER NOT NULL CHECK(requested_by_employee_id > 0),
    state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending', 'completed')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    CHECK((state='pending' AND completed_at IS NULL)
      OR (state='completed' AND completed_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS purge_requests_owner_idx ON purge_requests(owner_employee_id,state);
COMMIT;
"""

class SQLiteCompatConnection:
    """Narrow parameterized PostgreSQL-to-SQLite adapter for Storage SQL only."""

    is_sqlite = True

    def __init__(self, raw):
        self.raw = raw

    def execute(self, sql, params=()):
        # Never interpolate request parameters into SQL text.
        sql = re.sub(r"\bstorage\.(objects|grants|audit_events|file_versions|purge_requests)\b",
                     lambda match: match.group(1), sql)
        sql = re.sub(r"::(?:text|bigint|jsonb)", "", sql, flags=re.I)
        sql = re.sub(r"\bnow\(\)", "CURRENT_TIMESTAMP", sql, flags=re.I)
        sql = re.sub(r"\bILIKE\b", "LIKE", sql, flags=re.I)
        sql = sql.replace("%s", "?")
        return self.raw.execute(sql, tuple(params))

    def lock(self):
        # All mutations acquire the single-writer transaction before reading
        # any object/permission state. Concurrent mutations cannot bypass ACL.
        self.raw.execute("BEGIN IMMEDIATE")

    def commit(self):
        if self.raw.in_transaction:
            self.raw.commit()

    def rollback(self):
        if self.raw.in_transaction:
            self.raw.rollback()


def _database_path():
    path = Path(os.environ.get("STORAGE_V1_SQLITE_PATH", "/app/data/storage-v1.sqlite3"))
    if not path.is_absolute() or path.suffix != ".sqlite3":
        raise HTTPException(503, "Неправильный путь метаданных Storage")
    if not path.parent.is_dir() or not os.access(path.parent, os.W_OK):
        raise HTTPException(503, "Постоянное хранилище метаданных недоступно")
    if path.is_symlink():
        raise HTTPException(503, "Небезопасный путь метаданных")
    return path


def _open(path):
    raw = sqlite3.connect(str(path), timeout=15, isolation_level=None,
                          detect_types=sqlite3.PARSE_DECLTYPES)
    raw.execute("PRAGMA busy_timeout=15000")
    raw.execute("PRAGMA foreign_keys=ON")
    raw.execute("PRAGMA synchronous=FULL")
    raw.execute("PRAGMA journal_mode=WAL")
    with _SCHEMA_LOCK:
        if str(path) not in _SCHEMA_READY:
            raw.executescript(_SCHEMA)
            _SCHEMA_READY.add(str(path))
    return raw


@contextmanager
def sqlite_metadata_connection():
    path = _database_path()
    try:
        raw = _open(path)
    except (OSError, sqlite3.Error):
        raise HTTPException(503, "Локальные метаданные Storage недоступны")
    db = SQLiteCompatConnection(raw)
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        raw.close()
