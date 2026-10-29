"""Regression for full /tmp, full SQLite disk and permission errors.

Real S3 and host capacity checks still require a private deployment smoke test.
"""
import errno
import sqlite3
import unittest

from fastapi import FastAPI, File, UploadFile
from fastapi.testclient import TestClient

from app.storage_errors import install_storage_error_handlers


class StorageUploadFailureTests(unittest.TestCase):
    def setUp(self):
        service = FastAPI()
        install_storage_error_handlers(service)

        @service.post("/upload/disk")
        async def no_space(file: UploadFile = File(...)):
            raise OSError(errno.ENOSPC, "No space left on device")

        @service.post("/upload/readonly")
        async def read_only(file: UploadFile = File(...)):
            raise OSError(errno.EROFS, "Read-only file system")

        @service.post("/upload/sqlite-full")
        async def sqlite_full(file: UploadFile = File(...)):
            raise sqlite3.OperationalError("database or disk is full")

        @service.post("/upload/sqlite-locked")
        async def sqlite_locked(file: UploadFile = File(...)):
            raise sqlite3.OperationalError("database is locked")

        self.client = TestClient(service)

    def _upload(self, route):
        return self.client.post(route, files={"file": ("large.png", b"x" * 128, "image/png")})

    def test_enospc_is_507_with_no_exception_details(self):
        response = self._upload("/upload/disk")
        self.assertEqual(response.status_code, 507)
        self.assertNotIn("No space left", response.text)
        self.assertIn("места", response.json()["detail"])

    def test_readonly_disk_is_503(self):
        self.assertEqual(self._upload("/upload/readonly").status_code, 503)

    def test_sqlite_disk_full_is_507(self):
        self.assertEqual(self._upload("/upload/sqlite-full").status_code, 507)

    def test_sqlite_lock_is_503(self):
        self.assertEqual(self._upload("/upload/sqlite-locked").status_code, 503)


if __name__ == "__main__":
    unittest.main()
