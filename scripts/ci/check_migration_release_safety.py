#!/usr/bin/env python3
"""Read-only pre-deploy gate, executed INSIDE the running migration-ops container.

Fail closed: a Docker replacement kills the coordinator's daemon thread and
may interrupt an ongoing migration stage. Only confirmed idle state can pass.
No mutation to snapshots, operations, source/target DBs, or worker queues.
"""
import json
import sqlite3
import sys
from pathlib import Path

OPS = Path("/ops")
DATA = Path("/data/migration.sqlite")
ACTIVE = {"queued", "running"}
class UnsafeState(Exception):
    pass

def read_state(path):
    if not path.is_file():
        raise UnsafeState("missing required runtime state: " + path.name)
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeError):
        raise UnsafeState("cannot read runtime state: " + path.name)
    if not isinstance(value, dict):
        raise UnsafeState("unexpected runtime state: " + path.name)
    return value

def check():
    console = read_state(OPS / "console.json")
    operation = console.get("operation")
    if operation is not None and not isinstance(operation, dict):
        raise UnsafeState("operation has invalid shape")
    if operation and operation.get("status") in ACTIVE:
        raise UnsafeState("migration coordinator operation is active")
    snapshot = read_state(OPS / "status.json")
    if snapshot.get("state") in ACTIVE:
        raise UnsafeState("snapshot or restore operation is active")

    if not DATA.is_file():
        raise UnsafeState("migration metadata database is missing")
    try:
        with sqlite3.connect("file:/data/migration.sqlite?mode=ro", uri=True, timeout=3) as db:
            rows = db.execute(
                "SELECT COUNT(*) FROM migration_runs WHERE status IN ('queued','running')"
            ).fetchone()[0]
    except (sqlite3.Error, OSError):
        raise UnsafeState("cannot verify migration worker metadata")
    if rows:
        raise UnsafeState("migration worker has queued or running runs")

if __name__ == "__main__":
    try:
        check()
    except UnsafeState as exc:
        print("MIGRATION RELEASE BLOCKED: " + str(exc), file=sys.stderr)
        print("Preserved existing processes and persistent data. Verify worker and operation before retrying.", file=sys.stderr)
        sys.exit(2)
    except Exception as exc:
        print("MIGRATION RELEASE BLOCKED: unrecognized runtime check error " + type(exc).__name__, file=sys.stderr)
        sys.exit(3)
    print("MIGRATION RELEASE PREFLIGHT PASS: coordinator, snapshots and worker are idle.")
