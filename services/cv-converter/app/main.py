from __future__ import annotations

import os
import time
from io import BytesIO

import jwt
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile
from fastapi.responses import Response
from jwt import PyJWKClient

from .extractor import UnsupportedSourceError, extract_text
from .models import CanonicalCv, ParseMetrics, ParseResponse
from .providers import build_provider
from .renderer import render_docx, render_pdf

app = FastAPI(title='IRLIX CV Converter', version='0.2.0')

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
    return {
        'status': 'ok',
        'provider': os.getenv('CV_LLM_PROVIDER', 'local'),
        'local_model': os.getenv('CV_LOCAL_LLM_MODEL', 'Cotype-Nano-Q4_K_M'),
    }


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

    provider = build_provider()
    try:
        cv, provider_metrics = await provider.extract(source_text)
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f'LLM extraction failed: {exc}') from exc

    total_ms = round((time.perf_counter() - started) * 1000)
    metrics = ParseMetrics(
        provider=provider_metrics['provider'],
        model=provider_metrics.get('model'),
        extraction_ms=extraction_ms,
        llm_ms=provider_metrics['llm_ms'],
        total_ms=total_ms,
        source_chars=len(source_text),
        input_tokens=provider_metrics.get('input_tokens'),
        output_tokens=provider_metrics.get('output_tokens'),
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
