"""CV conversion entitlement and administrator-only provider settings."""

import os
import unittest
from unittest.mock import MagicMock, patch

from fastapi import HTTPException

from app.main import app, require_cv_access, require_platform_admin


def response(roles, allowed, *, status=200):
    value = MagicMock(status_code=status)
    value.json.return_value = {'data': {
        'roles': roles,
        'services': {'cv-converter': allowed},
    }}
    return value


class CvProductionLeadsAccessTest(unittest.TestCase):
    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_signed_entitlement_allows_production_manager_without_admin_role(self):
        claims = {'realm_access': {'roles': [
            'backend-head', 'head:backend', 'service:cv-converter',
        ]}}
        self.assertIs(require_cv_access(claims, authorization='Bearer synthetic'), claims)
        with self.assertRaises(HTTPException) as caught:
            require_platform_admin(claims, authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_manager_or_generic_staff_without_signed_service_entitlement_denied(self):
        for roles in (['backend-head'], ['head:backend'], ['backend'], ['system-admin'],
                      ['platform-tester'], ['company-admin'], ['platform-admin']):
            with self.subTest(roles=roles):
                with self.assertRaises(HTTPException) as caught:
                    require_cv_access({'realm_access': {'roles': roles}},
                                      authorization='Bearer synthetic')
                self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_signed_admin_may_convert_and_manage_provider_settings(self):
        claims = {'realm_access': {'roles': ['platform-admin', 'service:cv-converter']}}
        self.assertIs(require_cv_access(claims, authorization='Bearer synthetic'), claims)
        self.assertIs(require_platform_admin(claims, authorization='Bearer synthetic'), claims)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    def test_preview_cannot_use_cv_even_with_a_valid_service_grant(self):
        claims = {'realm_access': {'roles': ['platform-admin', 'service:cv-converter']}}
        for dependency in (require_cv_access, require_platform_admin):
            with self.subTest(dependency=dependency.__name__):
                with self.assertRaises(HTTPException) as caught:
                    dependency(claims, authorization='Bearer synthetic',
                               x_irlix_role_preview='backend-head')
                self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_employees_service_grant_allows_manager_conversion_only(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = response(
            ['backend', 'backend-head'], True)
        claims = {'realm_access': {'roles': ['backend']}}
        self.assertIs(require_cv_access(claims, authorization='Bearer synthetic'), claims)
        with self.assertRaises(HTTPException) as caught:
            require_platform_admin(claims, authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_employees_revocation_denies_even_if_role_is_still_present(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = response(
            ['backend-head'], False)
        with self.assertRaises(HTTPException) as caught:
            require_cv_access({'realm_access': {'roles': ['service:cv-converter']}},
                              authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_admin_settings_require_verified_employees_role(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = response(
            ['platform-admin'], True)
        self.assertEqual(
            require_platform_admin({'realm_access': {'roles': []}},
                                   authorization='Bearer synthetic'),
            {'realm_access': {'roles': []}},
        )

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_employees_outage_fails_closed(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = response(
            ['backend-head'], True, status=503)
        with self.assertRaises(HTTPException) as caught:
            require_cv_access({}, authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 503)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_unauthorized_access_payload_fails_closed(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = response(
            ['backend-head'], True, status=403)
        with self.assertRaises(HTTPException) as caught:
            require_cv_access({}, authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_malformed_employees_entitlement_fails_closed(self, client_cls):
        result = MagicMock(status_code=200)
        result.json.return_value = {'data': {'roles': ['backend-head'], 'services': None}}
        client_cls.return_value.__enter__.return_value.get.return_value = result
        with self.assertRaises(HTTPException) as caught:
            require_cv_access({}, authorization='Bearer synthetic')
        self.assertEqual(caught.exception.status_code, 403)

    def test_endpoint_dependencies_keep_settings_admin_only(self):
        actual = {}
        for route in app.routes:
            if getattr(route, 'path', None) in {
                '/api/parse', '/api/render/docx', '/api/render/pdf',
                '/api/settings', '/api/settings/test',
            }:
                actual[route.path] = route.dependant.dependencies[0].call
        for path in ('/api/parse', '/api/render/docx', '/api/render/pdf'):
            self.assertIs(actual[path], require_cv_access, path)
        for path in ('/api/settings', '/api/settings/test'):
            self.assertIs(actual[path], require_platform_admin, path)
        for route in app.routes:
            if getattr(route, 'path', None) == '/api/settings':
                self.assertIs(route.dependant.dependencies[0].call, require_platform_admin)


if __name__ == '__main__':
    unittest.main()
