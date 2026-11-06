"""Internal employee erasure for the restricted SQLite Storage V1 backend.

The employee-owned files and their application-level versions are permanently
deleted, as are the owner's folders, grants and authorship. S3 removal is
retryable: a durable purge marker blocks use before bytes are erased. This
exceptional owner-authorized erasure intentionally bypasses the ordinary
append-only SQLite audit/version triggers only inside its own transaction.
"""
from __future__ import annotations

import os
from fastapi import HTTPException
from botocore.exceptions import ClientError, BotoCoreError

from .metadata_repository import metadata_connection, MetadataRepository
from .v1_api import S3_ROOT, _s3, _bucket, _delete_current_s3_bytes

# No active employee can receive this identifier. It represents an actor whose
# identifying link has been erased, never a substitute ownership grant.
ERASED_ACTOR = 9223372036854775807


def _marks(values):
    return ",".join("%s" for _ in values)


def _erase_sqlite_metadata(conn, employee: int) -> None:
    repo = MetadataRepository(conn, lambda _: False)
    repo.lock()
    owned = [str(row[0]) for row in conn.execute(
        "SELECT id FROM storage.objects WHERE owner_employee_id=%s", (employee,)
    ).fetchall()]
    if owned:
        marks = _marks(owned)
        # Do not erase another employee's object through a cross-owner folder.
        if conn.execute(
            "SELECT 1 FROM storage.objects WHERE parent_id IN (" + marks +
            ") AND owner_employee_id<>%s LIMIT 1", (*owned, employee)
        ).fetchone():
            raise HTTPException(409, "Storage contains a folder with a different owner")

    # Preserve other employees' shared objects and grants, but remove the
    # deleted employee's identity from their attribution fields.
    conn.execute(
        "UPDATE storage.grants SET granted_by_employee_id=%s "
        "WHERE granted_by_employee_id=%s AND employee_id<>%s",
        (ERASED_ACTOR, employee, employee)
    )
    conn.execute("DELETE FROM storage.grants WHERE employee_id=%s", (employee,))
    conn.execute(
        "UPDATE storage.purge_requests SET requested_by_employee_id=%s "
        "WHERE requested_by_employee_id=%s AND owner_employee_id<>%s",
        (ERASED_ACTOR, employee, employee)
    )

    # SQLite version and audit immutability is retained for all normal calls.
    # SQLite DDL participates in this transaction; an error rolls back both
    # the erasure and temporary removal of these dedicated triggers.
    for trigger in ("audit_no_delete", "versions_no_delete", "versions_no_update"):
        conn.execute("DROP TRIGGER IF EXISTS " + trigger)
    conn.execute(
        "UPDATE storage.file_versions SET created_by_employee_id=%s "
        "WHERE created_by_employee_id=%s AND object_id NOT IN "
        "(SELECT id FROM storage.objects WHERE owner_employee_id=%s)",
        (ERASED_ACTOR, employee, employee)
    )
    conn.execute("DELETE FROM storage.audit_events WHERE actor_employee_id=%s", (employee,))
    conn.execute(
        "DELETE FROM storage.audit_events WHERE "
        "json_extract(details, '$.employee_id') = %s", (employee,)
    )
    if owned:
        marks = _marks(owned)
        conn.execute("DELETE FROM storage.audit_events WHERE object_id IN (" + marks + ")", owned)
        conn.execute("DELETE FROM storage.file_versions WHERE object_id IN (" + marks + ")", owned)
        conn.execute("DELETE FROM storage.grants WHERE object_id IN (" + marks + ")", owned)
        conn.execute("DELETE FROM storage.purge_requests WHERE object_id IN (" + marks + ")", owned)
        # Delete every owned child before its parent. Foreign keys remain ON.
        remaining = set(owned)
        while remaining:
            parents = {str(row[0]) for row in conn.execute(
                "SELECT parent_id FROM storage.objects WHERE id IN (" +
                _marks(list(remaining)) + ") AND parent_id IS NOT NULL",
                tuple(remaining)
            ).fetchall()}
            leaves = remaining - parents
            if not leaves:
                raise HTTPException(409, "Storage folder tree cannot be reconciled")
            for object_id in leaves:
                conn.execute("DELETE FROM storage.objects WHERE id=%s", (object_id,))
            remaining -= leaves
    conn.execute(
        "CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit_events "
        "BEGIN SELECT RAISE(ABORT, 'audit is append-only'); END"
    )
    conn.execute(
        "CREATE TRIGGER IF NOT EXISTS versions_no_delete BEFORE DELETE ON file_versions "
        "BEGIN SELECT RAISE(ABORT, 'versions are append-only'); END"
    )
    conn.execute(
        "CREATE TRIGGER IF NOT EXISTS versions_no_update BEFORE UPDATE ON file_versions "
        "BEGIN SELECT RAISE(ABORT, 'versions are append-only'); END"
    )


