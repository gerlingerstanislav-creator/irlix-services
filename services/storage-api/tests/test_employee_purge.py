"""Employee erasure safety: owned bytes, ACLs, foreign history and retries."""
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from uuid import uuid4

from botocore.exceptions import ClientError
from fastapi.testclient import TestClient

from app.main import app
from app.metadata_repository import metadata_connection
from app.sqlite_metadata import _SCHEMA_READY
from app.employee_purge import ERASED_ACTOR


class FakeStorage:
    def __init__(self):
        self.data = {"v1/owned", "v1/owned-old", "v1/foreign"}
        self.fail_key = None
        self.versioning = ""

    def get_bucket_versioning(self, **kwargs):
        return {"Status": self.versioning}

    def delete_object(self, *, Bucket, Key):
        if Key == self.fail_key:
            raise ClientError({"Error": {"Code": "ServiceUnavailable"}}, "DeleteObject")
        self.data.discard(Key)

    def head_object(self, *, Bucket, Key):
        if Key not in self.data:
            raise ClientError({"Error": {"Code": "404"}}, "HeadObject")
        return {"ContentLength": 1}


class EmployeePurgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = str(Path(self.tmp.name) / "storage-v1.sqlite3")
        self.env = patch.dict(os.environ, {
            "STORAGE_V1_ENABLED": "1",
            "STORAGE_V1_METADATA_BACKEND": "sqlite",
            "STORAGE_V1_SQLITE_PATH": self.path,
            "IRLIX_INTERNAL_PURGE_TOKEN": "synthetic-purge-test-token",
        })
        self.env.start()
        self.folder, self.owned, self.foreign = [str(uuid4()) for _ in range(3)]
        self.storage = FakeStorage()
        with metadata_connection() as conn:
            conn.lock()
            for ident, kind, owner, parent, key in [
                (self.folder, "folder", 7, None, None),
                (self.owned, "file", 7, self.folder, "v1/owned"),
                (self.foreign, "file", 8, None, "v1/foreign"),
            ]:
                conn.execute(
                    "INSERT INTO storage.objects "
                    "(id,kind,owner_employee_id,parent_id,display_name,s3_key) "
                    "VALUES (%s,%s,%s,%s,%s,%s)",
                    (ident, kind, owner, parent, ident, key)
                )
            for ident, obj, key, creator in [
                (str(uuid4()), self.owned, "v1/owned-old", 7),
                (str(uuid4()), self.foreign, "v1/foreign", 7),
            ]:
                conn.execute(
                    "INSERT INTO storage.file_versions "
                    "(id,object_id,s3_key,created_by_employee_id,size_bytes,sha256) "
                    "VALUES (%s,%s,%s,%s,1,%s)",
                    (ident, obj, key, creator, "a"*64)
                )
            conn.execute(
                "INSERT INTO storage.grants (object_id,employee_id,role,granted_by_employee_id) "
                "VALUES (%s,7,'reader',8)", (self.foreign,)
            )
            conn.execute(
                "INSERT INTO storage.grants (object_id,employee_id,role,granted_by_employee_id) "
                "VALUES (%s,9,'reader',7)", (self.foreign,)
            )
            for actor, oid in [(7, self.foreign), (8, self.owned), (8, self.foreign)]:
                conn.execute(
                    "INSERT INTO storage.audit_events "
                    "(event_id,actor_employee_id,object_id,action,outcome,correlation_id) "
                    "VALUES (%s,%s,%s,'file.preview','allowed',%s)",
                    (str(uuid4()), actor, oid, str(uuid4()))
                )
        self.s3 = patch("app.employee_purge._s3", return_value=self.storage)
        self.s3.start()
        self.client = TestClient(app)

    def tearDown(self):
        self.client.close()
        self.s3.stop()
        self.env.stop()
        _SCHEMA_READY.discard(self.path)
        self.tmp.cleanup()

    def run_purge(self, token="synthetic-purge-test-token"):
        return self.client.post("/internal/system/employees/7/purge",
                                headers={"X-Irlix-Internal-Purge-Token": token})

    def test_removes_owned_bytes_and_metadata_but_preserves_foreign_data(self):
        self.assertEqual(self.run_purge("invalid").status_code, 403)
        self.assertEqual(self.run_purge().status_code, 200)
        self.assertEqual(self.run_purge().status_code, 200)  # idempotent
        self.assertEqual(self.storage.data, {"v1/foreign"})
        with metadata_connection() as conn:
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.objects WHERE owner_employee_id=7"
            ).fetchone()[0], 0)
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.file_versions WHERE created_by_employee_id=7"
            ).fetchone()[0], 0)
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.grants WHERE employee_id=7 OR granted_by_employee_id=7"
            ).fetchone()[0], 0)
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.audit_events WHERE actor_employee_id=7"
            ).fetchone()[0], 0)
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.objects WHERE owner_employee_id=8"
            ).fetchone()[0], 1)
            self.assertEqual(conn.execute(
                "SELECT created_by_employee_id FROM storage.file_versions "
                "WHERE object_id=%s", (self.foreign,)
            ).fetchone()[0], ERASED_ACTOR)
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.audit_events WHERE actor_employee_id=8"
            ).fetchone()[0], 1)
            # Ordinary immutable history cannot be deleted after special erasure.
            with self.assertRaises(sqlite3.IntegrityError):
                conn.execute("DELETE FROM storage.audit_events")

    def test_failure_keeps_blocking_marker_and_retries(self):
        self.storage.fail_key = "v1/owned"
        self.assertEqual(self.run_purge().status_code, 503)
        with metadata_connection() as conn:
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.purge_requests WHERE owner_employee_id=7"
            ).fetchone()[0], 1)
            self.assertEqual(conn.execute(
                "SELECT state FROM storage.objects WHERE id=%s", (self.owned,)
            ).fetchone()[0], "trashed")
        self.storage.fail_key = None
        self.assertEqual(self.run_purge().status_code, 200)
        self.assertEqual(self.storage.data, {"v1/foreign"})

    def test_versioned_bucket_refuses_without_mutation(self):
        self.storage.versioning = "Enabled"
        self.assertEqual(self.run_purge().status_code, 409)
        with metadata_connection() as conn:
            self.assertEqual(conn.execute(
                "SELECT COUNT(*) FROM storage.purge_requests"
            ).fetchone()[0], 0)
        self.assertEqual(len(self.storage.data), 3)


if __name__ == "__main__":
    unittest.main()
