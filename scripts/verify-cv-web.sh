#!/bin/sh
set -eu

cd /opt/irlix-services
PUBLIC_URL="$(grep '^IRLIX_PUBLIC_URL=' .env | tail -n1 | cut -d= -f2-)"
[ -n "$PUBLIC_URL" ] || { echo "CV WEB VERIFY FAILED: IRLIX_PUBLIC_URL is missing" >&2; exit 1; }
HOST_HEADER=${PUBLIC_URL#http://}
HOST_HEADER=${HOST_HEADER#https://}
HOST_HEADER=${HOST_HEADER%%/*}

html="$(curl -H "Host: $HOST_HEADER" -fsS --retry 10 --retry-all-errors --retry-delay 1 http://127.0.0.1/cv-converter/)"
css_url="$(printf '%s' "$html" | sed -n 's/.*href="\([^"]*\.css\)".*/\1/p' | head -n1)"
[ -n "$css_url" ] || { echo "CV WEB VERIFY FAILED: stylesheet link not found in HTML" >&2; exit 1; }

headers="$(mktemp)"
body="$(mktemp)"
trap 'rm -f "$headers" "$body"' EXIT
curl -H "Host: $HOST_HEADER" -fsS -D "$headers" -o "$body" "http://127.0.0.1$css_url"
grep -Eiq '^Content-Type:[[:space:]]*text/css([;[:space:]]|$)' "$headers" || {
  echo "CV WEB VERIFY FAILED: $css_url is not served as text/css" >&2
  cat "$headers" >&2
  exit 1
}
grep -q '\.cv-app' "$body" || {
  echo "CV WEB VERIFY FAILED: stylesheet body does not contain CV styles" >&2
  exit 1
}

missing_status="$(curl -H "Host: $HOST_HEADER" -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/cv-converter/assets/irlix-ci-definitely-missing.css)"
[ "$missing_status" = "404" ] || {
  echo "CV WEB VERIFY FAILED: missing CSS asset returned HTTP $missing_status instead of 404" >&2
  exit 1
}

echo "[verify] CV frontend stylesheet OK: $css_url"
