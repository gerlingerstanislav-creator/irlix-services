"""Isolated administrator-only Storage pilot API. No business document access.

Nginx provides the first gate, but this service independently verifies the
short-lived Employees cookie on EACH request. No S3 keys reach the browser.
"""
from __future__ import annotations

import hashlib
import json
from io import BytesIO
import mimetypes
import os
import re
import uuid
from urllib.parse import quote

import boto3
from PIL import Image, ImageOps, UnidentifiedImageError
from botocore.config import Config
from botocore.exceptions import BotoCoreError, ClientError
from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, Request, UploadFile
from fastapi.responses import JSONResponse, Response, StreamingResponse
import httpx

app = FastAPI(title="IRLIX Storage Pilot", docs_url=None, redoc_url=None, openapi_url=None)

ROOT = "pilot-ui/"
META_ROOT = "pilot-ui-meta/"  # API-private display labels; S3 objects and their keys stay unchanged.
IMAGE_MIMES = {"image/jpeg", "image/png", "image/gif", "image/webp"}
Image.MAX_IMAGE_PIXELS = 12_000_000
MAX_FILE = 10 * 1024 * 1024
MAX_NAME = 160
MAX_DEPTH = 8
MAX_PREFIX = 700
SAFE_INLINE = {"application/pdf", "image/jpeg", "image/png", "image/gif", "image/webp",
               "text/plain", "text/csv", "application/json", "application/xml"}
IDENTIFIER = re.compile(r"^[0-9a-f]{32}__.+$")
INVALID = re.compile(r"[\\/\x00-\x1f\x7f]")

def name(value: str) -> str:
    value = value.strip()
    if not value or value in {".", ".."} or len(value.encode("utf-8")) > MAX_NAME or INVALID.search(value):
        raise HTTPException(422, "Недопустимое имя файла или папки")
    return value

def prefix(value: str) -> str:
    if not value:
        return ""
    if len(value.encode("utf-8")) > MAX_PREFIX or not value.endswith("/"):
        raise HTTPException(422, "Недопустимый путь")
    parts = value[:-1].split("/")
    if len(parts) > MAX_DEPTH:
        raise HTTPException(422, "Слишком большая глубина папок")
    return "/".join(name(part) for part in parts) + "/"

def file_key(value: str) -> str:
    if not value.startswith(ROOT):
        raise HTTPException(404, "Файл не найден")
    relative = value[len(ROOT):]
    if "/" in relative:
        folder, leaf = relative.rsplit("/", 1)
        prefix(folder + "/")
    else:
        leaf = relative
    if not IDENTIFIER.fullmatch(leaf) or leaf.endswith("__"):
        raise HTTPException(404, "Файл не найден")
    name(leaf[34:])
    return value

def s3():
    return boto3.client("s3",
                        endpoint_url=os.environ.get("STORAGE_S3_ENDPOINT", "http://storage-s3:8333"),
                        region_name=os.environ.get("STORAGE_S3_REGION", "us-east-1"),
                        aws_access_key_id=os.environ["STORAGE_S3_ACCESS_KEY"],
                        aws_secret_access_key=os.environ["STORAGE_S3_SECRET_KEY"],
                        config=Config(signature_version="s3v4", s3={"addressing_style": "path"},
                                      connect_timeout=3, read_timeout=15, retries={"max_attempts": 2}))

def bucket():
    return os.environ.get("STORAGE_S3_BUCKET", "irlix-storage-pilot")

async def guard(request: Request):
    token = request.cookies.get("irlix_storage_pilot", "")
    if not token or len(token) > 8192:
        raise HTTPException(401, "Войдите через платформу")
    try:
        async with httpx.AsyncClient(timeout=3, trust_env=False) as client:
            resp = await client.get(
                os.environ.get("STORAGE_EMPLOYEES_GATE", "http://employees:8000/_storage_pilot_access"),
                headers={"Cookie": "irlix_storage_pilot=" + token})
        if resp.status_code != 204:
            raise HTTPException(401 if resp.status_code == 401 else 403, "Нет доступа к хранилищу")
    except httpx.RequestError:
        raise HTTPException(503, "Не удалось проверить доступ")
    if request.method not in ("GET", "HEAD", "OPTIONS"):
        # Cookie authentication must not allow cross-site forms or scripts to mutate S3.
        expected = os.environ.get("STORAGE_PUBLIC_ORIGIN", "http://services.lan")
        if request.headers.get("Origin") != expected or request.headers.get("X-Irlix-Storage-Request") != "1":
            raise HTTPException(403, "Недопустимый источник запроса")

@app.get("/health")
def health():
    # Internal-only liveness; never disclose bucket, keys or user records.
    return {"status": "ok"}

def _metadata_key(folder: str) -> str:
    # Separate namespace: it cannot appear in pilot-ui/ browsing or be read through /files.
    return META_ROOT + hashlib.sha256((ROOT + folder).encode("utf-8")).hexdigest() + ".json"


