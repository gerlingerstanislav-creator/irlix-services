"""Opt-in Storage V1 API. Separate namespace; never exposes legacy pilot objects.

Every endpoint uses the signed Employees actor via app.main.guard, and a
separate transactional Storage PostgreSQL schema. Disabled by default.
"""
from io import BytesIO
import hashlib
import json
import mimetypes
import os
import sqlite3
from uuid import UUID, uuid4

import boto3
from botocore.exceptions import BotoCoreError, ClientError
from fastapi import APIRouter, File, Form, HTTPException, Query, Request, UploadFile
from fastapi.responses import StreamingResponse, Response
import httpx
from PIL import Image, ImageOps, UnidentifiedImageError
from pydantic import BaseModel, Field
import psycopg

from .access_policy import Principal, Permission, GrantRole, can_access
from .access_service import AccessService, AccessDenied, InvalidOperation
from .metadata_repository import metadata_connection, MetadataRepository

router = APIRouter(prefix="/v1", tags=["storage-v1"])
S3_ROOT = "v1/"
LIMIT = 10 * 1024 * 1024


def _identity(request: Request) -> Principal:
    actor = getattr(request.state, "storage_actor_id", None)
    if not isinstance(actor, int) or actor <= 0:
        raise HTTPException(503, "Подтверждение личности Storage недоступно")
    return Principal(actor, True, bool(getattr(request.state, "storage_platform_admin", False)))


def _employee_active(request: Request, recipient: int) -> bool:
    if recipient <= 0:
        return False
    # Fixed trusted backend URL. No user-supplied URLs or headers.
    url = os.environ.get("STORAGE_EMPLOYEES_RECIPIENT_BASE",
                         "http://employees:8000/_storage_v1_recipient/")
    if not url.startswith("http://employees:8000/_storage_v1_recipient/"):
        # Until a trusted operator configures an explicit allowlist, fail closed.
        return False
    token = request.cookies.get("irlix_storage_v1", "")
    if not token:
        return False
    try:
        with httpx.Client(timeout=3, trust_env=False) as client:
            response = client.get(url + str(recipient),
                                  headers={"Cookie": "irlix_storage_v1=" + token})
        return response.status_code == 204
    except httpx.RequestError:
        return False


def _repo(conn, request):
    return MetadataRepository(conn, lambda employee: _employee_active(request, employee))


def _decorate_entries(conn, entries: list[dict]) -> list[dict]:
    """Attach display metadata only after the caller's per-object ACL checks.

    A bounded, single lookup never expands the result set beyond authorized IDs.
    """
    if not entries:
        return entries
    ids = [item["id"] for item in entries]
    if getattr(conn, "is_sqlite", False):
        marks = ",".join("?" for _ in ids)
        rows = conn.execute(
            "SELECT o.id, o.updated_at, "
            "(SELECT fv.size_bytes FROM storage.file_versions fv "
            "WHERE fv.object_id=o.id ORDER BY fv.created_at DESC LIMIT 1), "
            "(SELECT count(*) FROM storage.grants g "
            "WHERE g.object_id=o.id AND g.revoked_at IS NULL) "
            "FROM storage.objects o WHERE o.id IN (" + marks + ")", ids
        ).fetchall()
    else:
        rows = conn.execute(
            "SELECT o.id::text, o.updated_at, "
            "(SELECT fv.size_bytes FROM storage.file_versions fv "
            "WHERE fv.object_id=o.id ORDER BY fv.created_at DESC LIMIT 1), "
            "(SELECT count(*) FROM storage.grants g "
            "WHERE g.object_id=o.id AND g.revoked_at IS NULL) "
            "FROM storage.objects o WHERE o.id = ANY(%s::uuid[])",
            (ids,),
        ).fetchall()
    by_id = {oid: {"modified": updated.isoformat() if updated else None,
                   "size": size, "shared_count": int(shared_count)}
             for oid, updated, size, shared_count in rows}
    for item in entries:
        item.update(by_id.get(item["id"], {"modified": None, "size": None, "shared_count": 0}))
    return entries


def _normalize_id(value) -> str:
    try:
        return str(UUID(str(value)))
    except (ValueError, AttributeError, TypeError):
        raise HTTPException(422, "Некорректный идентификатор")


def _authorize(repo, principal, object_id, permission):
    obj = _normalize_id(object_id)
    if not can_access(principal, obj, permission,
                      repo.load_objects(obj), repo.load_grants(obj)):
        # Record a scoped failed authorization in an independent transaction:
        # the HTTPException would otherwise roll back the caller's transaction.
        try:
            with metadata_connection() as log_conn:
                log_repo = MetadataRepository(log_conn, lambda _: False)
                _audit(log_repo, principal, obj, "access." + permission.value, "denied")
        except Exception:
            pass  # Still deny; a failed audit must not grant access.
        raise HTTPException(403, "Недостаточно прав на объект")
    return obj


