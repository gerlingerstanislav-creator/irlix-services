import os
import unittest
from unittest.mock import patch
from fastapi import HTTPException
from app.v1_api import _storage_usage, _require_capacity


class Cursor:
    def __init__(self, value):
        self.value = value
    def fetchone(self):
        return (self.value,)


class Connection:
    def __init__(self, value):
        self.value = value
        self.calls = []
    def execute(self, sql, args):
        self.calls.append((sql, args))
        return Cursor(self.value)


class Repo:
    def __init__(self, value):
        self.conn = Connection(value)


class P2QuotaTests(unittest.TestCase):
    def test_owner_scope_and_versions_are_accounted(self):
        repo = Repo(2048)
        self.assertEqual(_storage_usage(repo, 42), 2048)
        self.assertIn("storage.file_versions", repo.conn.calls[0][0])
        self.assertEqual(repo.conn.calls[0][1], (42,))

    def test_quota_limit_and_default_disabled(self):
        repo = Repo(900)
        with patch.dict(os.environ, {"STORAGE_V1_QUOTA_BYTES": "1000"}):
            _require_capacity(repo, 7, 100)
            with self.assertRaises(HTTPException) as context:
                _require_capacity(repo, 7, 101)
            self.assertEqual(context.exception.status_code, 413)
        with patch.dict(os.environ, {"STORAGE_V1_QUOTA_BYTES": "0"}):
            _require_capacity(repo, 7, 999999999)
        with patch.dict(os.environ, {"STORAGE_V1_QUOTA_BYTES": "-1"}):
            with self.assertRaises(HTTPException) as context:
                _require_capacity(repo, 7, 1)
            self.assertEqual(context.exception.status_code, 503)
        with patch.dict(os.environ, {"STORAGE_V1_QUOTA_BYTES": "invalid"}):
            with self.assertRaises(HTTPException) as context:
                _require_capacity(repo, 7, 1)
            self.assertEqual(context.exception.status_code, 503)


if __name__ == "__main__":
    unittest.main()
