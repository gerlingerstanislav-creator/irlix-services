"""Reversible pilot trash metadata. Never deletes or moves S3 file bytes.

The marker is a separate S3 object per logical identity. This is a pilot primitive:
production ACLs, retention and transactional audit require a metadata database.
"""
from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone

from botocore.exceptions import BotoCoreError, ClientError
from fastapi import HTTPException

TRASH_ROOT = "pilot-ui-trash/"
_ALLOWED_STATES = {"deleted", "restored"}
_ALLOWED_KINDS = {"file", "folder"}


def marker_key(kind: str, identity: str) -> str:
    if kind not in _ALLOWED_KINDS or not identity:
        raise HTTPException(422, "Недопустимый объект корзины")
    return TRASH_ROOT + hashlib.sha256((kind + "\n" + identity).encode("utf-8")).hexdigest() + ".json"


def read_marker(client, bucket_name: str, kind: str, identity: str) -> dict | None:
    try:
        obj = client.get_object(Bucket=bucket_name, Key=marker_key(kind, identity))
        try:
            body = obj["Body"].read(8193)
        finally:
            obj["Body"].close()
    except ClientError as err:
        if err.response.get("Error", {}).get("Code") in {"NoSuchKey", "404", "NotFound"}:
            return None
        raise HTTPException(503, "Не удалось проверить состояние файла")
    except BotoCoreError:
        raise HTTPException(503, "Не удалось проверить состояние файла")
    if len(body) > 8192:
        raise HTTPException(503, "Повреждённые метаданные корзины")
    try:
        record = json.loads(body)
    except (ValueError, UnicodeDecodeError):
        raise HTTPException(503, "Повреждённые метаданные корзины")
    if (not isinstance(record, dict) or record.get("kind") != kind
            or record.get("identity") != identity
            or record.get("state") not in _ALLOWED_STATES
            or not isinstance(record.get("name"), str)
            or not isinstance(record.get("deleted_at"), str)):
        raise HTTPException(503, "Повреждённые метаданные корзины")
    return record


def write_marker(client, bucket_name: str, kind: str, identity: str,
                 state: str, name: str, deleted_at: str | None = None) -> dict:
    if state not in _ALLOWED_STATES:
        raise HTTPException(422, "Недопустимое состояние")
    now = datetime.now(timezone.utc).isoformat()
    record = {"kind": kind, "identity": identity, "state": state, "name": name,
              "deleted_at": deleted_at or now, "updated_at": now}
    try:
        client.put_object(Bucket=bucket_name, Key=marker_key(kind, identity),
                          Body=json.dumps(record, ensure_ascii=False).encode("utf-8"),
                          ContentType="application/json")
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось сохранить состояние корзины")
    return record


def list_markers(client, bucket_name: str, continuation: str | None = None) -> dict:
    args = {"Bucket": bucket_name, "Prefix": TRASH_ROOT, "MaxKeys": 200}
    if continuation:
        if len(continuation) > 2048:
            raise HTTPException(422, "Недопустимый маркер")
        args["ContinuationToken"] = continuation
    try:
        page = client.list_objects_v2(**args)
    except (ClientError, BotoCoreError):
        raise HTTPException(503, "Не удалось открыть корзину")
    entries = []
    for obj in page.get("Contents", []):
        key = obj["Key"]
        if not key.startswith(TRASH_ROOT) or not key.endswith(".json"):
            continue
        # Hashed identities cannot be supplied by clients: validate the marker
        # before returning its contents.
        try:
            result = client.get_object(Bucket=bucket_name, Key=key)
            try:
                body = result["Body"].read(8193)
            finally:
                result["Body"].close()
            if len(body) > 8192:
                raise ValueError("oversize marker")
            record = json.loads(body)
            if (not isinstance(record, dict) or record.get("kind") not in _ALLOWED_KINDS
                    or not isinstance(record.get("identity"), str)
                    or marker_key(record["kind"], record["identity"]) != key
                    or record.get("state") not in _ALLOWED_STATES
                    or not isinstance(record.get("name"), str)
                    or not isinstance(record.get("deleted_at"), str)):
                raise ValueError("invalid marker")
        except (ClientError, BotoCoreError, ValueError, UnicodeError):
            raise HTTPException(503, "Повреждённые метаданные корзины")
        if record["state"] == "deleted":
            entries.append({field: record[field] for field in
                            ("kind", "identity", "name", "deleted_at")})
    return {"entries": entries,
            "next": page.get("NextContinuationToken") if page.get("IsTruncated") else None}
