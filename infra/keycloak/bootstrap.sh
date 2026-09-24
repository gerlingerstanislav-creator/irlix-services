#!/bin/sh
set -eu

echo "Starting IRLIX Keycloak bootstrap..."

KCADM=/opt/keycloak/bin/kcadm.sh
SERVER=http://127.0.0.1:8080/auth
REALM=${KEYCLOAK_REALM:-irlix}
ADMIN_USER=${KC_BOOTSTRAP_ADMIN_USERNAME:-admin}
ADMIN_PASSWORD=${KC_BOOTSTRAP_ADMIN_PASSWORD:-}
WEB_CLIENT_ID=irlix-services-web
CONSOLE_ADMIN_USER=keycloak-admin

if [ -z "$ADMIN_PASSWORD" ]; then
  echo "KC_BOOTSTRAP_ADMIN_PASSWORD is required" >&2
  exit 1
fi

attempts=0
until "$KCADM" config credentials --server "$SERVER" --realm master --user "$ADMIN_USER" --password "$ADMIN_PASSWORD" >/dev/null 2>&1; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge 10 ]; then
    echo "Cannot authenticate Keycloak bootstrap admin '$ADMIN_USER' in master realm." >&2
    exit 1
  fi
  sleep 2
done

echo "Applying realm login configuration..."
$KCADM update "realms/$REALM" \
  -s loginTheme=irlix \
  -s internationalizationEnabled=true \
  -s defaultLocale=ru \
  -s 'supportedLocales=["ru"]' >/dev/null

find_web_client_id() {
  $KCADM get clients -r "$REALM" -q "clientId=$WEB_CLIENT_ID" --fields id --format csv --noquotes 2>/dev/null | head -n1 || true
}

client_id="$(find_web_client_id)"
if [ -z "$client_id" ]; then
  echo "Creating OIDC client $WEB_CLIENT_ID..."
  $KCADM create clients -r "$REALM" \
    -s "clientId=$WEB_CLIENT_ID" \
    -s name='IRLIX Services Web' \
    -s enabled=true \
    -s publicClient=true \
    -s standardFlowEnabled=true \
    -s directAccessGrantsEnabled=false \
    -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*","http://127.0.0.1/*"]' \
    -s 'webOrigins=["http://192.168.90.100","http://localhost","http://127.0.0.1"]' >/dev/null
  client_id="$(find_web_client_id)"
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
  -s 'redirectUris=["http://192.168.90.100/*","http://localhost/*","http://127.0.0.1/*"]' \
  -s 'webOrigins=["http://192.168.90.100","http://localhost","http://127.0.0.1"]' >/dev/null

verified_client_id="$(find_web_client_id)"
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
      -s firstName=IRLIX \
      -s lastName=Admin \
      -s 'requiredActions=[]' >/dev/null
    user_id=$($KCADM get users -r "$REALM" -q username=admin --fields id --format csv --noquotes | head -n1)
  else
    $KCADM update "users/$user_id" -r "$REALM" \
      -s enabled=true \
      -s email=admin@irlix.ru \
      -s emailVerified=true \
      -s firstName=IRLIX \
      -s lastName=Admin \
      -s 'requiredActions=[]' >/dev/null
  fi

  $KCADM set-password -r "$REALM" --userid "$user_id" --new-password "$IRLIX_TEMP_ADMIN_PASSWORD" --temporary=false >/dev/null
  $KCADM add-roles -r "$REALM" --uid "$user_id" --rolename platform-admin >/dev/null 2>&1 || true

  user_id=$($KCADM get users -r "$REALM" -q username=admin --fields id --format csv --noquotes | head -n1)
  test -n "$user_id"
  $KCADM get "users/$user_id/role-mappings/realm" -r "$REALM" --fields name --format csv --noquotes | grep -qx 'platform-admin'
  $KCADM get "users/$user_id/credentials" -r "$REALM" --fields type --format csv --noquotes | grep -qx 'password'
  echo "Temporary platform admin is enabled, profile completed, password credential refreshed and platform-admin role assigned."
fi

if [ -n "${KEYCLOAK_ADMIN_PWD:-}" ]; then
  console_admin_id=$($KCADM get users -r master -q "username=$CONSOLE_ADMIN_USER" --fields id --format csv --noquotes 2>/dev/null | head -n1 || true)
  if [ -z "$console_admin_id" ]; then
    $KCADM create users -r master \
      -s "username=$CONSOLE_ADMIN_USER" \
      -s enabled=true \
      -s firstName=IRLIX \
      -s lastName='Keycloak Admin' \
      -s 'requiredActions=[]' >/dev/null
    console_admin_id=$($KCADM get users -r master -q "username=$CONSOLE_ADMIN_USER" --fields id --format csv --noquotes | head -n1)
  else
    $KCADM update "users/$console_admin_id" -r master \
      -s enabled=true \
      -s firstName=IRLIX \
      -s lastName='Keycloak Admin' \
      -s 'requiredActions=[]' >/dev/null
  fi

  test -n "$console_admin_id"
  $KCADM set-password -r master --userid "$console_admin_id" --new-password "$KEYCLOAK_ADMIN_PWD" --temporary=false >/dev/null
  $KCADM add-roles -r master --uid "$console_admin_id" --cclientid realm-management --rolename realm-admin >/dev/null 2>&1 || true
  $KCADM get "users/$console_admin_id/credentials" -r master --fields type --format csv --noquotes | grep -qx 'password'
  echo "Dedicated Keycloak console admin '$CONSOLE_ADMIN_USER' is configured."
fi

echo "IRLIX Keycloak bootstrap finished."