def _name(value):
    if not isinstance(value, str):
        raise HTTPException(422, "Недопустимое название")
    # Reject control characters in the *original* input. Stripping first
    # would silently accept a trailing newline and bypass name validation.
    if any(ord(ch) < 32 or ord(ch) == 127 for ch in value):
        raise HTTPException(422, "Недопустимое название")
    v = value.strip()
    if not v or v in {".", ".."} or len(v.encode("utf-8")) > 160 or any(
        ord(c) < 32 or c in "/\\\x7f" for c in v
    ):
        raise HTTPException(422, "Недопустимое название")
    return v


def _audit(repo, actor, obj_id, action, outcome="allowed"):
    repo.append_audit({"event_id": str(uuid4()), "actor_employee_id": actor.employee_id,
                       "object_id": obj_id, "action": action, "outcome": outcome,
                       "correlation_id": str(uuid4()), "details": {}})


def _bucket():
    return os.environ.get("STORAGE_S3_BUCKET", "irlix-storage-pilot")


def _s3():
    return boto3.client("s3", endpoint_url=os.environ.get("STORAGE_S3_ENDPOINT", "http://storage-s3:8333"),
                        aws_access_key_id=os.environ["STORAGE_S3_ACCESS_KEY"],
                        aws_secret_access_key=os.environ["STORAGE_S3_SECRET_KEY"],
                        region_name=os.environ.get("STORAGE_S3_REGION", "us-east-1"))


class GrantInput(BaseModel):
    employee_id: int = Field(gt=0)
    role: GrantRole


class FolderInput(BaseModel):
    name: str
    parent_id: UUID | None = None


class ObjectInput(BaseModel):
    object_id: UUID


def _parent_for_create(repo, actor, parent_id):
    if parent_id is None:
        return None, actor.employee_id
    identity = _authorize(repo, actor, parent_id, Permission.UPLOAD)
    row = repo.object_summary(identity)
    if not row or row[1] != "folder" or row[6] != "active":
        raise HTTPException(409, "Недоступная родительская папка")
    return identity, row[2]


@router.get("/items")
def list_items(request: Request, parent_id: UUID | None = None, scope: str = "all"):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        if parent_id:
            parent = _authorize(repo, actor, parent_id, Permission.LIST)
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,parent_id::text,display_name,state "
                "FROM storage.objects WHERE parent_id=%s AND state='active' "
                "ORDER BY display_name LIMIT 200", (parent,)
            ).fetchall()
        elif scope == "mine":
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,parent_id::text,display_name,state "
                "FROM storage.objects WHERE parent_id IS NULL AND state='active' "
                "AND owner_employee_id=%s ORDER BY display_name LIMIT 200",
                (actor.employee_id,)
            ).fetchall()
        elif actor.platform_admin:
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,parent_id::text,display_name,state "
                "FROM storage.objects WHERE parent_id IS NULL AND state='active' "
                "ORDER BY display_name LIMIT 200"
            ).fetchall()
        else:
            # Root documents are personal, plus explicit grants on shared roots.
            rows = conn.execute(
                "SELECT DISTINCT o.id::text,o.kind,o.owner_employee_id,o.parent_id::text,"
                "o.display_name,o.state FROM storage.objects o "
                "LEFT JOIN storage.grants g ON g.object_id=o.id AND g.revoked_at IS NULL "
                "AND g.employee_id=%s "
                "WHERE o.parent_id IS NULL AND o.state='active' "
                "AND (o.owner_employee_id=%s OR g.employee_id=%s) "
                "ORDER BY o.display_name LIMIT 200",
                (actor.employee_id, actor.employee_id, actor.employee_id)
            ).fetchall()
        result = []
        for oid, kind, owner, parent, title, state in rows:
            if can_access(actor, oid, Permission.READ, repo.load_objects(oid), repo.load_grants(oid)):
                result.append({"id": oid, "kind": kind, "owner_employee_id": owner,
                               "parent_id": parent, "name": title, "state": state,
                               "can_edit": can_access(actor, oid, Permission.EDIT,
                                                      repo.load_objects(oid), repo.load_grants(oid))})
        result = _decorate_entries(conn, result)
    return {"entries": result}


