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
else
  echo "Migration operation state cannot be proven idle; no snapshots deleted."
fi

section "Filesystem and Docker consumption (after)"
df -h / /var/lib/docker 2>/dev/null || true
$SUDO docker system df || true
echo "Protected: containers, named volumes, PostgreSQL, Keycloak, active or unknown snapshots, identity mappings."
