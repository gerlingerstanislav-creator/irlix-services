import unittest
from unittest.mock import MagicMock, patch

from fastapi import HTTPException

from app.main import _employees_access_roles, _realm_roles, require_platform_admin


class PlatformAdminAccessTest(unittest.TestCase):
    def test_normalizes_platform_admin_realm_role(self):
        claims = {'realm_access': {'roles': ['offline_access', 'platform_admin']}}
        self.assertIn('platform-admin', _realm_roles(claims))
        self.assertIs(require_platform_admin(claims, authorization='Bearer test'), claims)

    def test_normalizes_platform_admin_from_employees_access(self):
        payload = {'data': {'roles': ['employee', 'platform_admin']}}
        self.assertIn('platform-admin', _employees_access_roles(payload))

    @patch('app.main.httpx.Client')
    def test_accepts_platform_admin_from_employees_access(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['platform-admin']}}
        client = client_cls.return_value.__enter__.return_value
        client.get.return_value = response

        claims = {'realm_access': {'roles': ['employee']}}
        self.assertIs(require_platform_admin(claims, authorization='Bearer test'), claims)
        client.get.assert_called_once()

    @patch('app.main.httpx.Client')
    def test_rejects_non_admin(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['employee']}}
        client = client_cls.return_value.__enter__.return_value
        client.get.return_value = response

        claims = {'realm_access': {'roles': ['employee']}}
        with self.assertRaises(HTTPException) as context:
            require_platform_admin(claims, authorization='Bearer test')
        self.assertEqual(context.exception.status_code, 403)

    def test_rejects_missing_authorization_when_role_not_in_token(self):
        with self.assertRaises(HTTPException) as context:
            require_platform_admin({}, authorization=None)
        self.assertEqual(context.exception.status_code, 403)


if __name__ == '__main__':
    unittest.main()
