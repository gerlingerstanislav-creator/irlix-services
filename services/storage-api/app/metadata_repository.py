"""Storage-owned metadata repository (PostgreSQL or single-node SQLite debug).

Never reads Employees' tables. Caller supplies verified actor and active
recipient checker from the Employees API. All mutation methods share the same
SERIALIZABLE transaction; no physical S3 deletion is possible here.
"""
import json
import os
from uuid import UUID
from fastapi import HTTPException
import psycopg

from .access_policy import FileGrant, FileObject, GrantRole
from .sqlite_metadata import sqlite_metadata_connection


def metadata_connection():
    if os.environ.get("STORAGE_V1_ENABLED") != "1":
        raise HTTPException(503, "Новое хранилище пока не подключено")
    backend = os.environ.get("STORAGE_V1_METADATA_BACKEND", "postgres")
    if backend == "sqlite":
        return sqlite_metadata_connection()
    if backend != "postgres" or not os.environ.get("STORAGE_V1_DB_DSN"):
        raise HTTPException(503, "Метаданные хранилища не настроены")
    try:
        return psycopg.connect(os.environ["STORAGE_V1_DB_DSN"], connect_timeout=3)
    except psycopg.Error:
        raise HTTPException(503, "Метаданные хранилища недоступны")


class MetadataRepository:
    def __init__(self, conn, employee_active):
        self.conn = conn
        self.employee_active = employee_active

    def lock(self):
        if getattr(self.conn, "is_sqlite", False):
            self.conn.lock()
            return
        self.conn.execute("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE")
        self.conn.execute("SELECT pg_advisory_xact_lock(hashtext('irlix-storage-v1-metadata'))")

    def load_objects(self, object_id):
        result = {}
        current = str(UUID(str(object_id)))
        for _ in range(32):
            row = self.conn.execute(
                "SELECT id::text, owner_employee_id, parent_id::text, state "
                "FROM storage.objects WHERE id=%s", (current,)
            ).fetchone()
            if not row:
                break
            identity, owner, parent, state = row
            if identity in result:
                break
            result[identity] = FileObject(identity, owner, parent, state)
            if parent is None:
                break
            current = parent
        return result

    def load_grants(self, object_id):
        objects = self.load_objects(object_id)
        if not objects:
            return []
        keys = list(objects)
        if getattr(self.conn, "is_sqlite", False):
            marks = ",".join("?" for _ in keys)
            rows = self.conn.execute(
                "SELECT object_id, employee_id, role FROM storage.grants "
                "WHERE object_id IN (" + marks + ") AND revoked_at IS NULL",
                keys,
            ).fetchall()
        else:
            rows = self.conn.execute(
                "SELECT object_id::text, employee_id, role FROM storage.grants "
                "WHERE object_id = ANY(%s::uuid[]) AND revoked_at IS NULL", (keys,)
            ).fetchall()
        return [FileGrant(obj, employee, GrantRole(role)) for obj, employee, role in rows]

    def employee_is_active(self, employee_id):
        return bool(self.employee_active(employee_id))

    def put_grant(self, object_id, employee_id, role, granted_by):
        self.conn.execute(
            "INSERT INTO storage.grants "
            "(object_id,employee_id,role,granted_by_employee_id,revoked_at) "
            "VALUES (%s,%s,%s,%s,NULL) "
            "ON CONFLICT (object_id,employee_id) DO UPDATE SET "
            "role=EXCLUDED.role,granted_by_employee_id=EXCLUDED.granted_by_employee_id,"
            "granted_at=now(),revoked_at=NULL",
            (object_id, employee_id, role.value, granted_by)
        )

    def revoke_grant(self, object_id, employee_id):
        self.conn.execute(
            "UPDATE storage.grants SET revoked_at=now() "
            "WHERE object_id=%s AND employee_id=%s AND revoked_at IS NULL",
            (object_id, employee_id)
        )

    def set_object_state(self, object_id, state):
        self.conn.execute(
            "UPDATE storage.objects SET state=%s,deleted_at=CASE WHEN %s='trashed' THEN now() ELSE NULL END,"
            "updated_at=now(),revision=revision+1 WHERE id=%s",
            (state, state, object_id)
        )

    def append_audit(self, event):
        self.conn.execute(
            "INSERT INTO storage.audit_events "
            "(event_id,actor_employee_id,object_id,action,outcome,correlation_id,details) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s::jsonb)",
            (event["event_id"], event["actor_employee_id"], event.get("object_id"),
             event["action"], event["outcome"], event["correlation_id"],
             json.dumps(event.get("details", {})))
        )

    def object_summary(self, object_id):
        return self.conn.execute(
            "SELECT id::text,kind,owner_employee_id,parent_id::text,display_name,s3_key,state "
            "FROM storage.objects WHERE id=%s", (object_id,)
        ).fetchone()

    def list_for_actor(self, actor_id):
        return self.conn.execute(
            "SELECT DISTINCT o.id::text,o.kind,o.owner_employee_id,"
            "o.parent_id::text,o.display_name,o.state "
            "FROM storage.objects o LEFT JOIN storage.grants g ON g.object_id=o.id "
            "AND g.revoked_at IS NULL AND g.employee_id=%s "
            "WHERE o.owner_employee_id=%s OR g.employee_id=%s "
            "ORDER BY o.display_name LIMIT 200", (actor_id, actor_id, actor_id)
        ).fetchall()
