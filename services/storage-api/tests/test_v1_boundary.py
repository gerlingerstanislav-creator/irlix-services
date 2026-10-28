import unittest
from types import SimpleNamespace
from fastapi import HTTPException
from app.v1_api import _identity, _name, _normalize_id, _employee_active
from app.access_policy import Permission


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
        self.assertNotIn(Permission.SHARE.value, [p.value for p in ()])

    def test_invalid_names_and_ids_rejected(self):
        for name in ("", "..", "../pass", "has\\backslash", "line\nfeed"):
            with self.assertRaises(HTTPException):
                _name(name)
        for oid in ("../../var", "not-a-uuid", ""):
            with self.assertRaises(HTTPException):
                _normalize_id(oid)

    def test_recipient_lookup_fails_closed_without_signed_cookie(self):
        request = SimpleNamespace(cookies={})
        self.assertFalse(_employee_active(request, 42))
        self.assertFalse(_employee_active(request, 0))


if __name__ == "__main__":
    unittest.main()
