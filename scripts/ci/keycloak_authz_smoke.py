#!/usr/bin/env python3
"""Isolated real Keycloak 26 role -> JWT smoke; never contacts production."""
import base64
import json
import os
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("KEYCLOAK_CI_URL", "http://127.0.0.1:18885/keycloak/auth")
REALM = BASE + "/realms/irlix"
API = BASE + "/admin/realms/irlix"
ADMIN = BASE + "/realms/master/protocol/openid-connect/token"
USER = "irlix-ci-" + secrets.token_hex(5)
PASSWORD = secrets.token_urlsafe(22)
ROLES = [
    "accounting", "accounting-head", "personnel-officer", "service:finance",
    "permission:finance-view", "employee-id:42",
]

def request(url, method="GET", value=None, bearer=None, form=False):
    headers = {"Accept": "application/json"}
    if bearer:
        headers["Authorization"] = "Bearer " + bearer
    data = None
    if value is not None:
        if form:
            data = urllib.parse.urlencode(value).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            data = json.dumps(value).encode()
            headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=12) as response:
            body = response.read()
            return (response.status, json.loads(body) if body else None, response.headers)
    except urllib.error.HTTPError as err:
        body = err.read()[:500]
        raise RuntimeError(f"Keycloak HTTP {err.code} on {method} {url.rsplit('/',1)[-1]}: {body!r}") from err

def require(cond, why):
    if not cond:
        raise AssertionError(why)

for attempt in range(75):
    try:
        request(REALM + "/.well-known/openid-configuration")
        break
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, ConnectionResetError, OSError):
        # Quarkus may reset connections or answer 404/503 while importing the
        # realm and warming up the identity provider.
        time.sleep(3)
else:
    raise RuntimeError("Keycloak did not become available")

_, auth, _ = request(ADMIN, "POST", {
    "client_id": "admin-cli", "username": os.environ["KEYCLOAK_ADMIN_USERNAME"],
    "password": os.environ["KEYCLOAK_ADMIN_PASSWORD"], "grant_type": "password",
}, form=True)
admin = auth["access_token"]
_, _, _ = request(API + "/clients", "POST", {
    "clientId": "irlix-authz-ci", "enabled": True, "protocol": "openid-connect",
    "publicClient": True, "standardFlowEnabled": False, "directAccessGrantsEnabled": True,
}, admin)
_, _, headers = request(API + "/users", "POST", {
    "username": USER, "enabled": True, "emailVerified": True,
    "email": USER + "@ci.example.invalid",
    "firstName": "Synthetic", "lastName": "Authorization",
    "requiredActions": [],
}, admin)
user_id = headers.get("Location", "").rstrip("/").split("/")[-1]
require(bool(user_id), "Keycloak did not return synthetic user id")
request(API + f"/users/{user_id}/reset-password", "PUT", {
    "type": "password", "value": PASSWORD, "temporary": False,
}, admin)
# Keycloak's imported realm can apply required user-profile actions at
# creation time. Complete them explicitly and verify before password grant.
request(API + f"/users/{user_id}", "PUT", {
    "username": USER, "email": USER + "@ci.example.org",
    "firstName": "Synthetic", "lastName": "Authorization",
    "emailVerified": True, "enabled": True, "requiredActions": [],
}, admin)
_, fresh_user, _ = request(API + f"/users/{user_id}", bearer=admin)
require(not fresh_user.get("requiredActions"), "Synthetic user has pending Keycloak actions: " + repr(fresh_user.get("requiredActions")))

reps = []
for role in ROLES:
    request(API + "/roles", "POST", {"name": role}, admin)
    _, rep, _ = request(API + "/roles/" + urllib.parse.quote(role, safe=""), bearer=admin)
    reps.append(rep)
# Exercise the exact minimal RoleRepresentation payload sent by Employees.
minimal_reps = [{"id": r["id"], "name": r["name"]} for r in reps]
request(API + f"/users/{user_id}/role-mappings/realm", "POST", minimal_reps, admin)
_, effective, _ = request(API + f"/users/{user_id}/role-mappings/realm/composite", bearer=admin)
require(set(ROLES).issubset({r["name"] for r in effective}),
        "Minimal mapping POST returned success without an effective role assignment")

def token_roles():
    _, response, _ = request(REALM + "/protocol/openid-connect/token", "POST", {
        "grant_type": "password", "client_id": "irlix-authz-ci",
        "username": USER, "password": PASSWORD,
    }, form=True)
    parts = response["access_token"].split(".")
    require(len(parts) == 3, "Keycloak access token is not a JWT")
    payload = json.loads(base64.urlsafe_b64decode(parts[1] + "=" * (-len(parts[1]) % 4)))
    require(payload.get("azp") == "irlix-authz-ci", "wrong client in signed Keycloak token")
    require(payload.get("sub") == user_id, "wrong signed employee identity")
    return set(payload.get("realm_access", {}).get("roles", []))

roles = token_roles()
require(set(ROLES).issubset(roles), f"Role propagation failed: {set(ROLES)-roles}")
require("platform-admin" not in roles, "synthetic user unexpectedly has platform-admin")
request(API + f"/users/{user_id}/role-mappings/realm", "DELETE",
    [rep for rep in minimal_reps if rep["name"] in {"service:finance", "permission:finance-view"}], admin)
new_roles = token_roles()
require("service:finance" not in new_roles, "revoked service grant remains in newly issued JWT")
require("permission:finance-view" not in new_roles, "revoked fine-grained grant remains in newly issued JWT")
require("accounting" in new_roles, "revocation removed unrelated department label")
_, effective_after, _ = request(API + f"/users/{user_id}/role-mappings/realm/composite", bearer=admin)
require("service:finance" not in {r["name"] for r in effective_after},
        "Composite read-back failed to detect revoked finance service grant")
print("PASS: real Keycloak 26 minimal mapping payload, effective role read-back, signed JWT, revocation")