@router.get("/available")
def available(request: Request):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        rows = conn.execute(
            "SELECT o.id::text,o.kind,o.owner_employee_id,o.display_name "
            "FROM storage.objects o INNER JOIN storage.grants g ON g.object_id=o.id "
            "WHERE g.employee_id=%s AND g.revoked_at IS NULL AND "
            "o.owner_employee_id<>%s AND o.state='active' "
            "ORDER BY o.display_name LIMIT 200",
            (actor.employee_id, actor.employee_id)
        ).fetchall()
        result = [{"id": oid, "kind": kind, "owner_employee_id": owner, "name": title,
                   "can_edit": can_access(actor, oid, Permission.EDIT,
                                          repo.load_objects(oid), repo.load_grants(oid))}
                  for oid, kind, owner, title in rows
                  if can_access(actor, oid, Permission.READ,
                                repo.load_objects(oid), repo.load_grants(oid))]
        result = _decorate_entries(conn, result)
    return {"entries": result}


@router.get("/trash")
def list_trashed(request: Request):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        if actor.platform_admin:
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,display_name,deleted_at "
                "FROM storage.objects WHERE state='trashed' ORDER BY deleted_at DESC LIMIT 200"
            ).fetchall()
        else:
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,display_name,deleted_at "
                "FROM storage.objects WHERE owner_employee_id=%s AND state='trashed' "
                "ORDER BY deleted_at DESC LIMIT 200", (actor.employee_id,)
            ).fetchall()
        result = []
        for oid, kind, owner, title, removed in rows:
            if can_access(actor, oid, Permission.RESTORE, repo.load_objects(oid), repo.load_grants(oid)):
                result.append({"id": oid, "kind": kind, "name": title,
                               "owner_employee_id": owner, "deleted_at": removed})
        result = _decorate_entries(conn, result)
    return {"entries": result}