def _aliases(client, folder: str) -> dict[str, str]:
    try:
        obj = client.get_object(Bucket=bucket(), Key=_metadata_key(folder))
        try:
            raw = obj["Body"].read(64 * 1024 + 1)
        finally:
            obj["Body"].close()
        if len(raw) > 64 * 1024:
            raise ValueError("Metadata manifest too large")
        result = json.loads(raw)
        if not isinstance(result, dict) or any(not isinstance(k, str) or not isinstance(v, str)
                                               for k, v in result.items()):
            raise ValueError("Invalid metadata manifest")
        return result
    except ClientError as err:
        if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404", "NotFound"}:
            return {}
        raise HTTPException(503, "Метаданные хранилища недоступны")
    except (BotoCoreError, ValueError, UnicodeError):
        raise HTTPException(503, "Метаданные хранилища повреждены или недоступны")


def _breadcrumbs(client, folder: str) -> list[dict]:
    parts = folder.rstrip("/").split("/") if folder else []
    parent = ""
    result = []
    for part in parts:
        path = parent + part + "/"
        labels = _aliases(client, parent)
        result.append({"path": path, "name": labels.get("folder:" + path, part)})
        parent = path
    return result


def _set_alias(client, folder: str, item_key: str, value: str):
    labels = _aliases(client, folder)
    labels[item_key] = value
    raw = json.dumps(labels, ensure_ascii=False).encode("utf-8")
    if len(raw) > 64 * 1024:
        raise HTTPException(422, "Превышено ограничение метаданных папки")
    try:
        client.put_object(Bucket=bucket(), Key=_metadata_key(folder), Body=raw,
                          ContentType="application/json")
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось сохранить название")


@app.get("/items", dependencies=[Depends(guard)])
def items(folder: str = "", continuation: str | None = None):
    path = ROOT + prefix(folder)
    args = {"Bucket": bucket(), "Prefix": path, "Delimiter": "/", "MaxKeys": 200}
    if continuation:
        if len(continuation) > 2048:
            raise HTTPException(422, "Недопустимый маркер")
        args["ContinuationToken"] = continuation
    client = s3()
    try:
        result = client.list_objects_v2(**args)
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Хранилище временно недоступно")
    aliases = _aliases(client, folder)
    folders = [{"name": aliases.get("folder:" + obj["Prefix"][len(ROOT):],
                                    obj["Prefix"][len(path):-1]),
                "path": obj["Prefix"][len(ROOT):]}
               for obj in result.get("CommonPrefixes", [])
               if obj["Prefix"] != path and obj["Prefix"].startswith(path)]
    files = []
    for obj in result.get("Contents", []):
        key = obj["Key"]
        leaf = key[len(path):]
        if not IDENTIFIER.fullmatch(leaf) or "/" in leaf:
            continue
        files.append({"key": key, "name": aliases.get("file:" + key, leaf[34:]), "size": obj["Size"],
                      "modified": obj["LastModified"].isoformat() if obj.get("LastModified") else None,
                      "kind": "file"})
    return {"folder": folder, "folders": folders, "files": files,
            "breadcrumbs": _breadcrumbs(client, folder),
            "next": result.get("NextContinuationToken") if result.get("IsTruncated") else None}

@app.post("/folders", dependencies=[Depends(guard)])
def add_folder(folder: str = Form(""), folder_name: str = Form(...)):
    destination = ROOT + prefix(folder) + name(folder_name) + "/"
    # A marker is used so an empty folder remains visible.
    try:
        s3().put_object(Bucket=bucket(), Key=destination + ".irlix-folder", Body=b"")
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось создать папку")
    return {"path": destination[len(ROOT):]}

@app.post("/files", dependencies=[Depends(guard)])
async def upload(folder: str = Form(""), file: UploadFile = File(...)):
    dest = ROOT + prefix(folder)
    filename = name(file.filename or "")
    data = await file.read(MAX_FILE + 1)
    await file.close()
    if len(data) > MAX_FILE:
        raise HTTPException(413, "Файл больше 10 МБ")
    key = dest + uuid.uuid4().hex + "__" + filename
    mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
    # Never accept a browser-supplied HTML/script Content-Type for inline preview.
    try:
        s3().put_object(Bucket=bucket(), Key=key, Body=data, ContentType=mime,
                        Metadata={"sha256": hashlib.sha256(data).hexdigest()})
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось загрузить файл")
    return {"name": filename, "key": key, "size": len(data)}

