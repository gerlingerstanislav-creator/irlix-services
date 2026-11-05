from __future__ import annotations

import asyncio
import os
import sqlite3
import time
from typing import Literal

from pydantic import BaseModel

import httpx
import jwt
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile
from fastapi.responses import Response
from jwt import PyJWKClient

from . import access_policy
from .extractor import UnsupportedSourceError, extract_text
from .models import CanonicalCv, ParseMetrics, ParseResponse
from .providers import build_provider
from .renderer import render_docx, render_pdf
from .settings import ProviderSettingsUpdate, load_settings, public_settings, save_settings

app = FastAPI(title='IRLIX CV Converter', version='0.5.0')

MAX_SOURCE_BYTES = int(os.getenv('CV_MAX_SOURCE_BYTES', str(15 * 1024 * 1024)))
KEYCLOAK_INTERNAL_URL = os.getenv('KEYCLOAK_INTERNAL_URL', 'http://keycloak:8080/keycloak/auth').rstrip('/')
KEYCLOAK_REALM = os.getenv('KEYCLOAK_REALM', 'irlix')
KEYCLOAK_ISSUER = os.getenv('KEYCLOAK_ISSUER', 'http://localhost/keycloak/auth/realms/irlix')
KEYCLOAK_CLIENT_ID = os.getenv('KEYCLOAK_CLIENT_ID', 'irlix-services-web')
EMPLOYEES_ACCESS_URL = os.getenv('CV_EMPLOYEES_ACCESS_URL', 'http://employees:8000/api/access/me')
_jwks = PyJWKClient(f'{KEYCLOAK_INTERNAL_URL}/realms/{KEYCLOAK_REALM}/protocol/openid-connect/certs')
_gigachat_request_semaphore = asyncio.Semaphore(1)


def require_user(authorization: str | None = Header(default=None)) -> dict:
    if not authorization or not authorization.lower().startswith('bearer '):
        raise HTTPException(status_code=401, detail='Bearer token required')
    token = authorization.split(' ', 1)[1].strip()
    try:
        signing_key = _jwks.get_signing_key_from_jwt(token)
        claims = jwt.decode(
            token,
            signing_key.key,
            algorithms=['RS256'],
            issuer=KEYCLOAK_ISSUER,
            options={'verify_aud': False},
        )
    except Exception as exc:
        raise HTTPException(status_code=401, detail='Invalid authentication token') from exc
    if claims.get('azp') != KEYCLOAK_CLIENT_ID:
        raise HTTPException(status_code=401, detail='Unexpected token client')
    return claims


def _normalize_roles(values) -> set[str]:
    if not isinstance(values, list):
        return set()
    return {
        str(role).strip().lower().replace('_', '-')
        for role in values
        if str(role).strip()
    }


def _realm_roles(claims: dict) -> set[str]:
    # JWT role names are authorization keys, not human-readable aliases.
    # Never turn an unrelated Keycloak role such as platform_admin into
    # the Employees-owned canonical platform-admin capability.
    values = claims.get('realm_access', {}).get('roles', [])
    return {role for role in values if isinstance(role, str)} if isinstance(values, list) else set()


def _employees_access_roles(payload: dict | None) -> set[str]:
    if not isinstance(payload, dict):
        return set()
    access = payload.get('data', payload)
    if not isinstance(access, dict):
        return set()
    return _normalize_roles(access.get('roles', []))


