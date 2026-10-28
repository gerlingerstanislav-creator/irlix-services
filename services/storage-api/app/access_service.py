"""ACL commands that must execute inside the Storage metadata DB transaction."""
from dataclasses import dataclass
from typing import Protocol, Mapping, Sequence
from uuid import uuid4

from .access_policy import FileGrant, FileObject, GrantRole, Permission, Principal, can_access


class AccessDenied(Exception):
    pass


class InvalidOperation(Exception):
    pass


class Repository(Protocol):
    def load_objects(self, object_id: str) -> Mapping[str, FileObject]: ...
    def load_grants(self, object_id: str) -> Sequence[FileGrant]: ...
    def employee_is_active(self, employee_id: int) -> bool: ...
    def put_grant(self, object_id: str, employee_id: int, role: GrantRole, granted_by: int) -> None: ...
    def revoke_grant(self, object_id: str, employee_id: int) -> None: ...
    def set_object_state(self, object_id: str, state: str) -> None: ...
    def append_audit(self, event: dict) -> None: ...


@dataclass
class AccessService:
    repo: Repository
    actor: Principal
    correlation_id: str

    def _authorize(self, object_id: str, action: Permission) -> FileObject:
        objects = self.repo.load_objects(object_id)
        grants = self.repo.load_grants(object_id)
        if not can_access(self.actor, object_id, action, objects, grants):
            raise AccessDenied("Permission + scope denied")
        return objects[object_id]

    def _audit(self, object_id: str, action: str, details: dict | None = None) -> None:
        self.repo.append_audit({
            "event_id": str(uuid4()),
            "actor_employee_id": self.actor.employee_id,
            "object_id": object_id,
            "action": action,
            "outcome": "allowed",
            "correlation_id": self.correlation_id,
            "details": details or {},
        })

    def grant(self, object_id: str, employee_id: int, role: GrantRole) -> None:
        node = self._authorize(object_id, Permission.SHARE)
        if employee_id <= 0 or employee_id == node.owner_employee_id:
            raise InvalidOperation("Invalid recipient")
        if not isinstance(role, GrantRole) or not self.repo.employee_is_active(employee_id):
            raise InvalidOperation("Inactive recipient or invalid grant")
        self.repo.put_grant(object_id, employee_id, role, self.actor.employee_id)
        self._audit(object_id, "acl.grant", {"recipient_employee_id": employee_id, "role": role.value})

    def revoke(self, object_id: str, employee_id: int) -> None:
        self._authorize(object_id, Permission.SHARE)
        if employee_id <= 0:
            raise InvalidOperation("Invalid recipient")
        self.repo.revoke_grant(object_id, employee_id)
        self._audit(object_id, "acl.revoke", {"recipient_employee_id": employee_id})

    def trash(self, object_id: str) -> None:
        self._authorize(object_id, Permission.TRASH)
        self.repo.set_object_state(object_id, "trashed")
        self._audit(object_id, "object.trash")

    def restore(self, object_id: str) -> None:
        self._authorize(object_id, Permission.RESTORE)
        self.repo.set_object_state(object_id, "active")
        self._audit(object_id, "object.restore")
