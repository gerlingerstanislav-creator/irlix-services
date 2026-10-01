from __future__ import annotations

import os
import re
import shutil
import time
import uuid
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.responses import FileResponse

from .providers import ProviderResult, get_provider
from .render import render_docx, render_pdf
from .schema import CanonicalCv, ConvertRequest, ConvertResponse, ExtractionMetrics


APP_ROOT = "/api"
RENDER_DIR = Path(os.getenv("CV_RENDER_DIR", "/tmp/cv-converter"))
RENDER_TTL_SECONDS = int(os.getenv("CV_RENDER_TTL_SECONDS", "3600"))
MAX_CHUNK_CHARS = int(os.getenv("CV_LLM_CHUNK_CHARS", "6500"))

app = FastAPI(title="IRLIX CV Converter", version="0.2.0")


def _split_text(text: str) -> list[str]:
    clean = text.replace("\r\n", "\n").strip()
    if len(clean) <= MAX_CHUNK_CHARS:
        return [clean]
    paragraphs = [p.strip() for p in re.split(r"\n{2,}", clean) if p.strip()]
    chunks: list[str] = []
    current = ""
    for paragraph in paragraphs:
        if len(paragraph) > MAX_CHUNK_CHARS:
            parts = [paragraph[i : i + MAX_CHUNK_CHARS] for i in range(0, len(paragraph), MAX_CHUNK_CHARS)]
        else:
            parts = [paragraph]
        for part in parts:
            candidate = f"{current}\n\n{part}".strip() if current else part
            if current and len(candidate) > MAX_CHUNK_CHARS:
                chunks.append(current)
                current = part
            else:
                current = candidate
    if current:
        chunks.append(current)
    return chunks


def _dedupe_strings(items: list[str]) -> list[str]:
    result: list[str] = []
    seen: set[str] = set()
    for item in items:
        normalized = re.sub(r"\s+", " ", item or "").strip()
        key = normalized.casefold()
        if normalized and key not in seen:
            seen.add(key)
            result.append(normalized)
    return result


def _merge_cv(parts: list[CanonicalCv]) -> CanonicalCv:
    if not parts:
        return CanonicalCv()
    base = CanonicalCv()
    for part in parts:
        for field in ("full_name", "position", "commercial_experience", "role_experience"):
            if not getattr(base, field) and getattr(part, field):
                setattr(base, field, getattr(part, field))
        base.summary = _dedupe_strings(base.summary + part.summary)
        base.tools = _dedupe_strings(base.tools + part.tools)
        base.certifications = _dedupe_strings(base.certifications + part.certifications)

        existing_skill_groups = {g.title.casefold(): g for g in base.skill_groups}
        for group in part.skill_groups:
            key = group.title.strip().casefold()
            if not key:
                continue
            if key in existing_skill_groups:
                existing_skill_groups[key].items = _dedupe_strings(existing_skill_groups[key].items + group.items)
            else:
                base.skill_groups.append(group)
                existing_skill_groups[key] = group

        def add_unique(target, incoming, key_fn):
            seen = {key_fn(item) for item in target}
            for item in incoming:
                key = key_fn(item)
                if key not in seen:
                    target.append(item)
                    seen.add(key)

        add_unique(base.education, part.education, lambda x: (x.institution + "|" + (x.specialty or "")).casefold())
        add_unique(base.languages, part.languages, lambda x: x.language.casefold())
        add_unique(base.projects, part.projects, lambda x: (x.project_name + "|" + (x.period or "") + "|" + x.role).casefold())
        add_unique(base.additional_info, part.additional_info, lambda x: (x.title + "|" + x.text[:60]).casefold())

        for field in ("email", "phone", "telegram", "location"):
            if not getattr(base.contacts, field) and getattr(part.contacts, field):
                setattr(base.contacts, field, getattr(part.contacts, field))
        base.contacts.links = _dedupe_strings(base.contacts.links + part.contacts.links)
    return base


def _cleanup_old_renders() -> None:
    RENDER_DIR.mkdir(parents=True, exist_ok=True)
    cutoff = time.time() - RENDER_TTL_SECONDS
    for child in RENDER_DIR.iterdir():
        try:
            if child.stat().st_mtime < cutoff:
                if child.is_dir():
                    shutil.rmtree(child, ignore_errors=True)
                else:
                    child.unlink(missing_ok=True)
        except FileNotFoundError:
            pass


@app.get(f"{APP_ROOT}/health")
async def health():
    provider = get_provider()
    return {
        "status": "ok",
        "provider": provider.name,
        "model": provider.model,
        "chunk_chars": MAX_CHUNK_CHARS,
    }


@app.post(f"{APP_ROOT}/convert", response_model=ConvertResponse)
async def convert(request: ConvertRequest):
    _cleanup_old_renders()
    chunks = _split_text(request.text)
    provider = get_provider()
    extracted: list[CanonicalCv] = []
    total_ms = 0
    model_name = provider.model
    provider_name = provider.name

    try:
        for index, chunk in enumerate(chunks, start=1):
            decorated = f"Часть CV {index} из {len(chunks)}. Извлеки только факты, присутствующие в этой части.\n\n{chunk}"
            result: ProviderResult = await provider.extract(decorated)
            extracted.append(result.cv)
            total_ms += result.inference_ms
            model_name = result.model
            provider_name = result.provider
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"LLM extraction failed: {exc}") from exc

    cv = _merge_cv(extracted)
    render_id = uuid.uuid4().hex
    render_path = RENDER_DIR / render_id
    render_path.mkdir(parents=True, exist_ok=True)
    safe_name = re.sub(r"[^\w.-]+", "_", cv.full_name or Path(request.source_name).stem or "cv", flags=re.UNICODE).strip("_") or "cv"
    docx_path = render_path / f"{safe_name}_IRLIX.docx"

    try:
        render_docx(cv, docx_path)
        pdf_path = render_pdf(docx_path, render_path)
    except Exception as exc:
        shutil.rmtree(render_path, ignore_errors=True)
        raise HTTPException(status_code=500, detail=f"Document render failed: {exc}") from exc

    metrics = ExtractionMetrics(
        provider=provider_name,
        model=model_name,
        chunks=len(chunks),
        input_chars=len(request.text),
        inference_ms=total_ms,
    )
    return ConvertResponse(
        render_id=render_id,
        cv=cv,
        metrics=metrics,
        docx_url=f"/api/cv/render/{render_id}/docx",
        pdf_url=f"/api/cv/render/{render_id}/pdf",
    )


@app.get(f"{APP_ROOT}/render/{{render_id}}/{{kind}}")
async def download(render_id: str, kind: str):
    if not re.fullmatch(r"[0-9a-f]{32}", render_id):
        raise HTTPException(status_code=404, detail="Render not found")
    folder = RENDER_DIR / render_id
    if kind not in {"docx", "pdf"} or not folder.exists():
        raise HTTPException(status_code=404, detail="Render not found")
    matches = list(folder.glob(f"*.{kind}"))
    if not matches:
        raise HTTPException(status_code=404, detail="Render not found")
    media = "application/pdf" if kind == "pdf" else "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    return FileResponse(matches[0], media_type=media, filename=matches[0].name)