def _verified_cv_context(
    claims: dict,
    authorization: str | None,
    role_preview: str | None,
) -> dict:
    """Validate current signed identity and Employees service admission.

    CV owns cv.convert authorization locally. Employees does not decide that
    business permission: it only supplies current canonical identity roles
    and the transitional service:cv-converter gateway.
    """
    if isinstance(role_preview, str) and role_preview.strip():
        raise HTTPException(status_code=403, detail='CV operations are unavailable in role preview')
    if not isinstance(authorization, str) or not authorization.lower().startswith('bearer '):
        raise HTTPException(status_code=401, detail='Bearer token required')
    signed = _realm_roles(claims)
    jwt_mode = os.getenv('IRLIX_AUTHZ_JWT_ENABLED', 'false').lower() in {'true', '1', 'yes'}
    if jwt_mode and 'service:cv-converter' not in signed:
        raise HTTPException(status_code=403, detail='CV service entitlement absent')

    # Like Clients/Timesheets local V2, read current Employees identity for
    # revocation, NOT for the CV permission matrix. An expired head assignment
    # cannot be resurrected by a still-valid signed token.
    try:
        with httpx.Client(timeout=5.0) as client:
            response = client.get(
                EMPLOYEES_ACCESS_URL,
                headers={'Authorization': authorization, 'Accept': 'application/json'},
            )
    except httpx.HTTPError as exc:
        raise HTTPException(status_code=503, detail='Current identity unavailable') from exc
    if response.status_code == 401:
        raise HTTPException(status_code=401, detail='Authentication required')
    if response.status_code == 403:
        raise HTTPException(status_code=403, detail='Employees access denied')
    if response.status_code != 200:
        raise HTTPException(status_code=503, detail='Current identity unavailable')
    try:
        payload = response.json()
    except ValueError as exc:
        raise HTTPException(status_code=503, detail='Malformed identity response') from exc
    live = payload.get('data', payload) if isinstance(payload, dict) else None
    if not isinstance(live, dict):
        raise HTTPException(status_code=503, detail='Malformed identity response')
    services = live.get('services')
    actor_id = live.get('employee_id')
    if (not isinstance(services, dict)
            or services.get('cv-converter') is not True
            or type(actor_id) is not int or actor_id <= 0):
        raise HTTPException(status_code=403, detail='CV service access denied')

    canonical = live.get('role_claims')
    if not isinstance(canonical, list) or not all(isinstance(x, str) for x in canonical):
        raise HTTPException(status_code=503, detail='Current role assignments unavailable')
    live_claims = set(canonical)
    verified = {role for role in live_claims
                if access_policy.IDENTITY_RE.fullmatch(role)}
    live_roles = live.get('roles')
    if not isinstance(live_roles, list):
        raise HTTPException(status_code=503, detail='Current role assignments unavailable')
    live_production_heads = live.get('production_head_roles')
    if not isinstance(live_production_heads, list) or not all(
        isinstance(role, str) and role.startswith('head:')
        and access_policy.IDENTITY_RE.fullmatch(role)
        for role in live_production_heads
    ):
        raise HTTPException(status_code=503, detail='Current production head identities unavailable')
    active_admin = 'platform-admin' in live_roles

    if jwt_mode:
        identity_ids = {role for role in signed if role.startswith('employee-id:')}
        if identity_ids != {f'employee-id:{actor_id}'}:
            raise HTTPException(status_code=403, detail='Signed employee identity mismatch')
        signed_canonical = {role for role in signed
                            if access_policy.IDENTITY_RE.fullmatch(role)}
        if not signed_canonical.issubset(live_claims):
            raise HTTPException(status_code=403, detail='Organizational role was revoked')
        verified = signed_canonical  # Never grant based on unsigned live-only roles.
        active_admin = active_admin and bool(
            {'platform-admin', 'special:platform-admin'} & signed)
    return {
        'employee_id': actor_id, 'roles': verified,
        'production_heads': verified.intersection(live_production_heads),
        'admin': active_admin, 'claims': claims,
    }


def require_cv_service(
    claims: dict = Depends(require_user),
    authorization: str | None = Header(default=None),
    x_irlix_role_preview: str | None = Header(default=None),
) -> dict:
    return _verified_cv_context(claims, authorization, x_irlix_role_preview)


def require_cv_access(context: dict = Depends(require_cv_service)) -> dict:
    try:
        allowed = access_policy.can_convert(context['roles'], admin=context['admin'],
                                            production_heads=context['production_heads'])
    except (OSError, ValueError, sqlite3.Error) as exc:
        raise HTTPException(status_code=503, detail='CV permission matrix unavailable') from exc
    if not allowed:
        raise HTTPException(status_code=403, detail='CV conversion permission denied')
    return context['claims']


def require_platform_admin(context: dict = Depends(require_cv_service)) -> dict:
    if not context['admin']:
        raise HTTPException(status_code=403, detail='Platform administrator required')
    return context['claims']


class CvRoleRule(BaseModel):
    role: str
    allowed: bool


class CvRoleRemoval(BaseModel):
    role: str


@app.get('/api/permissions/me')
def my_permissions(context: dict = Depends(require_cv_service)):
    try:
        can_convert = access_policy.can_convert(context['roles'], admin=context['admin'],
                                            production_heads=context['production_heads'])
    except (OSError, ValueError, sqlite3.Error) as exc:
        raise HTTPException(status_code=503, detail='CV permission matrix unavailable') from exc
    return {'data': {'permissions': {
        'cv.convert': {'allowed': can_convert, 'scope': 'all' if can_convert else 'none'},
    }, 'can_manage': context['admin']}}


@app.get('/api/permissions')
def permission_matrix(_admin: dict = Depends(require_platform_admin)):
    try:
        return {'data': {'permissions': [access_policy.PERMISSION],
                         'scopes': ['none', 'all'], 'matrix': access_policy.matrix()}}
    except (OSError, ValueError, sqlite3.Error) as exc:
        raise HTTPException(status_code=503, detail='CV permission matrix unavailable') from exc


@app.put('/api/permissions')
def update_permission_rule(payload: CvRoleRule,
                           context: dict = Depends(require_cv_service)):
    if not context['admin']:
        raise HTTPException(status_code=403, detail='Platform administrator required')
    try:
        access_policy.update(payload.role, payload.allowed, context['employee_id'])
        return {'data': {'matrix': access_policy.matrix()}}
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except (OSError, sqlite3.Error) as exc:
        raise HTTPException(status_code=503, detail='CV permission matrix unavailable') from exc


