#!/usr/bin/env python3
"""Safely provision the *isolated pilot* Filestash S3 backend on the host.

No business files or credentials are emitted, migrated or overwritten.
Requires root to access the existing Docker named volume. --check never writes.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

PROJECT = "irlix-services"
SERVICE = "storage-web"
BACKEND = "irlix-pilot"


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()


def filestash_volume():
    ids = docker("ps", "-q", "--filter", "label=com.docker.compose.project=" + PROJECT,
                 "--filter", "label=com.docker.compose.service=" + SERVICE).splitlines()
    if len(ids) != 1 or not ids[0]:
        raise RuntimeError("Filestash pilot container is not uniquely running")
    container = json.loads(docker("inspect", ids[0]))[0]
    mounts = [m for m in container["Mounts"] if m.get("Destination") == "/app/data/state"]
    if len(mounts) != 1 or mounts[0].get("Type") != "volume" or not mounts[0].get("Name", "").endswith("_storage_filestash_state"):
        raise RuntimeError("Filestash pilot state is not the expected named Docker volume")
    environment = dict(partition for raw in container["Config"].get("Env", [])
                       if len(partition := raw.partition("=")[::2]) == 2)
    for key in ("AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"):
        if len(environment.get(key, "")) < 12 or environment[key].startswith("PROVISION_ON_SERVER"):
            raise RuntimeError("Filestash S3 server-side credential environment missing")
    if environment.get("CONFIG_ENCRYPT") != "false":
        raise RuntimeError("Filestash config adapter must not try to decrypt template-only middleware data")
    return ids[0], Path(mounts[0]["Source"])


def bucket_from_env(path):
    data = {}
    for raw in Path(path).read_text().splitlines():
        if "=" not in raw or raw.lstrip().startswith("#"):
            continue
        name, value = raw.split("=", 1)
        if name in ("STORAGE_S3_BUCKET",):
            data[name] = value.strip().strip("'\"")
    bucket = data.get("STORAGE_S3_BUCKET", "irlix-storage-pilot")
    if not re.fullmatch(r"[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]", bucket):
        raise RuntimeError("Invalid pilot bucket")
    return bucket


def desired_config(bucket):
    # All values below are nonsecret. The S3 SDK uses AWS_* env in the private
    # Filestash container. No access/secret keys are stored in config.json.
    passthrough = {"strategy": "direct"}
    target = {
        "type": "s3",
        "endpoint": "http://storage-s3:8333",
        "region": "us-east-1",
        "path": "/" + bucket + "/",
    }
    return {
        "connections": [{"type": "s3", "label": BACKEND}],
        "middleware": {
            "identity_provider": {"type": "passthrough",
                                  "params": json.dumps(passthrough, separators=(",", ":"))},
            "attribute_mapping": {
                "related_backend": BACKEND,
                "params": json.dumps({BACKEND: target}, separators=(",", ":")),
            },
        },
    }


def compatible(existing, expected):
    if existing.get("connections") != expected["connections"]:
        return False
    middleware = existing.get("middleware")
    if not isinstance(middleware, dict):
        return False
    provider = middleware.get("identity_provider", {})
    mapping = middleware.get("attribute_mapping", {})
    try:
        return (provider.get("type") == "passthrough"
                and json.loads(provider["params"]).get("strategy") == "direct"
                and mapping.get("related_backend") == BACKEND
                and json.loads(mapping["params"]) == json.loads(expected["middleware"]["attribute_mapping"]["params"]))
    except (KeyError, TypeError, ValueError):
        return False


def ensure_file(path, expected, check):
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Filestash config.json absent or not a regular file: refusing unsafe auto-init")
    source = json.loads(path.read_text())
    if not isinstance(source, dict):
        raise RuntimeError("Filestash config is not an object")
    if compatible(source, expected):
        return False
    if check:
        raise RuntimeError("Filestash pilot S3 backend has not been configured")
    # A separate operator configuration is never overwritten automatically.
    if source.get("connections") not in (None, []):
        raise RuntimeError("Filestash already has a backend; refusing to overwrite it")
    existing_auth = source.get("middleware")
    if existing_auth not in (None, {}):
        raise RuntimeError("Filestash already has authentication mapping; refusing to overwrite it")
    updated = dict(source)
    updated.update(expected)
    # Fail closed if any sensitive key accidentally reaches the persisted config.
    encoded = json.dumps(updated, ensure_ascii=False, indent=2)
    if "AWS_SECRET_ACCESS_KEY" in encoded or "AWS_ACCESS_KEY_ID" in encoded:
        raise RuntimeError("Unexpected credentials reference in persisted config")
    state = path.stat()
    backup = path.with_name("config.json.pre-irlix-pilot.bak")
    if not backup.exists():
        shutil.copy2(path, backup)
        os.chown(backup, state.st_uid, state.st_gid)
        os.chmod(backup, stat.S_IMODE(state.st_mode) & 0o660)
    fd, tempname = tempfile.mkstemp(prefix=".filestash-config-", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as out:
            out.write(encoded + "\n")
            out.flush()
            os.fsync(out.fileno())
        os.chown(tempname, state.st_uid, state.st_gid)
        os.chmod(tempname, stat.S_IMODE(state.st_mode) & 0o660)
        os.replace(tempname, path)
    finally:
        if os.path.exists(tempname):
            os.unlink(tempname)
    return True


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", default="/opt/irlix-services/.env")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args(argv)
    cid, volume = filestash_volume()
    config_path = volume / "config" / "config.json"
    changed = ensure_file(config_path, desired_config(bucket_from_env(args.env_file)), args.check)
    if changed:
        # A filesystem change does not reload Filestash's in-memory config.
        # Restart only the isolated pilot frontend; never business services.
        subprocess.run(["docker", "restart", cid], check=True, capture_output=True)
        check_state = json.loads(config_path.read_text())
        if not compatible(check_state, desired_config(bucket_from_env(args.env_file))):
            raise RuntimeError("Filestash pilot backend did not persist")
        print("[storage] Filestash pilot S3 backend configured, frontend restarted")
    else:
        print("[storage] Filestash pilot S3 backend configuration verified")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        # No secrets, config bodies or credentials in diagnostics.
        print("[storage] Filestash S3 setup failed: " + type(exc).__name__ + ": " + str(exc), file=sys.stderr)
        sys.exit(1)
