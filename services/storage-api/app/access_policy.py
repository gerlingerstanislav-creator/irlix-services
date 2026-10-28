"""Pure, fail-closed Storage per-object permission evaluator.

All identities are verified server-side and objects/grants come from Storage's
transactional repository. No browser-supplied role or owner is authoritative.
"""
from dataclasses import dataclass
from enum import Enum
from typing import Mapping, Sequence


class Permission(str, Enum):
    READ = "read"
    DOWNLOAD = "download"
    THUMBNAIL = "thumbnail"
    LIST = "list"
    UPLOAD = "upload"
    EDIT = "edit"
    SHARE = "share"
    TRASH = "trash"
    RESTORE = "restore"
    AUDIT = "audit"
    MOVE = "move"
    COPY = "copy"


class GrantRole(str, Enum):
    READER = "reader"
    EDITOR = "editor"


READ_ACTIONS = frozenset({Permission.READ, Permission.DOWNLOAD, Permission.THUMBNAIL, Permission.LIST})
EDITOR_ACTIONS = READ_ACTIONS | frozenset({Permission.UPLOAD, Permission.EDIT})
OWNER_ACTIONS = EDITOR_ACTIONS | frozenset({Permission.SHARE, Permission.TRASH, Permission.RESTORE, Permission.AUDIT, Permission.MOVE})


@dataclass(frozen=True)
class Principal:
    employee_id: int
    active: bool
    platform_admin: bool = False

    def __post_init__(self):
        if self.employee_id <= 0:
            raise ValueError("Invalid employee identity")


@dataclass(frozen=True)
class FileObject:
    object_id: str
    owner_employee_id: int
    parent_id: str | None = None
    state: str = "active"

    def __post_init__(self):
        if not self.object_id or self.owner_employee_id <= 0 or self.state not in {"active", "trashed"}:
            raise ValueError("Invalid file object")


@dataclass(frozen=True)
class FileGrant:
    object_id: str
    employee_id: int
    role: GrantRole

    def __post_init__(self):
        if not self.object_id or self.employee_id <= 0 or not isinstance(self.role, GrantRole):
            raise ValueError("Invalid grant")


def can_access(principal: Principal, object_id: str, permission: Permission,
               objects: Mapping[str, FileObject], grants: Sequence[FileGrant]) -> bool:
    """Deny if any ancestor, ownership, state or permission check is uncertain."""
    if not principal.active or object_id not in objects or not isinstance(permission, Permission):
        return False
    chain = []
    seen = set()
    current = object_id
    while current is not None:
        if current in seen or current not in objects or len(chain) >= 32:
            return False
        seen.add(current)
        node = objects[current]
        chain.append(node)
        current = node.parent_id
    target = chain[0]
    if any(node.owner_employee_id != target.owner_employee_id for node in chain):
        return False
    if any(node.state == "trashed" for node in chain):
        if permission is not Permission.RESTORE or target.state != "trashed":
            return False
        if any(node.state != "active" for node in chain[1:]):
            return False
    elif permission is Permission.RESTORE:
        return False
    if principal.platform_admin:
        return True
    if principal.employee_id == target.owner_employee_id:
        return permission in OWNER_ACTIONS
    if permission not in EDITOR_ACTIONS:
        return False
    chain_ids = {node.object_id for node in chain}
    return any(grant.employee_id == principal.employee_id and grant.object_id in chain_ids
               and (grant.role is GrantRole.EDITOR or
                    (grant.role is GrantRole.READER and permission in READ_ACTIONS))
               for grant in grants)
