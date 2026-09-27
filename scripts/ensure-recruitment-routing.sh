#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
CONFIG="/etc/nginx/sites-available/irlix-services"

if [ ! -f "$CONFIG" ]; then
  echo "Host nginx config not found: $CONFIG" >&2
  exit 1
fi

if ! $SUDO grep -q 'location /api/recruitment/' "$CONFIG" || ! $SUDO grep -q 'location /recruitment/' "$CONFIG"; then
  TMP="$(mktemp)"
  $SUDO awk '
    /location \/design-system\// && !inserted {
      print "              location /api/recruitment/ { proxy_pass http://127.0.0.1:8094/api/; proxy_set_header Host $host; proxy_set_header X-Real-IP $remote_addr; }"
      print "              location /recruitment/ { proxy_pass http://127.0.0.1:8095/; proxy_set_header Host $host; proxy_set_header X-Real-IP $remote_addr; }"
      print "              location = /recruitment { return 301 /recruitment/; }"
      inserted=1
    }
    { print }
  ' "$CONFIG" > "$TMP"
  $SUDO cp "$TMP" "$CONFIG"
  rm -f "$TMP"
fi

$SUDO nginx -t
$SUDO systemctl reload nginx

echo 'Recruitment host routing is configured.'
