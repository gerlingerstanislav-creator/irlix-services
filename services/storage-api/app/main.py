"""Isolated administrator-only Storage pilot API. No business document access.

Nginx provides the first gate, but this service independently verifies the
short-lived Employees cookie on EACH request. No S3 keys reach the browser.
"""
from __future__ import annotations

import hashlib
import mimetypes
import os
import re
import uuid
from urllib.parse import quote

import boto3
from botocore.config import Config
from botocore.exceptions import BotoCoreError, ClientError
from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, Request, UploadFile
from fastapi.responses import JSONResponse, StreamingResponse
import httpx

app = FastAPI(title="IRLIX Storage Pilot", docs_url=None, redoc_url=None, openapi_url=None)

ROOT = "pilot-ui/"
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
        expected = os.environ.get("STORAGE_PUBLIC_ORIGIN", "http://services.lan:8110")
        if request.headers.get("Origin") != expected or request.headers.get("X-Irlix-Storage-Request") != "1":
            raise HTTPException(403, "Недопустимый источник запроса")

@app.get("/health")
def health():
    # Internal-only liveness; never disclose bucket, keys or user records.
    return {"status": "ok"}

@app.get("/items", dependencies=[Depends(guard)])
def items(folder: str = "", continuation: str | None = None):
    path = ROOT + prefix(folder)
    args = {"Bucket": bucket(), "Prefix": path, "Delimiter": "/", "MaxKeys": 200}
    if continuation:
        if len(continuation) > 2048:
            raise HTTPException(422, "Недопустимый маркер")
        args["ContinuationToken"] = continuation
    try:
        result = s3().list_objects_v2(**args)
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Хранилище временно недоступно")
    folders = [{"name": obj["Prefix"][len(path):-1], "path": obj["Prefix"][len(ROOT):]}
               for obj in result.get("CommonPrefixes", [])
               if obj["Prefix"] != path and obj["Prefix"].startswith(path)]
    files = []
    for obj in result.get("Contents", []):
        key = obj["Key"]
        leaf = key[len(path):]
        if not IDENTIFIER.fullmatch(leaf) or "/" in leaf:
            continue
        files.append({"key": key, "name": leaf[34:], "size": obj["Size"],
                      "modified": obj["LastModified"].isoformat() if obj.get("LastModified") else None,
                      "kind": "file"})
    return {"folder": folder, "folders": folders, "files": files,
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

@app.get("/files", dependencies=[Depends(guard)])
def read_file(key: str = Query(...), preview: bool = False):
    key = file_key(key)
    filename = key.rsplit("/", 1)[-1][34:]  # preserve filenames containing double underscores
    mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
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
