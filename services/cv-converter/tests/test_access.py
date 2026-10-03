import unittest

from fastapi import HTTPException

from app.main import _realm_roles, require_platform_admin


class PlatformAdminAccessTest(unittest.TestCase):
    def test_normalizes_platform_admin_role(self):
        claims = {'realm_access': {'roles': ['offline_access', 'platform_admin']}}
        self.assertIn('platform-admin', _realm_roles(claims))
        self.assertIs(require_platform_admin(claims), claims)

    def test_rejects_non_admin(self):
        claims = {'realm_access': {'roles': ['employee']}}
        with self.assertRaises(HTTPException) as context:
            require_platform_admin(claims)
        self.assertEqual(context.exception.status_code, 403)

    def test_rejects_missing_roles(self):
        with self.assertRaises(HTTPException) as context:
            require_platform_admin({})
        self.assertEqual(context.exception.status_code, 403)


if __name__ == '__main__':
    unittest.main()
