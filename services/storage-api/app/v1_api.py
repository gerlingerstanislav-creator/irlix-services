"""Opt-in Storage V1 API. Separate namespace; never exposes legacy pilot objects.

Every endpoint uses the signed Employees actor via app.main.guard, and a
separate transactional Storage PostgreSQL schema. Disabled by default.
"""
from io import BytesIO
import hashlib
import json
import mimetypes
import os
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
def list_items(request: Request, parent_id: UUID | None = None):
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
        except psycopg.errors.UniqueViolation:
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
        except psycopg.errors.UniqueViolation:
            raise HTTPException(409, "Название уже существует")
        _audit(repo, actor, oid, "file.upload")
    return {"id": oid, "name": title, "kind": "file", "size": len(data)}


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
        except psycopg.errors.UniqueViolation:
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
        except psycopg.errors.UniqueViolation:
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
        _audit(repo, actor, oid, "file.copy.source")
        _audit(repo, actor, new_id, "file.copy.target")
    return {"id": new_id, "name": title, "kind": "file", "copied": True}



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
        _register_current_if_missing(repo, actor, oid, old[5])
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
