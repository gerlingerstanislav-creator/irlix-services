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

section "Console checkpoint audit (read-only)"
# Diagnose legacy checkpoint directories rejected by current retention.
# Only numeric checkpoint IDs, counts and disk allocation are shown; no payloads.
if [ -d "$BASE/.ci/migration-test-snapshots/console" ]; then
  $SUDO python3 - "$BASE/.ci/migration-test-snapshots/console" <<'PY'
import collections
import datetime as dt
import json
import os
from pathlib import Path
import sys

root = Path(sys.argv[1])
scopes = {s: (s,) for s in ("employees", "vacations", "clients", "timesheets")}
scopes["all"] = ("employees", "vacations", "clients", "timesheets")
allowed = {"target.dump", "metadata.sqlite", "migration-credential.key",
           "vacations-files.tar.gz", "manifest.json", "restored", ".keep"}
totals = collections.defaultdict(lambda: [0, 0, []])
for scope, schemas in scopes.items():
    base = root / scope
    if not base.is_dir() or base.is_symlink():
        continue
    for directory in base.iterdir():
        if not directory.name.isascii() or not directory.name.isdecimal():
            continue
        reason = "verified"
        bytes_used = 0
        if directory.is_symlink() or not directory.is_dir():
            reason = "unsafe_directory"
        else:
            try:
                files = list(directory.iterdir())
                bytes_used = sum(f.stat(follow_symlinks=False).st_blocks * 512
                                 for f in files if f.is_file() and not f.is_symlink())
                if any(f.is_symlink() or not f.is_file() or f.name not in allowed
                       or f.stat().st_nlink != 1 for f in files):
                    reason = "unexpected_contents"
                else:
                    manifest = directory / "manifest.json"
                    if not manifest.is_file():
                        reason = "missing_manifest"
                    else:
                        info = json.loads(manifest.read_text())
                        stamp = dt.datetime.fromisoformat(str(info.get("created_at_utc", "")).replace("Z", "+00:00"))
                        if stamp.tzinfo is None or stamp > dt.datetime.now(dt.timezone.utc):
                            reason = "invalid_timestamp"
                        elif info.get("service") != scope or str(info.get("snapshot_id")) != directory.name:
                            reason = "identity_mismatch"
                        elif tuple(info.get("schemas", ())) != schemas or info.get("verification_version") != 1:
                            reason = "manifest_version"
                        elif not isinstance(info.get("checksums"), dict) or any(
                            k not in info["checksums"] or not (directory / k).is_file()
                            for k in ("target.dump", "metadata.sqlite")
                        ):
                            reason = "missing_payload_proof"
                        elif (directory / ".keep").exists():
                            reason = "held"
            except (OSError, KeyError, TypeError, ValueError, AttributeError):
                reason = "unreadable"
        key = (scope, reason)
        record = totals[key]
        record[0] += 1
        record[1] += bytes_used
        if len(record[2]) < 3:
            record[2].append(directory.name)
for (scope, reason), (count, size, examples) in sorted(totals.items()):
    print(f"console_audit scope={scope} reason={reason} count={count} allocated_mib={size//1048576} sample_ids={','.join(examples)}")
PY
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
  if $SUDO docker compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" exec -T migration-ops python3 scripts/ci/check_migration_release_safety.py < /dev/null >/dev/null 2>&1; then
    safe_to_expire=true
  elif command -v docker-compose >/dev/null 2>&1; then
    if $SUDO docker-compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" exec -T migration-ops python3 scripts/ci/check_migration_release_safety.py < /dev/null >/dev/null 2>&1; then safe_to_expire=true; fi
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
  # Central retention policy is persisted in migration-ops /ops (default: two
  # per console scope). Same lock and verified-idle gate as the admin API.
  if $SUDO docker compose version >/dev/null 2>&1; then
    $SUDO docker compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" \
      exec -T migration-ops python3 scripts/migration_retention.py prune < /dev/null \
      || echo "Migration retention deferred: runtime state not safely idle or policy unavailable."
  elif command -v docker-compose >/dev/null 2>&1; then
    $SUDO docker-compose -f "$BASE/docker-compose.yml" -f "$BASE/docker-compose.migration.yml" --env-file "$BASE/.env" \
      exec -T migration-ops python3 scripts/migration_retention.py prune < /dev/null \
      || echo "Migration retention deferred: runtime state not safely idle or policy unavailable."
  else
    echo "Migration retention deferred: no Docker Compose implementation available."
  fi

else
  echo "Migration operation state cannot be proven idle; no snapshots deleted."
fi

section "Filesystem and Docker consumption (after)"
df -h / /var/lib/docker 2>/dev/null || true
$SUDO docker system df || true
echo "Protected: containers, named volumes, PostgreSQL, Keycloak, active or unknown snapshots, identity mappings."
