#!/usr/bin/env python3
"""Provision per-service Laravel keys without losing access to legacy ciphertext.

Run as root on the deployment host, BEFORE docker compose config/up. Never print
keys; keep prior keys only in the private server .env for Laravel decryption.
"""
from __future__ import annotations

import argparse
import base64
import binascii
import os
from pathlib import Path
import secrets
import subprocess
import tempfile

# Multiple processes of one application must share one current encryption key.
SERVICES = {
    "PLATFORM_CORE": ("platform-core", "platform-calendar-sync"),
    "EMPLOYEES": ("employees", "employees-authorization-sync", "employees-events"),
    "VACATIONS": ("vacations",),
    "CLIENTS": ("clients",),
    "TIMESHEETS": ("timesheets", "timesheets-reconcile"),
    "SPECIALISTS": ("specialists",),
    "RECRUITMENT": ("recruitment",),
    "EQUIPMENT": ("equipment",),
}
PLACEHOLDER = "PROVISION_ON_SERVER"


def valid_key(value: str) -> bool:
    if not value.startswith("base64:"):
        return False
    try:
        raw = base64.b64decode(value[7:], validate=True)
    except (ValueError, binascii.Error):
        return False
    return len(raw) == 32


def new_key() -> str:
    return "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii")


def env_values(content: str) -> dict[str, str]:
    result = {}
    for line in content.splitlines():
        if line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        if name in result and (name.endswith("_APP_KEY") or name.endswith("_APP_PREVIOUS_KEYS")):
            raise RuntimeError("Duplicate managed APP_KEY variable in server .env")
        result[name] = value
    return result


def runtime_keys(project: str, aliases: tuple[str, ...]) -> list[str]:
    keys: list[str] = []
    for service in aliases:
        ids = subprocess.run(
            ["docker", "ps", "-a", "--filter", f"label=com.docker.compose.project={project}",
             "--filter", f"label=com.docker.compose.service={service}", "--format", "{{.ID}}"],
            check=True, text=True, capture_output=True,
        ).stdout.splitlines()
        for container in ids:
            response = subprocess.run(
                ["docker", "inspect", "--format", "{{range .Config.Env}}{{println .}}{{end}}", container],
                check=True, text=True, capture_output=True,
            )
            matches = [entry[8:] for entry in response.stdout.splitlines() if entry.startswith("APP_KEY=")]
            if len(matches) != 1 or not valid_key(matches[0]):
                raise RuntimeError(f"Cannot verify existing APP_KEY of {service}; aborting before deployment")
            if matches[0] not in keys:
                keys.append(matches[0])
    return keys


def provision(content: str, project: str, inspector=runtime_keys) -> tuple[str, dict[str, int]]:
    config = env_values(content)
    updated: dict[str, str] = {}
    legacy_counts: dict[str, int] = {}
    for service, aliases in SERVICES.items():
        name = service + "_APP_KEY"
        previous_name = service + "_APP_PREVIOUS_KEYS"
        current = config.get(name, "")
        if current and current != PLACEHOLDER and not valid_key(current):
            raise RuntimeError(f"Invalid {name}; refusing to replace an unknown encryption key")
        before = config.get(previous_name, "")
        previous = [value.strip() for value in before.split(",") if value.strip()]
        if any(not valid_key(value) for value in previous):
            raise RuntimeError(f"Invalid {previous_name}; refusing unsafe key rotation")
        # Fetch existing keys even if the service already has a managed key:
        # a failed/partial rollout may have old containers still running.
        old_keys = inspector(project, aliases)
        if not current or current == PLACEHOLDER:
            current = new_key()
            updated[name] = current
        for legacy in old_keys:
            if legacy != current and legacy not in previous:
                previous.append(legacy)
        if previous:
            normalized = ",".join(previous)
            if before != normalized:
                updated[previous_name] = normalized
        legacy_counts[service] = len(previous)
    if not updated:
        return content, legacy_counts
    lines = content.splitlines(keepends=True)
    consumed: set[str] = set()
    for index, line in enumerate(lines):
        if line.startswith("#") or "=" not in line:
            continue
        key = line.split("=", 1)[0]
        if key in updated:
            lines[index] = key + "=" + updated[key] + ("\n" if line.endswith("\n") else "")
            consumed.add(key)
    if lines and not lines[-1].endswith("\n"):
        lines[-1] += "\n"
    for key, value in updated.items():
        if key not in consumed:
            lines.append(key + "=" + value + "\n")
    return "".join(lines), legacy_counts


def save_atomic(path: Path, content: str) -> None:
    fd, temp_path = tempfile.mkstemp(prefix=".env-keys-", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp_path, path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", default=".env")
    parser.add_argument("--project", default="irlix-services")
    args = parser.parse_args()
    path = Path(args.env_file)
    try:
        old = path.read_text(encoding="utf-8")
        new, counts = provision(old, args.project)
        if new != old:
            save_atomic(path, new)
        elif path.stat().st_mode & 0o077:
            os.chmod(path, 0o600)
        print("Laravel service APP_KEY provisioning verified; values not displayed.")
        for service, count in counts.items():
            print(f"{service}: configured (previous keys: {count})")
    except (OSError, subprocess.CalledProcessError, RuntimeError) as exc:
        # Never stringify Docker errors; their stdout/stderr may contain secrets.
        raise SystemExit("APP_KEY provisioning blocked; inspect server configuration without exposing keys") from None


if __name__ == "__main__":
    main()
