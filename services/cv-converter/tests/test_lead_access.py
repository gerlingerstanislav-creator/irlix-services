"""CV V2 security regression tests: local matrix, signed identity and settings."""
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

from fastapi import HTTPException

from app import access_policy
from app.main import (
    app, _employees_access_roles, _realm_roles, _verified_cv_context,
    require_cv_access, require_platform_admin,
)


def live_access(*, actor=10, roles=None, claims=None, allowed=True, status=200):
    response = MagicMock(status_code=status)
    response.json.return_value = {'data': {
        'employee_id': actor,
        'roles': roles or [],
        'role_claims': claims if claims is not None else ['head:development', 'org:backend'],
        'services': {'cv-converter': allowed},
    }}
    return response


def signed(*roles, actor=10):
    return {'realm_access': {'roles': [f'employee-id:{actor}', *roles]}}


class CvPermissionMatrixTest(unittest.TestCase):
    def setUp(self):
        self.tempdir = tempfile.TemporaryDirectory()
        self.addCleanup(self.tempdir.cleanup)
        self.patcher = patch.object(access_policy, 'DB_PATH', Path(self.tempdir.name) / 'cv.sqlite3')
        self.patcher.start()
        self.addCleanup(self.patcher.stop)

    def test_only_verified_heads_have_seed_grant_and_admin_is_immutable(self):
        self.assertTrue(access_policy.can_convert({'head:development'}))
        self.assertTrue(access_policy.can_convert({'head:backend', 'org:backend'}))
        self.assertFalse(access_policy.can_convert({'development-head'}))
        self.assertFalse(access_policy.can_convert({'org:development'}))
        self.assertFalse(access_policy.can_convert({'special:platform-tester'}))
        self.assertFalse(access_policy.can_convert(set()))
        self.assertTrue(access_policy.can_convert(set(), admin=True))

    def test_explicit_deny_overrides_wildcard_and_unrelated_grant(self):
        access_policy.update('head:backend', False, 42)
        access_policy.update('org:backend', True, 42)
        self.assertFalse(access_policy.can_convert({'head:backend', 'org:backend'}))
        self.assertTrue(access_policy.can_convert({'head:development'}))
        self.assertFalse(access_policy.can_convert({'org:recruitment'}))
        access_policy.update('head:backend', True, 42)
        self.assertTrue(access_policy.can_convert({'head:backend'}))

    def test_audit_and_customization_survive_store_reopen(self):
        access_policy.update('head:*', False, 1)
        access_policy.update('org:recruitment', True, 1)
        self.assertFalse(access_policy.can_convert({'head:development'}))
        self.assertTrue(access_policy.can_convert({'org:recruitment'}))
        with sqlite3.connect(access_policy.DB_PATH) as db:
            count, = db.execute('SELECT COUNT(*) FROM cv_permission_audit').fetchone()
        self.assertEqual(count, 2)
        self.assertEqual(len(access_policy.matrix()), 2)
        self.assertTrue(access_policy.DB_PATH.is_file())
        access_policy.remove('head:*', 1)
        self.assertFalse(access_policy.can_convert({'head:development'}))
        self.assertEqual(len(access_policy.matrix()), 1)  # No re-seeding after delete.

    def test_rejects_untrusted_role_formats_or_special_roles(self):
        for role in ('backend-head', 'service:cv-converter', 'scope:all',
                     'head:../backend', 'org:Backend', 'special:platform-admin',
                     'special:platform-tester', '', 'head:'):
            with self.subTest(role=role):
                with self.assertRaises(ValueError):
                    access_policy.update(role, True, 1)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_current_signed_head_and_service_allow_conversion_but_not_settings(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access()
        claims = signed('head:development', 'org:backend', 'service:cv-converter')
        context = _verified_cv_context(claims, 'Bearer synthetic', None)
        self.assertIs(require_cv_access(context), claims)
        with self.assertRaises(HTTPException) as exc:
            require_platform_admin(context)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_current_permission_deny_blocks_conversion_even_with_gateway(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access()
        claims = signed('head:development', 'service:cv-converter')
        context = _verified_cv_context(claims, 'Bearer synthetic', None)
        access_policy.update('head:development', False, 1)
        with self.assertRaises(HTTPException) as exc:
            require_cv_access(context)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_signed_claims_cannot_outlive_manager_revocation(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(claims=['org:backend'])
        with self.assertRaises(HTTPException) as exc:
            _verified_cv_context(signed('head:development', 'service:cv-converter'),
                                 'Bearer synthetic', None)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_identity_mismatch_or_service_revocation_fail_closed(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access()
        for claims in (signed('head:development', 'service:cv-converter', actor=99),
                       signed('head:development'),
                       signed('backend-head', 'service:cv-converter')):
            with self.subTest(claims=claims):
                with self.assertRaises(HTTPException) as exc:
                    ctx = _verified_cv_context(claims, 'Bearer synthetic', None)
                    require_cv_access(ctx)
                self.assertEqual(exc.exception.status_code, 403)
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(allowed=False)
        with self.assertRaises(HTTPException) as exc:
            _verified_cv_context(signed('head:development', 'service:cv-converter'),
                                 'Bearer synthetic', None)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_nonproduction_head_not_granted_by_title_or_untrusted_role(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(
            roles=['legal-head'], claims=['head:legal'])
        # Signed service gate can be separately granted by an administrator,
        # but only the local role policy decides business conversion.
        context = _verified_cv_context(signed('head:legal', 'service:cv-converter'),
                                       'Bearer synthetic', None)
        access_policy.update('head:legal', False, 1)  # explicit local deny
        with self.assertRaises(HTTPException) as exc:
            require_cv_access(context)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_admin_requires_live_role_even_when_signed_claims_present(self, client_cls):
        claims = signed('platform-admin', 'special:platform-admin', 'service:cv-converter')
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(
            roles=['platform-admin'], claims=['special:platform-admin'])
        context = _verified_cv_context(claims, 'Bearer synthetic', None)
        self.assertIs(require_platform_admin(context), claims)
        self.assertIs(require_cv_access(context), claims)
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(
            roles=[], claims=['org:backend'])
        with self.assertRaises(HTTPException) as exc:
            _verified_cv_context(claims, 'Bearer synthetic', None)
        self.assertEqual(exc.exception.status_code, 403)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'false'})
    @patch('app.main.httpx.Client')
    def test_legacy_reads_current_roles_and_no_unsigned_name_shortcut(self, client_cls):
        client_cls.return_value.__enter__.return_value.get.return_value = live_access()
        claims = {'realm_access': {'roles': ['unmanaged-head']}}
        ctx = _verified_cv_context(claims, 'Bearer synthetic', None)
        self.assertTrue(access_policy.can_convert(ctx['roles']))
        self.assertIs(require_cv_access(ctx), claims)

    @patch.dict(os.environ, {'IRLIX_AUTHZ_JWT_ENABLED': 'true'})
    @patch('app.main.httpx.Client')
    def test_preview_and_employees_unavailability_fail_closed(self, client_cls):
        claims = signed('head:development', 'service:cv-converter')
        with self.assertRaises(HTTPException) as exc:
            _verified_cv_context(claims, 'Bearer synthetic', 'development-head')
        self.assertEqual(exc.exception.status_code, 403)
        client_cls.return_value.__enter__.return_value.get.return_value = live_access(status=503)
        with self.assertRaises(HTTPException) as exc:
            _verified_cv_context(claims, 'Bearer synthetic', None)
        self.assertEqual(exc.exception.status_code, 503)

    def test_fastapi_routes_bind_local_cv_permission_vs_admin(self):
        actual = {route.path: route.dependant.dependencies[0].call
                  for route in app.routes
                  if getattr(route, 'dependant', None)
                  and route.dependant.dependencies}
        for path in ('/api/parse', '/api/render/docx', '/api/render/pdf'):
            self.assertIs(actual[path], require_cv_access)
        for path in ('/api/settings', '/api/settings/test', '/api/permissions'):
            self.assertIs(actual[path], require_platform_admin
                          if path != '/api/permissions' else require_platform_admin)


class JwtValidationTest(unittest.TestCase):
    def test_unrelated_raw_role_is_not_promoted_into_platform_admin(self):
        self.assertNotIn('platform-admin', _realm_roles(
            {'realm_access': {'roles': ['platform_admin', 'service:cv-converter']}}))
        self.assertIn('platform-admin',
                      _employees_access_roles({'data': {'roles': ['platform_admin']}}))


if __name__ == '__main__':
    unittest.main()
