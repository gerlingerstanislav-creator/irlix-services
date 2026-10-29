#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi

section() {
  printf '\n=== %s ===\n' "$1"
}

section "Host"
printf 'hostname: '; hostname
printf 'cpu_count: '; nproc
printf 'load_average: '; cut -d' ' -f1-3 /proc/loadavg
printf 'uptime: '; uptime -p 2>/dev/null || uptime

section "Memory"
free -h

section "Root filesystem"
df -h /

section "Top-level disk usage"
for path in /opt /var/lib/docker /var/log /tmp; do
  if [ -e "$path" ]; then
    $SUDO du -sh "$path" 2>/dev/null || true
  fi
done

section "Docker summary"
if command -v docker >/dev/null 2>&1; then
  $SUDO docker system df || true

  section "Docker live CPU/RAM"
  $SUDO docker stats --no-stream --format '{{.Name}}\tCPU={{.CPUPerc}}\tMEM={{.MemUsage}}\tMEM%={{.MemPerc}}\tNET={{.NetIO}}\tBLOCK={{.BlockIO}}' || true

  section "Docker images"
  $SUDO docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}\t{{.ID}}' | sort -k2 -h || true

  section "Running containers"
  $SUDO docker ps --size --format '{{.Names}}\t{{.Image}}\t{{.Size}}\t{{.Status}}' || true

  section "Docker volumes"
  $SUDO docker system df -v 2>/dev/null | sed -n '/Local Volumes space usage:/,/Build cache usage:/p' || true

  section "Build cache"
  $SUDO docker builder du 2>/dev/null || true
else
  echo 'docker: not installed'
fi

# Explicit opt-in production diagnostic. Never print raw request logs, names,
# IPs, cookies, S3 keys, traceback messages or uploaded file content.
if [ "${STORAGE_UPLOAD_DIAGNOSTICS:-0}" = "1" ] && command -v docker >/dev/null 2>&1; then
  section "Storage V1 HTTP 500 diagnostic (sanitized)"
  storage_container=irlix-services_storage-api_1
  $SUDO docker logs --since 8h --tail 4000 "$storage_container" 2>&1 | awk '
    /POST \/v1\/files/ && / 500([[:space:]]|$)/ { http500++ }
    /Exception in ASGI application/ { asgi++ }
    /File "\/app\/app\// && /, line [0-9]+, in / {
      frame=$0
      sub(/^.*File "\/app\/app\//, "", frame)
      sub(/", line /, ":", frame)
      sub(/, in .*/, "", frame)
      if (frame ~ /^[A-Za-z0-9_./:-]+$/) frames[frame]++
    }
    /^[A-Za-z_][A-Za-z0-9_.]*(Error|Exception):/ {
      error_type=$1
      sub(/:.*/, "", error_type)
      if (error_type ~ /^[A-Za-z_][A-Za-z0-9_.]*$/) errors[error_type]++
    }
    END {
      printf "v1_upload_http500=%d asgi_exceptions=%d\n", http500, asgi
      for (f in frames) printf "application_frame=%s count=%d\n", f, frames[f]
      for (e in errors) printf "exception_class=%s count=%d\n", e, errors[e]
    }
  ' || true
  # A real >1 MiB multipart request is spooled to /tmp by Starlette. This
  # self-contained probe checks that path without touching any user files.
  if $SUDO docker exec "$storage_container" python -c 'import tempfile; f=tempfile.SpooledTemporaryFile(max_size=1048576); f.write(b"x" * 3145728); f.seek(0); assert len(f.read()) == 3145728; f.close()' >/dev/null 2>&1; then
    echo "multipart_spool_3mib=PASS"
  else
    echo "multipart_spool_3mib=FAIL"
  fi
fi
