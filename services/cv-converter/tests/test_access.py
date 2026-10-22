import unittest
from unittest.mock import MagicMock, patch

from fastapi import HTTPException

from app.main import KEYCLOAK_CLIENT_ID, _employees_access_roles, _realm_roles, require_platform_admin, require_user


class AuthorizedPartyTest(unittest.TestCase):
    @patch('app.main._jwks')
    @patch('app.main.jwt.decode')
    def test_keycloak_jwt_without_azp_is_rejected(self, decode, jwks):
        decode.return_value = {'sub': 'synthetic', 'exp': 9999999999}
        jwks.get_signing_key_from_jwt.return_value.key = 'synthetic'
        with self.assertRaises(HTTPException) as caught:
            require_user(authorization='Bearer signed-but-no-authorized-party')
        self.assertEqual(caught.exception.status_code, 401)

    @patch('app.main._jwks')
    @patch('app.main.jwt.decode')
    def test_keycloak_jwt_from_other_client_is_rejected(self, decode, jwks):
        decode.return_value = {'azp': 'another-oidc-client'}
        jwks.get_signing_key_from_jwt.return_value.key = 'synthetic'
        with self.assertRaises(HTTPException) as caught:
            require_user(authorization='Bearer signed-for-other-client')
        self.assertEqual(caught.exception.status_code, 401)

    @patch('app.main._jwks')
    @patch('app.main.jwt.decode')
    def test_keycloak_jwt_from_approved_client_is_accepted(self, decode, jwks):
        decode.return_value = {'azp': KEYCLOAK_CLIENT_ID}
        jwks.get_signing_key_from_jwt.return_value.key = 'synthetic'
        self.assertEqual(require_user(authorization='Bearer signed')['azp'], KEYCLOAK_CLIENT_ID)


class PlatformAdminAccessTest(unittest.TestCase):
    @patch('app.main.httpx.Client')
    def test_normalizes_platform_admin_realm_role(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['platform-admin'], 'services': {'cv-converter': True}}}
        client_cls.return_value.__enter__.return_value.get.return_value = response
        claims = {'realm_access': {'roles': ['offline_access', 'platform_admin']}}
        self.assertNotIn('platform-admin', _realm_roles(claims))
        self.assertIs(require_platform_admin(claims, authorization='Bearer test'), claims)

    @patch('app.main.httpx.Client')
    def test_rejects_platform_tester_realm_role(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['platform-tester'], 'services': {'cv-converter': True}}}
        client_cls.return_value.__enter__.return_value.get.return_value = response
        claims = {'realm_access': {'roles': ['platform_tester']}}
        self.assertNotIn('platform-tester', _realm_roles(claims))
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin(claims, authorization='Bearer test')
        self.assertEqual(ctx.exception.status_code, 403)

    @patch.dict('os.environ', {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_signed_jwt_grant_does_not_call_employees(self, client_cls):
        claims = {'realm_access': {'roles': ['platform-admin', 'service:cv-converter']}}
        self.assertIs(require_platform_admin(claims, authorization='Bearer signed'), claims)
        client_cls.assert_not_called()

    @patch.dict('os.environ', {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_explicit_role_preview_denies_admin_tools(self):
        claims = {'realm_access': {'roles': ['platform-admin', 'service:cv-converter']}}
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin(claims, authorization='Bearer signed', x_irlix_role_preview='accounting')
        self.assertEqual(ctx.exception.status_code, 403)

    @patch.dict('os.environ', {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_unmanaged_keycloak_group_role_cannot_be_normalized_into_platform_admin(self):
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin({
                'realm_access': {'roles': ['platform_admin', 'service:cv-converter']},
            }, authorization='Bearer signed')
        self.assertEqual(ctx.exception.status_code, 403)

    @patch.dict('os.environ', {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_signed_jwt_without_service_grant_denied(self):
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin({'realm_access': {'roles': ['platform-admin']}}, authorization='Bearer signed')
        self.assertEqual(ctx.exception.status_code, 403)

    @patch.dict('os.environ', {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_signed_jwt_without_privileged_role_denied(self):
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin({'realm_access': {'roles': ['service:cv-converter']}}, authorization='Bearer signed')
        self.assertEqual(ctx.exception.status_code, 403)

    def test_normalizes_platform_admin_from_employees_access(self):
        payload = {'data': {'roles': ['employee', 'platform_admin']}}
        self.assertIn('platform-admin', _employees_access_roles(payload))

    @patch('app.main.httpx.Client')
    def test_rejects_platform_tester_from_employees_access(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['platform-tester'], 'services': {'cv-converter': True}}}
        client = client_cls.return_value.__enter__.return_value
        client.get.return_value = response

        claims = {'realm_access': {'roles': ['employee']}}
        with self.assertRaises(HTTPException) as ctx:
            require_platform_admin(claims, authorization='Bearer test')
        self.assertEqual(ctx.exception.status_code, 403)
        client.get.assert_called_once()

    @patch('app.main.httpx.Client')
    def test_accepts_platform_admin_from_employees_access(self, client_cls):
        response = MagicMock(status_code=200)
        response.json.return_value = {'data': {'roles': ['platform-admin'], 'services': {'cv-converter': True}}}
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
