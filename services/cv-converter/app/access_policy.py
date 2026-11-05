"""Service-owned AuthZ V2 matrix for the stateless CV Converter.

Employees/Keycloak provide verified identity and broad service admission.
This module owns business permission cv.convert (no object scope exists for
ephemeral, per-request CV conversion). The matrix is persistent on /data,
explicit deny overrides grant, and a verified platform-admin is the sole
immutable superuser. No candidate data is persisted in this database.
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
from pathlib import Path
from threading import RLock

DB_PATH = Path(os.getenv('CV_PERMISSIONS_DB_PATH', '/data/cv_permissions.sqlite3'))
_LOCK = RLock()
ROLE_RE = re.compile(r'^(?:(?:org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*|head:\*)$')
IDENTITY_RE = re.compile(r'^(?:org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$')
PERMISSION = 'cv.convert'
DEFAULT_ROLE = 'head:*'


def _connect():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    with _LOCK:
        conn = sqlite3.connect(DB_PATH, timeout=5)
        try:
            conn.row_factory = sqlite3.Row
            conn.execute('PRAGMA busy_timeout=5000')
            conn.execute('CREATE TABLE IF NOT EXISTS cv_permission_rules ('
                         'role TEXT NOT NULL, permission TEXT NOT NULL, '
                         'allowed INTEGER NOT NULL CHECK(allowed IN (0,1)), '
                         'scope TEXT NOT NULL CHECK(scope IN ("all", "none")), '
                         'PRIMARY KEY(role,permission))')
            conn.execute('CREATE TABLE IF NOT EXISTS cv_permission_audit ('
                         'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                         'actor_employee_id INTEGER NOT NULL, role TEXT NOT NULL, '
                         'permission TEXT NOT NULL, before_value TEXT, after_value TEXT, '
                         'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)')
            # Seed the initial default once. Never re-seed a deleted/edited
            # default on subsequent boots: preserve manual matrix changes.
            conn.execute('CREATE TABLE IF NOT EXISTS cv_policy_meta '
                         '(key TEXT PRIMARY KEY, value TEXT NOT NULL)')
            old = conn.execute('SELECT value FROM cv_policy_meta WHERE key=?', ('schema_version',)).fetchone()
            if old is None:
                conn.execute('INSERT OR IGNORE INTO cv_permission_rules '
                             '(role,permission,allowed,scope) VALUES(?,?,1,?)',
                             (DEFAULT_ROLE, PERMISSION, 'all'))
                conn.execute('INSERT INTO cv_policy_meta(key,value) VALUES(?,?)',
                             ('schema_version', '1'))
            conn.commit()
            try:
                os.chmod(DB_PATH, 0o600)
            except OSError:
                pass  # Existing Docker volume permissions govern access.
            return conn
        except BaseException:
            conn.close()
            raise


def matrix() -> list[dict]:
    with _LOCK:
        conn = _connect()
        try:
            return [dict(row) for row in conn.execute(
                'SELECT role,permission,allowed,scope FROM cv_permission_rules '
                'ORDER BY role,permission').fetchall()]
        finally:
            conn.close()


def evaluate(verified_roles: set[str], rows: list[dict], *, admin: bool = False) -> bool:
    """Only exact V2 identity roles count, and a deny always wins."""
    if admin:
        return True
    roles = {role for role in verified_roles
             if isinstance(role, str) and IDENTITY_RE.fullmatch(role)
             and role != 'special:platform-tester'}
    if not roles:
        return False
    permitted = False
    for row in rows:
        if row.get('permission') != PERMISSION:
            continue
        role = row.get('role')
        applies = role in roles or (role == DEFAULT_ROLE
                                    and any(item.startswith('head:') for item in roles))
        if not applies:
            continue
        if row.get('allowed') not in (True, 1) or row.get('scope') != 'all':
            return False  # Explicit deny for this same permission.
        permitted = True
    return permitted


def can_convert(verified_roles: set[str], *, admin: bool = False) -> bool:
    return evaluate(verified_roles, matrix(), admin=admin)


def _validate(role: str, allowed: bool):
    if not isinstance(role, str) or not ROLE_RE.fullmatch(role):
        raise ValueError('Invalid namespaced role')
    if role in {'special:platform-admin', 'special:platform-tester'}:
        raise ValueError('This special role is not editable')
    if not isinstance(allowed, bool):
        raise ValueError('Allowed must be a boolean')


def update(role: str, allowed: bool, actor_employee_id: int) -> None:
    _validate(role, allowed)
    if not isinstance(actor_employee_id, int) or actor_employee_id <= 0:
        raise ValueError('Verified actor required')
    scope = 'all' if allowed else 'none'
    with _LOCK:
        conn = _connect()
        try:
            with conn:
                row = conn.execute('SELECT allowed,scope FROM cv_permission_rules '
                                   'WHERE role=? AND permission=?',
                                   (role, PERMISSION)).fetchone()
                before = dict(row) if row is not None else None
                conn.execute('INSERT INTO cv_permission_rules(role,permission,allowed,scope) '
                             'VALUES(?,?,?,?) ON CONFLICT(role,permission) DO UPDATE SET '
                             'allowed=excluded.allowed,scope=excluded.scope',
                             (role, PERMISSION, int(allowed), scope))
                conn.execute('INSERT INTO cv_permission_audit '
                             '(actor_employee_id,role,permission,before_value,after_value) '
                             'VALUES(?,?,?,?,?)',
                             (actor_employee_id, role, PERMISSION, json.dumps(before),
                              json.dumps({'allowed': allowed, 'scope': scope})))
        finally:
            conn.close()


def remove(role: str, actor_employee_id: int) -> None:
    _validate(role, False)
    if not isinstance(actor_employee_id, int) or actor_employee_id <= 0:
        raise ValueError('Verified actor required')
    with _LOCK:
        conn = _connect()
        try:
            with conn:
                row = conn.execute('SELECT allowed,scope FROM cv_permission_rules '
                                   'WHERE role=? AND permission=?',
                                   (role, PERMISSION)).fetchone()
                if row is None:
                    return
                conn.execute('DELETE FROM cv_permission_rules WHERE role=? AND permission=?',
                             (role, PERMISSION))
                conn.execute('INSERT INTO cv_permission_audit '
                             '(actor_employee_id,role,permission,before_value,after_value) '
                             'VALUES(?,?,?,?,NULL)',
                             (actor_employee_id, role, PERMISSION, json.dumps(dict(row))))
        finally:
            conn.close()
