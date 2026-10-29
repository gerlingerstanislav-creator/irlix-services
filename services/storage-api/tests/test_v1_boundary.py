import unittest
from datetime import datetime, timezone
from types import SimpleNamespace
from fastapi import HTTPException
from fastapi.testclient import TestClient
from unittest.mock import patch
from app.main import app, guard, v1_guard
from app.metadata_repository import metadata_connection
from app.v1_api import _identity, _name, _normalize_id, _employee_active, _decorate_entries


class V1BoundaryTests(unittest.TestCase):
    def test_untrusted_identity_is_never_accepted(self):
        request = SimpleNamespace(state=SimpleNamespace(),
                                  cookies={"irlix_storage_pilot": "untrusted"},
                                  headers={"X-Storage-Employee-ID": "123"})
        with self.assertRaises(HTTPException) as context:
            _identity(request)
        self.assertEqual(context.exception.status_code, 503)

    def test_only_authenticated_server_actor_is_accepted(self):
        principal = _identity(SimpleNamespace(
            state=SimpleNamespace(storage_actor_id=12, storage_platform_admin=False)))
        self.assertEqual(principal.employee_id, 12)
        self.assertFalse(principal.platform_admin)

    def test_invalid_names_and_ids_rejected(self):
        for name in ("", "..", "../pass", "has\\backslash", "line\nfeed"):
            with self.assertRaises(HTTPException):
                _name(name)
        for oid in ("../../var", "not-a-uuid", ""):
            with self.assertRaises(HTTPException):
                _normalize_id(oid)

    def test_v1_api_fails_closed_without_server_verified_actor(self):
        app.dependency_overrides[guard] = lambda: None
        app.dependency_overrides[v1_guard] = lambda: None
        try:
            with TestClient(app) as client:
                response = client.get("/v1/items")
            self.assertEqual(response.status_code, 503)
        finally:
            app.dependency_overrides.clear()

    def test_v1_database_is_disabled_by_default_even_with_dsn(self):
        with patch.dict("os.environ", {"STORAGE_V1_ENABLED": "0",
                                     "STORAGE_V1_DB_DSN": "postgres://synthetic.invalid"}, clear=False):
            with self.assertRaises(HTTPException) as context:
                metadata_connection()
        self.assertEqual(context.exception.status_code, 503)

    def test_display_metadata_does_not_expand_acl_filtered_list(self):
        class Cursor:
            def fetchall(self):
                return [("00000000-0000-4000-8000-000000000001",
                         datetime(2026, 10, 29, tzinfo=timezone.utc), 24)]

        class Connection:
            def execute(self, query, params):
                self.assert_called = (query, params)
                return Cursor()

        conn = Connection()
        authorized = [{"id": "00000000-0000-4000-8000-000000000001",
                       "name": "visible.txt", "kind": "file"}]
        result = _decorate_entries(conn, authorized)
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["size"], 24)
        self.assertEqual(result[0]["modified"], "2026-10-29T00:00:00+00:00")
        self.assertEqual(conn.assert_called[1], ([authorized[0]["id"]],))
        self.assertIn("storage.file_versions", conn.assert_called[0])
        self.assertEqual(_decorate_entries(conn, []), [])

    def test_recipient_lookup_fails_closed_without_signed_cookie(self):
        request = SimpleNamespace(cookies={})
        self.assertFalse(_employee_active(request, 42))
        self.assertFalse(_employee_active(request, 0))


if __name__ == "__main__":
    unittest.main()