@router.get("/objects/{object_id}/audit")
def object_history(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        oid = _authorize(repo, actor, object_id, Permission.AUDIT)
        rows = conn.execute(
            "SELECT actor_employee_id,action,outcome,occurred_at "
            "FROM storage.audit_events WHERE object_id=%s ORDER BY occurred_at DESC LIMIT 200",
            (oid,)
        ).fetchall()
    return {"events": [
        {"actor_employee_id": actor_id, "action": action, "outcome": outcome,
         "at": when.isoformat()} for actor_id, action, outcome, when in rows
    ]}


@router.post("/folders")
def create_folder(request: Request, data: FolderInput):
    actor = _identity(request)
    title = _name(data.name)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        parent, owner = _parent_for_create(repo, actor, data.parent_id)
        oid = str(uuid4())
        try:
            conn.execute("INSERT INTO storage.objects "
                         "(id,kind,owner_employee_id,parent_id,display_name) "
                         "VALUES (%s,'folder',%s,%s,%s)",
                         (oid, owner, parent, title))
        except (psycopg.errors.UniqueViolation, sqlite3.IntegrityError):
            raise HTTPException(409, "Название уже существует")
        _audit(repo, actor, oid, "folder.create")
    return {"id": oid, "name": title, "kind": "folder"}


@router.post("/files")
async def upload(request: Request, file: UploadFile = File(...),
                 parent_id: str = Form("")):
    actor = _identity(request)
    title = _name(file.filename)
    data = await file.read(LIMIT + 1)
    await file.close()
    if len(data) > LIMIT:
        raise HTTPException(413, "Файл больше 10 МБ")
    oid = str(uuid4())
    key = S3_ROOT + oid
    mime = mimetypes.guess_type(title)[0] or "application/octet-stream"
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        parent, owner = _parent_for_create(repo, actor, _normalize_id(parent_id) if parent_id else None)
        _require_capacity(repo, owner, len(data))
        try:
            # An object created in S3 but not committed to metadata is
            # intentionally unreachable; operator reconciliation, not deletion.
            _s3().put_object(Bucket=_bucket(), Key=key, Body=data, ContentType=mime,
                             Metadata={"sha256": hashlib.sha256(data).hexdigest()})
        except (BotoCoreError, ClientError):
            raise HTTPException(503, "Не удалось загрузить файл")
        try:
            conn.execute("INSERT INTO storage.objects "
                         "(id,kind,owner_employee_id,parent_id,display_name,s3_key) "
                         "VALUES (%s,'file',%s,%s,%s,%s)",
                         (oid, owner, parent, title, key))
        except (psycopg.errors.UniqueViolation, sqlite3.IntegrityError):
            raise HTTPException(409, "Название уже существует")
        _register_version(repo, actor, oid, key, len(data), hashlib.sha256(data).hexdigest())
        _audit(repo, actor, oid, "file.upload")
    return {"id": oid, "name": title, "kind": "file", "size": len(data)}


@router.get("/preview/{object_id}")
def preview_safe(request: Request, object_id: UUID):
    """Authenticated bounded preview. HTML/SVG/Office macros are never inline."""
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        oid = _authorize(repo, actor, object_id, Permission.READ)
        row = repo.object_summary(oid)
        if not row or row[1] != "file" or row[6] != "active" or not row[5]:
            raise HTTPException(404, "Файл не найден")
        title, key = row[4], row[5]
        if not key.startswith(S3_ROOT):
            raise HTTPException(404, "Файл не найден")
        extension = os.path.splitext(title)[1].lower()
        types = {
            ".pdf": "application/pdf", ".png": "image/png",
            ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
            ".gif": "image/gif", ".webp": "image/webp",
            ".txt": "text/plain", ".csv": "text/plain", ".log": "text/plain",
            ".mp4": "video/mp4", ".webm": "video/webm",
        }
        mime = types.get(extension)
        if mime is None:
            raise HTTPException(415, "Просмотр формата пока недоступен")
        _audit(repo, actor, oid, "file.preview")
    try:
        obj = _s3().get_object(Bucket=_bucket(), Key=key)
        stream = obj["Body"]
        try:
            if int(obj.get("ContentLength", LIMIT + 1)) > LIMIT:
                raise HTTPException(413, "Файл слишком большой для просмотра")
            raw = stream.read(LIMIT + 1)
        finally:
            stream.close()
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось прочитать файл")
    if len(raw) > LIMIT:
        raise HTTPException(413, "Файл слишком большой для просмотра")
    signatures = {
        ".pdf": raw.startswith(b"%PDF-"),
        ".png": raw.startswith(b"\x89PNG\r\n\x1a\n"),
        ".jpg": raw.startswith(b"\xff\xd8\xff"),
        ".jpeg": raw.startswith(b"\xff\xd8\xff"),
        ".gif": raw.startswith((b"GIF87a", b"GIF89a")),
        ".webp": raw.startswith(b"RIFF") and raw[8:12] == b"WEBP",
    }
    if extension in signatures and not signatures[extension]:
        raise HTTPException(415, "Несоответствие содержимого и расширения")
    if extension in {".txt", ".csv", ".log"}:
        try:
            raw.decode("utf-8")
        except UnicodeDecodeError:
            raise HTTPException(415, "Текстовый предпросмотр требует UTF-8")
        # Text is displayed as text/plain, not as active HTML or JS.
        mime = "text/plain; charset=utf-8"
    return Response(raw, media_type=mime, headers={
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
        "Content-Disposition": "inline",
        "Content-Security-Policy": "sandbox; default-src 'none'; base-uri 'none'",
    })



@router.get("/thumbnail/{object_id}")
def thumbnail_safe(request: Request, object_id: UUID):
    """Private, ACL-checked 320x240 tile image, never the original file bytes.

    Reuse preview_safe so the thumbnail gets exactly the same per-object READ,
    active-file, S3 namespace, size, signature and audit checks. No persistent
    copy is written; every request must be authorized even after ACL changes.
    """
    preview = preview_safe(request, object_id)
    if preview.media_type not in {"image/png", "image/jpeg", "image/gif", "image/webp"}:
        raise HTTPException(415, "Миниатюры доступны только для изображений")
    try:
        with Image.open(BytesIO(preview.body)) as original:
            if original.width * original.height > 12_000_000:
                raise HTTPException(413, "Изображение слишком большое для миниатюры")
            original.seek(0)  # Animated GIF/WebP: show first frame only.
            picture = ImageOps.exif_transpose(original)
            picture.thumbnail((320, 240), Image.Resampling.LANCZOS)
            # JPEG cannot store alpha. Composite transparent PNGs on white.
            rgba = picture.convert("RGBA")
            rgb = Image.new("RGB", rgba.size, (255, 255, 255))
            rgb.paste(rgba, mask=rgba.getchannel("A"))
            output = BytesIO()
            rgb.save(output, format="JPEG", quality=78, optimize=True)
    except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError):
        raise HTTPException(415, "Не удалось создать миниатюру")
    return Response(output.getvalue(), media_type="image/jpeg", headers={
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
        "Content-Disposition": "inline",
        "Content-Security-Policy": "sandbox; default-src 'none'; base-uri 'none'",
    })


