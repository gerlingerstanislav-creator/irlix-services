"""Production-safe SQLite metadata readiness check (no login impersonation).

Creates the new metadata schema on the persistent Storage V1 volume if absent.
Tests a rolled-back synthetic transaction. Does not touch S3 or user records.
"""
import os
from uuid import uuid4

from app.metadata_repository import metadata_connection, MetadataRepository


def main():
    if os.getenv("STORAGE_V1_ENABLED") != "1":
        print("[verify] Storage V1 disabled; SQLite metadata check skipped", flush=True)
        return
    if os.getenv("STORAGE_V1_METADATA_BACKEND") != "sqlite":
        print("[verify] Storage V1 not SQLite; local metadata check skipped", flush=True)
        return

    marker = str(uuid4())
    with metadata_connection() as conn:
        names = {row[0] for row in conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table'"
        ).fetchall()}
        assert {"objects", "grants", "audit_events", "file_versions"} <= names
        check = conn.execute("PRAGMA integrity_check").fetchone()
        assert check and check[0] == "ok", "Storage metadata integrity check failed"
        repo = MetadataRepository(conn, lambda _: False)
        repo.lock()
        conn.execute("INSERT INTO storage.objects (id,kind,owner_employee_id,display_name) "
                     "VALUES (%s,'folder',%s,%s)", (marker, 1, "_ci_rollback"))
        conn.rollback()  # Never leave synthetic users, files or objects behind.
    with metadata_connection() as conn:
        exists = conn.execute(
            "SELECT 1 FROM storage.objects WHERE id=%s", (marker,)
        ).fetchone()
        assert exists is None, "SQLite debug rollback failed"
    print("[verify] Storage V1 SQLite persistent schema, integrity and rollback OK",
          flush=True)


if __name__ == "__main__":
    main()