@app.delete('/api/permissions')
def delete_permission_rule(payload: CvRoleRemoval,
                           context: dict = Depends(require_cv_service)):
    if not context['admin']:
        raise HTTPException(status_code=403, detail='Platform administrator required')
    try:
        access_policy.remove(payload.role, context['employee_id'])
        return {'data': {'matrix': access_policy.matrix()}}
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except (OSError, sqlite3.Error) as exc:
        raise HTTPException(status_code=503, detail='CV permission matrix unavailable') from exc


@app.get('/api/health')
def health():
    settings = load_settings()
    provider = settings.get('provider', 'local')
    provider_settings = settings.get(provider if provider != 'mws' else 'openai_compatible', {})
    return {
        'status': 'ok',
        'provider': provider,
        'model': provider_settings.get('model'),
    }


@app.get('/api/health/llm')
async def llm_health():
    try:
        return await build_provider().health()
    except Exception as exc:
        raise HTTPException(status_code=503, detail=f'LLM provider is not ready: {exc}') from exc


@app.get('/api/settings')
def get_settings(_user: dict = Depends(require_platform_admin)):
    return public_settings()


@app.put('/api/settings')
def update_settings(payload: ProviderSettingsUpdate, _user: dict = Depends(require_platform_admin)):
    provider = payload.provider.strip().lower()
    if provider not in {'local', 'gigachat', 'yandex', 'openai_compatible', 'mws'}:
        raise HTTPException(status_code=422, detail='Unsupported LLM provider')
    payload.provider = provider
    try:
        saved = save_settings(payload)
    except OSError as exc:
        raise HTTPException(status_code=500, detail=f'Cannot persist CV settings: {exc}') from exc
    return public_settings(saved)


@app.post('/api/settings/test')
async def test_settings(_user: dict = Depends(require_platform_admin)):
    try:
        result = await build_provider().health()
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f'Provider connection failed: {exc}') from exc
    return result


async def _extract_with_provider(provider, source_text: str):
    # Personal GigaChat API allows one concurrent inference stream. Queue only
    # GigaChat parses; local/Yandex/OpenAI-compatible providers remain parallel.
    if provider.name == 'gigachat':
        async with _gigachat_request_semaphore:
            return await provider.extract(source_text)
    return await provider.extract(source_text)


@app.post('/api/parse', response_model=ParseResponse)
async def parse_cv(file: UploadFile = File(...), _user: dict = Depends(require_cv_access)):
    started = time.perf_counter()
    data = await file.read(MAX_SOURCE_BYTES + 1)
    if len(data) > MAX_SOURCE_BYTES:
        raise HTTPException(status_code=413, detail='CV file is too large')

    extraction_started = time.perf_counter()
    try:
        source_text = extract_text(file.filename or 'cv', data)
    except UnsupportedSourceError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    extraction_ms = round((time.perf_counter() - extraction_started) * 1000)

    try:
        provider = build_provider()
        cv, provider_metrics = await _extract_with_provider(provider, source_text)
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f'LLM extraction failed: {exc}') from exc

    total_ms = round((time.perf_counter() - started) * 1000)
    metrics = ParseMetrics(
        provider=provider_metrics['provider'],
        model=provider_metrics.get('model'),
        extraction_ms=extraction_ms,
        preparse_ms=provider_metrics.get('preparse_ms', 0),
        llm_ms=provider_metrics['llm_ms'],
        validation_ms=provider_metrics.get('validation_ms', 0),
        postprocess_ms=provider_metrics.get('postprocess_ms', 0),
        llm_attempts=provider_metrics.get('llm_attempts', 1),
        total_ms=total_ms,
        source_chars=len(source_text),
        input_tokens=provider_metrics.get('input_tokens'),
        output_tokens=provider_metrics.get('output_tokens'),
        expected_work_experience=provider_metrics.get('expected_work_experience'),
        expected_projects=provider_metrics.get('expected_projects'),
    )
    return ParseResponse(cv=cv, metrics=metrics)


@app.post('/api/render/docx')
def download_docx(cv: CanonicalCv, template: Literal['irlix-cv', 'irlix-cv-an'] = 'irlix-cv', _user: dict = Depends(require_cv_access)):
    payload = render_docx(cv, template=template)
    filename = 'IRLIX_CV_AN' if template == 'irlix-cv-an' else 'IRLIX_CV'
    return Response(
        content=payload,
        media_type='application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        headers={'Content-Disposition': f'attachment; filename="{filename}.docx"'},
    )


@app.post('/api/render/pdf')
def download_pdf(cv: CanonicalCv, template: Literal['irlix-cv', 'irlix-cv-an'] = 'irlix-cv', _user: dict = Depends(require_cv_access)):
    started = time.perf_counter()
    payload = render_pdf(cv, template=template)
    filename = 'IRLIX_CV_AN' if template == 'irlix-cv-an' else 'IRLIX_CV'
    render_ms = round((time.perf_counter() - started) * 1000)
    return Response(
        content=payload,
        media_type='application/pdf',
        headers={
            'Content-Disposition': f'attachment; filename="{filename}.pdf"',
            'X-CV-Render-Ms': str(render_ms),
        },
    )