@app.post("/rename", dependencies=[Depends(guard)])
def rename(kind: str = Form(...), key: str = Form(""), path: str = Form(""),
           new_name: str = Form(...)):
    desired = name(new_name)
    if kind == "file":
        identity = file_key(key)
        leaf = identity.rsplit("/", 1)[-1][34:]
        relative = identity[len(ROOT):]
        folder = relative.rsplit("/", 1)[0] + "/" if "/" in relative else ""
        if os.path.splitext(leaf)[1].lower() != os.path.splitext(desired)[1].lower():
            raise HTTPException(422, "При переименовании файла нельзя менять расширение")
        label_key = "file:" + identity
        client = s3()
        try:
            client.head_object(Bucket=bucket(), Key=identity)
        except ClientError as err:
            if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404", "NotFound"}:
                raise HTTPException(404, "Файл не найден")
            raise HTTPException(503, "Не удалось проверить файл")
        except BotoCoreError:
            raise HTTPException(503, "Не удалось проверить файл")
    elif kind == "folder":
        relative = prefix(path)
        if not relative:
            raise HTTPException(422, "Корневая папка не переименовывается")
        folder = relative.rsplit("/", 2)[0] + "/" if relative.count("/") > 1 else ""
        label_key = "folder:" + relative
        client = s3()
        try:
            client.head_object(Bucket=bucket(), Key=ROOT + relative + ".irlix-folder")
        except ClientError as err:
            if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404", "NotFound"}:
                raise HTTPException(404, "Папка не найдена")
            raise HTTPException(503, "Не удалось проверить папку")
        except BotoCoreError:
            raise HTTPException(503, "Не удалось проверить папку")
    else:
        raise HTTPException(422, "Неизвестный тип объекта")

    # Reject ambiguous names in the same directory without moving or deleting S3 objects.
    continuation = None
    while True:
        current = items(folder, continuation)
        entries = [("folder:" + entry["path"], entry["name"]) for entry in current["folders"]]
        entries += [("file:" + entry["key"], entry["name"]) for entry in current["files"]]
        if any(item_id != label_key and label.casefold() == desired.casefold()
               for item_id, label in entries):
            raise HTTPException(409, "Файл или папка с таким названием уже существует")
        continuation = current["next"]
        if not continuation:
            break
    _set_alias(client, folder, label_key, desired)
    return {"name": desired, "kind": kind}


@app.get("/thumbnails", dependencies=[Depends(guard)])
def thumbnail(key: str = Query(...)):
    identity = file_key(key)
    filename = identity.rsplit("/", 1)[-1][34:]
    if (mimetypes.guess_type(filename)[0] or "") not in IMAGE_MIMES:
        raise HTTPException(415, "Миниатюры доступны только для изображений")
    client = s3()
    try:
        obj = client.get_object(Bucket=bucket(), Key=identity)
        try:
            if obj.get("ContentLength", MAX_FILE + 1) > MAX_FILE:
                raise HTTPException(413, "Изображение слишком большое")
            source = obj["Body"].read(MAX_FILE + 1)
        finally:
            obj["Body"].close()
        if len(source) > MAX_FILE:
            raise HTTPException(413, "Изображение слишком большое")
    except ClientError as err:
        if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404", "NotFound"}:
            raise HTTPException(404, "Файл не найден")
        raise HTTPException(503, "Не удалось загрузить изображение")
    except BotoCoreError:
        raise HTTPException(503, "Не удалось загрузить изображение")

    try:
        with Image.open(BytesIO(source)) as original:
            if original.width * original.height > 12_000_000:
                raise HTTPException(413, "Изображение слишком большое для миниатюры")
            original.seek(0)
            picture = ImageOps.exif_transpose(original)
            picture.thumbnail((320, 240), Image.Resampling.LANCZOS)
            buffer = BytesIO()
            picture.convert("RGB").save(buffer, format="JPEG", quality=78)
    except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError):
        raise HTTPException(415, "Не удалось создать миниатюру")
    return Response(buffer.getvalue(), media_type="image/jpeg",
                    headers={"Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff"})


@app.get("/files", dependencies=[Depends(guard)])
def read_file(key: str = Query(...), preview: bool = False):
    key = file_key(key)
    physical_name = key.rsplit("/", 1)[-1][34:]  # keep original MIME/type for preview safety
    folder = key[len(ROOT):].rsplit("/", 1)[0] + "/" if "/" in key[len(ROOT):] else ""
    filename = _aliases(s3(), folder).get("file:" + key, physical_name)
    mime = mimetypes.guess_type(physical_name)[0] or "application/octet-stream"
    inline = preview and mime in SAFE_INLINE
    try:
        obj = s3().get_object(Bucket=bucket(), Key=key)
    except ClientError as err:
        if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404"}:
            raise HTTPException(404, "Файл не найден")
        raise HTTPException(503, "Не удалось открыть файл")
    except BotoCoreError:
        raise HTTPException(503, "Хранилище временно недоступно")
    body = obj["Body"]

    def stream():
        try:
            while chunk := body.read(64 * 1024):
                yield chunk
        finally:
            body.close()

    disposition = "inline" if inline else "attachment"
    headers = {"Content-Disposition": disposition + "; filename*=UTF-8''" + quote(filename, safe=""),
               "X-Content-Type-Options": "nosniff", "Cache-Control": "no-store"}
    if obj.get("ContentLength") is not None:
        headers["Content-Length"] = str(obj["ContentLength"])
    # No active content as HTML/SVG: these are always downloaded.
    return StreamingResponse(stream(), media_type=mime if inline else "application/octet-stream", headers=headers)