def purge_employee(employee: int) -> dict:
    if employee <= 0:
        raise HTTPException(422, "Invalid employee ID")
    if os.environ.get("STORAGE_V1_ENABLED", "0") != "1":
        # Disabled V1 has no employee-owned Storage V1 namespace. The legacy
        # pilot is a separate administrator-only, unscoped S3 namespace.
        return {"purged": True, "storage_v1": "disabled"}
    if os.environ.get("STORAGE_V1_METADATA_BACKEND") != "sqlite":
        # PostgreSQL version/audit tables require a separately privileged,
        # reviewed erasure procedure; never claim full deletion without it.
        raise HTTPException(503, "Storage employee purge is unavailable for this metadata backend")

    with metadata_connection() as conn:
        repo = MetadataRepository(conn, lambda _: False)
        repo.lock()
        files = conn.execute(
            "SELECT id,s3_key FROM storage.objects "
            "WHERE owner_employee_id=%s AND kind='file'", (employee,)
        ).fetchall()
        keys = set()
        for file_id, main_key in files:
            versions = conn.execute(
                "SELECT s3_key FROM storage.file_versions WHERE object_id=%s", (file_id,)
            ).fetchall()
            keys.update([main_key, *(v[0] for v in versions)])
            if main_key is None or not main_key.startswith(S3_ROOT):
                raise HTTPException(409, "Storage object is outside the approved S3 namespace")
        if any(not isinstance(key, str) or not key.startswith(S3_ROOT)
               for key in keys):
            raise HTTPException(409, "Storage contains an invalid version key")
        # Fail before altering metadata if cloud-managed versions can retain
        # objects after a normal S3 DeleteObject.
        if files:
            try:
                versioning = _s3().get_bucket_versioning(Bucket=_bucket())
            except (BotoCoreError, ClientError, OSError):
                raise HTTPException(503, "Cannot verify Storage versioning")
            if versioning.get("Status") in {"Enabled", "Suspended"}:
                raise HTTPException(409, "Versioned S3 objects require explicit version reconciliation")
            if versioning.get("Status") not in (None, ""):
                raise HTTPException(503, "Unexpected Storage versioning configuration")
        for file_id, _ in files:
            conn.execute(
                "UPDATE storage.objects SET state='trashed',deleted_at=COALESCE(deleted_at,now()),"
                "updated_at=now() WHERE id=%s", (file_id,)
            )
            conn.execute(
                "INSERT INTO storage.purge_requests (object_id,owner_employee_id,requested_by_employee_id) "
                "VALUES (%s,%s,%s) ON CONFLICT(object_id) DO NOTHING",
                (file_id, employee, employee)
            )

    # The committed pending marker denies downloads/edits. Partial S3 failure
    # retains every key in metadata for an idempotent next attempt.
    if keys:
        client = _s3()
        for key in sorted(keys):
            _delete_current_s3_bytes(client, key)
    with metadata_connection() as conn:
        _erase_sqlite_metadata(conn, employee)
    return {"purged": True}
