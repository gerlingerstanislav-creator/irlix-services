"""Storage V1 single-host SQLite debug: ACL, metadata, audit and real API writes."""
import hashlib
import io
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from uuid import uuid4

from fastapi import Request
from fastapi.testclient import TestClient

from app.main import app, v1_guard
from app.metadata_repository import MetadataRepository, metadata_connection
from app.sqlite_metadata import _SCHEMA_READY


class FakeS3:
    def __init__(self):
        self.objects = {}

    def put_object(self, *, Bucket, Key, Body, ContentType, Metadata):
        self.objects[Key] = (bytes(Body), dict(Metadata), ContentType)

    def head_object(self, *, Bucket, Key):
        body, meta, content_type = self.objects[Key]
        return {"ContentLength": len(body), "Metadata": meta, "ContentType": content_type}

    def get_object(self, *, Bucket, Key):
        data, metadata, content_type = self.objects[Key]
        return {"Body": io.BytesIO(data), "ContentLength": len(data),
                "Metadata": metadata, "ContentType": content_type}

    def copy_object(self, *, Bucket, Key, CopySource):
        self.objects[Key] = self.objects[CopySource["Key"]]


class SQLiteDebugTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = str(Path(self.tmp.name) / "storage-v1.sqlite3")
        self.environment = patch.dict(os.environ, {
            "STORAGE_V1_ENABLED": "1", "STORAGE_V1_METADATA_BACKEND": "sqlite",
            "STORAGE_V1_SQLITE_PATH": self.path,
            "STORAGE_V1_QUOTA_BYTES": "524288",
            "STORAGE_V1_MIN_FREE_BYTES": "0",
        })
        self.environment.start()

    def tearDown(self):
        self.environment.stop()
        _SCHEMA_READY.discard(self.path)
        self.tmp.cleanup()

    def test_transactions_acl_audit_versions_and_restart(self):
        obj = str(uuid4())
        with metadata_connection() as conn:
            repo = MetadataRepository(conn, lambda employee: employee == 12)
            repo.lock()
            conn.execute(
                "INSERT INTO storage.objects (id,kind,owner_employee_id,display_name)"
                " VALUES (%s,'folder',%s,%s)", (obj, 11, "First")
            )
            repo.put_grant(obj, 12, __import__("app.access_policy", fromlist=["GrantRole"]).GrantRole.READER, 11)
            repo.append_audit({
                "event_id": str(uuid4()), "actor_employee_id": 11,
                "object_id": obj, "action": "folder.create", "outcome": "allowed",
                "correlation_id": str(uuid4()), "details": {}
            })
        with metadata_connection() as conn:
            repo = MetadataRepository(conn, lambda _: True)
            self.assertEqual(repo.load_objects(obj)[obj].owner_employee_id, 11)
            self.assertEqual(repo.load_grants(obj)[0].employee_id, 12)
            count = conn.execute("SELECT COUNT(*) FROM storage.audit_events").fetchone()[0]
            self.assertEqual(count, 1)
            with self.assertRaises(sqlite3.IntegrityError):
                conn.execute("UPDATE storage.audit_events SET action='changed'")
        # The rejected SQL must roll back rather than partially mutating data.
        with metadata_connection() as conn:
            row = conn.execute("SELECT action FROM storage.audit_events").fetchone()
            self.assertEqual(row[0], "folder.create")
        with self.assertRaises(sqlite3.IntegrityError):
            with metadata_connection() as conn:
                repo = MetadataRepository(conn, lambda _: True)
                repo.lock()
                conn.execute("INSERT INTO storage.objects (id,kind,owner_employee_id,display_name)"
                             " VALUES (%s,'folder',%s,%s)", (str(uuid4()), 11, "first"))
        with metadata_connection() as conn:
            self.assertEqual(conn.execute("SELECT COUNT(*) FROM storage.objects").fetchone()[0], 1)

    def test_production_metadata_probe_rolls_back_synthetic_row(self):
        from app.sqlite_smoke import main as probe
        with patch.dict(os.environ, {"STORAGE_V1_ENABLED": "1",
                                     "STORAGE_V1_METADATA_BACKEND": "sqlite"}):
            probe()
        with metadata_connection() as conn:
            self.assertEqual(conn.execute("SELECT COUNT(*) FROM storage.objects").fetchone()[0], 0)

    def test_http_file_roundtrip_owner_grant_revoke_trash_restore(self):
        fake = FakeS3()
        from app import v1_api

        async def pretend_authorized(request: Request):
            # TEST-ONLY override. Production V1 validates the signed Employees
            # HttpOnly session and never trusts any browser actor header.
            request.state.storage_actor_id = int(request.headers.get("x-test-actor", "11"))
            request.state.storage_platform_admin = False

        def caller(client, actor, method, path, **kwargs):
            headers = {"x-test-actor": str(actor)}
            return getattr(client, method)(path, headers=headers, **kwargs)

        app.dependency_overrides[v1_guard] = pretend_authorized
        try:
            with patch.object(v1_api, "_s3", return_value=fake), \
                 patch.object(v1_api, "_employee_active", side_effect=lambda _req, employee: employee == 12), \
                 TestClient(app) as client:
                folder = caller(client, 11, "post", "/v1/folders", json={"name": "Документы"})
                self.assertEqual(folder.status_code, 200, folder.text)
                root = folder.json()["id"]
                file = caller(client, 11, "post", "/v1/files",
                              data={"parent_id": root},
                              files={"file": ("summary.txt", b"hello world", "text/plain")})
                self.assertEqual(file.status_code, 200, file.text)
                oid = file.json()["id"]
                child = caller(client, 11, "get", "/v1/items", params={"parent_id": root})
                self.assertEqual(child.status_code, 200, child.text)
                self.assertEqual(child.json()["entries"][0]["size"], 11)
                self.assertEqual(child.json()["entries"][0]["shared_count"], 0)
                self.assertTrue(child.json()["entries"][0]["modified"])
                self.assertEqual(caller(client, 12, "get", f"/v1/files/{oid}").status_code, 403)
                shared = caller(client, 11, "post", f"/v1/objects/{root}/grants",
                                json={"employee_id": 12, "role": "reader"})
                self.assertEqual(shared.status_code, 200, shared.text)
                self.assertEqual(caller(client, 12, "get", f"/v1/files/{oid}").status_code, 200)
                active_grants = caller(client, 11, "get", f"/v1/objects/{root}/grants")
                self.assertEqual(active_grants.status_code, 200, active_grants.text)
                self.assertEqual(active_grants.json()["entries"], [{"employee_id": 12, "role": "reader"}])
                self.assertEqual(caller(client, 11, "get", "/v1/items").json()["entries"][0]["shared_count"], 1)
                self.assertEqual(caller(client, 12, "post", f"/v1/objects/{oid}/trash").status_code, 403)
                revoked = caller(client, 11, "delete", f"/v1/objects/{root}/grants/12")
                self.assertEqual(revoked.status_code, 200, revoked.text)
                self.assertEqual(caller(client, 12, "get", f"/v1/files/{oid}").status_code, 403)
                self.assertEqual(caller(client, 11, "get", "/v1/items").json()["entries"][0]["shared_count"], 0)
                self.assertEqual(caller(client, 11, "get", f"/v1/objects/{root}/grants").json()["entries"], [])
                trashed = caller(client, 11, "post", f"/v1/objects/{oid}/trash")
                self.assertEqual(trashed.status_code, 200, trashed.text)
                self.assertEqual(caller(client, 11, "get", f"/v1/files/{oid}").status_code, 403)
                restored = caller(client, 11, "post", f"/v1/objects/{oid}/restore")
                self.assertEqual(restored.status_code, 200, restored.text)
                self.assertEqual(caller(client, 11, "get", f"/v1/files/{oid}").content, b"hello world")
                versions = caller(client, 11, "get", f"/v1/objects/{oid}/versions")
                self.assertEqual(versions.status_code, 200, versions.text)
                self.assertEqual(len(versions.json()["versions"]), 1)
                history = caller(client, 11, "get", f"/v1/objects/{oid}/audit")
                self.assertEqual(history.status_code, 200, history.text)
                self.assertGreaterEqual(len(history.json()["events"]), 2)
                with metadata_connection() as conn:
                    self.assertEqual(conn.execute("SELECT COUNT(*) FROM file_versions").fetchone()[0], 1)
        finally:
            app.dependency_overrides.clear()


if __name__ == "__main__":
    unittest.main()
