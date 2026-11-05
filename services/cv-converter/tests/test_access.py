"""Authentication boundaries for CV's Employees/Keycloak V2 integration."""
import unittest
from unittest.mock import patch

from fastapi import HTTPException

from app.main import KEYCLOAK_CLIENT_ID, _realm_roles, require_user


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

    def test_keycloak_names_are_exact_signed_roles(self):
        self.assertNotIn('platform-admin', _realm_roles(
            {'realm_access': {'roles': ['platform_admin']}}))
        self.assertEqual(_realm_roles({'realm_access': {'roles': ['head:backend', 'service:cv-converter']}}),
                         {'head:backend', 'service:cv-converter'})


if __name__ == '__main__':
    unittest.main()
