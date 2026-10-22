#!/bin/sh
# Safe, idempotent host-side controller install. No external endpoints or
# Docker socket access is granted to cv-converter.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "root required" >&2; exit 1; }
command -v systemctl >/dev/null
command -v docker >/dev/null
test -f /opt/irlix-services/scripts/reconcile-cv-llm.sh
cat > /etc/systemd/system/irlix-cv-llm-reconcile.service <<'EOF'
[Unit]
Description=Match local IRLIX CV model state to effective provider
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/bin/sh /opt/irlix-services/scripts/reconcile-cv-llm.sh
NoNewPrivileges=true
PrivateTmp=true
TimeoutStartSec=180
EOF

cat > /etc/systemd/system/irlix-cv-llm-reconcile.timer <<'EOF'
[Unit]
Description=Reconcile CV local model with provider selection every 20 seconds

[Timer]
OnBootSec=10s
OnUnitInactiveSec=20s
AccuracySec=2s
Unit=irlix-cv-llm-reconcile.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now irlix-cv-llm-reconcile.timer
# Apply selected persisted provider immediately on deployment, not 20s later.
systemctl start irlix-cv-llm-reconcile.service
systemctl is-active --quiet irlix-cv-llm-reconcile.timer
