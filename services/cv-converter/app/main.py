from __future__ import annotations

import os
import time

import jwt
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile
from fastapi.responses import Response
from jwt import PyJWKClient

from .extractor import UnsupportedSourceError, extract_text
from .models import CanonicalCv, ParseMetrics, ParseResponse
from .providers import build_provider
from .renderer import render_docx, render_pdf
from .settings import ProviderSettingsUpdate, load_settings, public_settings, save_settings

app = FastAPI(title='IRLIX CV Converter', version='0.4.0')

MAX_SOURCE_BYTES = int(os.getenv('CV_MAX_SOURCE_BYTES', str(15 * 1024 * 1024)))
KEYCLOAK_INTERNAL_URL = os.getenv('KEYCLOAK_INTERNAL_URL', 'http://keycloak:8080/keycloak/auth').rstrip('/')
KEYCLOAK_REALM = os.getenv('KEYCLOAK_REALM', 'irlix')
KEYCLOAK_ISSUER = os.getenv('KEYCLOAK_ISSUER', 'http://localhost/keycloak/auth/realms/irlix')
KEYCLOAK_CLIENT_ID = os.getenv('KEYCLOAK_CLIENT_ID', 'irlix-services-web')
_jwks = PyJWKClient(f'{KEYCLOAK_INTERNAL_URL}/realms/{KEYCLOAK_REALM}/protocol/openid-connect/certs')


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
    if claims.get('azp') not in {None, KEYCLOAK_CLIENT_ID}:
        raise HTTPException(status_code=401, detail='Unexpected token client')
    return claims


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
def get_settings(_user: dict = Depends(require_user)):
    return public_settings()


@app.put('/api/settings')
def update_settings(payload: ProviderSettingsUpdate, _user: dict = Depends(require_user)):
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
async def test_settings(_user: dict = Depends(require_user)):
    try:
        result = await build_provider().health()
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f'Provider connection failed: {exc}') from exc
    return result


@app.post('/api/parse', response_model=ParseResponse)
async def parse_cv(file: UploadFile = File(...), _user: dict = Depends(require_user)):
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
        cv, provider_metrics = await provider.extract(source_text)
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f'LLM extraction failed: {exc}') from exc

    total_ms = round((time.perf_counter() - started) * 1000)
    metrics = ParseMetrics(
        provider=provider_metrics['provider'],
        model=provider_metrics.get('model'),
        extraction_ms=extraction_ms,
        preparse_ms=provider_metrics.get('preparse_ms', 0),
        llm_ms=provider_metrics['llm_ms'],
        postprocess_ms=provider_metrics.get('postprocess_ms', 0),
        total_ms=total_ms,
        source_chars=len(source_text),
        input_tokens=provider_metrics.get('input_tokens'),
        output_tokens=provider_metrics.get('output_tokens'),
        expected_work_experience=provider_metrics.get('expected_work_experience'),
        expected_projects=provider_metrics.get('expected_projects'),
    )
    return ParseResponse(cv=cv, metrics=metrics)


@app.post('/api/render/docx')
def download_docx(cv: CanonicalCv, _user: dict = Depends(require_user)):
    payload = render_docx(cv)
    return Response(
        content=payload,
        media_type='application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        headers={'Content-Disposition': 'attachment; filename="IRLIX_CV.docx"'},
    )


@app.post('/api/render/pdf')
def download_pdf(cv: CanonicalCv, _user: dict = Depends(require_user)):
    payload = render_pdf(cv)
    return Response(
        content=payload,
        media_type='application/pdf',
        headers={'Content-Disposition': 'attachment; filename="IRLIX_CV.pdf"'},
    )
