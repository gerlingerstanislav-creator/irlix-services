#!/bin/sh
set -eu

KCADM=/opt/keycloak/bin/kcadm.sh
SERVER=http://127.0.0.1:8080/auth
REALM=${KEYCLOAK_REALM:-irlix}
ADMIN_USER=${KC_BOOTSTRAP_ADMIN_USERNAME:-admin}
ADMIN_PASSWORD=${KC_BOOTSTRAP_ADMIN_PASSWORD:-}
WEB_CLIENT_ID=irlix-services-web

if [ -z "$ADMIN_PASSWORD" ]; then
  echo "KC_BOOTSTRAP_ADMIN_PASSWORD is required" >&2
  exit 1
fi

until "$KCADM" config credentials --server "$SERVER" --realm master --user "$ADMIN_USER" --password "$ADMIN_PASSWORD" >/dev/null 2>&1; do
  sleep 2
done

$KCADM update "realms/$REALM" \
  -s loginTheme=irlix \
  -s internationalizationEnabled=true \
  -s defaultLocale=ru \
  -s 'supportedLocales=["ru"]' >/dev/null

find_web_client_id() {
  $KCADM get clients -r "$REALM" -q "clientId=$WEB_CLIENT_ID" --fields id,clientId --format csv --noquotes 2>/dev/null \
    | awk -F, -v expected="$WEB_CLIENT_ID" '$2 == expected { print $1; exit }'
}

client_id="$(find_web_client_id || true)"
if [ -z "$client_id" ]; then
  $KCADM create clients -r "$REALM" \
    -s "clientId=$WEB_CLIENT_ID" \
    -s name='IRLIX Services Web' \
    -s enabled=true \
    -s publicClient=true \
    -s standardFlowEnabled=true \
    -s directAccessGrantsEnabled=false \
    -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*"]' \
    -s 'webOrigins=["http://192.168.90.100","http://localhost"]' >/dev/null
  client_id="$(find_web_client_id || true)"
fi

if [ -z "$client_id" ]; then
  echo "OIDC client $WEB_CLIENT_ID was not created" >&2
  exit 1
fi

$KCADM update "clients/$client_id" -r "$REALM" \
  -s enabled=true \
  -s publicClient=true \
  -s standardFlowEnabled=true \
  -s directAccessGrantsEnabled=false \
  -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*"]' \
  -s 'webOrigins=["http://192.168.90.100","http://localhost"]' >/dev/null

verified_client_id="$(find_web_client_id || true)"
test "$verified_client_id" = "$client_id"
echo "OIDC client $WEB_CLIENT_ID is configured."

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
  $KCADM get "users/$user_id/credentials" -r "$REALM" --fields type --format csv --noquotes | grep -qx 'password'
  echo "Temporary platform admin is enabled, password credential refreshed and platform-admin role assigned."
fi