@router.get("/files/{object_id}")
def download(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        oid = _authorize(repo, actor, object_id, Permission.DOWNLOAD)
        row = repo.object_summary(oid)
        if not row or row[1] != "file" or row[6] != "active" or not row[5]:
            raise HTTPException(404, "Файл не найден")
        title, key = row[4], row[5]
        if not key.startswith(S3_ROOT):
            raise HTTPException(404, "Неверная область хранения")
        _audit(repo, actor, oid, "file.download")
    try:
        obj = _s3().get_object(Bucket=_bucket(), Key=key)
    except (BotoCoreError, ClientError):
        raise HTTPException(503, "Не удалось открыть файл")
    body = obj["Body"]
    def stream():
        try:
            while part := body.read(65536):
                yield part
        finally:
            body.close()
    from urllib.parse import quote
    return StreamingResponse(stream(), media_type="application/octet-stream",
                             headers={"Content-Disposition": "attachment; filename*=UTF-8''" + quote(title, safe=""),
                                      "Cache-Control": "private, no-store",
                                      "X-Content-Type-Options": "nosniff"})


@router.get("/objects/{object_id}/grants")
def list_grants(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        oid = _authorize(repo, actor, object_id, Permission.SHARE)
        rows = conn.execute(
            "SELECT employee_id,role FROM storage.grants "
            "WHERE object_id=%s AND revoked_at IS NULL ORDER BY employee_id LIMIT 200",
            (oid,)
        ).fetchall()
    return {"entries": [{"employee_id": employee, "role": role}
                        for employee, role in rows]}


@router.post("/objects/{object_id}/grants")
def grant(request: Request, object_id: UUID, data: GrantInput):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        service = AccessService(repo, actor, str(uuid4()))
        try:
            service.grant(str(object_id), data.employee_id, data.role)
        except AccessDenied:
            raise HTTPException(403, "Недостаточно прав")
        except InvalidOperation:
            raise HTTPException(422, "Получатель недоступен")
    return {"granted": True}


@router.delete("/objects/{object_id}/grants/{employee_id}")
def revoke(request: Request, object_id: UUID, employee_id: int):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        try:
            AccessService(repo, actor, str(uuid4())).revoke(str(object_id), employee_id)
        except AccessDenied:
            raise HTTPException(403, "Недостаточно прав")
        except InvalidOperation:
            raise HTTPException(422, "Недопустимый получатель")
    return {"revoked": True}


@router.post("/objects/{object_id}/trash")
def trash(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        try:
            AccessService(repo, actor, str(uuid4())).trash(str(object_id))
        except AccessDenied:
            raise HTTPException(403, "Недостаточно прав")
    return {"trashed": True}


@router.post("/objects/{object_id}/restore")
def restore(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        try:
            AccessService(repo, actor, str(uuid4())).restore(str(object_id))
        except AccessDenied:
            raise HTTPException(403, "Недостаточно прав")
    return {"restored": True}


@router.get("/folders/targets")
def folder_targets(request: Request):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        if actor.platform_admin:
            rows = conn.execute(
                "SELECT id::text,owner_employee_id,display_name,parent_id::text "
                "FROM storage.objects WHERE kind='folder' AND state='active' "
                "ORDER BY display_name LIMIT 400"
            ).fetchall()
        else:
            # Include descendants of shared folders, but never trust this query
            # alone: each candidate still passes full ancestor ACL validation.
            rows = conn.execute(
                "SELECT id::text,owner_employee_id,display_name,parent_id::text "
                "FROM storage.objects WHERE kind='folder' AND state='active' "
                "ORDER BY display_name LIMIT 400"
            ).fetchall()
        options = [
            {"id": oid, "owner_employee_id": owner, "name": label, "parent_id": parent}
            for oid, owner, label, parent in rows
            if can_access(actor, oid, Permission.UPLOAD,
                          repo.load_objects(oid), repo.load_grants(oid))
        ]
    return {"entries": options}


@router.get("/search")
def search_objects(request: Request, q: str = Query(..., min_length=2, max_length=80)):
    actor = _identity(request)
    term = "%" + q.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        if actor.platform_admin:
            rows = conn.execute(
                "SELECT id::text,kind,owner_employee_id,parent_id::text,display_name "
                "FROM storage.objects WHERE state='active' AND "
                "display_name ILIKE %s ESCAPE '\\' ORDER BY display_name LIMIT 200", (term,)
            ).fetchall()
        elif getattr(conn, "is_sqlite", False):
            # Search candidates only; the full per-object ancestor ACL check
            # below remains authoritative and prevents leaking other owners.
            rows = conn.execute(
                "SELECT id,kind,owner_employee_id,parent_id,display_name "
                "FROM storage.objects WHERE state='active' AND "
                "display_name LIKE %s ESCAPE '\\' ORDER BY display_name LIMIT 200",
                (term,),
            ).fetchall()
        else:
            rows = conn.execute(
                "WITH RECURSIVE scope AS ("
                " SELECT o.id,o.owner_employee_id,0 AS depth,ARRAY[o.id] AS seen "
                " FROM storage.objects o WHERE o.owner_employee_id=%s OR EXISTS ("
                " SELECT 1 FROM storage.grants g WHERE g.object_id=o.id "
                " AND g.employee_id=%s AND g.revoked_at IS NULL)"
                " UNION ALL "
                " SELECT o.id,o.owner_employee_id,s.depth+1,s.seen||o.id "
                " FROM storage.objects o JOIN scope s ON o.parent_id=s.id "
                " WHERE o.owner_employee_id=s.owner_employee_id AND o.state='active'"
                " AND s.depth<32 AND NOT o.id=ANY(s.seen)"
                ") SELECT DISTINCT o.id::text,o.kind,o.owner_employee_id,"
                "o.parent_id::text,o.display_name FROM storage.objects o "
                "JOIN scope s ON s.id=o.id WHERE o.state='active' "
                "AND o.display_name ILIKE %s ESCAPE '\\' ORDER BY o.display_name LIMIT 200",
                (actor.employee_id, actor.employee_id, term)
            ).fetchall()
        result = []
        for oid, kind, owner, parent, title in rows:
            objects, grants = repo.load_objects(oid), repo.load_grants(oid)
            if can_access(actor, oid, Permission.READ, objects, grants):
                result.append({"id": oid, "kind": kind, "owner_employee_id": owner,
                               "parent_id": parent, "name": title,
                               "can_edit": can_access(actor, oid, Permission.EDIT, objects, grants)})
        result = _decorate_entries(conn, result)
    return {"entries": result}


# P1: logical relocation never alters existing S3 object keys or file bytes.
class MoveInput(BaseModel):
    parent_id: UUID | None = None


class RenameInput(BaseModel):
    name: str


class CopyInput(BaseModel):
    parent_id: UUID | None = None
    name: str | None = None


@router.post("/objects/{object_id}/move")
def move_object(request: Request, object_id: UUID, data: MoveInput):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        oid = _authorize(repo, actor, object_id, Permission.MOVE)
        source = repo.object_summary(oid)
        if not source or source[6] != "active":
            raise HTTPException(404, "Объект не найден")
        if data.parent_id:
            dest = _authorize(repo, actor, data.parent_id, Permission.READ)
            target = repo.object_summary(dest)
            if not target or target[1] != "folder" or target[6] != "active":
                raise HTTPException(409, "Недоступная папка назначения")
            if source[2] != target[2]:
                raise HTTPException(403, "Перемещение между владельцами запрещено")
            if oid in repo.load_objects(dest):
                raise HTTPException(409, "Нельзя переместить папку внутрь себя")
        else:
            dest = None
        try:
            conn.execute("UPDATE storage.objects SET parent_id=%s,updated_at=now(),"
                         "revision=revision+1 WHERE id=%s", (dest, oid))
        except (psycopg.errors.UniqueViolation, sqlite3.IntegrityError):
            raise HTTPException(409, "Объект с таким названием уже существует")
        _audit(repo, actor, oid, "object.move")
    return {"moved": True, "id": oid, "parent_id": dest}


@router.patch("/objects/{object_id}")
def rename_object(request: Request, object_id: UUID, data: RenameInput):
    actor = _identity(request)
    title = _name(data.name)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        oid = _authorize(repo, actor, object_id, Permission.EDIT)
        old = repo.object_summary(oid)
        if not old or old[6] != "active":
            raise HTTPException(404, "Объект не найден")
        if old[1] == "file":
            from pathlib import PurePath
            if PurePath(old[4]).suffix.lower() != PurePath(title).suffix.lower():
                raise HTTPException(422, "Расширение файла нельзя изменять")
        try:
            conn.execute("UPDATE storage.objects SET display_name=%s,updated_at=now(),"
                         "revision=revision+1 WHERE id=%s", (title, oid))
        except (psycopg.errors.UniqueViolation, sqlite3.IntegrityError):
            raise HTTPException(409, "Название уже занято")
        _audit(repo, actor, oid, "object.rename")
    return {"name": title, "id": oid}


@router.post("/objects/{object_id}/copy")
def copy_file(request: Request, object_id: UUID, data: CopyInput):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        oid = _authorize(repo, actor, object_id, Permission.COPY)
        source = repo.object_summary(oid)
        if not source or source[1] != "file" or source[6] != "active":
            raise HTTPException(422, "Копирование папки пока не поддерживается")
        if not source[5] or not source[5].startswith(S3_ROOT):
            raise HTTPException(404, "Недоступный файл")
        parent, owner = _parent_for_create(repo, actor, data.parent_id)
        size_row = conn.execute("SELECT size_bytes FROM storage.file_versions WHERE s3_key=%s",
                                (source[5],)).fetchone()
        if not size_row:
            raise HTTPException(503, "Размер оригинала не подтверждён")
        _require_capacity(repo, owner, size_row[0])
        title = _name(data.name or ("Копия " + source[4]))
        new_id = str(uuid4())
        target_key = S3_ROOT + new_id
        try:
            _s3().copy_object(Bucket=_bucket(), Key=target_key,
                              CopySource={"Bucket": _bucket(), "Key": source[5]})
        except (ClientError, BotoCoreError):
            raise HTTPException(503, "Не удалось скопировать файл")
        try:
            conn.execute("INSERT INTO storage.objects "
                         "(id,kind,owner_employee_id,parent_id,display_name,s3_key) "
                         "VALUES (%s,'file',%s,%s,%s,%s)",
                         (new_id, owner, parent, title, target_key))
        except psycopg.errors.UniqueViolation:
            raise HTTPException(409, "Название уже существует")
        try:
            info = _s3().head_object(Bucket=_bucket(), Key=target_key)
        except (ClientError, BotoCoreError):
            raise HTTPException(503, "Не удалось проверить копию")
        _register_version(repo, actor, new_id, target_key, info.get("ContentLength", 0),
                          info.get("Metadata", {}).get("sha256"))
        _audit(repo, actor, oid, "file.copy.source")
        _audit(repo, actor, new_id, "file.copy.target")
    return {"id": new_id, "name": title, "kind": "file", "copied": True}



def _storage_usage(repo, owner_id):
    row = repo.conn.execute(
        "SELECT COALESCE(SUM(v.size_bytes),0)::bigint "
        "FROM storage.file_versions v JOIN storage.objects o ON o.id=v.object_id "
        "WHERE o.owner_employee_id=%s", (owner_id,)
    ).fetchone()
    return int(row[0] if row else 0)


def _require_capacity(repo, owner_id, extra_bytes):
    if getattr(repo.conn, "is_sqlite", False):
        try:
            minimum = int(os.environ.get("STORAGE_V1_MIN_FREE_BYTES", "2147483648"))
            if minimum < 0:
                raise ValueError("negative minimum")
            path = os.environ.get("STORAGE_V1_SQLITE_PATH", "/app/data/storage-v1.sqlite3")
            fs = os.statvfs(os.path.dirname(path))
            free = fs.f_bavail * fs.f_frsize
        except (ValueError, OSError):
            raise HTTPException(503, "Не удалось проверить запас места для файлов")
        if free - extra_bytes < minimum:
            raise HTTPException(507, "Недостаточно свободного места для безопасной загрузки")
    raw = os.environ.get("STORAGE_V1_QUOTA_BYTES", "0")
    try:
        cap = int(raw)
    except ValueError:
        raise HTTPException(503, "Некорректная конфигурация квот")
    if cap < 0:
        raise HTTPException(503, "Некорректная конфигурация квот")
    if cap and _storage_usage(repo, owner_id) + extra_bytes > cap:
        raise HTTPException(413, "Превышена квота хранилища")


@router.get("/stats")
def storage_statistics(request: Request, employee_id: int | None = None):
    actor = _identity(request)
    owner = actor.employee_id
    if employee_id is not None:
        if employee_id != actor.employee_id and not actor.platform_admin:
            raise HTTPException(403, "Нет доступа к статистике сотрудника")
        owner = employee_id
    if owner <= 0:
        raise HTTPException(422, "Некорректный владелец")
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        used = _storage_usage(repo, owner)
        row = conn.execute(
            "SELECT COUNT(*) FILTER (WHERE kind='file'), "
            "COUNT(*) FILTER (WHERE kind='folder'), "
            "COUNT(*) FILTER (WHERE state='trashed') "
            "FROM storage.objects WHERE owner_employee_id=%s", (owner,)
        ).fetchone()
    raw = os.environ.get("STORAGE_V1_QUOTA_BYTES", "0")
    try:
        max_bytes = max(0, int(raw))
    except ValueError:
        max_bytes = 0
    return {"owner_employee_id": owner, "bytes_with_versions": used,
            "files": row[0], "folders": row[1], "trashed": row[2],
            "quota_bytes": max_bytes or None}


def _register_version(repo, actor, object_id, key, size, digest):
    if not isinstance(digest, str) or len(digest) != 64:
        raise HTTPException(503, "Не подтверждена целостность версии")
    repo.conn.execute(
        "INSERT INTO storage.file_versions "
        "(id,object_id,s3_key,created_by_employee_id,size_bytes,sha256) "
        "VALUES (%s,%s,%s,%s,%s,%s) ON CONFLICT (s3_key) DO NOTHING",
        (str(uuid4()), object_id, key, actor.employee_id, size, digest)
    )


def _register_current_if_missing(repo, actor, object_id, key):
    existing = repo.conn.execute(
        "SELECT 1 FROM storage.file_versions WHERE s3_key=%s", (key,)
    ).fetchone()
    if existing:
        return
    try:
        info = _s3().head_object(Bucket=_bucket(), Key=key)
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Текущая версия не найдена")
    _register_version(repo, actor, object_id, key, info.get("ContentLength", 0),
                      info.get("Metadata", {}).get("sha256"))


@router.get("/objects/{object_id}/versions")
def versions(request: Request, object_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        oid = _authorize(repo, actor, object_id, Permission.READ)
        row = repo.object_summary(oid)
        if not row or row[1] != "file":
            raise HTTPException(404, "Файл не найден")
        values = conn.execute(
            "SELECT id::text,size_bytes,sha256,created_at,s3_key "
            "FROM storage.file_versions WHERE object_id=%s "
            "ORDER BY created_at DESC LIMIT 100", (oid,)
        ).fetchall()
        result = [{"id": vid, "size_bytes": size, "sha256": digest,
                   "created_at": created.isoformat(), "current": key == row[5]}
                  for vid, size, digest, created, key in values]
    return {"versions": result}


@router.post("/objects/{object_id}/versions")
async def replace_version(request: Request, object_id: UUID, file: UploadFile = File(...)):
    actor = _identity(request)
    payload = await file.read(LIMIT + 1)
    await file.close()
    if len(payload) > LIMIT:
        raise HTTPException(413, "Версия больше 10 МБ")
    new_key = S3_ROOT + "versions/" + str(uuid4())
    digest = hashlib.sha256(payload).hexdigest()
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        oid = _authorize(repo, actor, object_id, Permission.EDIT)
        old = repo.object_summary(oid)
        if not old or old[1] != "file" or old[6] != "active" or not old[5].startswith(S3_ROOT):
            raise HTTPException(404, "Файл не найден")
        # A version changes bytes, not the established document type.
        if os.path.splitext(str(file.filename or ""))[1].lower() != os.path.splitext(old[4])[1].lower():
            raise HTTPException(422, "Расширение новой версии должно совпадать с исходным")
        _register_current_if_missing(repo, actor, oid, old[5])
        _require_capacity(repo, old[2], len(payload))
        mime = mimetypes.guess_type(old[4])[0] or "application/octet-stream"
        try:
            _s3().put_object(Bucket=_bucket(), Key=new_key, Body=payload, ContentType=mime,
                             Metadata={"sha256": digest})
        except (BotoCoreError, ClientError):
            raise HTTPException(503, "Не удалось сохранить версию")
        _register_version(repo, actor, oid, new_key, len(payload), digest)
        conn.execute(
            "UPDATE storage.objects SET s3_key=%s,updated_at=now(),revision=revision+1 WHERE id=%s",
            (new_key, oid)
        )
        _audit(repo, actor, oid, "version.replace")
    return {"replaced": True, "id": oid}


@router.post("/objects/{object_id}/versions/{version_id}/restore")
def restore_version(request: Request, object_id: UUID, version_id: UUID):
    actor = _identity(request)
    with metadata_connection() as conn:
        repo = _repo(conn, request)
        repo.lock()
        oid = _authorize(repo, actor, object_id, Permission.EDIT)
        old = repo.object_summary(oid)
        if not old or old[1] != "file" or old[6] != "active":
            raise HTTPException(404, "Файл не найден")
        version = conn.execute(
            "SELECT s3_key,size_bytes,sha256 FROM storage.file_versions "
            "WHERE id=%s AND object_id=%s", (str(version_id), oid)
        ).fetchone()
        if not version:
            raise HTTPException(404, "Версия не найдена")
        _register_current_if_missing(repo, actor, oid, old[5])
        _require_capacity(repo, old[2], version[1])
        new_key = S3_ROOT + "versions/" + str(uuid4())
        if not version[0].startswith(S3_ROOT):
            raise HTTPException(403, "Неверное пространство хранения")
        try:
            _s3().copy_object(Bucket=_bucket(), Key=new_key,
                              CopySource={"Bucket": _bucket(), "Key": version[0]})
        except (BotoCoreError, ClientError):
            raise HTTPException(503, "Не удалось восстановить версию")
        _register_version(repo, actor, oid, new_key, version[1], version[2])
        conn.execute(
            "UPDATE storage.objects SET s3_key=%s,updated_at=now(),revision=revision+1 WHERE id=%s",
            (new_key, oid)
        )
        _audit(repo, actor, oid, "version.restore")
    return {"restored": True, "id": oid}
