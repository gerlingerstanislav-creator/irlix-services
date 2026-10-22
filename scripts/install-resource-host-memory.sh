#!/bin/sh
# Install a host-side, read-only memory sampler for the cgroup-isolated monitor.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "root required" >&2; exit 1; }
test -e /opt/irlix-services/scripts/resource-host-memory.py
command -v systemctl >/dev/null
command -v docker >/dev/null
docker volume inspect irlix-services_resource_monitor_data >/dev/null

cat > /etc/systemd/system/irlix-resource-host-memory.service <<'EOF'
[Unit]
Description=IRLIX host RAM sample for resource monitor
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /opt/irlix-services/scripts/resource-host-memory.py
NoNewPrivileges=true
PrivateNetwork=true
EOF

cat > /etc/systemd/system/irlix-resource-host-memory.timer <<'EOF'
[Unit]
Description=Refresh IRLIX host RAM snapshot every 10 seconds

[Timer]
OnBootSec=10s
OnUnitInactiveSec=10s
AccuracySec=1s
Unit=irlix-resource-host-memory.service

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now irlix-resource-host-memory.timer
# Immediate fresh reading: do not wait for first timer activation.
systemctl start irlix-resource-host-memory.service
systemctl is-active --quiet irlix-resource-host-memory.timer

# Independent, less frequent host disk scan. The container remains read-only.
test -e /opt/irlix-services/scripts/resource-host-disk.py
cat > /etc/systemd/system/irlix-resource-host-disk.service <<'EOF'
[Unit]
Description=IRLIX read-only host disk inventory
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /opt/irlix-services/scripts/resource-host-disk.py
NoNewPrivileges=true
PrivateNetwork=true
Nice=10
IOSchedulingClass=idle
CPUQuota=25%
TimeoutStartSec=120
EOF

cat > /etc/systemd/system/irlix-resource-host-disk.timer <<'EOF'
[Unit]
Description=Refresh IRLIX disk inventory every five minutes

[Timer]
OnBootSec=45s
OnUnitInactiveSec=5min
AccuracySec=15s
Unit=irlix-resource-host-disk.service

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now irlix-resource-host-disk.timer
# First inventory is best-effort: a large host must not block the release.
if ! systemctl start irlix-resource-host-disk.service; then
  echo "Host disk inventory not yet available; timer will retry" >&2
fi
systemctl is-active --quiet irlix-resource-host-disk.timer

