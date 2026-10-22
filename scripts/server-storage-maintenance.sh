#!/bin/sh
# Production storage diagnostics and conservative automatic cleanup.
# Never delete containers, named volumes, databases, migration metadata, or
# protected recovery points. Runs from a GitHub Actions SSH session.
set -eu
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
BASE=/opt/irlix-services
section() { printf '\n=== %s ===\n' "$1"; }
size() {
  path="$1"
  if [ -e "$path" ]; then $SUDO du -xsh "$path" 2>/dev/null || true; fi
}
section "Filesystem and top-level consumption (before)"
df -h / /var/lib/docker 2>/dev/null || true
for path in /var/lib/docker /var/lib/docker/overlay2 /var/lib/docker/volumes /var/lib/docker/containers /var/log /var/cache /opt /opt/irlix-services "$BASE/.ci/migration-test-snapshots"; do size "$path"; done

section "Migration backup inventory"
if [ -d "$BASE/.ci/migration-test-snapshots" ]; then
  $SUDO du -xsh "$BASE/.ci/migration-test-snapshots"/* 2>/dev/null || true
fi

section "Docker storage by object class"
$SUDO docker system df -v || true
section "Docker volume disk usage (size only; no volume is removed)"
for v in $($SUDO docker volume ls -q); do
  case "$v" in
    *migration*|*postgres*|*vacation*|*resource*|*finance*|*clients*|*timesheets*)
      point=$($SUDO docker volume inspect -f '{{.Mountpoint}}' "$v" 2>/dev/null || true)
      [ -n "$point" ] && printf '%s: ' "$v" && $SUDO du -sh "$point" 2>/dev/null || true
    ;;
  esac
done

# Avoid racing the release pull/recreate process; disk maintenance is repeated
# nightly if a release is currently in flight.
if ps -eo args | grep -E '[s]h scripts/deploy-from-ghcr.sh|[/]opt/irlix-services/scripts/deploy-from-ghcr.sh' >/dev/null 2>&1; then
  echo "Active deployment detected: report only; skip destructive maintenance."
  exit 0
fi

section "Safe system cache pruning"
# Prune only images not referenced by a container; never --volumes or container prune.
$SUDO docker image prune -af --filter 'until=168h' || true
$SUDO docker builder prune -af --filter 'until=72h' || true
if command -v journalctl >/dev/null 2>&1; then
  $SUDO journalctl --vacuum-time=14d --vacuum-size=512M || true
fi
if command -v apt-get >/dev/null 2>&1; then $SUDO apt-get clean || true; fi

section "Conservative migration recovery-point retention"
# The ops gate confirms NO active migration, checkpoint or rollback.
# If any check fails, preservation wins. Never delete metadata/identity volumes.
safe_to_expire=false
if [ -f "$BASE/scripts/ci/check_migration_release_safety.py" ]; then
  if $SUDO docker compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" exec -T migration-ops python3 scripts/ci/check_migration_release_safety.py >/dev/null 2>&1; then
    safe_to_expire=true
  elif command -v docker-compose >/dev/null 2>&1; then
    if $SUDO docker-compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" exec -T migration-ops python3 scripts/ci/check_migration_release_safety.py >/dev/null 2>&1; then safe_to_expire=true; fi
  fi
fi
if [ "$safe_to_expire" = true ]; then
  $SUDO python3 - "$BASE/.ci/migration-test-snapshots" <<'PY'
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import sys

root = Path(sys.argv[1])
if not root.is_dir() or root.is_symlink():
    print("No recoverable snapshot root; no deletion")
    sys.exit(0)
now = dt.datetime.now(dt.timezone.utc)
groups = {}
# Only well-formed, individually verifiable snapshot directories.
# Unknown layouts, symlinks, nested contents, incomplete manifests are retained.
for manifest in root.glob("*/manifest.json"):
    folder = manifest.parent
    if folder.is_symlink() or manifest.is_symlink() or (folder / ".keep").exists():
        continue
    try:
        info = json.loads(manifest.read_text())
        created = dt.datetime.fromisoformat(str(info.get("created_at_utc", "")).replace("Z", "+00:00"))
        if created.tzinfo is None or created > now:
            continue
        # Only archives produced by our own Employees test-snapshot contract.
        if info.get("snapshot_id") != folder.name or not folder.name.isdecimal():
            continue
        if not (folder / "employees.dump").is_file() or not (folder / "migration-data.tar.gz").is_file():
            continue
        if not info.get("employees_dump_sha256") or not info.get("migration_data_sha256"):
            continue
        groups.setdefault(str(info.get("scope", "employees")), []).append((created, folder))
    except (ValueError, OSError, TypeError, AttributeError):
        continue

for scope, entries in groups.items():
    entries.sort(key=lambda item: item[0], reverse=True)
    for created, folder in entries[2:]:
        age_days = (now - created).days
        if age_days < 30 or (folder / ".keep").exists():
            continue
        # Recheck the directory before removal: never follow symlinks.
        if folder.is_symlink() or folder.parent != root or not folder.is_dir():
            continue
        try:
            shutil.rmtree(folder)
            print(f"Expired documented Employees test snapshot {folder.name} ({age_days} days)")
        except OSError as exc:
            print(f"Preserved {folder.name}: {type(exc).__name__}")
print("Snapshot retention: latest two per scope and everything <30 days preserved; unknown and held snapshots preserved.")
PY
  # Console checkpoints use /snapshots/console/<scope>/<numeric ID>, not the
  # legacy top-level layout. Their repeated metadata.sqlite backups can be
  # gigabytes each. Check server-side state AGAIN under the same exclusive
  # filesystem lock used by checkpoint creation, restore and manual deletion.
  ops_mount=$($SUDO docker volume inspect -f '{{.Mountpoint}}' irlix-services_migration_ops_socket 2>/dev/null || true)
  data_mount=$($SUDO docker volume inspect -f '{{.Mountpoint}}' irlix-services_migration_data 2>/dev/null || true)
  if [ -n "$ops_mount" ] && [ -n "$data_mount" ]; then
    $SUDO python3 - "$BASE/.ci/migration-test-snapshots/console" "$ops_mount" "$data_mount" <<'PY'
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys

root, ops, data = map(Path, sys.argv[1:])
scopes = {"employees": ("employees",), "vacations": ("vacations",),
          "clients": ("clients",), "timesheets": ("timesheets",),
          "all": ("employees", "vacations", "clients", "timesheets")}
min_free = 8 * 1024**3
now = dt.datetime.now(dt.timezone.utc)
if not root.is_dir() or root.is_symlink() or not ops.is_dir() or not data.is_dir():
    print("Console snapshot inspection unavailable; all retained")
    sys.exit(0)
try:
    with (root / ".lock").open("a") as mutex:
        fcntl.flock(mutex, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = json.loads((ops / "console.json").read_text())
        status = json.loads((ops / "status.json").read_text())
        if (not isinstance(state, dict) or not isinstance(status, dict)
                or (state.get("operation") or {}).get("status") in ("running", "queued")
                or status.get("state") in ("running", "queued")):
            raise ValueError("migration operation state not safely idle")
        db_path = data / "migration.sqlite"
        with sqlite3.connect(f"file:{db_path}?mode=ro", uri=True, timeout=3) as db:
            active = db.execute("SELECT COUNT(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]
        if active:
            raise ValueError("migration worker has active runs")

        protected = set()
        # Protect recovery points attached to the current operation, in addition
        # to the two newest verified checkpoints in every contour.
        recent = [state.get("operation")] if isinstance(state.get("operation"), dict) else []
        for op in recent:
            scope = op.get("scope")
            sid = str(op.get("snapshot_id") or "")
            if scope in scopes and sid.isdecimal():
                protected.add((scope, sid))
            for name, value in (op.get("snapshot_ids") or {}).items():
                if name in scopes and str(value).isdecimal():
                    protected.add((name, str(value)))

        expired = []
        counted = 0
        for scope in scopes:
            parent = root / scope
            if not parent.is_dir() or parent.is_symlink():
                continue
            entries = []
            for folder in parent.iterdir():
                if not folder.name.isascii() or not folder.name.isdecimal() or folder.is_symlink():
                    continue
                manifest = folder / "manifest.json"
                if not manifest.is_file() or manifest.is_symlink() or (folder / ".keep").exists():
                    continue
                try:
                    meta = json.loads(manifest.read_text())
                    created = dt.datetime.fromisoformat(str(meta["created_at_utc"]).replace("Z", "+00:00"))
                    if (created.tzinfo is None or created > now
                            or meta.get("service") != scope or str(meta.get("snapshot_id")) != folder.name
                            or tuple(meta.get("schemas", [])) != scopes[scope]
                            or meta.get("verification_version") != 1):
                        continue
                    checksums = meta.get("checksums", {})
                    if (not isinstance(checksums, dict)
                            or not all(x in checksums and (folder / x).is_file()
                                       and not (folder / x).is_symlink()
                                       for x in ("target.dump", "metadata.sqlite"))):
                        continue
                    # Never recursively delete a directory containing symlinks,
                    # unexpected hard links, or any format we don't understand.
                    allowed = {"target.dump", "metadata.sqlite", "migration-credential.key",
                               "vacations-files.tar.gz", "manifest.json", "restored", ".keep"}
                    if any(p.is_symlink() or p.name not in allowed or
                           (p.is_file() and p.stat().st_nlink > 1) for p in folder.iterdir()):
                        continue
                    entries.append((created, folder))
                except (OSError, KeyError, ValueError, TypeError, AttributeError):
                    continue
            entries.sort(reverse=True, key=lambda x: x[0])
            counted += len(entries)
            for created, folder in entries[2:]:
                if ((scope, folder.name) in protected or (folder / ".keep").exists()
                        or (folder / "restored").exists()):
                    continue
                expired.append((created, scope, folder))

        expired.sort(key=lambda x: x[0])
        stat = os.statvfs(root)
        free = stat.f_bavail * stat.f_frsize
        print(f"Console checkpoints: {counted} verifiable; free before: {free//(1024**2)} MiB")
        removed = 0
        for created, scope, folder in expired:
            age_days = (now - created).days
            emergency = free < min_free
            if age_days < 30 and not emergency:
                continue
            # Must be a direct numeric child of the exact allowlisted contour.
            if folder.parent != root / scope or folder.is_symlink() or not folder.is_dir():
                continue
            shutil.rmtree(folder)
            removed += 1
            stat = os.statvfs(root)
            free = stat.f_bavail * stat.f_frsize
            print(f"Removed redundant checkpoint {scope}/{folder.name}; age={age_days}d; free={free//(1024**2)}MiB")
        print(f"Console cleanup complete: removed={removed}; free={free//(1024**2)}MiB; "
              "latest 2 per scope, current-operation and held/restored points protected.")
except (BlockingIOError, OSError, ValueError, sqlite3.Error, TypeError, KeyError) as exc:
    print(f"Console cleanup safely skipped: {type(exc).__name__} (no unverified deletion)")
PY
  else
    echo "Migration operation volumes unavailable: console snapshots protected."
  fi
else
  echo "Migration operation state cannot be proven idle; no snapshots deleted."
fi

section "Filesystem and Docker consumption (after)"
df -h / /var/lib/docker 2>/dev/null || true
$SUDO docker system df || true
echo "Protected: containers, named volumes, PostgreSQL, Keycloak, active or unknown snapshots, identity mappings."
