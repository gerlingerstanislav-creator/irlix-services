#!/bin/sh
set -eu

KCADM=/opt/keycloak/bin/kcadm.sh
SERVER=http://127.0.0.1:8080/auth
REALM=${KEYCLOAK_REALM:-irlix}
ADMIN_USER=${KC_BOOTSTRAP_ADMIN_USERNAME:-admin}
ADMIN_PASSWORD=${KC_BOOTSTRAP_ADMIN_PASSWORD:-}

if [ -z "$ADMIN_PASSWORD" ]; then
  echo "KC_BOOTSTRAP_ADMIN_PASSWORD is required" >&2
  exit 1
fi

until "$KCADM" config credentials --server "$SERVER" --realm master --user "$ADMIN_USER" --password "$ADMIN_PASSWORD" >/dev/null 2>&1; do
  sleep 2
done

client_id=$($KCADM get clients -r "$REALM" -q clientId=irlix-services-web --fields id --format csv --noquotes 2>/dev/null | head -n1 || true)
if [ -z "$client_id" ]; then
  $KCADM create clients -r "$REALM" \
    -s clientId=irlix-services-web \
    -s name='IRLIX Services Web' \
    -s enabled=true \
    -s publicClient=true \
    -s standardFlowEnabled=true \
    -s directAccessGrantsEnabled=false \
    -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*"]' \
    -s 'webOrigins=["+"]' >/dev/null
else
  $KCADM update "clients/$client_id" -r "$REALM" \
    -s enabled=true \
    -s publicClient=true \
    -s standardFlowEnabled=true \
    -s directAccessGrantsEnabled=false \
    -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*"]' \
    -s 'webOrigins=["+"]' >/dev/null
fi

if ! $KCADM get roles/platform-admin -r "$REALM" >/dev/null 2>&1; then
  $KCADM create roles -r "$REALM" -s name=platform-admin -s description='IRLIX platform administrator' >/dev/null
fi

if [ -n "${IRLIX_TEMP_ADMIN_PASSWORD:-}" ]; then
  user_id=$($KCADM get users -r "$REALM" -q username=admin --fields id --format csv --noquotes 2>/dev/null | head -n1 || true)
  if [ -z "$user_id" ]; then
    $KCADM create users -r "$REALM" \
      -s username=admin \
      -s enabled=true \
      -s email=admin@irlix.ru \
      -s emailVerified=true \
      -s 'requiredActions=[]' >/dev/null
    user_id=$($KCADM get users -r "$REALM" -q username=admin --fields id --format csv --noquotes | head -n1)
  else
    $KCADM update "users/$user_id" -r "$REALM" \
      -s enabled=true \
      -s email=admin@irlix.ru \
      -s emailVerified=true \
      -s 'requiredActions=[]' >/dev/null
  fi

  $KCADM set-password -r "$REALM" --userid "$user_id" --new-password "$IRLIX_TEMP_ADMIN_PASSWORD" --temporary=false >/dev/null
  $KCADM add-roles -r "$REALM" --uid "$user_id" --rolename platform-admin >/dev/null 2>&1 || true

  user_id=$($KCADM get users -r "$REALM" -q username=admin --fields id --format csv --noquotes | head -n1)
  test -n "$user_id"
  $KCADM get "users/$user_id/role-mappings/realm" -r "$REALM" --fields name --format csv --noquotes | grep -qx 'platform-admin'
  echo "Temporary platform admin is enabled, password refreshed and platform-admin role assigned."
fi
