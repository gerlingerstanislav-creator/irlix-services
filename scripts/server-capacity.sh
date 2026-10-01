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
