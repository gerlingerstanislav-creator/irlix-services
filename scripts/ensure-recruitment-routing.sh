#!/bin/sh
set -eu

CONFIG="/etc/nginx/sites-available/irlix-services"

if [ ! -f "$CONFIG" ]; then
  echo "Host nginx config not found: $CONFIG" >&2
  exit 1
fi

if grep -q 'location /api/recruitment/' "$CONFIG" && grep -q 'location /recruitment/' "$CONFIG"; then
  nginx -t
  systemctl reload nginx
  exit 0
fi

TMP="$(mktemp)"
awk '
  /location \/design-system\// && !inserted {
    print "              location /api/recruitment/ { proxy_pass http://127.0.0.1:8094/api/; proxy_set_header Host $host; proxy_set_header X-Real-IP $remote_addr; }"
    print "              location /recruitment/ { proxy_pass http://127.0.0.1:8095/; proxy_set_header Host $host; proxy_set_header X-Real-IP $remote_addr; }"
    print "              location = /recruitment { return 301 /recruitment/; }"
    inserted=1
  }
  { print }
' "$CONFIG" > "$TMP"
cat "$TMP" > "$CONFIG"
rm -f "$TMP"

nginx -t
systemctl reload nginx
